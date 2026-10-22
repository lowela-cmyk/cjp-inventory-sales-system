<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SystemAnalysisRevisionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_page_shows_full_profile_without_lift_panel_and_filters_by_depot(): void
    {
        $records = $this->liftingRecords();
        $otherDepot = DB::table('depots')->insertGetId(['depot_code' => 'DEP-SA-2', 'name' => 'Northern Terminal', 'address' => 'North Road', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->haul($records, $records['depot'], 'LFT-SA-SOUTH');
        $this->haul($records, $otherDepot, 'LFT-SA-NORTH');

        $page = $this->actingAs($records['driver'])
            ->withSession(['status' => 'Withdrawal receipt uploaded successfully.'])
            ->get(route('driver.fuel-lifting'));

        $page->assertOk()
            // One notification channel (2.1): the message is a toast, never an inline banner too.
            ->assertDontSee('driver-notice', false)
            ->assertSee('class="cjp-toast cjp-toast-success"', false)
            ->assertSee('Withdrawal receipt uploaded successfully.')
            ->assertSee('driver-profile-grid', false)
            ->assertSee('Emergency Contact')
            ->assertSee('License No.')
            ->assertSee('Account Status')
            ->assertDontSee('Next assigned lift')
            ->assertDontSee('name="source_type"', false)
            ->assertSee('name="depot_id"', false)
            ->assertSee('Northern Terminal')
            ->assertSee('LFT-SA-SOUTH')
            ->assertSee('LFT-SA-NORTH');

        $this->actingAs($records['driver'])
            ->get(route('driver.fuel-lifting', ['depot_id' => $otherDepot]))
            ->assertOk()
            ->assertSee('LFT-SA-NORTH')
            ->assertDontSee('LFT-SA-SOUTH');
    }

    public function test_login_welcome_shows_only_as_toast_for_every_role_while_other_flashes_stay_inline(): void
    {
        $welcome = 'Welcome back to CJP Southern Star. Your dashboard is ready.';

        foreach (['admin', 'dispatch_officer', 'inventory_officer', 'sales_officer', 'driver'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active', 'approval_status' => 'approved']);
            if ($role === 'driver') {
                DB::table('driver_profiles')->insert(['user_id' => $user->id, 'driver_code' => 'DRV-LOGIN', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
            }

            $page = $this->followingRedirects()
                ->post(route('login.store'), ['username' => $user->email, 'password' => 'password']);

            $page->assertOk()
                ->assertSee('Login success')
                ->assertSee('cjp-toast cjp-toast-success', false)
                ->assertDontSee('role="status">'.$welcome, false)
                ->assertDontSee('<div class="alert-title">'.$welcome, false);
            $this->assertSame(1, substr_count($page->getContent(), $welcome), "Welcome text should appear only in the toast for {$role}.");

            $this->post(route('logout'));
        }

        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active', 'approval_status' => 'approved']);
        $this->actingAs($salesOfficer)
            ->withSession(['status' => 'Customer record created successfully.'])
            ->get(route('sales-officer.sales'))
            ->assertOk()
            ->assertDontSee('admin-flash', false)
            ->assertSee('class="cjp-toast cjp-toast-success"', false)
            ->assertSee('Customer record created successfully.');
    }

    public function test_dispatch_filters_apply_without_date_from_and_lift_date_matches_one_day(): void
    {
        $records = $this->liftingRecords();
        $this->haul($records, $records['depot'], 'LFT-SA-DAY1', '2026-09-19 08:00:00');
        $this->haul($records, $records['depot'], 'LFT-SA-DAY2', '2026-09-20 08:00:00');

        $page = $this->actingAs($records['dispatch'])->get(route('dispatch.fuel-lifting'));
        $page->assertOk()
            ->assertSee('data-auto-filter', false)
            ->assertDontSee('name="date_from"', false)
            ->assertDontSee('name="date_to"', false)
            ->assertDontSee('>Filter</button>', false);

        $this->actingAs($records['dispatch'])
            ->get(route('dispatch.fuel-lifting', ['lift_date' => '2026-09-20']))
            ->assertOk()
            ->assertViewHas('filters', fn (array $filters): bool => $filters['lift_date'] === '2026-09-20' && ! array_key_exists('date_from', $filters))
            ->assertViewHas('scheduledRows', fn ($rows): bool => $rows->pluck('cells.0')->all() === [DB::table('lifting_schedules')->where('scheduled_at', '2026-09-20 08:00:00')->value('schedule_code')]);
    }

    public function test_purchase_recorded_in_inventory_appears_in_schedule_lift_dropdown_and_can_be_scheduled(): void
    {
        $records = $this->liftingRecords();

        $this->actingAs($records['inventory'])->post(route('inventory-officer.inventory.purchases.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'purchase_date' => '2026-09-18',
            'depot_id' => $records['depot'],
            'fuel_type_id' => $records['fuel'],
            'quantity_ordered_liters' => 12000,
            'unit_cost' => 50,
            'payment_status' => 'unpaid',
        ])->assertRedirect();

        $item = DB::table('purchase_items')->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')->first(['purchase_items.id', 'purchases.purchase_code']);

        $this->actingAs($records['dispatch'])->get(route('dispatch.fuel-lifting'))
            ->assertOk()
            // Part 2 revisions: "Fuel / Remaining in Depot" shows the liters still at the depot.
            ->assertSee($item->purchase_code.' | Diesel | Southern Terminal')
            ->assertSee('data-remaining="12000"', false)
            ->assertSee('data-remaining-label="DIESEL - 12,000.00 L"', false)
            ->assertDontSee('No purchases have fuel remaining in the depot');

        $this->actingAs($records['dispatch'])->post(route('dispatch.fuel-lifting.hauls.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [['purchase_item_id' => $item->id, 'quantity_liters' => 5000]],
            'driver_user_id' => $records['driver']->id,
            'truck_id' => $records['truck'],
            'scheduled_at' => '2026-09-19 08:00:00',
        ])->assertRedirect(route('dispatch.fuel-lifting'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('hauls', ['purchase_item_id' => $item->id, 'quantity_liters' => '5000.00', 'status' => 'scheduled']);
        $this->actingAs($records['dispatch'])->get(route('dispatch.fuel-lifting'))
            ->assertOk()
            ->assertSee('data-remaining-label="DIESEL - 7,000.00 L"', false);
    }

    public function test_schedule_lift_dropdown_hides_purchases_that_cannot_be_saved(): void
    {
        $records = $this->liftingRecords();
        $inactiveDepot = DB::table('depots')->insertGetId(['depot_code' => 'DEP-SA-OFF', 'name' => 'Closed Terminal', 'status' => 'inactive', 'created_at' => now(), 'updated_at' => now()]);
        $this->purchaseItem($records, $inactiveDepot, 'PUR-SA-CLOSED', 9000);

        $this->actingAs($records['dispatch'])->get(route('dispatch.fuel-lifting'))
            ->assertOk()
            ->assertDontSee('PUR-SA-CLOSED')
            ->assertSee('No purchases have fuel remaining in the depot')
            ->assertSee('No purchases in the depot');
    }

    public function test_customer_bank_name_is_saved_validated_and_displayed(): void
    {
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($salesOfficer)->post(route('sales-officer.sales.customers.store'), [
            'name' => 'Bank Customer',
            'company_name' => 'Bank Customer Co.',
            'phone' => '09171234567',
            'bank_name' => 'BDO Unibank',
            'payment_status' => 'clear',
            'status' => 'active',
        ])->assertRedirect(route('sales-officer.sales.customers'));

        $customerId = (int) DB::table('customers')->value('id');
        $this->assertDatabaseHas('customers', ['id' => $customerId, 'bank_name' => 'BDO Unibank']);

        $this->actingAs($salesOfficer)->patch(route('sales-officer.sales.customers.update', $customerId), [
            'name' => 'Bank Customer',
            'company_name' => 'Bank Customer Co.',
            'bank_name' => 'Metrobank',
            'payment_status' => 'clear',
            'status' => 'active',
        ])->assertRedirect(route('sales-officer.sales.customers'));
        $this->assertDatabaseHas('customers', ['id' => $customerId, 'bank_name' => 'Metrobank']);

        $this->actingAs($salesOfficer)->from(route('sales-officer.sales.customers'))->patch(route('sales-officer.sales.customers.update', $customerId), [
            'name' => 'Bank Customer',
            'company_name' => 'Bank Customer Co.',
            'bank_name' => str_repeat('B', 256),
            'payment_status' => 'clear',
            'status' => 'active',
        ])->assertSessionHasErrors('bank_name');

        $this->actingAs($salesOfficer)->get(route('sales-officer.sales.customers'))
            ->assertOk()
            ->assertSee('<th>Bank Name</th>', false)
            ->assertSee('name="bank_name"', false)
            ->assertSee('Metrobank');

        $this->actingAs($admin)->get(route('admin.sales'))
            ->assertOk()
            ->assertSee('<th>Bank Name</th>', false)
            ->assertSee('Metrobank');
    }

    public function test_initial_amount_payment_creates_first_payment_and_updates_status(): void
    {
        $records = $this->salesRecords();

        $this->actingAs($records['salesOfficer'])->post(route('sales-officer.sales.store'), $this->salePayload($records, ['amount_paid' => '4000.00']))
            ->assertRedirect(route('sales-officer.sales'))
            ->assertSessionHasNoErrors();

        $sale = DB::table('sales')->latest('id')->first();
        $this->assertSame('partially_paid', $sale->status);
        $this->assertSame(4000.0, (float) DB::table('payments')->where('sale_id', $sale->id)->sum('amount'));

        $this->actingAs($records['salesOfficer'])->from(route('sales-officer.sales'))
            ->post(route('sales-officer.sales.store'), $this->salePayload($records, ['amount_paid' => '10000.01']))
            ->assertSessionHasErrors('amount_paid');
    }

    public function test_payment_history_form_is_hidden_until_requested_and_reopens_after_saving(): void
    {
        $records = $this->salesRecords();
        $this->actingAs($records['salesOfficer'])->post(route('sales-officer.sales.store'), $this->salePayload($records));
        $saleId = (int) DB::table('sales')->value('id');

        foreach ([[$records['salesOfficer'], 'sales-officer'], [$records['admin'], 'admin']] as [$user, $prefix]) {
            $page = $this->actingAs($user)->get(route($prefix.'.sales'));
            $page->assertOk()
                ->assertSee('+ Add New Payment')
                ->assertSee('hidden  data-payment-form>', false)
                ->assertSee('data-modal-swap=', false)
                ->assertSee('>Back</button>', false)
                ->assertDontSee('data-open-modal-on-load', false);
        }

        $this->actingAs($records['admin'])->post(route('admin.sales.payments.store', $saleId), [
            'idempotency_key' => (string) Str::uuid(),
            'payment_sale_id' => $saleId,
            'amount' => 2500,
            'method' => 'cash_on_delivery',
            'payment_date' => '2026-09-25',
        ])->assertRedirect(route('admin.sales'))->assertSessionHas('payment_sale_id', $saleId);

        $this->assertSame(2500.0, (float) DB::table('payments')->where('sale_id', $saleId)->sum('amount'));
        $this->assertSame('partially_paid', DB::table('sales')->where('id', $saleId)->value('status'));

        $this->actingAs($records['admin'])->withSession(['payment_sale_id' => $saleId])->get(route('admin.sales'))
            ->assertOk()
            ->assertSee('data-open-modal-on-load="so-payment-history-'.$saleId.'"', false)
            ->assertSee('7,500.00');
    }

    public function test_inventory_filters_work_per_tab_and_placeholder_buttons_are_gone(): void
    {
        $records = $this->liftingRecords();
        $otherDepot = DB::table('depots')->insertGetId(['depot_code' => 'DEP-SA-2', 'name' => 'Northern Terminal', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->purchaseItem($records, $records['depot'], 'PUR-SA-SOUTH', 5000, '2026-09-10');
        $this->purchaseItem($records, $otherDepot, 'PUR-SA-NORTH', 6000, '2026-09-11');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $page = $this->actingAs($admin)->get(route('admin.inventory'));
        $page->assertOk()
            ->assertDontSee('@hidden', false)
            ->assertSee('name="status"', false)
            ->assertSee('name="date"', false)
            ->assertSee('name="depot_id"', false)
            ->assertSee('name="fuel_type_id"', false);
        $this->assertSame(1, substr_count($page->getContent(), 'data-modal-open="io-depot-add"'));

        $this->actingAs($admin)->get(route('admin.inventory', ['depot_id' => $otherDepot]))
            ->assertOk()->assertSee('PUR-SA-NORTH')->assertDontSee('PUR-SA-SOUTH');
        $this->actingAs($admin)->get(route('admin.inventory', ['date' => '2026-09-10', 'search' => 'PUR-SA']))
            ->assertOk()->assertSee('PUR-SA-SOUTH')->assertDontSee('PUR-SA-NORTH');
        $this->actingAs($admin)->get(route('admin.inventory', ['status' => 'pending', 'fuel_type_id' => $records['fuel'], 'depot_id' => $records['depot']]))
            ->assertOk()->assertSee('PUR-SA-SOUTH')->assertDontSee('PUR-SA-NORTH');
        $this->actingAs($admin)->get(route('admin.inventory', ['status' => 'completed']))
            ->assertOk()->assertDontSee('PUR-SA-SOUTH')->assertDontSee('PUR-SA-NORTH');

        $this->actingAs($admin)->get(route('admin.inventory.depots', ['status' => 'inactive']))
            ->assertOk()->assertViewHas('depotRows', fn ($rows): bool => $rows->count() === 0);
        $this->actingAs($admin)->get(route('admin.inventory.stock-out', ['status' => 'released', 'date' => '2026-09-10']))->assertOk();
        $this->actingAs($admin)->get(route('admin.inventory.stock-in', ['date' => '2026-09-10', 'depot_id' => $otherDepot, 'fuel_type_id' => $records['fuel']]))->assertOk();
        $this->actingAs($admin)->from(route('admin.inventory.depots'))->get(route('admin.inventory.depots', ['status' => 'pending']))
            ->assertSessionHasErrors('status');
    }

    public function test_admin_ledger_tables_use_centered_ledger_layout(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $page = $this->actingAs($admin)->get(route('admin.ledger'));
        $page->assertOk();
        $this->assertSame(2, substr_count($page->getContent(), 'class="admin-table ledger-table"'));
    }

    /**
     * @return array<string, mixed>
     */
    private function liftingRecords(): array
    {
        $inventory = User::factory()->create(['role' => 'inventory_officer', 'status' => 'active', 'approval_status' => 'approved']);
        $dispatch = User::factory()->create(['role' => 'dispatch_officer', 'status' => 'active', 'approval_status' => 'approved']);
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active', 'approval_status' => 'approved', 'phone' => '09170000000']);
        DB::table('driver_profiles')->insert(['user_id' => $driver->id, 'driver_code' => 'DRV-SA', 'license_number' => 'LIC-SA-1', 'emergency_contact' => '09179999999', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        $depot = DB::table('depots')->insertGetId(['depot_code' => 'DEP-SA', 'name' => 'Southern Terminal', 'address' => 'Official Depot Road', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $fuel = DB::table('fuel_types')->where('code', 'DSL')->value('id') ?: DB::table('fuel_types')->insertGetId(['code' => 'DSL', 'name' => 'Diesel', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $truck = DB::table('trucks')->insertGetId(['truck_code' => 'TRK-SA', 'capacity_liters' => 30000, 'truck_type' => 'hauling', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);

        return compact('inventory', 'dispatch', 'driver', 'depot', 'fuel', 'truck');
    }

    /**
     * @param  array<string, mixed>  $records
     */
    private function purchaseItem(array $records, int $depotId, string $code, int $quantity, string $date = '2026-09-18'): int
    {
        $purchase = DB::table('purchases')->insertGetId([
            'purchase_code' => $code, 'depot_id' => $depotId, 'purchase_date' => $date, 'payment_status' => 'unpaid',
            'status' => 'ordered', 'workflow_status' => 'pending', 'created_by' => $records['inventory']->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchase, 'fuel_type_id' => $records['fuel'], 'quantity_ordered_liters' => $quantity,
            'unit_cost' => 50, 'line_total' => $quantity * 50, 'quantity_hauled_liters' => 0, 'status' => 'unlifted',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $records
     */
    private function haul(array $records, int $depotId, string $code, string $scheduledAt = '2026-09-19 08:00:00'): int
    {
        $itemId = $this->purchaseItem($records, $depotId, 'PUR-'.$code, 5000);
        $item = DB::table('purchase_items')->where('id', $itemId)->first();
        $scheduleId = DB::table('lifting_schedules')->insertGetId([
            'schedule_code' => 'SCH-'.$code, 'depot_id' => $depotId, 'truck_id' => $records['truck'], 'driver_user_id' => $records['driver']->id,
            'scheduled_at' => $scheduledAt, 'status' => 'scheduled', 'created_by' => $records['dispatch']->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('hauls')->insertGetId([
            'lifting_schedule_id' => $scheduleId, 'haul_code' => $code, 'purchase_id' => $item->purchase_id, 'purchase_item_id' => $itemId,
            'depot_id' => $depotId, 'fuel_type_id' => $records['fuel'], 'truck_id' => $records['truck'], 'driver_user_id' => $records['driver']->id,
            'scheduled_at' => $scheduledAt, 'quantity_liters' => 5000, 'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function salesRecords(): array
    {
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-SA', 'name' => 'Revision Customer', 'company_name' => 'Revision Company',
            'payment_status' => 'clear', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $fuelTypeId = (int) DB::table('fuel_types')->where('code', 'DSL')->where('status', 'active')->value('id');

        return compact('salesOfficer', 'admin', 'customerId', 'fuelTypeId');
    }

    /**
     * @param  array<string, mixed>  $records
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function salePayload(array $records, array $overrides = []): array
    {
        return array_merge([
            'idempotency_key' => (string) Str::uuid(),
            'customer_id' => $records['customerId'],
            'sale_date' => '2026-09-24',
            'items' => [['fuel_type_id' => $records['fuelTypeId'], 'quantity_liters' => '1000', 'unit_price' => '10.00']],
            'amount_paid' => '0.00',
            'payment_method' => 'cash_on_delivery',
            'payment_terms' => 'installment',
            'status' => 'confirmed',
        ], $overrides);
    }
}
