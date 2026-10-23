<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Part 10 (October 22, 2026): the Admin "Dashboard and Analytics" page.
 *
 * Every figure is an SQL aggregate over real records (sales by day are bucketed into weeks / months / years in
 * PHP, which stays one row per day). Only stock filtered by depot or purchase uses the stock-lot figures, one
 * row per stock receipt. The formula of each metric is on the method that computes it.
 */
class DashboardService
{
    public const VALID_SALE_STATUSES = DashboardSummaryService::VALID_SALE_STATUSES;

    public const RED = '#E8453C';

    public const GREEN = '#3B9A35';

    public const ORANGE = '#E28A22';

    public const NAVY = '#0D1424';

    /** Week / Month / Year toggle of Expected Sales Revenue: how many periods the x-axis shows. */
    public const REVENUE_PERIODS = ['week' => 12, 'month' => 12, 'year' => 5];

    /** Forecasts are the average of this many previous complete periods (moving average). */
    public const MOVING_AVERAGE_PERIODS = 3;

    /** Current Stock by Fuel Type: mockup order and colors. */
    private const STOCK_FUELS = ['PREM' => self::RED, 'UNL' => self::GREEN, 'DSL' => self::ORANGE, 'F1' => self::NAVY];

    /** Filter dropdown order: Premium, Unleaded, Diesel, F1. */
    private const FILTER_FUELS = ['PREM', 'UNL', 'DSL', 'F1'];

    /** Fuel Sold: mockup order; F1 only shows when F1 itself was sold. */
    private const SOLD_FUELS = ['UNL', 'PREM', 'DSL', 'F1'];

    private const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    /** @var Collection<string, object>|null */
    private ?Collection $fuels = null;

    public function __construct(
        private readonly PurchaseStockService $purchaseStock,
        private readonly StockInService $stockIns
    ) {}

    /**
     * @param  array<string, mixed>  $inventoryFilters  search, fuel_type_id, depot_id
     * @param  array<string, mixed>  $salesFilters  search, date_from, date_to, fuel_type_id, customer_id
     * @return array<string, mixed>
     */
    public function data(array $inventoryFilters = [], array $salesFilters = [], ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();

        return [
            'inventory' => $this->inventory($inventoryFilters),
            'revenue' => $this->revenue($today),
            'salesTrend' => $this->salesTrend($salesFilters, $today),
            'filterOptions' => $this->filterOptions(),
        ];
    }

    // ----- Section 1: Current Inventory ------------------------------------------------------------------

