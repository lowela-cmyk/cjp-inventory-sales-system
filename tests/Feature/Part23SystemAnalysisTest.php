<?php

namespace Tests\Feature;

use App\Services\GarageTankService;
use App\Services\PurchaseService;
use App\Services\RecordCodeService;
use App\Services\TruckAvailabilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PurchaseFlows;
use Tests\Feature\Audit\AuditTestCase;

/**
 * System Analysis Part 23 (November 16, 2026).
 */
class Part23SystemAnalysisTest extends AuditTestCase
{
    use PurchaseFlows;

    /** A historical lift written straight in as Completed, with no delivery links (as the workbook import did). */
    private function importedCompletedLift(array $a, int $purchaseItemId, float $liters): int
    {
        $item = DB::table('purchase_items')->where('id', $purchaseItemId)->first();

        return (int) DB::table('hauls')->insertGetId([
            'haul_code' => 'LFT-HIST-'.Str::random(5), 'purchase_id' => $item->purchase_id, 'purchase_item_id' => $item->id,
            'depot_id' => $a['depot'], 'fuel_type_id' => $item->fuel_type_id, 'truck_id' => $a['truck'],
            'historical_driver_name' => 'Historical Driver', 'scheduled_at' => '2026-01-05 08:00:00', 'hauled_at' => '2026-01-05 09:00:00',
            'quantity_liters' => $liters, 'status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_page_links_keep_the_active_tab_and_filters_and_sit_under_the_table(): void
    {
        $a = $this->actors();
        for ($i = 0; $i < 25; $i++) $this->purchaseLine($a, 'DSL', 1000 + $i);
        $this->actingAs($a['admin']);

        $page = $this->get(route('admin.inventory.stock-in', ['per_page' => 10, 'search' => 'PUR', 'fuel_type_id' => $a['fuel'], 'stock_in_page' => 3]))->assertOk();
        $rows = $page->viewData('stockIn');
        $this->assertSame(3, $rows->currentPage());
        $this->assertStringContainsString('/admin/inventory/stock-in?', $rows->previousPageUrl());
        foreach (['search=PUR', 'fuel_type_id='.$a['fuel'], 'per_page=10', 'stock_in_page=2'] as $part) {
            $this->assertStringContainsString($part, $rows->previousPageUrl());
        }
        $page->assertSee('Showing 21 to 25 of 25 records.');

        $stockOut = $this->get(route('admin.inventory.stock-out', ['per_page' => 10, 'search' => 'SLS']))->assertOk()->viewData('stockOut');
        $this->assertSame('stock_out_page', $stockOut->getPageName());

        // Ledger, Transaction and Hauled: the bar is under the table, not above the tabs.
        foreach (['admin.ledger', 'admin.ledger.transactions', 'admin.fuel-lifting.hauled'] as $route) {
            $html = $this->get(route($route))->assertOk()->getContent();
            $tabs = strpos($html, 'class="tabs"');
            $this->assertNotFalse($tabs, $route);
            $bar = strpos($html, 'data-table-pagination');
            if ($bar !== false) {
                $this->assertGreaterThan($tabs, $bar, $route.' pagination must follow the table');
                $this->assertGreaterThan(strpos($html, '</table>'), $bar, $route);
            }
        }
    }

    public function test_purchase_payment_mode_reads_imported_bank_values_and_the_get_url_opens_purchases(): void
    {
        $this->assertSame(['bank_transfer', 'BDO'], PurchaseService::normalizePaymentMode(null, 'Bank (BDO)'));
        $this->assertSame(['bank_transfer', 'METROBANK'], PurchaseService::normalizePaymentMode('Bank (Metrobank)', null));
        $this->assertSame(['cash', null], PurchaseService::normalizePaymentMode('Cash', 'BDO'));
        $this->assertSame(['cheque', 'BPI'], PurchaseService::normalizePaymentMode('cheque', 'BPI'));
        $this->assertSame([null, null], PurchaseService::normalizePaymentMode(null, null));

        $a = $this->actors();
        $item = $this->purchaseLine($a, 'DSL', 20000);
        DB::table('purchases')->where('id', DB::table('purchase_items')->where('id', $item)->value('purchase_id'))->update(['payment_mode' => null, 'bank_name' => 'Bank (BDO)']);
        $rows = $this->actingAs($a['admin'])->get(route('admin.inventory'))->assertOk()->viewData('purchases');
        $row = collect($rows->items())->firstWhere('id', $item) ?? collect($rows->items())->first();
        $this->assertSame('bank_transfer', $row['payment_mode']);
        $this->assertSame('BDO', $row['bank_name']);

        $this->get(route('admin.inventory.purchases', ['per_page' => 25]))->assertRedirect(route('admin.inventory', ['per_page' => 25]));
    }

    public function test_depot_orders_are_at_least_40000_liters_and_stock_in_is_read_only(): void
    {
        config(['fuels.min_depot_order_liters' => 40000]);
        $a = $this->actors();
        $base = ['purchase_date' => '2026-10-14', 'depot_id' => $a['depot'], 'fuel_type_id' => $this->fuel('DSL'), 'unit_cost' => 50, 'payment_status' => 'unpaid', 'payment_mode' => 'cash'];

        $this->actingAs($a['inventory'])->post(route('inventory-officer.inventory.purchases.store'), $base + ['idempotency_key' => (string) Str::uuid(), 'quantity_ordered_liters' => 39999])
            ->assertSessionHasErrors(['quantity_ordered_liters' => 'The minimum order quantity to the depot is 40,000 L.']);
        $this->post(route('inventory-officer.inventory.purchases.store'), $base + ['idempotency_key' => (string) Str::uuid(), 'quantity_ordered_liters' => 40000])->assertSessionHasNoErrors();
        $item = (int) DB::table('purchase_items')->orderByDesc('id')->value('id');
        $this->patch(route('inventory-officer.inventory.purchases.update', $item), $base + ['quantity_ordered_liters' => 30000])->assertSessionHasErrors('quantity_ordered_liters');

        // A smaller historical order stays editable as long as its quantity is unchanged.
        DB::table('purchase_items')->where('id', $item)->update(['quantity_ordered_liters' => 2000]);
        $this->patch(route('inventory-officer.inventory.purchases.update', $item), $base + ['quantity_ordered_liters' => 2000, 'unit_cost' => 51])->assertSessionHasNoErrors();

        $html = $this->actingAs($a['admin'])->get(route('admin.inventory'))->getContent();
        $this->assertMatchesRegularExpression('/id="quantity_ordered_liters" name="quantity_ordered_liters" type="number" min="40000"/', $html);
        $stockIn = $this->get(route('admin.inventory.stock-in'))->assertOk()->assertSee('Stock-In Record')->assertDontSee('Edit Stock-In Record');
        $this->assertStringNotContainsString('id="stock-in-update-', $stockIn->getContent());
        $this->assertMatchesRegularExpression('/data-modal-open="[^"]+">View<\/button>/', $stockIn->getContent());
    }

    public function test_completed_lifts_are_empty_so_on_truck_never_adds_up_past_trips(): void
    {
        $a = $this->actors();
        $items = collect(range(1, 3))->map(fn () => $this->purchaseLine($a, 'DSL', 20000));
        foreach ($items as $item) $this->importedCompletedLift($a, $item, 20000);

        $trucks = app(TruckAvailabilityService::class);
        $this->assertSame(0.0, $trucks->leftoverOnTruck($a['truck']), 'Completed lifts hold no fuel (completion requires an empty truck).');
        $this->assertSame('available', $trucks->effectiveStatus($a['truck']));

        // A Lifted line still carries its undelivered fuel, never more than the truck holds.
        $this->scheduleLift($a, [['purchase_item_id' => $this->purchaseLine($a, 'DSL', 20000), 'quantity_liters' => 18000]])->assertSessionHasNoErrors();
        $this->completeLift($a, $this->lastHaulId());
        $this->assertSame(18000.0, $trucks->leftoverOnTruck($a['truck']));
        $this->assertSame('loaded', $trucks->effectiveStatus($a['truck']));
        $row = collect($trucks->directory()->items())->firstWhere('id', $a['truck']);
        $this->assertLessThanOrEqual((float) $row->capacity_liters, (float) $row->leftover_liters);
    }

    public function test_truck_ids_are_typed_as_truck_codes_within_40000_liters_and_inactive_is_retired(): void
    {
        $a = $this->actors();
        $this->actingAs($a['admin']);
        $this->get(route('admin.fuel-lifting.trucks'))->assertOk()->assertDontSee('Name / Description')->assertSee('TRUCK-001');

        $truck = ['plate_number' => 'abc 123', 'capacity_liters' => 30000, 'status' => 'available'];
        $this->post(route('admin.trucks.store'), $truck)->assertSessionHasErrors('truck_code');
        $this->post(route('admin.trucks.store'), $truck + ['truck_code' => 'TRK-9'])->assertSessionHasErrors(['truck_code' => 'The Truck ID must look like TRUCK-001.']);
        $this->post(route('admin.trucks.store'), ['truck_code' => 'TRUCK-001'] + array_merge($truck, ['capacity_liters' => 40001]))->assertSessionHasErrors('capacity_liters');
        $this->post(route('admin.trucks.store'), ['truck_code' => 'TRUCK-001'] + array_merge($truck, ['status' => 'inactive']))->assertSessionHasErrors('status');
        $this->post(route('admin.trucks.store'), $truck + ['truck_code' => 'truck-001'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('trucks', ['truck_code' => 'TRUCK-001', 'plate_number' => 'ABC 123', 'name' => null]);
        $this->post(route('admin.trucks.store'), ['truck_code' => 'TRUCK-001', 'plate_number' => 'XYZ 9'] + $truck)->assertSessionHasErrors('truck_code');

        // The legacy code of an existing truck may be kept; a new code must follow the format.
        $this->patch(route('admin.trucks.update', $a['truck']), ['truck_code' => 'TRK-AUD', 'plate_number' => 'AUD 1', 'capacity_liters' => 30000, 'status' => 'maintenance'])->assertSessionHasNoErrors();
        $this->patch(route('admin.trucks.update', $a['truck']), ['truck_code' => 'TRUCK-002', 'plate_number' => 'AUD 1', 'capacity_liters' => 30000, 'status' => 'available'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('trucks', ['id' => $a['truck'], 'truck_code' => 'TRUCK-002']);
        $this->patch(route('admin.trucks.status', $a['truck']))->assertSessionHasErrors('status');
        $this->assertDatabaseHas('trucks', ['id' => $a['truck'], 'status' => 'available']);
        $this->assertNotContains('inactive', TruckAvailabilityService::DISPLAY_STATUSES);
    }

    public function test_hauled_table_shows_the_truck_id_and_the_drivers_phone_and_hides_load_columns(): void
    {
        $a = $this->actors();
        DB::table('users')->where('id', $a['driver']->id)->update(['name' => 'Historical Driver', 'phone' => '09170000001']);
        DB::table('trucks')->where('id', $a['truck'])->update(['truck_code' => 'TRUCK-007', 'name' => 'CJP Fuel Tanker TRUCK-007']);
        $this->importedCompletedLift($a, $this->purchaseLine($a, 'DSL', 20000), 20000);

        $page = $this->actingAs($a['admin'])->get(route('admin.fuel-lifting.hauled'))->assertOk();
        $row = collect($page->viewData('deliveredRows'))->first();
        $this->assertSame('09170000001', $row['cells'][6]);
        $this->assertSame('TRUCK-007', $row['cells'][7]);
        $this->assertArrayHasKey('Delivered Quantity', $row['details']);
        $this->assertArrayHasKey('Remaining on Truck', $row['details']);
        $section = Str::betweenFirst($page->getContent(), 'data-tab-panel="hauled"', '</thead>');
        $this->assertStringNotContainsString('<th>Delivered Quantity</th>', $section);
        $this->assertStringNotContainsString('<th>On Truck</th>', $section);
    }

    public function test_garage_tanks_never_exceed_24000_liters_and_cards_drop_average_cost(): void
    {
        $this->assertSame(24000.0, GarageTankService::capacity(120000));
        $this->assertSame(20000.0, GarageTankService::capacity(20000));
        $this->assertSame(24000.0, GarageTankService::capacity(null));

        $a = $this->actors();
        $html = $this->actingAs($a['admin'])->get(route('admin.inventory.garage'))->assertOk()->getContent();
        $this->assertStringNotContainsString('garage-tank-avg', $html);
        $this->assertStringNotContainsString('L free of 80,000', $html);
    }

    public function test_lift_codes_skip_codes_already_used_by_imported_or_deleted_lifts(): void
    {
        $a = $this->actors();
        $fuel = $this->fuel('DSL');
        DB::table('code_sequences')->insert(['prefix' => 'LFT', 'fuel_code' => 'DSL', 'last_number' => 1, 'created_at' => now(), 'updated_at' => now()]);
        foreach (['LFT-DSL-002', 'LFT-DSL-003'] as $code) {
            DB::table('lifting_schedules')->insert(['schedule_code' => $code, 'depot_id' => $a['depot'], 'truck_id' => $a['truck'], 'scheduled_at' => now(), 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->assertSame('LFT-DSL-004', app(RecordCodeService::class)->next(RecordCodeService::LIFT, $fuel));

        $this->scheduleLift($a, [['purchase_item_id' => $this->purchaseLine($a, 'DSL', 20000), 'quantity_liters' => 10000]])->assertSessionHasNoErrors();
        $this->assertSame('LFT-DSL-005', DB::table('lifting_schedules')->orderByDesc('id')->value('schedule_code'));
    }

    public function test_record_sales_hint_is_one_line_per_fuel_and_save_errors_are_specific(): void
    {
        $a = $this->actors();
        $this->purchaseLine($a, 'DSL', 20000);
        $html = $this->actingAs($a['admin'])->get(route('admin.sales'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-stock-hint-fuel="DSL" hidden>Diesel: 20,000 L in depot, 0 L in garage, 0 L on trucks<\/span>/', $html);
        $this->assertStringNotContainsString('Not in the depot:', $html);

        $item = (int) DB::table('purchase_items')->orderByDesc('id')->value('id');
        $this->recordOrder($a, ['purchase_item_id' => $item, 'quantity_liters' => 100, 'unit_price' => 60], ['sales_order_number' => 'SO-DUP-1'])->assertSessionHasNoErrors();
        $this->recordOrder($a, ['purchase_item_id' => $item, 'quantity_liters' => 100, 'unit_price' => 60], ['sales_order_number' => 'SO-DUP-1'])
            ->assertSessionHasErrors('sales_order_number');

        // A database error while saving (here a Sales ID taken between validation and insert) names the cause and is
        // logged, instead of "Sale record could not be saved". The trigger syntax is SQLite's.
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }
        $sequence = DB::table('code_sequences')->where('prefix', 'SLS')->where('fuel_code', 'DSL')->value('last_number');
        DB::statement("CREATE TRIGGER part23_sale_code_clash BEFORE INSERT ON sales BEGIN SELECT RAISE(ABORT, 'UNIQUE constraint failed: sales.sale_code'); END");
        $this->recordOrder($a, ['purchase_item_id' => $item, 'quantity_liters' => 100, 'unit_price' => 60])
            ->assertSessionHasErrors('sale');
        $this->assertStringStartsWith('A new Sales ID could not be assigned', session('errors')->first('sale'));
        DB::statement('DROP TRIGGER part23_sale_code_clash');
        $this->assertSame($sequence, DB::table('code_sequences')->where('prefix', 'SLS')->where('fuel_code', 'DSL')->value('last_number'));
    }

    public function test_peak_demands_counts_purchases_per_month(): void
    {
        $a = $this->actors();
        $item = $this->purchaseLine($a, 'DSL', 50000);
        foreach ([1000, 2500, 400] as $liters) {
            $this->recordOrder($a, ['purchase_item_id' => $item, 'quantity_liters' => $liters, 'unit_price' => 60], ['sale_date' => '2026-10-15'])->assertSessionHasNoErrors();
        }
        $page = $this->actingAs($a['admin'])->get(route('admin.dashboard', ['date_from' => '2026-01-01', 'date_to' => '2026-12-31']))->assertOk();
        $html = $page->getContent();
        $this->assertStringContainsString('Number of purchases per month of 2026', $html);
        $this->assertStringContainsString('data-dashboard-export', $html);
        $this->assertStringNotContainsString('/admin/imports', $html); // Data Import was removed
        preg_match("/data-chart='([^']+)' role=\"img\" aria-label=\"Number of purchases per month of 2026\"/", $html, $m);
        $chart = json_decode(html_entity_decode($m[1]), true);
        $this->assertSame('count', $chart['format']);
        $this->assertSame('Number of purchases', $chart['axisTitle']);
        $this->assertSame(3, $chart['datasets'][0]['data'][9]);
    }

    public function test_workflow_backfill_is_a_dry_run_by_default_and_skips_held_records(): void
    {
        $a = $this->actors();
        $item = $this->purchaseLine($a, 'DSL', 20000);
        $this->importedCompletedLift($a, $item, 20000);
        $purchase = (int) DB::table('purchase_items')->where('id', $item)->value('purchase_id');
        DB::table('purchases')->where('id', $purchase)->update(['workflow_status' => 'pending']);

        $this->artisan('inventory:sync-purchase-workflow')->assertSuccessful();
        $this->assertSame('pending', DB::table('purchases')->where('id', $purchase)->value('workflow_status'));

        $this->artisan('inventory:sync-purchase-workflow', ['--apply' => true])->assertSuccessful();
        $this->assertSame('completed', DB::table('purchases')->where('id', $purchase)->value('workflow_status'));
        $this->assertSame(20000.0, (float) DB::table('purchase_items')->where('id', $item)->value('quantity_ordered_liters'));
    }
}
