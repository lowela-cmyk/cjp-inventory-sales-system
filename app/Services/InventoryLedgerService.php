<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryLedgerService
{
    /**
     * @return array{ledger: Collection<int, array<string, mixed>>, transactions: Collection<int, array<string, mixed>>, latestBalances: array<string, float>}
     */
    public function rows(?string $search = null): array
    {
        $purchaseRows = $this->purchaseProgressRows($search);
        $purchaseItemIds = $purchaseRows->pluck('purchase_item_id')->map(fn (mixed $id): int => (int) $id)->all();
        $liftsByPurchaseItem = $this->liftsByPurchaseItem($purchaseItemIds);
        $movementsByPurchaseItem = $this->movementSummariesByPurchaseItem($purchaseItemIds);
        $latestBalances = $this->latestBalances();

        $transactions = $purchaseRows->map(function (object $row) use ($liftsByPurchaseItem, $movementsByPurchaseItem): array {
            $purchased = round((float) $row->quantity_ordered_liters, 2);
            $lifted = round(min($purchased, (float) $row->total_lifted_liters), 2);
            $remaining = round(max(0, $purchased - $lifted), 2);
            $lifts = $liftsByPurchaseItem[(int) $row->purchase_item_id] ?? collect();
            $movementSummary = $movementsByPurchaseItem[(int) $row->purchase_item_id] ?? 'No inventory movements recorded';
            $status = $this->liftingStatus($lifted, $purchased);

            return [
                'id' => 'ledger-purchase-'.$row->purchase_item_id,
                'purchase_id' => (int) $row->purchase_id,
                'purchase_item_id' => (int) $row->purchase_item_id,
                'purchase_code' => $row->purchase_code,
                'status' => $status,
                'status_class' => $remaining <= 0 ? 'modal-complete' : 'modal-incomplete',
                'lifts' => $lifts,
                'search_text' => strtolower(implode(' ', [
                    $row->purchase_code,
                    $row->fuel_name,
                    $row->depot_name,
                    $status,
                    $movementSummary,
                    $lifts->pluck('search_text')->implode(' '),
                ])),
                'cells' => [
                    $row->purchase_code,
                    $row->fuel_name,
                    $row->depot_name,
                    $this->formatNumber($purchased),
                    $this->formatNumber($lifted),
                    $this->formatNumber($remaining),
                    $status,
                ],
                'details' => [
                    'Purchase ID' => $row->purchase_code,
                    'Purchase Date' => $this->formatDate($row->purchase_date),
                    'Fuel Type' => $row->fuel_name,
                    'Depot' => $row->depot_name,
                    'Purchased Quantity' => $this->formatLiters($purchased),
                    'Total Lifted' => $this->formatLiters($lifted),
                    'Remaining Quantity' => $this->formatLiters($remaining),
                    'Lift Transactions' => (string) $lifts->count(),
                    'Inventory Movements' => $movementSummary,
                    'Status' => $status,
                ],
            ];
        });

        return [
            'ledger' => $transactions
                ->filter(fn (array $row): bool => (float) str_replace(',', '', $row['cells'][5]) > 0)
                ->map(fn (array $row): array => $row['cells'])
                ->values(),
            'transactions' => $transactions->values(),
            'latestBalances' => $latestBalances,
        ];
    }

    private function purchaseProgressRows(?string $search): Collection
    {
        $completedLifts = DB::table('hauls')
            ->where('status', 'completed')
            ->selectRaw('purchase_item_id, COALESCE(SUM(quantity_liters), 0) as total_lifted_liters')
            ->groupBy('purchase_item_id');

        return DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->join('depots', 'depots.id', '=', 'purchases.depot_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'purchase_items.fuel_type_id')
            ->leftJoinSub($completedLifts, 'completed_lifts', 'completed_lifts.purchase_item_id', '=', 'purchase_items.id')
            ->whereNull('purchases.deleted_at')
            ->when($search, fn (Builder $query): Builder => $query->where(function (Builder $query) use ($search): void {
                foreach ([
                    'purchases.purchase_code',
                    'depots.name',
                    'fuel_types.name',
                    'purchases.status',
                    'purchase_items.status',
                ] as $column) {
                    $query->orWhere($column, 'like', '%'.$search.'%');
                }
            }))
            ->orderByDesc('purchases.purchase_date')
            ->orderByDesc('purchase_items.id')
            ->get([
                'purchase_items.id as purchase_item_id',
                'purchase_items.purchase_id',
                'purchase_items.quantity_ordered_liters',
                'purchases.purchase_code',
                'purchases.purchase_date',
                'depots.name as depot_name',
                'fuel_types.name as fuel_name',
                DB::raw('COALESCE(completed_lifts.total_lifted_liters, 0) as total_lifted_liters'),
            ]);
    }

    /**
     * @param array<int, int> $purchaseItemIds
     * @return array<int, Collection<int, array<string, mixed>>>
     */
    private function liftsByPurchaseItem(array $purchaseItemIds): array
    {
        if ($purchaseItemIds === []) {
            return [];
        }

        return DB::table('hauls')
            ->join('trucks', 'trucks.id', '=', 'hauls.truck_id')
            ->join('users as drivers', 'drivers.id', '=', 'hauls.driver_user_id')
            ->leftJoin('driver_profiles', 'driver_profiles.user_id', '=', 'drivers.id')
            ->whereIn('hauls.purchase_item_id', $purchaseItemIds)
            ->orderBy('hauls.scheduled_at')
            ->orderBy('hauls.id')
            ->get([
                'hauls.id',
                'hauls.purchase_item_id',
                'hauls.haul_code',
                'hauls.dr_number',
                'hauls.quantity_liters',
                'hauls.scheduled_at',
                'hauls.hauled_at',
                'hauls.status',
                'trucks.truck_code',
                'trucks.plate_number',
                'trucks.capacity_liters',
                'drivers.name as driver_name',
                'driver_profiles.driver_code',
            ])
            ->groupBy('purchase_item_id')
            ->map(fn (Collection $rows): Collection => $rows->values()->map(function (object $row, int $index): array {
                $truck = trim($row->truck_code.($row->plate_number ? ' / '.$row->plate_number : ''));
                $details = [
                    'Lift/Transaction ID' => $row->haul_code,
                    'Quantity' => $this->formatLiters($row->quantity_liters),
                    'Driver' => $row->driver_name,
                    'Truck' => $truck,
                    'Truck Capacity' => $this->formatLiters($row->capacity_liters),
                    'Assigned/Lift Date' => $this->formatDateTime($row->hauled_at ?: $row->scheduled_at),
                    'DR Number' => $row->dr_number ?: 'N/A',
                    'Status' => $this->label($row->status),
                ];

                return [
                    'sequence' => $index + 1,
                    'code' => $row->haul_code,
                    'quantity' => $this->formatLiters($row->quantity_liters),
                    'status' => $this->label($row->status),
                    'counts_as_lifted' => $row->status === 'completed',
                    'details' => $details,
                    'search_text' => strtolower(implode(' ', $details)),
                ];
            }))
            ->all();
    }

    private function liftingStatus(float $lifted, float $purchased): string
    {
        return match (true) {
            $lifted <= 0 => 'Incomplete',
            round($lifted, 2) >= round($purchased, 2) => 'Complete',
            default => 'Partially Lifted',
        };
    }

    /**
     * @param array<int, int> $purchaseItemIds
     * @return array<int, string>
     */
    private function movementSummariesByPurchaseItem(array $purchaseItemIds): array
    {
        if ($purchaseItemIds === []) {
            return [];
        }

        return DB::table('inventory_movements')
            ->leftJoin('haul_allocations', function ($join): void {
                $join->on('haul_allocations.id', '=', 'inventory_movements.reference_id')
                    ->where('inventory_movements.reference_type', 'haul_allocation');
            })
            ->leftJoin('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
            ->where(function (Builder $query) use ($purchaseItemIds): void {
                $query->where(function (Builder $query) use ($purchaseItemIds): void {
                    $query->where('inventory_movements.reference_type', 'purchase_item')
                        ->whereIn('inventory_movements.reference_id', $purchaseItemIds);
                })->orWhereIn('hauls.purchase_item_id', $purchaseItemIds);
            })
            ->whereNotExists($this->cancelledStockOutExists())
            ->whereNotExists($this->cancelledHaulAllocationExists())
            ->get([
                'inventory_movements.movement_type',
                'inventory_movements.quantity_liters',
                'inventory_movements.direction',
                'inventory_movements.reference_type',
                'inventory_movements.reference_id',
                'hauls.purchase_item_id as allocation_purchase_item_id',
            ])
            ->groupBy(function (object $row): int {
                return $row->reference_type === 'haul_allocation'
                    ? (int) $row->allocation_purchase_item_id
                    : (int) $row->reference_id;
            })
            ->map(function (Collection $rows): string {
                return $rows
                    ->groupBy('movement_type')
                    ->map(function (Collection $rows, string $type): string {
                        $quantity = $rows->sum(fn (object $row): float => (float) $row->quantity_liters);

                        return $this->label($type).': '.$this->formatLiters($quantity);
                    })
                    ->values()
                    ->implode(', ');
            })
            ->all();
    }

    /**
     * @param Collection<int, object> $movements
     * @return array<string, string>
     */
    private function referenceLabels(Collection $movements): array
    {
        $labels = [];
        $idsByType = $movements
            ->groupBy(fn (object $row): string => (string) $row->reference_type)
            ->map(fn (Collection $rows): array => $rows->pluck('reference_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());

        foreach ($this->purchaseItemReferences($idsByType->get('purchase_item', [])) as $id => $label) {
            $labels[$this->referenceKey('purchase_item', (int) $id)] = $label;
        }

        foreach ($this->haulAllocationReferences($idsByType->get('haul_allocation', [])) as $id => $label) {
            $labels[$this->referenceKey('haul_allocation', (int) $id)] = $label;
        }

        foreach ($this->stockOutReferences($idsByType->get('stock_out', [])) as $id => $label) {
            $labels[$this->referenceKey('stock_out', (int) $id)] = $label;
        }

        return $labels;
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, string>
     */
    private function purchaseItemReferences(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $haulCodes = DB::table('hauls')
            ->whereIn('purchase_item_id', $ids)
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get(['purchase_item_id', 'haul_code'])
            ->groupBy('purchase_item_id')
            ->map(fn (Collection $rows): string => $rows->pluck('haul_code')->implode(', '));

        return DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->whereIn('purchase_items.id', $ids)
            ->get(['purchase_items.id', 'purchases.purchase_code'])
            ->mapWithKeys(function (object $row) use ($haulCodes): array {
                $codes = $haulCodes->get($row->id);

                return [(int) $row->id => $row->purchase_code.($codes ? ' / '.$codes : '')];
            })
            ->all();
    }

    /**
     * @return array<string, float>
     */
    private function latestBalances(): array
    {
        return DB::table('inventory_movements')
            ->whereNotExists($this->cancelledStockOutExists())
            ->whereNotExists($this->cancelledHaulAllocationExists())
            ->selectRaw("storage_location_id, fuel_type_id, COALESCE(SUM(CASE WHEN direction = 'in' THEN quantity_liters ELSE -quantity_liters END), 0) as balance")
            ->groupBy('storage_location_id', 'fuel_type_id')
            ->get()
            ->mapWithKeys(fn (object $row): array => [
                $row->storage_location_id.'-'.$row->fuel_type_id => round((float) $row->balance, 2),
            ])
            ->all();
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

    /**
     * @param array<int, int> $ids
     * @return array<int, string>
     */
    private function haulAllocationReferences(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('haul_allocations')
            ->join('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
            ->join('purchases', 'purchases.id', '=', 'hauls.purchase_id')
            ->whereIn('haul_allocations.id', $ids)
            ->get(['haul_allocations.id', 'purchases.purchase_code', 'hauls.haul_code'])
            ->mapWithKeys(fn (object $row): array => [
                (int) $row->id => $row->purchase_code.' / '.$row->haul_code,
            ])
            ->all();
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, string>
     */
    private function stockOutReferences(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('stock_outs')
            ->leftJoin('sales', 'sales.id', '=', 'stock_outs.sale_id')
            ->whereIn('stock_outs.id', $ids)
            ->get(['stock_outs.id', 'stock_outs.stock_out_code', 'sales.sale_code'])
            ->mapWithKeys(fn (object $row): array => [
                (int) $row->id => $row->stock_out_code.($row->sale_code ? ' / '.$row->sale_code : ''),
            ])
            ->all();
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

    private function formatDate(mixed $date): string
    {
        return $date ? date('n/j/Y', strtotime((string) $date)) : 'N/A';
    }

    private function formatLiters(mixed $value): string
    {
        return $this->formatNumber($value).' L';
    }

    private function formatNumber(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2);
    }
}
