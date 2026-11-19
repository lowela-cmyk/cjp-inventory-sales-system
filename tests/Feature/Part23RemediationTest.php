<?php

namespace Tests\Feature;

use App\Services\PurchaseStockService;
use App\Services\SaleConfirmationService;
use App\Services\StockInService;
use App\Services\StockOutReleaseService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\PurchaseFlows;
use Tests\Feature\Audit\AuditTestCase;

class Part23RemediationTest extends AuditTestCase
{
    use PurchaseFlows;

    public function test_stock_in_uses_sql_limit_and_sorts_filtered_records_without_loading_other_tabs(): void
    {
        $a = $this->actors();
        for ($i = 0; $i < 31; $i++) $this->purchaseLine($a, 'DSL', 1000 + $i, 40 + $i);
        $this->actingAs($a['admin']);
        DB::enableQueryLog();
        $response = $this->get(route('admin.inventory.stock-in', ['per_page' => 10, 'sort' => 'quantity', 'direction' => 'asc', 'status' => 'available']));
        $response->assertOk()->assertViewHas('purchases', fn ($rows) => $rows->isEmpty())->assertViewHas('stockOut', fn ($rows) => $rows->isEmpty());
        $rows = $response->viewData('stockIn');
        $this->assertSame(31, $rows->total());
        $this->assertCount(10, $rows);
        $this->assertSame('1,000.00', $rows->first()['purchased']);
        $queries = collect(DB::getQueryLog())->pluck('query');
        $this->assertTrue($queries->contains(fn ($sql) => str_contains($sql, 'stock_rows') && preg_match('/limit 10\b/i', $sql)));
        DB::disableQueryLog();
        $next = $this->get($rows->nextPageUrl())->assertOk()->viewData('stockIn');
        $this->assertSame('1,010.00', $next->first()['purchased']);
        $this->assertEmpty($rows->pluck('id')->intersect($next->pluck('id')));
        $descending = $this->get(route('admin.inventory.stock-in', ['per_page' => 10, 'sort' => 'quantity', 'direction' => 'desc']))->viewData('stockIn');
        $this->assertSame('1,030.00', $descending->first()['purchased']);
        $this->get(route('admin.inventory.stock-in', ['sort' => 'id); DROP TABLE purchases', 'direction' => 'invalid']))->assertOk();
        $this->assertDatabaseCount('purchases', 31);
    }

    public function test_sql_stock_status_and_totals_follow_reservations_and_remain_independent_of_the_page(): void
    {
        $a = $this->actors();
        $first = $this->purchaseLine($a, 'DSL', 1000);
        $second = $this->purchaseLine($a, 'DSL', 2000);
        $this->recordOrder($a, ['fuel_type_id' => $a['fuel'], 'purchase_item_id' => $first, 'quantity_liters' => 400, 'unit_price' => 60])->assertSessionHasNoErrors();
        $service = app(StockInService::class);
        // Both still have liters left, so both are Available (statuses are Available / Sold Out).
        $available = $service->query(['status' => 'available'])->get()->keyBy('purchase_item_id');
        $this->assertEqualsCanonicalizing([$first, $second], $available->keys()->map(fn ($id) => (int) $id)->all());
        $this->assertEquals(600, $available[$first]->remaining);
        $this->assertEquals(2000, $available[$second]->remaining);
        $this->assertEquals(2600, $service->totals()['total_inventory']);
        $this->assertSame([], $service->query(['status' => 'sold_out'])->pluck('purchase_item_id')->all());
    }

    public function test_customers_are_lazy_and_user_selected_sorting_is_real(): void
    {
        $a = $this->actors();
        DB::table('customers')->insert(['customer_code' => 'AAA', 'name' => 'AAA', 'company_name' => 'AAA', 'status' => 'active', 'payment_status' => 'clear']);
        $this->actingAs($a['sales']);
        $response = $this->get(route('sales-officer.sales.customers', ['sort' => 'name', 'direction' => 'asc']));
        $response->assertOk()->assertViewHas('sales', fn ($rows) => $rows->isEmpty());
        $this->assertSame('AAA', $response->viewData('customers')->first()['name']);
        $descending = $this->get(route('sales-officer.sales.customers', ['sort' => 'name', 'direction' => 'desc']))->viewData('customers');
        $this->assertNotSame('AAA', $descending->first()['name']);
    }