    /**
     * Part 2 revisions: stock is kept per purchase (tanks are retired). Over the purchases matching the filters:
     * - Total Inventory in Stock / Current Stock by Fuel Type: their remaining liters (ordered - delivered).
     * - Total Purchases: purchases (not cancelled or deleted) with a matching line.
     * - Remaining Balance in Depots: liters still at the depot (ordered - Lifted/Completed lifts - delivered straight
     *   from the depot).
     * - Total Lifts: lift schedules (not cancelled) carrying a matching purchase.
     * - Expected Stock Out Rate: liters promised to undelivered orders (Standard and Depot to Client) from those
     *   purchases / their stock x 100.
     * - Fuel Lifting Status: purchase lines Unlifted (0 lifted), Partially Lifted, Lifted (lifted >= ordered).
     * - Purchased vs Stock In: Completed = liters that left the depot (lifted or delivered directly); Uncompleted =
     *   liters still at the depot.
     * - Stock levels: the Remaining of the active stock-ins per fuel (as the Stock-In tab).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function inventory(array $filters = []): array
    {
        $filters = [
            'search' => trim((string) ($filters['search'] ?? '')),
            'fuel_type_id' => ($filters['fuel_type_id'] ?? null) ? (int) $filters['fuel_type_id'] : null,
            'depot_id' => ($filters['depot_id'] ?? null) ? (int) $filters['depot_id'] : null,
        ];

        $totals = DB::query()->fromSub($this->purchaseLinesQuery($filters), 'lines')
            ->selectRaw('COUNT(DISTINCT purchase_id) as purchases')
            ->selectRaw('COALESCE(SUM(purchased_liters), 0) as purchased')
            ->selectRaw('COALESCE(SUM(remaining_liters), 0) as remaining')
            ->selectRaw("COALESCE(SUM(CASE WHEN lift_status = 'unlifted' THEN 1 ELSE 0 END), 0) as unlifted")
            ->selectRaw("COALESCE(SUM(CASE WHEN lift_status = 'partial' THEN 1 ELSE 0 END), 0) as partial")
            ->selectRaw("COALESCE(SUM(CASE WHEN lift_status = 'lifted' THEN 1 ELSE 0 END), 0) as lifted")
            ->first();

        $matchingIds = $this->matchingPurchaseLines($filters)->pluck('purchase_items.id')->map(fn (mixed $id): int => (int) $id)->all();
        $lines = $this->purchaseStock->lines($matchingIds);
        // Stock-In revisions: the stock chart and Total Inventory read the stock-in records (as the Stock-In tab).
        $stockIns = $this->stockIns->records(['depot_id' => $filters['depot_id'], 'fuel_type_id' => $filters['fuel_type_id']])
            ->where('status', 'active')
            ->whereIn('purchase_item_id', $matchingIds);
        $stockByFuel = $stockIns->groupBy('fuel_type_id')->map(fn (Collection $group): float => round((float) $group->sum('remaining'), 2))->all();
        $stockFuels = $this->fuels()->only(array_keys(self::STOCK_FUELS))
            ->when($filters['fuel_type_id'], fn (Collection $fuels, int $id): Collection => $fuels->where('id', $id))
            ->sortBy(fn (object $fuel): int => array_search($fuel->code, array_keys(self::STOCK_FUELS), true));
        $stockValues = $stockFuels->map(fn (object $fuel): float => round((float) ($stockByFuel[$fuel->id] ?? 0), 2));
        $stock = round((float) $stockValues->sum(), 2);

        $pending = round((float) $lines->sum('reserved'), 2);
        // Share of the fuel on hand (not delivered yet) that open orders already took.
        $onHand = round((float) $lines->sum('on_hand'), 2);
        $stockOutRate = $onHand > 0 ? round($pending / $onHand * 100, 2) : ($pending > 0 ? null : 0.0);
        $inDepot = round((float) $lines->sum('not_lifted'), 2);

        $purchased = round((float) $totals->purchased, 2);
        $completed = round(max(0, $purchased - $inDepot), 2);
        $uncompleted = round(min($purchased, $inDepot), 2);
        $completedPercent = $purchased > 0 ? round($completed / $purchased * 100, 2) : 0.0;
        $liftCounts = [(int) $totals->unlifted, (int) $totals->partial, (int) $totals->lifted];
        $fuelName = $filters['fuel_type_id'] ? $this->fuels()->firstWhere('id', $filters['fuel_type_id'])?->name : null;
        $depotName = $filters['depot_id'] ? DB::table('depots')->where('id', $filters['depot_id'])->value('name') : null;

        return [
            'filters' => $filters,
            'totals' => [
                'stock_liters' => $stock,
                'purchases' => (int) $totals->purchases,
                'remaining_liters' => $inDepot,
                'lifts' => $this->liftCount($filters),
                'pending_order_liters' => $pending,
                'stock_out_rate' => $stockOutRate,
                'purchased_liters' => $purchased,
                'completed_liters' => $completed,
                'uncompleted_liters' => $uncompleted,
                'completed_percent' => $completedPercent,
                'lift_status' => ['unlifted' => $liftCounts[0], 'partial' => $liftCounts[1], 'lifted' => $liftCounts[2]],
                'stock_by_fuel' => $stockFuels->mapWithKeys(fn (object $fuel): array => [$fuel->name => $stockValues[$fuel->code]])->all(),
            ],
            'cards' => [
                ['Total Inventory in Stock', $this->kl($stock)],
                ['Total Purchases', number_format((int) $totals->purchases)],
                ['Fuel Type', $fuelName ?? 'ALL'],
                ['Depot', $depotName ?? 'ALL'],
                ['Remaining Balance in Depots', $this->kl($inDepot)],
                ['Total Lifts', number_format($this->liftCount($filters))],
            ],
            'stockOutRateLabel' => $stockOutRate === null ? 'N/A (no stock on hand)' : $this->percent($stockOutRate),
            'stockChart' => $this->chart('bar', 'liters', $stockFuels->pluck('name')->values()->all(), [[
                'label' => 'Stock',
                'data' => $stockValues->values()->all(),
                'backgroundColor' => $stockFuels->map(fn (object $fuel): string => self::STOCK_FUELS[$fuel->code])->values()->all(),
            ]]),
            'liftingChart' => $this->chart('bar', 'count', ['Unlifted', 'Partially Lifted', 'Lifted'], [[
                'label' => 'Purchases',
                'data' => $liftCounts,
                'backgroundColor' => [self::RED, self::ORANGE, self::GREEN],
            ]], yAxis: 'none'),
            // Part 10 wireframe: "Completed 68.8%" / "Uncompleted 31.3%" written beside the slices, no legend.
            'purchasedChart' => $this->chart('pie', 'liters', ['Completed', 'Uncompleted'], [[
                'label' => 'Purchased',
                'data' => [$completed, $uncompleted],
                'backgroundColor' => [self::GREEN, self::ORANGE],
            ]], extra: ['sliceLabels' => $purchased > 0 ? [
                number_format($completedPercent, 1).'%',
                number_format(100 - $completedPercent, 1).'%',
            ] : []]),
        ];
    }

    // ----- Section 2: Revenue -----------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function revenue(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $series = [];
        foreach (self::REVENUE_PERIODS as $period => $count) {
            $series[$period] = $this->revenueSeries($period, $count, $today);
        }

        return ['series' => $series, 'margin' => $this->stockOutMargin()];
    }

    /**
     * Expected Sales Revenue for one toggle (week / month / year).
     * - Actual: sales billed in the period (sum of line totals of sales that are not cancelled); the current period
     *   is to date.
     * - Expected: average Actual of the 3 previous periods, plotted for each period and for the next one (the next
     *   period's forecast uses the 3 most recent complete periods, so it equals the current period's).
     * - Increase Rate: (Expected next period - Actual of the last complete period) / that Actual x 100.
     *
     * @return array<string, mixed>
     */
    public function revenueSeries(string $period, int $count, CarbonImmutable $today): array
    {
        $current = $this->periodStart($period, $today);
        $history = self::MOVING_AVERAGE_PERIODS;
        $starts = [];
        for ($offset = $count - 1 + $history; $offset >= 0; $offset--) {
            $starts[] = $this->shiftPeriod($period, $current, -$offset);
        }

        $daily = $this->validSaleLines()
            ->whereBetween('sales.sale_date', [$starts[0]->toDateString(), $this->shiftPeriod($period, $current, 1)->subDay()->toDateString()])
            ->groupBy('sales.sale_date')
            ->selectRaw('sales.sale_date as day, COALESCE(SUM(sale_items.line_total), 0) as total')
            ->get();
        $byPeriod = [];
        foreach ($daily as $row) {
            $key = $this->periodStart($period, CarbonImmutable::parse($row->day))->toDateString();
            $byPeriod[$key] = round(($byPeriod[$key] ?? 0) + (float) $row->total, 2);
        }
        $all = array_map(fn (CarbonImmutable $start): float => (float) ($byPeriod[$start->toDateString()] ?? 0), $starts);

        $labels = [];
        $actual = [];
        $expected = [];
        for ($index = $history; $index < count($starts); $index++) {
            $labels[] = $this->periodLabel($period, $starts[$index]);
            $actual[] = $all[$index];
            $expected[] = $this->average(array_slice($all, $index - $history, $history));
        }
        // The next period: forecast only.
        $nextForecast = $this->average(array_slice($all, -1 - $history, $history));
        $labels[] = $this->periodLabel($period, $this->shiftPeriod($period, $current, 1));
        $actual[] = null;
        $expected[] = $nextForecast;

        $lastComplete = $all[count($all) - 2];
        $increaseRate = $lastComplete > 0 ? round(($nextForecast - $lastComplete) / $lastComplete * 100, 2) : null;

        return [
            'labels' => $labels,
            'actual' => $actual,
            'expected' => $expected,
            'lastComplete' => $lastComplete,
            'forecast' => $nextForecast,
            'increaseRate' => $increaseRate,
            'increaseRateLabel' => $increaseRate === null ? 'N/A (no sales in the last complete '.$period.')' : $this->percent($increaseRate),
            'empty' => array_sum(array_map('floatval', array_filter($actual, fn (?float $value): bool => $value !== null))) <= 0 && $nextForecast <= 0,
        ];
    }

