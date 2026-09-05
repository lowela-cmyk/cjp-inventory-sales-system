<?php

namespace App\Services;

use Closure;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class DashboardSummaryService
{
    public const VALID_SALE_STATUSES = ['confirmed', 'partially_paid', 'paid', 'unpaid'];
    public const SALES_TREND_PERIODS = ['week', 'month', 'year'];
    public const LIFTING_PROGRESS_STATUSES = ['unlifted', 'partial', 'lifted'];
    public const INVENTORY_VARIANCE_STATUSES = ['matched', 'variance'];
    private const ACTIVE_DELIVERY_STATUSES = ['scheduled', 'in_transit', 'incomplete'];
    private const STOCK_LEVEL_COLORS = ['#f7043a', '#3b9a35', '#e28a22', '#0d1424', '#6b7280'];

    /**
     * @var array<string, mixed>
     */
    private array $memo = [];

    /**
     * @return array<string, mixed>
     */
    public function adminSummary(): array
    {
        return $this->remember('adminSummary', function (): array {
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
                ],
            ];
        });
    }

    /**
     * @return array<int, array{label: string, value: string, caption: string}>
     */
    public function inventoryCards(): array
    {
        return $this->remember('inventoryCards', fn (): array => [
            ['label' => 'Total Inventory', 'value' => $this->formatLiters($this->totalInventoryLiters()), 'caption' => 'Current stock'],
            ['label' => 'Stock-In Today', 'value' => $this->formatLiters($this->stockMovementLiters('in', CarbonImmutable::now())), 'caption' => 'Received today'],
            ['label' => 'Stock-Out Today', 'value' => $this->formatLiters($this->stockMovementLiters('out', CarbonImmutable::now())), 'caption' => 'Released today'],
            ['label' => 'Unlifted Fuel', 'value' => $this->formatLiters($this->unliftedFuelLiters()), 'caption' => 'Pending lifting'],
            ['label' => 'Open Purchases', 'value' => number_format($this->openPurchases()), 'caption' => 'Not cancelled'],
        ]);
    }

    /**
     * @return array<int, array{label: string, value: string, caption: string}>
     */
    public function salesCards(): array
    {
        return $this->remember('salesCards', fn (): array => [
            ['label' => 'Total Sales', 'value' => $this->formatMoney($this->totalSalesRevenue()), 'caption' => 'Valid sales'],
            ['label' => "Today's Sales", 'value' => $this->formatMoney($this->salesRevenueForDate(CarbonImmutable::now())), 'caption' => 'Valid sales today'],
            ['label' => 'Payments Collected', 'value' => $this->formatMoney($this->collectedRevenue()), 'caption' => 'Recorded payments'],
            ['label' => 'Outstanding Receivables', 'value' => $this->formatMoney($this->outstandingReceivables()), 'caption' => 'Unpaid balances'],
            ['label' => 'Active Customers', 'value' => number_format($this->activeCustomers()), 'caption' => 'Customer records'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function salesTrend(string $period = 'week', ?int $year = null): array
    {
        $period = in_array($period, self::SALES_TREND_PERIODS, true) ? $period : 'week';
        $year = $year ?: CarbonImmutable::now()->year;

        return $this->remember('salesTrend:'.$period.':'.$year, function () use ($period, $year): array {
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
        });
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
        return $this->remember('stockLevels', function (): array {
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
        });
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
        return $this->remember('receivablesMonitoring:'.$limit, function () use ($limit): array {
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
        });
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function unliftedFuelMonitoring(array $filters = [], int $limit = 6): array
    {
        return $this->remember('unliftedFuelMonitoring:'.md5(json_encode([$filters, $limit], JSON_THROW_ON_ERROR)), function () use ($filters, $limit): array {
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
        });
    }

    /**
     * Inventory variance follows the manuscript definition: stock-out vs receivables mismatch.
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function inventoryVarianceMonitoring(array $filters = [], int $limit = 6): array
    {
        return $this->remember('inventoryVarianceMonitoring:'.md5(json_encode([$filters, $limit], JSON_THROW_ON_ERROR)), function () use ($filters, $limit): array {
        $rows = $this->inventoryVarianceRows($filters);
        $totalChecked = $rows->count();
        $varianceCount = $rows->where('variance_status', 'variance')->count();
        $matchedCount = $totalChecked - $varianceCount;
        $quantityVariance = round((float) $rows->sum('quantity_variance_liters'), 2);
        $varianceRate = $totalChecked > 0 ? round(($varianceCount / $totalChecked) * 100, 1) : 0.0;
        $details = $rows
            ->where('variance_status', 'variance')
            ->sortByDesc('transaction_date')
            ->take($limit)
            ->map(fn (array $row): array => $this->formatInventoryVarianceRow($row))
            ->values()
            ->all();

        return [
            'summary' => [
                'total_checked' => $totalChecked,
                'matched_count' => $matchedCount,
                'variance_count' => $varianceCount,
                'variance_rate' => $varianceRate,
                'quantity_variance_liters' => $quantityVariance,
                'formatted_variance_rate' => number_format($varianceRate, 1).'%',
                'formatted_quantity_variance' => $this->formatLiters($quantityVariance),
            ],
            'rows' => $details,
            'reasonBreakdown' => $this->inventoryVarianceReasonBreakdown($rows),
            'chart' => [
                'labels' => ['Matched', 'Requires Verification'],
                'datasets' => [[
                    'label' => 'Inventory Variance',
                    'data' => [$matchedCount, $varianceCount],
                    'formattedData' => [number_format($matchedCount), number_format($varianceCount)],
                    'backgroundColor' => ['#3b9a35', '#f7043a'],
                    'borderColor' => '#ffffff',
                    'borderWidth' => 1,
                    'borderRadius' => 5,
                ]],
            ],
        ];
        });
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

        return $this->remember('expectedRevenue:'.$year, function () use ($year): array {
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
        });
    }

    public function totalInventoryLiters(): float
    {
        return $this->remember('totalInventoryLiters', fn (): float => (float) DB::table('fuel_types')
            ->leftJoinSub($this->inventoryBalancesByFuelQuery(), 'inventory_balances', 'inventory_balances.fuel_type_id', '=', 'fuel_types.id')
            ->where('fuel_types.status', 'active')
            ->selectRaw('COALESCE(SUM(inventory_balances.liters), 0) as total')
            ->value('total'));
    }

    public function totalSalesRevenue(): float
    {
        return $this->remember('totalSalesRevenue', fn (): float => (float) DB::query()
            ->fromSub($this->saleTotalsQuery(), 'sale_totals')
            ->selectRaw('COALESCE(SUM(total), 0) as total')
            ->value('total'));
    }

    public function collectedRevenue(): float
    {
        return $this->remember('collectedRevenue', fn (): float => (float) DB::query()
            ->fromSub($this->paymentTotalsQuery(), 'payment_totals')
            ->selectRaw('COALESCE(SUM(paid), 0) as total')
            ->value('total'));
    }

    public function outstandingReceivables(): float
    {
        return $this->remember('outstandingReceivables', fn (): float => (float) DB::query()
            ->fromSub($this->saleTotalsQuery(), 'sale_totals')
            ->leftJoinSub($this->paymentTotalsQuery(), 'payment_totals', 'payment_totals.sale_id', '=', 'sale_totals.sale_id')
            ->selectRaw('COALESCE(SUM(CASE WHEN sale_totals.total > COALESCE(payment_totals.paid, 0) THEN sale_totals.total - COALESCE(payment_totals.paid, 0) ELSE 0 END), 0) as total')
            ->value('total'));
    }

    public function outstandingSalesCount(): int
    {
        return $this->remember('outstandingSalesCount', fn (): int => $this->outstandingReceivableRowsQuery()->count());
    }

    public function unliftedFuelLiters(): float
    {
        return $this->remember('unliftedFuelLiters', fn (): float => (float) DB::query()
            ->fromSub($this->unliftedPurchaseItemsQuery(), 'unlifted_items')
            ->selectRaw('COALESCE(SUM(remaining_liters), 0) as total')
            ->value('total'));
    }

    public function liftedFuelLiters(): float
    {
        return $this->remember('liftedFuelLiters', fn (): float => (float) DB::query()
            ->fromSub($this->unliftedPurchaseItemsQuery(), 'unlifted_items')
            ->selectRaw('COALESCE(SUM(lifted_liters), 0) as total')
            ->value('total'));
    }

    /**
     * @return array<string, mixed>
     */
    public function unliftedFilterOptions(): array
    {
        return $this->remember('unliftedFilterOptions', fn (): array => [
            'statuses' => self::LIFTING_PROGRESS_STATUSES,
            'fuelTypes' => DB::table('fuel_types')
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name']),
            'depots' => DB::table('depots')
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function inventoryVarianceFilterOptions(): array
    {
        return $this->remember('inventoryVarianceFilterOptions', fn (): array => [
            'statuses' => self::INVENTORY_VARIANCE_STATUSES,
            'fuelTypes' => DB::table('fuel_types')
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name']),
            'customers' => DB::table('customers')
                ->orderBy('name')
                ->get(['id', 'name', 'company_name']),
        ]);
    }

    public function activeDeliveries(): int
    {
        return $this->remember('activeDeliveries', fn (): int => DB::table('deliveries')
            ->whereIn('status', self::ACTIVE_DELIVERY_STATUSES)
            ->count());
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
        $key = 'salesRevenueForDate:'.$date->toDateString();

        return $this->remember($key, fn (): float => (float) DB::table('sales')
            ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', self::VALID_SALE_STATUSES)
            ->whereDate('sales.sale_date', $date->toDateString())
            ->selectRaw('COALESCE(SUM(sale_items.line_total), 0) as total')
            ->value('total'));
    }

    private function stockMovementLiters(string $direction, CarbonImmutable $date): float
    {
        $key = 'stockMovementLiters:'.$direction.':'.$date->toDateString();

        return $this->remember($key, fn (): float => (float) DB::table('inventory_movements')
            ->where('direction', $direction)
            ->whereNotExists($this->cancelledStockOutExists())
            ->whereNotExists($this->cancelledHaulAllocationExists())
            ->whereDate('movement_date', $date->toDateString())
            ->selectRaw('COALESCE(SUM(quantity_liters), 0) as total')
            ->value('total'));
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

    /**
     * @param array<string, mixed> $filters
     */
    private function inventoryVarianceRows(array $filters = [])
    {
        $releaseTotals = $this->saleItemReleaseTotals();
        $paymentTotals = $this->paymentTotalsQuery();
        $duplicateDeliveries = DB::table('stock_outs')
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('delivery_id')
            ->selectRaw('delivery_id, COUNT(*) as stock_out_count')
            ->groupBy('delivery_id')
            ->havingRaw('COUNT(*) > 1');
        $duplicateSaleItems = DB::table('stock_outs')
            ->joinSub($duplicateDeliveries, 'duplicate_deliveries', 'duplicate_deliveries.delivery_id', '=', 'stock_outs.delivery_id')
            ->where('stock_outs.status', '!=', 'cancelled')
            ->whereNotNull('stock_outs.sale_item_id')
            ->selectRaw('stock_outs.sale_item_id, COUNT(*) as duplicate_count')
            ->groupBy('stock_outs.sale_item_id');

        $saleRows = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'sale_items.fuel_type_id')
            ->leftJoin('receivables', 'receivables.sale_id', '=', 'sales.id')
            ->leftJoinSub($releaseTotals, 'release_totals', 'release_totals.sale_item_id', '=', 'sale_items.id')
            ->leftJoinSub($paymentTotals, 'payment_totals', 'payment_totals.sale_id', '=', 'sales.id')
            ->leftJoinSub($duplicateSaleItems, 'duplicate_sale_items', 'duplicate_sale_items.sale_item_id', '=', 'sale_items.id')
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', self::VALID_SALE_STATUSES)
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date): Builder => $query->where('sales.sale_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date): Builder => $query->where('sales.sale_date', '<=', $date))
            ->when($filters['fuel_type_id'] ?? null, fn (Builder $query, mixed $fuelTypeId): Builder => $query->where('sale_items.fuel_type_id', (int) $fuelTypeId))
            ->when($filters['customer_id'] ?? null, fn (Builder $query, mixed $customerId): Builder => $query->where('sales.customer_id', (int) $customerId))
            ->select([
                'sales.id as sale_id',
                'sales.sale_code',
                'sales.sale_date as transaction_date',
                'sales.status as sale_status',
                'customers.id as customer_id',
                'customers.name as customer_name',
                'customers.company_name',
                'fuel_types.id as fuel_type_id',
                'fuel_types.name as fuel_name',
                'sale_items.id as sale_item_id',
                'sale_items.quantity_liters as sale_quantity_liters',
                'sale_items.line_total as sale_amount',
                'receivables.id as receivable_id',
                'receivables.status as receivable_status',
            ])
            ->selectRaw('COALESCE(release_totals.released_liters, 0) as stock_out_quantity_liters')
            ->selectRaw('COALESCE(payment_totals.paid, 0) as total_paid')
            ->selectRaw('CASE WHEN sale_items.line_total > COALESCE(payment_totals.paid, 0) THEN sale_items.line_total - COALESCE(payment_totals.paid, 0) ELSE 0 END as outstanding_amount')
            ->selectRaw('COALESCE(duplicate_sale_items.duplicate_count, 0) as duplicate_relationships')
            ->get()
            ->map(fn (object $row): array => $this->inventoryVarianceSaleRow($row));

        $invalidStockOutRows = $this->invalidStockOutRows($filters);

        $rows = $saleRows->merge($invalidStockOutRows)->values();
        $status = $filters['variance_status'] ?? null;

        if (in_array($status, self::INVENTORY_VARIANCE_STATUSES, true)) {
            $rows = $rows->where('variance_status', $status)->values();
        }

        return $rows;
    }

    private function saleItemReleaseTotals(): Builder
    {
        $garageReleases = DB::table('stock_outs')
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('sale_item_id')
            ->selectRaw('sale_item_id, COALESCE(SUM(quantity_liters), 0) as released_liters')
            ->groupBy('sale_item_id');

        $depotReleases = DB::table('deliveries')
            ->where('source_type', 'depot')
            ->where('status', 'delivered')
            ->whereNotNull('sale_item_id')
            ->selectRaw('sale_item_id, COALESCE(SUM(COALESCE(actual_quantity_liters, scheduled_quantity_liters, 0)), 0) as released_liters')
            ->groupBy('sale_item_id');

        return DB::query()
            ->fromSub($garageReleases->unionAll($depotReleases), 'release_rows')
            ->selectRaw('sale_item_id, COALESCE(SUM(released_liters), 0) as released_liters')
            ->groupBy('sale_item_id');
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function invalidStockOutRows(array $filters)
    {
        return DB::table('stock_outs')
            ->leftJoin('sales', 'sales.id', '=', 'stock_outs.sale_id')
            ->leftJoin('sale_items', 'sale_items.id', '=', 'stock_outs.sale_item_id')
            ->leftJoin('customers', 'customers.id', '=', 'stock_outs.customer_id')
            ->leftJoin('fuel_types', 'fuel_types.id', '=', 'stock_outs.fuel_type_id')
            ->where('stock_outs.status', '!=', 'cancelled')
            ->where(function (Builder $query): void {
                $query->whereNull('sales.id')
                    ->orWhereNotNull('sales.deleted_at')
                    ->orWhereNotIn('sales.status', self::VALID_SALE_STATUSES)
                    ->orWhereNull('stock_outs.sale_item_id')
                    ->orWhereNull('sale_items.id')
                    ->orWhereColumn('sale_items.sale_id', '!=', 'stock_outs.sale_id')
                    ->orWhereColumn('sale_items.fuel_type_id', '!=', 'stock_outs.fuel_type_id')
                    ->orWhereColumn('sales.customer_id', '!=', 'stock_outs.customer_id');
            })
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date): Builder => $query->where('stock_outs.stock_out_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date): Builder => $query->where('stock_outs.stock_out_at', '<=', $date.' 23:59:59'))
            ->when($filters['fuel_type_id'] ?? null, fn (Builder $query, mixed $fuelTypeId): Builder => $query->where('stock_outs.fuel_type_id', (int) $fuelTypeId))
            ->when($filters['customer_id'] ?? null, fn (Builder $query, mixed $customerId): Builder => $query->where('stock_outs.customer_id', (int) $customerId))
            ->get([
                'stock_outs.id as stock_out_id',
                'stock_outs.stock_out_code',
                'stock_outs.stock_out_at as transaction_date',
                'stock_outs.quantity_liters as stock_out_quantity_liters',
                'stock_outs.sale_id',
                'stock_outs.sale_item_id',
                'stock_outs.customer_id',
                'stock_outs.fuel_type_id',
                'sales.sale_code',
                'sales.status as sale_status',
                'customers.name as customer_name',
                'customers.company_name',
                'fuel_types.name as fuel_name',
                'sale_items.quantity_liters as sale_quantity_liters',
                'sale_items.line_total as sale_amount',
            ])
            ->map(fn (object $row): array => [
                'transaction_key' => 'stock_out:'.$row->stock_out_id,
                'sale_id' => $row->sale_id ? (int) $row->sale_id : null,
                'sale_item_id' => $row->sale_item_id ? (int) $row->sale_item_id : null,
                'sale_code' => $row->sale_code ?: 'Missing Sale',
                'stock_out_code' => $row->stock_out_code,
                'transaction_date' => $row->transaction_date,
                'customer_id' => $row->customer_id ? (int) $row->customer_id : null,
                'customer_name' => $row->customer_name ?: 'Missing Customer',
                'company_name' => $row->company_name ?: 'N/A',
                'fuel_type_id' => $row->fuel_type_id ? (int) $row->fuel_type_id : null,
                'fuel_name' => $row->fuel_name ?: 'Missing Fuel Type',
                'sale_quantity_liters' => round((float) ($row->sale_quantity_liters ?? 0), 2),
                'stock_out_quantity_liters' => round((float) $row->stock_out_quantity_liters, 2),
                'quantity_variance_liters' => round((float) $row->stock_out_quantity_liters - (float) ($row->sale_quantity_liters ?? 0), 2),
                'sale_amount' => round((float) ($row->sale_amount ?? 0), 2),
                'total_paid' => 0.0,
                'outstanding_amount' => 0.0,
                'receivable_status' => 'missing',
                'variance_status' => 'variance',
                'reasons' => ['Invalid Transaction Link', 'Missing Sale/Receivable'],
            ]);
    }

    private function inventoryVarianceSaleRow(object $row): array
    {
        $saleQuantity = round((float) $row->sale_quantity_liters, 2);
        $stockOutQuantity = round((float) $row->stock_out_quantity_liters, 2);
        $saleAmount = round((float) $row->sale_amount, 2);
        $paid = round((float) $row->total_paid, 2);
        $reasons = [];

        if (! $row->receivable_id) {
            $reasons[] = 'Missing Sale/Receivable';
        }

        if ($stockOutQuantity <= 0) {
            $reasons[] = 'Missing Stock-Out';
        } elseif (round($stockOutQuantity, 2) !== round($saleQuantity, 2)) {
            $reasons[] = 'Quantity Mismatch';
        }

        if ((int) $row->duplicate_relationships > 0) {
            $reasons[] = 'Duplicate Relationship';
        }

        if ($paid > $saleAmount) {
            $reasons[] = 'Financial Record Mismatch';
        }

        return [
            'transaction_key' => 'sale_item:'.$row->sale_item_id,
            'sale_id' => (int) $row->sale_id,
            'sale_item_id' => (int) $row->sale_item_id,
            'sale_code' => $row->sale_code,
            'stock_out_code' => null,
            'transaction_date' => $row->transaction_date,
            'customer_id' => (int) $row->customer_id,
            'customer_name' => $row->customer_name,
            'company_name' => $row->company_name,
            'fuel_type_id' => (int) $row->fuel_type_id,
            'fuel_name' => $row->fuel_name,
            'sale_quantity_liters' => $saleQuantity,
            'stock_out_quantity_liters' => $stockOutQuantity,
            'quantity_variance_liters' => round($stockOutQuantity - $saleQuantity, 2),
            'sale_amount' => $saleAmount,
            'total_paid' => $paid,
            'outstanding_amount' => round((float) $row->outstanding_amount, 2),
            'receivable_status' => $row->receivable_status ?: 'missing',
            'variance_status' => $reasons === [] ? 'matched' : 'variance',
            'reasons' => $reasons,
        ];
    }

    private function inventoryVarianceReasonBreakdown($rows): array
    {
        return $rows
            ->flatMap(fn (array $row): array => $row['reasons'])
            ->countBy()
            ->map(fn (int $count, string $reason): array => [
                'label' => $reason,
                'count' => $count,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function formatInventoryVarianceRow(array $row): array
    {
        return array_merge($row, [
            'transaction_reference' => $row['stock_out_code'] ? $row['sale_code'].' / '.$row['stock_out_code'] : $row['sale_code'],
            'formatted_transaction_date' => $row['transaction_date'] ? CarbonImmutable::parse($row['transaction_date'])->format('M d, Y') : 'N/A',
            'formatted_sale_quantity' => $this->formatLiters((float) $row['sale_quantity_liters']),
            'formatted_stock_out_quantity' => $this->formatLiters((float) $row['stock_out_quantity_liters']),
            'formatted_quantity_variance' => $this->formatLiters((float) $row['quantity_variance_liters']),
            'formatted_sale_amount' => $this->formatMoney((float) $row['sale_amount']),
            'formatted_total_paid' => $this->formatMoney((float) $row['total_paid']),
            'formatted_outstanding_amount' => $this->formatMoney((float) $row['outstanding_amount']),
            'receivable_status_label' => $this->receivableStatusLabel((string) $row['receivable_status']),
            'variance_status_label' => $row['variance_status'] === 'matched' ? 'Matched' : 'Requires Verification',
            'reason' => implode('; ', $row['reasons']),
        ]);
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
        return $this->remember('openPurchases', fn (): int => DB::table('purchases')
            ->whereNull('deleted_at')
            ->where('status', '!=', 'cancelled')
            ->count());
    }

    private function activeCustomers(): int
    {
        return $this->remember('activeCustomers', fn (): int => DB::table('customers')
            ->where('status', 'active')
            ->count());
    }

    private function remember(string $key, Closure $callback): mixed
    {
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        return $this->memo[$key] = $callback();
    }
}
