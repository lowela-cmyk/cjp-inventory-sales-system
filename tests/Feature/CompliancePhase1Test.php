<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ModuleActivityService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Audit\AuditTestCase;

/**
 * System Analysis compliance, Phase 1: business logic and data correctness.
 */
class CompliancePhase1Test extends AuditTestCase
{
    public function test_red_dots_tolerate_users_without_a_known_role(): void
    {
        // The factory sets no role, so the model holds none in memory (the database default fills the column).
        // Creating and approving such a user must not crash.
        $user = User::factory()->create();
        $this->assertNull($user->role);
        $user->update(['approval_status' => 'approved']);

        $activity = app(ModuleActivityService::class);
        $activity->markAllViewed($user);
        $activity->markViewed($user, 'inventory');

        $this->assertSame([], $activity->unseenModules($user));
        $this->assertDatabaseMissing('user_module_views', ['user_id' => $user->id]);

        $unknown = new User(['role' => 'unknown_role']);
        $this->assertSame([], $activity->unseenModules($unknown));

        // A known role still gets every module marked as seen.
        $user->update(['role' => 'sales_officer']);
        $this->assertDatabaseHas('user_module_views', ['user_id' => $user->id, 'module' => 'sales']);
        $this->assertDatabaseHas('user_module_views', ['user_id' => $user->id, 'module' => 'alerts']);
    }

