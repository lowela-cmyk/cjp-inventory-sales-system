<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminDashboardService
{
    public function __construct(private DashboardSummaryService $summary)
    {
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function data(array $filters = []): array
    {
        $summary = $this->summary->adminSummary();
        $totalSalesRevenue = $summary['totalSalesRevenue'];
        $collectedRevenue = $summary['collectedRevenue'];
        $outstandingBalance = $summary['outstandingReceivables'];
        $stockLevels = $this->summary->stockLevels();
        $receivablesMonitoring = $this->summary->receivablesMonitoring();
        $unliftedFilters = [
            'date_from' => $filters['unlifted_date_from'] ?? null,
            'date_to' => $filters['unlifted_date_to'] ?? null,
            'depot_id' => $filters['unlifted_depot_id'] ?? null,
            'fuel_type_id' => $filters['unlifted_fuel_type_id'] ?? null,
            'lifting_status' => $filters['unlifted_lifting_status'] ?? null,
        ];
        $unliftedMonitoring = $this->summary->unliftedFuelMonitoring($unliftedFilters);
        $expectedRevenue = $this->summary->expectedRevenue(
            isset($filters['expected_year']) ? (int) $filters['expected_year'] : null
        );
        $salesTrend = $this->summary->salesTrend(
            (string) ($filters['trend_period'] ?? 'week'),
            isset($filters['trend_year']) ? (int) $filters['trend_year'] : null
        );

        return [
            'metricCards' => $summary['metricCards'],
            'salesTrend' => $salesTrend['bars'],
            'salesTrendChart' => $salesTrend['chart'],
            'salesTrendFilters' => [
                'period' => $salesTrend['period'],
                'year' => $salesTrend['year'],
            ],
            'stockByFuelType' => $stockLevels['bars'],
            'stockLevelChart' => $stockLevels['chart'],
            'receivablesMonitoring' => $receivablesMonitoring,
            'receivableRows' => $receivablesMonitoring['rows'],
            'receivablesChart' => $receivablesMonitoring['chart'],
            'unliftedMonitoring' => $unliftedMonitoring,
            'unliftedFuelRows' => $unliftedMonitoring['rows'],
            'unliftedFuelChart' => $unliftedMonitoring['chart'],
            'unliftedFuelFilters' => $unliftedFilters,
            'unliftedFuelFilterOptions' => $this->summary->unliftedFilterOptions(),
            'expectedRevenue' => $expectedRevenue,
            'expectedRevenueChart' => $expectedRevenue['chart'],
            'revenueBars' => $this->revenueBars($totalSalesRevenue, $collectedRevenue, $outstandingBalance),
            'demandDays' => $this->demandByDay(),
            'demandMonths' => $this->demandByMonth(),
        ];
    }

    /**
     * @return array<int, array{label: string, value: string, height: int, color: string}>
     */
    private function revenueBars(float $revenue, float $collected, float $receivables): array
    {
        $rows = [
            ['Revenue', $revenue, '#0d1424'],
            ['Collected', $collected, '#0d1424'],
            ['Receivables', $receivables, '#a7191d'],
        ];

        $max = max(1, ...array_map(fn (array $row): float => abs((float) $row[1]), $rows));

        return array_map(fn (array $row): array => [
            'label' => $row[0],
            'value' => $this->formatMoney((float) $row[1], false),
            'height' => (float) $row[1] === 0.0 ? 2 : max(2, (int) round((abs((float) $row[1]) / $max) * 150)),
            'color' => $row[2],
        ], $rows);
    }

    /**
     * @return array<int, array{label: string, percent: int, hot: bool}>
     */
    private function demandByDay(): array
    {
        $labels = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
        $totals = $this->salesQuantityByDate()->groupBy(fn (object $row): string => CarbonImmutable::parse($row->sale_date)->format('D'))
            ->map(fn (Collection $rows): float => (float) $rows->sum('quantity'));

        return $this->normalizedDemandRows($labels, $totals);
    }

    /**
     * @return array<int, array{label: string, percent: int, hot: bool}>
     */
    private function demandByMonth(): array
    {
        $labels = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $totals = $this->salesQuantityByDate()->groupBy(fn (object $row): string => CarbonImmutable::parse($row->sale_date)->format('M'))
            ->map(fn (Collection $rows): float => (float) $rows->sum('quantity'));

        return $this->normalizedDemandRows($labels, $totals);
    }

    private function salesQuantityByDate(): Collection
    {
        return DB::table('sales')
            ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', DashboardSummaryService::VALID_SALE_STATUSES)
            ->selectRaw('sales.sale_date as sale_date, COALESCE(SUM(sale_items.quantity_liters), 0) as quantity')
            ->groupBy('sales.sale_date')
            ->get();
    }

    /**
     * @param array<int, string> $labels
     * @param Collection<string, float> $totals
     * @return array<int, array{label: string, percent: int, hot: bool}>
     */
    private function normalizedDemandRows(array $labels, Collection $totals): array
    {
        $max = (float) $totals->max();

        return collect($labels)
            ->map(function (string $label) use ($totals, $max): array {
                $value = (float) ($totals[$label] ?? 0);
                $percent = $max > 0 ? (int) round(($value / $max) * 100) : 0;

                return [
                    'label' => $label,
                    'percent' => $percent,
                    'hot' => $percent === 100 && $max > 0,
                ];
            })
            ->all();
    }

    private function saleTotalsQuery()
    {
        return $this->summary->saleTotalsQuery();
    }

    private function paymentTotalsQuery()
    {
        return $this->summary->paymentTotalsQuery();
    }

    private function formatMoney(float $value, bool $withSpace = true): string
    {
        return $this->summary->formatMoney($value, $withSpace);
    }

    private function formatLiters(float $value): string
    {
        return $this->summary->formatLiters($value);
    }

    private function formatKiloliters(float $liters): string
    {
        return $this->summary->formatKiloliters($liters);
    }
}
