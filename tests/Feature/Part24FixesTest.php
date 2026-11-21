<?php

namespace Tests\Feature;

use App\Services\DashboardService;
use App\Services\PurchaseStockService;
use App\Services\StockInService;
use App\Services\TruckAvailabilityService;
use Carbon\Carbon;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\PurchaseFlows;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Part 24 (November 20, 2026) system fixes:
 * 1. Sales: a delivery truck shows its max and available capacity, and an order can't overload it.
 * 2. Sales: a Garage to Client order's delivery status is updated with a dropdown (Pending / Out for Delivery /
 *    Delivered) and the change persists; Delivered deducts once and is final.
 * 3. Sales: a Depot to Client order can take fuel left in several purchases (one line each), without the same purchase
 *    twice and without overselling; its lift delivers each line from its own purchase.
 * 4. Ledger / Fuel Lifting: a receipt upload that could not be stored is reported (it used to "succeed" with no file);
 *    a stored receipt is linked to its lift and can be viewed or downloaded.
 * 5. Stock In: Sold QTY counts delivered sales only; undelivered orders are Reserved, and nothing is deducted twice.
 * 6. Dashboard: Purchased = Delivered + Reserved + Available, read from the same stock-in rows.
 */
class Part24FixesTest extends AuditTestCase
{
    use PurchaseFlows;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 09:00:00');
    }

    // ----- helpers ---------------------------------------------------------------------------------------------

    /**
     * Premium in garage Tank 1: a 5,000 L lift, 3,000 L unloaded to the tank (2,000 L stay on the audit truck).
     *
     * @param  array<string, mixed>  $a
     */
    private function premiumInGarage(array $a, float $toTank = 3000): int
    {
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 5000]])->assertSessionHasNoErrors();
        $haul = $this->lastHaulId();
        $this->completeLift($a, $haul);
        $tank = (int) DB::table('storage_locations')->where('type', 'garage')->where('status', 'active')->where('fuel_type_id', $this->fuel('PREM'))->where('tank_number', 1)->value('id');
        $this->actingAs($a['admin'])->post(route('admin.fuel-lifting.hauls.unload-to-garage', $haul), ['unload' => [$haul => [$tank => $toTank]]])->assertSessionHasNoErrors();

        return $tank;
    }

    /** @param  array<string, mixed>  $a */
    private function garageOrder(array $a, int $tank, float $liters, int $truckId)
    {
        return $this->recordOrder($a, ['purchase_item_id' => 'tank:'.$tank, 'quantity_liters' => $liters, 'unit_price' => 70], [
            'delivery_type' => 'garage_to_client', 'delivery_truck_id' => $truckId, 'delivery_driver_user_id' => $a['driver']->id, 'delivery_date' => '2026-11-21 10:00',
        ]);
    }

    private function truck(string $code, float $capacity): int
    {
        return (int) DB::table('trucks')->insertGetId(['truck_code' => $code, 'name' => 'Truck '.$code, 'capacity_liters' => $capacity, 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function stockIn(int $purchaseItemId): object
    {
        return app(StockInService::class)->query()->where('purchase_item_id', $purchaseItemId)->first();
    }

    // ----- 1. Truck capacity -------------------------------------------------------------------------------------

    public function test_delivery_trucks_show_their_capacity_and_an_order_cannot_overload_them(): void
    {
        $a = $this->actors();
        $tank = $this->premiumInGarage($a);
        $small = $this->truck('TRK-SMALL', 1000);

        // The audit truck (30,000 L) still carries the 2,000 L its lift did not unload.
        $trucks = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->viewData('deliveryTrucks')->keyBy('id');
        $this->assertSame([30000.0, 2000.0, 28000.0], [$trucks[$a['truck']]->capacity_liters, $trucks[$a['truck']]->loaded_liters, $trucks[$a['truck']]->available_liters]);
        $this->assertSame([1000.0, 0.0, 1000.0], [$trucks[$small]->capacity_liters, $trucks[$small]->loaded_liters, $trucks[$small]->available_liters]);
        $html = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->getContent();
        // Part 25 #1: the dropdown shows the maximum capacity only (the figures above still drive the server check).
        $this->assertStringContainsString('Truck TRK-SMALL – Max 1,000 L</option>', $html);
        $this->assertStringNotContainsString('data-truck-capacity-summary', $html);

        // More than the truck can carry is refused, and nothing is saved.
        $this->garageOrder($a, $tank, 1500, $small)->assertSessionHasErrors(['delivery_truck_id' => 'Truck TRK-SMALL can carry at most 1,000.00 L. This order needs 1,500.00 L.']);
        $this->assertSame(0, DB::table('sales')->count());

        // Exactly the capacity fits; the next order on that truck no longer does.
        $this->garageOrder($a, $tank, 1000, $small)->assertSessionHasNoErrors();
        $first = $this->lastSaleId();
        $this->garageOrder($a, $tank, 500, $small)->assertSessionHasErrors('delivery_truck_id');
        $this->assertSame(1, DB::table('sales')->count());
        $this->assertSame(0.0, app(TruckAvailabilityService::class)->deliveryCapacities([$small])[$small]->available_liters);

        // Editing the order on its own truck does not count its liters twice; moving it to a full truck is refused.
        $sale = DB::table('sales')->where('id', $first)->first();
        $edit = fn (int $truckId) => $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.update', $first), [
            'edit_sale_id' => $first, 'customer_id' => $sale->customer_id, 'sale_date' => $sale->sale_date, 'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment',
            'sales_order_number' => 'SO-P24-CAP', 'delivery_truck_id' => $truckId, 'delivery_driver_user_id' => $a['driver']->id, 'delivery_date' => '2026-11-21 10:00',
        ]);
        $edit($small)->assertSessionHasNoErrors();
        $this->assertSame('SO-P24-CAP', DB::table('sales')->where('id', $first)->value('sales_order_number'));
        $tiny = $this->truck('TRK-TINY', 500);
        $edit($tiny)->assertSessionHasErrors('delivery_truck_id');
        $this->assertSame($small, (int) DB::table('sales')->where('id', $first)->value('delivery_truck_id'));

        // Once delivered, the order no longer holds the truck.
        $this->actingAs($a['sales'])->patch(route('sales-officer.sales.garage-delivery.update', $first), ['garage_delivery_status' => 'delivered'])->assertSessionHasNoErrors();
        $this->assertSame(1000.0, app(TruckAvailabilityService::class)->deliveryCapacities([$small])[$small]->available_liters);
    }

    public function test_assigning_a_garage_tank_source_checks_the_delivery_truck_capacity(): void
    {
        $a = $this->actors();
        $tank = $this->premiumInGarage($a);
        $small = $this->truck('TRK-SMALL', 1000);
        $this->recordOrder($a, ['purchase_item_id' => $this->purchaseLine($a, 'PREM', 5000), 'quantity_liters' => 2000, 'unit_price' => 70])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $assign = fn (int $truckId) => $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->post(route('sales-officer.sales.assign-source', $saleId), [
            'source_type' => 'garage_tank', 'source_id' => $tank, 'delivery_truck_id' => $truckId, 'delivery_driver_user_id' => $a['driver']->id, 'delivery_date' => '2026-11-21 10:00',
        ]);

        $assign($small)->assertSessionHasErrors('delivery_truck_id');
        $this->assertSame('standard', DB::table('sales')->where('id', $saleId)->value('delivery_type'));

        $assign($a['truck'])->assertSessionHasNoErrors();
        $this->assertSame(['garage_to_client', (int) $a['truck']], [DB::table('sales')->where('id', $saleId)->value('delivery_type'), (int) DB::table('sales')->where('id', $saleId)->value('delivery_truck_id')]);
        // 2,000 L left on the truck from its lift + this 2,000 L order.
        $this->assertSame(4000.0, app(TruckAvailabilityService::class)->deliveryCapacities([$a['truck']])[$a['truck']]->loaded_liters);
    }

    public function test_standard_order_truck_selection_shows_plate_and_prevents_overloading(): void
    {
        $a = $this->actors();
        $diesel = $this->purchaseLine($a, 'DSL', 20000);
        $smallId = (int) DB::table('trucks')->insertGetId([
            'truck_code' => 'TRUCK-901',
            'plate_number' => 'ABC-1234',
            'name' => 'Small Tanker',
            'capacity_liters' => 1500,
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([$a['sales'], $a['admin']] as $user) {
            $route = $user->role === 'admin' ? 'admin.sales' : 'sales-officer.sales';
            $html = $this->actingAs($user)->get(route($route))->assertOk()->getContent();
            $this->assertStringContainsString('Small Tanker (ABC-1234) – Max 1,500 L</option>', $html);
        }

        // Overloading a Standard order's chosen truck is rejected.
        $this->recordOrder($a, ['purchase_item_id' => $diesel, 'quantity_liters' => 2000, 'unit_price' => 60], [
            'delivery_type' => 'standard',
            'delivery_truck_id' => $smallId,
        ])->assertSessionHasErrors('delivery_truck_id');
        $this->assertSame(0, DB::table('sales')->count());

        // Within capacity saves cleanly and reserves the truck's capacity.
        $this->recordOrder($a, ['purchase_item_id' => $diesel, 'quantity_liters' => 1000, 'unit_price' => 60], [
            'delivery_type' => 'standard',
            'delivery_truck_id' => $smallId,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sales', [
            'id' => $this->lastSaleId(),
            'delivery_type' => 'standard',
            'delivery_truck_id' => $smallId,
        ]);
        $this->assertSame(500.0, app(TruckAvailabilityService::class)->deliveryCapacities([$smallId])[$smallId]->available_liters);
    }

    // ----- 2. Garage to Client delivery status dropdown ----------------------------------------------------------

    public function test_sales_and_admin_update_a_garage_order_delivery_status_with_the_dropdown_and_it_persists(): void
    {
        $a = $this->actors();
        $tank = $this->premiumInGarage($a);
        $this->garageOrder($a, $tank, 1000, $a['truck'])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();

        // The order's modal has the dropdown with the three statuses, Pending selected.
        $html = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $this->assertStringContainsString('action="'.route('sales-officer.sales.garage-delivery.update', $saleId).'"', $html);
        $this->assertMatchesRegularExpression('/name="garage_delivery_status"[^>]*>\s*<option value="pending" selected>Pending<\/option>\s*<option value="out_for_delivery"\s*>Out for Delivery<\/option>\s*<option value="delivered"\s*>Delivered<\/option>/', $html);

        // Sales Officer: Out for Delivery. Saved with who and when; nothing is deducted yet.
        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.garage-delivery.update', $saleId), ['garage_delivery_status' => 'out_for_delivery', 'idempotency_key' => $this->uuid()])
            ->assertSessionHasNoErrors()->assertRedirect(route('sales-officer.sales'));
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'delivery_status' => 'out_for_delivery', 'delivery_status_updated_by' => $a['sales']->id, 'updated_by' => $a['sales']->id]);
        $this->assertSame(0, DB::table('stock_outs')->where('sale_id', $saleId)->count());
        $this->assertSame('out_for_delivery', collect($this->actingAs($a['sales'])->get(route('sales-officer.sales'))->viewData('sales')->items())->firstWhere('id', $saleId)['delivery_status']);

        // The same status again is refused; back to Pending is allowed.
        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.garage-delivery.update', $saleId), ['garage_delivery_status' => 'out_for_delivery'])
            ->assertSessionHasErrors(['garage_delivery_status' => 'The order is already Out For Delivery.']);
        $this->actingAs($a['sales'])->patch(route('sales-officer.sales.garage-delivery.update', $saleId), ['garage_delivery_status' => 'pending'])->assertSessionHasNoErrors();
        $this->assertSame('pending', DB::table('sales')->where('id', $saleId)->value('delivery_status'));

        // Admin: Delivered releases the order from its tank once; a resent request (same key) saves nothing more.
        $key = $this->uuid();
        foreach ([1, 2] as $attempt) {
            $this->actingAs($a['admin'])->from(route('admin.sales'))->patch(route('admin.sales.garage-delivery.update', $saleId), ['garage_delivery_status' => 'delivered', 'idempotency_key' => $key])
                ->assertSessionHasNoErrors()->assertRedirect(route('admin.sales'));
        }
        $sale = DB::table('sales')->where('id', $saleId)->first();
        $this->assertSame(['delivered', (int) $a['admin']->id], [$sale->delivery_status, (int) $sale->delivery_status_updated_by]);
        $this->assertNotNull($sale->delivered_at);
        $this->assertEquals(1000, DB::table('stock_outs')->where('sale_id', $saleId)->where('status', 'released')->sum('quantity_liters'));
        $this->assertSame(1, DB::table('stock_outs')->where('sale_id', $saleId)->count());

        // Delivered is final, and the driver sees the same status.
        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.garage-delivery.update', $saleId), ['garage_delivery_status' => 'pending'])
            ->assertSessionHasErrors(['garage_delivery_status' => 'A delivered sale cannot be moved back because its inventory was already released.']);
        $this->assertStringNotContainsString(route('sales-officer.sales.garage-delivery.update', $saleId), $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->getContent());
        $this->assertSame('delivered', collect($this->actingAs($a['driver'])->get(route('driver.fuel-lifting.deliveries'))->viewData('deliveryRows'))->firstWhere('id', $saleId)['delivery_status']);
    }

    public function test_the_dropdown_is_for_garage_orders_only_and_the_driver_still_updates_their_delivery(): void
    {
        $a = $this->actors();
        $tank = $this->premiumInGarage($a);
        $this->recordOrder($a, ['purchase_item_id' => $this->purchaseLine($a, 'DSL', 5000), 'quantity_liters' => 1000, 'unit_price' => 60])->assertSessionHasNoErrors();
        $standard = $this->lastSaleId();
        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.garage-delivery.update', $standard), ['garage_delivery_status' => 'delivered'])
            ->assertSessionHasErrors(['garage_delivery_status' => 'Only a Garage to Client order\'s delivery status is updated here.']);
        $this->assertSame('pending', DB::table('sales')->where('id', $standard)->value('delivery_status'));

        $this->garageOrder($a, $tank, 1000, $a['truck'])->assertSessionHasNoErrors();
        $garage = $this->lastSaleId();
        foreach (['inventory', 'dispatch', 'driver'] as $role) {
            $this->actingAs($a[$role])->patch(route('admin.sales.garage-delivery.update', $garage), ['garage_delivery_status' => 'delivered'])->assertForbidden();
        }
        $this->actingAs($a['sales'])->patch(route('sales-officer.sales.garage-delivery.update', $garage), ['garage_delivery_status' => 'bogus'])->assertSessionHasErrors('garage_delivery_status');

        $this->driverDelivery($garage, ['delivery_status' => 'out_for_delivery'])->assertSessionHasNoErrors();
        $this->assertSame('out_for_delivery', DB::table('sales')->where('id', $garage)->value('delivery_status'));
        $this->actingAs($a['sales'])->patch(route('sales-officer.sales.garage-delivery.update', $garage), ['garage_delivery_status' => 'delivered'])->assertSessionHasNoErrors();
        $this->assertSame('delivered', DB::table('sales')->where('id', $garage)->value('delivery_status'));
    }

    // ----- 3. Depot to Client order from several purchases -------------------------------------------------------

    public function test_a_depot_order_takes_fuel_left_in_several_purchases_without_double_allocation_or_overselling(): void
    {
        $a = $this->actors();
        $first = $this->purchaseLine($a, 'DSL', 10000);
        $second = $this->purchaseLine($a, 'DSL', 10000);
        // 7,000 L of the first purchase are promised to another order: 3,000 L are left in it.
        $this->recordOrder($a, ['purchase_item_id' => $first, 'quantity_liters' => 7000, 'unit_price' => 60])->assertSessionHasNoErrors();
        $this->assertSame([3000.0, 10000.0], [$this->available($first), $this->available($second)]);

        // Both purchases with stock are offered; the Record Sales form can add purchase lines.
        $page = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk();
        $this->assertEqualsCanonicalizing([$first, $second], collect($page->viewData('purchaseOptions'))->pluck('id')->map(fn ($id) => (int) $id)->all());
        // (The Premium purchase recorded below joins the options only after this check.)
        $page->assertSee('data-add-sale-line', false)->assertSee('data-sale-line-template', false);

        $depot = fn (array $lines) => $this->recordOrder($a, $lines[0], ['delivery_type' => 'depot_to_client', 'items' => $lines]);
        // The same purchase twice is refused (raise the line instead).
        $depot([['purchase_item_id' => $first, 'quantity_liters' => 1000, 'unit_price' => 62], ['purchase_item_id' => $first, 'quantity_liters' => 1000, 'unit_price' => 62]])
            ->assertSessionHasErrors(['items.1.purchase_item_id' => 'This purchase is already on line 1 of this order. Change that line\'s quantity instead of adding it twice.']);
        // Another fuel cannot join the order: its lift could not carry both.
        $premium = $this->purchaseLine($a, 'PREM', 10000);
        $depot([['purchase_item_id' => $first, 'quantity_liters' => 1000, 'unit_price' => 62], ['purchase_item_id' => $premium, 'quantity_liters' => 1000, 'unit_price' => 70]])
            ->assertSessionHasErrors(['items.1.purchase_item_id' => 'Every purchase line of a Depot to Client order must be the same fuel as line 1 (one lift carries one fuel).']);
        // A line larger than its purchase still has is refused.
        $depot([['purchase_item_id' => $first, 'quantity_liters' => 3500, 'unit_price' => 62], ['purchase_item_id' => $second, 'quantity_liters' => 1500, 'unit_price' => 62]])
            ->assertSessionHasErrors('items.0.quantity_liters');
        $this->assertSame(1, DB::table('sales')->count());

        // 5,000 L: the 3,000 L left in the first purchase + 2,000 L of the second.
        $depot([['purchase_item_id' => $first, 'quantity_liters' => 3000, 'unit_price' => 62], ['purchase_item_id' => $second, 'quantity_liters' => 2000, 'unit_price' => 62]])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $this->assertSame([[$first, 3000.0], [$second, 2000.0]], DB::table('sale_items')->where('sale_id', $saleId)->orderBy('id')->get()->map(fn ($item) => [(int) $item->purchase_item_id, (float) $item->quantity_liters])->all());
        $this->assertEquals(310000, DB::table('sale_items')->where('sale_id', $saleId)->sum('line_total'));
        $this->assertSame([0.0, 8000.0], [$this->available($first), $this->available($second)]);
        // Nothing is left in the first purchase for another order.
        $this->recordOrder($a, ['purchase_item_id' => $first, 'quantity_liters' => 1, 'unit_price' => 60])->assertSessionHasErrors('items.0.quantity_liters');

        // The lift must carry both purchases.
        $this->scheduleLift($a, [['purchase_item_id' => $second, 'quantity_liters' => 5000]], [$saleId])->assertSessionHasErrors('deliveries.0.sale_id');
        $this->assertSame(0, DB::table('haul_allocations')->where('sale_id', $saleId)->count());

        $this->scheduleLift($a, [['purchase_item_id' => $first, 'quantity_liters' => 3000], ['purchase_item_id' => $second, 'quantity_liters' => 2000]], [$saleId])->assertSessionHasNoErrors();
        $rows = DB::table('haul_allocations')->join('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')->where('haul_allocations.sale_id', $saleId)->orderBy('haul_allocations.id')->get(['haul_allocations.id', 'hauls.id as haul_id', 'hauls.purchase_item_id', 'haul_allocations.quantity_liters']);
        $this->assertSame([[$first, 3000.0], [$second, 2000.0]], $rows->map(fn ($row) => [(int) $row->purchase_item_id, (float) $row->quantity_liters])->all());
        // On the lift, the order no longer waits in either depot.
        $this->assertSame('depot_to_client', DB::table('sales')->where('id', $saleId)->value('delivery_type'));

        // Lifted: each line is released once, from its own purchase, through the lift line carrying that purchase.
        $this->completeLift($a, (int) $rows[0]->haul_id);
        $this->assertSame('delivered', DB::table('sales')->where('id', $saleId)->value('delivery_status'));
        $releases = DB::table('stock_outs')->where('sale_id', $saleId)->where('status', 'released')->orderBy('id')->get();
        $this->assertSame([[$first, 3000.0, (int) $rows[0]->id], [$second, 2000.0, (int) $rows[1]->id]], $releases->map(fn ($r) => [(int) $r->purchase_item_id, (float) $r->quantity_liters, (int) $r->haul_allocation_id])->all());
        $this->assertSame(['delivered', 'delivered'], DB::table('haul_allocations')->where('sale_id', $saleId)->orderBy('id')->pluck('status')->all());
        // Stock adds up: no double deduction, nothing left on the truck.
        $lines = app(PurchaseStockService::class)->lines([$first, $second]);
        $this->assertSame([7000.0, 7000.0, 0.0, false], [$lines[$first]->on_hand, $lines[$first]->reserved, $lines[$first]->on_truck, $lines[$first]->requires_reconciliation]);
        $this->assertSame([8000.0, 0.0, 8000.0, 0.0, false], [$lines[$second]->on_hand, $lines[$second]->reserved, $lines[$second]->available, $lines[$second]->on_truck, $lines[$second]->requires_reconciliation]);
        $this->assertSame(0.0, app(TruckAvailabilityService::class)->leftoverOnTruck((int) $a['truck']));
    }

    // ----- Record Sales modal layout (restored) -------------------------------------------------------------------

    /** The Record Sales modal's HTML (from its opening tag to the next modal). */
    private function recordSalesModal(string $html): string
    {
        $from = strpos($html, 'id="so-sales-add"');
        $this->assertNotFalse($from, 'Missing the Record Sales modal');

        return substr($html, $from, strpos($html, 'so-sales-edit-', $from) ?: strlen($html) - $from);
    }

    public function test_the_record_sales_modal_keeps_its_original_layout_for_sales_and_admin(): void
    {
        $a = $this->actors();
        $this->purchaseLine($a, 'DSL', 10000);

        foreach ([[$a['sales'], 'sales-officer'], [$a['admin'], 'admin']] as [$user, $prefix]) {
            $modal = $this->recordSalesModal($this->actingAs($user)->get(route($prefix.'.sales'))->assertOk()->getContent());
            $form = substr($modal, 0, strpos($modal, '<template data-sale-line-template>'));
            $rest = substr($modal, strpos($modal, '</template>'));

            // The original fields, in their original order: order line (with Truck Selection under Fuel Type / Fuel Price), payment details, delivery details, buttons.
            $this->assertSame(1, preg_match_all('/Delivery Type<\/label>.*Customer<\/label>.*Sales Order<\/label>.*Order Date<\/label>.*Qty Ordered<\/label>.*Fuel Type<\/label>.*Fuel Price<\/label>.*Truck Selection<\/label>.*data-truck-capacity-hint.*Ordered Amount.*Fuel Amount.*Total Amount.*Fuel Total.*Line Total/s', $form));
            $this->assertMatchesRegularExpression('/Order Payment Details.*Amount Payment<\/label>.*Reference Number<\/label>.*Date of Payment<\/label>.*Payment Terms<\/label>.*Payment Method<\/label>.*Due Date<\/label>.*Payment Status<\/label>.*Delivery Details.*Driver Name<\/label>.*Delivery Date<\/label>.*Add Order<\/button>.*Cancel<\/button>/s', $rest);
            // One order line on open; no F1 column; the Fuel Total | Line Total bar.
            $this->assertSame(1, preg_match_all('/data-sales-item[\s>]/', $form));
            $this->assertStringContainsString('sale-line-totals sale-line-totals-no-f1', $form);
            $this->assertStringNotContainsString('data-f1-row', $form);
            // Part 24 #3 stays, as one compact control (hidden until Depot to Client) with no extra text in the card.
            $this->assertMatchesRegularExpression('/<div class="sale-add-line-row" data-sale-add-line-row\s+hidden\s*>\s*<button class="btn btn-small btn-secondary" type="button" data-add-sale-line[^>]*>\+ Add another purchase<\/button>\s*<span class="visually-hidden"/', $form);
            $this->assertStringNotContainsString('<p class="table-help">Fuel left in another purchase', $modal);
            // The added line repeats the original row: Qty Ordered | Fuel Type | Fuel Price, then the amounts and totals.
            $template = substr($modal, strpos($modal, '<template data-sale-line-template>'), strpos($modal, '</template>') - strpos($modal, '<template data-sale-line-template>'));
            $this->assertMatchesRegularExpression('/sale-extra-line-header.*Qty Ordered<\/label>.*Fuel Type<\/label>.*Fuel Price<\/label>.*Ordered Amount.*Fuel Amount.*Total Amount.*Fuel Total.*Line Total/s', $template);
            $this->assertStringContainsString('name="items[__INDEX__][quantity_liters]"', $template);
        }

        // The extra line header spans the whole grid row (it used to push Fuel Price onto its own row).
        $css = file_get_contents(resource_path('css/app.css'));
        $this->assertMatchesRegularExpression('/\.sale-order-line > \.sale-extra-line-header \{\s*grid-column: 1 \/ -1;/', $css);
        // Phones: Record Sales' two totals share the bar (no empty third column squeezing Line Total).
        $this->assertMatchesRegularExpression('/@media \(max-width: 767px\) \{.*\.admin-modal-sales-wide \.sale-order-line > \.sale-line-totals-no-f1 \{\s*grid-template-columns: repeat\(2, minmax\(0, 1fr\)\);/s', file_get_contents(resource_path('css/part9.css')));
    }

    public function test_a_failed_depot_order_reopens_with_its_extra_purchase_lines(): void
    {
        $a = $this->actors();
        $first = $this->purchaseLine($a, 'DSL', 10000);
        $second = $this->purchaseLine($a, 'DSL', 10000);

        $this->recordOrder($a, ['purchase_item_id' => $first, 'quantity_liters' => 3000, 'unit_price' => 62], [
            'delivery_type' => 'depot_to_client', 'customer_id' => 999999,
            'items' => [['purchase_item_id' => $first, 'quantity_liters' => 3000, 'unit_price' => 62], ['purchase_item_id' => $second, 'quantity_liters' => 2500, 'unit_price' => 63]],
        ])->assertSessionHasErrors('customer_id');

        $modal = $this->recordSalesModal($this->actingAs($a['sales'])->get(route('sales-officer.sales'))->getContent());
        $form = substr($modal, 0, strpos($modal, '<template data-sale-line-template>'));
        $this->assertSame(2, preg_match_all('/data-sales-item[\s>]/', $form));
        $this->assertMatchesRegularExpression('/data-sale-extra-line data-line-index="1".*name="items\[1\]\[quantity_liters\]"[^>]*value="2500"/s', $form);
        $this->assertMatchesRegularExpression('/<option value="'.$second.'"[^>]*selected/', substr($form, strpos($form, 'data-line-index="1"')));
        $this->assertMatchesRegularExpression('/<div class="sale-add-line-row" data-sale-add-line-row\s*>/', $form);
    }

    // ----- 4. Lifting receipts -----------------------------------------------------------------------------------

    public function test_a_receipt_that_cannot_be_stored_is_reported_and_not_linked_to_the_lift(): void
    {
        $a = $this->actors();
        $line = $this->purchaseLine($a, 'DSL', 10000);
        $this->scheduleLift($a, [['purchase_item_id' => $line, 'quantity_liters' => 4000]])->assertSessionHasNoErrors();
        $haulId = $this->lastHaulId();
        $this->completeLift($a, $haulId);

        // A disk that cannot be written (storeAs gives false, as the local disk does when it does not throw).
        $disk = \Mockery::mock(Filesystem::class);
        $disk->shouldReceive('putFileAs')->andReturn(false);
        $disk->shouldReceive('exists')->andReturn(false);
        $disk->shouldReceive('delete')->andReturn(true);
        Storage::shouldReceive('disk')->with('local')->andReturn($disk);

        $this->actingAs($a['driver'])->from(route('driver.fuel-lifting.hauled'))
            ->post(route('driver.fuel-lifting.hauls.withdrawal-receipt.store', $haulId), ['withdrawal_receipt' => UploadedFile::fake()->image('receipt.jpg', 600, 800)])
            ->assertSessionHasErrors('withdrawal_receipt')->assertSessionMissing('status');
        $this->actingAs($a['driver'])->postJson(route('driver.fuel-lifting.hauls.withdrawal-receipt.store', $haulId), ['withdrawal_receipt' => UploadedFile::fake()->image('receipt.jpg', 600, 800)])
            ->assertStatus(500)->assertJsonPath('errors.withdrawal_receipt.0', fn (string $message): bool => str_contains($message, 'could not be saved on the server'));
        $haul = DB::table('hauls')->where('id', $haulId)->first();
        $this->assertSame([null, null], [$haul->withdrawal_receipt_path, $haul->withdrawal_receipt_uploaded_at]);
    }

    public function test_a_stored_receipt_is_linked_to_its_lift_and_can_be_viewed_or_downloaded(): void
    {
        Storage::fake('local');
        $a = $this->actors();
        $line = $this->purchaseLine($a, 'DSL', 10000);
        $this->scheduleLift($a, [['purchase_item_id' => $line, 'quantity_liters' => 4000]])->assertSessionHasNoErrors();
        $haulId = $this->lastHaulId();
        $this->completeLift($a, $haulId);

        $this->actingAs($a['driver'])->postJson(route('driver.fuel-lifting.hauls.withdrawal-receipt.store', $haulId), ['withdrawal_receipt' => UploadedFile::fake()->image('receipt.png', 600, 800)])
            ->assertOk()->assertJsonPath('message', 'Withdrawal receipt uploaded successfully.');
        $path = (string) DB::table('hauls')->where('id', $haulId)->value('withdrawal_receipt_path');
        $this->assertStringStartsWith('withdrawal-receipts/', $path);
        Storage::disk('local')->assertExists($path);

        $url = route('withdrawal-receipts.show', $haulId);
        $this->actingAs($a['inventory'])->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Content-Disposition', 'inline; filename="'.Str::slug((string) DB::table('hauls')->where('id', $haulId)->value('haul_code')).'-withdrawal.png"');
        $this->assertStringStartsWith('attachment;', (string) $this->actingAs($a['admin'])->get(route('withdrawal-receipts.show', ['haul' => $haulId, 'download' => 1]))->assertOk()->headers->get('Content-Disposition'));

        // The ledger lift card shows it, with view and download links.
        $row = collect($this->actingAs($a['inventory'])->get(route('inventory-officer.ledger.transactions'))->assertOk()->viewData('transactions'))->firstWhere('purchase_item_id', $line);
        $receipt = $row['lifts'][0]['receipt'];
        $this->assertStringContainsString($url, $receipt['url']);
        $this->assertSame(route('withdrawal-receipts.show', ['haul' => $haulId, 'download' => 1]), $receipt['download_url']);
        $this->actingAs($a['inventory'])->get(route('inventory-officer.ledger.transactions'))->assertSee('Download receipt');
    }

    // ----- 5. Stock In: Sold QTY on delivery only ----------------------------------------------------------------

    public function test_sold_quantity_changes_only_when_a_sale_is_delivered_and_reserved_liters_are_tracked_separately(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $figures = fn (): array => (fn (object $row): array => [(float) $row->stock_out, (float) $row->reserved, (float) $row->remaining, (float) $row->available])($this->stockIn($premium));

        // Recorded (Pending): reserved, not sold.
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 5000, 'unit_price' => 65])->assertSessionHasNoErrors();
        $standard = $this->lastSaleId();
        $this->assertSame([0.0, 5000.0, 20000.0, 15000.0], $figures());
        // Out for Delivery is not Delivered either.
        $this->driverDelivery($standard, ['delivery_status' => 'out_for_delivery'])->assertSessionHasNoErrors();
        $this->assertSame([0.0, 5000.0, 20000.0, 15000.0], $figures());

        // A Depot to Client order on a lift stays reserved until the lift is Lifted.
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 3000, 'unit_price' => 65], ['delivery_type' => 'depot_to_client'])->assertSessionHasNoErrors();
        $depot = $this->lastSaleId();
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 3000]], [$depot])->assertSessionHasNoErrors();
        $this->assertSame([0.0, 8000.0, 20000.0, 12000.0], $figures());

        // Delivered: the liters move from Reserved to Sold, once.
        $this->driverDelivery($standard)->assertSessionHasNoErrors();
        $this->assertSame([5000.0, 3000.0, 15000.0, 12000.0], $figures());
        // Marking it Delivered again releases nothing more.
        $this->driverDelivery($standard)->assertSessionHasNoErrors();
        $this->assertSame([5000.0, 3000.0, 15000.0, 12000.0], $figures());
        $this->assertSame(1, DB::table('stock_outs')->where('sale_id', $standard)->count());
        $this->completeLift($a, $this->lastHaulId());
        $this->assertSame([8000.0, 0.0, 12000.0, 12000.0], $figures());
        $this->assertEquals(8000, DB::table('stock_outs')->where('status', 'released')->sum('quantity_liters'));

        // A cancelled order gives its reserved liters back and never counts as sold.
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 2000, 'unit_price' => 65])->assertSessionHasNoErrors();
        $this->assertSame([8000.0, 2000.0, 12000.0, 10000.0], $figures());
        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.cancel', $this->lastSaleId()), ['cancel_reason' => 'duplicate_entry'])->assertSessionHasNoErrors();
        $this->assertSame([8000.0, 0.0, 12000.0, 12000.0], $figures());

        // The Stock-In tab shows Sold, Reserved and Available as their own columns.
        $html = $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory.stock-in'))->assertOk()->getContent();
        foreach (['<th>Current QTY</th>', '<th>Sold QTY</th>', '<th>Reserved</th>', '<th>Available</th>'] as $column) {
            $this->assertStringContainsString($column, $html);
        }
    }

    public function test_fully_reserved_stock_has_its_own_status_and_filter(): void
    {
        $a = $this->actors();
        $diesel = $this->purchaseLine($a, 'DSL', 3000);
        $this->recordOrder($a, ['purchase_item_id' => $diesel, 'quantity_liters' => 3000, 'unit_price' => 60])->assertSessionHasNoErrors();

        $this->assertSame('reserved', $this->stockIn($diesel)->stock_status);
        $this->assertSame([$diesel], app(StockInService::class)->query(['status' => 'reserved'])->pluck('purchase_item_id')->map(fn ($id) => (int) $id)->all());
        $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory.stock-in', ['status' => 'reserved']))->assertOk()->assertSee('Fully Reserved');

        $this->driverDelivery($this->lastSaleId())->assertSessionHasNoErrors();
        $this->assertSame('sold_out', $this->stockIn($diesel)->stock_status);
    }

    // ----- 6. Dashboard reconciliation ---------------------------------------------------------------------------

    public function test_dashboard_inventory_reconciles_with_stock_in_stock_out_and_sales(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $diesel = $this->purchaseLine($a, 'DSL', 10000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 4000, 'unit_price' => 65])->assertSessionHasNoErrors();
        $this->driverDelivery($this->lastSaleId())->assertSessionHasNoErrors();
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 1500, 'unit_price' => 65])->assertSessionHasNoErrors();
        $this->recordOrder($a, ['purchase_item_id' => $diesel, 'quantity_liters' => 2500, 'unit_price' => 60])->assertSessionHasNoErrors();
        $this->recordOrder($a, ['purchase_item_id' => $diesel, 'quantity_liters' => 1000, 'unit_price' => 60])->assertSessionHasNoErrors();
        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.cancel', $this->lastSaleId()), ['cancel_reason' => 'duplicate_entry'])->assertSessionHasNoErrors();

        $inventory = app(DashboardService::class)->inventory();
        $rec = $inventory['reconciliation'];
        $this->assertSame(['purchased' => 30000.0, 'delivered' => 4000.0, 'reserved' => 4000.0, 'available' => 22000.0, 'on_hand' => 26000.0, 'over_delivered' => 0.0], $rec);
        $this->assertSame($rec['purchased'], round($rec['delivered'] + $rec['reserved'] + $rec['available'], 2));

        // The same figures as the Stock-In rows, the Stock Out releases and the open sales.
        $rows = app(StockInService::class)->records()->where('status', 'active');
        $this->assertSame([$rec['delivered'], $rec['reserved'], $rec['available'], $rec['on_hand']], [(float) $rows->sum('stock_out'), (float) $rows->sum('reserved'), (float) $rows->sum('available'), (float) $rows->sum('remaining')]);
        $this->assertEquals($rec['delivered'], DB::table('stock_outs')->where('status', 'released')->sum('quantity_liters'));
        $openLiters = (float) DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')->where('sales.status', '!=', 'cancelled')->where('sales.delivery_status', '!=', 'delivered')->sum('sale_items.quantity_liters');
        $this->assertSame($rec['reserved'], $openLiters);

        // Cards and the per-fuel chart.
        $cards = collect($inventory['cards'])->mapWithKeys(fn (array $card): array => [$card[0] => $card[1]]);
        $this->assertSame(['26.00 KL', '4.00 KL', '22.00 KL'], [$cards['Total Inventory in Stock'], $cards['Reserved (Pending Delivery)'], $cards['Available to Sell']]);
        $chart = collect($inventory['reconciliationChart']['datasets'])->mapWithKeys(fn (array $set): array => [$set['label'] => $set['data']]);
        $this->assertSame(['Premium', 'Unleaded', 'Diesel', 'F1'], $inventory['reconciliationChart']['labels']);
        $this->assertSame([[4000.0, 0.0, 0.0, 0.0], [1500.0, 0.0, 2500.0, 0.0], [14500.0, 0.0, 7500.0, 0.0]], [$chart['Delivered (Sold)'], $chart['Reserved (Pending Delivery)'], $chart['Available']]);
        // Reserved / on hand.
        $this->assertSame(round(4000 / 26000 * 100, 2), $inventory['totals']['stock_out_rate']);

        // Part 26 #1: the reconciliation chart is no longer on the dashboard; its figures above stay the same.
        $this->actingAs($a['admin'])->get(route('admin.dashboard'))->assertOk()
            ->assertDontSee('Inventory Reconciliation (Purchased = Delivered + Reserved + Available)')
            ->assertDontSee('data-inventory-reconciliation', false);
    }
}
