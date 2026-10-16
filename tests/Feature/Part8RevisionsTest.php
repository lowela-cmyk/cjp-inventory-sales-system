<?php

namespace Tests\Feature;

use App\Services\StockLotService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Audit\AuditTestCase;

/**
 * PART 8 (October 15, 2026): stock lots, garage-only lifts, automatic truck codes, retired payment
 * options, white/red rows and the notification bell.
 */
class Part8RevisionsTest extends AuditTestCase
{
    public function test_record_sales_lists_stock_lots_with_stock_by_fuel_then_oldest(): void
    {
        $a = $this->actors();
        [$older, $newer] = $this->twoLots($a);

        $lots = app(StockLotService::class)->saleOptions();
        $this->assertSame([$older, $newer], $lots->where('fuel_type_id', $a['fuel'])->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertMatchesRegularExpression('/^PUR-\S+ · Diesel · 6,000 L in stock · ₱50\.00\/L$/', $lots->firstWhere('id', $older)->label);

        $html = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $this->assertStringContainsString('name="items[0][stock_lot_id]"', $html);
        $this->assertStringContainsString('<optgroup label="Diesel">', $html);
        $this->assertStringContainsString('6,000 L in stock · ₱50.00/L', $html);
        $this->assertStringContainsString('4,000 L in stock · ₱60.00/L', $html);
        $this->assertStringNotContainsString('name="items[0][fuel_type_id]"', $this->between($html, 'id="record-sale-form"', '</form>'));

        // A lot with nothing left is not offered.
        $this->sell($a, $newer, 4000);
        $this->assertNull(app(StockLotService::class)->saleOptions()->firstWhere('id', $newer));
    }

    public function test_quantity_above_a_lots_available_stock_is_rejected_under_quantity(): void
    {
        $a = $this->actors();
        [$older] = $this->twoLots($a);

        $this->sell($a, $older, 6500)->assertSessionHasErrors(['items.0.quantity_liters' => 'Only 6,000.00 L of '.$this->lotCode($older).' (Diesel) is available.']);
        $this->assertSame(0, DB::table('sales')->count());

        // An undelivered sale reserves its liters: the next sale may only take what is left.
        $this->sell($a, $older, 5000)->assertSessionHasNoErrors();
        $this->sell($a, $older, 1500)->assertSessionHasErrors(['items.0.quantity_liters' => 'Only 1,000.00 L of '.$this->lotCode($older).' (Diesel) is available.']);
        $this->sell($a, $older, 1000)->assertSessionHasNoErrors();

        // The sale line stores its lot, and its fuel comes from the lot whatever the client sent.
        $this->assertSame(2, DB::table('sale_items')->where('stock_in_movement_id', $older)->where('fuel_type_id', $a['fuel'])->count());
    }

    public function test_delivered_deducts_from_the_chosen_lot_and_its_tank_at_the_lot_cost(): void
    {
        $a = $this->actors();
        [$older, $newer] = $this->twoLots($a);
        $olderTank = (int) DB::table('inventory_movements')->where('id', $older)->value('storage_location_id');
        $newerTank = (int) DB::table('inventory_movements')->where('id', $newer)->value('storage_location_id');
        $this->assertNotSame($olderTank, $newerTank);

        // Sell 3,000 L from the NEWER lot (₱60/L) at ₱70/L, paying 100,000 up front.
        $this->sell($a, $newer, 3000, 70, 100000)->assertSessionHasNoErrors();
        $saleId = (int) DB::table('sales')->value('id');
        $this->actingAs($a['sales'])->patch(route('sales-officer.sales.delivery-status.update', $saleId), ['delivery_status' => 'delivered'])->assertSessionHasNoErrors();

        $stockOut = DB::table('stock_outs')->where('sale_id', $saleId)->first();
        $this->assertSame($newerTank, (int) $stockOut->storage_location_id);
        $this->assertSame(60.0, (float) $stockOut->unit_cost, 'The lot cost, not the tank average.');

        $lots = app(StockLotService::class)->lots()->keyBy('id');
        $this->assertSame(1000.0, $lots[$newer]->on_hand);
        $this->assertSame(6000.0, $lots[$older]->on_hand, 'The other lot is untouched.');
        $this->assertSame(1000.0, $this->tankBalance($newerTank, $a['fuel']));
        $this->assertSame(6000.0, $this->tankBalance($olderTank, $a['fuel']));

        // Stock-Out: cost 3,000 × 60 = 180,000; margin 210,000 − 180,000 = 30,000; realized 100,000 − 180,000.
        $this->actingAs($a['admin'])->get(route('admin.inventory.stock-out'))->assertOk()
            ->assertSeeTextInOrder(['60.00', '180,000.00', '70.00', '210,000.00', '100,000.00', 'Garage', $this->lotCode($newer), 'Released', '30,000.00', '-80,000.00']);

        // The Stock-In tab shows what has left each lot.
        $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory.stock-in'))->assertOk()
            ->assertSeeTextInOrder(['4,000.00', '3,000.00', 'Confirmed']);
    }

    public function test_a_lot_that_no_longer_has_the_stock_blocks_delivery_with_a_clear_message(): void
    {
        $a = $this->actors();
        [$older] = $this->twoLots($a);
        $this->sell($a, $older, 6000)->assertSessionHasNoErrors();
        $saleId = (int) DB::table('sales')->value('id');

        // Stock leaves the tank another way first (a pre-lot pooled release of the same fuel).
        DB::table('inventory_movements')->where('id', $older)->update(['quantity_liters' => 2000]);

        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.delivery-status.update', $saleId), ['delivery_status' => 'delivered'])
            ->assertSessionHasErrors(['delivery_status' => 'Stock source '.$this->lotCode($older).' has only 2,000.00 L in stock.']);
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'delivery_status' => 'pending']);
        $this->assertSame(0, DB::table('stock_outs')->where('sale_id', $saleId)->count());
    }

    public function test_sales_recorded_before_lots_keep_working_and_show_a_dash(): void
    {
        $a = $this->actors();
        $this->twoLots($a);
        $saleId = $this->legacySale($a, 500);

        $this->actingAs($a['sales'])->patch(route('sales-officer.sales.delivery-status.update', $saleId), ['delivery_status' => 'delivered'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('stock_outs', ['sale_id' => $saleId, 'status' => 'released', 'quantity_liters' => 500]);

        $html = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<td>Diesel</td><td>—</td>#u', $html);

        // Lot figures still add up to the tank balances after the pooled release (charged oldest-first).
        $lots = app(StockLotService::class)->lots();
        $this->assertSame(9500.0, round((float) $lots->sum('on_hand'), 2));
    }

    public function test_every_new_sale_line_needs_a_stock_source_for_sales_officer_and_admin(): void
    {
        $a = $this->actors();
        $this->twoLots($a);
        $this->withoutStockLots();

        foreach ([[$a['sales'], 'sales-officer'], [$a['admin'], 'admin']] as [$user, $prefix]) {
            $base = ['idempotency_key' => $this->uuid(), 'customer_id' => $a['customer'], 'sale_date' => '2026-10-15', 'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment'];

            $this->actingAs($user)->from(route($prefix.'.sales'))->post(route($prefix.'.sales.store'), $base + ['items' => [['fuel_type_id' => $a['fuel'], 'quantity_liters' => 100, 'unit_price' => 65]]])
                ->assertSessionHasErrors(['items.0.stock_lot_id' => 'Select a stock source for this line.']);
            // The old single-line form has no line to carry a lot.
            $this->actingAs($user)->from(route($prefix.'.sales'))->post(route($prefix.'.sales.store'), $base + ['fuel_type_id' => $a['fuel'], 'quantity_liters' => 100, 'unit_price' => 65])
                ->assertSessionHasErrors(['items' => 'Add at least one line with a stock source.']);
        }
        $this->assertSame(0, DB::table('sales')->count());

        // The error shows under the field when Record Sales reopens.
        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->post(route('sales-officer.sales.store'), [
            'idempotency_key' => $this->uuid(), 'form_context' => 'record-sale', 'customer_id' => $a['customer'], 'sale_date' => '2026-10-15',
            'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment', 'items' => [['quantity_liters' => 100, 'unit_price' => 65]],
        ]);
        $html = $this->get(route('sales-officer.sales'))->getContent();
        $this->assertMatchesRegularExpression('#name="items\[0\]\[stock_lot_id\]"[^>]*aria-invalid="true"#', $html);
        $this->assertStringContainsString('<p class="field-error form-row-message">Select a stock source for this line.</p>', $html);
    }

    public function test_a_sale_recorded_before_lots_stays_editable_but_added_lines_need_a_lot(): void
    {
        $a = $this->actors();
        [$older] = $this->twoLots($a);
        $saleId = $this->legacySale($a, 500);
        $this->withoutStockLots();
        $edit = fn (array $items) => $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.update', $saleId), [
            'edit_sale_id' => $saleId, 'customer_id' => $a['customer'], 'sale_date' => '2026-10-01', 'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment', 'items' => $items,
        ]);

        // Its own lot-less line can still change (quantity here) without a lot.
        $edit([['fuel_type_id' => $a['fuel'], 'quantity_liters' => 600, 'unit_price' => 65]])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sale_items', ['sale_id' => $saleId, 'quantity_liters' => 600, 'stock_in_movement_id' => null]);

        // An extra line without a lot is refused, under that line.
        $edit([['fuel_type_id' => $a['fuel'], 'quantity_liters' => 600, 'unit_price' => 65], ['fuel_type_id' => $a['fuel'], 'quantity_liters' => 100, 'unit_price' => 65]])
            ->assertSessionHasErrors(['items.1.stock_lot_id' => 'Select a stock source for this line.']);

        // An extra line from a lot is fine.
        $edit([['fuel_type_id' => $a['fuel'], 'quantity_liters' => 600, 'unit_price' => 65], ['stock_lot_id' => $older, 'quantity_liters' => 100, 'unit_price' => 65]])->assertSessionHasNoErrors();
        $this->assertSame([null, $older], DB::table('sale_items')->where('sale_id', $saleId)->orderBy('id')->pluck('stock_in_movement_id')->map(fn ($id) => $id ? (int) $id : null)->all());

        // The Edit modal adds new lines from a Stock Source template.
        $html = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->getContent();
        $this->assertStringContainsString('<template data-sales-item-template>', $html);
        $this->assertMatchesRegularExpression('#<template data-sales-item-template>\s*<div class="sales-item-row" data-sales-item>\s*<div class="form-row">\s*<label for="sale_lot_\d+_new">Stock Source</label>#', $html);
    }

    public function test_truck_codes_are_generated_and_cannot_be_typed_or_edited(): void
    {
        $a = $this->actors();
        DB::table('trucks')->insert(['truck_code' => 'TRK-007', 'capacity_liters' => 1000, 'truck_type' => 'hauling', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        $payload = fn (string $plate): array => ['truck_code' => 'HACK-1', 'plate_number' => $plate, 'name' => 'New Tanker', 'capacity_liters' => 20000, 'truck_type' => 'hauling', 'status' => 'available'];

        $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting.trucks'))->assertOk()
            ->assertSee('value="TRK-008" readonly', false)
            ->assertSee('Generated automatically when the truck is saved.')
            ->assertDontSee('name="truck_code"', false);

        $this->actingAs($a['dispatch'])->post(route('dispatch.trucks.store'), $payload('NEW-0001'))->assertSessionHasNoErrors();
        $this->actingAs($a['admin'])->post(route('admin.trucks.store'), $payload('NEW-0002'))->assertSessionHasNoErrors();
        $this->assertSame('TRK-008', DB::table('trucks')->where('plate_number', 'NEW-0001')->value('truck_code'));
        $this->assertSame('TRK-009', DB::table('trucks')->where('plate_number', 'NEW-0002')->value('truck_code'));
        $this->assertDatabaseMissing('trucks', ['truck_code' => 'HACK-1']);

        $truckId = (int) DB::table('trucks')->where('plate_number', 'NEW-0001')->value('id');
        $this->actingAs($a['dispatch'])->patch(route('dispatch.trucks.update', $truckId), $payload('NEW-0001'))->assertSessionHasNoErrors();
        $this->assertSame('TRK-008', DB::table('trucks')->where('id', $truckId)->value('truck_code'));
    }

    public function test_advance_payment_and_cod_are_retired_but_existing_records_still_show_them(): void
    {
        $a = $this->actors();
        $base = ['customer_id' => $a['customer'], 'sale_date' => '2026-10-15', 'items' => [['fuel_type_id' => $a['fuel'], 'quantity_liters' => 100, 'unit_price' => 65]]];

        $this->actingAs($a['sales'])->post(route('sales-officer.sales.store'), $base + ['idempotency_key' => $this->uuid(), 'payment_method' => 'advance_payment', 'payment_terms' => 'installment'])
            ->assertSessionHasErrors('payment_method');
        $this->actingAs($a['sales'])->post(route('sales-officer.sales.store'), $base + ['idempotency_key' => $this->uuid(), 'payment_method' => 'cash_on_delivery', 'payment_terms' => 'cod'])
            ->assertSessionHasErrors('payment_terms');

        // An existing sale with both retired options, and a payment made with Advance Payment.
        $saleId = DB::table('sales')->insertGetId(['sale_code' => 'SLS-LEGACY', 'customer_id' => $a['customer'], 'sale_date' => '2026-10-01', 'payment_method' => 'advance_payment', 'payment_terms' => 'cod', 'status' => 'confirmed', 'delivery_status' => 'pending', 'created_by' => $a['sales']->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sale_items')->insert(['sale_id' => $saleId, 'fuel_type_id' => $a['fuel'], 'quantity_liters' => 100, 'unit_price' => 65, 'line_total' => 6500, 'fulfilled_quantity_liters' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('receivables')->insert(['sale_id' => $saleId, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('payments')->insert(['payment_code' => 'PAY-LEGACY', 'sale_id' => $saleId, 'payment_date' => '2026-10-02', 'amount' => 1000, 'method' => 'advance_payment', 'received_by' => $a['sales']->id, 'created_at' => now(), 'updated_at' => now()]);

        $html = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $this->assertStringContainsString('>Advance Payment (legacy)</option>', $html);
        $this->assertStringContainsString('>COD (legacy)</option>', $html);
        $this->assertStringContainsString('Advance Payment', $this->between($html, 'id="so-payment-history-'.$saleId.'"', '</table>'));
        $record = $this->between($html, 'id="record-sale-form"', '</form>');
        $this->assertStringNotContainsString('value="advance_payment"', $record);
        $this->assertStringNotContainsString('value="cod"', $record);

        // Editing the legacy sale keeps its values; new payments can't use Advance Payment.
        $this->actingAs($a['sales'])->patch(route('sales-officer.sales.update', $saleId), array_merge($base, ['edit_sale_id' => $saleId, 'sale_date' => '2026-10-01', 'sales_order_number' => 'SO-LEGACY', 'payment_method' => 'advance_payment', 'payment_terms' => 'cod']))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'payment_method' => 'advance_payment', 'payment_terms' => 'cod', 'sales_order_number' => 'SO-LEGACY']);
        $this->actingAs($a['sales'])->post(route('sales-officer.sales.payments.store', $saleId), ['idempotency_key' => $this->uuid(), 'payment_date' => '2026-10-03', 'amount' => 500, 'method' => 'advance_payment'])
            ->assertSessionHasErrors('method');
    }

    public function test_purchase_and_sale_rows_are_white_unless_money_is_owed(): void
    {
        $a = $this->actors();
        [$older] = $this->twoLots($a);
        DB::table('purchases')->update(['payment_status' => 'paid']);
        $unpaidPurchase = (int) DB::table('purchases')->orderBy('id')->value('id');
        DB::table('purchases')->where('id', $unpaidPurchase)->update(['payment_status' => 'unpaid']);

        $purchases = collect($this->actingAs($a['admin'])->get(route('admin.inventory'))->viewData('purchases')->items());
        $this->assertSame(['row-danger', ''], $purchases->sortBy('id')->pluck('class')->values()->all());

        $this->sell($a, $older, 100, 65, 6500);   // fully paid
        $this->sell($a, $older, 100, 65, 1000);   // balance due
        $this->sell($a, $older, 100, 65, 0);      // cancelled below
        $cancelled = (int) DB::table('sales')->orderByDesc('id')->value('id');
        $this->actingAs($a['sales'])->patch(route('sales-officer.sales.cancel', $cancelled), ['cancel_reason' => 'duplicate_entry']);

        $sales = collect($this->actingAs($a['sales'])->get(route('sales-officer.sales'))->viewData('sales')->items())->keyBy('id');
        $ids = DB::table('sales')->orderBy('id')->pluck('id')->all();
        $this->assertSame('', $sales[$ids[0]]['class']);
        $this->assertSame('row-danger', $sales[$ids[1]]['class']);
        $this->assertSame('', $sales[$ids[2]]['class']);
    }

    public function test_header_bell_counts_unread_alerts_each_role_can_see(): void
    {
        $a = $this->actors();
        $alert = fn (string $type): int => DB::table('alerts')->insertGetId([
            'alert_code' => 'ALT-'.strtoupper(substr($this->uuid(), 0, 8)), 'type' => $type, 'severity' => 'info', 'title' => ucfirst($type).' alert',
            'message' => 'Test', 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $bell = fn ($user, string $page): string => $this->between($this->actingAs($user)->get(route($page))->assertOk()->getContent(), '<header', '</header>');

        // Nothing unread: the bell links to the role's Alerts page and shows no badge.
        $this->assertStringContainsString('href="'.route('sales-officer.alerts').'"', $bell($a['sales'], 'sales-officer.sales'));
        $this->assertStringNotContainsString('header-bell-badge', $bell($a['sales'], 'sales-officer.sales'));

        $purchaseAlert = $alert('purchase');
        $alert('haul');
        $alert('payment');

        $this->assertStringContainsString('data-alert-bell-count>2<', $bell($a['inventory'], 'inventory-officer.inventory'));   // purchase + haul
        $this->assertStringContainsString('data-alert-bell-count>1<', $bell($a['dispatch'], 'dispatch.fuel-lifting'));         // haul
        $this->assertStringContainsString('data-alert-bell-count>1<', $bell($a['sales'], 'sales-officer.sales'));              // payment
        $this->assertStringContainsString('data-alert-bell-count>3<', $bell($a['admin'], 'admin.dashboard'));                 // all
        $this->assertStringContainsString('href="'.route('admin.alerts').'"', $bell($a['admin'], 'admin.dashboard'));

        // Mark Read drops the count for that user only (Admin included).
        $this->actingAs($a['inventory'])->patch(route('inventory-officer.alerts.read', $purchaseAlert))->assertRedirect();
        $this->assertStringContainsString('data-alert-bell-count>1<', $bell($a['inventory'], 'inventory-officer.inventory'));
        $this->actingAs($a['admin'])->patch(route('admin.alerts.read', $purchaseAlert))->assertRedirect();
        $this->assertStringContainsString('data-alert-bell-count>2<', $bell($a['admin'], 'admin.dashboard'));

        // Drivers have no Alerts module, so no bell.
        $this->assertStringNotContainsString('data-alert-bell', $bell($a['driver'], 'driver.fuel-lifting'));

        // Above 99 the badge reads 99+.
        foreach (range(1, 100) as $i) {
            $alert('payment');
        }
        $this->assertStringContainsString('data-alert-bell-count>99+<', $bell($a['sales'], 'sales-officer.sales'));
    }

    /**
     * A sale as recorded before stock lots existed: its line names a fuel, not a lot.
     *
     * @param  array<string, mixed>  $a
     */
    private function legacySale(array $a, float $liters): int
    {
        $saleId = DB::table('sales')->insertGetId(['sale_code' => 'SLS-PRE-LOT-'.substr($this->uuid(), 0, 6), 'customer_id' => $a['customer'], 'sale_date' => '2026-10-01', 'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment', 'status' => 'confirmed', 'delivery_status' => 'pending', 'created_by' => $a['sales']->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sale_items')->insert(['sale_id' => $saleId, 'fuel_type_id' => $a['fuel'], 'quantity_liters' => $liters, 'unit_price' => 65, 'line_total' => $liters * 65, 'fulfilled_quantity_liters' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('receivables')->insert(['sale_id' => $saleId, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        return (int) $saleId;
    }

    /**
     * Two Diesel lots in two tanks: 6,000 L at ₱50 (older) and 4,000 L at ₱60 (newer).
     *
     * @param  array<string, mixed>  $a
     * @return array{0: int, 1: int}
     */
    private function twoLots(array $a): array
    {
        $tanks = DB::table('storage_locations')->where('type', 'garage')->where('status', 'active')->where('fuel_type_id', $a['fuel'])->orderBy('tank_number')->pluck('id');
        if ($tanks->count() < 2) {
            $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory'))->assertOk();
            $tanks = DB::table('storage_locations')->where('type', 'garage')->where('status', 'active')->where('fuel_type_id', $a['fuel'])->orderBy('tank_number')->pluck('id');
        }

        $lots = [];
        foreach ([[6000, 50, $tanks[0]], [4000, 60, $tanks[1]]] as $i => [$liters, $cost, $tank]) {
            $this->travel($i)->minutes();
            $this->actingAs($a['inventory'])->post(route('inventory-officer.inventory.purchases.store'), [
                'idempotency_key' => $this->uuid(), 'purchase_date' => '2026-10-14', 'depot_id' => $a['depot'], 'fuel_type_id' => $a['fuel'],
                'quantity_ordered_liters' => $liters, 'unit_cost' => $cost, 'payment_status' => 'unpaid',
            ])->assertSessionHasNoErrors();
            $itemId = (int) DB::table('purchase_items')->orderByDesc('id')->value('id');
            $this->actingAs($a['dispatch'])->post(route('dispatch.fuel-lifting.hauls.store'), [
                'idempotency_key' => $this->uuid(), 'items' => [['purchase_item_id' => $itemId, 'quantity_liters' => $liters, 'storage_location_id' => $tank]],
                'driver_user_id' => $a['driver']->id, 'truck_id' => $a['truck'], 'scheduled_at' => '2026-10-14 08:00:00', 'form_context' => 'schedule-lift',
            ])->assertSessionHasNoErrors();
            $haulId = (int) DB::table('hauls')->orderByDesc('id')->value('id');
            foreach (['in_transit', 'lifted'] as $status) {
                $this->actingAs($a['driver'])->patch(route('driver.fuel-lifting.hauls.status', $haulId), ['idempotency_key' => $this->uuid(), 'lifting_status' => $status])->assertSessionHasNoErrors();
            }
            $lots[] = (int) DB::table('inventory_movements')->where('direction', 'in')->where('movement_type', 'stock_in')->orderByDesc('id')->value('id');
        }

        return $lots;
    }

    /**
     * @param  array<string, mixed>  $a
     */
    private function sell(array $a, int $lotId, float $liters, float $price = 65, float $paid = 0)
    {
        return $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->post(route('sales-officer.sales.store'), [
            'idempotency_key' => $this->uuid(), 'form_context' => 'record-sale', 'customer_id' => $a['customer'], 'sale_date' => '2026-10-15',
            // A wrong fuel from the client is ignored: the lot decides.
            'items' => [['stock_lot_id' => $lotId, 'fuel_type_id' => 999, 'quantity_liters' => $liters, 'unit_price' => $price]],
            'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment', 'amount_paid' => $paid,
        ]);
    }

    private function lotCode(int $lotId): string
    {
        return (string) app(StockLotService::class)->lots([$lotId])->first()->source_code;
    }

    private function tankBalance(int $tankId, int $fuelTypeId): float
    {
        return round((float) DB::table('inventory_movements')
            ->leftJoin('stock_outs', fn ($join) => $join->on('stock_outs.id', '=', 'inventory_movements.reference_id')->where('inventory_movements.reference_type', '=', 'stock_out'))
            ->where('inventory_movements.storage_location_id', $tankId)->where('inventory_movements.fuel_type_id', $fuelTypeId)
            ->where(fn ($query) => $query->whereNull('stock_outs.id')->orWhere('stock_outs.status', '!=', 'cancelled'))
            ->selectRaw("COALESCE(SUM(CASE WHEN inventory_movements.direction = 'in' THEN inventory_movements.quantity_liters ELSE -inventory_movements.quantity_liters END), 0) as balance")
            ->value('balance'), 2);
    }

    private function between(string $html, string $start, string $end): string
    {
        $from = strpos($html, $start);
        $this->assertNotFalse($from, 'Missing '.$start);

        return substr($html, $from, strpos($html, $end, $from) - $from);
    }
}
