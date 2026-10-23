<?php

namespace App\Services;

use App\Support\FilterDates;
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
 * - Status: Available (nothing taken), Partially Used, Depleted (nothing left), Cancelled (purchase voided).
 * - Stock-Out Today: liters released that day, as listed on the Stock Out tab.
 */
class StockInService
{
    public const STATUSES = ['available', 'partially_used', 'depleted', 'cancelled'];

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
            'voided_at' => $voided ? ($existing?->voided_at ?? now()) : null,
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
     * Stock-in records with their liters out, remaining and status, newest first.
     *
     * @param  array{status?: ?string, date?: ?string, depot_id?: mixed, fuel_type_id?: mixed}  $filters
     * @return Collection<int, \stdClass>
     */
    public function records(array $filters = [], ?string $search = null): Collection
    {
        $rows = DB::table('stock_ins')
            ->join('purchases', 'purchases.id', '=', 'stock_ins.purchase_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'stock_ins.fuel_type_id')
            ->join('depots', 'depots.id', '=', 'stock_ins.depot_id')
            ->leftJoin('users', 'users.id', '=', 'stock_ins.created_by')
            ->when($search, fn (Builder $query, string $term): Builder => $query->where(function (Builder $query) use ($term): void {
                foreach (['purchases.purchase_code', 'fuel_types.name', 'depots.name'] as $column) {
                    $query->orWhere($column, 'like', '%'.$term.'%');
                }
            }))
            ->when($filters['date'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('stock_ins.stock_in_date', $date))
            ->when($filters['depot_id'] ?? null, fn (Builder $query, mixed $id): Builder => $query->where('stock_ins.depot_id', (int) $id))
            ->when($filters['fuel_type_id'] ?? null, fn (Builder $query, mixed $id): Builder => $query->where('stock_ins.fuel_type_id', (int) $id))
            ->orderByDesc('stock_ins.stock_in_date')
            ->orderByDesc('stock_ins.id')
            ->get([
                'stock_ins.*',
                'purchases.purchase_code',
                'purchases.payment_status',
                'fuel_types.name as fuel_name',
                'fuel_types.code as fuel_code',
                'depots.name as depot_name',
                'users.name as created_by_name',
            ]);

        $usage = $this->purchaseStock->usage($rows->pluck('purchase_item_id')->map(fn (mixed $id): int => (int) $id)->all());

        return $rows->map(function (object $row) use ($usage): object {
            $id = (int) $row->purchase_item_id;
            $purchased = round((float) $row->quantity_liters, 2);
            $row->delivered = round((float) ($usage['released'][$id] ?? 0), 2);
            $row->reserved = round((float) ($usage['reserved'][$id] ?? 0), 2);
            $row->stock_out = round($row->delivered + $row->reserved, 2);
            $row->remaining = $row->status === 'voided' ? 0.0 : round(max(0, $purchased - $row->stock_out), 2);
            $row->stock_status = match (true) {
                $row->status === 'voided' => 'cancelled',
                $row->remaining <= 0 => 'depleted',
                $row->stock_out > 0 => 'partially_used',
                default => 'available',
            };

            return $row;
        })
            ->when($filters['status'] ?? null, fn (Collection $rows, string $status): Collection => $rows->where('stock_status', $status))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, ?string $search, int $perPage = 25, string $pageName = 'stock_in_page'): LengthAwarePaginator
    {
        $records = $this->records($filters, $search);
        $page = Paginator::resolveCurrentPage($pageName);
        $items = $records->forPage($page, $perPage)->values();
        $deductions = $this->deductions($items->pluck('purchase_item_id')->map(fn (mixed $id): int => (int) $id)->all());

        $paginator = new LengthAwarePaginator(
            $items->map(fn (object $row): object => tap($row, fn (object $row) => $row->deductions = $deductions[(int) $row->purchase_item_id] ?? [])),
            $records->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath(), 'pageName' => $pageName]
        );

        return $paginator->withQueryString();
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
            ->whereNull('sales.deleted_at')
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
                'customers.name as customer_name',
                "sale_items.$quantity as liters",
            ])
            ->selectRaw('? as line_portion', [$name]);

        $lines = $portion('purchase_item_id', 'main_quantity_liters', 'main')
            ->unionAll($portion('f1_purchase_item_id', 'f1_quantity_liters', 'f1'))
            ->get();

        $releases = DB::table('stock_outs')
            ->where('status', 'released')
            ->whereIn('purchase_item_id', $purchaseItemIds)
            ->orderBy('stock_out_at')
            ->get(['sale_item_id', 'purchase_item_id', 'portion', 'stock_out_code', 'quantity_liters', 'stock_out_at'])
            ->groupBy(fn (object $release): string => $release->purchase_item_id.':'.$release->sale_item_id.':'.($release->portion === 'f1' ? 'f1' : 'main'));

        return $lines
            ->sortByDesc(fn (object $line): string => $line->sale_date.'-'.str_pad((string) $line->sale_item_id, 10, '0', STR_PAD_LEFT))
            ->groupBy('purchase_item_id')
            ->map(fn (Collection $group): array => $group->map(function (object $line) use ($releases): array {
                $released = $releases->get($line->purchase_item_id.':'.$line->sale_item_id.':'.$line->line_portion, collect());

                return [
                    'sale_code' => $line->sale_code,
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
        $records = $this->records(array_merge($filters, ['date' => null]));
        $onDate = fn (object $row): bool => substr((string) $row->stock_in_date, 0, 10) === $date;
        $active = $records->where('status', 'active');

        return [
            'total_inventory' => round((float) (($filters['date'] ?? null) ? $records->filter($onDate) : $records)->sum('remaining'), 2),
            'stock_in' => round((float) $active->filter($onDate)->sum('quantity_liters'), 2),
            'stock_out' => $this->stockOutOn($filters, ($filters['status'] ?? null) ? $records->pluck('purchase_item_id')->map(fn (mixed $id): int => (int) $id)->all() : null, $date),
            'date' => $date,
            'active' => $active->count(),
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
        return $this->records($filters)
            ->where('status', 'active')
            ->groupBy('fuel_type_id')
            ->map(fn (Collection $rows): float => round((float) $rows->sum('remaining'), 2))
            ->mapWithKeys(fn (float $liters, mixed $id): array => [(int) $id => $liters])
            ->all();
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
    private function stockOutOn(array $filters, ?array $purchaseItemIds, string $date): float
    {
        return round((float) DB::table('stock_outs')
            ->where('status', 'released')
            ->whereDate('stock_out_at', $date)
            ->when($filters['depot_id'] ?? null, fn (Builder $query, mixed $id): Builder => $query->where('depot_id', (int) $id))
            ->when($filters['fuel_type_id'] ?? null, fn (Builder $query, mixed $id): Builder => $query->where('fuel_type_id', (int) $id))
            ->when($purchaseItemIds !== null, fn (Builder $query): Builder => $query->whereIn('purchase_item_id', $purchaseItemIds ?: [0]))
            ->sum('quantity_liters'), 2);
    }
}
