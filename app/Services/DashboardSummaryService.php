<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class DashboardSummaryService
{
    public const VALID_SALE_STATUSES = ['confirmed', 'partially_paid', 'paid', 'unpaid'];
    private const ACTIVE_DELIVERY_STATUSES = ['scheduled', 'in_transit', 'incomplete'];

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

    public function totalInventoryLiters(): float
    {
        return (float) DB::table('inventory_movements')
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN quantity_liters WHEN direction = 'out' THEN -quantity_liters ELSE 0 END), 0) as total")
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
            ->whereDate('movement_date', $date->toDateString())
            ->selectRaw('COALESCE(SUM(quantity_liters), 0) as total')
            ->value('total');
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
