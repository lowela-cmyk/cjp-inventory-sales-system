<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class DashboardSummaryService
{
    public const VALID_SALE_STATUSES = ['confirmed', 'partially_paid', 'paid', 'unpaid'];
    public const SALES_TREND_PERIODS = ['week', 'month', 'year'];
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

    public function unliftedFuelLiters(): float
    {
        return (float) DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->whereNull('purchases.deleted_at')
            ->where('purchases.status', '!=', 'cancelled')
            ->selectRaw('COALESCE(SUM(CASE WHEN quantity_ordered_liters > quantity_hauled_liters THEN quantity_ordered_liters - quantity_hauled_liters ELSE 0 END), 0) as total')
            ->value('total');
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

    private function inventoryBalancesByFuelQuery(): Builder
    {
        return DB::table('inventory_movements')
            ->whereIn('direction', ['in', 'out'])
            ->whereNotExists($this->cancelledStockOutExists())
            ->whereNotExists($this->cancelledHaulAllocationExists())
            ->selectRaw("fuel_type_id, COALESCE(SUM(CASE WHEN direction = 'in' THEN quantity_liters WHEN direction = 'out' THEN -quantity_liters ELSE 0 END), 0) as liters")
            ->groupBy('fuel_type_id');
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
