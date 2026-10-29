<?php

namespace Tests\Feature;

use App\Services\LiftAllocationService;
use App\Services\PurchaseStockService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PurchaseFlows;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Part 2 Button Revisions (October 22, 2026): tanks are retired and stock is kept per PURCHASE. Record Sales takes
 * the fuel and the F1 portion from purchases (F1 has its own price); Standard orders carry Delivery Details and are
 * deducted when Delivered; Depot to Client orders ride on a lift and are deducted when the lift is completed.
 */
class Part2ButtonRevisionsTest extends AuditTestCase
{
    use PurchaseFlows;

    // ----- Record Sales: totals, F1, stock ---------------------------------------------------------------------

    public function test_line_total_is_premium_total_plus_f1_total(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 10000);
        $f1 = $this->purchaseLine($a, 'F1', 5000);

        // 4,000 L Premium at 50 with 10% F1 at 40: 3,600 x 50 + 400 x 40 = 180,000 + 16,000.
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 4000, 'unit_price' => 50, 'f1_percentage' => 10, 'f1_purchase_item_id' => $f1, 'f1_unit_price' => 40])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $this->lastSaleId(), 'purchase_item_id' => $premium, 'f1_purchase_item_id' => $f1, 'fuel_type_id' => $this->fuel('PREM'),
            'quantity_liters' => 4000, 'main_quantity_liters' => 3600, 'f1_quantity_liters' => 400, 'unit_price' => 50, 'f1_unit_price' => 40, 'line_total' => 196000,
        ]);
        $this->assertDatabaseHas('sales', ['id' => $this->lastSaleId(), 'delivery_type' => 'standard', 'delivery_status' => 'pending']);
    }

    public function test_the_f1_row_is_ignored_for_diesel_and_f1_fuel(): void
    {
        $a = $this->actors();
        $diesel = $this->purchaseLine($a, 'DSL', 10000);
        $f1 = $this->purchaseLine($a, 'F1', 5000);

        foreach ([$diesel, $f1] as $purchase) {
            $this->recordOrder($a, ['purchase_item_id' => $purchase, 'quantity_liters' => 1000, 'unit_price' => 60, 'f1_percentage' => 10, 'f1_purchase_item_id' => $f1, 'f1_unit_price' => 40])
                ->assertSessionHasNoErrors();
            $this->assertDatabaseHas('sale_items', [
                'sale_id' => $this->lastSaleId(), 'purchase_item_id' => $purchase, 'f1_percentage' => 0, 'f1_quantity_liters' => 0,
                'f1_purchase_item_id' => null, 'f1_unit_price' => 0, 'line_total' => 60000,
            ]);
        }

        // Nothing was promised from the F1 purchase as an F1 portion: only the 1,000 L F1 order itself.
        $this->assertSame(4000.0, $this->available($f1));
    }

    public function test_insufficient_purchase_stock_is_rejected(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 5000);
        $f1 = $this->purchaseLine($a, 'F1', 300);
        $this->withoutStockLots();

        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 6000, 'unit_price' => 50])
            ->assertSessionHasErrors(['items.0.quantity_liters' => 'Only 5,000.00 L of '.$this->code($premium).' (Premium) are left in the depot for this order.']);
        // 10% of 4,000 = 400 L of F1, but the F1 purchase has 300 L.
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 4000, 'unit_price' => 50, 'f1_percentage' => 10, 'f1_purchase_item_id' => $f1, 'f1_unit_price' => 40])
            ->assertSessionHasErrors(['items.0.f1_purchase_item_id' => 'Only 300.00 L of '.$this->code($f1).' (F1) are left in the depot for the 400.00 L F1 portion.']);
        // An F1 portion needs its F1 purchase and its F1 Price.
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 1000, 'unit_price' => 50, 'f1_percentage' => 10])
            ->assertSessionHasErrors(['items.0.f1_purchase_item_id', 'items.0.f1_unit_price']);
        $this->assertSame(0, DB::table('sales')->count());

        // Liters promised to an undelivered order are not available to the next one.
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 3000, 'unit_price' => 50])->assertSessionHasNoErrors();
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 2500, 'unit_price' => 50])
            ->assertSessionHasErrors(['items.0.quantity_liters' => 'Only 2,000.00 L of '.$this->code($premium).' (Premium) are left in the depot for this order.']);
        $this->assertSame(5000.0, $this->remaining($premium), 'Nothing is deducted before delivery.');
    }

    // ----- Standard orders ---------------------------------------------------------------------------------------

    public function test_a_standard_order_is_deducted_from_its_purchases_once_when_delivered(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 10000);
        $f1 = $this->purchaseLine($a, 'F1', 5000);

        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 4000, 'unit_price' => 50, 'f1_percentage' => 10, 'f1_purchase_item_id' => $f1, 'f1_unit_price' => 40], [
            'delivery_truck_id' => $a['truck'], 'delivery_driver_user_id' => $a['driver']->id, 'delivery_date' => '2026-10-16 10:30', 'delivery_status' => 'out_for_delivery',
        ])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'delivery_status' => 'out_for_delivery', 'delivery_truck_id' => $a['truck'], 'delivery_driver_user_id' => $a['driver']->id]);

        // Promised, not deducted.
        $this->assertSame([10000.0, 6400.0, 5000.0, 4600.0], [$this->remaining($premium), $this->available($premium), $this->remaining($f1), $this->available($f1)]);

        $this->deliverOrder($a, $saleId)->assertSessionHasNoErrors();
        $this->deliverOrder($a, $saleId)->assertSessionHasNoErrors();

        // Premium 3,600 L from its purchase, F1 400 L from the F1 purchase, once.
        $this->assertSame([6400.0, 4600.0], [$this->remaining($premium), $this->remaining($f1)]);
        $this->assertSame([6400.0, 4600.0], [$this->available($premium), $this->available($f1)]);
        $this->assertDatabaseHas('stock_outs', ['sale_id' => $saleId, 'purchase_item_id' => $premium, 'portion' => 'main', 'quantity_liters' => 3600, 'status' => 'released', 'haul_allocation_id' => null]);
        $this->assertDatabaseHas('stock_outs', ['sale_id' => $saleId, 'purchase_item_id' => $f1, 'portion' => 'f1', 'fuel_type_id' => $this->fuel('F1'), 'quantity_liters' => 400, 'status' => 'released']);
        $this->assertSame(2, DB::table('stock_outs')->where('sale_id', $saleId)->count());
    }

    public function test_a_standard_order_recorded_as_delivered_is_deducted_on_save(): void
    {
        $a = $this->actors();
        $diesel = $this->purchaseLine($a, 'DSL', 8000);

        $this->recordOrder($a, ['purchase_item_id' => $diesel, 'quantity_liters' => 2000, 'unit_price' => 60], ['delivery_status' => 'delivered', 'delivery_truck_id' => $a['truck']])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sales', ['id' => $this->lastSaleId(), 'delivery_status' => 'delivered']);
        $this->assertNotNull(DB::table('sales')->where('id', $this->lastSaleId())->value('delivery_date'), 'Delivered without a date is dated now.');
        $this->assertSame(6000.0, $this->remaining($diesel));
    }

    public function test_depot_to_client_orders_get_no_delivery_details_and_are_not_delivered_from_the_sales_page(): void
    {
        $a = $this->actors();
        $diesel = $this->purchaseLine($a, 'DSL', 8000);

        $this->recordOrder($a, ['purchase_item_id' => $diesel, 'quantity_liters' => 2000, 'unit_price' => 60], ['delivery_type' => 'depot_to_client', 'delivery_truck_id' => $a['truck']])
            ->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'delivery_type' => 'depot_to_client', 'delivery_truck_id' => null]);

        $this->deliverOrder($a, $saleId)->assertSessionHasErrors(['delivery_status' => 'This Depot to Client sale is delivered through its lift. Add it to a lift in Fuel Lifting and complete the lift first.']);
        $this->assertSame(8000.0, $this->remaining($diesel));
    }

    public function test_garage_to_client_is_the_garage_delivery_type_and_needs_garage_stock(): void
    {
        $a = $this->actors();
        $diesel = $this->purchaseLine($a, 'DSL', 8000);

        // Part 13: "garage_to_client" is no longer an alias of Standard; it sells from a garage tank only.
        $this->recordOrder($a, ['purchase_item_id' => $diesel, 'quantity_liters' => 500, 'unit_price' => 60], ['delivery_type' => 'garage_to_client'])
            ->assertSessionHasErrors('items.0.garage_stock_id');
        $this->assertSame(0, DB::table('sales')->count());

        $html = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $this->assertStringContainsString('<option value="depot_to_client"', $html);
        // (The rejected Garage to Client order keeps its type selected in the reopened form.)
        $this->assertStringContainsString('<option value="standard"', $html);
        $this->assertStringContainsString('<option value="garage_to_client" selected>Garage to Client</option>', $html);
        $this->assertStringContainsString('<th>Delivery Type</th>', $html);
    }

    // ----- Depot to Client through Schedule Lift ---------------------------------------------------------------

    public function test_a_depot_to_client_order_is_deducted_once_when_its_lift_is_completed(): void
    {
        $a = $this->actors();
        DB::table('trucks')->where('id', $a['truck'])->update(['capacity_liters' => 40000]);
        $premium = $this->purchaseLine($a, 'PREM', 60000);
        $f1 = $this->purchaseLine($a, 'F1', 5000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 4000, 'unit_price' => 50, 'f1_percentage' => 10, 'f1_purchase_item_id' => $f1, 'f1_unit_price' => 40], [
            'delivery_type' => 'depot_to_client', 'sales_order_number' => 'SH09357043',
        ])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();

        // Schedule Lift offers it as "Customer · Sales Order No." with its Ordered Quantity.
        $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting'))->assertOk()
            ->assertSee('Audit Customer · SH09357043')
            ->assertSee('data-ordered="PREMIUM - 4,000.00 L"', false)
            ->assertSee('data-remaining-label="PREMIUM - 60,000.00 L"', false);

        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 40000, 'atl_number' => 'ATL-001']], [$saleId])->assertSessionHasNoErrors();
        $haulId = $this->lastHaulId();
        $this->assertDatabaseHas('haul_allocations', ['haul_id' => $haulId, 'destination_type' => 'customer', 'sale_id' => $saleId, 'quantity_liters' => 4000, 'status' => 'planned']);
        // Fuel Remaining in Truck: 40,000 - 4,000.
        $this->assertSame(['delivered' => 4000.0, 'remaining' => 36000.0], app(LiftAllocationService::class)->loadSummaryByHaul([$haulId])[$haulId]);
        // The lift itself deducts nothing: only the order does, on completion.
        $this->assertSame([60000.0, 5000.0], [$this->remaining($premium), $this->remaining($f1)]);

        $this->completeLift($a, $haulId);
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'delivery_status' => 'delivered']);
        $this->assertSame([56400.0, 4600.0], [$this->remaining($premium), $this->remaining($f1)]);

        // Completing the schedule and delivering again change nothing.
        $this->actingAs($a['dispatch'])->patch(route('dispatch.fuel-lifting.hauls.status', $haulId), ['idempotency_key' => $this->uuid(), 'status' => 'completed'])->assertSessionHasNoErrors();
        $this->deliverOrder($a, $saleId)->assertSessionHasNoErrors();
        $this->assertSame([56400.0, 4600.0], [$this->remaining($premium), $this->remaining($f1)]);
        $this->assertSame(2, DB::table('stock_outs')->where('sale_id', $saleId)->where('status', 'released')->count());
        $this->assertSame(2, DB::table('stock_outs')->where('sale_id', $saleId)->whereNotNull('haul_allocation_id')->count());

        // The purchase's depot balance: 60,000 - 40,000 lifted (the order rode on the lift, not counted twice).
        $this->assertSame(20000.0, (float) app(PurchaseStockService::class)->line($premium)->remaining_in_depot);
        // The Hauled tab: Delivered next to Lifted, no Allocated column.
        $html = $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting.hauled'))->assertOk()->getContent();
        $this->assertStringNotContainsString('Allocated Quantity', $html);
        $this->assertMatchesRegularExpression('/40,000\.00 L<\/td>\s*<td>4,000\.00 L<\/td>/', $html);
        $this->assertStringContainsString('<div class="detail-label">Remaining on Truck</div><div class="detail-value">36,000.00 L</div>', $html);
    }

    public function test_schedule_lift_rejects_over_delivery_and_an_order_on_two_lifts(): void
    {
        $a = $this->actors();
        $diesel = $this->purchaseLine($a, 'DSL', 30000);
        $premium = $this->purchaseLine($a, 'PREM', 30000);
        $big = $this->depotOrder($a, $diesel, 6000);
        $small = $this->depotOrder($a, $diesel, 3000);
        $premiumOrder = $this->depotOrder($a, $premium, 1000);

        // 6,000 + 3,000 delivered from a 5,000 L lift.
        $this->scheduleLift($a, [['purchase_item_id' => $diesel, 'quantity_liters' => 5000]], [$big, $small])
            ->assertSessionHasErrors('deliveries.0.sale_id');
        // The same order twice in one lift.
        $this->scheduleLift($a, [['purchase_item_id' => $diesel, 'quantity_liters' => 20000]], [$small, $small])
            ->assertSessionHasErrors(['deliveries.1.sale_id' => 'This order is already in this lift.']);
        // An order whose purchase is not on the lift.
        $this->scheduleLift($a, [['purchase_item_id' => $diesel, 'quantity_liters' => 20000]], [$premiumOrder])
            ->assertSessionHasErrors('deliveries.0.sale_id');
        $this->assertSame(0, DB::table('hauls')->count());

        // Deliveries may total less: the rest stays on the truck.
        $this->scheduleLift($a, [['purchase_item_id' => $diesel, 'quantity_liters' => 20000]], [$big])->assertSessionHasNoErrors();
        // The order is now on a lift: it can't go on another, and Schedule Lift no longer offers it.
        $this->scheduleLift($a, [['purchase_item_id' => $diesel, 'quantity_liters' => 5000]], [$big])
            ->assertSessionHasErrors(['deliveries.0.sale_id' => 'This order is already on another lift.']);
        $options = app(LiftAllocationService::class)->directSaleOptions()->pluck('sale_id')->all();
        $this->assertNotContains($big, $options);
        $this->assertContains($small, $options);

        // Cancelling the lift frees the order for another lift.
        $haulId = $this->lastHaulId();
        $this->actingAs($a['dispatch'])->patch(route('dispatch.fuel-lifting.hauls.status', $haulId), ['idempotency_key' => $this->uuid(), 'status' => 'cancelled'])->assertSessionHasNoErrors();
        $this->assertContains($big, app(LiftAllocationService::class)->directSaleOptions()->pluck('sale_id')->all());
    }

    public function test_schedule_lift_follows_the_mockup_and_has_no_tank_fields(): void
    {
        $a = $this->actors();
        $this->purchaseLine($a, 'DSL', 10000);

        $html = $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting'))->assertOk()->getContent();
        foreach (['Lift Details', 'Truck ID', 'Driver', 'Lift Date', 'Purchase ID', 'Fuel / Remaining in Depot', 'Amount to Lift', 'Authority to Load', '+ Add Purchase', 'Remove Purchase', 'DR Number', 'Fuel Remaining in Truck:', 'Add Schedule'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        foreach (['Allocation / Delivery', 'Depot to Garage', 'data-allocation-tank', 'data-tank-stock', 'Select Tank'] as $retired) {
            $this->assertStringNotContainsString($retired, $html);
        }
    }

    public function test_the_inventory_page_has_no_tank_receipts_and_shows_purchase_remaining(): void
    {
        $a = $this->actors();
        $diesel = $this->purchaseLine($a, 'DSL', 8000);
        $this->recordOrder($a, ['purchase_item_id' => $diesel, 'quantity_liters' => 2000, 'unit_price' => 60], ['delivery_status' => 'delivered'])->assertSessionHasNoErrors();

        $html = $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory'))->assertOk()->getContent();
        $this->assertStringContainsString('<th>Remaining (L)</th>', $html);
        $this->assertStringContainsString('6,000.00', $html);
        foreach (['Assign Tank', 'Pending Garage Receipts', 'Record Stock-In', 'Purchase Stock-In Pipeline', 'tank assignment'] as $retired) {
            $this->assertStringNotContainsString($retired, $html);
        }
        // Stock-In revisions (October 23, 2026): the Stock-In URL opens its own tab again.
        $stockIn = $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory.stock-in'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<section data-tab-panel="stock-in"\s*>/', $stockIn);
    }

    /**
     * @param  array<string, mixed>  $a
     */
    private function depotOrder(array $a, int $purchase, float $liters): int
    {
        $this->recordOrder($a, ['purchase_item_id' => $purchase, 'quantity_liters' => $liters, 'unit_price' => 60], ['delivery_type' => 'depot_to_client'])->assertSessionHasNoErrors();

        return $this->lastSaleId();
    }

    private function code(int $purchaseItemId): string
    {
        return (string) DB::table('purchase_items')->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')->where('purchase_items.id', $purchaseItemId)->value('purchases.purchase_code');
    }
}
