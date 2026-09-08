<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class StockOutReleaseService
{
    public function releaseSaleItemFromGarage(int $saleItemId, float $quantity, string $stockOutAt, int $createdBy, ?int $preferredGarageId = null, ?string $remarks = null): ?string
    {
        $saleItem = $this->saleItemForStockOut($saleItemId);

        if (! $saleItem) {
            return 'The selected sale is not eligible for stock-out.';
        }

        if ($this->releasedQuantityForSaleItem((int) $saleItem->id) > 0) {
            return null;
        }

        $remaining = round((float) $saleItem->quantity_liters - (float) $saleItem->fulfilled_quantity_liters, 2);
        $quantity = round($quantity, 2);

        if ($quantity > $remaining) {
            return 'Quantity released cannot exceed the remaining sale quantity.';
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
            return null;
        }

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

    private function releasedQuantityForSaleItem(int $saleItemId): float
    {
        return round((float) DB::table('stock_outs')
            ->where('sale_item_id', $saleItemId)
            ->where('status', '!=', 'cancelled')
            ->lockForUpdate()
            ->selectRaw('COALESCE(SUM(quantity_liters), 0) as released_liters')
            ->value('released_liters'), 2);
    }

    private function insertGarageRelease(object $saleItem, int $garageId, float $quantity, string $stockOutAt, int $createdBy, ?string $remarks): void
    {
        DB::table('sale_items')
            ->where('id', $saleItem->id)
            ->update([
                'fulfilled_quantity_liters' => DB::raw('fulfilled_quantity_liters + '.$quantity),
                'updated_at' => now(),
            ]);

        $stockOutId = DB::table('stock_outs')->insertGetId([
            'stock_out_code' => $this->nextCode('stock_outs', 'stock_out_code', 'STO'),
            'sale_id' => $saleItem->sale_id,
            'sale_item_id' => $saleItem->id,
            'customer_id' => $saleItem->customer_id,
            'fuel_type_id' => $saleItem->fuel_type_id,
            'storage_location_id' => $garageId,
            'source_type' => 'garage',
            'quantity_liters' => $quantity,
            'stock_out_at' => $stockOutAt,
            'status' => 'released',
            'created_by' => $createdBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $movementId = DB::table('inventory_movements')->insertGetId([
            'movement_code' => $this->nextCode('inventory_movements', 'movement_code', 'MOV'),
            'storage_location_id' => $garageId,
            'fuel_type_id' => $saleItem->fuel_type_id,
            'movement_type' => 'stock_out',
            'direction' => 'out',
            'quantity_liters' => $quantity,
            'unit_cost' => null,
            'reference_type' => 'stock_out',
            'reference_id' => $stockOutId,
            'movement_date' => $stockOutAt,
            'remarks' => $remarks,
            'created_by' => $createdBy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('stock_outs')
            ->where('id', $stockOutId)
            ->update([
                'inventory_movement_id' => $movementId,
                'updated_at' => now(),
            ]);
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
        $nextId = ((int) DB::table($table)->max('id')) + 1;

        do {
            $code = $prefix.'-'.str_pad((string) $nextId, 6, '0', STR_PAD_LEFT);
            $nextId++;
        } while (DB::table($table)->where($column, $code)->exists());

        return $code;
    }
}
