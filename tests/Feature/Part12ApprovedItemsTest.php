<?php

namespace Tests\Feature;

use App\Services\AlertSyncService;
use App\Services\LiftAllocationService;
use App\Services\PurchaseStockService;
use App\Services\TruckAvailabilityService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PurchaseFlows;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Part 12 approved items (October 26, 2026): 11 fuel left on a truck (Loaded, deliver from truck, return to depot),
 * 16 a Delivered sale's details are final, 19 Reports removed, 20 alerts for records not settled yet.
 */
class Part12ApprovedItemsTest extends AuditTestCase
{
    use PurchaseFlows;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-26 09:00:00');
    }

    // ----- 11: fuel left on the truck --------------------------------------------------------------------------

    public function test_a_truck_with_fuel_left_is_loaded_delivers_any_customer_order_once_and_returns_the_rest(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 3000, 'unit_price' => 50], ['delivery_type' => 'depot_to_client', 'sales_order_number' => 'SO-D2C'])->assertSessionHasNoErrors();
        $depotOrder = $this->lastSaleId();
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 10000]], [$depotOrder])->assertSessionHasNoErrors();
        $haulId = $this->lastHaulId();
        $this->completeLift($a, $haulId);

        // 10,000 L lifted, 3,000 L delivered on the lift: 7,000 L stay on the truck, which is Loaded.
        $trucks = app(TruckAvailabilityService::class);
        $this->assertSame(7000.0, $trucks->leftoverOnTruck($a['truck']));
        $this->assertSame('loaded', DB::table('trucks')->where('id', $a['truck'])->value('status'));
        $this->assertFalse($trucks->assignableTrucks()->contains('id', $a['truck']));
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 1000]])->assertSessionHasErrors('lift');
        $this->assertSame(10000.0, $this->depot($premium));

        // The Trucks tab, the Schedule row's View modal and the Hauled tab show the liters on the truck.
        $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting.trucks'))->assertOk()->assertSee('7,000.00 L')->assertSee('Loaded');
        $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting'))->assertOk()
            ->assertSee('Fuel Left on the Truck: 7,000.00 L')
            ->assertSee(route('dispatch.fuel-lifting.hauls.return-to-depot', $haulId), false);

        // A Standard order of the same purchase is delivered from the truck: one deduction, through the lift's row.
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 2000, 'unit_price' => 52], ['sales_order_number' => 'SO-STD'])->assertSessionHasNoErrors();
        $standard = $this->lastSaleId();
        $remainingBefore = $this->remaining($premium);
        $this->actingAs($a['dispatch'])->from(route('dispatch.fuel-lifting'))
            ->post(route('dispatch.fuel-lifting.hauls.truck-deliveries.store', $haulId), ['sale_id' => $standard])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn (string $status): bool => str_contains($status, '5,000.00 L left on the truck'));

        $this->assertSame('delivered', DB::table('sales')->where('id', $standard)->value('delivery_status'));
        $this->assertSame(2000.0, (float) DB::table('stock_outs')->where('sale_id', $standard)->sum('quantity_liters'));
        $this->assertNotNull(DB::table('stock_outs')->where('sale_id', $standard)->value('haul_allocation_id'));
        $this->assertSame($remainingBefore - 2000, $this->remaining($premium));
        $this->assertSame(10000.0, $this->depot($premium), 'The truck\'s fuel already left the depot: no second deduction.');
        $this->assertSame((int) $a['truck'], (int) DB::table('sales')->where('id', $standard)->value('delivery_truck_id'));
        $this->assertSame(5000.0, $trucks->leftoverOnTruck($a['truck']));

        // An order larger than what is left is refused; a driver cannot use these actions.
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 6000, 'unit_price' => 50])->assertSessionHasNoErrors();
        $this->actingAs($a['dispatch'])->from(route('dispatch.fuel-lifting'))
            ->post(route('dispatch.fuel-lifting.hauls.truck-deliveries.store', $haulId), ['sale_id' => $this->lastSaleId()])
            ->assertSessionHasErrors('sale_id');
        $this->actingAs($a['driver'])->post(route('dispatch.fuel-lifting.hauls.return-to-depot', $haulId))->assertForbidden();

        // Return to Depot: the 5,000 L go back to the purchase and the truck is Available.
        $this->actingAs($a['admin'])->from(route('admin.fuel-lifting.hauled'))->post(route('admin.fuel-lifting.hauls.return-to-depot', $haulId))
            ->assertSessionHasNoErrors();
        $this->assertSame(0.0, $trucks->leftoverOnTruck($a['truck']));
        $this->assertSame('available', DB::table('trucks')->where('id', $a['truck'])->value('status'));
        $this->assertSame(15000.0, $this->depot($premium));
        $this->assertDatabaseHas('hauls', ['id' => $haulId, 'quantity_liters' => 5000, 'returned_liters' => 5000, 'returned_by' => $a['admin']->id]);
        $this->actingAs($a['admin'])->post(route('admin.fuel-lifting.hauls.return-to-depot', $haulId))->assertSessionHasErrors('lifting');

        // The lift can still be completed, and the truck can take a new lift.
        $this->actingAs($a['dispatch'])->patch(route('dispatch.fuel-lifting.hauls.status', $haulId), ['idempotency_key' => (string) Str::uuid(), 'status' => 'completed'])
            ->assertSessionHasNoErrors();
        $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting.hauled'))->assertOk()->assertSee('Returned to Depot');
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 1000]])->assertSessionHasNoErrors();
    }

    // ----- 16: a Delivered sale is final --------------------------------------------------------------------------

    public function test_a_delivered_sale_keeps_its_order_and_delivery_details_and_stock_out_only_changes_cost(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 10000);
        $truck2 = DB::table('trucks')->insertGetId(['truck_code' => 'TRK-AUD-2', 'capacity_liters' => 30000, 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 1000, 'unit_price' => 50], [
            'sales_order_number' => 'SO-LOCK', 'delivery_truck_id' => $a['truck'], 'delivery_driver_user_id' => $a['driver']->id, 'delivery_date' => '2026-10-25 10:00', 'delivery_status' => 'delivered',
        ])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $sale = DB::table('sales')->where('id', $saleId)->first();
        $form = ['edit_sale_id' => $saleId, 'customer_id' => $sale->customer_id, 'sale_date' => $sale->sale_date, 'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment', 'sales_order_number' => 'SO-LOCK'];

        // Truck, driver, delivery date and lines are refused with a field error.
        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.update', $saleId), $form + ['delivery_truck_id' => $truck2])
            ->assertSessionHasErrors(['delivery_truck_id' => 'This sale is already Delivered, so its truck can no longer be changed.']);
        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.update', $saleId), $form + ['delivery_date' => '2026-10-20 08:00'])
            ->assertSessionHasErrors('delivery_date');
        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.update', $saleId), $form + ['items' => [['purchase_item_id' => $premium, 'quantity_liters' => 900, 'unit_price' => 50]]])
            ->assertSessionHasErrors('sale');
        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.update', $saleId), ['customer_id' => 999999] + $form)
            ->assertSessionHasErrors();

        // Order No., payment terms / method and due date still change; the read-only fields are not sent and stay.
        $this->actingAs($a['sales'])->patch(route('sales-officer.sales.update', $saleId), ['sales_order_number' => 'SO-LOCK-2', 'payment_terms' => 'advance', 'due_date' => '2026-11-30'] + $form)
            ->assertSessionHasNoErrors();
        $after = DB::table('sales')->where('id', $saleId)->first();
        $this->assertSame(['SO-LOCK-2', (int) $a['truck'], (int) $a['driver']->id], [$after->sales_order_number, (int) $after->delivery_truck_id, (int) $after->delivery_driver_user_id]);
        $this->assertSame(1, DB::table('sale_items')->where('sale_id', $saleId)->count());

        // The Edit modal shows them as text, without inputs.
        $page = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="sale_delivery_truck_'.$saleId.'"', $page);
        $this->assertStringNotContainsString('id="sale_qty_'.$saleId.'_0"', $page);

        // Inventory > Edit Stock-Out: only Cost / Unit changes.
        $stockOut = DB::table('stock_outs')->where('sale_id', $saleId)->first();
        $this->actingAs($a['inventory'])->patch(route('inventory-officer.inventory.stock-out.update', $stockOut->id), [
            'edit_stock_out_id' => $stockOut->id, 'unit_cost' => 44, 'stock_out_at' => '2026-10-01 08:00', 'delivery_truck_id' => $truck2, 'delivery_date' => '2026-10-01 08:00',
        ])->assertSessionHasNoErrors();
        $this->assertSame(44.0, (float) DB::table('stock_outs')->where('id', $stockOut->id)->value('unit_cost'));
        $this->assertSame((string) $stockOut->stock_out_at, (string) DB::table('stock_outs')->where('id', $stockOut->id)->value('stock_out_at'));
        $this->assertSame((int) $a['truck'], (int) DB::table('sales')->where('id', $saleId)->value('delivery_truck_id'));
        $stockOutPage = $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory.stock-out'))->assertOk()->getContent();
        $this->assertStringNotContainsString('id="so_truck_'.$stockOut->id.'"', $stockOutPage);
        $this->assertStringNotContainsString('id="so_at_'.$stockOut->id.'"', $stockOutPage);
        $this->assertStringContainsString('id="so_cost_'.$stockOut->id.'"', $stockOutPage);
    }

    // ----- 19: Reports removed ------------------------------------------------------------------------------------

    public function test_reports_is_gone_and_its_old_links_open_the_dashboard(): void
    {
        $a = $this->actors();

        foreach (['/admin/reports', '/admin/reports/export?period=all'] as $url) {
            $this->actingAs($a['admin'])->get($url)->assertRedirect(route('admin.dashboard'));
        }
        $this->actingAs($a['admin'])->post('/admin/reports/business-insight', ['period' => 'all'])->assertRedirect(route('admin.dashboard'));
        $this->actingAs($a['admin'])->get(route('admin.dashboard'))->assertOk()->assertDontSee('>Reports<', false);
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('admin.reports'));
        $this->actingAs($a['sales'])->get('/admin/reports')->assertForbidden();
    }

    // ----- 20: alerts for records not settled yet ---------------------------------------------------------------

    public function test_unsettled_records_raise_alerts_that_escalate_and_resolve_without_duplicates(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 10000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 100, 'unit_price' => 50], ['due_date' => '2026-10-30', 'sales_order_number' => 'SO-UNPAID'])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $saleCode = (string) DB::table('sales')->where('id', $saleId)->value('sale_code');
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 200, 'unit_price' => 50], ['delivery_type' => 'depot_to_client', 'sales_order_number' => 'SO-NOLIFT'])->assertSessionHasNoErrors();
        $depotOrder = $this->lastSaleId();

        $alert = fn (string $key): ?object => DB::table('alerts')->where('deduplication_key', $key)->first();
        $this->actingAs($a['sales'])->get(route('sales-officer.alerts'))->assertOk()->assertSee($saleCode.' Unpaid')->assertSee('Not on a Lift');
        $this->assertSame(['receivable', 'warning', 'open'], [$alert('sync:sale-balance:'.$saleId)->type, $alert('sync:sale-balance:'.$saleId)->severity, $alert('sync:sale-balance:'.$saleId)->status]);
        $this->assertSame('open', $alert('sync:purchase-unlifted:'.DB::table('purchase_items')->where('id', $premium)->value('purchase_id'))->status);
        $this->assertSame('open', $alert('sync:order-not-on-lift:'.$depotOrder)->status);
        // Inventory sees the purchase alert; Dispatch sees the order alert and is sent to the Schedule tab.
        $this->actingAs($a['inventory'])->get(route('inventory-officer.alerts'))->assertOk()->assertSee('Not Fully Lifted')->assertDontSee($saleCode.' Unpaid');
        $this->actingAs($a['dispatch'])->get(route('dispatch.alerts.view', $alert('sync:order-not-on-lift:'.$depotOrder)->id))->assertRedirect(route('dispatch.fuel-lifting'));

        // Running the sync again (or on every page) creates no duplicates.
        app(AlertSyncService::class)->sync(true);
        app(AlertSyncService::class)->sync(true);
        $this->assertSame(1, DB::table('alerts')->where('deduplication_key', 'sync:sale-balance:'.$saleId)->count());

        // Past its due date the alert becomes Overdue (critical) and unread again.
        $this->actingAs($a['sales'])->patch(route('sales-officer.alerts.read', $alert('sync:sale-balance:'.$saleId)->id));
        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->actingAs($a['sales'])->get(route('sales-officer.alerts'))->assertOk()->assertSee($saleCode.' Overdue');
        $this->assertSame('critical', $alert('sync:sale-balance:'.$saleId)->severity);
        $this->assertDatabaseMissing('alert_reads', ['alert_id' => $alert('sync:sale-balance:'.$saleId)->id]);

        // Paid in full: resolved, out of the default list, shown under Status "Resolved"; the bell no longer counts it.
        $this->actingAs($a['sales'])->post(route('sales-officer.sales.payments.store', $saleId), [
            'idempotency_key' => (string) Str::uuid(), 'payment_sale_id' => $saleId, 'amount' => 5000, 'method' => 'cash_on_delivery', 'payment_date' => '2026-11-02',
        ])->assertSessionHasNoErrors();
        $this->actingAs($a['sales'])->get(route('sales-officer.alerts'))->assertOk()->assertDontSee($saleCode.' Overdue');
        $this->assertSame('resolved', $alert('sync:sale-balance:'.$saleId)->status);
        $this->actingAs($a['sales'])->get(route('sales-officer.alerts', ['read' => 'resolved']))->assertOk()->assertSee($saleCode.' Overdue')->assertSee('Resolved');

        // Putting the Depot to Client order on a lift resolves its alert.
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 200]], [$depotOrder])->assertSessionHasNoErrors();
        app(AlertSyncService::class)->sync();
        $this->assertSame('resolved', $alert('sync:order-not-on-lift:'.$depotOrder)->status);
    }

    /** Liters of the purchase still in the depot (not on a lift). */
    private function depot(int $purchaseItemId): float
    {
        return (float) app(PurchaseStockService::class)->line($purchaseItemId)->remaining_in_depot;
    }
}
