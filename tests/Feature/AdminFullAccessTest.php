<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Admin may perform every function of every role (central rule in EnsureUserHasRole), from Admin
 * pages, with each action attributed to the admin. Every other role keeps its own restrictions.
 */
class AdminFullAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_performs_inventory_officer_functions_from_admin_pages(): void
    {
        $r = $this->records();

        $this->actingAs($r['admin'])->post(route('admin.inventory.depots.store'), [
            'depot_code' => 'DEP-ADMIN', 'name' => 'Admin Depot', 'status' => 'active',
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('depots', ['depot_code' => 'DEP-ADMIN']);

        $this->actingAs($r['admin'])->post(route('admin.inventory.purchases.store'), [
            'idempotency_key' => (string) Str::uuid(), 'purchase_date' => '2026-10-10', 'depot_id' => $r['depot'], 'fuel_type_id' => $r['fuel'],
            'quantity_ordered_liters' => 8000, 'unit_cost' => 50, 'payment_status' => 'unpaid',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('purchases', ['created_by' => $r['admin']->id]);

        // Stock-out is recorded automatically by the sale; there is no manual stock-out entry.
        $this->actingAs($r['admin'])->get(route('admin.inventory.stock-out'))->assertOk()->assertDontSee('+ Record Stock-Out');
        $this->actingAs($r['admin'])->post('/admin/inventory/stock-out', ['quantity_liters' => 500])->assertStatus(405);
    }

    public function test_admin_performs_sales_officer_functions_including_customers_from_the_admin_sales_page(): void
    {
        $r = $this->records();

        $page = $this->actingAs($r['admin'])->get(route('admin.sales'))->assertOk();
        $page->assertSee('+ Record Sales')->assertSee('+ Add Customers')
            ->assertSee('action="'.route('admin.sales.customers.store').'"', false);

        $this->actingAs($r['admin'])->post(route('admin.sales.customers.store'), [
            'name' => 'Admin Customer', 'company_name' => 'Admin Customer Co.', 'payment_status' => 'clear', 'status' => 'active',
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.sales.customers'));
        $customerId = (int) DB::table('customers')->where('company_name', 'Admin Customer Co.')->value('id');

        $this->actingAs($r['admin'])->patch(route('admin.sales.customers.update', $customerId), [
            'name' => 'Admin Customer', 'company_name' => 'Admin Customer Co.', 'bank_name' => 'BPI', 'payment_status' => 'clear', 'status' => 'active',
        ])->assertRedirect(route('admin.sales.customers'));
        $this->assertDatabaseHas('customers', ['id' => $customerId, 'bank_name' => 'BPI']);

        $this->actingAs($r['admin'])->post(route('admin.sales.store'), [
            'idempotency_key' => (string) Str::uuid(), 'customer_id' => $customerId, 'sale_date' => '2026-10-12',
            'items' => [['fuel_type_id' => $r['fuel'], 'quantity_liters' => 100, 'unit_price' => 60]],
            'amount_paid' => 1000, 'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment', 'status' => 'confirmed',
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.sales'));
        $saleId = (int) DB::table('sales')->value('id');
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'created_by' => $r['admin']->id, 'status' => 'partially_paid']);

        $this->actingAs($r['admin'])->post(route('admin.sales.payments.store', $saleId), [
            'idempotency_key' => (string) Str::uuid(), 'amount' => 5000, 'method' => 'cash_on_delivery', 'payment_date' => '2026-10-12',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('payments', ['sale_id' => $saleId, 'amount' => '5000.00', 'received_by' => $r['admin']->id]);
        $this->assertSame('paid', DB::table('sales')->where('id', $saleId)->value('status'));

        $this->actingAs($r['admin'])->patch(route('admin.sales.customers.deactivate', $customerId))->assertRedirect(route('admin.sales.customers'));
        $this->assertDatabaseHas('customers', ['id' => $customerId, 'status' => 'inactive']);
    }

    public function test_admin_schedules_edits_and_cancels_lifts_and_acts_for_the_driver_from_admin_fuel_lifting(): void
    {
        Storage::fake('local');
        $r = $this->records();
        $itemId = $this->purchaseItem($r, 'PUR-ADMIN-LIFT', 6000);
        $otherTruck = DB::table('trucks')->insertGetId(['truck_code' => 'TRK-ADMIN-2', 'capacity_liters' => 20000, 'truck_type' => 'hauling', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);

        $page = $this->actingAs($r['admin'])->get(route('admin.fuel-lifting'))->assertOk();
        $page->assertSee('id="lift-schedule-form"', false)
            ->assertSee('action="'.route('admin.fuel-lifting.hauls.store').'"', false)
            ->assertSee($r['driver']->name);

        $this->actingAs($r['admin'])->post(route('admin.fuel-lifting.hauls.store'), [
            'idempotency_key' => (string) Str::uuid(), 'form_context' => 'schedule-lift',
            'items' => [['purchase_item_id' => $itemId, 'quantity_liters' => 6000]],
            'driver_user_id' => $r['driver']->id, 'truck_id' => $r['truck'], 'scheduled_at' => '2026-10-13 08:00:00',
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.fuel-lifting'));
        $haulId = (int) DB::table('hauls')->value('id');
        $this->assertDatabaseHas('lifting_schedules', ['created_by' => $r['admin']->id]);

        // Edit: change truck while scheduled.
        $this->actingAs($r['admin'])->patch(route('admin.fuel-lifting.hauls.truck', $haulId), [
            'idempotency_key' => (string) Str::uuid(), 'truck_id' => $otherTruck,
        ])->assertRedirect(route('admin.fuel-lifting'));
        $this->assertDatabaseHas('hauls', ['id' => $haulId, 'truck_id' => $otherTruck]);

        // Driver actions on behalf of the driver (status + withdrawal receipt), attributed to the admin.
        foreach (['in_transit', 'lifted'] as $status) {
            $this->actingAs($r['admin'])->patch(route('driver.fuel-lifting.hauls.status', $haulId), [
                'idempotency_key' => (string) Str::uuid(), 'lifting_status' => $status,
            ])->assertSessionHasNoErrors()->assertRedirect(route('admin.fuel-lifting'));
        }
        $this->assertDatabaseHas('hauls', ['id' => $haulId, 'status' => 'lifted', 'status_updated_by' => $r['admin']->id]);

        $this->actingAs($r['admin'])->post(route('admin.fuel-lifting.hauls.withdrawal-receipt.store', $haulId), [
            'withdrawal_receipt' => UploadedFile::fake()->image('receipt.png'),
            'withdrawal_notes' => 'Uploaded by admin for the driver',
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.fuel-lifting.hauled'));
        $this->assertDatabaseHas('hauls', ['id' => $haulId, 'withdrawal_receipt_uploaded_by' => $r['admin']->id]);

        $this->actingAs($r['admin'])->get(route('admin.fuel-lifting'))
            ->assertOk()
            ->assertSee('Withdrawal receipts (on behalf of the driver)')
            ->assertSee('by '.$r['admin']->name);

        // Cancel a second lift.
        $secondItem = $this->purchaseItem($r, 'PUR-ADMIN-CANCEL', 1000);
        $this->actingAs($r['admin'])->post(route('admin.fuel-lifting.hauls.store'), [
            'idempotency_key' => (string) Str::uuid(), 'items' => [['purchase_item_id' => $secondItem, 'quantity_liters' => 1000]],
            'driver_user_id' => $r['driver']->id, 'truck_id' => $r['truck'], 'scheduled_at' => '2026-10-14 08:00:00',
        ])->assertSessionHasNoErrors();
        $secondHaul = (int) DB::table('hauls')->where('purchase_item_id', $secondItem)->value('id');
        $this->actingAs($r['admin'])->patch(route('admin.fuel-lifting.hauls.status', $secondHaul), [
            'idempotency_key' => (string) Str::uuid(), 'status' => 'cancelled',
        ])->assertRedirect(route('admin.fuel-lifting'));
        $this->assertDatabaseHas('hauls', ['id' => $secondHaul, 'status' => 'cancelled', 'status_updated_by' => $r['admin']->id]);
    }

    public function test_admin_only_pages_and_role_pages_are_reachable_and_other_roles_stay_restricted(): void
    {
        $r = $this->records();

        foreach (['admin.dashboard', 'admin.inventory', 'admin.ledger', 'admin.fuel-lifting', 'admin.fuel-lifting.trucks', 'admin.sales', 'admin.sales.customers',
            'admin.alerts', 'admin.account-requests', 'admin.user-management',
            'inventory-officer.inventory', 'sales-officer.sales', 'dispatch.fuel-lifting', 'dispatch.fuel-lifting.trucks', 'driver.fuel-lifting'] as $route) {
            $this->actingAs($r['admin'])->get(route($route))->assertOk();
        }

        $restricted = [
            'driver' => ['/admin/dashboard', '/admin/sales', '/inventory-officer/inventory', '/sales-officer/sales', '/dispatch/fuel-lifting'],
            'sales_officer' => ['/admin/dashboard', '/dispatch/fuel-lifting', '/inventory-officer/inventory', '/driver/fuel-lifting'],
            'inventory_officer' => ['/admin/inventory', '/sales-officer/sales', '/dispatch/fuel-lifting', '/driver/fuel-lifting'],
            'dispatch_officer' => ['/admin/fuel-lifting', '/sales-officer/sales', '/inventory-officer/inventory', '/driver/fuel-lifting'],
        ];
        foreach ($restricted as $role => $urls) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active', 'approval_status' => 'approved']);
            foreach ($urls as $url) {
                $this->actingAs($user)->get($url)->assertForbidden();
            }
        }

        // A driver still cannot act on another driver's lift, and a dispatch officer cannot upload receipts as admin.
        $itemId = $this->purchaseItem($r, 'PUR-OTHER', 1000);
        $haulId = $this->haul($r, $itemId, 1000, 'scheduled');
        $otherDriver = User::factory()->create(['role' => 'driver', 'status' => 'active', 'approval_status' => 'approved']);
        $this->actingAs($otherDriver)->from(route('driver.fuel-lifting'))->patch(route('driver.fuel-lifting.hauls.status', $haulId), [
            'idempotency_key' => (string) Str::uuid(), 'lifting_status' => 'in_transit',
        ])->assertSessionHasErrors('lifting');
        $this->assertDatabaseHas('hauls', ['id' => $haulId, 'status' => 'scheduled']);
        $dispatch = User::factory()->create(['role' => 'dispatch_officer', 'status' => 'active', 'approval_status' => 'approved']);
        $this->actingAs($dispatch)->post(route('admin.fuel-lifting.hauls.withdrawal-receipt.store', $haulId), [])->assertForbidden();
    }

    public function test_every_admin_write_is_recorded_in_the_activity_log(): void
    {
        $r = $this->records();

        $this->actingAs($r['admin'])->post(route('admin.sales.customers.store'), [
            'name' => 'Logged Customer', 'company_name' => 'Logged Co.', 'payment_status' => 'clear', 'status' => 'active',
        ]);
        $customerId = (int) DB::table('customers')->where('company_name', 'Logged Co.')->value('id');
        $this->actingAs($r['admin'])->patch(route('sales-officer.sales.customers.update', $customerId), [
            'name' => 'Logged Customer', 'company_name' => 'Logged Co.', 'payment_status' => 'clear', 'status' => 'active',
        ]);

        $this->assertDatabaseHas('activity_logs', ['user_id' => $r['admin']->id, 'user_role' => 'admin', 'route_name' => 'admin.sales.customers.store', 'method' => 'POST', 'succeeded' => true]);
        $this->assertDatabaseHas('activity_logs', ['user_id' => $r['admin']->id, 'route_name' => 'sales-officer.sales.customers.update', 'method' => 'PATCH', 'succeeded' => true]);
        $this->assertSame(json_encode(['customer' => (string) $customerId]), DB::table('activity_logs')->where('route_name', 'sales-officer.sales.customers.update')->value('route_parameters'));

        // GET requests are not logged, and failed writes are logged as not succeeded.
        $this->actingAs($r['admin'])->get(route('admin.sales'));
        $this->actingAs($r['admin'])->from(route('admin.sales'))->post(route('admin.sales.customers.store'), ['name' => '']);
        $this->assertSame(0, DB::table('activity_logs')->where('method', 'GET')->count());
        $this->assertDatabaseHas('activity_logs', ['route_name' => 'admin.sales.customers.store', 'succeeded' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    private function records(): array
    {
        $admin = User::factory()->create(['name' => 'Ada Admin', 'role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);
        $driver = User::factory()->create(['name' => 'Dan Driver', 'role' => 'driver', 'status' => 'active', 'approval_status' => 'approved']);
        DB::table('driver_profiles')->insert(['user_id' => $driver->id, 'driver_code' => 'DRV-ADM', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        $fuel = (int) DB::table('fuel_types')->where('code', 'DSL')->value('id');
        $garage = (int) DB::table('storage_locations')->where('type', 'garage')->where('fuel_type_id', $fuel)->value('id')
            ?: DB::table('storage_locations')->insertGetId(['location_code' => 'GAR-ADM', 'name' => 'Diesel Tank A', 'type' => 'garage', 'fuel_type_id' => $fuel, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return [
            'admin' => $admin,
            'driver' => $driver,
            'fuel' => $fuel,
            'garage' => $garage,
            'depot' => DB::table('depots')->insertGetId(['depot_code' => 'DEP-ADM', 'name' => 'Admin Terminal', 'address' => 'Pier 1', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]),
            'truck' => DB::table('trucks')->insertGetId(['truck_code' => 'TRK-ADMIN', 'capacity_liters' => 20000, 'truck_type' => 'hauling', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]),
            'customer' => DB::table('customers')->insertGetId(['customer_code' => 'CUS-ADM', 'name' => 'Existing Customer', 'company_name' => 'Existing Co.', 'payment_status' => 'clear', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]),
        ];
    }

    private function purchaseItem(array $r, string $code, float $quantity): int
    {
        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => $code, 'depot_id' => $r['depot'], 'purchase_date' => '2026-10-10', 'payment_status' => 'unpaid',
            'status' => 'ordered', 'workflow_status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchaseId, 'fuel_type_id' => $r['fuel'], 'quantity_ordered_liters' => $quantity, 'unit_cost' => 50,
            'line_total' => $quantity * 50, 'quantity_hauled_liters' => 0, 'status' => 'unlifted', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function haul(array $r, int $itemId, float $quantity, string $status): int
    {
        $purchaseId = (int) DB::table('purchase_items')->where('id', $itemId)->value('purchase_id');

        return DB::table('hauls')->insertGetId([
            'haul_code' => 'LFT-'.Str::upper(Str::random(6)), 'purchase_id' => $purchaseId, 'purchase_item_id' => $itemId, 'depot_id' => $r['depot'],
            'fuel_type_id' => $r['fuel'], 'truck_id' => $r['truck'], 'driver_user_id' => $r['driver']->id, 'scheduled_at' => '2026-10-11 07:00:00',
            'hauled_at' => $status === 'completed' ? '2026-10-11 10:00:00' : null, 'quantity_liters' => $quantity, 'status' => $status,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
