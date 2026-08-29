<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AdminDashboardService
{
    private const VALID_SALE_STATUSES = ['confirmed', 'partially_paid', 'paid', 'unpaid'];

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $totalInventoryLiters = $this->totalInventoryLiters();
        $totalSalesRevenue = $this->totalSalesRevenue();
        $collectedRevenue = $this->collectedRevenue();
        $outstandingBalance = $totalSalesRevenue - $collectedRevenue;

        return [
            'metricCards' => [
                ['Total Inventory (KL)', $this->formatKiloliters($totalInventoryLiters), 'Across all depots', ''],
                ['Total Sales Revenue', $this->formatMoney($totalSalesRevenue), 'Cumulative', ''],
                ['Outstanding Balance', $this->formatMoney($outstandingBalance), 'Receivables', 'color:#a31318'],
                ['Unlifted Fuel (KL)', $this->formatKiloliters($this->unliftedFuelLiters()), 'Pending lifting', ''],
                ['Active Deliveries', number_format($this->activeDeliveries()), 'Scheduled / in transit', ''],
            ],
            'salesTrend' => $this->weeklySalesTrend(),
            'stockByFuelType' => $this->stockByFuelType(),
            'revenueBars' => $this->revenueBars($totalSalesRevenue, $collectedRevenue, $outstandingBalance),
            'demandDays' => $this->demandByDay(),
            'demandMonths' => $this->demandByMonth(),
            'hasRevenueProjection' => false,
        ];
    }

    private function totalInventoryLiters(): float
    {
        return (float) DB::table('inventory_movements')
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN quantity_liters ELSE -quantity_liters END), 0) as total")
            ->value('total');
    }

    private function totalSalesRevenue(): float
    {
        return (float) DB::query()
            ->fromSub($this->saleTotalsQuery(), 'sale_totals')
            ->selectRaw('COALESCE(SUM(total), 0) as total')
            ->value('total');
    }

    private function collectedRevenue(): float
    {
        return (float) DB::query()
            ->fromSub($this->paymentTotalsQuery(), 'payment_totals')
            ->selectRaw('COALESCE(SUM(paid), 0) as total')
            ->value('total');
    }

    private function unliftedFuelLiters(): float
    {
        return (float) DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->whereNull('purchases.deleted_at')
            ->where('purchases.status', '!=', 'cancelled')
            ->selectRaw('COALESCE(SUM(quantity_ordered_liters - quantity_hauled_liters), 0) as total')
            ->value('total');
    }

    private function activeDeliveries(): int
    {
        return DB::table('deliveries')
            ->whereIn('status', ['scheduled', 'in_transit', 'incomplete'])
            ->count();
    }

    /**
     * @return array<int, array{label: string, value: string, height: int}>
     */
    private function weeklySalesTrend(): array
    {
        $start = CarbonImmutable::now()->startOfWeek();
        $end = $start->endOfWeek();

        $totals = DB::table('sales')
            ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', self::VALID_SALE_STATUSES)
            ->whereBetween('sales.sale_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('sales.sale_date as sale_date, COALESCE(SUM(sale_items.line_total), 0) as total')
            ->groupBy('sales.sale_date')
            ->get()
            ->mapWithKeys(fn (object $row): array => [CarbonImmutable::parse($row->sale_date)->format('D') => (float) $row->total]);

        $max = max(1, (float) $totals->max());

        return collect(['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'])
            ->map(function (string $day) use ($totals, $max): array {
                $value = (float) ($totals[$day] ?? 0);

                return [
                    'label' => $day,
                    'value' => $this->formatMoney($value),
                    'height' => $value === 0.0 ? 6 : max(6, (int) round(($value / $max) * 96)),
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array{label: string, value: string, height: int, color: string}>
     */
    private function stockByFuelType(): array
    {
        $colors = ['#f7043a', '#3b9a35', '#e28a22', '#0d1424', '#6b7280'];

        $rows = DB::table('fuel_types')
            ->leftJoin('inventory_movements', 'inventory_movements.fuel_type_id', '=', 'fuel_types.id')
            ->where('fuel_types.status', 'active')
            ->selectRaw("fuel_types.name, COALESCE(SUM(CASE WHEN inventory_movements.direction = 'in' THEN inventory_movements.quantity_liters WHEN inventory_movements.direction = 'out' THEN -inventory_movements.quantity_liters ELSE 0 END), 0) as liters")
            ->groupBy('fuel_types.id', 'fuel_types.name')
            ->orderBy('fuel_types.name')
            ->get();

        if ($rows->isEmpty()) {
            $rows = collect([
                (object) ['name' => 'Premium', 'liters' => 0],
                (object) ['name' => 'Diesel', 'liters' => 0],
                (object) ['name' => 'Unleaded', 'liters' => 0],
            ]);
        }

        $max = max(1, ...$rows->map(fn (object $row): float => abs((float) $row->liters))->all());

        return $rows
            ->values()
            ->map(function (object $row, int $index) use ($colors, $max): array {
                $liters = (float) $row->liters;

                return [
                    'label' => $row->name,
                    'value' => $this->formatLiters($liters),
                    'height' => $liters === 0.0 ? 2 : max(2, (int) round((abs($liters) / $max) * 100)),
                    'color' => $colors[$index % count($colors)],
                ];
            })
            ->all();
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
            ->whereIn('sales.status', self::VALID_SALE_STATUSES)
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
        return DB::table('sales')
            ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', self::VALID_SALE_STATUSES)
            ->selectRaw('sales.id as sale_id, COALESCE(SUM(sale_items.line_total), 0) as total')
            ->groupBy('sales.id');
    }

    private function paymentTotalsQuery()
    {
        return DB::table('payments')
            ->join('sales', 'sales.id', '=', 'payments.sale_id')
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', self::VALID_SALE_STATUSES)
            ->selectRaw('payments.sale_id, COALESCE(SUM(payments.amount), 0) as paid')
            ->groupBy('payments.sale_id');
    }

    private function formatMoney(float $value, bool $withSpace = true): string
    {
        return 'PHP'.($withSpace ? ' ' : '').number_format($value, 0);
    }

    private function formatLiters(float $value): string
    {
        return number_format($value, 0).' L';
    }

    private function formatKiloliters(float $liters): string
    {
        return number_format($liters / 1000, 0).' KL';
    }
}
