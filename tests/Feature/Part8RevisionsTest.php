<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Audit\AuditTestCase;

/**
 * PART 8 (October 15, 2026): stock lots, garage-only lifts, automatic truck codes, retired payment
 * options, white/red rows and the notification bell.
 */
class Part8RevisionsTest extends AuditTestCase
{
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
     * Two purchases of the fuel (6,000 L at 50 and 4,000 L at 60). Part 2 revisions: the purchase is the stock.
     *
     * @param  array<string, mixed>  $a
     * @return array{0: int, 1: int} purchase line ids, oldest first
     */
    private function twoLots(array $a): array
    {
        $lots = [];
        foreach ([[6000, 50], [4000, 60]] as $i => [$liters, $cost]) {
            $this->travel($i)->minutes();
            $this->actingAs($a['inventory'])->post(route('inventory-officer.inventory.purchases.store'), [
                'idempotency_key' => $this->uuid(), 'purchase_date' => '2026-10-14', 'depot_id' => $a['depot'], 'fuel_type_id' => $a['fuel'],
                'quantity_ordered_liters' => $liters, 'unit_cost' => $cost, 'payment_status' => 'unpaid',
            ])->assertSessionHasNoErrors();
            $lots[] = (int) DB::table('purchase_items')->orderByDesc('id')->value('id');
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
            // The purchase decides the fuel.
            'items' => [['purchase_item_id' => $lotId, 'quantity_liters' => $liters, 'unit_price' => $price]],
            'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment', 'amount_paid' => $paid,
        ]);
    }

    private function between(string $html, string $start, string $end): string
    {
        $from = strpos($html, $start);
        $this->assertNotFalse($from, 'Missing '.$start);

        return substr($html, $from, strpos($html, $end, $from) - $from);
    }
}
