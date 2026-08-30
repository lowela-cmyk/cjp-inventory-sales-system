<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_user_management_displays_real_staff_drivers_and_customers(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin User',
            'email' => 'admin-user@example.com',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $driver = User::factory()->create([
            'name' => 'Real Driver',
            'email' => 'real-driver@example.com',
            'role' => 'driver',
            'phone' => '09170000001',
            'status' => 'active',
        ]);

        DB::table('driver_profiles')->insert([
            'user_id' => $driver->id,
            'driver_code' => 'DRV-000123',
            'license_number' => 'N01-22-123456',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('customers')->insert([
            'customer_code' => 'CUS-REAL',
            'name' => 'Real Customer',
            'company_name' => 'Real Customer Company',
            'location' => 'Nasugbu',
            'email' => 'customer@example.com',
            'phone' => '09170000002',
            'payment_status' => 'clear',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.user-management'))
            ->assertOk()
            ->assertSee('Admin User')
            ->assertSee('Real Driver')
            ->assertSee('DRV-000123')
            ->assertSee('N01-22-123456')
            ->assertSee('Real Customer Company')
            ->assertDontSee('Manuel P. Ligaya')
            ->assertDontSee('Maria C. Pilar');
    }

    public function test_admin_can_create_office_staff_account(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.user-management.staff.store'), [
                'name' => 'New Sales Officer',
                'email' => 'new-sales@example.com',
                'phone' => '09171234567',
                'role' => 'sales_officer',
                'status' => 'active',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertRedirect(route('admin.user-management', ['tab' => 'office']));

        $user = User::where('email', 'new-sales@example.com')->firstOrFail();

        $this->assertSame('sales_officer', $user->role);
        $this->assertSame('active', $user->status);
        $this->assertTrue(Hash::check('password', $user->password));
    }

    public function test_admin_can_create_driver_account_with_driver_profile(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.user-management.drivers.store'), [
                'name' => 'New Driver',
                'email' => 'new-driver@example.com',
                'phone' => '09181234567',
                'role' => 'driver',
                'license_number' => 'N01-22-654321',
                'status' => 'active',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertRedirect(route('admin.user-management', ['tab' => 'drivers']));

        $driver = User::where('email', 'new-driver@example.com')->firstOrFail();

        $this->assertSame('driver', $driver->role);
        $this->assertTrue(Hash::check('password', $driver->password));
        $this->assertDatabaseHas('driver_profiles', [
            'user_id' => $driver->id,
            'driver_code' => 'DRV-'.str_pad((string) $driver->id, 6, '0', STR_PAD_LEFT),
            'license_number' => 'N01-22-654321',
            'status' => 'available',
        ]);
    }

    public function test_admin_can_update_staff_without_overwriting_password_when_blank(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $staff = User::factory()->create([
            'name' => 'Old Staff',
            'email' => 'old-staff@example.com',
            'role' => 'inventory_officer',
            'status' => 'active',
            'password' => 'original-password',
        ]);

        $originalPassword = $staff->password;

        $this->actingAs($admin)
            ->patch(route('admin.user-management.staff.update', $staff), [
                'name' => 'Updated Staff',
                'email' => 'updated-staff@example.com',
                'phone' => '09179990000',
                'role' => 'dispatch_officer',
                'status' => 'inactive',
                'password' => null,
                'password_confirmation' => null,
            ])
            ->assertRedirect(route('admin.user-management', ['tab' => 'office']));

        $staff->refresh();

        $this->assertSame('Updated Staff', $staff->name);
        $this->assertSame('dispatch_officer', $staff->role);
        $this->assertSame('inactive', $staff->status);
        $this->assertSame($originalPassword, $staff->password);
    }

    public function test_admin_can_update_driver_license_and_password(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $driver = User::factory()->create([
            'name' => 'Old Driver',
            'email' => 'old-driver@example.com',
            'role' => 'driver',
            'status' => 'active',
            'password' => 'old-password',
        ]);

        DB::table('driver_profiles')->insert([
            'user_id' => $driver->id,
            'driver_code' => 'DRV-000777',
            'license_number' => 'OLD-LICENSE',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.user-management.drivers.update', $driver), [
                'name' => 'Updated Driver',
                'email' => 'updated-driver@example.com',
                'phone' => '09176660000',
                'role' => 'driver',
                'license_number' => 'NEW-LICENSE',
                'status' => 'active',
                'password' => 'new-password',
                'password_confirmation' => 'new-password',
            ])
            ->assertRedirect(route('admin.user-management', ['tab' => 'drivers']));

        $driver->refresh();

        $this->assertSame('Updated Driver', $driver->name);
        $this->assertTrue(Hash::check('new-password', $driver->password));
        $this->assertDatabaseHas('driver_profiles', [
            'user_id' => $driver->id,
            'driver_code' => 'DRV-000777',
            'license_number' => 'NEW-LICENSE',
            'status' => 'available',
        ]);
    }

    public function test_duplicate_name_and_email_are_rejected(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        User::factory()->create([
            'name' => 'Existing Account',
            'email' => 'existing-account@example.com',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.user-management.staff.store'), [
                'name' => 'Existing Account',
                'email' => 'existing-account@example.com',
                'role' => 'admin',
                'status' => 'active',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertSessionHasErrors(['name', 'email']);
    }

    public function test_deactivated_account_cannot_log_in(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $staff = User::factory()->create([
            'email' => 'deactivate-me@example.com',
            'role' => 'sales_officer',
            'status' => 'active',
            'password' => 'password',
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.user-management.users.status', $staff), [
                'status' => 'inactive',
                'tab' => 'office',
            ])
            ->assertRedirect(route('admin.user-management', ['tab' => 'office']));

        $this->post('/logout');

        $this->post('/login', [
            'username' => 'deactivate-me@example.com',
            'role' => 'sales_officer',
            'password' => 'password',
        ])->assertSessionHasErrors('username');
    }

    public function test_non_admin_users_cannot_access_user_management_write_routes(): void
    {
        $inventoryOfficer = User::factory()->create([
            'role' => 'inventory_officer',
            'status' => 'active',
        ]);

        $target = User::factory()->create([
            'role' => 'driver',
            'status' => 'active',
        ]);

        $this->actingAs($inventoryOfficer)
            ->get(route('admin.user-management'))
            ->assertForbidden();

        $this->actingAs($inventoryOfficer)
            ->post(route('admin.user-management.staff.store'), [
                'name' => 'Blocked Staff',
                'email' => 'blocked-staff@example.com',
                'role' => 'admin',
                'status' => 'active',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertForbidden();

        $this->actingAs($inventoryOfficer)
            ->patch(route('admin.user-management.staff.update', $target), [
                'name' => 'Blocked Update',
                'email' => 'blocked-update@example.com',
                'role' => 'admin',
                'status' => 'active',
                'password' => null,
                'password_confirmation' => null,
            ])
            ->assertForbidden();

        $this->actingAs($inventoryOfficer)
            ->patch(route('admin.user-management.users.status', $target), [
                'status' => 'inactive',
                'tab' => 'drivers',
            ])
            ->assertForbidden();
    }

    public function test_admin_can_change_inventory_officer_to_sales_officer_and_login_uses_new_dashboard(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $staff = User::factory()->create([
            'name' => 'Inventory Staff',
            'email' => 'inventory-to-sales@example.com',
            'role' => 'inventory_officer',
            'status' => 'active',
            'password' => 'password',
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.user-management.staff.update', $staff), [
                'name' => 'Inventory Staff',
                'email' => 'inventory-to-sales@example.com',
                'phone' => null,
                'role' => 'sales_officer',
                'status' => 'active',
                'password' => null,
                'password_confirmation' => null,
            ])
            ->assertRedirect(route('admin.user-management', ['tab' => 'office']));

        $this->assertSame('sales_officer', $staff->refresh()->role);

        $this->post('/logout');

        $this->post('/login', [
            'username' => 'inventory-to-sales@example.com',
            'role' => 'sales_officer',
            'password' => 'password',
        ])->assertRedirect(route('sales-officer.sales'));

        $this->get(route('inventory-officer.inventory'))->assertForbidden();
        $this->get(route('sales-officer.sales'))->assertOk();
    }

    public function test_admin_can_change_sales_officer_to_dispatch_officer(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $staff = User::factory()->create([
            'name' => 'Sales Staff',
            'email' => 'sales-to-dispatch@example.com',
            'role' => 'sales_officer',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.user-management.staff.update', $staff), [
                'name' => 'Sales Staff',
                'email' => 'sales-to-dispatch@example.com',
                'phone' => null,
                'role' => 'dispatch_officer',
                'status' => 'active',
                'password' => null,
                'password_confirmation' => null,
            ])
            ->assertRedirect(route('admin.user-management', ['tab' => 'office']));

        $this->assertSame('dispatch_officer', $staff->refresh()->role);
    }

    public function test_admin_can_change_staff_account_to_driver_without_duplicate_account(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $staff = User::factory()->create([
            'name' => 'Staff Driver',
            'email' => 'staff-to-driver@example.com',
            'role' => 'sales_officer',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.user-management.staff.update', $staff), [
                'name' => 'Staff Driver',
                'email' => 'staff-to-driver@example.com',
                'phone' => null,
                'role' => 'driver',
                'status' => 'active',
                'password' => null,
                'password_confirmation' => null,
            ])
            ->assertRedirect(route('admin.user-management', ['tab' => 'drivers']));

        $this->assertSame('driver', $staff->refresh()->role);
        $this->assertSame(1, User::where('email', 'staff-to-driver@example.com')->count());
        $this->assertDatabaseHas('driver_profiles', [
            'user_id' => $staff->id,
            'driver_code' => 'DRV-'.str_pad((string) $staff->id, 6, '0', STR_PAD_LEFT),
            'status' => 'available',
        ]);
    }

    public function test_driver_can_be_changed_to_office_role_while_driver_profile_is_preserved(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $driver = User::factory()->create([
            'name' => 'Driver History',
            'email' => 'driver-history@example.com',
            'role' => 'driver',
            'status' => 'active',
        ]);

        DB::table('driver_profiles')->insert([
            'user_id' => $driver->id,
            'driver_code' => 'DRV-HISTORY',
            'license_number' => 'KEEP-ME',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.user-management.drivers.update', $driver), [
                'name' => 'Driver History',
                'email' => 'driver-history@example.com',
                'phone' => null,
                'role' => 'inventory_officer',
                'license_number' => 'KEEP-ME',
                'status' => 'active',
                'password' => null,
                'password_confirmation' => null,
            ])
            ->assertRedirect(route('admin.user-management', ['tab' => 'office']));

        $this->assertSame('inventory_officer', $driver->refresh()->role);
        $this->assertDatabaseHas('driver_profiles', [
            'user_id' => $driver->id,
            'driver_code' => 'DRV-HISTORY',
            'license_number' => 'KEEP-ME',
            'status' => 'inactive',
        ]);
    }

    public function test_invalid_role_submission_is_rejected(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $staff = User::factory()->create([
            'role' => 'sales_officer',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.user-management.staff.update', $staff), [
                'name' => $staff->name,
                'email' => $staff->email,
                'phone' => null,
                'role' => 'super_admin',
                'status' => 'active',
                'password' => null,
                'password_confirmation' => null,
            ])
            ->assertSessionHasErrors('role');

        $this->assertSame('sales_officer', $staff->refresh()->role);
    }

    public function test_removing_final_active_admin_role_is_blocked(): void
    {
        $admin = User::factory()->create([
            'name' => 'Only Admin',
            'email' => 'only-admin@example.com',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.user-management.staff.update', $admin), [
                'name' => 'Only Admin',
                'email' => 'only-admin@example.com',
                'phone' => null,
                'role' => 'sales_officer',
                'status' => 'active',
                'password' => null,
                'password_confirmation' => null,
            ])
            ->assertSessionHasErrors('role');

        $this->assertSame('admin', $admin->refresh()->role);
    }

    public function test_deactivating_final_active_admin_is_blocked(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->patch(route('admin.user-management.users.status', $admin), [
                'status' => 'inactive',
                'tab' => 'office',
            ])
            ->assertSessionHasErrors('role');

        $this->assertSame('active', $admin->refresh()->status);
    }

    public function test_stale_logged_in_role_is_rechecked_on_protected_requests(): void
    {
        $user = User::factory()->create([
            'role' => 'inventory_officer',
            'status' => 'active',
        ]);

        $this->actingAs($user);

        $user->forceFill(['role' => 'sales_officer'])->save();

        $this->get(route('inventory-officer.inventory'))->assertForbidden();
        $this->get(route('dashboard'))->assertRedirect(route('sales-officer.sales'));
    }
}