    /**
     * Stock Out vs Receivables Margin, over delivered sales only (option A):
     * - Receivables: amount billed for delivered sales (sum of their line totals).
     * - Stock Out: value of the fuel released for those sales = released liters x the sale line's price.
     * - Missing: Receivables - Stock Out (billed fuel that never left a tank or depot).
     * - Inventory Variance Rate: Missing / Receivables x 100.
     *
     * @return array<string, mixed>
     */
    public function stockOutMargin(): array
    {
        $delivered = fn (): Builder => DB::table('sales')
            ->whereNull('deleted_at')
            ->whereIn('status', self::VALID_SALE_STATUSES)
            ->where('delivery_status', 'delivered')
            ->select('id');

        $receivables = round((float) DB::table('sale_items')->whereIn('sale_id', $delivered())->sum('line_total'), 2);
        $stockOut = round((float) $this->releasesQuery()->whereIn('stock_outs.sale_id', $delivered())
            ->sum(DB::raw('stock_outs.quantity_liters * '.$this->releasePriceSql())), 2);
        $missing = round($receivables - $stockOut, 2);
        $rate = $receivables > 0 ? round($missing / $receivables * 100, 2) : 0.0;

        return [
            'stock_out' => $stockOut,
            'receivables' => $receivables,
            'missing' => $missing,
            'variance_rate' => $rate,
            'missingLabel' => $this->money($missing),
            'varianceRateLabel' => $this->percent($rate),
            'chart' => $this->chart('bar', 'money', ['Stock Out', 'Receivables'], [[
                'label' => 'Delivered sales',
                'data' => [$stockOut, $receivables],
                'backgroundColor' => [self::RED, self::GREEN],
            ]], showValues: true, yAxis: 'none'),
        ];
    }

