<?php

namespace App\Services;

use App\Support\FilterDates;
use App\Support\HistoricalReconciliation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stock-In revisions (October 23, 2026): one stock-in record per purchase line, created with the purchase.
 *
 * - Stock Out (L): liters sales took from the purchase = delivered (released stock-outs) + promised to open orders
 *   not delivered yet. A sale deducts the moment it is saved; cancelling it gives the liters back.
 * - Remaining (L): purchased - stock out. This is the Total Inventory and the dashboard stock.
 * - Status: Available (liters left) or Sold Out (nothing left); a voided purchase's stock-in reads Cancelled.
 * - Stock-Out Today: liters released that day, as listed on the Stock Out tab.
 */
class StockInService
{
    /** Stock-In filter statuses: Available (liters left) and Sold Out (nothing left). */
    public const STATUSES = ['available', 'sold_out'];

    private bool $repaired = false;

    public function __construct(private readonly PurchaseStockService $purchaseStock) {}

    /** Creates or refreshes the stock-in of a purchase line from the purchase (voided when it is cancelled or deleted). */
    public function sync(int $purchaseItemId, ?int $userId = null): void
    {
        $line = DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where('purchase_items.id', $purchaseItemId)
            ->first([
                'purchase_items.purchase_id',
                'purchase_items.fuel_type_id',
                'purchase_items.quantity_ordered_liters',
                'purchase_items.unit_cost',
                'purchases.depot_id',
                'purchases.purchase_date',
                'purchases.status',
                'purchases.deleted_at',
            ]);

        if (! $line) {
            return;
        }

        $voided = $line->status === 'cancelled' || $line->deleted_at !== null;
        $existing = DB::table('stock_ins')->where('purchase_item_id', $purchaseItemId)->first(['id', 'status', 'voided_at']);
        $values = [
            'purchase_id' => $line->purchase_id,
            'fuel_type_id' => $line->fuel_type_id,
            'depot_id' => $line->depot_id,
            'stock_in_date' => $line->purchase_date,
            'quantity_liters' => $line->quantity_ordered_liters,
            'unit_cost' => $line->unit_cost,
            'total_cost' => round((float) $line->quantity_ordered_liters * (float) $line->unit_cost, 2),
            'status' => $voided ? 'voided' : 'active',
            'voided_at' => $voided ? ($existing->voided_at ?? now()) : null,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('stock_ins')->where('id', $existing->id)->update($values);
        } else {
            DB::table('stock_ins')->insert($values + ['purchase_item_id' => $purchaseItemId, 'created_by' => $userId, 'created_at' => now()]);
        }
    }

    /**
     * Gives every purchase line a stock-in and voids those whose purchase was cancelled or deleted. Purchases recorded
     * through Purchases already have one; this covers lines written by other tools (imports, manual SQL). Run by the
     * create_stock_ins migration and `php artisan stock-in:sync`; never on a page load.
     *
     * @return array{created: int, voided: int}
     */
    public function syncAll(): array
    {
        $now = now()->toDateTimeString();
        $voided = fn (Builder $query): Builder => $query->where('purchases.status', 'cancelled')->orWhereNotNull('purchases.deleted_at');

        $created = DB::table('stock_ins')->insertOrIgnoreUsing(
            ['purchase_id', 'purchase_item_id', 'fuel_type_id', 'depot_id', 'stock_in_date', 'quantity_liters', 'unit_cost', 'total_cost', 'status', 'voided_at', 'created_by', 'created_at', 'updated_at'],
            DB::table('purchase_items')
                ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
                ->whereNotExists(fn (Builder $query): Builder => $query->selectRaw('1')->from('stock_ins')->whereColumn('stock_ins.purchase_item_id', 'purchase_items.id'))
                ->select(['purchase_items.purchase_id', 'purchase_items.id', 'purchase_items.fuel_type_id', 'purchases.depot_id', 'purchases.purchase_date', 'purchase_items.quantity_ordered_liters', 'purchase_items.unit_cost'])
                ->selectRaw('ROUND(purchase_items.quantity_ordered_liters * purchase_items.unit_cost, 2)')
                ->selectRaw("CASE WHEN purchases.status = 'cancelled' OR purchases.deleted_at IS NOT NULL THEN 'voided' ELSE 'active' END")
                ->selectRaw("CASE WHEN purchases.status = 'cancelled' OR purchases.deleted_at IS NOT NULL THEN ? ELSE NULL END", [$now])
                ->addSelect('purchases.created_by')
                ->selectRaw('?, ?', [$now, $now])
        );

        $voidedCount = DB::table('stock_ins')
            ->where('status', 'active')
            ->whereIn('purchase_id', DB::table('purchases')->where($voided)->select('purchases.id'))
            ->update(['status' => 'voided', 'voided_at' => $now, 'updated_at' => $now]);

        return ['created' => (int) $created, 'voided' => (int) $voidedCount];
    }

