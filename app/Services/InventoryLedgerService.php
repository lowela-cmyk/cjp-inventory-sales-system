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
        $base = DB::table('inventory_movements')
            ->join('storage_locations', 'storage_locations.id', '=', 'inventory_movements.storage_location_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'inventory_movements.fuel_type_id')
            ->leftJoin('users', 'users.id', '=', 'inventory_movements.created_by')
            ->whereNotExists($this->cancelledStockOutExists())
            ->whereNotExists($this->cancelledHaulAllocationExists())
            ->select([
                'inventory_movements.id',
                'inventory_movements.movement_code',
                'inventory_movements.movement_date',
                'inventory_movements.movement_type',
                'inventory_movements.direction',
                'inventory_movements.quantity_liters',
                'inventory_movements.unit_cost',
                'inventory_movements.reference_type',
                'inventory_movements.reference_id',
                'inventory_movements.remarks',
                'inventory_movements.created_at',
                'storage_locations.id as storage_location_id',
                'storage_locations.name as location_name',
                'fuel_types.id as fuel_type_id',
                'fuel_types.name as fuel_name',
                'users.name as created_by_name',
            ])
            ->selectRaw("SUM(CASE WHEN inventory_movements.direction = 'in' THEN inventory_movements.quantity_liters ELSE -inventory_movements.quantity_liters END) OVER (PARTITION BY inventory_movements.storage_location_id, inventory_movements.fuel_type_id ORDER BY inventory_movements.movement_date, inventory_movements.created_at, inventory_movements.id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) as running_balance");

        $query = DB::query()->fromSub($base, 'ledger_rows');

        if ($search) {
            $query->where(function (Builder $query) use ($search): void {
                foreach ([
                    'movement_code',
                    'movement_type',
                    'direction',
                    'fuel_name',
                    'location_name',
                    'remarks',
                    'created_by_name',
                ] as $column) {
                    $query->orWhere($column, 'like', '%'.$search.'%');
                }
            });
        }

        $movements = $query
            ->orderByDesc('movement_date')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        $references = $this->referenceLabels($movements);
        $latestBalances = $this->latestBalances();

        $rows = $movements->map(function (object $row) use ($references): array {
            $signedQuantity = $row->direction === 'out' ? -((float) $row->quantity_liters) : (float) $row->quantity_liters;
            $reference = $references[$this->referenceKey($row->reference_type, (int) $row->reference_id)]
                ?? $this->label($row->reference_type).' #'.$row->reference_id;
            $stockIn = $row->direction === 'in' ? $this->formatNumber($row->quantity_liters) : '0.00';
            $stockOut = $row->direction === 'out' ? $this->formatNumber($row->quantity_liters) : '0.00';
            $status = $this->label($row->movement_type);

            return [
                'id' => 'ledger-movement-'.$row->id,
                'search_text' => strtolower(implode(' ', [
                    $row->movement_code,
                    $reference,
                    $row->movement_type,
                    $row->direction,
                    $row->fuel_name,
                    $row->location_name,
                    $row->remarks,
                    $row->created_by_name,
                    $status,
                ])),
                'cells' => [
                    $reference,
                    $this->formatDateTime($row->movement_date),
                    $row->fuel_name,
                    $row->location_name,
                    $stockIn,
                    $stockOut,
                    $this->formatNumber(abs($signedQuantity)),
                    $this->formatNumber($row->running_balance),
                    $status,
                ],
                'status' => $status,
                'class' => $row->direction === 'out' ? 'row-warning' : 'row-success',
                'details' => [
                    'Movement ID' => $row->movement_code,
                    'Date' => $this->formatDateTime($row->movement_date),
                    'Reference' => $reference,
                    'Transaction Type' => $status,
                    'Fuel' => $row->fuel_name,
                    'Garage' => $row->location_name,
                    'Stock In' => $stockIn,
                    'Stock Out' => $stockOut,
                    'Running Balance' => $this->formatNumber($row->running_balance),
                    'Created By' => $row->created_by_name ?: 'N/A',
                    'Remarks' => $row->remarks ?: 'N/A',
                ],
            ];
        });

        return [
            'ledger' => $rows->map(fn (array $row): array => $row['cells']),
            'transactions' => $rows,
            'latestBalances' => $latestBalances,
        ];
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

    private function formatNumber(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2);
    }
}
