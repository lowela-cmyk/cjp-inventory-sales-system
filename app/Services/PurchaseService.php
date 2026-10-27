<?php

namespace App\Services;

use App\Support\FilterDates;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PurchaseService
{
    private const STOCK_IN_REFERENCE_TYPE = 'haul_allocation';

    /** Part 9: how a purchase was paid. A bank is chosen for Bank Transfer and Cheque only. */
    public const PAYMENT_MODES = ['bank_transfer' => 'Bank Transfer', 'cash' => 'Cash', 'cheque' => 'Cheque'];

    public const BANKS = ['BPI', 'BDO', 'RCBC'];

    public function __construct(
        private readonly IdempotencyService $idempotencyService,
        private readonly WorkflowAlertService $alerts,
        private readonly PurchaseWorkflowService $workflow,
        private readonly PurchaseStockService $purchaseStock,
        private readonly StockInService $stockIns
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
                    'payment_mode' => $data['payment_mode'] ?? null,
                    'bank_name' => $data['bank_name'] ?? null,
                    'status' => 'ordered',
                    'workflow_status' => 'pending',
                    'created_by' => $userId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            $purchaseItemId = (int) DB::table('purchase_items')->insertGetId([
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

            // Stock-In revisions: the purchase is in stock as soon as it is recorded (no lift needed).
            $this->stockIns->sync($purchaseItemId, $userId);

            DB::table('purchase_status_histories')->insert([
                'purchase_id' => $purchaseId,
                'previous_status' => null,
                'new_status' => 'pending',
                'changed_by' => $userId,
                'changed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->alerts->purchase($purchaseId, 'created', $userId);

            return $purchaseId;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updatePurchase(int $purchaseItemId, array $data, ?int $userId = null): void
    {
        $lineTotal = $this->lineTotal($data['quantity_ordered_liters'], $data['unit_cost']);
        if ($lineTotal > 999999999999.99) {
            throw ValidationException::withMessages([
                'quantity_ordered_liters' => 'Calculated total purchase cost exceeds allowed limit.',
            ]);
        }

        DB::transaction(function () use ($purchaseItemId, $data, $userId): void {
            $row = $this->purchaseItemForUpdate($purchaseItemId);
            if (! $row) {
                abort(404);
            }

            $this->assertEditableStock($row, $data);

            DB::table('purchases')
                ->where('id', $row->purchase_id)
                ->update([
                    'depot_id' => $data['depot_id'],
                    'purchase_date' => $data['purchase_date'],
                    'payment_status' => $data['payment_status'],
                    'payment_mode' => $data['payment_mode'] ?? null,
                    'bank_name' => $data['bank_name'] ?? null,
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

            $this->stockIns->sync((int) $row->id, $userId);
            $this->workflow->synchronize((int) $row->purchase_id, $userId);
        });
    }

    public function cancelPurchase(int $purchaseItemId, ?int $userId = null): void
    {
        DB::transaction(function () use ($purchaseItemId, $userId): void {
            $row = $this->purchaseItemForUpdate($purchaseItemId);
            if (! $row) {
                abort(404);
            }

            $sales = $this->salesDeductingFrom((int) $row->id);
            if ($sales !== []) {
                throw ValidationException::withMessages([
                    'purchase' => 'This purchase cannot be cancelled: '.count($sales).' sale(s) already deducted fuel from it ('.implode(', ', $sales).'). Cancel those sales first.',
                ]);
            }
            if ($this->hasDependentActivity($row)) {
                throw ValidationException::withMessages([
                    'purchase' => 'This purchase already has lifting activity and cannot be cancelled from Purchases.',
                ]);
            }

            DB::table('purchases')
                ->where('id', $row->purchase_id)
                ->update([
                    'status' => 'cancelled',
                    'updated_at' => now(),
                ]);
            // Its stock-in is voided with it.
            $this->stockIns->sync((int) $row->id, $userId);

            $this->workflow->transition((int) $row->purchase_id, 'cancelled', $userId);
        });
    }

    public function purchaseItemForUpdate(int $purchaseItemId): ?object
    {
        return DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where('purchase_items.id', $purchaseItemId)
            ->whereNull('purchases.deleted_at')
            ->lockForUpdate()
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

    /**
     * @param  array{status?: ?string, date?: ?string, depot_id?: mixed, fuel_type_id?: mixed}  $filters
     */
    public function purchaseRows(?string $search, int $perPage = 25, string $pageName = 'purchases_page', array $filters = []): LengthAwarePaginator
    {
        $haulTotals = DB::table('hauls')
            ->whereNull('deleted_at')
            ->where('status', '!=', 'cancelled')
            ->selectRaw('purchase_item_id, COALESCE(SUM(quantity_liters), 0) as hauled_liters')
            ->groupBy('purchase_item_id');

        $withdrawalTotals = DB::table('hauls')
            ->whereNull('deleted_at')
            ->whereNotNull('withdrawal_receipt_path')
            ->selectRaw('purchase_item_id, COUNT(*) as withdrawal_count, MAX(withdrawal_receipt_uploaded_at) as latest_withdrawal_at')
            ->groupBy('purchase_item_id');

        $query = DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->join('depots', 'depots.id', '=', 'purchases.depot_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'purchase_items.fuel_type_id')
            ->leftJoin('users', 'users.id', '=', 'purchases.created_by')
            ->leftJoinSub($haulTotals, 'haul_totals', 'haul_totals.purchase_item_id', '=', 'purchase_items.id')
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
            ->when($filters['status'] ?? null, fn (Builder $q, string $status): Builder => $q->where('purchases.workflow_status', $status))
            ->when($filters['date'] ?? null, fn (Builder $q, string $date): Builder => $q->whereDate('purchases.purchase_date', $date))
            ->tap(fn (Builder $q): Builder => FilterDates::apply($q, 'purchases.purchase_date', ['from' => $filters['date_from'] ?? null, 'to' => $filters['date_to'] ?? null]))
            ->when($filters['depot_id'] ?? null, fn (Builder $q, mixed $depotId): Builder => $q->where('purchases.depot_id', (int) $depotId))
            ->when($filters['fuel_type_id'] ?? null, fn (Builder $q, mixed $fuelTypeId): Builder => $q->where('purchase_items.fuel_type_id', (int) $fuelTypeId))
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
                'purchases.payment_mode',
                'purchases.bank_name',
                'purchases.status as legacy_purchase_status',
                'purchases.workflow_status as purchase_status',
                'purchases.created_at',
                'purchases.updated_at',
                'depots.name as depot_name',
                'fuel_types.name as fuel_name',
                'users.name as created_by_name',
                DB::raw('COALESCE(haul_totals.hauled_liters, 0) as hauled_liters'),
                DB::raw('COALESCE(withdrawal_totals.withdrawal_count, 0) as withdrawal_count'),
                'withdrawal_totals.latest_withdrawal_at',
            ]);

        $paginator = $query->paginate($perPage, ['*'], $pageName)->withQueryString();
        $rawRows = collect($paginator->items());

        $dependencyMap = $this->purchaseDependencyMap($rawRows);
        $withdrawalsMap = $this->batchWithdrawalsForPurchaseItems($rawRows);
        // Part 2 revisions: the purchase is the stock (ordered - delivered).
        $stock = $this->purchaseStock->lines($rawRows->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(), false, true);

        $transformed = $rawRows->map(function (object $row) use ($dependencyMap, $withdrawalsMap, $stock): array {
            $line = $stock->get((int) $row->id);
            $released = (float) ($line->released ?? 0);
            $onHand = (float) ($line->on_hand ?? $row->quantity_ordered_liters);
            $inventoryStatus = match (true) {
                $onHand <= 0 => 'depleted',
                $released > 0 => 'partially_delivered',
                default => 'in_stock',
            };

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
                'payment_mode' => $row->payment_mode,
                'bank_name' => $row->bank_name,
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
                    // Garage / direct allocation stay in the details and the lifting logic; the table hides them.
                    $this->formatNumber($released),
                    $this->label($inventoryStatus),
                    $this->formatNumber($row->unit_cost),
                    $this->formatNumber($row->line_total),
                    $this->withdrawalStatus((int) $row->withdrawal_count),
                    $this->label($row->purchase_status),
                    $this->label($row->payment_status),
                    self::PAYMENT_MODES[$row->payment_mode] ?? 'N/A',
                    $row->bank_name ?: 'N/A',
                    $this->formatNumber($onHand),
                ],
                'details' => [
                    'Date' => $this->formatDate($row->purchase_date),
                    'Fuel' => $row->fuel_name,
                    'Depot' => $row->depot_name,
                    'Quantity Purchased' => $this->formatLiters($row->quantity_ordered_liters),
                    'Quantity Hauled' => $this->formatLiters($row->hauled_liters),
                    'Quantity Delivered' => $this->formatLiters($released),
                    'Remaining (Stock)' => $this->formatLiters($onHand),
                    'Reserved for Orders' => $this->formatLiters($line->reserved ?? 0),
                    'Remaining in Depot' => $this->formatLiters($line->remaining_in_depot ?? 0),
                    'Inventory Status' => $this->label($inventoryStatus),
                    'Cost/Liter' => $this->formatNumber($row->unit_cost),
                    'Total Cost' => $this->formatNumber($row->line_total),
                    'Payment Mode' => self::PAYMENT_MODES[$row->payment_mode] ?? 'N/A',
                    'Bank' => $row->bank_name ?: 'N/A',
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
            ->whereNull('deleted_at')
            ->whereNotNull('withdrawal_receipt_path')
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
            ->merge(DB::table('hauls')->whereIn('purchase_item_id', $ids->all())->whereNull('deleted_at')->pluck('purchase_item_id'))
            // Part 2 revisions: orders sell from the purchase (its own fuel or an F1 portion).
            ->merge(DB::table('sale_items')->whereIn('purchase_item_id', $ids->all())->pluck('purchase_item_id'))
            ->merge(DB::table('sale_items')->whereIn('f1_purchase_item_id', $ids->all())->pluck('f1_purchase_item_id'))
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
            || DB::table('hauls')->where('purchase_item_id', $row->id)->whereNull('deleted_at')->exists()
            || $this->salesDeductingFrom((int) $row->id) !== []
            || DB::table('inventory_movements')
                ->where('reference_type', 'purchase_item')
                ->where('reference_id', $row->id)
                ->exists();
    }

    /**
     * Stock-In revisions: a purchase stays editable after sales took from it, within limits. Its quantity can't drop
     * below what sales already took (delivered or promised) or what its lifts carry, and its fuel and depot stay fixed
     * once sales or lifts use it (they were recorded against them). Date and cost can always be corrected.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function assertEditableStock(object $row, array $data): void
    {
        $line = $this->purchaseStock->line((int) $row->id, true);
        $taken = round((float) ($line->released ?? 0) + (float) ($line->reserved ?? 0), 2);
        $onLifts = round((float) DB::table('hauls')->where('purchase_item_id', $row->id)->whereNull('deleted_at')->where('status', '!=', 'cancelled')->sum('quantity_liters'), 2);
        $quantity = round((float) $data['quantity_ordered_liters'], 2);
        $errors = [];

        if ($quantity < $taken) {
            $errors['quantity_ordered_liters'] = "Quantity can't go below the ".number_format($taken, 2).' L already sold.';
        } elseif ($quantity < $onLifts) {
            $errors['quantity_ordered_liters'] = "Quantity can't go below the ".number_format($onLifts, 2).' L already on lifts.';
        }

        $used = $this->salesDeductingFrom((int) $row->id) !== [] || $onLifts > 0 || (float) ($row->quantity_hauled_liters ?? 0) > 0;
        if ($used && (int) $row->fuel_type_id !== (int) $data['fuel_type_id']) {
            $errors['fuel_type_id'] = "Fuel can't change: sales or lifts already use this purchase.";
        }
        if ($used && (int) $row->depot_id !== (int) $data['depot_id']) {
            $errors['depot_id'] = "Depot can't change: sales or lifts already use this purchase.";
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @return array<int, string> codes of sales (not cancelled) that took fuel from the purchase line */
    public function salesDeductingFrom(int $purchaseItemId): array
    {
        return DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereNull('sales.deleted_at')
            ->where('sales.status', '!=', 'cancelled')
            ->where(fn (Builder $query): Builder => $query->where('sale_items.purchase_item_id', $purchaseItemId)->orWhere('sale_items.f1_purchase_item_id', $purchaseItemId))
            ->orderBy('sales.sale_code')
            ->distinct()
            ->pluck('sales.sale_code')
            ->all();
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

    private function nextCode(string $table, string $column, string $prefix): string
    {
        do {
            $code = $prefix.'-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        } while (DB::table($table)->where($column, $code)->exists());

        return $code;
    }

    /** Purchases rows are white; only an Unpaid purchase is tinted red (Part 8 #3). */
    private function rowClass(?string $paymentStatus): string
    {
        return $paymentStatus === 'unpaid' ? 'row-danger' : '';
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