    public function test_already_delivered_without_stock_outs_is_reported_without_creating_movements(): void
    {
        $a = $this->actors();
        $purchase = $this->purchaseLine($a, 'DSL', 1000);
        $this->recordOrder($a, ['fuel_type_id' => $a['fuel'], 'purchase_item_id' => $purchase, 'quantity_liters' => 200, 'unit_price' => 60])->assertSessionHasNoErrors();
        $sale = $this->lastSaleId();
        DB::table('sales')->where('id', $sale)->update(['delivery_status' => 'delivered']);
        DB::table('sale_items')->where('sale_id', $sale)->update(['fulfilled_quantity_liters' => 200]);
        try { app(SaleConfirmationService::class)->updateDeliveryStatus($sale, 'delivered', $a['driver']->id); $this->fail('Expected reconciliation guard'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('do not reconcile', $e->getMessage()); }
        $this->assertDatabaseCount('stock_outs', 0);
        $this->assertDatabaseHas('sale_items', ['sale_id' => $sale, 'fulfilled_quantity_liters' => 200]);
        $this->assertDatabaseHas('sales', ['id' => $sale, 'delivery_status' => 'delivered']);
    }

    public function test_future_standard_delivery_cannot_take_the_same_fuel_from_depot_and_a_lift(): void
    {
        $a = $this->actors();
        $purchase = $this->purchaseLine($a, 'DSL', 1000);
        $this->recordOrder($a, ['fuel_type_id' => $a['fuel'], 'purchase_item_id' => $purchase, 'quantity_liters' => 200, 'unit_price' => 60])->assertSessionHasNoErrors();
        $sale = $this->lastSaleId();
        $this->scheduleLift($a, [['purchase_item_id' => $purchase, 'quantity_liters' => 1000]])->assertSessionHasNoErrors();
        try { app(SaleConfirmationService::class)->updateDeliveryStatus($sale, 'delivered', $a['driver']->id); $this->fail('Expected depot source guard'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('already left the depot', $e->getMessage()); }
        $this->assertDatabaseHas('sales', ['id' => $sale, 'delivery_status' => 'pending']);
        $this->assertDatabaseCount('stock_outs', 0);
        $this->assertDatabaseHas('sale_items', ['sale_id' => $sale, 'fulfilled_quantity_liters' => 0]);
    }

    public function test_linked_lift_delivery_conserves_quantities_and_is_idempotent(): void
    {
        $a = $this->actors();
        $purchase = $this->purchaseLine($a, 'DSL', 1000);
        $this->recordOrder($a, ['fuel_type_id' => $a['fuel'], 'purchase_item_id' => $purchase, 'quantity_liters' => 300, 'unit_price' => 60], ['delivery_type' => 'depot_to_client'])->assertSessionHasNoErrors();
        $sale = $this->lastSaleId();
        $this->scheduleLift($a, [['purchase_item_id' => $purchase, 'quantity_liters' => 500]], [$sale])->assertSessionHasNoErrors();
        $this->completeLift($a, $this->lastHaulId());
        app(SaleConfirmationService::class)->updateDeliveryStatus($sale, 'delivered', $a['driver']->id);
        $line = app(PurchaseStockService::class)->line($purchase);
        $this->assertFalse($line->requires_reconciliation);
        $this->assertEquals(1000, $line->remaining_in_depot + $line->on_truck + $line->in_garage + $line->released);
        $this->assertDatabaseCount('stock_outs', 1);
        $this->assertNotNull(DB::table('stock_outs')->value('haul_allocation_id'));
    }

    public function test_wrong_sale_allocation_is_rejected_without_writes(): void
    {
        $a = $this->actors();
        $purchase = $this->purchaseLine($a, 'DSL', 1000);
        $this->recordOrder($a, ['fuel_type_id' => $a['fuel'], 'purchase_item_id' => $purchase, 'quantity_liters' => 200, 'unit_price' => 60], ['delivery_type' => 'depot_to_client'])->assertSessionHasNoErrors();
        $other = $this->lastSaleId();
        $this->scheduleLift($a, [['purchase_item_id' => $purchase, 'quantity_liters' => 500]], [$other])->assertSessionHasNoErrors();
        $haul = $this->lastHaulId();
        DB::table('hauls')->where('id', $haul)->update(['status' => 'lifted']);
        $allocation = DB::table('haul_allocations')->first(['id', 'haul_id']);
        $this->recordOrder($a, ['fuel_type_id' => $a['fuel'], 'purchase_item_id' => $purchase, 'quantity_liters' => 100, 'unit_price' => 60])->assertSessionHasNoErrors();
        $sale = $this->lastSaleId();
        DB::table('sales')->where('id', $sale)->update(['delivery_status' => 'delivered']);
        $error = app(StockOutReleaseService::class)->releaseSaleItem(DB::table('sale_items')->where('sale_id', $sale)->value('id'), now()->toDateTimeString(), $a['driver']->id, $allocation);
        $this->assertStringContainsString('does not belong', $error);
        $this->assertDatabaseCount('stock_outs', 0);
    }

    public function test_reconciliation_report_is_evidence_only_and_holds_ambiguous_stock(): void
    {
        $a = $this->actors();
        $purchase = $this->purchaseLine($a, 'DSL', 1000);
        $this->recordOrder($a, ['fuel_type_id' => $a['fuel'], 'purchase_item_id' => $purchase, 'quantity_liters' => 200, 'unit_price' => 60])->assertSessionHasNoErrors();
        $sale = $this->lastSaleId();
        DB::table('sales')->where('id', $sale)->update(['delivery_status' => 'delivered']);
        DB::table('hauls')->insert(['haul_code' => 'HIST-REVIEW', 'purchase_id' => DB::table('purchase_items')->where('id', $purchase)->value('purchase_id'), 'purchase_item_id' => $purchase, 'depot_id' => $a['depot'], 'fuel_type_id' => $a['fuel'], 'truck_id' => $a['truck'], 'driver_user_id' => $a['driver']->id, 'scheduled_at' => now(), 'quantity_liters' => 1000, 'status' => 'completed']);
        DB::table('stock_outs')->insert(['stock_out_code' => 'HIST-RELEASE', 'sale_id' => $sale, 'sale_item_id' => DB::table('sale_items')->where('sale_id', $sale)->value('id'), 'customer_id' => $a['customer'], 'fuel_type_id' => $a['fuel'], 'purchase_item_id' => $purchase, 'source_type' => 'depot', 'depot_id' => $a['depot'], 'quantity_liters' => 100, 'unit_cost' => 50, 'stock_out_at' => now(), 'status' => 'released']);
        $before = DB::table('stock_outs')->get()->toJson();
        $report = app(\App\Services\InventoryReconciliationReportService::class)->report();
        $this->assertSame(['delivery_items' => 1, 'delivery_gap_liters' => 100.0, 'purchase_lines' => 1], $report['summary']);
        $this->assertFalse($report['inventory_reconciliation'][0]['executable_correction']);
        $this->assertTrue($report['inventory_reconciliation'][0]['conditional_validation']['quantity_conservation_passes_under_assumption']);
        $this->assertFalse($report['inventory_reconciliation'][0]['conditional_validation']['specific_release_to_haul_relationship_proven']);
        $this->assertSame($before, DB::table('stock_outs')->get()->toJson());
        $this->assertEquals(0, app(PurchaseStockService::class)->line($purchase)->available);
        $this->assertDatabaseCount('haul_allocations', 0);
    }

    public function test_sales_metadata_queries_do_not_grow_per_displayed_row(): void
    {
        $a = $this->actors();
        for ($i = 0; $i < 101; $i++) {
            $sale = DB::table('sales')->insertGetId(['sale_code' => 'BATCH-'.$i, 'customer_id' => $a['customer'], 'sale_date' => '2026-10-01', 'delivery_type' => 'standard', 'delivery_status' => 'pending', 'status' => 'confirmed']);
            DB::table('sale_items')->insert(['sale_id' => $sale, 'fuel_type_id' => $a['fuel'], 'quantity_liters' => 10, 'main_quantity_liters' => 10, 'unit_price' => 50, 'line_total' => 500]);
            DB::table('receivables')->insert(['sale_id' => $sale, 'status' => 'unpaid']);
        }
        $this->actingAs($a['admin']);
        DB::enableQueryLog();
        $this->get(route('admin.sales', ['per_page' => 10]))->assertOk();
        $small = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->app->forgetScopedInstances();
        $this->get(route('admin.sales', ['per_page' => 100]))->assertOk();
        $large = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual($small + 10, $large, 'Visible orders should share source pools and metadata reads.');
    }

}
