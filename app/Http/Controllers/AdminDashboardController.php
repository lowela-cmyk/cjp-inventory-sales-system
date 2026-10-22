<?php

namespace App\Http\Controllers;

use App\Rules\ApprovedFuelType;
use App\Services\DashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    /** Sales Trend "Date" dropdown (mockup): each option is a date range ending today or within its period. */
    public const SALES_PERIODS = [
        'today' => 'Today',
        'this_week' => 'This Week',
        'this_month' => 'This Month',
        'last_month' => 'Last Month',
        'this_year' => 'This Year',
        'last_year' => 'Last Year',
    ];

    public function __invoke(Request $request, DashboardService $dashboard): View
    {
        $filters = self::validatedFilters($request);

        return view('admin.dashboard', $dashboard->data(self::inventoryFilters($filters), self::salesFilters($filters)) + [
            'filterQuery' => array_filter($filters, fn (mixed $value): bool => $value !== null && $value !== ''),
        ]);
    }

    /**
     * Part 10 filters: Current Inventory (inv_*) and Sales Trend (sales_*). Shared with the insight report.
     *
     * @return array<string, mixed>
     */
    public static function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'inv_search' => ['nullable', 'string', 'max:60'],
            'inv_fuel_type_id' => ['nullable', 'integer', ApprovedFuelType::rule()],
            'inv_depot_id' => ['nullable', 'integer', Rule::exists('depots', 'id')],
            'sales_search' => ['nullable', 'string', 'max:60'],
            'sales_period' => ['nullable', Rule::in(array_keys(self::SALES_PERIODS))],
            'sales_date_from' => ['nullable', 'date'],
            'sales_date_to' => ['nullable', 'date', 'after_or_equal:sales_date_from'],
            'sales_fuel_type_id' => ['nullable', 'integer', ApprovedFuelType::rule()],
            'sales_customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')],
        ], [], [
            'inv_search' => 'purchase ID search',
            'inv_fuel_type_id' => 'fuel type',
            'inv_depot_id' => 'depot',
            'sales_search' => 'sales ID search',
            'sales_period' => 'date',
            'sales_date_from' => 'date from',
            'sales_date_to' => 'date to',
            'sales_fuel_type_id' => 'fuel type',
            'sales_customer_id' => 'customer',
        ]);

        return collect(['inv_search', 'inv_fuel_type_id', 'inv_depot_id', 'sales_search', 'sales_period', 'sales_date_from', 'sales_date_to', 'sales_fuel_type_id', 'sales_customer_id'])
            ->mapWithKeys(fn (string $key): array => [$key => $validated[$key] ?? null])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public static function inventoryFilters(array $filters): array
    {
        return ['search' => $filters['inv_search'], 'fuel_type_id' => $filters['inv_fuel_type_id'], 'depot_id' => $filters['inv_depot_id']];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public static function salesFilters(array $filters): array
    {
        [$from, $to] = $filters['sales_period']
            ? self::periodRange((string) $filters['sales_period'])
            : [$filters['sales_date_from'], $filters['sales_date_to']];

        return [
            'search' => $filters['sales_search'],
            'date_from' => $from,
            'date_to' => $to,
            'fuel_type_id' => $filters['sales_fuel_type_id'],
            'customer_id' => $filters['sales_customer_id'],
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function periodRange(string $period): array
    {
        $today = CarbonImmutable::today();
        [$from, $to] = match ($period) {
            'today' => [$today, $today],
            'this_week' => [$today->startOfWeek(CarbonImmutable::MONDAY), $today->endOfWeek(CarbonImmutable::SUNDAY)],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()],
            'this_year' => [$today->startOfYear(), $today->endOfYear()],
            'last_year' => [$today->subYearNoOverflow()->startOfYear(), $today->subYearNoOverflow()->endOfYear()],
            default => [$today->startOfMonth(), $today->endOfMonth()],
        };

        return [$from->toDateString(), $to->toDateString()];
    }
}
