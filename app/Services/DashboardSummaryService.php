<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class DashboardSummaryService
{
    public const VALID_SALE_STATUSES = ['confirmed', 'partially_paid', 'paid', 'unpaid'];
    public const SALES_TREND_PERIODS = ['week', 'month', 'year'];
    public const LIFTING_PROGRESS_STATUSES = ['unlifted', 'partial', 'lifted'];
    private const ACTIVE_DELIVERY_STATUSES = ['scheduled', 'in_transit', 'incomplete'];
    private const STOCK_LEVEL_COLORS = ['#f7043a', '#3b9a35', '#e28a22', '#0d1424', '#6b7280'];

    /**
     * @return array<string, mixed>
     */
    public function adminSummary(): array
    {
        $totalInventoryLiters = $this->totalInventoryLiters();
        $totalSalesRevenue = $this->totalSalesRevenue();
        $collectedRevenue = $this->collectedRevenue();
        $outstandingBalance = $this->outstandingReceivables();

        return [
            'totalInventoryLiters' => $totalInventoryLiters,
            'totalSalesRevenue' => $totalSalesRevenue,
            'collectedRevenue' => $collectedRevenue,
            'outstandingReceivables' => $outstandingBalance,
            'metricCards' => [
                ['Total Inventory (KL)', $this->formatKiloliters($totalInventoryLiters), 'Across all depots', ''],
                ['Total Sales Revenue', $this->formatMoney($totalSalesRevenue), 'Cumulative', ''],
                ['Outstanding Balance', $this->formatMoney($outstandingBalance), 'Receivables', 'color:#a31318'],
                ['Unlifted Fuel (KL)', $this->formatKiloliters($this->unliftedFuelLiters()), 'Pending lifting', ''],
                ['Active Deliveries', number_format($this->activeDeliveries()), 'Scheduled / in transit', ''],
            ],
        ];
    }

    /**
     * @return array<int, array{label: string, value: string, caption: string}>
     */
    public function inventoryCards(): array
    {
        return [
            ['label' => 'Total Inventory', 'value' => $this->formatLiters($this->totalInventoryLiters()), 'caption' => 'Current stock'],
            ['label' => 'Stock-In Today', 'value' => $this->formatLiters($this->stockMovementLiters('in', CarbonImmutable::now())), 'caption' => 'Received today'],
            ['label' => 'Stock-Out Today', 'value' => $this->formatLiters($this->stockMovementLiters('out', CarbonImmutable::now())), 'caption' => 'Released today'],
            ['label' => 'Unlifted Fuel', 'value' => $this->formatLiters($this->unliftedFuelLiters()), 'caption' => 'Pending lifting'],
            ['label' => 'Open Purchases', 'value' => number_format($this->openPurchases()), 'caption' => 'Not cancelled'],
        ];
    }

    /**
     * @return array<int, array{label: string, value: string, caption: string}>
     */
    public function salesCards(): array
    {
        return [
            ['label' => 'Total Sales', 'value' => $this->formatMoney($this->totalSalesRevenue()), 'caption' => 'Valid sales'],
            ['label' => "Today's Sales", 'value' => $this->formatMoney($this->salesRevenueForDate(CarbonImmutable::now())), 'caption' => 'Valid sales today'],
            ['label' => 'Payments Collected', 'value' => $this->formatMoney($this->collectedRevenue()), 'caption' => 'Recorded payments'],
            ['label' => 'Outstanding Receivables', 'value' => $this->formatMoney($this->outstandingReceivables()), 'caption' => 'Unpaid balances'],
            ['label' => 'Active Customers', 'value' => number_format($this->activeCustomers()), 'caption' => 'Customer records'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function salesTrend(string $period = 'week', ?int $year = null): array
    {
        $period = in_array($period, self::SALES_TREND_PERIODS, true) ? $period : 'week';
        $year = $year ?: CarbonImmutable::now()->year;

        [$labels, $values] = match ($period) {
            'month' => $this->monthlySalesTrend($year),
            'year' => $this->yearlySalesTrend($year),
            default => $this->weeklySalesTrend(),
        };

        $max = max([1, ...array_map(fn (float $value): float => abs($value), $values)]);
        $formattedValues = array_map(fn (float $value): string => $this->formatMoney($value, false), $values);

        return [
            'period' => $period,
            'year' => $year,
            'labels' => $labels,
            'values' => $values,
            'formattedValues' => $formattedValues,
            'total' => array_sum($values),
            'formattedTotal' => $this->formatMoney(array_sum($values)),
            'datasetLabel' => 'Sales Revenue',
            'bars' => collect($labels)
                ->map(fn (string $label, int $index): array => [
                    'label' => $label,
                    'value' => $formattedValues[$index],
                    'height' => $values[$index] === 0.0 ? 6 : max(6, (int) round((abs($values[$index]) / $max) * 96)),
                ])
                ->all(),
            'chart' => [
                'labels' => $labels,
                'datasets' => [[
                    'label' => 'Sales Revenue',
                    'data' => $values,
                    'formattedData' => $formattedValues,
                    'backgroundColor' => '#0d1424',
                    'borderColor' => '#0d1424',
                    'borderWidth' => 1,
                    'borderRadius' => 5,
                ]],
            ],
        ];
    }

    /**
     * @return array{
     *     rows: array<int, array{fuel_type_id: int, label: string, liters: float, formatted_liters: string}>,
     *     bars: array<int, array{label: string, value: string, height: int, color: string}>,
     *     chart: array{labels: array<int, string>, datasets: array<int, array<string, mixed>>},
     *     totalLiters: float,
     *     formattedTotal: string
     * }
     */
    public function stockLevels(): array
    {
        $rows = DB::table('fuel_types')
            ->leftJoinSub($this->inventoryBalancesByFuelQuery(), 'inventory_balances', 'inventory_balances.fuel_type_id', '=', 'fuel_types.id')
            ->where('fuel_types.status', 'active')
            ->selectRaw('fuel_types.id, fuel_types.name, COALESCE(inventory_balances.liters, 0) as liters')
            ->groupBy('fuel_types.id', 'fuel_types.name', 'inventory_balances.liters')
            ->orderBy('fuel_types.name')
            ->get();

        $values = $rows->map(fn (object $row): float => round((float) $row->liters, 2))->all();
        $labels = $rows->pluck('name')->map(fn (string $name): string => $name)->all();
        $formattedValues = array_map(fn (float $liters): string => $this->formatLiters($liters), $values);
        $max = max([1, ...array_map(fn (float $value): float => abs($value), $values)]);

        return [
            'rows' => $rows
                ->values()
                ->map(fn (object $row): array => [
                    'fuel_type_id' => (int) $row->id,
                    'label' => $row->name,
                    'liters' => round((float) $row->liters, 2),
                    'formatted_liters' => $this->formatLiters((float) $row->liters),
                ])
                ->all(),
            'bars' => collect($labels)
                ->map(fn (string $label, int $index): array => [
                    'label' => $label,
                    'value' => $formattedValues[$index],
                    'height' => $values[$index] === 0.0 ? 2 : max(2, (int) round((abs($values[$index]) / $max) * 100)),
                    'color' => self::STOCK_LEVEL_COLORS[$index % count(self::STOCK_LEVEL_COLORS)],
                ])
                ->all(),
            'chart' => [
                'labels' => $labels,
                'datasets' => [[
                    'label' => 'Available Stock',
                    'data' => $values,
                    'formattedData' => $formattedValues,
                    'backgroundColor' => array_map(
                        fn (int $index): string => self::STOCK_LEVEL_COLORS[$index % count(self::STOCK_LEVEL_COLORS)],
                        array_keys($labels)
                    ),
                    'borderColor' => '#ffffff',
                    'borderWidth' => 1,
                    'borderRadius' => 5,
                ]],
            ],
            'totalLiters' => array_sum($values),
            'formattedTotal' => $this->formatLiters(array_sum($values)),
        ];
    }

    /**
     * @return array{
     *     rows: array<int, array<string, mixed>>,
     *     customerTotals: array<int, array<string, mixed>>,
     *     chart: array{labels: array<int, string>, datasets: array<int, array<string, mixed>>},
     *     totalOutstanding: float,
     *     formattedTotalOutstanding: string,
     *     outstandingSalesCount: int
     * }
     */
    public function receivablesMonitoring(int $limit = 5): array
    {
        $rows = $this->outstandingReceivableRowsQuery()
            ->orderByRaw('(sale_totals.total - COALESCE(payment_totals.paid, 0)) desc')
            ->orderBy('sales.sale_date')
            ->orderBy('sales.id')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => $this->formatReceivableRow($row))
            ->all();

        $customerTotals = $this->receivableCustomerTotals($limit);
        $paid = $this->collectedRevenue();
        $outstanding = $this->outstandingReceivables();

        return [
            'rows' => $rows,
            'customerTotals' => $customerTotals,
            'chart' => [
                'labels' => ['Payments Collected', 'Outstanding Receivables'],
                'datasets' => [[
                    'label' => 'Receivables Monitoring',
                    'data' => [$paid, $outstanding],
                    'formattedData' => [$this->formatMoney($paid), $this->formatMoney($outstanding)],
                    'backgroundColor' => ['#238636', '#a7191d'],
                    'borderColor' => '#ffffff',
                    'borderWidth' => 1,
                    'borderRadius' => 5,
                ]],
            ],
            'totalOutstanding' => $outstanding,
            'formattedTotalOutstanding' => $this->formatMoney($outstanding),
            'outstandingSalesCount' => $this->outstandingSalesCount(),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function unliftedFuelMonitoring(array $filters = [], int $limit = 6): array
    {
        $base = $this->unliftedPurchaseItemsQuery($filters);
        $summary = DB::query()
            ->fromSub(clone $base, 'unlifted_items')
            ->selectRaw("
                COALESCE(SUM(purchased_liters), 0) as purchased_liters,
                COALESCE(SUM(lifted_liters), 0) as lifted_liters,
                COALESCE(SUM(remaining_liters), 0) as remaining_liters,
                COALESCE(SUM(CASE WHEN lift_status = 'partial' THEN 1 ELSE 0 END), 0) as partial_count,
                COALESCE(SUM(CASE WHEN lift_status = 'unlifted' THEN 1 ELSE 0 END), 0) as unlifted_count,
                COALESCE(SUM(CASE WHEN lift_status = 'lifted' THEN 1 ELSE 0 END), 0) as lifted_count
            ")
            ->first();

        $rows = DB::query()
            ->fromSub(clone $base, 'unlifted_items')
            ->where('remaining_liters', '>', 0)
            ->orderByDesc('purchase_date')
            ->orderBy('purchase_code')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => $this->formatUnliftedFuelRow($row))
            ->all();

        $fuelBreakdown = DB::query()
            ->fromSub(clone $base, 'unlifted_items')
            ->where('remaining_liters', '>', 0)
            ->selectRaw('fuel_type_id, fuel_name as label, COALESCE(SUM(remaining_liters), 0) as liters')
            ->groupBy('fuel_type_id', 'fuel_name')
            ->orderBy('fuel_name')
            ->get()
            ->map(fn (object $row): array => [
                'fuel_type_id' => (int) $row->fuel_type_id,
                'label' => $row->label,
                'liters' => round((float) $row->liters, 2),
                'formatted_liters' => $this->formatLiters((float) $row->liters),
            ])
            ->all();

        $depotBreakdown = DB::query()
            ->fromSub(clone $base, 'unlifted_items')
            ->where('remaining_liters', '>', 0)
            ->selectRaw('depot_id, depot_name as label, COALESCE(SUM(remaining_liters), 0) as liters')
            ->groupBy('depot_id', 'depot_name')
            ->orderBy('depot_name')
            ->get()
            ->map(fn (object $row): array => [
                'depot_id' => (int) $row->depot_id,
                'label' => $row->label,
                'liters' => round((float) $row->liters, 2),
                'formatted_liters' => $this->formatLiters((float) $row->liters),
            ])
            ->all();

        $purchased = round((float) ($summary->purchased_liters ?? 0), 2);
        $lifted = round((float) ($summary->lifted_liters ?? 0), 2);
        $remaining = round((float) ($summary->remaining_liters ?? 0), 2);

        return [
            'summary' => [
                'purchased_liters' => $purchased,
                'lifted_liters' => $lifted,
                'remaining_liters' => $remaining,
                'partial_count' => (int) ($summary->partial_count ?? 0),
                'unlifted_count' => (int) ($summary->unlifted_count ?? 0),
                'lifted_count' => (int) ($summary->lifted_count ?? 0),
                'formatted_purchased' => $this->formatLiters($purchased),
                'formatted_lifted' => $this->formatLiters($lifted),
                'formatted_remaining' => $this->formatLiters($remaining),
            ],
            'rows' => $rows,
            'fuelBreakdown' => $fuelBreakdown,
            'depotBreakdown' => $depotBreakdown,
            'chart' => [
                'labels' => ['Purchased', 'Lifted', 'Unlifted'],
                'datasets' => [[
                    'label' => 'Purchased vs Lifted vs Unlifted',
                    'data' => [$purchased, $lifted, $remaining],
                    'formattedData' => [$this->formatLiters($purchased), $this->formatLiters($lifted), $this->formatLiters($remaining)],
                    'backgroundColor' => ['#0d1424', '#3b9a35', '#f7043a'],
                    'borderColor' => '#ffffff',
                    'borderWidth' => 1,
                    'borderRadius' => 5,
                ]],
            ],
        ];
    }

    /**
     * Expected revenue is deterministic expected collectible cash for a year:
     * payments actually collected in the year plus still-outstanding receivables due in that year.
     *
     * @return array<string, mixed>
     */
    public function expectedRevenue(?int $year = null): array
    {
        $year = $year ?: CarbonImmutable::now()->year;
        $labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $collectedByMonth = $this->collectedRevenueByPaymentMonth($year);
        $dueOutstandingByMonth = $this->outstandingReceivablesByDueMonth($year);

        $collectedValues = array_map(fn (int $month): float => (float) ($collectedByMonth[$month] ?? 0), range(1, 12));
        $dueOutstandingValues = array_map(fn (int $month): float => (float) ($dueOutstandingByMonth[$month] ?? 0), range(1, 12));
        $expectedValues = array_map(
            fn (float $collected, float $dueOutstanding): float => round($collected + $dueOutstanding, 2),
            $collectedValues,
            $dueOutstandingValues
        );
        $formattedValues = array_map(fn (float $value): string => $this->formatMoney($value), $expectedValues);
        $totalCollected = array_sum($collectedValues);
        $totalDueOutstanding = array_sum($dueOutstandingValues);
        $totalExpected = array_sum($expectedValues);
        $collectionRate = $totalExpected > 0 ? round(($totalCollected / $totalExpected) * 100, 1) : 0.0;
        $max = max([1, ...array_map(fn (float $value): float => abs($value), $expectedValues)]);

        return [
            'year' => $year,
            'period' => (string) $year,
            'labels' => $labels,
            'values' => $expectedValues,
            'collectedValues' => $collectedValues,
            'dueOutstandingValues' => $dueOutstandingValues,
            'formattedValues' => $formattedValues,
            'totalExpected' => $totalExpected,
            'totalCollected' => $totalCollected,
            'totalDueOutstanding' => $totalDueOutstanding,
            'formattedTotalExpected' => $this->formatMoney($totalExpected),
            'formattedTotalCollected' => $this->formatMoney($totalCollected),
            'formattedTotalDueOutstanding' => $this->formatMoney($totalDueOutstanding),
            'collectionRate' => $collectionRate,
            'formattedCollectionRate' => number_format($collectionRate, 1).'%',
            'formula' => 'Expected Revenue = collected payments within the year + outstanding receivable balances due within the year.',
            'bars' => collect($labels)
                ->map(fn (string $label, int $index): array => [
                    'label' => $label,
                    'value' => $formattedValues[$index],
                    'height' => $expectedValues[$index] === 0.0 ? 6 : max(6, (int) round((abs($expectedValues[$index]) / $max) * 96)),
                ])
                ->all(),
            'chart' => [
                'labels' => $labels,
                'datasets' => [[
                    'label' => 'Expected Revenue',
                    'data' => $expectedValues,
                    'formattedData' => $formattedValues,
                    'backgroundColor' => '#0d1424',
                    'borderColor' => '#0d1424',
                    'borderWidth' => 1,
                    'borderRadius' => 5,
                ]],
            ],
        ];
    }

    public function totalInventoryLiters(): float
    {
        return (float) DB::table('fuel_types')
            ->leftJoinSub($this->inventoryBalancesByFuelQuery(), 'inventory_balances', 'inventory_balances.fuel_type_id', '=', 'fuel_types.id')
            ->where('fuel_types.status', 'active')
            ->selectRaw('COALESCE(SUM(inventory_balances.liters), 0) as total')
            ->value('total');
    }

    public function totalSalesRevenue(): float
    {
        return (float) DB::query()
            ->fromSub($this->saleTotalsQuery(), 'sale_totals')
            ->selectRaw('COALESCE(SUM(total), 0) as total')
            ->value('total');
    }

    public function collectedRevenue(): float
    {
        return (float) DB::query()
            ->fromSub($this->paymentTotalsQuery(), 'payment_totals')
            ->selectRaw('COALESCE(SUM(paid), 0) as total')
            ->value('total');
    }

    public function outstandingReceivables(): float
    {
        return (float) DB::query()
            ->fromSub($this->saleTotalsQuery(), 'sale_totals')
            ->leftJoinSub($this->paymentTotalsQuery(), 'payment_totals', 'payment_totals.sale_id', '=', 'sale_totals.sale_id')
            ->selectRaw('COALESCE(SUM(CASE WHEN sale_totals.total > COALESCE(payment_totals.paid, 0) THEN sale_totals.total - COALESCE(payment_totals.paid, 0) ELSE 0 END), 0) as total')
            ->value('total');
    }

    public function outstandingSalesCount(): int
    {
        return $this->outstandingReceivableRowsQuery()->count();
    }

    public function unliftedFuelLiters(): float
    {
        return (float) DB::query()
            ->fromSub($this->unliftedPurchaseItemsQuery(), 'unlifted_items')
            ->selectRaw('COALESCE(SUM(remaining_liters), 0) as total')
            ->value('total');
    }

    public function liftedFuelLiters(): float
    {
        return (float) DB::query()
            ->fromSub($this->unliftedPurchaseItemsQuery(), 'unlifted_items')
            ->selectRaw('COALESCE(SUM(lifted_liters), 0) as total')
            ->value('total');
    }

    /**
     * @return array<string, mixed>
     */
    public function unliftedFilterOptions(): array
    {
        return [
            'statuses' => self::LIFTING_PROGRESS_STATUSES,
            'fuelTypes' => DB::table('fuel_types')
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name']),
            'depots' => DB::table('depots')
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name']),
        ];
    }

    public function activeDeliveries(): int
    {
        return DB::table('deliveries')
            ->whereIn('status', self::ACTIVE_DELIVERY_STATUSES)
            ->count();
    }

    public function saleTotalsQuery(): Builder
    {
        return DB::table('sales')
            ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', self::VALID_SALE_STATUSES)
            ->selectRaw('sales.id as sale_id, COALESCE(SUM(sale_items.line_total), 0) as total')
            ->groupBy('sales.id');
    }

    public function paymentTotalsQuery(): Builder
    {
        return DB::table('payments')
            ->join('sales', 'sales.id', '=', 'payments.sale_id')
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', self::VALID_SALE_STATUSES)
            ->selectRaw('payments.sale_id, COALESCE(SUM(payments.amount), 0) as paid')
            ->groupBy('payments.sale_id');
    }

    public function formatMoney(float $value, bool $withSpace = true): string
    {
        return 'PHP'.($withSpace ? ' ' : '').number_format($value, 0);
    }

    public function formatLiters(float $value): string
    {
        return number_format($value, 0).' L';
    }

    public function formatKiloliters(float $liters): string
    {
        return number_format($liters / 1000, 0).' KL';
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, float>}
     */
    private function weeklySalesTrend(): array
    {
        $start = CarbonImmutable::now()->startOfWeek();
        $end = $start->endOfWeek();
        $labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

        $totals = DB::table('sales')
            ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', self::VALID_SALE_STATUSES)
            ->whereBetween('sales.sale_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('sales.sale_date as sale_date, COALESCE(SUM(sale_items.line_total), 0) as total')
            ->groupBy('sales.sale_date')
            ->get()
            ->mapWithKeys(fn (object $row): array => [CarbonImmutable::parse($row->sale_date)->format('D') => (float) $row->total]);

        return [$labels, array_map(fn (string $label): float => (float) ($totals[$label] ?? 0), $labels)];
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, float>}
     */
    private function monthlySalesTrend(int $year): array
    {
        $labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

        $totals = DB::table('sales')
            ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', self::VALID_SALE_STATUSES)
            ->whereBetween('sales.sale_date', [$year.'-01-01', $year.'-12-31'])
            ->selectRaw('CAST(SUBSTR(sales.sale_date, 6, 2) AS INTEGER) as month_number, COALESCE(SUM(sale_items.line_total), 0) as total')
            ->groupByRaw('CAST(SUBSTR(sales.sale_date, 6, 2) AS INTEGER)')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->month_number => (float) $row->total]);

        return [$labels, array_map(fn (int $month): float => (float) ($totals[$month] ?? 0), range(1, 12))];
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, float>}
     */
    private function yearlySalesTrend(int $endYear): array
    {
        $years = range($endYear - 4, $endYear);

        $totals = DB::table('sales')
            ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', self::VALID_SALE_STATUSES)
            ->whereBetween('sales.sale_date', [$years[0].'-01-01', $endYear.'-12-31'])
            ->selectRaw('CAST(SUBSTR(sales.sale_date, 1, 4) AS INTEGER) as sale_year, COALESCE(SUM(sale_items.line_total), 0) as total')
            ->groupByRaw('CAST(SUBSTR(sales.sale_date, 1, 4) AS INTEGER)')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->sale_year => (float) $row->total]);

        return [
            array_map(fn (int $year): string => (string) $year, $years),
            array_map(fn (int $year): float => (float) ($totals[$year] ?? 0), $years),
        ];
    }

    private function salesRevenueForDate(CarbonImmutable $date): float
    {
        return (float) DB::table('sales')
            ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', self::VALID_SALE_STATUSES)
            ->whereDate('sales.sale_date', $date->toDateString())
            ->selectRaw('COALESCE(SUM(sale_items.line_total), 0) as total')
            ->value('total');
    }

    private function stockMovementLiters(string $direction, CarbonImmutable $date): float
    {
        return (float) DB::table('inventory_movements')
            ->where('direction', $direction)
            ->whereNotExists($this->cancelledStockOutExists())
            ->whereNotExists($this->cancelledHaulAllocationExists())
            ->whereDate('movement_date', $date->toDateString())
            ->selectRaw('COALESCE(SUM(quantity_liters), 0) as total')
            ->value('total');
    }

    private function outstandingReceivableRowsQuery(): Builder
    {
        return DB::table('sales')
            ->joinSub($this->saleTotalsQuery(), 'sale_totals', 'sale_totals.sale_id', '=', 'sales.id')
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->leftJoin('receivables', 'receivables.sale_id', '=', 'sales.id')
            ->leftJoinSub($this->paymentTotalsQuery(), 'payment_totals', 'payment_totals.sale_id', '=', 'sales.id')
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', self::VALID_SALE_STATUSES)
            ->whereRaw('sale_totals.total > COALESCE(payment_totals.paid, 0)')
            ->select([
                'sales.id',
                'sales.sale_code',
                'sales.sale_date',
                'sales.status as sale_status',
                'customers.name as customer_name',
                'customers.company_name',
                'receivables.due_date',
                'receivables.status as receivable_status',
            ])
            ->selectRaw('sale_totals.total as sale_total, COALESCE(payment_totals.paid, 0) as total_paid, sale_totals.total - COALESCE(payment_totals.paid, 0) as balance');
    }

    /**
     * @return array<int, float>
     */
    private function collectedRevenueByPaymentMonth(int $year): array
    {
        return DB::table('payments')
            ->join('sales', 'sales.id', '=', 'payments.sale_id')
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', self::VALID_SALE_STATUSES)
            ->whereBetween('payments.payment_date', [$year.'-01-01', $year.'-12-31'])
            ->selectRaw('CAST(SUBSTR(payments.payment_date, 6, 2) AS INTEGER) as month_number, COALESCE(SUM(payments.amount), 0) as total')
            ->groupByRaw('CAST(SUBSTR(payments.payment_date, 6, 2) AS INTEGER)')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->month_number => (float) $row->total])
            ->all();
    }

    /**
     * @return array<int, float>
     */
    private function outstandingReceivablesByDueMonth(int $year): array
    {
        return DB::query()
            ->fromSub($this->outstandingReceivableRowsQuery(), 'outstanding_receivables')
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$year.'-01-01', $year.'-12-31'])
            ->selectRaw('CAST(SUBSTR(due_date, 6, 2) AS INTEGER) as month_number, COALESCE(SUM(balance), 0) as total')
            ->groupByRaw('CAST(SUBSTR(due_date, 6, 2) AS INTEGER)')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->month_number => (float) $row->total])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function receivableCustomerTotals(int $limit): array
    {
        return DB::query()
            ->fromSub($this->outstandingReceivableRowsQuery(), 'outstanding_receivables')
            ->select([
                'customer_name',
                'company_name',
            ])
            ->selectRaw('COUNT(*) as sales_count, COALESCE(SUM(sale_total), 0) as sale_total, COALESCE(SUM(total_paid), 0) as total_paid, COALESCE(SUM(balance), 0) as balance')
            ->groupBy('customer_name', 'company_name')
            ->orderByDesc('balance')
            ->orderBy('customer_name')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): array => [
                'customer_name' => $row->customer_name,
                'company_name' => $row->company_name,
                'sales_count' => (int) $row->sales_count,
                'sale_total' => (float) $row->sale_total,
                'paid' => (float) $row->total_paid,
                'balance' => (float) $row->balance,
                'formatted_sale_total' => $this->formatMoney((float) $row->sale_total),
                'formatted_paid' => $this->formatMoney((float) $row->total_paid),
                'formatted_balance' => $this->formatMoney((float) $row->balance),
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatReceivableRow(object $row): array
    {
        $saleTotal = (float) $row->sale_total;
        $paid = (float) $row->total_paid;
        $balance = max(0, (float) $row->balance);
        $status = $this->receivableStatusForSale($saleTotal, $paid, $row->due_date);

        return [
            'sale_id' => (int) $row->id,
            'sale_code' => $row->sale_code,
            'sale_date' => $row->sale_date,
            'customer_name' => $row->customer_name,
            'company_name' => $row->company_name,
            'sale_total' => $saleTotal,
            'paid' => $paid,
            'balance' => $balance,
            'due_date' => $row->due_date,
            'status' => $status,
            'status_label' => $this->receivableStatusLabel($status),
            'formatted_sale_total' => $this->formatMoney($saleTotal),
            'formatted_paid' => $this->formatMoney($paid),
            'formatted_balance' => $this->formatMoney($balance),
            'formatted_due_date' => $row->due_date ? CarbonImmutable::parse($row->due_date)->format('M d, Y') : 'N/A',
        ];
    }

    private function receivableStatusForSale(float $saleTotal, float $totalPaid, mixed $dueDate = null): string
    {
        if (round($totalPaid, 2) >= round($saleTotal, 2)) {
            return 'clear';
        }

        if ($dueDate && CarbonImmutable::parse($dueDate)->lt(CarbonImmutable::now()->startOfDay())) {
            return 'overdue';
        }

        return $totalPaid > 0 ? 'partial' : 'unpaid';
    }

    private function receivableStatusLabel(string $status): string
    {
        return match ($status) {
            'clear' => 'Settled',
            'partial' => 'Partially Paid',
            default => ucwords(str_replace('_', ' ', $status)),
        };
    }

    private function inventoryBalancesByFuelQuery(): Builder
    {
        return DB::table('inventory_movements')
            ->whereIn('direction', ['in', 'out'])
            ->whereNotExists($this->cancelledStockOutExists())
            ->whereNotExists($this->cancelledHaulAllocationExists())
            ->selectRaw("fuel_type_id, COALESCE(SUM(CASE WHEN direction = 'in' THEN quantity_liters WHEN direction = 'out' THEN -quantity_liters ELSE 0 END), 0) as liters")
            ->groupBy('fuel_type_id');
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function unliftedPurchaseItemsQuery(array $filters = []): Builder
    {
        $completedHauls = DB::table('hauls')
            ->where('status', 'completed')
            ->selectRaw('purchase_item_id, COALESCE(SUM(quantity_liters), 0) as completed_liters')
            ->groupBy('purchase_item_id');

        $query = DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->join('depots', 'depots.id', '=', 'purchases.depot_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'purchase_items.fuel_type_id')
            ->leftJoinSub($completedHauls, 'completed_hauls', 'completed_hauls.purchase_item_id', '=', 'purchase_items.id')
            ->whereNull('purchases.deleted_at')
            ->where('purchases.status', '!=', 'cancelled')
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date): Builder => $query->where('purchases.purchase_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date): Builder => $query->where('purchases.purchase_date', '<=', $date))
            ->when($filters['depot_id'] ?? null, fn (Builder $query, mixed $depotId): Builder => $query->where('purchases.depot_id', (int) $depotId))
            ->when($filters['fuel_type_id'] ?? null, fn (Builder $query, mixed $fuelTypeId): Builder => $query->where('purchase_items.fuel_type_id', (int) $fuelTypeId));

        $status = $filters['lifting_status'] ?? null;
        if (in_array($status, self::LIFTING_PROGRESS_STATUSES, true)) {
            $query->whereRaw($this->liftStatusSql().' = ?', [$status]);
        }

        return $query->select([
            'purchase_items.id as purchase_item_id',
            'purchase_items.purchase_id',
            'purchase_items.fuel_type_id',
            'purchases.depot_id',
            'purchases.purchase_code',
            'purchases.purchase_date',
            'purchases.status as purchase_status',
            'depots.name as depot_name',
            'fuel_types.name as fuel_name',
        ])->selectRaw('ROUND(purchase_items.quantity_ordered_liters, 2) as purchased_liters')
            ->selectRaw($this->liftedLitersSql().' as lifted_liters')
            ->selectRaw($this->remainingLitersSql().' as remaining_liters')
            ->selectRaw($this->liftStatusSql().' as lift_status');
    }

    private function liftedLitersSql(): string
    {
        return 'ROUND(CASE WHEN COALESCE(completed_hauls.completed_liters, 0) > purchase_items.quantity_ordered_liters THEN purchase_items.quantity_ordered_liters ELSE COALESCE(completed_hauls.completed_liters, 0) END, 2)';
    }

    private function remainingLitersSql(): string
    {
        return 'ROUND(CASE WHEN purchase_items.quantity_ordered_liters > COALESCE(completed_hauls.completed_liters, 0) THEN purchase_items.quantity_ordered_liters - COALESCE(completed_hauls.completed_liters, 0) ELSE 0 END, 2)';
    }

    private function liftStatusSql(): string
    {
        return "CASE WHEN COALESCE(completed_hauls.completed_liters, 0) <= 0 THEN 'unlifted' WHEN ROUND(COALESCE(completed_hauls.completed_liters, 0), 2) >= ROUND(purchase_items.quantity_ordered_liters, 2) THEN 'lifted' ELSE 'partial' END";
    }

    /**
     * @return array<string, mixed>
     */
    private function formatUnliftedFuelRow(object $row): array
    {
        return [
            'purchase_item_id' => (int) $row->purchase_item_id,
            'purchase_id' => (int) $row->purchase_id,
            'purchase_code' => $row->purchase_code,
            'purchase_date' => $row->purchase_date,
            'depot_name' => $row->depot_name,
            'fuel_name' => $row->fuel_name,
            'purchased_liters' => round((float) $row->purchased_liters, 2),
            'lifted_liters' => round((float) $row->lifted_liters, 2),
            'remaining_liters' => round((float) $row->remaining_liters, 2),
            'lift_status' => $row->lift_status,
            'lift_status_label' => $this->liftStatusLabel((string) $row->lift_status),
            'formatted_purchased' => $this->formatLiters((float) $row->purchased_liters),
            'formatted_lifted' => $this->formatLiters((float) $row->lifted_liters),
            'formatted_remaining' => $this->formatLiters((float) $row->remaining_liters),
            'formatted_purchase_date' => $row->purchase_date ? CarbonImmutable::parse($row->purchase_date)->format('M d, Y') : 'N/A',
        ];
    }

    private function liftStatusLabel(string $status): string
    {
        return match ($status) {
            'partial' => 'Partially Lifted',
            'lifted' => 'Fully Lifted',
            default => 'Unlifted',
        };
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

    private function openPurchases(): int
    {
        return DB::table('purchases')
            ->whereNull('deleted_at')
            ->where('status', '!=', 'cancelled')
            ->count();
    }

    private function activeCustomers(): int
    {
        return DB::table('customers')
            ->where('status', 'active')
            ->count();
    }
}
