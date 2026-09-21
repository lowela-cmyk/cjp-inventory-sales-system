<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TruckManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_and_dispatch_can_manage_trucks_with_unique_normalized_plates(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $dispatch = User::factory()->create(['role' => 'dispatch_officer', 'status' => 'active']);

        $this->actingAs($admin)->get(route('admin.trucks'))
            ->assertOk()->assertSee('Truck Management')->assertSee('Add Truck');
        $this->actingAs($dispatch)->get(route('dispatch.trucks'))
            ->assertOk()->assertSee('Truck Management');

        $this->actingAs($admin)->post(route('admin.trucks.store'), [
            'truck_code' => ' trk-101 ',
            'plate_number' => ' abc-1234 ',
            'name' => 'Southern Tanker 1',
            'description' => 'Primary hauling truck',
            'capacity_liters' => 20000,
            'truck_type' => 'hauling',
            'status' => 'available',
        ])->assertRedirect(route('admin.trucks'));

        $truckId = (int) DB::table('trucks')->where('plate_number', 'ABC-1234')->value('id');
        $this->assertGreaterThan(0, $truckId);
        $this->assertDatabaseHas('trucks', [
            'id' => $truckId,
            'truck_code' => 'TRK-101',
            'name' => 'Southern Tanker 1',
            'capacity_liters' => '20000.00',
        ]);

        $this->actingAs($dispatch)->patch(route('dispatch.trucks.update', $truckId), [
            'truck_code' => 'TRK-101',
            'plate_number' => 'ABC-1234',
            'name' => 'Southern Tanker One',
            'description' => 'Updated fleet record',
            'capacity_liters' => 22000,
            'truck_type' => 'mixed',
            'status' => 'maintenance',
        ])->assertRedirect(route('dispatch.trucks'));
        $this->assertDatabaseHas('trucks', ['id' => $truckId, 'name' => 'Southern Tanker One', 'status' => 'maintenance']);

        $this->actingAs($admin)->from(route('admin.trucks'))->post(route('admin.trucks.store'), [
            'truck_code' => 'TRK-102',
            'plate_number' => 'abc-1234',
            'name' => 'Duplicate Plate Truck',
            'capacity_liters' => 10000,
            'truck_type' => 'hauling',
            'status' => 'available',
        ])->assertSessionHasErrors('plate_number');
    }

    public function test_truck_module_rbac_and_activate_deactivate_workflow(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $dispatch = User::factory()->create(['role' => 'dispatch_officer', 'status' => 'active']);
        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-202', 'plate_number' => 'DEF-5678', 'name' => 'Reserve Tanker',
            'capacity_liters' => 18000, 'truck_type' => 'hauling', 'status' => 'available',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($dispatch)->patch(route('dispatch.trucks.status', $truckId))->assertRedirect(route('dispatch.trucks'));
        $this->assertDatabaseHas('trucks', ['id' => $truckId, 'status' => 'inactive']);
        $this->actingAs($admin)->patch(route('admin.trucks.status', $truckId))->assertRedirect(route('admin.trucks'));
        $this->assertDatabaseHas('trucks', ['id' => $truckId, 'status' => 'available']);

        foreach (['inventory_officer', 'sales_officer', 'driver'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);
            $this->actingAs($user)->get(route('admin.trucks'))->assertForbidden();
            $this->actingAs($user)->get(route('dispatch.trucks'))->assertForbidden();
        }
    }
}
