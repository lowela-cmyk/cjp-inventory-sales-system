<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ModuleActivityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Part 6 follow-ups: overpayments are rejected at every entry point; red dots never fire for history.
 */
class FollowupsTest extends AuditTestCase
{
    public function test_overpayment_is_rejected_on_the_payment_form_for_sales_officer_and_admin(): void
    {
        $a = $this->actors();

        foreach ([[$a['sales'], 'sales-officer'], [$a['admin'], 'admin']] as [$user, $prefix]) {
            $saleId = $this->sale($a, 1000); // total 10,000

            $this->actingAs($user)->from(route($prefix.'.sales'))
                ->post(route($prefix.'.sales.payments.store', $saleId), $this->payment(4000))
                ->assertSessionHasNoErrors();

            // Payment History "+ Add New Payment" and Edit Sale "+ Add Payment" both post here.
            $this->actingAs($user)->from(route($prefix.'.sales'))
                ->post(route($prefix.'.sales.payments.store', $saleId), $this->payment(6000.01) + ['payment_sale_id' => $saleId])
                ->assertRedirect(route($prefix.'.sales'))
                ->assertSessionHasErrors(['amount' => 'Amount paid cannot exceed the remaining balance of PHP 6,000.00.'])
                ->assertSessionHasInput('amount', 6000.01)
                ->assertSessionHasInput('payment_sale_id', $saleId);

            $this->assertSame(4000.0, $this->paid($saleId), $prefix);
            $this->assertSame(1, DB::table('payments')->where('sale_id', $saleId)->count());
        }
    }

    public function test_the_payment_modal_reopens_with_the_error_under_amount(): void
    {
        $a = $this->actors();
        $saleId = $this->sale($a, 1000);

        $html = $this->actingAs($a['sales'])->withSession(['_old_input' => ['payment_sale_id' => $saleId, 'amount' => '99999']])
            ->withSession(['errors' => (new ViewErrorBag)->put('default', new MessageBag(['amount' => 'Amount paid cannot exceed the remaining balance of PHP 10,000.00.']))])
            ->get(route('sales-officer.sales'))->assertOk()->getContent();

        $paymentModal = 'payment_'.$saleId.'_amount';
        $this->assertMatchesRegularExpression('/data-open-modal-on-load="[^"]+"/', $html);
        $this->assertMatchesRegularExpression('/id="'.$paymentModal.'"[^>]*value="99999"/', $html);
        $this->assertMatchesRegularExpression('/id="'.$paymentModal.'".*?<p class="field-error[^"]*"[^>]*>Amount paid cannot exceed the remaining balance of PHP 10,000.00.<\/p>/s', $html);
    }

    public function test_initial_amount_payment_above_the_sale_total_is_rejected_for_both_roles(): void
    {
        $a = $this->actors();

        foreach ([[$a['sales'], 'sales-officer'], [$a['admin'], 'admin']] as [$user, $prefix]) {
            $before = DB::table('sales')->count();
            $this->actingAs($user)->from(route($prefix.'.sales'))->post(route($prefix.'.sales.store'), [
                'idempotency_key' => $this->uuid(), 'form_context' => 'record-sale', 'customer_id' => $a['customer'], 'sale_date' => '2026-10-15',
                'items' => [['fuel_type_id' => $a['fuel'], 'quantity_liters' => 100, 'unit_price' => 10]],
                'amount_paid' => 1000.01, 'payment_method' => 'cash_on_delivery', 'payment_terms' => 'cod', 'status' => 'confirmed',
                // A balance sent by the form is never trusted.
                'balance' => 999999,
            ])->assertSessionHasErrors();
            $this->assertSame($before, DB::table('sales')->count());
            $this->assertSame(0, DB::table('payments')->count());

            $this->actingAs($user)->from(route($prefix.'.sales'))->post(route($prefix.'.sales.store'), [
                'idempotency_key' => $this->uuid(), 'form_context' => 'record-sale', 'customer_id' => $a['customer'], 'sale_date' => '2026-10-15',
                'items' => [['fuel_type_id' => $a['fuel'], 'quantity_liters' => 100, 'unit_price' => 10]],
                'amount_paid' => 1000.01, 'payment_method' => 'cash_on_delivery', 'payment_terms' => 'cod', 'status' => 'confirmed',
            ])->assertSessionHasErrors(['amount_paid' => 'Amount paid cannot exceed the sale total.']);
            $this->assertSame($before, DB::table('sales')->count());
        }
    }

    public function test_edit_sale_cannot_record_a_payment_and_a_posted_balance_is_ignored(): void
    {
        $a = $this->actors();
        $saleId = $this->sale($a, 1000);

        $this->actingAs($a['sales'])->patch(route('sales-officer.sales.update', $saleId), ['amount_paid' => 50000])
            ->assertSessionHasErrors('amount_paid');
        $this->actingAs($a['sales'])->post(route('sales-officer.sales.payments.store', $saleId), $this->payment(100) + ['remaining_balance' => 999999, 'total_paid' => 0])
            ->assertSessionHasErrors(['remaining_balance', 'total_paid']);
        $this->assertSame(0, DB::table('payments')->count());
    }

