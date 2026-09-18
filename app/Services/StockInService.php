<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockInService
{
    private const STOCK_IN_REFERENCE_TYPE = 'haul_allocation';

    public function __construct(
        private readonly IdempotencyService $idempotencyService,
        private readonly WorkflowAlertService $alerts
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function recordStockIn(array $data, int $userId): ?string
    {
        return DB::transaction(function () use ($data, $userId): ?string {
            $allocation = $this->garageAllocationForUpdate((int) $data['haul_allocation_id']);

            if (! $allocation) {
                return 'The selected stock-in source is invalid.';
            }

            $tank = DB::table('storage_locations')
                ->where('id', (int) $data['storage_location_id'])
                ->where('type', 'garage')
                ->where('status', 'active')
                ->where(function (Builder $query) use ($allocation): void {
                    $query->whereNull('fuel_type_id')->orWhere('fuel_type_id', $allocation->fuel_type_id);
                })
                ->lockForUpdate()
                ->first(['id']);

            if (! $tank) {
                return 'The selected destination tank is not active or does not match the fuel type.';
            }

            if ($allocation->storage_location_id && (int) $allocation->storage_location_id !== (int) $data['storage_location_id']) {
                return 'The selected garage does not match the haul allocation destination.';
            }

            if (! $this->haulAllocationsAreWithinQuantity((int) $allocation->haul_id, (float) $allocation->haul_quantity_liters)) {
                return 'The selected haul has invalid allocation quantities.';
            }

            $quantity = round((float) $data['quantity_liters'], 2);
            $remaining = $this->remainingReceivableForAllocation($allocation);

            if ($quantity > $remaining) {
                return 'Quantity received cannot exceed the remaining garage allocation.';
            }

            if ($this->duplicateStockInExists($allocation, (int) $data['storage_location_id'], $quantity, (string) $data['movement_date'])) {
                return 'This stock-in receipt has already been recorded.';
            }

            $receiptKey = (string) ($data['idempotency_key'] ?? Str::uuid());
            if (DB::table('stock_receipts')->where('idempotency_key', $receiptKey)->lockForUpdate()->exists()) {
                return 'This stock-in receipt has already been recorded.';
            }

            $movementId = $this->idempotencyService->retryOnCollision('movement_code', function () use ($data, $allocation, $quantity, $userId): int {
                return (int) DB::table('inventory_movements')->insertGetId([
                    'movement_code' => $this->nextCode('inventory_movements', 'movement_code', 'MOV'),
                    'storage_location_id' => $data['storage_location_id'],
                    'fuel_type_id' => $allocation->fuel_type_id,
                    'movement_type' => 'stock_in',
                    'direction' => 'in',
                    'quantity_liters' => $quantity,
                    'unit_cost' => $allocation->unit_cost,
                    'reference_type' => self::STOCK_IN_REFERENCE_TYPE,
                    'reference_id' => $allocation->id,
                    'movement_date' => $data['movement_date'],
                    'remarks' => $data['remarks'] ?? null,
                    'created_by' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            DB::table('stock_receipts')->insert([
                'receipt_code' => $this->nextCode('stock_receipts', 'receipt_code', 'RCV'),
                'idempotency_key' => $receiptKey,
                'haul_allocation_id' => $allocation->id,
                'storage_location_id' => $data['storage_location_id'],
                'inventory_movement_id' => $movementId,
                'quantity_liters' => $quantity,
                'received_at' => $data['movement_date'],
                'status' => 'stock_posted',
                'received_by' => $userId,
                'remarks' => $data['remarks'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $newRemaining = round($remaining - $quantity, 2);

            DB::table('haul_allocations')
                ->where('id', $allocation->id)
                ->update([
                    'status' => $newRemaining <= 0 ? 'received' : $allocation->status,
                    'storage_location_id' => $data['storage_location_id'],
                    'updated_at' => now(),
                ]);

            $this->alerts->purchase((int) $allocation->purchase_id, $newRemaining <= 0 ? 'received' : 'partially_received', $userId, (int) $allocation->haul_id);

            return null;
        });
    }

    public function garageAllocationForUpdate(int $allocationId): ?object
    {
        return DB::table('haul_allocations')
            ->join('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
            ->join('purchase_items', 'purchase_items.id', '=', 'hauls.purchase_item_id')
            ->join('purchases', 'purchases.id', '=', 'hauls.purchase_id')
            ->leftJoin('storage_locations', 'storage_locations.id', '=', 'haul_allocations.storage_location_id')
            ->where('haul_allocations.id', $allocationId)
            ->where('haul_allocations.destination_type', 'garage')
            ->where('haul_allocations.status', '!=', 'cancelled')
            ->where('hauls.status', 'completed')
            ->whereNull('purchases.deleted_at')
            ->whereColumn('hauls.purchase_id', 'purchase_items.purchase_id')
            ->whereColumn('hauls.depot_id', 'purchases.depot_id')
            ->whereColumn('hauls.fuel_type_id', 'purchase_items.fuel_type_id')
            ->whereColumn('haul_allocations.fuel_type_id', 'hauls.fuel_type_id')
            ->where(function (Builder $query): void {
                $query->whereNull('haul_allocations.storage_location_id')
                    ->orWhere(function (Builder $query): void {
                        $query->where('storage_locations.type', 'garage')
                            ->where('storage_locations.status', 'active')
                            ->where(function (Builder $query): void {
                                $query->whereNull('storage_locations.fuel_type_id')
                                    ->orWhereColumn('storage_locations.fuel_type_id', 'haul_allocations.fuel_type_id');
                            });
                    });
            })
            ->lockForUpdate()
            ->first([
                'haul_allocations.id',
                'haul_allocations.haul_id',
                'haul_allocations.storage_location_id',
                'haul_allocations.fuel_type_id',
                'haul_allocations.quantity_liters',
                'haul_allocations.status',
                'purchases.id as purchase_id',
                'hauls.quantity_liters as haul_quantity_liters',
                'purchase_items.unit_cost',
            ]);
    }

    public function remainingReceivableForAllocation(object $allocation): float
    {
        $received = (float) DB::table('inventory_movements')
            ->where('reference_type', self::STOCK_IN_REFERENCE_TYPE)
            ->where('reference_id', $allocation->id)
            ->where('direction', 'in')
            ->where('movement_type', 'stock_in')
            ->sum('quantity_liters');

        return round(max(0, (float) $allocation->quantity_liters - $received), 2);
    }

    public function duplicateStockInExists(object $allocation, int $garageId, float $quantity, string $movementDate): bool
    {
        return DB::table('inventory_movements')
            ->where('reference_type', self::STOCK_IN_REFERENCE_TYPE)
            ->where('reference_id', $allocation->id)
            ->where('storage_location_id', $garageId)
            ->where('fuel_type_id', $allocation->fuel_type_id)
            ->where('direction', 'in')
            ->where('movement_type', 'stock_in')
            ->where('quantity_liters', $quantity)
            ->where('movement_date', $movementDate)
            ->lockForUpdate()
            ->first(['id']) !== null;
    }

    public function haulAllocationsAreWithinQuantity(int $haulId, float $haulQuantity): bool
    {
        DB::table('haul_allocations')
            ->where('haul_id', $haulId)
            ->lockForUpdate()
            ->get(['id']);

        $allocated = (float) DB::table('haul_allocations')
            ->where('haul_id', $haulId)
            ->where('status', '!=', 'cancelled')
            ->sum('quantity_liters');

        return round($allocated, 2) <= round($haulQuantity, 2);
    }

    public function stockInRows(?string $search, int $perPage = 25, string $pageName = 'stock_in_page'): LengthAwarePaginator
    {
        $query = DB::table('inventory_movements')
            ->join('storage_locations', 'storage_locations.id', '=', 'inventory_movements.storage_location_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'inventory_movements.fuel_type_id')
            ->leftJoin('users', 'users.id', '=', 'inventory_movements.created_by')
            ->where('inventory_movements.direction', 'in')
            ->where('inventory_movements.movement_type', 'stock_in')
            ->whereNotExists($this->cancelledHaulAllocationExists())
            ->when($search, fn (Builder $q): Builder => $this->applySearch($q, $search, [
                'inventory_movements.movement_code',
                'storage_locations.name',
                'fuel_types.name',
                'inventory_movements.movement_type',
                'inventory_movements.remarks',
                'users.name',
            ]))
            ->orderByDesc('inventory_movements.movement_date')
            ->select([
                'inventory_movements.id',
                'inventory_movements.movement_code',
                'inventory_movements.movement_date',
                'inventory_movements.movement_type',
                'inventory_movements.quantity_liters',
                'inventory_movements.unit_cost',
                'inventory_movements.reference_type',
                'inventory_movements.reference_id',
                'inventory_movements.remarks',
                'storage_locations.name as location_name',
                'fuel_types.name as fuel_name',
                'users.name as created_by_name',
            ]);

        $paginator = $query->paginate($perPage, ['*'], $pageName)->withQueryString();
        $movements = collect($paginator->items());

        $references = $this->stockInReferenceLabels($movements);

        $transformed = $movements->map(fn (object $row): array => [
            'modal_id' => 'io-stockin-detail-'.$row->id,
            'class' => 'row-success',
            'cells' => [
                $references[$this->referenceKey($row->reference_type, (int) $row->reference_id)] ?? $row->movement_code,
                $this->formatDateTime($row->movement_date),
                $row->fuel_name,
                $row->location_name,
                $this->formatNumber($row->quantity_liters),
                $this->formatNumber($row->unit_cost),
                $this->formatNumber(((float) $row->quantity_liters) * ((float) ($row->unit_cost ?? 0))),
                $this->formatNumber($row->quantity_liters),
                '0.00',
                'Confirmed',
            ],
            'details' => [
                'Stock-In ID' => $row->movement_code,
                'Date' => $this->formatDateTime($row->movement_date),
                'Fuel' => $row->fuel_name,
                'Garage' => $row->location_name,
                'Quantity Received' => $this->formatLiters($row->quantity_liters),
                'Cost / Liter' => $this->formatNumber($row->unit_cost),
                'Source' => $references[$this->referenceKey($row->reference_type, (int) $row->reference_id)] ?? $this->label($row->reference_type).' #'.$row->reference_id,
                'Received By' => $row->created_by_name ?: 'N/A',
                'Remarks' => $row->remarks ?: 'N/A',
                'Status' => 'Confirmed',
            ],
        ]);

        $paginator->setCollection($transformed);

        return $paginator;
    }

    /**
     * @param  Collection<int, object>  $movements
     * @return array<string, string>
     */
    public function stockInReferenceLabels(Collection $movements): array
    {
        $allocationIds = $movements
            ->where('reference_type', self::STOCK_IN_REFERENCE_TYPE)
            ->pluck('reference_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($allocationIds->isEmpty()) {
            return [];
        }

        return DB::table('haul_allocations')
            ->join('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
            ->join('purchases', 'purchases.id', '=', 'hauls.purchase_id')
            ->leftJoin('depots', 'depots.id', '=', 'hauls.depot_id')
            ->whereIn('haul_allocations.id', $allocationIds->all())
            ->get([
                'haul_allocations.id',
                'haul_allocations.fuel_type_id',
                'purchases.purchase_code',
                'hauls.haul_code',
                'depots.name as depot_name',
            ])
            ->mapWithKeys(fn (object $row): array => [
                $this->referenceKey(self::STOCK_IN_REFERENCE_TYPE, (int) $row->id) => $row->purchase_code.' / '.$row->haul_code.' / '.($row->depot_name ?: 'Depot'),
            ])
            ->all();
    }

    private function cancelledHaulAllocationExists(): \Closure
    {
        return function (Builder $query): void {
            $query->selectRaw('1')
                ->from('haul_allocations')
                ->whereColumn('haul_allocations.id', 'inventory_movements.reference_id')
                ->where('inventory_movements.reference_type', self::STOCK_IN_REFERENCE_TYPE)
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

    private function referenceKey(?string $type, int $id): string
    {
        return ((string) $type).':'.$id;
    }

    private function label(?string $value): string
    {
        return ucwords(str_replace('_', ' ', (string) $value));
    }

    private function formatDateTime(mixed $date): string
    {
        return $date ? date('n/j/Y h:i A', strtotime((string) $date)) : 'N/A';
    }

    private function formatNumber(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2);
    }

    private function formatLiters(mixed $value): string
    {
        return $this->formatNumber($value).' L';
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function applySearch(Builder $query, string $term, array $columns): Builder
    {
        return $query->where(function (Builder $query) use ($term, $columns): void {
            foreach ($columns as $column) {
                $query->orWhere($column, 'like', '%'.$term.'%');
            }
        });
    }
}
