<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockOutReleaseService
{
    public function __construct(
        private readonly IdempotencyService $idempotencyService,
        private readonly InventoryCostService $inventoryCost
    ) {}

    public function releaseSaleItemFromGarage(int $saleItemId, float $quantity, string $stockOutAt, int $createdBy, ?int $preferredGarageId = null, ?string $remarks = null, bool $allowPreparation = true): ?string
    {
        $saleItem = $this->saleItemForStockOut($saleItemId);

        if (! $saleItem) {
            return 'The selected sale is not eligible for stock-out.';
        }

        $releasedQuantity = round((float) DB::table('stock_outs')
            ->where('sale_item_id', $saleItem->id)
            ->where('status', 'released')
            ->lockForUpdate()
            ->sum('quantity_liters'), 2);
        $fulfilledQuantity = max((float) $saleItem->fulfilled_quantity_liters, $releasedQuantity);

        if ($fulfilledQuantity > (float) $saleItem->fulfilled_quantity_liters) {
            DB::table('sale_items')->where('id', $saleItem->id)->update([
                'fulfilled_quantity_liters' => $fulfilledQuantity,
                'updated_at' => now(),
            ]);
        }

        $remaining = round((float) $saleItem->quantity_liters - $fulfilledQuantity, 2);
        if ($remaining <= 0) {
            return null;
        }

        $quantity = round($quantity, 2);

        if ($quantity > $remaining) {
            return 'Quantity released cannot exceed the remaining sale quantity.';
        }

        $directResult = $this->releaseFromDirectDepotAllocations($saleItem, $quantity, $stockOutAt, $createdBy);
        if ($directResult['handled']) {
            return $directResult['error'];
        }

        $garages = DB::table('storage_locations')
            ->where('type', 'garage')
            ->where('status', 'active')
            ->where(function (Builder $query) use ($saleItem): void {
                $query->whereNull('fuel_type_id')
                    ->orWhere('fuel_type_id', $saleItem->fuel_type_id);
            })
            ->when($preferredGarageId, fn (Builder $query): Builder => $query->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$preferredGarageId]))
            ->orderBy('tank_number')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);

        $availableByGarage = [];
        $totalAvailable = 0.0;

        foreach ($garages as $garage) {
            $available = $this->availableGarageStockForUpdate((int) $garage->id, (int) $saleItem->fuel_type_id);
            $availableByGarage[(int) $garage->id] = $available;
            $totalAvailable = round($totalAvailable + $available, 2);
        }

        if ($totalAvailable < $quantity) {
            if (! $allowPreparation) {
                return 'Insufficient available garage inventory to complete delivery.';
            }

            if (! $this->preparedStockOutExistsForSaleItem((int) $saleItem->id)) {
                $this->insertPreparedGarageRelease($saleItem, $quantity, $stockOutAt, $createdBy);
            }

            return null;
        }

        DB::table('stock_outs')
            ->where('sale_item_id', $saleItem->id)
            ->where('status', 'prepared')
            ->update(['status' => 'cancelled', 'updated_at' => now()]);

        $toRelease = $quantity;

        foreach ($garages as $garage) {
            $available = $availableByGarage[(int) $garage->id] ?? 0.0;

            if ($available <= 0) {
                continue;
            }

            $releaseQuantity = round(min($available, $toRelease), 2);
            $this->insertGarageRelease($saleItem, (int) $garage->id, $releaseQuantity, $stockOutAt, $createdBy, $remarks);
            $toRelease = round($toRelease - $releaseQuantity, 2);

            if ($toRelease <= 0) {
                break;
            }
        }

        return null;
    }

    private function saleItemForStockOut(int $saleItemId): ?object
    {
        return DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sale_items.id', $saleItemId)
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', ['confirmed', 'partially_paid', 'paid', 'unpaid'])
            ->where('sales.delivery_status', 'delivered')
            ->lockForUpdate()
            ->first([
                'sale_items.id',
                'sale_items.sale_id',
                'sale_items.fuel_type_id',
                'sale_items.quantity_liters',
                'sale_items.fulfilled_quantity_liters',
                'sales.customer_id',
            ]);
    }

    private function preparedStockOutExistsForSaleItem(int $saleItemId): bool
    {
        return DB::table('stock_outs')
            ->where('sale_item_id', $saleItemId)
            ->where('status', 'prepared')
            ->lockForUpdate()
            ->exists();
    }

    private function insertGarageRelease(object $saleItem, int $garageId, float $quantity, string $stockOutAt, int $createdBy, ?string $remarks): void
    {
        $unitCost = $this->inventoryCost->currentUnitCostForUpdate($garageId, (int) $saleItem->fuel_type_id);

        if ($unitCost === null) {
            throw new \RuntimeException('The selected garage inventory has no verifiable cost basis.');
        }

        DB::table('sale_items')
            ->where('id', $saleItem->id)
            ->update([
                'fulfilled_quantity_liters' => DB::raw('fulfilled_quantity_liters + '.$quantity),
                'updated_at' => now(),
            ]);

        $stockOutId = $this->idempotencyService->retryOnCollision('stock_out_code', function () use ($saleItem, $garageId, $quantity, $unitCost, $stockOutAt, $createdBy): int {
            return (int) DB::table('stock_outs')->insertGetId([
                'stock_out_code' => $this->nextCode('stock_outs', 'stock_out_code', 'STO'),
                'sale_id' => $saleItem->sale_id,
                'sale_item_id' => $saleItem->id,
                'customer_id' => $saleItem->customer_id,
                'fuel_type_id' => $saleItem->fuel_type_id,
                'storage_location_id' => $garageId,
                'source_type' => 'garage',
                'quantity_liters' => $quantity,
                'unit_cost' => $unitCost,
                'stock_out_at' => $stockOutAt,
                'status' => 'released',
                'created_by' => $createdBy,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $movementId = $this->idempotencyService->retryOnCollision('movement_code', function () use ($garageId, $saleItem, $quantity, $unitCost, $stockOutId, $stockOutAt, $remarks, $createdBy): int {
            return (int) DB::table('inventory_movements')->insertGetId([
                'movement_code' => $this->nextCode('inventory_movements', 'movement_code', 'MOV'),
                'storage_location_id' => $garageId,
                'fuel_type_id' => $saleItem->fuel_type_id,
                'movement_type' => 'stock_out',
                'direction' => 'out',
                'quantity_liters' => $quantity,
                'unit_cost' => $unitCost,
                'reference_type' => 'stock_out',
                'reference_id' => $stockOutId,
                'movement_date' => $stockOutAt,
                'remarks' => $remarks,
                'created_by' => $createdBy,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        DB::table('stock_outs')
            ->where('id', $stockOutId)
            ->update([
                'inventory_movement_id' => $movementId,
                'updated_at' => now(),
            ]);
    }

    private function insertPreparedGarageRelease(object $saleItem, float $quantity, string $stockOutAt, int $createdBy): void
    {
        $this->idempotencyService->retryOnCollision('stock_out_code', function () use ($saleItem, $quantity, $stockOutAt, $createdBy): void {
            DB::table('stock_outs')->insert([
                'stock_out_code' => $this->nextCode('stock_outs', 'stock_out_code', 'STO'),
                'sale_id' => $saleItem->sale_id,
                'sale_item_id' => $saleItem->id,
                'customer_id' => $saleItem->customer_id,
                'fuel_type_id' => $saleItem->fuel_type_id,
                'storage_location_id' => null,
                'source_type' => 'garage',
                'quantity_liters' => $quantity,
                'stock_out_at' => $stockOutAt,
                'status' => 'prepared',
                'created_by' => $createdBy,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * @return array{handled: bool, error: ?string}
     */
    private function releaseFromDirectDepotAllocations(object $saleItem, float $quantity, string $stockOutAt, int $createdBy): array
    {
        $allocations = DB::table('haul_allocations')
            ->join('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
            ->join('purchase_items', 'purchase_items.id', '=', 'hauls.purchase_item_id')
            ->join('purchases', 'purchases.id', '=', 'hauls.purchase_id')
            ->where('haul_allocations.destination_type', 'customer')
            ->where('haul_allocations.sale_id', $saleItem->sale_id)
            ->where('haul_allocations.customer_id', $saleItem->customer_id)
            ->where('haul_allocations.fuel_type_id', $saleItem->fuel_type_id)
            ->where('haul_allocations.status', '!=', 'cancelled')
            ->whereNull('purchases.deleted_at')
            ->whereColumn('hauls.purchase_id', 'purchase_items.purchase_id')
            ->whereColumn('hauls.depot_id', 'purchases.depot_id')
            ->whereColumn('hauls.fuel_type_id', 'purchase_items.fuel_type_id')
            ->whereColumn('haul_allocations.fuel_type_id', 'hauls.fuel_type_id')
            ->orderBy('haul_allocations.id')
            ->lockForUpdate()
            ->get([
                'haul_allocations.id',
                'haul_allocations.haul_id',
                'haul_allocations.quantity_liters',
                'hauls.quantity_liters as haul_quantity_liters',
                'hauls.status as haul_status',
                'hauls.depot_id',
                'purchase_items.unit_cost',
            ]);

        if ($allocations->isEmpty()) {
            return ['handled' => false, 'error' => null];
        }

        if ($allocations->contains(fn (object $allocation): bool => ! in_array($allocation->haul_status, ['lifted', 'completed'], true))) {
            return ['handled' => true, 'error' => 'Complete the linked direct depot lifting before marking this sale delivered.'];
        }

        foreach ($allocations->groupBy('haul_id') as $haulAllocations) {
            if (round((float) $haulAllocations->sum('quantity_liters'), 2) > round((float) $haulAllocations->first()->haul_quantity_liters, 2)) {
                return ['handled' => true, 'error' => 'The linked direct depot lifting has invalid allocation quantities.'];
            }
        }

        $available = 0.0;
        $committedByAllocation = [];
        foreach ($allocations as $allocation) {
            $committed = round((float) DB::table('stock_outs')
                ->where('haul_allocation_id', $allocation->id)
                ->where('source_type', 'depot')
                ->where('status', '!=', 'cancelled')
                ->lockForUpdate()
                ->sum('quantity_liters'), 2);
            $committedByAllocation[(int) $allocation->id] = $committed;
            $available = round($available + max(0, (float) $allocation->quantity_liters - $committed), 2);
        }

        if ($available < $quantity) {
            return ['handled' => true, 'error' => 'The linked direct depot allocation is insufficient to complete delivery.'];
        }

        DB::table('stock_outs')
            ->where('sale_item_id', $saleItem->id)
            ->where('status', 'prepared')
            ->update(['status' => 'cancelled', 'updated_at' => now()]);

        $toRelease = $quantity;
        foreach ($allocations as $allocation) {
            $allocationRemaining = round((float) $allocation->quantity_liters - ($committedByAllocation[(int) $allocation->id] ?? 0), 2);
            if ($allocationRemaining <= 0) {
                continue;
            }

            $releaseQuantity = round(min($allocationRemaining, $toRelease), 2);

            DB::table('sale_items')
                ->where('id', $saleItem->id)
                ->update([
                    'fulfilled_quantity_liters' => DB::raw('fulfilled_quantity_liters + '.$releaseQuantity),
                    'updated_at' => now(),
                ]);

            $this->idempotencyService->retryOnCollision('stock_out_code', function () use ($saleItem, $allocation, $releaseQuantity, $stockOutAt, $createdBy): void {
                DB::table('stock_outs')->insert([
                    'stock_out_code' => $this->nextCode('stock_outs', 'stock_out_code', 'STO'),
                    'sale_id' => $saleItem->sale_id,
                    'sale_item_id' => $saleItem->id,
                    'customer_id' => $saleItem->customer_id,
                    'fuel_type_id' => $saleItem->fuel_type_id,
                    'source_type' => 'depot',
                    'storage_location_id' => null,
                    'depot_id' => $allocation->depot_id,
                    'haul_allocation_id' => $allocation->id,
                    'quantity_liters' => $releaseQuantity,
                    'unit_cost' => $allocation->unit_cost,
                    'stock_out_at' => $stockOutAt,
                    'status' => 'released',
                    'created_by' => $createdBy,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            if (round($allocationRemaining - $releaseQuantity, 2) <= 0) {
                DB::table('haul_allocations')->where('id', $allocation->id)->update([
                    'status' => 'delivered',
                    'updated_at' => now(),
                ]);
            }

            $toRelease = round($toRelease - $releaseQuantity, 2);
            if ($toRelease <= 0) {
                break;
            }
        }

        return ['handled' => true, 'error' => null];
    }

    private function availableGarageStockForUpdate(int $garageId, int $fuelTypeId): float
    {
        DB::table('inventory_movements')
            ->where('storage_location_id', $garageId)
            ->where('fuel_type_id', $fuelTypeId)
            ->whereNotExists($this->cancelledStockOutExists())
            ->whereNotExists($this->cancelledHaulAllocationExists())
            ->lockForUpdate()
            ->get(['id']);

        return round((float) DB::table('inventory_movements')
            ->where('storage_location_id', $garageId)
            ->where('fuel_type_id', $fuelTypeId)
            ->whereNotExists($this->cancelledStockOutExists())
            ->whereNotExists($this->cancelledHaulAllocationExists())
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN quantity_liters ELSE -quantity_liters END), 0) as balance")
            ->value('balance'), 2);
    }

    private function cancelledStockOutExists(): \Closure
    {
        return function (Builder $query): void {
            $query->selectRaw('1')
                ->from('stock_outs')
                ->whereColumn('stock_outs.id', 'inventory_movements.reference_id')
                ->where('inventory_movements.reference_type', 'stock_out')
                ->where('stock_outs.status', 'cancelled');
        };
    }

    private function cancelledHaulAllocationExists(): \Closure
    {
        return function (Builder $query): void {
            $query->selectRaw('1')
                ->from('haul_allocations')
                ->whereColumn('haul_allocations.id', 'inventory_movements.reference_id')
                ->where('inventory_movements.reference_type', 'haul_allocation')
                ->where('haul_allocations.status', 'cancelled');
        };
    }

    private function nextCode(string $table, string $column, string $prefix): string
    {
        do {
            $code = $prefix.'-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        } while (DB::table($table)->where($column, $code)->exists());

        return $code;
    }
}