    public function test_paying_exactly_the_remaining_balance_is_accepted_and_the_sale_becomes_paid(): void
    {
        $a = $this->actors();
        $saleId = $this->sale($a, 1000);

        $this->actingAs($a['sales'])->post(route('sales-officer.sales.payments.store', $saleId), $this->payment(2500))->assertSessionHasNoErrors();
        $this->actingAs($a['admin'])->post(route('admin.sales.payments.store', $saleId), $this->payment(7500))->assertSessionHasNoErrors();

        $this->assertSame(10000.0, $this->paid($saleId));
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'status' => 'paid']);
        $this->assertDatabaseHas('receivables', ['sale_id' => $saleId, 'status' => 'clear']);
        $this->actingAs($a['sales'])->post(route('sales-officer.sales.payments.store', $saleId), $this->payment(0.01))
            ->assertSessionHasErrors(['payment' => 'The selected sale is already fully paid.']);
        $this->actingAs($a['sales'])->post(route('sales-officer.sales.payments.store', $saleId), $this->payment(0))->assertSessionHasErrors('amount');
    }

    public function test_two_payments_that_together_exceed_the_balance_cannot_both_save(): void
    {
        $a = $this->actors();
        $saleId = $this->sale($a, 1000);

        // Two separate submissions (different idempotency keys), each within the balance on its own.
        // The second re-reads the paid total under the sale row lock, so it is rejected.
        $this->actingAs($a['sales'])->post(route('sales-officer.sales.payments.store', $saleId), $this->payment(7000))->assertSessionHasNoErrors();
        $this->actingAs($a['admin'])->post(route('admin.sales.payments.store', $saleId), $this->payment(7000))
            ->assertSessionHasErrors(['amount' => 'Amount paid cannot exceed the remaining balance of PHP 3,000.00.']);
        $this->assertSame(7000.0, $this->paid($saleId));
    }

    public function test_existing_users_have_every_module_marked_seen_by_the_data_migration(): void
    {
        $a = $this->actors();
        // A user that existed before the red-dot feature (inserted without model events).
        $legacyId = DB::table('users')->insertGetId(['name' => 'Legacy Admin', 'email' => 'legacy@example.test', 'password' => bcrypt('x'), 'role' => 'admin', 'status' => 'active', 'approval_status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);
        $this->purchase($a);
        DB::table('user_module_views')->delete();

        $this->travel(1)->seconds();
        (require database_path('migrations/2026_10_17_000001_mark_existing_users_modules_viewed.php'))->up();

        foreach (ModuleActivityService::ROLE_MODULES as $role => $modules) {
            $userId = $role === 'admin' ? $legacyId : (int) DB::table('users')->where('role', $role)->value('id');
            $this->assertEqualsCanonicalizing($modules, DB::table('user_module_views')->where('user_id', $userId)->pluck('module')->all(), $role);
        }
        $this->assertSame(7, DB::table('user_module_views')->where('user_id', $legacyId)->whereNotNull('last_viewed_at')->count());

        foreach (['admin' => User::find($legacyId), 'inventory' => $a['inventory'], 'sales' => $a['sales'], 'dispatch' => $a['dispatch'], 'driver' => $a['driver']] as $user) {
            $this->assertSame([], $this->unseen($user), $user->role);
        }
    }

    public function test_new_user_sees_no_dots_on_first_login_even_with_existing_records(): void
    {
        $a = $this->actors();
        $this->purchase($a);
        $this->travel(1)->seconds();

        $newcomer = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);
        $this->assertSame(7, DB::table('user_module_views')->where('user_id', $newcomer->id)->count());
        $this->actingAs($newcomer)->get(route('admin.dashboard'))->assertOk()->assertDontSee('data-nav-dot', false);

        // A self-registered user approved later starts fresh from the approval.
        $pending = User::factory()->create(['role' => 'sales_officer', 'status' => 'active', 'approval_status' => 'pending']);
        $this->travel(5)->minutes();
        $this->sale($a, 10);
        $this->travel(1)->minutes();
        $this->actingAs($a['admin'])->patch(route('admin.account-requests.update', $pending->id), ['approval_status' => 'approved'])->assertSessionHasNoErrors();
        $this->assertSame([], $this->unseen($pending->fresh()));
    }

    public function test_new_records_raise_dots_only_for_the_roles_that_can_see_them_and_opening_clears(): void
    {
        $a = $this->actors();
        $users = ['admin' => $a['admin'], 'inventory' => $a['inventory'], 'sales' => $a['sales'], 'dispatch' => $a['dispatch'], 'driver' => $a['driver']];
        foreach ($users as $user) {
            $this->assertSame([], $this->unseen($user));
        }

        $this->travel(1)->minutes();
        $itemId = $this->purchase($a);
        $this->assertDots($users, ['admin' => ['inventory', 'ledger'], 'inventory' => ['inventory', 'ledger'], 'sales' => [], 'dispatch' => ['ledger'], 'driver' => []]);

        $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory'))->assertOk()->assertDontSee('data-nav-dot="inventory"', false);
        $this->assertNotContains('inventory', $this->unseen($a['inventory']));

        $this->clearAll($a);
        $this->travel(1)->minutes();
        $this->sale($a, 10);
        $this->assertDots($users, ['admin' => ['sales'], 'inventory' => [], 'sales' => ['sales'], 'dispatch' => [], 'driver' => []]);

        $this->clearAll($a);
        $this->travel(1)->minutes();
        DB::table('hauls')->insert(['haul_code' => 'LFT-FU', 'purchase_id' => DB::table('purchase_items')->where('id', $itemId)->value('purchase_id'), 'purchase_item_id' => $itemId, 'depot_id' => $a['depot'], 'fuel_type_id' => $a['fuel'], 'truck_id' => $a['truck'], 'driver_user_id' => $a['driver']->id, 'scheduled_at' => now(), 'quantity_liters' => 100, 'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertDots($users, ['admin' => ['ledger', 'fuel-lifting'], 'inventory' => ['ledger'], 'sales' => [], 'dispatch' => ['ledger', 'fuel-lifting'], 'driver' => ['fuel-lifting']]);

        $this->clearAll($a);
        $this->travel(1)->minutes();
        DB::table('alerts')->insert(['alert_code' => 'ALT-FU', 'type' => 'payment', 'severity' => 'warning', 'title' => 'Payment alert', 'message' => 'x', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertDots($users, ['admin' => ['alerts'], 'inventory' => [], 'sales' => ['alerts'], 'dispatch' => [], 'driver' => []]);

        $this->actingAs($a['sales'])->get(route('sales-officer.alerts'))->assertOk()->assertDontSee('data-nav-dot', false);
        $this->assertSame([], $this->unseen($a['sales']));
    }

    public function test_a_login_does_not_raise_a_user_management_dot_for_admins(): void
    {
        $a = $this->actors();
        $this->travel(1)->minutes();

        $this->post(route('login.store'), ['username' => $a['sales']->email, 'password' => 'password'])->assertRedirect();
        $this->assertNotNull($a['sales']->fresh()->last_login_at);
        $this->assertSame([], $this->unseen($a['admin']));
    }

    /**
     * @param  array<string, User>  $users
     * @param  array<string, array<int, string>>  $expected
     */
    private function assertDots(array $users, array $expected): void
    {
        foreach ($users as $key => $user) {
            $this->assertEqualsCanonicalizing($expected[$key], $this->unseen($user), $key);
        }
    }

    /**
     * @param  array<string, mixed>  $a
     */
    private function clearAll(array $a): void
    {
        $this->travel(1)->seconds();
        foreach (['admin', 'inventory', 'sales', 'dispatch', 'driver'] as $key) {
            app(ModuleActivityService::class)->markAllViewed($a[$key]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function unseen(User $user): array
    {
        $this->app->forgetScopedInstances();

        return app(ModuleActivityService::class)->unseenModules($user);
    }

    /**
     * @param  array<string, mixed>  $a
     */
    private function sale(array $a, int $liters): int
    {
        $saleId = DB::table('sales')->insertGetId(['sale_code' => 'SLS-FU-'.substr($this->uuid(), 0, 8), 'customer_id' => $a['customer'], 'sale_date' => '2026-10-15', 'payment_method' => 'cash_on_delivery', 'payment_terms' => 'cod', 'status' => 'confirmed', 'delivery_status' => 'pending', 'created_by' => $a['sales']->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sale_items')->insert(['sale_id' => $saleId, 'fuel_type_id' => $a['fuel'], 'quantity_liters' => $liters, 'unit_price' => 10, 'line_total' => $liters * 10, 'fulfilled_quantity_liters' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('receivables')->insert(['sale_id' => $saleId, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        return $saleId;
    }

    /**
     * @param  array<string, mixed>  $a
     */
    private function purchase(array $a): int
    {
        $purchaseId = DB::table('purchases')->insertGetId(['purchase_code' => 'PUR-FU-'.substr($this->uuid(), 0, 8), 'depot_id' => $a['depot'], 'purchase_date' => '2026-10-15', 'payment_status' => 'unpaid', 'status' => 'ordered', 'workflow_status' => 'pending', 'created_by' => $a['inventory']->id, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('purchase_items')->insertGetId(['purchase_id' => $purchaseId, 'fuel_type_id' => $a['fuel'], 'quantity_ordered_liters' => 1000, 'unit_cost' => 50, 'line_total' => 50000, 'quantity_hauled_liters' => 0, 'status' => 'unlifted', 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payment(float $amount): array
    {
        return ['idempotency_key' => $this->uuid(), 'payment_date' => '2026-10-15', 'amount' => $amount, 'method' => 'cash_on_delivery'];
    }

    private function paid(int $saleId): float
    {
        return round((float) DB::table('payments')->where('sale_id', $saleId)->sum('amount'), 2);
    }
}
