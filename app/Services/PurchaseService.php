<?php

namespace App\Services;

use App\Services\IdempotencyService;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseService
{
    private const STOCK_IN_REFERENCE_TYPE = 'haul_allocation';

    public function __construct(
        private readonly IdempotencyService $idempotencyService
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function createPurchase(array $data, int $userId): int
    {
        $lineTotal = $this->lineTotal($data['quantity_ordered_liters'], $data['unit_cost']);
        if ($lineTotal > 999999999999.99) {
            throw ValidationException::withMessages([
                'quantity_ordered_liters' => 'Calculated total purchase cost exceeds allowed limit.',
            ]);
        }

        return DB::transaction(function () use ($data, $userId): int {
            $purchaseId = $this->idempotencyService->retryOnCollision('purchase_code', function () use ($data, $userId): int {
                return (int) DB::table('purchases')->insertGetId([
                    'purchase_code' => $this->nextCode('purchases', 'purchase_code', 'PUR'),
                    'depot_id' => $data['depot_id'],
                    'purchase_date' => $data['purchase_date'],
                    'receipt_reference' => null,
                    'payment_status' => $data['payment_status'],
                    'status' => $data['status'],
                    'created_by' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            DB::table('purchase_items')->insert([
                'purchase_id' => $purchaseId,
                'fuel_type_id' => $data['fuel_type_id'],
                'quantity_ordered_liters' => $data['quantity_ordered_liters'],
                'unit_cost' => $data['unit_cost'],
                'line_total' => $this->lineTotal($data['quantity_ordered_liters'], $data['unit_cost']),
                'quantity_hauled_liters' => 0,
                'status' => 'unlifted',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $purchaseId;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePurchase(int $purchaseItemId, array $data): void
    {
        $row = $this->purchaseItemForUpdate($purchaseItemId);
        if (! $row) {
            abort(404);
        }

        if ($this->hasDependentActivity($row) && $this->changesProtectedFields($row, $data)) {
            throw ValidationException::withMessages([
                'purchase' => 'This purchase already has hauling activity, so quantity, fuel, depot, and date cannot be changed.',
            ]);
        }

        $lineTotal = $this->lineTotal($data['quantity_ordered_liters'], $data['unit_cost']);
        if ($lineTotal > 999999999999.99) {
            throw ValidationException::withMessages([
                'quantity_ordered_liters' => 'Calculated total purchase cost exceeds allowed limit.',
            ]);
        }

        DB::transaction(function () use ($row, $data): void {
            DB::table('purchases')
                ->where('id', $row->purchase_id)
                ->update([
                    'depot_id' => $data['depot_id'],
                    'purchase_date' => $data['purchase_date'],
                    'payment_status' => $data['payment_status'],
                    'status' => $data['status'],
                    'updated_at' => now(),
                ]);

            DB::table('purchase_items')
                ->where('id', $row->id)
                ->update([
                    'fuel_type_id' => $data['fuel_type_id'],
                    'quantity_ordered_liters' => $data['quantity_ordered_liters'],
                    'unit_cost' => $data['unit_cost'],
                    'line_total' => $this->lineTotal($data['quantity_ordered_liters'], $data['unit_cost']),
                    'status' => $this->itemStatus((float) $row->quantity_hauled_liters, (float) $data['quantity_ordered_liters']),
                    'updated_at' => now(),
                ]);
        });
    }

    public function cancelPurchase(int $purchaseItemId): void
    {
        $row = $this->purchaseItemForUpdate($purchaseItemId);
        if (! $row) {
            abort(404);
        }

        if ($this->hasDependentActivity($row)) {
            throw ValidationException::withMessages([
                'purchase' => 'This purchase already has dependent activity and cannot be cancelled from Purchases.',
            ]);
        }

        DB::table('purchases')
            ->where('id', $row->purchase_id)
            ->update([
                'status' => 'cancelled',
                'updated_at' => now(),
            ]);
    }

    public function purchaseItemForUpdate(int $purchaseItemId): ?object
    {
        return DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where('purchase_items.id', $purchaseItemId)
            ->whereNull('purchases.deleted_at')
            ->first([
                'purchase_items.id',
                'purchase_items.purchase_id',
                'purchase_items.fuel_type_id',
                'purchase_items.quantity_ordered_liters',
                'purchase_items.quantity_hauled_liters',
                'purchase_items.unit_cost',
                'purchases.depot_id',
                'purchases.purchase_date',
            ]);
    }

    public function purchaseRows(?string $search, int $perPage = 25, string $pageName = 'purchases_page'): LengthAwarePaginator
    {
        $haulTotals = DB::table('hauls')
            ->where('status', '!=', 'cancelled')
            ->selectRaw('purchase_item_id, COALESCE(SUM(quantity_liters), 0) as hauled_liters')
            ->groupBy('purchase_item_id');

        $allocationTotals = DB::table('haul_allocations')
            ->join('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
            ->where('haul_allocations.status', '!=', 'cancelled')
            ->where('hauls.status', '!=', 'cancelled')
            ->selectRaw("
                hauls.purchase_item_id,
                COALESCE(SUM(CASE WHEN haul_allocations.destination_type = 'garage' THEN haul_allocations.quantity_liters ELSE 0 END), 0) as garage_allocated_liters,
                COALESCE(SUM(CASE WHEN haul_allocations.destination_type = 'customer' THEN haul_allocations.quantity_liters ELSE 0 END), 0) as direct_allocated_liters
            ")
            ->groupBy('hauls.purchase_item_id');

        $receivedTotals = DB::table('inventory_movements')
            ->join('haul_allocations', function ($join): void {
                $join->on('haul_allocations.id', '=', 'inventory_movements.reference_id')
                    ->where('inventory_movements.reference_type', self::STOCK_IN_REFERENCE_TYPE);
            })
            ->join('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
            ->where('inventory_movements.direction', 'in')
            ->where('inventory_movements.movement_type', 'stock_in')
            ->where('haul_allocations.status', '!=', 'cancelled')
            ->where('hauls.status', '!=', 'cancelled')
            ->selectRaw('hauls.purchase_item_id, COALESCE(SUM(inventory_movements.quantity_liters), 0) as received_liters')
            ->groupBy('hauls.purchase_item_id');

        $withdrawalTotals = DB::table('hauls')
            ->whereNotNull('withdrawal_receipt_path')
            ->where('status', '!=', 'cancelled')
            ->selectRaw('purchase_item_id, COUNT(*) as withdrawal_count, MAX(withdrawal_receipt_uploaded_at) as latest_withdrawal_at')
            ->groupBy('purchase_item_id');

        $query = DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->join('depots', 'depots.id', '=', 'purchases.depot_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'purchase_items.fuel_type_id')
            ->leftJoin('users', 'users.id', '=', 'purchases.created_by')
            ->leftJoinSub($haulTotals, 'haul_totals', 'haul_totals.purchase_item_id', '=', 'purchase_items.id')
            ->leftJoinSub($allocationTotals, 'allocation_totals', 'allocation_totals.purchase_item_id', '=', 'purchase_items.id')
            ->leftJoinSub($receivedTotals, 'received_totals', 'received_totals.purchase_item_id', '=', 'purchase_items.id')
            ->leftJoinSub($withdrawalTotals, 'withdrawal_totals', 'withdrawal_totals.purchase_item_id', '=', 'purchase_items.id')
            ->whereNull('purchases.deleted_at')
            ->when($search, fn (Builder $q): Builder => $this->applySearch($q, $search, [
                'purchases.purchase_code',
                'depots.name',
                'fuel_types.name',
                'purchases.payment_status',
                'purchases.status',
                'users.name',
            ]))
            ->orderByDesc('purchases.purchase_date')
            ->orderByDesc('purchase_items.id')
            ->select([
                'purchase_items.id',
                'purchase_items.purchase_id',
                'purchase_items.fuel_type_id',
                'purchase_items.quantity_ordered_liters',
                'purchase_items.quantity_hauled_liters',
                'purchase_items.unit_cost',
                'purchase_items.line_total',
                'purchase_items.status as item_status',
                'purchases.purchase_code',
                'purchases.depot_id',
                'purchases.purchase_date',
                'purchases.receipt_reference',
                'purchases.payment_status',
                'purchases.status as purchase_status',
                'purchases.created_at',
                'purchases.updated_at',
                'depots.name as depot_name',
                'fuel_types.name as fuel_name',
                'users.name as created_by_name',
                DB::raw('COALESCE(haul_totals.hauled_liters, 0) as hauled_liters'),
                DB::raw('COALESCE(allocation_totals.garage_allocated_liters, 0) as garage_allocated_liters'),
                DB::raw('COALESCE(allocation_totals.direct_allocated_liters, 0) as direct_allocated_liters'),
                DB::raw('COALESCE(received_totals.received_liters, 0) as received_liters'),
                DB::raw('COALESCE(withdrawal_totals.withdrawal_count, 0) as withdrawal_count'),
                'withdrawal_totals.latest_withdrawal_at',
            ]);

        $paginator = $query->paginate($perPage, ['*'], $pageName)->withQueryString();
        $rawRows = collect($paginator->items());

        $dependencyMap = $this->purchaseDependencyMap($rawRows);
        $withdrawalsMap = $this->batchWithdrawalsForPurchaseItems($rawRows);

        $transformed = $rawRows->map(function (object $row) use ($dependencyMap, $withdrawalsMap): array {
            $inventoryStatus = $this->inventoryLinkStatus(
                (float) $row->garage_allocated_liters,
                (float) $row->received_liters,
                (float) $row->direct_allocated_liters
            );

            return [
                'id' => $row->id,
                'modal_id' => 'io-purchase-edit-'.$row->id,
                'purchase_code' => $row->purchase_code,
                'purchase_date' => $row->purchase_date,
                'depot_id' => $row->depot_id,
                'fuel_type_id' => $row->fuel_type_id,
                'quantity_ordered_liters' => $row->quantity_ordered_liters,
                'unit_cost' => $row->unit_cost,
                'receipt_url' => null,
                'withdrawals' => $withdrawalsMap[(int) $row->id] ?? [],
                'payment_status' => $row->payment_status,
                'purchase_status' => $row->purchase_status,
                'has_dependencies' => $dependencyMap[(int) $row->id] ?? false,
                'class' => $this->rowClass($row->payment_status),
                'cells' => [
                    $row->purchase_code,
                    $this->formatDate($row->purchase_date),
                    $row->fuel_name,
                    $row->depot_name,
                    $this->formatNumber($row->quantity_ordered_liters),
                    $this->formatNumber($row->hauled_liters),
                    $this->formatNumber($row->garage_allocated_liters),
                    $this->formatNumber($row->direct_allocated_liters),
                    $this->formatNumber($row->received_liters),
                    $this->label($inventoryStatus),
                    $this->formatNumber($row->unit_cost),
                    $this->formatNumber($row->line_total),
                    $this->withdrawalStatus((int) $row->withdrawal_count),
                    $this->label($row->payment_status),
                ],
                'details' => [
                    'Date' => $this->formatDate($row->purchase_date),
                    'Fuel' => $row->fuel_name,
                    'Depot' => $row->depot_name,
                    'Quantity Purchased' => $this->formatLiters($row->quantity_ordered_liters),
                    'Quantity Hauled' => $this->formatLiters($row->hauled_liters),
                    'Garage Allocation' => $this->formatLiters($row->garage_allocated_liters),
                    'Direct Client Allocation' => $this->formatLiters($row->direct_allocated_liters),
                    'Received Into Garage' => $this->formatLiters($row->received_liters),
                    'Inventory Status' => $this->label($inventoryStatus),
                    'Cost/Liter' => $this->formatNumber($row->unit_cost),
                    'Total Cost' => $this->formatNumber($row->line_total),
                    'Withdrawal Receipts' => $this->withdrawalStatus((int) $row->withdrawal_count),
                    'Latest Withdrawal Upload' => $this->formatDateTime($row->latest_withdrawal_at),
                    'Purchase Status' => $this->label($row->purchase_status),
                    'Item Status' => $this->label($row->item_status),
                    'Created By' => $row->created_by_name ?: 'N/A',
                    'Created At' => $this->formatDateTime($row->created_at),
                    'Updated At' => $this->formatDateTime($row->updated_at),
                ],
            ];
        });

        $paginator->setCollection($transformed);

        return $paginator;
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array<int, array<int, array<string, string|null>>>
     */
    public function batchWithdrawalsForPurchaseItems(Collection $rows): array
    {
        $ids = $rows
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $withdrawals = DB::table('hauls')
            ->whereIn('purchase_item_id', $ids->all())
            ->whereNotNull('withdrawal_receipt_path')
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('withdrawal_receipt_uploaded_at')
            ->orderByDesc('id')
            ->get(['id', 'purchase_item_id', 'haul_code', 'withdrawal_receipt_notes', 'withdrawal_receipt_uploaded_at']);

        $grouped = [];
        foreach ($ids as $id) {
            $grouped[$id] = [];
        }

        foreach ($withdrawals as $withdrawal) {
            $itemId = (int) $withdrawal->purchase_item_id;
            $grouped[$itemId][] = [
                'haul_code' => $withdrawal->haul_code,
                'uploaded_at' => $this->formatDateTime($withdrawal->withdrawal_receipt_uploaded_at),
                'notes' => $withdrawal->withdrawal_receipt_notes ?: null,
                'url' => route('withdrawal-receipts.show', $withdrawal->id),
            ];
        }

        return $grouped;
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array<int, bool>
     */
    public function purchaseDependencyMap(Collection $rows): array
    {
        $ids = $rows
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $dependentIds = $rows
            ->filter(fn (object $row): bool => (float) ($row->quantity_hauled_liters ?? 0) > 0)
            ->pluck('id')
            ->merge(DB::table('hauls')->whereIn('purchase_item_id', $ids->all())->pluck('purchase_item_id'))
            ->merge(DB::table('inventory_movements')
                ->where('reference_type', 'purchase_item')
                ->whereIn('reference_id', $ids->all())
                ->pluck('reference_id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->all();

        $dependentSet = array_fill_keys($dependentIds, true);

        return $ids
            ->mapWithKeys(fn (int $id): array => [$id => isset($dependentSet[$id])])
            ->all();
    }

    public function hasDependentActivity(object $row): bool
    {
        return (float) ($row->quantity_hauled_liters ?? 0) > 0
            || DB::table('hauls')->where('purchase_item_id', $row->id)->exists()
            || DB::table('inventory_movements')
                ->where('reference_type', 'purchase_item')
                ->where('reference_id', $row->id)
                ->exists();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function changesProtectedFields(object $row, array $data): bool
    {
        return (int) $row->depot_id !== (int) $data['depot_id']
            || (int) $row->fuel_type_id !== (int) $data['fuel_type_id']
            || (string) $row->purchase_date !== (string) $data['purchase_date']
            || (float) $row->quantity_ordered_liters !== (float) $data['quantity_ordered_liters']
            || (float) $row->unit_cost !== (float) $data['unit_cost'];
    }

    public function lineTotal(mixed $quantity, mixed $unitCost): float
    {
        return round(((float) $quantity) * ((float) $unitCost), 2);
    }

    public function itemStatus(float $hauled, float $ordered): string
    {
        return match (true) {
            $hauled <= 0 => 'unlifted',
            $hauled >= $ordered => 'lifted',
            default => 'partial',
        };
    }

    private function inventoryLinkStatus(float $garageAllocated, float $received, float $directAllocated): string
    {
        if ($garageAllocated <= 0 && $directAllocated <= 0) {
            return 'not_allocated';
        }

        if ($garageAllocated <= 0 && $directAllocated > 0) {
            return 'direct_to_client';
        }

        if ($received <= 0) {
            return 'awaiting_garage_receipt';
        }

        if (round($received, 2) < round($garageAllocated, 2)) {
            return 'partially_received';
        }

        return $directAllocated > 0 ? 'garage_received_with_direct' : 'garage_received';
    }

    private function nextCode(string $table, string $column, string $prefix): string
    {
        do {
            $code = $prefix.'-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        } while (DB::table($table)->where($column, $code)->exists());

        return $code;
    }

    private function rowClass(?string $status): string
    {
        return match ($status) {
            'unpaid', 'cancelled' => 'row-danger',
            'partial' => 'row-warning',
            'paid' => 'row-success',
            default => '',
        };
    }

    private function label(?string $value): string
    {
        return ucwords(str_replace('_', ' ', (string) $value));
    }

    private function withdrawalStatus(int $count): string
    {
        return $count > 0 ? $count.' Uploaded' : 'No Withdrawal';
    }

    private function formatDate(mixed $date): string
    {
        return $date ? date('n/j/Y', strtotime((string) $date)) : 'N/A';
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