    public function test_stock_out_posts_once_from_the_depot_only_when_the_sale_becomes_delivered(): void
    {
        $a = $this->actors();

        // October 22: the garage-tank and direct-depot allocation paths were removed; a sale's fuel is released from
        // its purchase in the depot. Lifting the purchase posts nothing.
        $itemId = $this->purchase($a, 10000);
        $this->schedule($a, [['purchase_item_id' => $itemId, 'quantity_liters' => 10000]])->assertSessionHasNoErrors();
        $this->driverLifts($a, (int) DB::table('hauls')->orderByDesc('id')->value('id'));
        $this->assertSame(0, DB::table('stock_outs')->count(), 'Lifting must not post a stock-out.');

        $saleId = $this->recordSale($a, 2000, 65, 0);
        $this->assertSame(0, DB::table('stock_outs')->where('sale_id', $saleId)->count(), 'Recording a sale must not post a stock-out.');

        $this->actingAs($a['sales'])->patch(route('sales-officer.sales.delivery-status.update', $saleId), ['delivery_status' => 'delivered'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('stock_outs', ['sale_id' => $saleId, 'source_type' => 'depot', 'quantity_liters' => 2000, 'status' => 'released']);
        $this->assertSame(1, DB::table('stock_outs')->where('sale_id', $saleId)->count());

        // Only once: setting Delivered again does not post a second stock-out.
        $this->actingAs($a['sales'])->patch(route('sales-officer.sales.delivery-status.update', $saleId), ['delivery_status' => 'delivered']);
        $this->assertSame(1, DB::table('stock_outs')->where('sale_id', $saleId)->count(), 'Delivered must post the stock-out only once.');
        $this->assertSame(2000.0, (float) DB::table('stock_outs')->where('sale_id', $saleId)->sum('quantity_liters'));

        // Both screens explain when Stock-Out is posted; there is still no manual stock-out entry.
        // (Notes reworded on October 22 for the two delivery types.)
        $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()
            ->assertSee("Stock-Out is deducted from the order's purchases when a Standard order is Delivered", false);
        $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory.stock-out'))->assertOk()
            ->assertSee('a Standard order when it is Delivered', false)->assertDontSee('Record Stock-Out');
    }

    public function test_stock_out_shows_margin_and_realized_profit_with_formula_tooltips(): void
    {
        $a = $this->actors();
        $itemId = $this->purchase($a, 10000);
        $this->schedule($a, [['purchase_item_id' => $itemId, 'quantity_liters' => 10000, 'garage_liters' => 10000, 'storage_location_id' => $this->tank($a), 'direct_liters' => 0]]);
        $this->driverLifts($a, (int) DB::table('hauls')->value('id'));

        // 2,000 L at 65 against a cost of 50: margin 30,000. Only 40,000 is paid so far, so realized profit is -60,000.
        $saleId = $this->recordSale($a, 2000, 65, 40000);
        $this->actingAs($a['sales'])->patch(route('sales-officer.sales.delivery-status.update', $saleId), ['delivery_status' => 'delivered'])->assertSessionHasNoErrors();

        $this->actingAs($a['admin'])->get(route('admin.inventory.stock-out'))->assertOk()
            // Part 4 #6: Source shares the Delivery column, so the table fits without scrolling.
            ->assertSeeTextInOrder(['Source', 'Total Price', 'Total Paid', 'Status', 'Margin', 'Realized Profit'])
            ->assertSee('title="Margin = Total Price − Total Cost (expected profit once fully paid)"', false)
            ->assertSee('title="Realized Profit = Total Paid − Total Cost (negative until payments cover the cost)"', false)
            ->assertSeeTextInOrder(['130,000.00', '40,000.00', 'Released', '30,000.00', '-60,000.00']);

        // A voided release earns nothing: both columns read "—" (the 40,000 paid is a refund due).
        $this->actingAs($a['sales'])->patch(route('sales-officer.sales.cancel', $saleId), ['cancel_reason' => 'wrong_customer'])->assertSessionHasNoErrors();
        $this->actingAs($a['admin'])->get(route('admin.inventory.stock-out'))->assertOk()
            ->assertSeeTextInOrder(['130,000.00', '40,000.00', 'Cancelled', '—', '—'])
            ->assertDontSeeText('-60,000.00');
    }

    public function test_cancelling_a_sale_requires_a_reason_and_shows_refund_due_when_it_had_payments(): void
    {
        $a = $this->actors();
        $saleId = $this->recordSale($a, 1000, 65, 15000);
        $route = route('sales-officer.sales.cancel', $saleId);

        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch($route, [])
            ->assertSessionHasErrors(['cancel_reason' => 'Select why this sale is being cancelled.']);
        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch($route, ['cancel_reason' => 'not_a_reason'])
            ->assertSessionHasErrors('cancel_reason');
        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch($route, ['cancel_reason' => 'other'])
            ->assertSessionHasErrors(['cancel_note' => 'Describe the reason when "Other" is selected.']);
        $this->assertDatabaseMissing('sales', ['id' => $saleId, 'status' => 'cancelled']);

        $this->actingAs($a['sales'])->patch($route, ['cancel_reason' => 'duplicate_entry', 'cancel_note' => 'Entered twice by mistake'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'status' => 'cancelled', 'cancellation_reason' => 'Duplicate entry — Entered twice by mistake']);
        $this->assertSame(15000.0, (float) DB::table('payments')->where('sale_id', $saleId)->sum('amount'), 'Payments are kept for the audit trail.');

        // The cancelled sale and its customer show the money owed back instead of "clear".
        $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()
            ->assertSee('Refund due: ₱15,000.00')
            ->assertSee('Duplicate entry — Entered twice by mistake');
        $this->actingAs($a['sales'])->get(route('sales-officer.sales.customers'))->assertOk()
            ->assertSee('Refund Due');

        // The cancel modal lists the reasons; a sale without payments stays plain "Cancelled".
        $unpaidId = $this->recordSale($a, 500, 65, 0);
        $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()
            ->assertSee('id="so-sale-cancel-'.$unpaidId.'"', false)
            ->assertSee('<option value="wrong_quantity_or_price"', false);
        $this->actingAs($a['sales'])->patch(route('sales-officer.sales.cancel', $unpaidId), ['cancel_reason' => 'customer_cancelled'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sales', ['id' => $unpaidId, 'cancellation_reason' => 'Customer cancelled the order']);
    }

    public function test_purchase_alert_messages_state_their_own_event(): void
    {
        $a = $this->actors();
        $itemId = $this->purchase($a, 10000);
        $this->schedule($a, [['purchase_item_id' => $itemId, 'quantity_liters' => 10000, 'garage_liters' => 10000, 'storage_location_id' => $this->tank($a), 'direct_liters' => 0]]);
        $this->driverLifts($a, (int) DB::table('hauls')->value('id'));

        // Event alerts only (Part 12's computed "not settled" alerts describe a state, not an event).
        $alerts = DB::table('alerts')->whereIn('type', ['purchase', 'haul'])
            ->where(fn ($query) => $query->whereNull('deduplication_key')->orWhere('deduplication_key', 'not like', 'sync:%'))
            ->get(['title', 'message']);
        $this->assertNotEmpty($alerts);
        foreach ($alerts as $alert) {
            // Title "PUR-… Received" must not say "Status: In Transit" in its body.
            $event = trim(substr($alert->title, strpos($alert->title, ' ')));
            $this->assertStringContainsString('Status: '.$event.' |', $alert->message, $alert->title);
        }
    }

    public function test_lifted_lifts_count_as_completed_in_the_dispatch_and_driver_cards(): void
    {
        $a = $this->actors();
        $itemId = $this->purchase($a, 10000);
        $this->schedule($a, [['purchase_item_id' => $itemId, 'quantity_liters' => 10000, 'garage_liters' => 10000, 'storage_location_id' => $this->tank($a), 'direct_liters' => 0]]);
        $this->driverLifts($a, (int) DB::table('hauls')->value('id'));
        $this->assertDatabaseHas('hauls', ['status' => 'lifted']);

        $dispatch = $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting'))->assertOk()->getContent();
        $this->assertSame('0', $this->card($dispatch, 'In Progress'));
        $this->assertSame('1', $this->card($dispatch, 'Completed'));

        $driver = $this->actingAs($a['driver'])->get(route('driver.fuel-lifting'))->assertOk()->getContent();
        $this->assertSame('0', $this->card($driver, 'Active'));
        $this->assertSame('1', $this->card($driver, 'Completed'));
    }

    /**
     * @param  array<string, mixed>  $a
     */
    private function purchase(array $a, int $liters): int
    {
        $this->actingAs($a['inventory'])->post(route('inventory-officer.inventory.purchases.store'), [
            'idempotency_key' => $this->uuid(), 'purchase_date' => '2026-10-14', 'depot_id' => $a['depot'], 'fuel_type_id' => $a['fuel'],
            'quantity_ordered_liters' => $liters, 'unit_cost' => 50, 'payment_status' => 'unpaid',
        ])->assertSessionHasNoErrors();
        // Opening Inventory creates the standard garage tanks.
        $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory'))->assertOk();

        return (int) DB::table('purchase_items')->orderByDesc('id')->value('id');
    }

    /**
     * @param  array<string, mixed>  $a
     */
    private function tank(array $a): int
    {
        return (int) DB::table('storage_locations')->where('type', 'garage')->where('status', 'active')->where('fuel_type_id', $a['fuel'])->orderBy('tank_number')->value('id');
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<int, array<string, mixed>>  $items
     */
    private function schedule(array $a, array $items)
    {
        return $this->actingAs($a['dispatch'])->from(route('dispatch.fuel-lifting'))->post(route('dispatch.fuel-lifting.hauls.store'), [
            'idempotency_key' => $this->uuid(), 'items' => $items, 'driver_user_id' => $a['driver']->id, 'truck_id' => $a['truck'],
            'scheduled_at' => '2026-10-14 08:00:00', 'form_context' => 'schedule-lift',
        ]);
    }

    /**
     * @param  array<string, mixed>  $a
     */
    private function driverLifts(array $a, int $haulId): void
    {
        foreach (['in_transit', 'lifted'] as $status) {
            $this->actingAs($a['driver'])->patch(route('driver.fuel-lifting.hauls.status', $haulId), ['idempotency_key' => $this->uuid(), 'lifting_status' => $status])
                ->assertSessionHasNoErrors();
        }
    }

    /**
     * @param  array<string, mixed>  $a
     */
    private function recordSale(array $a, int $liters, int $price, int $amountPaid): int
    {
        $this->actingAs($a['sales'])->post(route('sales-officer.sales.store'), [
            'idempotency_key' => $this->uuid(), 'customer_id' => $a['customer'], 'sale_date' => '2026-10-14',
            'items' => [['fuel_type_id' => $a['fuel'], 'quantity_liters' => $liters, 'unit_price' => $price]],
            'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment', 'amount_paid' => $amountPaid,
        ])->assertSessionHasNoErrors();

        return (int) DB::table('sales')->orderByDesc('id')->value('id');
    }

    private function card(string $html, string $label): string
    {
        $this->assertSame(1, preg_match('#<em>'.preg_quote($label, '#').'</em>\s*<strong>([^<]*)</strong>#', $html, $match), 'Missing card '.$label);

        return trim($match[1]);
    }
}