    // ----- Section 3: Sales Trend -------------------------------------------------------------------------

    /**
     * Over the matching sales (not cancelled) and, with a fuel filter, their lines of that fuel:
     * - Collected Revenue: payments on those sales; with a fuel filter each sale's payments are split across its
     *   lines by their share of the sale total.
     * - Total Delivered: released liters (KL).
     * - Total Sales: number of sales.
     * - Total Profit: gross margin on delivered fuel = released liters x (line price - cost per liter); releases
     *   without a recorded cost are left out.
     * - Unpaid Amount: sum of (total - paid) per sale where something is still owed.
     * - Paid / Unpaid Customers: customers whose matching sales are all paid, vs customers owing on any.
     * - Payment Status: Paid (paid >= total), Partially Paid (some paid), Unpaid (nothing paid); overdue sales fall
     *   under Unpaid or Partially Paid by what was paid.
     * - Fuel Sold: ordered liters per fuel (a line with an F1 % counts under its own fuel).
     * - Peak Demands: liters sold per month of the selected year (the year of the date filter, else this year).
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function salesTrend(array $filters = [], ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $filters = [
            'search' => trim((string) ($filters['search'] ?? '')),
            'date_from' => $filters['date_from'] ?? null ?: null,
            'date_to' => $filters['date_to'] ?? null ?: null,
            'fuel_type_id' => ($filters['fuel_type_id'] ?? null) ? (int) $filters['fuel_type_id'] : null,
            'customer_id' => ($filters['customer_id'] ?? null) ? (int) $filters['customer_id'] : null,
        ];

        $perSale = $this->salePaymentsQuery($filters);
        $sums = DB::query()->fromSub($perSale, 'per_sale')
            ->selectRaw('COUNT(*) as sales')
            ->selectRaw('COALESCE(SUM(paid), 0) as collected')
            ->selectRaw('COALESCE(SUM(CASE WHEN ROUND(total, 2) > ROUND(paid, 2) THEN total - paid ELSE 0 END), 0) as unpaid')
            ->selectRaw('COALESCE(SUM(CASE WHEN ROUND(paid, 2) >= ROUND(total, 2) THEN 1 ELSE 0 END), 0) as paid_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN ROUND(paid, 2) > 0 AND ROUND(paid, 2) < ROUND(total, 2) THEN 1 ELSE 0 END), 0) as partial_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN ROUND(paid, 2) <= 0 AND ROUND(total, 2) > 0 THEN 1 ELSE 0 END), 0) as unpaid_count')
            ->first();
        $customers = DB::query()->fromSub(
            DB::query()->fromSub($perSale, 'per_sale')->groupBy('customer_id')
                ->selectRaw('customer_id, SUM(CASE WHEN ROUND(total, 2) > ROUND(paid, 2) THEN 1 ELSE 0 END) as open_sales'),
            'per_customer'
        )->selectRaw('COALESCE(SUM(CASE WHEN open_sales = 0 THEN 1 ELSE 0 END), 0) as paid, COALESCE(SUM(CASE WHEN open_sales > 0 THEN 1 ELSE 0 END), 0) as unpaid')->first();

        $releases = $this->releasesQuery()
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', self::VALID_SALE_STATUSES)
            ->where(fn (Builder $query) => $this->applySaleFilters($query, $filters))
            ->when($filters['fuel_type_id'], fn (Builder $query, int $id): Builder => $query->whereRaw('COALESCE(sale_items.fuel_type_id, stock_outs.fuel_type_id) = ?', [$id]))
            ->selectRaw('COALESCE(SUM(stock_outs.quantity_liters), 0) as delivered')
            ->selectRaw('COALESCE(SUM(CASE WHEN '.$this->releaseCostSql().' IS NULL THEN 0 ELSE stock_outs.quantity_liters * ('.$this->releasePriceSql().' - '.$this->releaseCostSql().') END), 0) as profit')
            ->first();

        $collected = round((float) $sums->collected, 2);
        $delivered = round((float) $releases->delivered, 2);
        $profit = round((float) $releases->profit, 2);
        $unpaid = round((float) $sums->unpaid, 2);
        $paymentCounts = [(int) $sums->paid_count, (int) $sums->unpaid_count, (int) $sums->partial_count];

        $soldByFuel = $this->matchingSaleLines($filters)->groupBy('sale_items.fuel_type_id')
            ->selectRaw('sale_items.fuel_type_id, COALESCE(SUM(sale_items.quantity_liters), 0) as liters')
            ->pluck('liters', 'fuel_type_id');
        $soldFuels = $this->fuels()->only(self::SOLD_FUELS)
            ->when($filters['fuel_type_id'], fn (Collection $fuels, int $id): Collection => $fuels->where('id', $id))
            ->filter(fn (object $fuel): bool => $fuel->code !== 'F1' || (float) ($soldByFuel[$fuel->id] ?? 0) > 0)
            ->sortBy(fn (object $fuel): int => array_search($fuel->code, self::SOLD_FUELS, true));

        $peak = $this->peakDemand($filters, $today);

        return [
            'filters' => $filters,
            'totals' => [
                'collected' => $collected,
                'delivered_liters' => $delivered,
                'sales' => (int) $sums->sales,
                'profit' => $profit,
                'unpaid' => $unpaid,
                'paid_customers' => (int) $customers->paid,
                'unpaid_customers' => (int) $customers->unpaid,
                'payment_status' => ['paid' => $paymentCounts[0], 'unpaid' => $paymentCounts[1], 'partial' => $paymentCounts[2]],
                'fuel_sold' => $soldFuels->mapWithKeys(fn (object $fuel): array => [$fuel->name => round((float) ($soldByFuel[$fuel->id] ?? 0), 2)])->all(),
            ],
            'cards' => [
                ['Collected Revenue', $this->money($collected)],
                ['Total Delivered', $this->kl($delivered)],
                ['Total Sales', number_format((int) $sums->sales)],
                ['Total Profit', $this->money($profit)],
                ['Unpaid Amount', $this->money($unpaid)],
                ['Paid Customers', number_format((int) $customers->paid)],
                ['Unpaid Customers', number_format((int) $customers->unpaid)],
            ],
            'fuelSoldChart' => $this->chart('bar', 'liters', $soldFuels->pluck('name')->values()->all(), [[
                'label' => 'Liters sold',
                'data' => $soldFuels->map(fn (object $fuel): float => round((float) ($soldByFuel[$fuel->id] ?? 0), 2))->values()->all(),
                'backgroundColor' => self::ORANGE,
            ]], yAxis: 'grid'),
            'paymentChart' => $this->chart('bar', 'count', ['Paid', 'Unpaid', 'Partially Paid'], [[
                'label' => 'Sales',
                'data' => $paymentCounts,
                'backgroundColor' => self::RED,
            ]], yAxis: 'grid'),
            'peak' => $peak,
        ];
    }

    /**
     * - Liters per month of the selected year.
     * - Month With Highest Sales: the month with the most liters that year.
     * - Expected Month with Most Demand (YoY): the calendar month with the highest average liters over the past
     *   years that have sales (before the selected year); needs at least one such year.
     * - Expected Increase Rate by Next Month: forecast (average of the last 3 complete months) vs the last complete
     *   month, relative to today. The date filter is not applied to the YoY and next-month figures.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function peakDemand(array $filters, CarbonImmutable $today): array
    {
        $year = (int) CarbonImmutable::parse($filters['date_to'] ?? $filters['date_from'] ?? $today)->year;
        $monthly = array_fill(0, 12, 0.0);
        foreach ($this->litersByDay($filters, true, "$year-01-01", "$year-12-31") as $row) {
            $month = (int) CarbonImmutable::parse($row->day)->month - 1;
            $monthly[$month] = round($monthly[$month] + (float) $row->liters, 2);
        }
        $highest = max($monthly) > 0 ? self::MONTHS[array_search(max($monthly), $monthly, true)] : null;

        // Past years, by calendar month.
        $history = [];
        foreach ($this->litersByDay($filters, false, null, ($year - 1).'-12-31') as $row) {
            $date = CarbonImmutable::parse($row->day);
            $history[$date->year][$date->month - 1] = ($history[$date->year][$date->month - 1] ?? 0) + (float) $row->liters;
        }
        $history = array_filter($history, fn (array $months): bool => array_sum($months) > 0);
        $yoyMonth = null;
        if ($history !== []) {
            $averages = array_map(fn (int $month): float => array_sum(array_map(fn (array $months): float => (float) ($months[$month] ?? 0), $history)) / count($history), range(0, 11));
            $yoyMonth = self::MONTHS[array_search(max($averages), $averages, true)];
        }

        // Next month, from the last 3 complete months.
        $thisMonth = $today->startOfMonth();
        $from = $thisMonth->subMonthsNoOverflow(self::MOVING_AVERAGE_PERIODS);
        $recent = array_fill(0, self::MOVING_AVERAGE_PERIODS, 0.0);
        foreach ($this->litersByDay($filters, false, $from->toDateString(), $thisMonth->subDay()->toDateString()) as $row) {
            $index = (int) $from->diffInMonths(CarbonImmutable::parse($row->day)->startOfMonth());
            $recent[$index] = round($recent[$index] + (float) $row->liters, 2);
        }
        $lastComplete = $recent[self::MOVING_AVERAGE_PERIODS - 1];
        $forecast = $this->average($recent);
        $nextRate = $lastComplete > 0 ? round(($forecast - $lastComplete) / $lastComplete * 100, 2) : null;

        return [
            'year' => $year,
            'monthly' => $monthly,
            'highestMonth' => $highest,
            'yoyMonth' => $yoyMonth,
            'nextMonthRate' => $nextRate,
            'highestMonthLabel' => $highest ?? 'No sales yet',
            'yoyMonthLabel' => $yoyMonth ?? 'Not enough history',
            'nextMonthRateLabel' => $nextRate === null ? 'N/A (no sales last month)' : $this->percent($nextRate),
            'chart' => $this->chart('bar', 'liters', self::MONTHS, [[
                'label' => 'Liters sold ('.$year.')',
                'data' => $monthly,
                'backgroundColor' => self::GREEN,
            ]]),
        ];
    }

    // ----- Filter options -----------------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function filterOptions(): array
    {
        return [
            'fuelTypes' => collect(self::FILTER_FUELS)->map(fn (string $code): ?object => $this->fuels()->get($code))->filter()->values(),
            'depots' => DB::table('depots')->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'customers' => DB::table('customers')->orderBy('name')->get(['id', 'name', 'company_name']),
        ];
    }

    // ----- Queries --------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $filters
     */
    private function matchingPurchaseLines(array $filters): Builder
    {
        return DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->whereNull('purchases.deleted_at')
            ->where('purchases.status', '!=', 'cancelled')
            ->when($filters['search'], fn (Builder $query, string $term): Builder => $query->where('purchases.purchase_code', 'like', '%'.$term.'%'))
            ->when($filters['fuel_type_id'], fn (Builder $query, int $id): Builder => $query->where('purchase_items.fuel_type_id', $id))
            ->when($filters['depot_id'], fn (Builder $query, int $id): Builder => $query->where('purchases.depot_id', $id));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function purchaseLinesQuery(array $filters): Builder
    {
        $lifted = DB::table('hauls')
            ->whereIn('status', ['lifted', 'completed'])
            ->groupBy('purchase_item_id')
            ->selectRaw('purchase_item_id, COALESCE(SUM(quantity_liters), 0) as lifted_liters');

        return $this->matchingPurchaseLines($filters)
            ->leftJoinSub($lifted, 'lifted', 'lifted.purchase_item_id', '=', 'purchase_items.id')
            ->select('purchase_items.id as purchase_item_id', 'purchase_items.purchase_id')
            ->selectRaw('purchase_items.quantity_ordered_liters as purchased_liters')
            ->selectRaw('CASE WHEN COALESCE(lifted.lifted_liters, 0) >= purchase_items.quantity_ordered_liters THEN 0 ELSE purchase_items.quantity_ordered_liters - COALESCE(lifted.lifted_liters, 0) END as remaining_liters')
            ->selectRaw("CASE WHEN COALESCE(lifted.lifted_liters, 0) <= 0 THEN 'unlifted' WHEN ROUND(COALESCE(lifted.lifted_liters, 0), 2) >= ROUND(purchase_items.quantity_ordered_liters, 2) THEN 'lifted' ELSE 'partial' END as lift_status");
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function liftCount(array $filters): int
    {
        $hauls = fn (): Builder => DB::table('hauls')
            ->where('status', '!=', 'cancelled')
            ->whereIn('purchase_item_id', $this->matchingPurchaseLines($filters)->select('purchase_items.id'));

        return (int) $hauls()->whereNotNull('lifting_schedule_id')->distinct()->count('lifting_schedule_id')
            + (int) $hauls()->whereNull('lifting_schedule_id')->count();
    }

    /** Lines of valid (not cancelled, not deleted) sales. */
    private function validSaleLines(): Builder
    {
        return DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', self::VALID_SALE_STATUSES);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function matchingSaleLines(array $filters, bool $withDates = true): Builder
    {
        return $this->validSaleLines()
            ->where(fn (Builder $query) => $this->applySaleFilters($query, $filters, $withDates))
            ->when($filters['fuel_type_id'], fn (Builder $query, int $id): Builder => $query->where('sale_items.fuel_type_id', $id));
    }

    /**
     * Search (sale code / sales order no.), date range and customer, on the joined sales table.
     *
     * @param  array<string, mixed>  $filters
     */
    private function applySaleFilters(Builder $query, array $filters, bool $withDates = true): void
    {
        $query->when($filters['search'], fn (Builder $query, string $term): Builder => $query->where(fn (Builder $query): Builder => $query
            ->where('sales.sale_code', 'like', '%'.$term.'%')
            ->orWhere('sales.sales_order_number', 'like', '%'.$term.'%')))
            ->when($withDates && $filters['date_from'], fn (Builder $query): Builder => $query->where('sales.sale_date', '>=', $filters['date_from']))
            ->when($withDates && $filters['date_to'], fn (Builder $query): Builder => $query->where('sales.sale_date', '<=', $filters['date_to']))
            ->when($filters['customer_id'], fn (Builder $query, int $id): Builder => $query->where('sales.customer_id', $id));
    }

    /**
     * One row per matching sale: its matching total and the payments allocated to it.
     *
     * @param  array<string, mixed>  $filters
     */
    private function salePaymentsQuery(array $filters): Builder
    {
        $matching = $this->matchingSaleLines($filters)
            ->groupBy('sales.id', 'sales.customer_id')
            ->selectRaw('sales.id as sale_id, sales.customer_id, COALESCE(SUM(sale_items.line_total), 0) as total');
        $saleTotals = DB::table('sale_items')->groupBy('sale_id')->selectRaw('sale_id, COALESCE(SUM(line_total), 0) as sale_total');
        $payments = DB::table('payments')->groupBy('sale_id')->selectRaw('sale_id, COALESCE(SUM(amount), 0) as paid');

        return DB::query()->fromSub($matching, 'matching')
            ->leftJoinSub($saleTotals, 'sale_totals', 'sale_totals.sale_id', '=', 'matching.sale_id')
            ->leftJoinSub($payments, 'payments_total', 'payments_total.sale_id', '=', 'matching.sale_id')
            ->selectRaw('matching.sale_id, matching.customer_id, matching.total')
            ->selectRaw('CASE WHEN COALESCE(sale_totals.sale_total, 0) > 0 THEN COALESCE(payments_total.paid, 0) * matching.total / sale_totals.sale_total ELSE 0 END as paid');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, \stdClass>
     */
    private function litersByDay(array $filters, bool $withDates, ?string $from, string $to): Collection
    {
        return $this->matchingSaleLines($filters, $withDates)
            ->when($from, fn (Builder $query, string $date): Builder => $query->where('sales.sale_date', '>=', $date))
            ->where('sales.sale_date', '<=', $to)
            ->groupBy('sales.sale_date')
            ->selectRaw('sales.sale_date as day, COALESCE(SUM(sale_items.quantity_liters), 0) as liters')
            ->get();
    }

    /**
     * Released stock-outs with their sale, sale line, the line price of a release recorded without a line, and
     * the cost basis (as on the Stock-Out tab).
     */
    private function releasesQuery(): Builder
    {
        $linePrices = DB::table('sale_items')->groupBy('sale_id', 'fuel_type_id')
            ->selectRaw('sale_id, fuel_type_id, AVG(unit_price) as unit_price');

        return DB::table('stock_outs')
            ->join('sales', 'sales.id', '=', 'stock_outs.sale_id')
            ->leftJoin('sale_items', 'sale_items.id', '=', 'stock_outs.sale_item_id')
            ->leftJoinSub($linePrices, 'line_prices', function ($join): void {
                $join->on('line_prices.sale_id', '=', 'stock_outs.sale_id')->on('line_prices.fuel_type_id', '=', 'stock_outs.fuel_type_id');
            })
            ->leftJoin('inventory_movements as release_movement', 'release_movement.id', '=', 'stock_outs.inventory_movement_id')
            ->leftJoin('haul_allocations as release_allocation', 'release_allocation.id', '=', 'stock_outs.haul_allocation_id')
            ->leftJoin('hauls as release_haul', 'release_haul.id', '=', 'release_allocation.haul_id')
            ->leftJoin('purchase_items as release_purchase_item', 'release_purchase_item.id', '=', 'release_haul.purchase_item_id')
            ->leftJoin('purchase_items as stock_purchase_item', 'stock_purchase_item.id', '=', 'stock_outs.purchase_item_id')
            ->where('stock_outs.status', 'released');
    }

    private function releasePriceSql(): string
    {
        // The F1 portion has its own price (lines before October 22, 2026 billed it at the line price).
        return "CASE WHEN stock_outs.portion = 'f1' AND sale_items.f1_unit_price > 0 THEN sale_items.f1_unit_price ELSE COALESCE(sale_items.unit_price, line_prices.unit_price, 0) END";
    }

    private function releaseCostSql(): string
    {
        return 'COALESCE(stock_outs.unit_cost, release_movement.unit_cost, stock_purchase_item.unit_cost, release_purchase_item.unit_cost)';
    }

    // ----- Helpers --------------------------------------------------------------------------------------------

    /** @return Collection<string, object> approved fuels keyed by code */
    private function fuels(): Collection
    {
        return $this->fuels ??= DB::table('fuel_types')
            ->where('status', 'active')
            ->whereIn('code', array_keys(config('fuels.approved', [])))
            ->get(['id', 'code', 'name'])
            ->keyBy('code');
    }

    /**
     * $yAxis: 'ticks' (numbers and gridlines), 'grid' (gridlines only) or 'none', as each mockup chart shows.
     *
     * @param  array<int, string>  $labels
     * @param  array<int, array<string, mixed>>  $datasets
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function chart(string $type, string $format, array $labels, array $datasets, bool $showValues = false, string $yAxis = 'ticks', array $extra = []): array
    {
        $values = collect($datasets)->flatMap(fn (array $dataset): array => $dataset['data']);

        return [
            'type' => $type,
            'format' => $format,
            'labels' => $labels,
            'datasets' => $datasets,
            'showValues' => $showValues,
            'yAxis' => $yAxis,
            'empty' => $labels === [] || $values->every(fn (mixed $value): bool => (float) $value === 0.0),
        ] + $extra;
    }

    private function periodStart(string $period, CarbonImmutable $date): CarbonImmutable
    {
        return match ($period) {
            'week' => $date->startOfWeek(CarbonImmutable::MONDAY),
            'year' => $date->startOfYear(),
            default => $date->startOfMonth(),
        };
    }

    private function shiftPeriod(string $period, CarbonImmutable $start, int $by): CarbonImmutable
    {
        return match ($period) {
            'week' => $start->addWeeks($by),
            'year' => $start->addYearsNoOverflow($by),
            default => $start->addMonthsNoOverflow($by),
        };
    }

    private function periodLabel(string $period, CarbonImmutable $start): string
    {
        return match ($period) {
            'week' => $start->format('M d'),
            'year' => $start->format('Y'),
            default => $start->format('M Y'),
        };
    }

    /** @param  array<int, float>  $values */
    private function average(array $values): float
    {
        return $values === [] ? 0.0 : round(array_sum($values) / count($values), 2);
    }

    public function money(float $value): string
    {
        return ($value < 0 ? '-' : '').'PHP '.number_format(abs($value), 2);
    }

    public function kl(float $liters): string
    {
        return number_format($liters / 1000, 2).' KL';
    }

    public function percent(float $value): string
    {
        return number_format($value, 2).'%';
    }
}
