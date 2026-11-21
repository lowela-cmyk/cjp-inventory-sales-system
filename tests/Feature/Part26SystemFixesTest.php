<?php

namespace Tests\Feature;

use App\Services\PurchaseStockService;
use App\Services\StockInService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\PurchaseFlows;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Part 26 (November 21, 2026): complete system fixes.
 * 1. Dashboard: the Inventory Reconciliation and Purchase Reports charts are removed.
 * 2-4. A purchase stays sellable as Depot to Client while its fuel is in the depot or still unclaimed on a truck; the
 *    order then rides that truck (Deliver from Truck) or a lift; fuel in a tank, delivered or promised is never sold
 *    twice, and Stock-In drops only on delivery (PUR-DSL-002: 100,000 L, 40,000 L sold and delivered = 60,000 L).
 * 5. Withdrawal receipts: image or PDF, previewed before upload.
 * 6. Stock Out: the Source column wraps on several lines.
 */
class Part26SystemFixesTest extends AuditTestCase
{
    use PurchaseFlows;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-21 09:00:00');
    }

    // ----- helpers ---------------------------------------------------------------------------------------------

    /** @param  array<string, mixed>  $a */
    private function purchase(array $a, string $code, float $liters, string $fuel = 'DSL'): int
    {
        $line = $this->purchaseLine($a, $fuel, $liters);
        DB::table('purchases')->where('id', DB::table('purchase_items')->where('id', $line)->value('purchase_id'))->update(['purchase_code' => $code]);

        return $line;
    }

    private function truck(string $code, float $capacity): int
    {
        return (int) DB::table('trucks')->insertGetId(['truck_code' => $code, 'name' => 'Truck '.$code, 'capacity_liters' => $capacity, 'truck_type' => 'hauling', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param  array<string, mixed>  $a */
    private function sale(array $a, int $purchaseItemId, float $liters, string $type = 'depot_to_client')
    {
        return $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->post(route('sales-officer.sales.store'), [
            'idempotency_key' => (string) Str::uuid(), 'form_context' => 'record-sale', 'delivery_type' => $type,
            'customer_id' => $a['customer'], 'sale_date' => '2026-11-21', 'items' => [['purchase_item_id' => $purchaseItemId, 'quantity_liters' => $liters, 'unit_price' => 62]],
            'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment',
        ]);
    }

    /**
     * Lifts the liters of a purchase onto a truck (no orders) and marks the lift Lifted.
     *
     * @param  array<string, mixed>  $a
     */
    private function liftOnto(array $a, int $truck, int $purchaseItemId, float $liters): int
    {
        $this->scheduleLift($a, [['purchase_item_id' => $purchaseItemId, 'quantity_liters' => $liters]], [], ['truck_id' => $truck])->assertSessionHasNoErrors();
        $haul = $this->lastHaulId();
        $this->completeLift($a, $haul);

        return $haul;
    }

    /** @param  array<string, mixed>  $a */
    private function deliverFromTruck(array $a, int $haul, int $saleId)
    {
        return $this->actingAs($a['dispatch'])->from(route('dispatch.fuel-lifting'))->post(route('dispatch.fuel-lifting.hauls.truck-deliveries.store', $haul), ['sale_id' => $saleId]);
    }

    /** @param  array<string, mixed>  $a */
    private function unload(array $a, int $haul, float $liters, string $fuel = 'DSL')
    {
        // A garage tank holds at most 24,000 L (Part 23 #4).
        $tank = (int) DB::table('storage_locations')->where('type', 'garage')->where('status', 'active')->where('fuel_type_id', $this->fuel($fuel))->where('tank_number', 1)->value('id');

        return $this->actingAs($a['dispatch'])->from(route('dispatch.fuel-lifting'))->post(route('dispatch.fuel-lifting.hauls.unload-to-garage', $haul), ['unload' => [$haul => [$tank => $liters]]]);
    }

    private function line(int $purchaseItemId): object
    {
        return app(PurchaseStockService::class)->line($purchaseItemId);
    }

    private function stockInRemaining(int $purchaseItemId): float
    {
        return (float) app(StockInService::class)->records()->firstWhere('purchase_item_id', $purchaseItemId)->remaining;
    }

    // ----- 2-4. The PUR-DSL-002 example: lifted fuel stays sellable until delivered or in a tank -----------------------

    public function test_a_lifted_purchase_stays_sellable_as_depot_to_client_and_stock_in_drops_only_on_delivery(): void
    {
        $a = $this->actors();
        $truck = $this->truck('TRK-100K', 100000);
        $line = $this->purchase($a, 'PUR-DSL-002', 100000);

        // The whole purchase is lifted onto a truck: nothing is left in the depot.
        $haul = $this->liftOnto($a, $truck, $line, 100000);
        $stock = $this->line($line);
        $this->assertSame([0.0, 0.0, 100000.0, 100000.0, 100000.0], [$stock->remaining_in_depot, $stock->available, $stock->truck_free, $stock->depot_to_client_available, $stock->on_truck]);
        $this->assertFalse($stock->requires_reconciliation);

        // Sales still lists PUR-DSL-002 under its fuel type for Depot to Client (not for Standard: depot liters only).
        $html = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="'.$line.'" data-source="depot"[^>]*data-purchase-code="PUR-DSL-002"[^>]*data-available-standard="0"[^>]*data-available-d2c="100000"[^>]*data-label-d2c="PUR-DSL-002 – DIESEL – 100,000 L available \(lifted fuel on trucks included\) – ₱50.00\/L"/s', $html);
        $this->sale($a, $line, 1, 'standard')->assertSessionHasErrors('items.0.quantity_liters');

        // 40,000 L Depot to Client sold from it.
        $this->sale($a, $line, 40000)->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $this->assertSame($line, (int) DB::table('sale_items')->where('sale_id', $saleId)->value('purchase_item_id'));
        $this->assertSame([40000.0, 60000.0, 100000.0], [$this->line($line)->reserved, $this->line($line)->depot_to_client_available, $this->stockInRemaining($line)]);

        // Fuel Lifting: the lift shows the purchase's figures and offers the sale to deliver from the truck.
        $page = $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting'))->assertOk();
        $row = $page->viewData('scheduledRows')->first();
        $this->assertSame(['Purchased' => '100,000.00 L', 'Reserved (open sales)' => '40,000.00 L', 'Lifted' => '100,000.00 L', 'Delivered' => '0.00 L', 'Remaining in depot' => '0.00 L', 'Unclaimed on trucks' => '100,000.00 L', 'Sellable (Depot to Client)' => '60,000.00 L'], $row['hauls'][0]['purchase_figures']);
        $this->assertTrue($row['truck_order_options']->contains('sale_id', $saleId));
        $page->assertSee('data-lift-purchase-figures="PUR-DSL-002"', false);

        // The sold 40,000 L cannot also go to the garage: only the other 60,000 L may be unloaded.
        $this->assertSame([$haul => 60000.0], app(PurchaseStockService::class)->unloadableByHaul([$line]));
        $this->assertSame(0, DB::table('garage_stocks')->count());

        // Delivered from the truck: Stock-In becomes 60,000 L, deducted once.
        $this->deliverFromTruck($a, $haul, $saleId)->assertSessionHasNoErrors();
        $this->assertSame('delivered', DB::table('sales')->where('id', $saleId)->value('delivery_status'));
        $this->assertSame(60000.0, $this->stockInRemaining($line));
        $stock = $this->line($line);
        $this->assertSame([0.0, 40000.0, 60000.0, 60000.0], [$stock->reserved, $stock->released, $stock->on_truck, $stock->depot_to_client_available]);
        $this->assertFalse($stock->requires_reconciliation);
        $this->assertSame(40000.0, (float) DB::table('stock_outs')->where('sale_id', $saleId)->where('status', 'released')->sum('quantity_liters'));
        // The same Purchase ID on the Stock Out row.
        $stockOut = $this->actingAs($a['admin'])->get(route('admin.inventory.stock-out'))->viewData('stockOut')->first();
        $this->assertSame('PUR-DSL-002', $stockOut['cells'][6]);

        // 24,000 L go into a garage tank: they are no longer sellable as Depot to Client (36,000 L on the truck still are).
        $this->unload($a, $haul, 24000)->assertSessionHasNoErrors();
        $stock = $this->line($line);
        $this->assertSame([36000.0, 36000.0, 24000.0, 60000.0], [$stock->on_truck, $stock->depot_to_client_available, $stock->in_garage, $this->stockInRemaining($line)]);
        $this->sale($a, $line, 36000.01)->assertSessionHasErrors('items.0.quantity_liters');
        $this->assertFalse($stock->requires_reconciliation);
    }

    public function test_truck_fuel_is_never_sold_twice(): void
    {
        $a = $this->actors();
        $truck = $this->truck('TRK-100K', 100000);
        $line = $this->purchase($a, 'PUR-DSL-010', 100000);
        $haul = $this->liftOnto($a, $truck, $line, 100000);

        $this->sale($a, $line, 60000)->assertSessionHasNoErrors();
        $first = $this->lastSaleId();
        $this->sale($a, $line, 40000.01)->assertSessionHasErrors('items.0.quantity_liters');
        $this->sale($a, $line, 40000)->assertSessionHasNoErrors();
        $second = $this->lastSaleId();
        $this->sale($a, $line, 1)->assertSessionHasErrors('items.0.quantity_liters');
        $this->unload($a, $haul, 1)->assertSessionHasErrors("unload.$haul");

        $this->deliverFromTruck($a, $haul, $first)->assertSessionHasNoErrors();
        $this->deliverFromTruck($a, $haul, $second)->assertSessionHasNoErrors();
        // Delivering the same order again is refused (the truck is empty and the order is no longer open).
        $this->deliverFromTruck($a, $haul, $second)->assertSessionHasErrors('lifting');
        $stock = $this->line($line);
        $this->assertSame([100000.0, 0.0, 0.0, 0.0], [$stock->released, $stock->on_truck, $stock->depot_to_client_available, $this->stockInRemaining($line)]);
        $this->assertSame(100000.0, (float) DB::table('stock_outs')->where('purchase_item_id', $line)->where('status', 'released')->sum('quantity_liters'));
        $this->assertSame('available', DB::table('trucks')->where('id', $truck)->value('status'));
    }

    public function test_an_order_must_fit_one_source_and_lifts_keep_the_liters_orders_need(): void
    {
        $a = $this->actors();
        $truck = $this->truck('TRK-100K', 100000);
        $line = $this->purchase($a, 'PUR-DSL-020', 100000);
        // 30,000 L on a truck, 70,000 L still in the depot.
        $haul = $this->liftOnto($a, $truck, $line, 30000);

        // An order is delivered whole from one place: at most 70,000 L (the depot), never 70,000 + 30,000 L at once.
        $this->assertSame(70000.0, $this->line($line)->depot_to_client_available);
        $this->sale($a, $line, 70000.01)->assertSessionHasErrors('items.0.quantity_liters');

        // A 30,000 L order fits the truck: a new lift may still take all 70,000 L of the depot.
        $this->sale($a, $line, 30000)->assertSessionHasNoErrors();
        $fitsTruck = $this->lastSaleId();
        $purchase = $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting'))->viewData('purchaseItems')->firstWhere('id', $line);
        $this->assertSame([70000.0, 0.0, 70000.0], [$purchase->remaining_liters, $purchase->reserved_for_sales, $purchase->free_liters]);
        $this->assertSame(70000.0, $this->line($line)->depot_to_client_available);

        // A 40,000 L order fits no truck: it keeps 40,000 L of the depot from a lift.
        $this->sale($a, $line, 40000)->assertSessionHasNoErrors();
        $this->scheduleLift($a, [['purchase_item_id' => $line, 'quantity_liters' => 30000.01]], [], ['truck_id' => $this->truck('TRK-B', 100000)])->assertSessionHasErrors('lift');
        $this->assertSame(30000.0, $this->line($line)->depot_to_client_available);

        // Both orders could still come from the 70,000 L in the depot, so the truck's 30,000 L may go to the garage, and a
        // new order may take at most the 30,000 L of one source (the truck, or the depot's free 30,000 L).
        $this->assertSame([$haul => 30000.0], app(PurchaseStockService::class)->unloadableByHaul([$line]));
        $this->assertSame(30000.0, $this->line($line)->depot_to_client_available);
        $this->deliverFromTruck($a, $haul, $fitsTruck)->assertSessionHasNoErrors();
        $stock = $this->line($line);
        $this->assertSame([30000.0, 40000.0, 0.0, 70000.0], [$stock->released, $stock->reserved, $stock->on_truck, $this->stockInRemaining($line)]);
        $this->assertFalse($stock->requires_reconciliation);
    }

    /** Purchased = in the depot + on trucks + in the garage + delivered; releases match; nothing negative or flagged. */
    private function assertReconciles(int $purchaseItemId, string $step): void
    {
        $stock = $this->line($purchaseItemId);
        $this->assertFalse($stock->requires_reconciliation, $step.': purchase flagged for reconciliation');
        $this->assertEqualsWithDelta($stock->ordered, $stock->remaining_in_depot + $stock->on_truck + $stock->in_garage + $stock->released, 0.005, $step.': liters do not add up');
        $released = (float) DB::table('stock_outs')->where('status', 'released')->where('purchase_item_id', $purchaseItemId)->sum('quantity_liters')
            + (float) DB::table('stock_out_draws')->join('stock_outs', 'stock_outs.id', '=', 'stock_out_draws.stock_out_id')->where('stock_outs.status', 'released')->where('stock_out_draws.purchase_item_id', $purchaseItemId)->sum('stock_out_draws.liters');
        $this->assertEqualsWithDelta($stock->released, $released, 0.005, $step.': delivered liters differ from the releases');
        $this->assertEqualsWithDelta($stock->ordered - $stock->released, $this->stockInRemaining($purchaseItemId), 0.005, $step.': Stock-In remaining');
        foreach (['available', 'depot_to_client_available', 'truck_free', 'reserved', 'on_truck', 'in_garage'] as $field) {
            $this->assertGreaterThanOrEqual(0, $stock->{$field}, $step.': '.$field.' is negative');
        }
        $this->assertLessThanOrEqual($stock->depot_to_client_pool + 0.005, $stock->depot_to_client_available, $step.': sellable above the pool');
    }

    public function test_purchase_depot_truck_garage_sales_and_stock_out_reconcile_through_edge_cases(): void
    {
        $a = $this->actors();
        $truck = $this->truck('TRK-100K', 100000);
        $line = $this->purchase($a, 'PUR-DSL-070', 100000);
        $this->assertReconciles($line, 'purchased');

        // 60,000 L lifted; 40,000 L stay in the depot.
        $haul = $this->liftOnto($a, $truck, $line, 60000);
        $this->assertReconciles($line, 'lifted');
        $this->assertSame([40000.0, 60000.0, 60000.0], [$this->line($line)->remaining_in_depot, $this->line($line)->truck_free, $this->line($line)->depot_to_client_available]);

        // A Depot to Client order from the truck, then cancelled: its liters are sellable again, nothing is deducted.
        $this->sale($a, $line, 50000)->assertSessionHasNoErrors();
        $cancelled = $this->lastSaleId();
        $this->assertSame(40000.0, $this->line($line)->depot_to_client_available);
        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.cancel', $cancelled), ['cancel_reason' => 'customer_cancelled'])->assertSessionHasNoErrors();
        $this->assertSame([0.0, 60000.0], [$this->line($line)->reserved, $this->line($line)->depot_to_client_available]);
        $this->assertSame(0, DB::table('stock_outs')->where('sale_id', $cancelled)->count());
        $this->deliverFromTruck($a, $haul, $cancelled)->assertSessionHasErrors('sale_id');
        $this->assertReconciles($line, 'cancelled order');

        // A Standard order is sold from depot liters only; it may still be delivered from a truck later, so the biggest
        // single source left for a Depot to Client order is the truck's 50,000 L.
        $this->sale($a, $line, 40000.01, 'standard')->assertSessionHasErrors('items.0.quantity_liters');
        $this->sale($a, $line, 10000, 'standard')->assertSessionHasNoErrors();
        $standard = $this->lastSaleId();
        $this->assertSame([30000.0, 50000.0], [$this->line($line)->available, $this->line($line)->depot_to_client_available]);

        // 24,000 L into the garage tank; a Garage to Client order sells 4,000 L of it and is delivered.
        $this->unload($a, $haul, 24000)->assertSessionHasNoErrors();
        $this->assertReconciles($line, 'unloaded');
        $this->assertSame([36000.0, 24000.0, 40000.0], [$this->line($line)->on_truck, $this->line($line)->in_garage, $this->line($line)->depot_to_client_available]);
        $tank = (int) DB::table('storage_locations')->where('type', 'garage')->where('status', 'active')->where('fuel_type_id', $this->fuel('DSL'))->where('tank_number', 1)->value('id');
        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->post(route('sales-officer.sales.store'), [
            'idempotency_key' => (string) Str::uuid(), 'form_context' => 'record-sale', 'delivery_type' => 'garage_to_client', 'customer_id' => $a['customer'], 'sale_date' => '2026-11-21',
            'items' => [['purchase_item_id' => 'tank:'.$tank, 'quantity_liters' => 4000, 'unit_price' => 70]], 'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment',
            'delivery_truck_id' => $a['truck'], 'delivery_driver_user_id' => $a['driver']->id, 'delivery_date' => '2026-11-22 10:00',
        ])->assertSessionHasNoErrors();
        $garage = $this->lastSaleId();
        // The tank's fuel is never offered to a Depot to Client order.
        $this->assertSame(40000.0, $this->line($line)->depot_to_client_available);
        $this->actingAs($a['admin'])->from(route('admin.sales'))->patch(route('admin.sales.garage-delivery.update', $garage), ['garage_delivery_status' => 'delivered'])->assertSessionHasNoErrors();
        $this->assertSame('delivered', DB::table('sales')->where('id', $garage)->value('delivery_status'));
        $this->assertReconciles($line, 'garage order delivered');
        $this->assertSame([4000.0, 20000.0], [$this->line($line)->released, $this->line($line)->in_garage]);

        // The Standard order rides the truck (Deliver from Truck) and is deducted once; delivering again is refused.
        $this->deliverFromTruck($a, $haul, $standard)->assertSessionHasNoErrors();
        $this->deliverFromTruck($a, $haul, $standard)->assertSessionHasErrors('sale_id');
        $this->assertReconciles($line, 'standard delivered from truck');
        $this->assertSame([14000.0, 26000.0, 40000.0], [$this->line($line)->released, $this->line($line)->on_truck, $this->line($line)->remaining_in_depot]);

        // The last 26,000 L on the truck: one order. It could still come from the depot, so the truck's liters stay
        // unloadable; a second 26,000 L order and then a 14,000.01 L one would leave no source for all of them.
        $this->sale($a, $line, 26000)->assertSessionHasNoErrors();
        $last = $this->lastSaleId();
        $this->assertSame(40000.0, $this->line($line)->depot_to_client_available);
        $this->assertSame([$haul => 26000.0], app(PurchaseStockService::class)->unloadableByHaul([$line]));
        $this->sale($a, $line, 26000)->assertSessionHasNoErrors();
        $second = $this->lastSaleId();
        $this->assertSame([14000.0, 0.0], [$this->line($line)->depot_to_client_available, app(PurchaseStockService::class)->unloadableByHaul([$line])[$haul]]);
        $this->sale($a, $line, 14000.01)->assertSessionHasErrors('items.0.quantity_liters');
        $this->unload($a, $haul, 1)->assertSessionHasErrors("unload.$haul");
        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.cancel', $second), ['cancel_reason' => 'customer_cancelled'])->assertSessionHasNoErrors();
        $this->deliverFromTruck($a, $haul, $last)->assertSessionHasNoErrors();
        $this->assertSame('available', DB::table('trucks')->where('id', $truck)->value('status'));
        $this->assertReconciles($line, 'truck empty');

        // Every stock-out names the same Purchase ID, and the totals match: 40,000 L delivered, 60,000 L left.
        $this->assertSame([40000.0, 60000.0], [$this->line($line)->released, $this->stockInRemaining($line)]);
        $codes = collect($this->actingAs($a['admin'])->get(route('admin.inventory.stock-out'))->viewData('stockOut')->items())->pluck('cells.6')->unique()->values()->all();
        $this->assertContains('PUR-DSL-070', $codes);
        $this->assertSame(3, DB::table('stock_outs')->where('status', 'released')->where(fn ($q) => $q->where('purchase_item_id', $line)->orWhereIn('id', DB::table('stock_out_draws')->where('purchase_item_id', $line)->select('stock_out_id')))->count());
    }

    public function test_the_trucks_tab_shows_the_fuel_inside_each_truck_by_purchase(): void
    {
        $a = $this->actors();
        $truck = $this->truck('TRK-100K', 100000);
        $line = $this->purchase($a, 'PUR-DSL-030', 50000);
        $this->liftOnto($a, $truck, $line, 50000);

        $page = $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting.trucks'))->assertOk();
        $this->assertSame('PUR-DSL-030', $page->viewData('truckFuel')[$truck][0]['purchase_code']);
        $this->assertSame(50000.0, $page->viewData('truckFuel')[$truck][0]['liters']);
        $page->assertSee('data-truck-fuel="PUR-DSL-030"', false)->assertSee('PUR-DSL-030 · Diesel · 50,000.00 L');
    }

    // ----- 1. Dashboard ---------------------------------------------------------------------------------------------

    public function test_the_dashboard_prices_a_release_without_its_sale_line_at_the_average_line_price_of_its_fuel(): void
    {
        $a = $this->actors();
        $truck = $this->truck('TRK-100K', 100000);
        $line = $this->purchase($a, 'PUR-DSL-060', 50000);
        $haul = $this->liftOnto($a, $truck, $line, 50000);
        $this->sale($a, $line, 1000)->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $this->deliverFromTruck($a, $haul, $saleId)->assertSessionHasNoErrors();
        $dashboard = app(\App\Services\DashboardService::class);
        $this->assertSame(62000.0, $dashboard->stockOutMargin()['stock_out']);

        // An imported release that names no sale line: priced by its sale's lines of the same fuel (finalization rewrite
        // of the derived-table join, same figure).
        DB::table('sale_items')->where('sale_id', $saleId)->update(['unit_price' => 64]);
        DB::table('stock_outs')->where('sale_id', $saleId)->update(['sale_item_id' => null]);
        $this->assertSame(64000.0, $dashboard->stockOutMargin()['stock_out']);
        DB::table('stock_outs')->where('sale_id', $saleId)->update(['fuel_type_id' => $this->fuel('PREM')]);
        $this->assertSame(0.0, $dashboard->stockOutMargin()['stock_out']);
    }


    public function test_the_two_circled_dashboard_charts_are_removed_and_the_rest_stays(): void
    {
        $a = $this->actors();
        $html = $this->actingAs($a['admin'])->get(route('admin.dashboard'))->assertOk()->getContent();
        foreach (['Inventory Reconciliation', 'Purchase Reports', 'purchase-report-title', 'data-inventory-reconciliation', 'Number of purchase transactions per month'] as $gone) {
            $this->assertStringNotContainsString($gone, $html, $gone.' should be gone');
        }
        foreach (['Current Stock By Fuel Type (Stock Levels)', 'Fuel Lifting Status', 'Purchased V.S Stock In', 'Garage Tanks', 'Expected Sales Revenue', 'Stock Out V.S Receivables Margin', 'Fuel Sold', 'Payment Status (Customers)', 'Peak Demands', 'data-dashboard-export'] as $kept) {
            $this->assertStringContainsString($kept, $html, $kept.' should stay');
        }
    }

    // ----- 5. Withdrawal receipt: image or PDF, preview before upload ------------------------------------------------

    public function test_a_pdf_withdrawal_receipt_is_accepted_served_privately_and_the_forms_preview_the_file(): void
    {
        Storage::fake('local');
        $a = $this->actors();
        $line = $this->purchase($a, 'PUR-DSL-040', 10000);
        $haul = $this->liftOnto($a, $a['truck'], $line, 10000);

        // The Admin View form and the driver form both carry the preview (image or PDF frame, with Remove).
        $admin = $this->actingAs($a['admin'])->get(route('admin.fuel-lifting'))->assertOk();
        $admin->assertSee('data-receipt-preview-form', false)->assertSee('data-preview-pdf', false)->assertSee('data-preview-remove', false)
            ->assertSee('accept="image/jpeg,image/png,image/webp,application/pdf,.jpg,.jpeg,.png,.webp,.pdf"', false);
        $this->actingAs($a['driver'])->get(route('driver.fuel-lifting'))->assertOk()->assertSee('data-receipt-preview-form', false)->assertSee('data-preview-remove', false);

        // A renamed text file is refused; a PDF is stored on the private disk.
        $this->actingAs($a['admin'])->from(route('admin.fuel-lifting'))->post(route('admin.fuel-lifting.hauls.withdrawal-receipt.store', $haul), [
            'withdrawal_receipt' => UploadedFile::fake()->create('receipt.pdf', 10, 'text/plain'),
        ])->assertSessionHasErrors('withdrawal_receipt');
        $this->actingAs($a['admin'])->from(route('admin.fuel-lifting'))->post(route('admin.fuel-lifting.hauls.withdrawal-receipt.store', $haul), [
            'withdrawal_receipt' => UploadedFile::fake()->create('receipt.pdf', 40, 'application/pdf'),
        ])->assertSessionHasNoErrors();
        $path = (string) DB::table('hauls')->where('id', $haul)->value('withdrawal_receipt_path');
        $this->assertStringEndsWith('.pdf', $path);
        Storage::disk('local')->assertExists($path);

        // Served as a PDF to the roles that may see it; never to a Sales Officer.
        $this->actingAs($a['inventory'])->get(route('withdrawal-receipts.show', $haul))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($a['sales'])->get(route('withdrawal-receipts.show', $haul))->assertForbidden();

        // An image still works as before.
        $this->actingAs($a['admin'])->from(route('admin.fuel-lifting'))->post(route('admin.fuel-lifting.hauls.withdrawal-receipt.store', $haul), [
            'withdrawal_receipt' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertSessionHasNoErrors();
        $this->assertStringEndsWith('.jpg', (string) DB::table('hauls')->where('id', $haul)->value('withdrawal_receipt_path'));
        Storage::disk('local')->assertMissing($path);
    }

    public function test_the_content_security_policy_allows_the_receipt_frames_only_from_this_site_and_the_local_preview(): void
    {
        $a = $this->actors();
        $csp = (string) $this->actingAs($a['admin'])->get(route('admin.dashboard'))->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-src 'self' blob:;", $csp);
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
    }

    // ----- 6. Stock Out Source on several lines ---------------------------------------------------------------------

    public function test_the_stock_out_source_column_shows_each_part_on_its_own_line(): void
    {
        $a = $this->actors();
        $truck = $this->truck('TRK-100K', 100000);
        $line = $this->purchase($a, 'PUR-DSL-050', 20000);
        $haul = $this->liftOnto($a, $truck, $line, 20000);
        $this->sale($a, $line, 5000)->assertSessionHasNoErrors();
        $this->deliverFromTruck($a, $haul, $this->lastSaleId())->assertSessionHasNoErrors();

        $page = $this->actingAs($a['admin'])->get(route('admin.inventory.stock-out'))->assertOk();
        $row = $page->viewData('stockOut')->first();
        $this->assertSame(['Depot — Audit Terminal'], $row['source_lines']);
        $this->assertSame('Depot — Audit Terminal', $row['cells'][13]);
        $page->assertSee('<td class="stock-out-source" data-stock-out-source>', false)->assertSee('<span class="stock-out-source-lines">', false)->assertSee('<span class="stock-out-source-line">Depot — Audit Terminal</span>', false);
    }
}
