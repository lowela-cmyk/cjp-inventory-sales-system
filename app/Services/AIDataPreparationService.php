<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AIDataPreparationService
{
    public function __construct(private DashboardSummaryService $summary) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     *
     * @throws AuthorizationException
     */
    public function prepareForUser(User $user, array $filters = []): array
    {
        if ($user->role !== 'admin') {
            throw new AuthorizationException('Only administrators can prepare company-wide AI reporting data.');
        }

        return $this->prepare($filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function prepare(array $filters = []): array
    {
        $filters = $this->validatedFilters($filters);
        $limit = (int) $filters['limit'];
        $period = $this->reportingPeriod($filters);
        $dateRange = [$period['date_from'], $period['date_to']];
        $trendPeriod = (string) $filters['trend_period'];
        $trendYear = (int) $filters['trend_year'];
        $expectedYear = (int) $filters['expected_year'];

        return [
            'reporting_period' => $period,
            'payload_policy' => [
                'purpose' => 'Prepared system-calculated data for later AI explanation only.',
                'authoritative_calculations' => 'Laravel/MySQL services remain the source of truth. AI must not calculate official totals.',
                'row_limit' => $limit,
                'sensitive_fields_excluded' => [
                    'passwords',
                    'password_hashes',
                    'api_keys',
                    'auth_tokens',
                    'session_data',
                    'receipt_files',
                    'unnecessary_customer_personal_information',
                ],
            ],
            'revenue' => $this->revenueData($dateRange, $expectedYear),
            'sales_trends' => $this->salesTrendData($trendPeriod, $trendYear, $dateRange),
            'inventory' => $this->inventoryData($dateRange),
            'fuel_lifting' => $this->fuelLiftingData($dateRange, $limit),
            'receivables' => $this->receivablesData($limit),
            'inventory_variance' => $this->inventoryVarianceData($dateRange, $limit),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function validatedFilters(array $filters): array
    {
        $validated = Validator::make($filters, [
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'trend_period' => ['nullable', Rule::in(DashboardSummaryService::SALES_TREND_PERIODS)],
            'trend_year' => ['nullable', 'integer', 'between:2000,2100'],
            'expected_year' => ['nullable', 'integer', 'between:2000,2100'],
            'limit' => ['nullable', 'integer', 'between:1,10'],
        ])->validate();

        $now = CarbonImmutable::now();

        return [
            'date_from' => isset($validated['date_from']) ? CarbonImmutable::parse($validated['date_from'])->toDateString() : null,
            'date_to' => isset($validated['date_to']) ? CarbonImmutable::parse($validated['date_to'])->toDateString() : null,
            'trend_period' => $validated['trend_period'] ?? 'month',
            'trend_year' => isset($validated['trend_year']) ? (int) $validated['trend_year'] : $now->year,
            'expected_year' => isset($validated['expected_year']) ? (int) $validated['expected_year'] : $now->year,
            'limit' => isset($validated['limit']) ? (int) $validated['limit'] : 6,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function reportingPeriod(array $filters): array
    {
        return [
            'scope' => $filters['date_from'] || $filters['date_to'] ? 'filtered' : 'all_time',
            'date_from' => $filters['date_from'],
            'date_to' => $filters['date_to'],
            'trend_period' => $filters['trend_period'],
            'trend_year' => $filters['trend_year'],
            'expected_revenue_year' => $filters['expected_year'],
            'timezone' => config('app.timezone'),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
        ];
    }

    /**
     * @param  array{0: ?string, 1: ?string}  $dateRange
     * @return array<string, mixed>
     */
    private function revenueData(array $dateRange, int $expectedYear): array
    {
        $salesTotal = DB::query()
            ->fromSub($this->periodSaleTotalsQuery($dateRange), 'period_sales')
            ->selectRaw('COUNT(*) as sales_count, COALESCE(SUM(total), 0) as total')
            ->first();
        $totalValidSales = round((float) ($salesTotal->total ?? 0), 2);
        $collectedRevenue = round($this->periodCollectedRevenue($dateRange), 2);
        $outstandingReceivables = round($this->periodOutstandingReceivables($dateRange), 2);
        $expectedRevenue = $this->summary->expectedRevenue($expectedYear);

        return [
            'currency' => 'PHP',
            'period' => [
                'date_from' => $dateRange[0],
                'date_to' => $dateRange[1],
            ],
            'total_valid_sales' => $totalValidSales,
            'valid_sales_count' => (int) ($salesTotal->sales_count ?? 0),
            'collected_revenue' => $collectedRevenue,
            'expected_revenue' => round((float) $expectedRevenue['totalExpected'], 2),
            'expected_revenue_year' => $expectedYear,
            'expected_revenue_collected' => round((float) $expectedRevenue['totalCollected'], 2),
            'expected_revenue_due_outstanding' => round((float) $expectedRevenue['totalDueOutstanding'], 2),
            'outstanding_receivables' => $outstandingReceivables,
            'collection_percentage' => $totalValidSales > 0 ? round(($collectedRevenue / $totalValidSales) * 100, 1) : 0.0,
            'source' => 'DashboardSummaryService saleTotalsQuery(), paymentTotalsQuery(), expectedRevenue().',
        ];
    }

    /**
     * @param  array{0: ?string, 1: ?string}  $dateRange
     * @return array<string, mixed>
     */
    private function salesTrendData(string $trendPeriod, int $trendYear, array $dateRange): array
    {
        $trend = $this->summary->salesTrend($trendPeriod, $trendYear);

        return [
            'reporting_period' => $trend['period'],
            'year' => $trend['year'],
            'currency' => 'PHP',
            'series' => collect($trend['labels'])
                ->map(fn (string $label, int $index): array => [
                    'label' => $this->sanitizeText($label),
                    'sales_total' => round((float) $trend['values'][$index], 2),
                    'quantity_sold_liters' => $this->quantitySoldSeries($trendPeriod, $trendYear)[$index] ?? 0.0,
                ])
                ->all(),
            'total_sales' => round((float) $trend['total'], 2),
            'previous_period_comparison' => null,
            'percentage_change' => null,
            'comparison_note' => 'Previous-period comparison is not yet calculated by the existing dashboard service.',
            'fuel_type_breakdown' => $this->salesFuelBreakdown($dateRange),
            'source' => 'DashboardSummaryService salesTrend(); quantity and fuel summaries use the same valid sale status rules.',
        ];
    }

    /**
     * @param  array{0: ?string, 1: ?string}  $dateRange
     * @return array<string, mixed>
     */
    private function inventoryData(array $dateRange): array
    {
        $stockLevels = $this->summary->stockLevels();

        return [
            'quantity_unit' => 'liters',
            'current_stock_liters' => round((float) $stockLevels['totalLiters'], 2),
            'fuel_type_breakdown' => collect($stockLevels['rows'])
                ->map(fn (array $row): array => [
                    'fuel_type_id' => (int) $row['fuel_type_id'],
                    'fuel_type' => $this->sanitizeText((string) $row['label']),
                    'stock_liters' => round((float) $row['liters'], 2),
                ])
                ->all(),
            'recorded_movement_summary_liters' => $this->inventoryMovementSummary($dateRange),
            'separation_note' => 'Current stock is garage inventory from recorded inventory movements; depot fuel pending lifting is reported separately under fuel_lifting.',
            'source' => 'DashboardSummaryService stockLevels(); movement summary uses inventory_movements aggregates.',
        ];
    }

    /**
     * @param  array{0: ?string, 1: ?string}  $dateRange
     * @return array<string, mixed>
     */
    private function fuelLiftingData(array $dateRange, int $limit): array
    {
        $lifting = $this->summary->unliftedFuelMonitoring([
            'date_from' => $dateRange[0],
            'date_to' => $dateRange[1],
        ], $limit);

        return [
            'quantity_unit' => 'liters',
            'summary' => [
                'purchased_liters' => round((float) $lifting['summary']['purchased_liters'], 2),
                'lifted_liters' => round((float) $lifting['summary']['lifted_liters'], 2),
                'unlifted_liters' => round((float) $lifting['summary']['remaining_liters'], 2),
                'partial_count' => (int) $lifting['summary']['partial_count'],
                'unlifted_count' => (int) $lifting['summary']['unlifted_count'],
                'fully_lifted_count' => (int) $lifting['summary']['lifted_count'],
            ],
            'fuel_breakdown' => collect($lifting['fuelBreakdown'])
                ->map(fn (array $row): array => [
                    'fuel_type_id' => (int) $row['fuel_type_id'],
                    'fuel_type' => $this->sanitizeText((string) $row['label']),
                    'unlifted_liters' => round((float) $row['liters'], 2),
                ])
                ->all(),
            'depot_breakdown' => collect($lifting['depotBreakdown'])
                ->map(fn (array $row): array => [
                    'depot_id' => (int) $row['depot_id'],
                    'depot' => $this->sanitizeText((string) $row['label']),
                    'unlifted_liters' => round((float) $row['liters'], 2),
                ])
                ->all(),
            'sample_open_items' => collect($lifting['rows'])
                ->map(fn (array $row): array => [
                    'purchase_code' => $this->sanitizeText((string) $row['purchase_code']),
                    'purchase_date' => $row['purchase_date'],
                    'depot' => $this->sanitizeText((string) $row['depot_name']),
                    'fuel_type' => $this->sanitizeText((string) $row['fuel_name']),
                    'purchased_liters' => round((float) $row['purchased_liters'], 2),
                    'lifted_liters' => round((float) $row['lifted_liters'], 2),
                    'remaining_liters' => round((float) $row['remaining_liters'], 2),
                    'lift_status' => $this->sanitizeText((string) $row['lift_status']),
                ])
                ->all(),
            'source' => 'DashboardSummaryService unliftedFuelMonitoring().',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function receivablesData(int $limit): array
    {
        $receivables = $this->summary->receivablesMonitoring($limit);

        return [
            'currency' => 'PHP',
            'total_outstanding' => round((float) $receivables['totalOutstanding'], 2),
            'outstanding_sales_count' => (int) $receivables['outstandingSalesCount'],
            'status_breakdown' => $this->receivableStatusBreakdown(),
            'top_balance_buckets' => collect($receivables['customerTotals'])
                ->values()
                ->map(fn (array $row, int $index): array => [
                    'bucket' => 'customer_bucket_'.($index + 1),
                    'sales_count' => (int) $row['sales_count'],
                    'sale_total' => round((float) $row['sale_total'], 2),
                    'paid' => round((float) $row['paid'], 2),
                    'balance' => round((float) $row['balance'], 2),
                ])
                ->all(),
            'source' => 'DashboardSummaryService receivablesMonitoring(). Customer names are omitted from AI payload.',
        ];
    }

    /**
     * @param  array{0: ?string, 1: ?string}  $dateRange
     * @return array<string, mixed>
     */
    private function inventoryVarianceData(array $dateRange, int $limit): array
    {
        $variance = $this->summary->inventoryVarianceMonitoring([
            'date_from' => $dateRange[0],
            'date_to' => $dateRange[1],
        ], $limit);

        return [
            'quantity_unit' => 'liters',
            'summary' => [
                'transactions_checked' => (int) $variance['summary']['total_checked'],
                'matched_transactions' => (int) $variance['summary']['matched_count'],
                'variance_count' => (int) $variance['summary']['variance_count'],
                'variance_rate_percent' => round((float) $variance['summary']['variance_rate'], 1),
                'quantity_difference_liters' => round((float) $variance['summary']['quantity_variance_liters'], 2),
            ],
            'reason_breakdown' => collect($variance['reasonBreakdown'])
                ->map(fn (array $row): array => [
                    'reason' => $this->sanitizeText((string) $row['label']),
                    'count' => (int) $row['count'],
                ])
                ->all(),
            'sample_variances' => collect($variance['rows'])
                ->map(fn (array $row): array => [
                    'transaction_reference' => $this->sanitizeText((string) $row['transaction_reference']),
                    'transaction_date' => $row['transaction_date'],
                    'fuel_type' => $this->sanitizeText((string) $row['fuel_name']),
                    'sale_quantity_liters' => round((float) $row['sale_quantity_liters'], 2),
                    'stock_out_quantity_liters' => round((float) $row['stock_out_quantity_liters'], 2),
                    'quantity_variance_liters' => round((float) $row['quantity_variance_liters'], 2),
                    'sale_amount' => round((float) $row['sale_amount'], 2),
                    'paid_amount' => round((float) $row['total_paid'], 2),
                    'outstanding_amount' => round((float) $row['outstanding_amount'], 2),
                    'reason' => $this->sanitizeText((string) $row['reason']),
                ])
                ->all(),
            'source' => 'DashboardSummaryService inventoryVarianceMonitoring().',
        ];
    }

    /**
     * @param  array{0: ?string, 1: ?string}  $dateRange
     */
    private function periodSaleTotalsQuery(array $dateRange): Builder
    {
        return DB::query()
            ->fromSub($this->summary->saleTotalsQuery(), 'sale_totals')
            ->join('sales', 'sales.id', '=', 'sale_totals.sale_id')
            ->when($dateRange[0], fn (Builder $query, string $date): Builder => $query->where('sales.sale_date', '>=', $date))
            ->when($dateRange[1], fn (Builder $query, string $date): Builder => $query->where('sales.sale_date', '<=', $date))
            ->select('sale_totals.sale_id', 'sale_totals.total');
    }

    /**
     * @param  array{0: ?string, 1: ?string}  $dateRange
     */
    private function periodCollectedRevenue(array $dateRange): float
    {
        return (float) DB::table('payments')
            ->join('sales', 'sales.id', '=', 'payments.sale_id')
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', DashboardSummaryService::VALID_SALE_STATUSES)
            ->when($dateRange[0], fn (Builder $query, string $date): Builder => $query->where('payments.payment_date', '>=', $date))
            ->when($dateRange[1], fn (Builder $query, string $date): Builder => $query->where('payments.payment_date', '<=', $date))
            ->selectRaw('COALESCE(SUM(payments.amount), 0) as total')
            ->value('total');
    }

    /**
     * @param  array{0: ?string, 1: ?string}  $dateRange
     */
    private function periodOutstandingReceivables(array $dateRange): float
    {
        return (float) DB::query()
            ->fromSub($this->periodSaleTotalsQuery($dateRange), 'sale_totals')
            ->leftJoinSub($this->summary->paymentTotalsQuery(), 'payment_totals', 'payment_totals.sale_id', '=', 'sale_totals.sale_id')
            ->selectRaw('COALESCE(SUM(CASE WHEN sale_totals.total > COALESCE(payment_totals.paid, 0) THEN sale_totals.total - COALESCE(payment_totals.paid, 0) ELSE 0 END), 0) as total')
            ->value('total');
    }

    /**
     * @return array<int, float>
     */
    private function quantitySoldSeries(string $period, int $year): array
    {
        return match ($period) {
            'week' => $this->weeklyQuantitySold(),
            'year' => $this->yearlyQuantitySold($year),
            default => $this->monthlyQuantitySold($year),
        };
    }

    /**
     * @return array<int, float>
     */
    private function weeklyQuantitySold(): array
    {
        $start = CarbonImmutable::now()->startOfWeek();
        $end = $start->endOfWeek();
        $labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
        $totals = $this->validSaleItemsQuery()
            ->whereBetween('sales.sale_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('sales.sale_date as sale_date, COALESCE(SUM(sale_items.quantity_liters), 0) as total')
            ->groupBy('sales.sale_date')
            ->get()
            ->mapWithKeys(fn (object $row): array => [CarbonImmutable::parse($row->sale_date)->format('D') => round((float) $row->total, 2)]);

        return array_map(fn (string $label): float => (float) ($totals[$label] ?? 0), $labels);
    }

    /**
     * @return array<int, float>
     */
    private function monthlyQuantitySold(int $year): array
    {
        $totals = $this->validSaleItemsQuery()
            ->whereBetween('sales.sale_date', [$year.'-01-01', $year.'-12-31'])
            ->selectRaw('CAST(SUBSTR(sales.sale_date, 6, 2) AS INTEGER) as month_number, COALESCE(SUM(sale_items.quantity_liters), 0) as total')
            ->groupByRaw('CAST(SUBSTR(sales.sale_date, 6, 2) AS INTEGER)')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->month_number => round((float) $row->total, 2)]);

        return array_map(fn (int $month): float => (float) ($totals[$month] ?? 0), range(1, 12));
    }

    /**
     * @return array<int, float>
     */
    private function yearlyQuantitySold(int $endYear): array
    {
        $years = range($endYear - 4, $endYear);
        $totals = $this->validSaleItemsQuery()
            ->whereBetween('sales.sale_date', [$years[0].'-01-01', $endYear.'-12-31'])
            ->selectRaw('CAST(SUBSTR(sales.sale_date, 1, 4) AS INTEGER) as sale_year, COALESCE(SUM(sale_items.quantity_liters), 0) as total')
            ->groupByRaw('CAST(SUBSTR(sales.sale_date, 1, 4) AS INTEGER)')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->sale_year => round((float) $row->total, 2)]);

        return array_map(fn (int $year): float => (float) ($totals[$year] ?? 0), $years);
    }

    /**
     * @param  array{0: ?string, 1: ?string}  $dateRange
     * @return array<int, array<string, mixed>>
     */
    private function salesFuelBreakdown(array $dateRange): array
    {
        return $this->validSaleItemsQuery()
            ->join('fuel_types', 'fuel_types.id', '=', 'sale_items.fuel_type_id')
            ->when($dateRange[0], fn (Builder $query, string $date): Builder => $query->where('sales.sale_date', '>=', $date))
            ->when($dateRange[1], fn (Builder $query, string $date): Builder => $query->where('sales.sale_date', '<=', $date))
            ->select([
                'fuel_types.id as fuel_type_id',
                'fuel_types.name as fuel_name',
            ])
            ->selectRaw('COALESCE(SUM(sale_items.quantity_liters), 0) as quantity_liters')
            ->selectRaw('COALESCE(SUM(sale_items.line_total), 0) as sales_total')
            ->groupBy('fuel_types.id', 'fuel_types.name')
            ->orderBy('fuel_types.name')
            ->limit(10)
            ->get()
            ->map(fn (object $row): array => [
                'fuel_type_id' => (int) $row->fuel_type_id,
                'fuel_type' => $this->sanitizeText((string) $row->fuel_name),
                'quantity_liters' => round((float) $row->quantity_liters, 2),
                'sales_total' => round((float) $row->sales_total, 2),
            ])
            ->all();
    }

    private function validSaleItemsQuery(): Builder
    {
        return DB::table('sales')
            ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', DashboardSummaryService::VALID_SALE_STATUSES);
    }

    /**
     * @param  array{0: ?string, 1: ?string}  $dateRange
     * @return array<int, array<string, mixed>>
     */
    private function inventoryMovementSummary(array $dateRange): array
    {
        return DB::table('inventory_movements')
            ->when($dateRange[0], fn (Builder $query, string $date): Builder => $query->where('movement_date', '>=', $date))
            ->when($dateRange[1], fn (Builder $query, string $date): Builder => $query->where('movement_date', '<=', $date.' 23:59:59'))
            ->select('direction')
            ->selectRaw('COUNT(*) as movement_count, COALESCE(SUM(quantity_liters), 0) as quantity_liters')
            ->groupBy('direction')
            ->orderBy('direction')
            ->get()
            ->map(fn (object $row): array => [
                'direction' => $this->sanitizeText((string) $row->direction),
                'movement_count' => (int) $row->movement_count,
                'quantity_liters' => round((float) $row->quantity_liters, 2),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function receivableStatusBreakdown(): array
    {
        return DB::query()
            ->fromSub($this->summary->saleTotalsQuery(), 'sale_totals')
            ->join('sales', 'sales.id', '=', 'sale_totals.sale_id')
            ->leftJoin('receivables', 'receivables.sale_id', '=', 'sales.id')
            ->leftJoinSub($this->summary->paymentTotalsQuery(), 'payment_totals', 'payment_totals.sale_id', '=', 'sale_totals.sale_id')
            ->selectRaw("COALESCE(receivables.status, 'missing') as receivable_status")
            ->selectRaw('COUNT(*) as sales_count')
            ->selectRaw('COALESCE(SUM(sale_totals.total), 0) as sale_total')
            ->selectRaw('COALESCE(SUM(payment_totals.paid), 0) as paid')
            ->selectRaw('COALESCE(SUM(CASE WHEN sale_totals.total > COALESCE(payment_totals.paid, 0) THEN sale_totals.total - COALESCE(payment_totals.paid, 0) ELSE 0 END), 0) as balance')
            ->groupByRaw("COALESCE(receivables.status, 'missing')")
            ->orderByRaw("COALESCE(receivables.status, 'missing')")
            ->get()
            ->map(fn (object $row): array => [
                'status' => $this->sanitizeText((string) $row->receivable_status),
                'sales_count' => (int) $row->sales_count,
                'sale_total' => round((float) $row->sale_total, 2),
                'paid' => round((float) $row->paid, 2),
                'balance' => round((float) $row->balance, 2),
            ])
            ->all();
    }

    private function sanitizeText(string $value): string
    {
        $value = strip_tags($value);
        $value = preg_replace('/[^\P{C}\t\n\r]+/u', '', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';

        return mb_substr(trim($value), 0, 120);
    }
}
