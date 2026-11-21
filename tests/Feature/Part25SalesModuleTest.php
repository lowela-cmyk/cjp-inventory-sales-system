<?php

namespace Tests\Feature;

use App\Services\PurchaseStockService;
use App\Services\SaleConfirmationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PurchaseFlows;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Part 25 (November 21, 2026): Sales module.
 * 1. Truck Selection shows each truck's maximum capacity only; the capacity validation stays on the server.
 * 2. Each fuel type shows its Purchase ID; a sale takes fuel straight from a purchase, lifted or not.
 * 3. Garage to Client keeps Truck Selection; Depot to Client has none and takes one or more Purchase IDs instead.
 * 4. A sale reserves its purchase's liters at once: Fuel Lifting sees the sale and its purchase, a lift (to the garage or
 *    another truck) cannot take reserved liters, and delivery deducts exactly the sold liters once.
 */
class Part25SalesModuleTest extends AuditTestCase
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

    /**
     * A Depot to Client order with one line per purchase ([purchase_item_id => liters]).
     *
     * @param  array<string, mixed>  $a
     * @param  array<int, float>  $lines
     */
    private function depotSale(array $a, array $lines, array $extra = [])
    {
        $items = [];
        foreach ($lines as $purchaseItemId => $liters) {
            $items[] = ['purchase_item_id' => $purchaseItemId, 'quantity_liters' => $liters, 'unit_price' => 62];
        }

        return $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->post(route('sales-officer.sales.store'), $extra + [
            'idempotency_key' => (string) Str::uuid(), 'form_context' => 'record-sale', 'delivery_type' => 'depot_to_client',
            'customer_id' => $a['customer'], 'sale_date' => '2026-11-21', 'items' => $items,
            'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment',
        ]);
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<int, float>  $lines  purchase_item_id => Amount to Lift
     * @param  array<int, int>  $saleIds
     */
    private function lift(array $a, int $truck, array $lines, array $saleIds = [])
    {
        $items = [];
        foreach ($lines as $purchaseItemId => $liters) {
            $items[] = ['purchase_item_id' => $purchaseItemId, 'quantity_liters' => $liters];
        }

        return $this->scheduleLift($a, $items, $saleIds, ['truck_id' => $truck]);
    }

    private function figures(int $purchaseItemId): array
    {
        $line = app(PurchaseStockService::class)->line($purchaseItemId);

        return [
            'reserved' => $line->reserved,
            'delivered' => $line->released,
            'lifted' => $line->lifted,
            'available' => $line->available,
            'remaining' => $line->on_hand,
            'in_depot' => $line->remaining_in_depot,
        ];
    }

    /** @param  array<string, mixed>  $a */
    private function premiumInGarage(array $a): int
    {
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 5000]])->assertSessionHasNoErrors();
        $haul = $this->lastHaulId();
        $this->completeLift($a, $haul);
        $tank = (int) DB::table('storage_locations')->where('type', 'garage')->where('status', 'active')->where('fuel_type_id', $this->fuel('PREM'))->where('tank_number', 1)->value('id');
        $this->actingAs($a['admin'])->post(route('admin.fuel-lifting.hauls.unload-to-garage', $haul), ['unload' => [$haul => [$tank => 5000]]])->assertSessionHasNoErrors();

        return $tank;
    }

    // ----- 1. Truck Selection: maximum capacity only ------------------------------------------------------------

    public function test_truck_selection_shows_only_the_maximum_capacity_and_keeps_the_capacity_check(): void
    {
        $a = $this->actors();
        $tank = $this->premiumInGarage($a);
        $small = $this->truck('TRK-SMALL', 1000);

        $html = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $this->assertStringContainsString('Truck TRK-SMALL – Max 1,000 L</option>', $html);
        foreach (['Loaded', 'Available Capacity', 'Remaining Capacity', 'data-truck-capacity-summary', 'data-loaded=', 'data-available="1000"', 'L available</option>'] as $gone) {
            $this->assertStringNotContainsString($gone, $html, $gone.' should no longer be shown');
        }

        // The server still refuses an order larger than the truck (Garage to Client).
        $garage = fn (float $liters) => $this->recordOrder($a, ['purchase_item_id' => 'tank:'.$tank, 'quantity_liters' => $liters, 'unit_price' => 70], [
            'delivery_type' => 'garage_to_client', 'delivery_truck_id' => $small, 'delivery_driver_user_id' => $a['driver']->id, 'delivery_date' => '2026-11-22 10:00',
        ]);
        $garage(1500)->assertSessionHasErrors('delivery_truck_id');
        $this->assertSame(0, DB::table('sales')->count());
        $garage(1000)->assertSessionHasNoErrors();
        // ...and counts what is already assigned to the truck.
        $garage(1)->assertSessionHasErrors('delivery_truck_id');
        $this->assertSame(1, DB::table('sales')->count());
    }

    // ----- 2. Fuel type with its Purchase ID ----------------------------------------------------------------------

    public function test_each_fuel_type_shows_its_purchase_id_in_the_sales_module(): void
    {
        $a = $this->actors();
        $line = $this->purchase($a, 'PUR-DSL-002', 100000);

        $html = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $this->assertStringContainsString('PUR-DSL-002 – DIESEL – 100,000 L in depot', $html);
        $this->assertStringContainsString('data-purchase-code="PUR-DSL-002"', $html);
        $this->assertStringContainsString('PUR-DSL-002 · Purchased 100,000 L · On lifts 0 L · Reserved 0 L · Delivered 0 L · Available 100,000 L', $html);

        $this->depotSale($a, [$line => 40000])->assertSessionHasNoErrors();
        $row = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->viewData('sales')->first();
        $this->assertSame('Diesel · PUR-DSL-002', $row['cells'][5]);
        $this->assertSame('Diesel · PUR-DSL-002', $row['details']['Fuel Type']);
        $this->assertSame('PUR-DSL-002', $row['details']['Purchase ID']);
    }

    // ----- 3. Truck Selection per delivery type ------------------------------------------------------------------

    public function test_depot_to_client_has_no_truck_selection_and_garage_to_client_keeps_it(): void
    {
        $a = $this->actors();
        $line = $this->purchase($a, 'PUR-DSL-002', 100000);

        // A truck sent with a Depot to Client order is ignored: its truck comes from the lift.
        $this->depotSale($a, [$line => 40000], ['delivery_truck_id' => $a['truck']])->assertSessionHasNoErrors();
        $this->assertNull(DB::table('sales')->where('id', $this->lastSaleId())->value('delivery_truck_id'));

        // After a failed Depot to Client submit, the form comes back on Depot to Client with the truck section hidden.
        $this->depotSale($a, [$line => 999999])->assertSessionHasErrors('items.0.quantity_liters');
        $html = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->getContent();
        $this->assertMatchesRegularExpression('/data-sale-truck-section\s+hidden/', $html);
        $this->assertMatchesRegularExpression('/id="sale_delivery_truck_id"[^>]*disabled/s', $html);
        $this->assertStringNotContainsString('Truck is assigned during Fuel Lifting', $html);

        // Garage to Client still requires its truck.
        $tank = $this->premiumInGarage($a);
        $this->recordOrder($a, ['purchase_item_id' => 'tank:'.$tank, 'quantity_liters' => 100, 'unit_price' => 70], [
            'delivery_type' => 'garage_to_client', 'delivery_driver_user_id' => $a['driver']->id, 'delivery_date' => '2026-11-22 10:00',
        ])->assertSessionHasErrors('delivery_truck_id');
    }

    // ----- 4. The PUR-DSL-002 example ---------------------------------------------------------------------------

    public function test_a_depot_sale_takes_unlifted_purchase_fuel_and_delivery_deducts_exactly_its_liters(): void
    {
        $a = $this->actors();
        $big = $this->truck('TRK-100K', 100000);
        $line = $this->purchase($a, 'PUR-DSL-002', 100000);

        // 40,000 L sold straight from the purchase: nothing lifted, no truck.
        $this->depotSale($a, [$line => 40000])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $this->assertSame($line, (int) DB::table('sale_items')->where('sale_id', $saleId)->value('purchase_item_id'));
        $this->assertSame(['reserved' => 40000.0, 'delivered' => 0.0, 'lifted' => 0.0, 'available' => 60000.0, 'remaining' => 100000.0, 'in_depot' => 100000.0], $this->figures($line));

        // Fuel Lifting recognises the sale and its purchase.
        $page = $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting'))->assertOk();
        $option = $page->viewData('directSaleOptions')->firstWhere('sale_id', $saleId);
        $this->assertSame('PUR-DSL-002', $option->purchase_code);
        $this->assertStringEndsWith(' · PUR-DSL-002', $option->label);
        $this->assertSame('PUR-DSL-002 · DIESEL - 40,000.00 L', $option->ordered_label);
        $this->assertSame([$line => 40000.0], $option->reserved_by_purchase);
        $purchase = $page->viewData('purchaseItems')->firstWhere('id', $line);
        $this->assertSame([100000.0, 40000.0, 60000.0], [$purchase->remaining_liters, $purchase->reserved_for_sales, $purchase->free_liters]);
        $this->assertStringContainsString('DIESEL - 100,000.00 L · 40,000.00 L reserved for sales · 60,000.00 L free to lift', $page->getContent());

        // The remaining 60,000 L can go to the garage or another truck, but not the sale's 40,000 L.
        $this->lift($a, $big, [$line => 60000.01])->assertSessionHasErrors('lift');
        $this->lift($a, $big, [$line => 100000])->assertSessionHasErrors('lift');
        $this->assertSame(0, DB::table('hauls')->whereNotNull('lifting_schedule_id')->count());
        $this->assertStringContainsString('40,000.00 L of its 100,000.00 L in the depot are reserved for open sales', session('errors')->first('lift'));

        // A lift carrying the sale may take its liters; delivering it deducts exactly 40,000 L.
        $this->lift($a, $big, [$line => 40000], [$saleId])->assertSessionHasNoErrors();
        $haul = $this->lastHaulId();
        $this->assertSame(['reserved' => 40000.0, 'delivered' => 0.0, 'lifted' => 40000.0, 'available' => 60000.0, 'remaining' => 100000.0, 'in_depot' => 60000.0], $this->figures($line));
        $this->completeLift($a, $haul);
        $this->assertSame('delivered', DB::table('sales')->where('id', $saleId)->value('delivery_status'));
        $this->assertSame(['reserved' => 0.0, 'delivered' => 40000.0, 'lifted' => 40000.0, 'available' => 60000.0, 'remaining' => 60000.0, 'in_depot' => 60000.0], $this->figures($line));
        $this->assertFalse(app(PurchaseStockService::class)->line($line)->requires_reconciliation);

        // Marking it Delivered again deducts nothing more.
        app(SaleConfirmationService::class)->updateDeliveryStatus($saleId, 'delivered', $a['admin']->id);
        $this->assertSame(40000.0, (float) DB::table('stock_outs')->where('sale_id', $saleId)->where('status', 'released')->sum('quantity_liters'));
        $this->assertSame(60000.0, $this->figures($line)['remaining']);

        // The 60,000 L left: 20,000 L onto another lift, 40,000 L to another sale. Part 26: the 20,000 L on that lift stay
        // sellable as Depot to Client until an order or a garage unload claims them; then nothing more.
        $this->lift($a, $big, [$line => 20000])->assertSessionHasNoErrors();
        $this->depotSale($a, [$line => 40000.01])->assertSessionHasErrors('items.0.quantity_liters');
        $this->depotSale($a, [$line => 40000])->assertSessionHasNoErrors();
        $this->assertSame(0.0, $this->figures($line)['available']);
        $this->depotSale($a, [$line => 20000.01])->assertSessionHasErrors('items.0.quantity_liters');
        $this->depotSale($a, [$line => 20000])->assertSessionHasNoErrors();
        $this->depotSale($a, [$line => 1])->assertSessionHasErrors('items.0.quantity_liters');
    }

    public function test_a_depot_sale_is_not_delivered_without_its_lift(): void
    {
        $a = $this->actors();
        $line = $this->purchase($a, 'PUR-DSL-002', 100000);
        $this->depotSale($a, [$line => 40000])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();

        try {
            app(SaleConfirmationService::class)->updateDeliveryStatus($saleId, 'delivered', $a['admin']->id);
            $this->fail('A Depot to Client sale was delivered without a lift.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('delivered through its lift', $exception->getMessage());
        }
        $this->assertSame(['pending', 0], [DB::table('sales')->where('id', $saleId)->value('delivery_status'), DB::table('stock_outs')->where('sale_id', $saleId)->count()]);
        $this->assertSame(40000.0, $this->figures($line)['reserved']);
    }

    // ----- multiple purchase allocations ------------------------------------------------------------------------

    public function test_a_depot_sale_takes_fuel_from_several_purchases_and_each_is_deducted_once(): void
    {
        $a = $this->actors();
        $big = $this->truck('TRK-100K', 100000);
        $first = $this->purchase($a, 'PUR-DSL-002', 30000);
        $second = $this->purchase($a, 'PUR-DSL-003', 20000);

        // The same purchase twice, or more than a purchase has, is refused.
        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->post(route('sales-officer.sales.store'), [
            'idempotency_key' => (string) Str::uuid(), 'form_context' => 'record-sale', 'delivery_type' => 'depot_to_client', 'customer_id' => $a['customer'], 'sale_date' => '2026-11-21',
            'items' => [['purchase_item_id' => $first, 'quantity_liters' => 10000, 'unit_price' => 62], ['purchase_item_id' => $first, 'quantity_liters' => 10000, 'unit_price' => 62]],
            'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment',
        ])->assertSessionHasErrors('items.1.purchase_item_id');
        $this->depotSale($a, [$first => 30000, $second => 20000.01])->assertSessionHasErrors('items.1.quantity_liters');
        $this->assertSame(0, DB::table('sales')->count());

        $this->depotSale($a, [$first => 30000, $second => 15000])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $this->assertSame([30000.0, 0.0], [$this->figures($first)['reserved'], $this->figures($first)['available']]);
        $this->assertSame([15000.0, 5000.0], [$this->figures($second)['reserved'], $this->figures($second)['available']]);
        $row = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->viewData('sales')->first();
        $this->assertSame('Diesel · PUR-DSL-002 + PUR-DSL-003', $row['cells'][5]);

        // Fuel Lifting: the order needs both purchases on its lift; neither purchase's reserved liters can be lifted away.
        $option = $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting'))->viewData('directSaleOptions')->firstWhere('sale_id', $saleId);
        $this->assertSame('PUR-DSL-002 + PUR-DSL-003', $option->purchase_code);
        $this->assertSame([$first => 30000.0, $second => 15000.0], $option->reserved_by_purchase);
        $this->lift($a, $big, [$first => 30000], [$saleId])->assertSessionHasErrors('deliveries.0.sale_id');
        $this->lift($a, $big, [$second => 5000.01])->assertSessionHasErrors('lift');
        $this->lift($a, $big, [$first => 30000, $second => 15000], [$saleId])->assertSessionHasNoErrors();
        $this->completeLift($a, $this->lastHaulId());

        $this->assertSame('delivered', DB::table('sales')->where('id', $saleId)->value('delivery_status'));
        $this->assertSame(['reserved' => 0.0, 'delivered' => 30000.0, 'lifted' => 30000.0, 'available' => 0.0, 'remaining' => 0.0, 'in_depot' => 0.0], $this->figures($first));
        $this->assertSame(['reserved' => 0.0, 'delivered' => 15000.0, 'lifted' => 15000.0, 'available' => 5000.0, 'remaining' => 5000.0, 'in_depot' => 5000.0], $this->figures($second));
        $this->assertSame([$first => 30000.0, $second => 15000.0], DB::table('stock_outs')->where('sale_id', $saleId)->where('status', 'released')
            ->groupBy('purchase_item_id')->selectRaw('purchase_item_id, SUM(quantity_liters) as liters')->pluck('liters', 'purchase_item_id')->map(fn ($l) => (float) $l)->all());
    }

    // ----- partial deliveries -----------------------------------------------------------------------------------

    public function test_a_purchase_delivered_in_parts_keeps_reserved_delivered_and_available_apart(): void
    {
        $a = $this->actors();
        $big = $this->truck('TRK-100K', 100000);
        $line = $this->purchase($a, 'PUR-DSL-002', 100000);
        $this->depotSale($a, [$line => 40000])->assertSessionHasNoErrors();
        $first = $this->lastSaleId();
        $this->depotSale($a, [$line => 30000])->assertSessionHasNoErrors();
        $second = $this->lastSaleId();
        $this->assertSame(['reserved' => 70000.0, 'delivered' => 0.0, 'lifted' => 0.0, 'available' => 30000.0, 'remaining' => 100000.0, 'in_depot' => 100000.0], $this->figures($line));

        // A 50,000 L lift delivers the first sale only; 10,000 L stay on the truck.
        $this->lift($a, $big, [$line => 50000], [$first])->assertSessionHasNoErrors();
        $this->completeLift($a, $this->lastHaulId());
        $this->assertSame(['delivered', 'pending'], [DB::table('sales')->where('id', $first)->value('delivery_status'), DB::table('sales')->where('id', $second)->value('delivery_status')]);
        $this->assertSame(['reserved' => 30000.0, 'delivered' => 40000.0, 'lifted' => 50000.0, 'available' => 20000.0, 'remaining' => 60000.0, 'in_depot' => 50000.0], $this->figures($line));
        $this->assertSame(10000.0, app(PurchaseStockService::class)->line($line)->on_truck);

        // The depot's free 20,000 L can be lifted; one more liter would take the second sale's.
        $this->lift($a, $this->truck('TRK-GAR', 100000), [$line => 20000.01])->assertSessionHasErrors('lift');

        // The second sale on its own lift (the first truck still carries its 10,000 L): 70,000 L delivered in total.
        $this->lift($a, $this->truck('TRK-2ND', 100000), [$line => 30000], [$second])->assertSessionHasNoErrors();
        $this->completeLift($a, $this->lastHaulId());
        $this->assertSame(['reserved' => 0.0, 'delivered' => 70000.0, 'lifted' => 80000.0, 'available' => 20000.0, 'remaining' => 30000.0, 'in_depot' => 20000.0], $this->figures($line));
        $this->assertSame(70000.0, (float) DB::table('stock_outs')->where('purchase_item_id', $line)->where('status', 'released')->sum('quantity_liters'));
    }

    // ----- lift edits and cancellations -------------------------------------------------------------------------

    public function test_editing_a_lift_cannot_take_the_liters_of_an_order_it_drops(): void
    {
        $a = $this->actors();
        $big = $this->truck('TRK-100K', 100000);
        $line = $this->purchase($a, 'PUR-DSL-002', 100000);
        $this->depotSale($a, [$line => 40000])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $this->lift($a, $big, [$line => 40000], [$saleId])->assertSessionHasNoErrors();
        $haul = $this->lastHaulId();

        $edit = fn (float $liters, array $saleIds) => $this->actingAs($a['dispatch'])->from(route('dispatch.fuel-lifting'))->patch(route('dispatch.fuel-lifting.hauls.lift', $haul), [
            'items' => [['purchase_item_id' => $line, 'quantity_liters' => $liters]],
            'deliveries' => array_map(fn (int $id): array => ['sale_id' => $id], $saleIds),
            'driver_user_id' => $a['driver']->id, 'truck_id' => $big, 'scheduled_at' => '2026-11-22 08:00:00',
        ]);

        // Dropping the order puts its 40,000 L back in reserve: the lift may then take 60,000 L at most.
        $edit(100000, [])->assertSessionHasErrors('lift');
        $edit(60000.01, [])->assertSessionHasErrors('lift');
        // Keeping the order, the lift may take all 100,000 L.
        $edit(100000, [$saleId])->assertSessionHasNoErrors();
        $this->assertSame(100000.0, (float) DB::table('hauls')->where('id', $haul)->value('quantity_liters'));
        $edit(60000, [])->assertSessionHasNoErrors();
        $this->assertSame(['reserved' => 40000.0, 'available' => 0.0, 'in_depot' => 40000.0], array_intersect_key($this->figures($line), array_flip(['reserved', 'available', 'in_depot'])));
    }

    public function test_cancelling_a_depot_sale_frees_its_reserved_liters(): void
    {
        $a = $this->actors();
        $big = $this->truck('TRK-100K', 100000);
        $line = $this->purchase($a, 'PUR-DSL-002', 100000);
        $this->depotSale($a, [$line => 40000])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $this->lift($a, $big, [$line => 100000])->assertSessionHasErrors('lift');

        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.cancel', $saleId), ['cancel_reason' => 'customer_cancelled'])->assertSessionHasNoErrors();
        $this->assertSame(['reserved' => 0.0, 'available' => 100000.0], array_intersect_key($this->figures($line), array_flip(['reserved', 'available'])));
        $this->lift($a, $big, [$line => 100000])->assertSessionHasNoErrors();
    }
}
