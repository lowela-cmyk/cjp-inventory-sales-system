<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class StockOutFinancialService
{
    public function query(?string $search = null): Builder
    {
        $payments = DB::table('payments')
            ->selectRaw('sale_id, COALESCE(SUM(amount), 0) as total_paid')
            ->groupBy('sale_id');

        $saleTotals = DB::table('sale_items')
            ->selectRaw('sale_id, COALESCE(SUM(line_total), 0) as sale_total')
            ->groupBy('sale_id');

        return DB::table('stock_outs')
            ->join('sales', 'sales.id', '=', 'stock_outs.sale_id')
            ->join('customers', 'customers.id', '=', 'stock_outs.customer_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'stock_outs.fuel_type_id')
            ->leftJoin('sale_items', 'sale_items.id', '=', 'stock_outs.sale_item_id')
            ->leftJoin('inventory_movements as stock_out_movement', 'stock_out_movement.id', '=', 'stock_outs.inventory_movement_id')
            ->leftJoin('haul_allocations', 'haul_allocations.id', '=', 'stock_outs.haul_allocation_id')
            ->leftJoin('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
            ->leftJoin('purchase_items as source_purchase_item', 'source_purchase_item.id', '=', 'hauls.purchase_item_id')
            ->leftJoinSub($payments, 'payments_total', 'payments_total.sale_id', '=', 'sales.id')
            ->leftJoinSub($saleTotals, 'sale_totals', 'sale_totals.sale_id', '=', 'sales.id')
            ->whereNull('sales.deleted_at')
            ->when($search, fn (Builder $query): Builder => $query->where(function (Builder $query) use ($search): void {
                $term = '%'.$search.'%';
                $query->where('stock_outs.stock_out_code', 'like', $term)
                    ->orWhere('sales.sale_code', 'like', $term)
                    ->orWhere('sales.sales_order_number', 'like', $term)
                    ->orWhere('customers.name', 'like', $term)
                    ->orWhere('customers.company_name', 'like', $term)
                    ->orWhere('fuel_types.name', 'like', $term)
                    ->orWhere('stock_outs.status', 'like', $term);
            }))
            ->select([
                'stock_outs.id',
                'stock_outs.stock_out_code',
                'stock_outs.stock_out_at',
                'stock_outs.quantity_liters',
                'stock_outs.status',
                'stock_outs.source_type',
                'sales.sale_code',
                'customers.name as customer_name',
                'customers.company_name',
                'fuel_types.name as fuel_name',
                'sale_items.unit_price',
                DB::raw('COALESCE(stock_outs.unit_cost, stock_out_movement.unit_cost, source_purchase_item.unit_cost) as cost_unit'),
                DB::raw('COALESCE(payments_total.total_paid, 0) as sale_paid'),
                DB::raw('COALESCE(sale_totals.sale_total, 0) as sale_total'),
            ]);
    }

    /**
     * Allocate sale-level payments to this release by its share of the sale value.
     * This keeps the sum of displayed paid amounts from duplicating a payment when
     * a sale has multiple items or releases.
     *
     * @return array<string, float|null>
     */
    public function calculate(object $row): array
    {
        $quantity = round((float) $row->quantity_liters, 2);
        $unitPrice = round((float) ($row->unit_price ?? 0), 2);
        $totalPrice = round($quantity * $unitPrice, 2);
        $saleTotal = round((float) ($row->sale_total ?? 0), 2);
        $salePaid = round((float) ($row->sale_paid ?? 0), 2);
        $allocatedPaid = $saleTotal > 0 ? round($salePaid * ($totalPrice / $saleTotal), 2) : 0.0;
        $unitCost = $row->cost_unit === null ? null : round((float) $row->cost_unit, 2);
        $totalCost = $unitCost === null ? null : round($quantity * $unitCost, 2);

        return [
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'total_cost' => $totalCost,
            'unit_price' => $unitPrice,
            'total_price' => $totalPrice,
            'total_paid' => $allocatedPaid,
            'profit' => $totalCost === null ? null : round($allocatedPaid - $totalCost, 2),
        ];
    }
}
