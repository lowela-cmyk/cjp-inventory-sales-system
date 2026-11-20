<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PurchaseFlows;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Part 21 (November 14, 2026):
 * 1. Delivery status is the assigned Driver's responsibility. Admin and Sales Officers see it and cannot change it
 *    (no button, no route, no value accepted on Record Sales / Assign Source). A lift marked Lifted still delivers.
 * 2. No F1 % in the Record Sales Receivables modals; F1 lines already recorded keep their figures.
 * 3. Login: 5 failed attempts within 1 minute lock the username for 15 minutes, with a warning on the 4th.
 */
class Part21RevisionsTest extends AuditTestCase
{
    use PurchaseFlows;

    private const BAD_CREDENTIALS = 'The provided credentials do not match an active account.';

    private const WARNING = 'Warning: You have 1 login attempt remaining.';

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-14 09:00:00');
    }

    // ----- 1. Delivery status: driver responsibility -------------------------------------------------------------

    public function test_sales_and_admin_see_the_delivery_status_but_have_no_way_to_change_it(): void
    {
        $a = $this->actors();
        $saleId = $this->garageOrder($a);

        $this->assertFalse(Route::has('sales-officer.sales.delivery-status.update'));
        $this->assertFalse(Route::has('admin.sales.delivery-status.update'));
        $this->assertFileDoesNotExist(resource_path('views/sales-officer/partials/delivery-update-modal.blade.php'));

        foreach ([[$a['sales'], 'sales-officer'], [$a['admin'], 'admin']] as [$user, $prefix]) {
            $html = $this->actingAs($user)->get(route($prefix.'.sales'))->assertOk()->getContent();
            // Receivables row and both "Record Sales Receivables" modals: no driver Update Delivery button or form.
            $this->assertStringNotContainsString('btn-delivery-update', $html);
            $this->assertStringNotContainsString('delivery-status', $html);
            $this->assertSame(1, substr_count($html, 'name="delivery_status"'), 'Only the Receivables filter names delivery_status.');
            // Part 24 #2: a Garage to Client order has its own Delivery Status dropdown in Sales (the only one).
            $this->assertSame(1, substr_count($html, 'name="garage_delivery_status"'));
            $this->assertStringContainsString(route($prefix.'.sales.garage-delivery.update', $saleId), $html);
            // Still visible: the column, the badge and the note in the order's modal.
            $this->assertStringContainsString('<th>Delivery Status</th>', $html);
            $this->assertStringContainsString('Delivery status is updated with the Delivery Status dropdown above or by the assigned driver.', $html);

            // The old endpoint is gone, and the driver endpoint refuses them.
            $this->actingAs($user)->patch('/'.$prefix.'/sales/'.$saleId.'/delivery-status', ['delivery_status' => 'delivered'])->assertNotFound();
            $this->actingAs($user)->patch(route('driver.fuel-lifting.deliveries.status', $saleId), ['delivery_status' => 'delivered'])->assertForbidden();
        }

        $this->assertDatabaseHas('sales', ['id' => $saleId, 'delivery_status' => 'pending', 'delivery_status_updated_by' => null]);
        $this->assertSame(0, DB::table('stock_outs')->where('sale_id', $saleId)->count());
    }

    public function test_sales_cannot_set_a_delivery_status_when_recording_editing_or_assigning_an_order(): void
    {
        $a = $this->actors();
        $diesel = $this->purchaseLine($a, 'DSL', 10000);
        $line = ['purchase_item_id' => $diesel, 'quantity_liters' => 1000, 'unit_price' => 70];
        $refused = ['delivery_status' => 'Only the assigned driver can update the delivery status.'];

        foreach (['out_for_delivery', 'delivered'] as $status) {
            $this->recordOrder($a, $line, ['delivery_status' => $status, 'delivery_driver_user_id' => $a['driver']->id])->assertSessionHasErrors($refused);
        }
        $this->assertSame(0, DB::table('sales')->count());

        // Pending (what the form sends now: nothing) is fine.
        $this->recordOrder($a, $line, ['delivery_status' => 'pending'])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $sale = DB::table('sales')->where('id', $saleId)->first();

        // Edit Sales never took a delivery status and still does not.
        $this->actingAs($a['admin'])->from(route('admin.sales'))->patch(route('admin.sales.update', $saleId), [
            'edit_sale_id' => $saleId, 'customer_id' => $sale->customer_id, 'sale_date' => $sale->sale_date,
            'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment', 'delivery_status' => 'delivered',
        ])->assertSessionHasErrors('delivery_status');

        $this->assertDatabaseHas('sales', ['id' => $saleId, 'delivery_status' => 'pending']);
        $this->assertSame(10000.0, $this->remaining($diesel));
    }

    public function test_only_the_assigned_driver_updates_a_delivery_and_it_persists(): void
    {
        $a = $this->actors();
        $saleId = $this->garageOrder($a);
        $otherDriver = User::factory()->create(['role' => 'driver', 'status' => 'active', 'approval_status' => 'approved']);
        $url = route('driver.fuel-lifting.deliveries.status', $saleId);

        // Another driver neither sees the order nor can touch it.
        $this->assertSame([], collect($this->actingAs($otherDriver)->get(route('driver.fuel-lifting.deliveries'))->assertOk()->viewData('deliveryRows'))->all());
        $this->actingAs($otherDriver)->patch($url, ['delivery_status' => 'delivered'])->assertForbidden();
        foreach (['inventory', 'dispatch', 'sales', 'admin'] as $role) {
            $this->actingAs($a[$role])->patch($url, ['delivery_status' => 'delivered'])->assertForbidden();
        }
        $this->post(route('logout'));
        $this->patch($url, ['delivery_status' => 'delivered'])->assertRedirect(route('login'));
        $this->actingAs($a['driver'])->patch(route('driver.fuel-lifting.deliveries.status', 999999), ['delivery_status' => 'delivered'])->assertNotFound();
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'delivery_status' => 'pending']);

        // The assigned driver: the tab lists the order with its action, and each step is saved.
        $page = $this->actingAs($a['driver'])->get(route('driver.fuel-lifting.deliveries'))->assertOk();
        $page->assertSee('data-tab-target="deliveries"', false)->assertSee('Update Delivery');
        $row = collect($page->viewData('deliveryRows'))->firstWhere('id', $saleId);
        $this->assertSame(['pending', ['out_for_delivery', 'delivered'], true], [$row['delivery_status'], $row['delivery_update']['options'], $row['delivery_update']['available']]);

        $this->actingAs($a['driver'])->from(route('driver.fuel-lifting.deliveries'))->patch($url, ['delivery_status' => 'out_for_delivery', 'idempotency_key' => (string) Str::uuid()])
            ->assertSessionHasNoErrors()->assertRedirect(route('driver.fuel-lifting.deliveries'));
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'delivery_status' => 'out_for_delivery', 'delivery_status_updated_by' => $a['driver']->id, 'updated_by' => $a['driver']->id]);
        $this->assertSame(0, DB::table('stock_outs')->where('sale_id', $saleId)->count());

        $this->actingAs($a['driver'])->patch($url, ['delivery_status' => 'delivered'])->assertSessionHasNoErrors();
        $sale = DB::table('sales')->where('id', $saleId)->first();
        $this->assertSame(['delivered', (int) $a['driver']->id], [$sale->delivery_status, (int) $sale->delivery_status_updated_by]);
        $this->assertNotNull($sale->delivered_at);
        // Stock Out happened once, as it did when Sales pressed Update Delivery.
        $this->assertEquals(1000, DB::table('stock_outs')->where('sale_id', $saleId)->where('status', 'released')->sum('quantity_liters'));

        // Delivered is final for the driver too, and Sales / Admin read the same status.
        $this->actingAs($a['driver'])->from(route('driver.fuel-lifting.deliveries'))->patch($url, ['delivery_status' => 'pending'])
            ->assertSessionHasErrors(['delivery_status' => 'A delivered sale cannot be moved back because its inventory was already released.']);
        $this->assertFalse(collect($this->actingAs($a['driver'])->get(route('driver.fuel-lifting.deliveries'))->viewData('deliveryRows'))->firstWhere('id', $saleId)['delivery_update']['available']);
        foreach ([[$a['sales'], 'sales-officer.sales'], [$a['admin'], 'admin.sales']] as [$user, $route]) {
            $salesRow = collect($this->actingAs($user)->get(route($route))->viewData('sales')->items())->firstWhere('id', $saleId);
            $this->assertSame('delivered', $salesRow['delivery_status']);
            $this->assertStringContainsString($a['driver']->name, $salesRow['delivery_last_update']);
        }
    }

    public function test_admin_opening_the_driver_page_gets_no_deliveries_and_no_action(): void
    {
        $a = $this->actors();
        $this->garageOrder($a);

        // Admin may open every role's pages (role middleware); the delivery data and action stay with the driver.
        $page = $this->actingAs($a['admin'])->get(route('driver.fuel-lifting.deliveries'))->assertOk();
        $this->assertSame([], collect($page->viewData('deliveryRows'))->all());
        $page->assertSee('No Deliveries Assigned')->assertDontSee('btn-delivery-update', false);
    }

    public function test_a_lift_marked_lifted_still_delivers_its_orders_automatically(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 2000, 'unit_price' => 70], ['delivery_type' => 'depot_to_client'])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 2000, 'atl_number' => 'ATL-21']], [$saleId])->assertSessionHasNoErrors();
        $haulId = $this->lastHaulId();
        $status = fn (string $next): TestResponse => $this->actingAs($a['driver'])
            ->patch(route('driver.fuel-lifting.hauls.status', $haulId), ['idempotency_key' => (string) Str::uuid(), 'lifting_status' => $next]);

        // The lift's driver is the order's assigned driver: it is on their Deliveries tab.
        $row = collect($this->actingAs($a['driver'])->get(route('driver.fuel-lifting.deliveries'))->viewData('deliveryRows'))->firstWhere('id', $saleId);
        $this->assertSame(['pending', ['out_for_delivery']], [$row['delivery_status'], $row['delivery_update']['options']]);

        $status('in_transit')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'delivery_status' => 'pending']);
        $status('lifted')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sales', ['id' => $saleId, 'delivery_status' => 'delivered', 'delivery_status_updated_by' => $a['driver']->id]);
        $this->assertEquals(2000, DB::table('stock_outs')->where('sale_id', $saleId)->sum('quantity_liters'));
        $this->assertSame(18000.0, $this->remaining($premium));

        // Dispatch completing a lift (their own workflow) delivers too, as before.
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 1000, 'unit_price' => 70], ['delivery_type' => 'depot_to_client'])->assertSessionHasNoErrors();
        $second = $this->lastSaleId();
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 1000, 'atl_number' => 'ATL-22']], [$second])->assertSessionHasNoErrors();
        $this->completeLift($a, $this->lastHaulId());
        $this->assertDatabaseHas('sales', ['id' => $second, 'delivery_status' => 'delivered']);
    }

    // ----- 2. No F1 % in Record Sales Receivables ------------------------------------------------------------------

    public function test_the_record_sales_receivables_modals_have_no_f1_percentage_and_recorded_f1_lines_keep_their_figures(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 10000);
        $f1 = $this->purchaseLine($a, 'F1', 5000);

        // An F1 order recorded through the API (older forms / records): 10% of 4,000 L is F1.
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 4000, 'unit_price' => 50, 'f1_percentage' => 10, 'f1_purchase_item_id' => $f1, 'f1_unit_price' => 40], [
            'delivery_truck_id' => $a['truck'], 'delivery_driver_user_id' => $a['driver']->id,
        ])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $item = DB::table('sale_items')->where('sale_id', $saleId)->first();
        $this->assertSame([10.0, 400.0, 196000.0], [(float) $item->f1_percentage, (float) $item->f1_quantity_liters, (float) $item->line_total]);

        // A payment puts the order into the "correct QTY or prices" section (the one that still said F1 %).
        $this->actingAs($a['sales'])->post(route('sales-officer.sales.payments.store', $saleId), [
            'idempotency_key' => (string) Str::uuid(), 'payment_date' => '2026-11-14', 'amount' => 100, 'method' => 'cash_on_delivery',
        ])->assertSessionHasNoErrors();

        $html = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $add = substr($html, strpos($html, 'id="so-sales-add"'));
        $add = substr($add, 0, strpos($add, 'id="so-sales-edit-'.$saleId.'"'));
        $edit = substr($html, strpos($html, 'id="so-sales-edit-'.$saleId.'"'));
        $edit = substr($edit, 0, strpos($edit, 'id="so-payment-history-'.$saleId.'"'));
        foreach ([$add, $edit] as $modal) {
            $this->assertStringNotContainsString('F1 %', $modal);
            $this->assertStringNotContainsString('F1%', $modal);
            $this->assertStringNotContainsString('Select F1', $modal);
        }
        $this->assertStringContainsString('Correct QTY or prices. The fuel and purchase stay as recorded.', $edit);
        // The recorded percentage travels unchanged, so saving the form does not drop the F1 portion.
        $this->assertStringContainsString('name="items[0][f1_percentage]" value="'.number_format((float) $item->f1_percentage, 2, '.', '').'"', $edit);

        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.update', $saleId), [
            'edit_sale_id' => $saleId, 'customer_id' => $a['customer'], 'sale_date' => '2026-10-15', 'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment',
            'items' => [['purchase_item_id' => $premium, 'f1_purchase_item_id' => $f1, 'quantity_liters' => 4000, 'f1_percentage' => $item->f1_percentage, 'unit_price' => 55, 'f1_unit_price' => 40]],
        ])->assertSessionHasNoErrors();
        $after = DB::table('sale_items')->where('sale_id', $saleId)->first();
        $this->assertSame([10.0, 400.0, 3600 * 55 + 400 * 40.0], [(float) $after->f1_percentage, (float) $after->f1_quantity_liters, (float) $after->line_total]);

        // F1 stock, the F1 columns and the split on delivery are untouched.
        $this->driverDelivery($saleId)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('stock_outs', ['sale_id' => $saleId, 'purchase_item_id' => $f1, 'portion' => 'f1', 'quantity_liters' => 400]);
        $this->assertSame([6400.0, 4600.0], [$this->remaining($premium), $this->remaining($f1)]);
    }

    // ----- 3. Login attempts ---------------------------------------------------------------------------------------

    public function test_the_fourth_failed_login_warns_and_the_fifth_locks_for_fifteen_minutes(): void
    {
        $user = $this->loginUser();

        foreach ([1, 2, 3] as $attempt) {
            $this->attempt($user->email)->assertStatus(302)->assertSessionHasErrors(['username' => self::BAD_CREDENTIALS])->assertSessionMissing('login_warning');
        }
        $this->attempt($user->email)->assertSessionHasErrors(['username' => self::BAD_CREDENTIALS])->assertSessionHas('login_warning', self::WARNING);
        // The warning is on the page the browser is sent back to.
        $this->get(route('login'))->assertSee(self::WARNING)->assertSee(self::BAD_CREDENTIALS);

        $locked = $this->attempt($user->email)->assertStatus(429);
        $locked->assertSee('Too many failed login attempts. Please try again in 15 minutes.')
            ->assertSee('data-login-lockout="900"', false)
            ->assertSee('value="'.$user->email.'"', false)
            ->assertDontSee(self::WARNING)
            ->assertHeader('Retry-After', 900);

        // Locked: the right password is refused too, and the wait shown is what is left.
        $this->travel(5)->minutes();
        $this->attempt($user->email, 'password')->assertStatus(429)->assertSee('Please try again in 10 minutes.')->assertSee('data-login-lockout="600"', false);
        $this->assertGuest();
        $this->travel(9)->minutes();
        $this->travel(30)->seconds();
        $this->attempt($user->email, 'password')->assertStatus(429)->assertSee('Please try again in 30 seconds.');
        // Attempts made while locked did not extend the lock.
        $this->travel(31)->seconds();
        $this->attempt($user->email, 'password')->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_lock_expires_and_the_counter_starts_again(): void
    {
        $user = $this->loginUser();
        foreach (range(1, 5) as $attempt) {
            $last = $this->attempt($user->email);
        }
        $last->assertStatus(429);

        $this->travel(15)->minutes();
        $this->travel(1)->seconds();
        // A fresh window: plain errors again, the warning on the 4th, the lock on the 5th.
        foreach ([1, 2, 3] as $attempt) {
            $this->attempt($user->email)->assertStatus(302)->assertSessionMissing('login_warning');
        }
        $this->attempt($user->email)->assertSessionHas('login_warning', self::WARNING);
        $this->attempt($user->email)->assertStatus(429);
    }

    public function test_failed_attempts_older_than_a_minute_do_not_count(): void
    {
        $user = $this->loginUser();
        foreach (range(1, 4) as $attempt) {
            $this->attempt($user->email)->assertStatus(302);
        }

        $this->travel(61)->seconds();
        // The window expired: this is failure 1 of a new minute, not the 5th.
        $this->attempt($user->email)->assertStatus(302)->assertSessionHasErrors('username')->assertSessionMissing('login_warning');
        $this->attempt($user->email, 'password')->assertRedirect(route('admin.dashboard'));
    }

    public function test_a_successful_login_resets_the_failed_attempt_counter(): void
    {
        $user = $this->loginUser();
        foreach (range(1, 4) as $attempt) {
            $this->attempt($user->email);
        }
        $this->attempt($user->email, 'password')->assertRedirect(route('admin.dashboard'));
        $this->post(route('logout'));

        // Four more failures are allowed again; without the reset the first of them would have locked.
        foreach ([1, 2, 3] as $attempt) {
            $this->attempt($user->email)->assertStatus(302)->assertSessionMissing('login_warning');
        }
        $this->attempt($user->email)->assertStatus(302)->assertSessionHas('login_warning', self::WARNING);
    }

    public function test_the_limit_is_per_username_and_gives_no_hint_whether_an_account_exists(): void
    {
        $user = $this->loginUser();
        $run = function (string $username): array {
            $seen = [];
            foreach (range(1, 6) as $attempt) {
                $response = $this->attempt($username);
                $seen[] = [
                    $response->getStatusCode(),
                    $response->getStatusCode() === 429 ? 'locked' : session('errors')->first('username'),
                    session('login_warning'),
                ];
                // The next request starts without this one's flashed messages, as in a browser.
                $this->get(route('login'));
            }

            return $seen;
        };

        // A real account and a made-up one answer identically, attempt for attempt.
        $real = $run($user->email);
        $this->assertSame($real, $run('nobody-'.Str::random(6).'@example.com'));
        $this->assertSame([302, self::BAD_CREDENTIALS, null], $real[0]);
        $this->assertSame([302, self::BAD_CREDENTIALS, self::WARNING], $real[3]);
        $this->assertSame([429, 'locked', null], $real[4]);
        $this->assertSame([429, 'locked', null], $real[5]);

        // One locked username does not lock another, and letter case or spaces do not make a new counter.
        $other = $this->loginUser();
        $this->attempt($other->email, 'password')->assertRedirect(route('admin.dashboard'));
        $this->post(route('logout'));
        $this->attempt('  '.Str::upper($user->email).' ', 'password')->assertStatus(429);
    }

    public function test_the_lock_follows_the_account_across_ip_addresses(): void
    {
        // Without a trusted proxy X-Forwarded-For is ignored, so rotating it changes nothing: still locked on the 5th.
        $user = $this->loginUser();
        $statuses = [];
        foreach (range(1, 6) as $i) {
            $statuses[] = $this->withHeader('X-Forwarded-For', '198.51.100.'.$i)->post(route('login.store'), ['username' => $user->email, 'password' => 'wrong'])->getStatusCode();
        }
        $this->assertSame([302, 302, 302, 302, 429, 429], $statuses);
    }

    public function test_the_login_page_counts_the_lockout_down(): void
    {
        $js = File::get(resource_path('js/app.js'));
        $this->assertStringContainsString("document.querySelector('[data-login-lockout]')", $js);
        $this->assertStringContainsString('submit.disabled = true;', $js);
        $this->assertStringContainsString('.login-warning {', File::get(resource_path('css/app.css')));
    }

    // ----- helpers -------------------------------------------------------------------------------------------------

    private function loginUser(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved', 'password' => 'password']);
    }

    private function attempt(string $username, string $password = 'wrong-password'): TestResponse
    {
        return $this->from(route('login'))->post(route('login.store'), ['username' => $username, 'password' => $password]);
    }

    /**
     * A Garage to Client order of 1,000 L Premium with Delivery Details naming $a['driver'] (Pending).
     *
     * @param  array<string, mixed>  $a
     */
    private function garageOrder(array $a): int
    {
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 5000]])->assertSessionHasNoErrors();
        $haul = $this->lastHaulId();
        $this->completeLift($a, $haul);
        $tank = (int) DB::table('storage_locations')->where('type', 'garage')->where('status', 'active')->where('fuel_type_id', $this->fuel('PREM'))->where('tank_number', 1)->value('id');
        $this->actingAs($a['admin'])->post(route('admin.fuel-lifting.hauls.unload-to-garage', $haul), ['unload' => [$haul => [$tank => 3000]]])->assertSessionHasNoErrors();
        $this->recordOrder($a, ['purchase_item_id' => 'tank:'.$tank, 'quantity_liters' => 1000, 'unit_price' => 70], ['delivery_type' => 'garage_to_client'] + $this->garageDelivery($a))
            ->assertSessionHasNoErrors();

        return $this->lastSaleId();
    }
}