    /**
     * Repairs the stock-ins of purchase lines written outside Purchases (seeders, phpMyAdmin, imports): creates the
     * missing ones, voids those of cancelled or deleted purchases, and refreshes those whose purchase was changed
     * directly (date, fuel, depot, quantity or cost). It only reads unless something is wrong, and re-syncs just those
     * lines, so running it any number of times never double counts. Runs before stock-in records are read, from the
     * October 31 backfill migration and from `php artisan stock-in:sync`.
     *
     * @return array{created: int, voided: int, refreshed: int}
     */
    public function repair(): array
    {
        $missing = DB::table('purchase_items')
            ->whereNotExists(fn (Builder $query): Builder => $query->selectRaw('1')->from('stock_ins')->whereColumn('stock_ins.purchase_item_id', 'purchase_items.id'))
            ->exists();
        $result = $missing ? $this->syncAll() : ['created' => 0, 'voided' => 0];

        $stale = DB::table('stock_ins')
            ->join('purchase_items', 'purchase_items.id', '=', 'stock_ins.purchase_item_id')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where(function (Builder $query): void {
                $voided = "(purchases.status = 'cancelled' OR purchases.deleted_at IS NOT NULL)";
                $query->whereColumn('stock_ins.quantity_liters', '!=', 'purchase_items.quantity_ordered_liters')
                    ->orWhereColumn('stock_ins.unit_cost', '!=', 'purchase_items.unit_cost')
                    ->orWhereColumn('stock_ins.fuel_type_id', '!=', 'purchase_items.fuel_type_id')
                    ->orWhereColumn('stock_ins.depot_id', '!=', 'purchases.depot_id')
                    ->orWhereColumn('stock_ins.purchase_id', '!=', 'purchase_items.purchase_id')
                    ->orWhereRaw('DATE(stock_ins.stock_in_date) <> DATE(purchases.purchase_date)')
                    ->orWhereRaw("stock_ins.status = 'active' AND $voided")
                    ->orWhereRaw("stock_ins.status = 'voided' AND NOT $voided");
            })
            ->pluck('stock_ins.purchase_item_id');
        foreach ($stale as $purchaseItemId) {
            $this->sync((int) $purchaseItemId);
        }

        return $result + ['refreshed' => $stale->count()];
    }

    /**
     * Stock-in records with their liters out, remaining and status, newest first.
     *
     * @param  array{status?: ?string, date?: ?string, date_from?: ?string, date_to?: ?string, depot_id?: mixed, fuel_type_id?: mixed}  $filters
     * @return Collection<int, \stdClass>
     */
    public function records(array $filters = [], ?string $search = null): Collection
    {
        return $this->query($filters, $search)->orderByDesc('stock_in_date')->orderByDesc('id')->get()->map(fn ($row) => $this->normalizeQuantities($row));
    }

