<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Audit\AuditTestCase;

/** Add Staff offers Driver: it creates the same driver account and driver profile as the Drivers tab. */
class AddStaffDriverOptionTest extends AuditTestCase
{
    public function test_add_staff_can_create_a_driver_with_its_profile(): void
    {
        $a = $this->actors();
        $page = $this->actingAs($a['admin'])->get(route('admin.user-management', ['tab' => 'office']))->assertOk();
        $page->assertSee('<option value="driver" >Driver</option>', false)->assertSee('data-staff-driver-only', false);

        $this->actingAs($a['admin'])->from(route('admin.user-management', ['tab' => 'office']))->post(route('admin.user-management.staff.store'), [
            'name' => 'New Driver Via Staff', 'role' => 'driver', 'email' => 'new.driver@example.test', 'phone' => '09170000000',
            'status' => 'active', 'license_number' => 'N01-23-456789', 'password' => 'Str0ng-Passw0rd!', 'password_confirmation' => 'Str0ng-Passw0rd!',
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.user-management', ['tab' => 'drivers']))->assertSessionHas('status', 'Driver account created successfully.');

        $driver = User::where('email', 'new.driver@example.test')->firstOrFail();
        $this->assertSame(['driver', 'active', 'approved'], [$driver->role, $driver->status, $driver->approval_status]);
        $profile = DB::table('driver_profiles')->where('user_id', $driver->id)->first();
        $this->assertNotNull($profile, 'driver profile created');
        $this->assertSame(['N01-23-456789', 'available'], [$profile->license_number, $profile->status]);
        $this->assertNotEmpty($profile->driver_code);

        $drivers = $this->actingAs($a['admin'])->get(route('admin.user-management', ['tab' => 'drivers']))->assertOk()->getContent();
        $this->assertStringContainsString('New Driver Via Staff', $drivers);
        $office = $this->tabPanel($this->actingAs($a['admin'])->get(route('admin.user-management', ['tab' => 'office']))->getContent(), 'office');
        $this->assertStringNotContainsString('new.driver@example.test', $office, 'drivers are not listed as office staff');
    }

    public function test_office_positions_still_create_office_staff_without_a_driver_profile(): void
    {
        $a = $this->actors();
        $this->actingAs($a['admin'])->post(route('admin.user-management.staff.store'), [
            'name' => 'New Sales Staff', 'role' => 'sales_officer', 'email' => 'new.sales@example.test', 'status' => 'active',
            'password' => 'Str0ng-Passw0rd!', 'password_confirmation' => 'Str0ng-Passw0rd!',
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.user-management', ['tab' => 'office']));
        $user = User::where('email', 'new.sales@example.test')->firstOrFail();
        $this->assertSame('sales_officer', $user->role);
        $this->assertFalse(DB::table('driver_profiles')->where('user_id', $user->id)->exists());

        $this->actingAs($a['admin'])->post(route('admin.user-management.staff.store'), [
            'name' => 'Bad Role', 'role' => 'superuser', 'email' => 'bad.role@example.test', 'status' => 'active',
            'password' => 'Str0ng-Passw0rd!', 'password_confirmation' => 'Str0ng-Passw0rd!',
        ])->assertSessionHasErrors('role');
    }
}
