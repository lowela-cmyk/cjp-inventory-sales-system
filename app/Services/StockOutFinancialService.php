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
            // Part 2 revisions: the purchase a release was deducted from, and the delivery details: the lift (Depot to
            // Client) or the order's own truck, driver and date (Standard).
            ->leftJoin('purchase_items as release_purchase_item', 'release_purchase_item.id', '=', 'stock_outs.purchase_item_id')
            ->leftJoin('purchases as release_purchase', 'release_purchase.id', '=', 'release_purchase_item.purchase_id')
            ->leftJoin('lifting_schedules', 'lifting_schedules.id', '=', 'hauls.lifting_schedule_id')
            ->leftJoin('trucks as delivery_truck', 'delivery_truck.id', '=', DB::raw('COALESCE(hauls.truck_id, sales.delivery_truck_id)'))
            ->leftJoin('users as delivery_driver', 'delivery_driver.id', '=', DB::raw('COALESCE(hauls.driver_user_id, sales.delivery_driver_user_id)'))
            ->leftJoin('purchase_items as source_purchase_item', 'source_purchase_item.id', '=', 'hauls.purchase_item_id')
            // Where the fuel physically left from: a garage tank, or the depot of a direct release.
            ->leftJoin('storage_locations as source_garage', 'source_garage.id', '=', 'stock_outs.storage_location_id')
            // The stock lot a sale line was sold from (Part 8 #8): its purchase code names the source. The lot covers
            // releases of the line's own fuel only; the F1 portion of a line (Part 9) is a pooled F1 release.
            ->leftJoin('inventory_movements as lot_movement', function ($join): void {
                $join->on('lot_movement.id', '=', 'sale_items.stock_in_movement_id')
                    ->whereColumn('stock_outs.fuel_type_id', 'sale_items.fuel_type_id');
            })
            ->leftJoin('haul_allocations as lot_allocation', function ($join): void {
                $join->on('lot_allocation.id', '=', 'lot_movement.reference_id')
                    ->where('lot_movement.reference_type', '=', 'haul_allocation');
            })
            ->leftJoin('hauls as lot_haul', 'lot_haul.id', '=', 'lot_allocation.haul_id')
            ->leftJoin('purchases as lot_purchase', 'lot_purchase.id', '=', 'lot_haul.purchase_id')
            ->leftJoin('depots as source_depot', 'source_depot.id', '=', DB::raw('COALESCE(stock_outs.depot_id, hauls.depot_id)'))
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
                    ->orWhere('source_garage.name', 'like', $term)
                    ->orWhere('source_depot.name', 'like', $term)
                    ->orWhere('release_purchase.purchase_code', 'like', $term)
                    ->orWhere('stock_outs.status', 'like', $term);
            }))
            ->select([
                'stock_outs.id',
                'stock_outs.stock_out_code',
                'stock_outs.stock_out_at',
                'stock_outs.quantity_liters',
                'stock_outs.status',
                'stock_outs.source_type',
                'source_garage.name as garage_name',
                'lot_purchase.purchase_code as lot_code',
                'lot_movement.id as lot_id',
                'source_depot.id as source_depot_id',
                'source_depot.name as depot_name',
                'sales.sale_code',
                'customers.name as customer_name',
                'customers.company_name',
                'fuel_types.name as fuel_name',
                // The F1 portion has its own price (lines recorded before October 22, 2026 billed it at the line price).
                DB::raw("CASE WHEN stock_outs.portion = 'f1' AND sale_items.f1_unit_price > 0 THEN sale_items.f1_unit_price ELSE sale_items.unit_price END as unit_price"),
                'stock_outs.portion',
                'sales.delivery_type',
                'sales.delivery_status',
                'release_purchase.purchase_code as release_purchase_code',
                'delivery_truck.truck_code as delivery_truck_code',
                'delivery_truck.plate_number as delivery_truck_plate',
                'delivery_driver.name as delivery_driver_name',
                DB::raw('COALESCE(hauls.hauled_at, hauls.scheduled_at, sales.delivery_date, stock_outs.stock_out_at) as delivery_date'),
                'hauls.atl_number',
                DB::raw('COALESCE(hauls.dr_number, lifting_schedules.dr_number) as dr_number'),
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
            // Margin is the expected profit; realized profit only counts what has been paid so far.
            'margin' => $totalCost === null ? null : round($totalPrice - $totalCost, 2),
            'profit' => $totalCost === null ? null : round($allocatedPaid - $totalCost, 2),
        ];
    }
}