    /** Derived quantities are filtered and counted in SQL. No stock-in rows are loaded before LIMIT/OFFSET. */
    public function query(array $filters = [], ?string $search = null): Builder
    {
        if (! $this->repaired) {
            $this->repair();
            $this->repaired = true;
        }
        $released = DB::query()->fromSub(PurchaseStockService::releasesByPurchaseQuery(), 'release_rows')
            ->groupBy('purchase_item_id')->selectRaw('purchase_item_id, SUM(liters) as liters');
        $reserved = $this->purchaseStock->reservationTotalsQuery();
        $used = '(COALESCE(released.liters, 0) + COALESCE(reserved.liters, 0))';
        // Historical imports: liters the source documents as consumed (e.g. F1 blended into gasoline sales) without
        // naming the sale are not verified stock. They are excluded from Remaining and reported separately; no stock
        // movement is invented. Lines without a register entry are unaffected.
        // Historical imports also document blended F1 carried by gasoline releases (excess): it is not this purchase's own
        // fuel, so it is taken out of Sold. Together these give the source's own Current QTY and Sold QTY.
        $unverified = 'COALESCE(historical.unverified, 0)';
        $excess = 'COALESCE(historical.excess, 0)';
        $consumed = "($used - $excess + $unverified)";
        $remaining = "CASE WHEN stock_ins.status = 'voided' THEN 0 WHEN stock_ins.quantity_liters > $consumed THEN stock_ins.quantity_liters - $consumed ELSE 0 END";
        $historical = HistoricalReconciliation::stockInAdjustmentsByPurchaseItem();
        $query = $this->baseQuery($filters, $search)
            ->leftJoin('users', 'users.id', '=', 'stock_ins.created_by')
            ->leftJoinSub($released, 'released', 'released.purchase_item_id', '=', 'stock_ins.purchase_item_id')
            ->leftJoinSub($reserved, 'reserved', 'reserved.purchase_item_id', '=', 'stock_ins.purchase_item_id')
            ->leftJoinSub($historical, 'historical', 'historical.purchase_item_id', '=', 'stock_ins.purchase_item_id')
            ->select('stock_ins.*', 'purchases.purchase_code', 'purchases.payment_status', 'purchases.created_at as recorded_at', 'fuel_types.name as fuel_name', 'fuel_types.code as fuel_code', 'depots.name as depot_name', 'users.name as created_by_name')
            ->selectRaw("ROUND(COALESCE(released.liters, 0) - $excess + $unverified, 2) as delivered, ROUND(COALESCE(reserved.liters, 0), 2) as reserved, ROUND($consumed, 2) as stock_out, ROUND($remaining, 2) as remaining")
            ->selectRaw("ROUND(CASE WHEN stock_ins.status = 'voided' THEN 0 ELSE $unverified END, 2) as historical_unverified, ROUND($excess, 2) as historical_blended_f1")
            // Available while liters are left, Sold Out when nothing is left (a voided purchase's stock-in: Cancelled).
            ->selectRaw("CASE WHEN stock_ins.status = 'voided' THEN 'cancelled' WHEN ROUND($remaining, 2) <= 0 THEN 'sold_out' ELSE 'available' END as stock_status");

        return DB::query()->fromSub($query, 'stock_rows')
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('stock_status', $status));
    }

    private function baseQuery(array $filters, ?string $search): Builder
    {
        return DB::table('stock_ins')->join('purchases', 'purchases.id', '=', 'stock_ins.purchase_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'stock_ins.fuel_type_id')->join('depots', 'depots.id', '=', 'stock_ins.depot_id')
            ->when($search, fn (Builder $q, string $term) => $q->where(fn (Builder $q) => $q->where('purchases.purchase_code', 'like', '%'.$term.'%')->orWhere('fuel_types.name', 'like', '%'.$term.'%')->orWhere('depots.name', 'like', '%'.$term.'%')))
            ->when($filters['date'] ?? null, fn (Builder $q, string $date) => $q->whereDate('stock_ins.stock_in_date', $date))
            ->tap(fn (Builder $q) => FilterDates::apply($q, 'stock_ins.stock_in_date', ['from' => $filters['date_from'] ?? null, 'to' => $filters['date_to'] ?? null]))
            ->when($filters['depot_id'] ?? null, fn (Builder $q, $id) => $q->where('stock_ins.depot_id', $id))
            ->when($filters['fuel_type_id'] ?? null, fn (Builder $q, $id) => $q->where('stock_ins.fuel_type_id', $id));
    }

    public function paginate(array $filters, ?string $search, int $perPage = 25, string $pageName = 'stock_in_page'): LengthAwarePaginator
    {
        $query = $this->query($filters, $search);
        \App\Support\TableSort::apply($query, ['date' => 'stock_in_date', 'code' => 'purchase_code', 'fuel' => 'fuel_name', 'quantity' => 'quantity_liters', 'cost' => 'unit_cost', 'remaining' => 'remaining'], 'date', 'id');
        $total = ($filters['status'] ?? null) ? null : $this->baseQuery($filters, $search)->count('stock_ins.id');
        $paginator = $query->paginate(\App\Support\TablePagination::size(), ['*'], $pageName, total: $total)->withQueryString();
        $deductions = $this->deductions($paginator->getCollection()->pluck('purchase_item_id')->map(fn ($id) => (int) $id)->all());
        $paginator->getCollection()->each(function (object $row) use ($deductions): void {
            $this->normalizeQuantities($row);
            $row->deductions = $deductions[(int) $row->purchase_item_id] ?? [];
        });

        return $paginator;
    }

    private function normalizeQuantities(object $row): object
    {
        foreach (['delivered', 'reserved', 'stock_out', 'remaining', 'historical_unverified', 'historical_blended_f1'] as $field) $row->$field = (float) ($row->$field ?? 0);

        return $row;
    }

    /**
     * Sales lines that took liters from each purchase (their own fuel, or their F1 portion), with what was delivered.
     *
     * @param  array<int, int>  $purchaseItemIds
     * @return array<int, array<int, array<string, mixed>>> purchase_item_id => deductions, newest first
     */
    public function deductions(array $purchaseItemIds): array
    {
        if ($purchaseItemIds === []) {
            return [];
        }

        $portion = fn (string $column, string $quantity, string $name): Builder => DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            // A deleted (archived) delivered order keeps its liters deducted, so it stays in this list, marked.
            ->where('sales.status', '!=', 'cancelled')
            ->whereIn("sale_items.$column", $purchaseItemIds)
            ->where("sale_items.$quantity", '>', 0)
            ->select([
                "sale_items.$column as purchase_item_id",
                'sale_items.id as sale_item_id',
                'sales.sale_code',
                'sales.sales_order_number',
                'sales.sale_date',
                'sales.delivery_type',
                'sales.delivery_status',
                'sales.deleted_at as sale_deleted_at',
                'customers.name as customer_name',
                "sale_items.$quantity as liters",
            ])
            ->selectRaw('? as line_portion', [$name]);

        // Part 18 add-on: a line sold from a garage tank names no purchase; it is listed under each purchase its
        // releases drew from, for the liters drawn.
        $drawn = DB::table('stock_out_draws')
            ->join('stock_outs', 'stock_outs.id', '=', 'stock_out_draws.stock_out_id')
            ->join('sale_items', 'sale_items.id', '=', 'stock_outs.sale_item_id')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->where('stock_outs.status', 'released')
            ->whereIn('stock_out_draws.purchase_item_id', $purchaseItemIds)
            ->groupBy('stock_out_draws.purchase_item_id', 'sale_items.id', 'stock_outs.portion', 'sales.sale_code', 'sales.sales_order_number', 'sales.sale_date', 'sales.delivery_type', 'sales.delivery_status', 'sales.deleted_at', 'customers.name')
            ->select([
                'stock_out_draws.purchase_item_id',
                'sale_items.id as sale_item_id',
                'sales.sale_code',
                'sales.sales_order_number',
                'sales.sale_date',
                'sales.delivery_type',
                'sales.delivery_status',
                'sales.deleted_at as sale_deleted_at',
                'customers.name as customer_name',
            ])
            ->selectRaw('COALESCE(SUM(stock_out_draws.liters), 0) as liters')
            ->selectRaw("CASE WHEN stock_outs.portion = 'f1' THEN 'f1' ELSE 'main' END as line_portion");

        $lines = $portion('purchase_item_id', 'main_quantity_liters', 'main')
            ->unionAll($portion('f1_purchase_item_id', 'f1_quantity_liters', 'f1'))
            ->unionAll($drawn)
            ->get();

        // Part 18 add-on: a tank release counts for each purchase it drew from (its draw's liters).
        $releases = PurchaseStockService::releasesByPurchaseQuery()
            ->whereIn('purchase_item_id', $purchaseItemIds)
            ->orderBy('stock_out_at')
            ->get(['sale_item_id', 'purchase_item_id', 'portion', 'stock_out_code', 'liters as quantity_liters', 'stock_out_at'])
            ->groupBy(fn (object $release): string => $release->purchase_item_id.':'.$release->sale_item_id.':'.($release->portion === 'f1' ? 'f1' : 'main'));

        return $lines
            ->sortByDesc(fn (object $line): string => $line->sale_date.'-'.str_pad((string) $line->sale_item_id, 10, '0', STR_PAD_LEFT))
            ->groupBy('purchase_item_id')
            ->map(fn (Collection $group): array => $group->map(function (object $line) use ($releases): array {
                $released = $releases->get($line->purchase_item_id.':'.$line->sale_item_id.':'.$line->line_portion, collect());

                return [
                    'sale_code' => $line->sale_code.($line->sale_deleted_at ? ' (deleted order)' : ''),
                    'sales_order_number' => $line->sales_order_number,
                    'sale_date' => $line->sale_date,
                    'customer_name' => $line->customer_name,
                    'portion' => $line->line_portion,
                    'liters' => round((float) $line->liters, 2),
                    'delivered' => round((float) $released->sum('quantity_liters'), 2),
                    'stock_out_codes' => $released->pluck('stock_out_code')->all(),
                    'delivery_type' => $line->delivery_type,
                    'delivery_status' => $line->delivery_status,
                ];
            })->values()->all())
            ->mapWithKeys(fn (array $rows, mixed $id): array => [(int) $id => $rows])
            ->all();
    }

    /**
     * KPI totals over the stock-ins matching $filters. A date filter moves the "today" cards to that date.
     *
     * @param  array<string, mixed>  $filters
     * @return array{total_inventory: float, stock_in: float, stock_out: float, date: string, active: int}
     */
    public function totals(array $filters = [], ?CarbonImmutable $today = null): array
    {
        $date = ($filters['date'] ?? null) ?: ($today ?? CarbonImmutable::now())->toDateString();
        $query = $this->query(array_merge($filters, ['date' => null]));
        $totals = (clone $query)->selectRaw("COALESCE(SUM(remaining), 0) as remaining_total, COALESCE(SUM(CASE WHEN status = 'active' AND stock_in_date = ? THEN quantity_liters ELSE 0 END), 0) as received, COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) as active", [$date])->first();
        $inventory = ($filters['date'] ?? null) ? (clone $query)->whereDate('stock_in_date', $date)->sum('remaining') : $totals->remaining_total;

        return [
            'total_inventory' => round((float) $inventory, 2), 'stock_in' => round((float) $totals->received, 2),
            'stock_out' => $this->stockOutOn($filters, ($filters['status'] ?? null) ? (clone $query)->select('purchase_item_id') : null, $date),
            'date' => $date, 'active' => (int) $totals->active,
        ];
    }

    /**
     * Remaining liters per fuel over active stock-ins (dashboard stock levels).
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, float> fuel_type_id => remaining liters
     */
    public function remainingByFuel(array $filters = []): array
    {
        return $this->query($filters)->where('status', 'active')->groupBy('fuel_type_id')
            ->selectRaw('fuel_type_id, ROUND(SUM(remaining), 2) as liters')->pluck('liters', 'fuel_type_id')->map(fn ($liters) => (float) $liters)->all();
    }

    /** @return array<int, string> distinct stock-in dates, newest first (Date filter options) */
    public function dates(): array
    {
        return FilterDates::distinct(DB::table('stock_ins'), 'stock_in_date');
    }

    /**
     * Liters released on $date (the Stock Out tab's rows of that day), narrowed by Depot and Fuel Type, and to the
     * stock-ins a Status filter kept.
     *
     * @param  array<string, mixed>  $filters
     * @param  array<int, int>|null  $purchaseItemIds
     */
    private function stockOutOn(array $filters, array|Builder|null $purchaseItemIds, string $date): float
    {
        // Narrowed to purchases, a tank release counts by its draws (Part 18 add-on); otherwise every release counts.
        $query = $purchaseItemIds === null
            ? DB::table('stock_outs')->where('status', 'released')->selectRaw('stock_out_at, depot_id, fuel_type_id, quantity_liters as liters')
            : PurchaseStockService::releasesByPurchaseQuery()->whereIn('purchase_item_id', $purchaseItemIds ?: [0]);

        return round((float) DB::query()->fromSub($query, 'day_releases')
            ->whereDate('stock_out_at', $date)
            ->when($filters['depot_id'] ?? null, fn (Builder $query, mixed $id): Builder => $query->where('depot_id', (int) $id))
            ->when($filters['fuel_type_id'] ?? null, fn (Builder $query, mixed $id): Builder => $query->where('fuel_type_id', (int) $id))
            ->sum('liters'), 2);
    }
}
