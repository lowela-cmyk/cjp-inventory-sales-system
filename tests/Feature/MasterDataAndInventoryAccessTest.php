<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MasterDataAndInventoryAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_required_master_data_and_garage_tanks_exist_on_empty_install(): void
    {
        $this->assertSame(4, DB::table('fuel_types')->where('status', 'active')->count());
        $this->assertGreaterThanOrEqual(1, DB::table('depots')->where('status', 'active')->count());
        $this->assertSame(8, DB::table('storage_locations')->where('type', 'garage')->where('status', 'active')->count());

        DB::table('fuel_types')
            ->where('status', 'active')
            ->pluck('id')
            ->each(function (int $fuelTypeId): void {
                $this->assertSame(2, DB::table('storage_locations')
                    ->where('type', 'garage')
                    ->where('status', 'active')
                    ->where('fuel_type_id', $fuelTypeId)
                    ->whereIn('tank_number', [1, 2])
                    ->count());
            });
    }

    public function test_admin_can_manage_inventory_and_create_purchase_records(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $fuelTypeId = DB::table('fuel_types')->where('code', 'ADO')->value('id');
        $depotId = DB::table('depots')->where('depot_code', 'DEP-CJP-MAIN')->value('id');

        $this->actingAs($admin)
            ->get(route('admin.inventory'))
            ->assertOk()
            ->assertSee('Record Purchases')
            ->assertSee('Fuel Type')
            ->assertSee('Depot');

        $this->actingAs($admin)
            ->post(route('admin.inventory.purchases.store'), [
                'purchase_date' => '2026-09-07',
                'depot_id' => $depotId,
                'fuel_type_id' => $fuelTypeId,
                'quantity_ordered_liters' => 12000,
                'unit_cost' => 55,
                'payment_status' => 'unpaid',
                'status' => 'ordered',
            ])
            ->assertRedirect(route('admin.inventory'));

        $this->assertDatabaseHas('purchases', [
            'depot_id' => $depotId,
            'created_by' => $admin->id,
            'status' => 'ordered',
        ]);
    }

    public function test_inventory_officer_can_add_fuel_type_and_its_two_garage_tanks_are_created(): void
    {
        $inventoryOfficer = User::factory()->create(['role' => 'inventory_officer', 'status' => 'active']);

        $this->actingAs($inventoryOfficer)
            ->post(route('inventory-officer.inventory.fuel-types.store'), [
                'code' => 'BIO',
                'name' => 'Biodiesel',
                'description' => 'Optional biodiesel stock.',
                'status' => 'active',
            ])
            ->assertRedirect(route('inventory-officer.inventory'));

        $fuelTypeId = DB::table('fuel_types')->where('code', 'BIO')->value('id');

        $this->assertNotNull($fuelTypeId);
        $this->assertSame(2, DB::table('storage_locations')
            ->where('type', 'garage')
            ->where('status', 'active')
            ->where('fuel_type_id', $fuelTypeId)
            ->whereIn('tank_number', [1, 2])
            ->count());
    }

    public function test_dispatch_ledger_uses_real_ledger_data_and_empty_state(): void
    {
        $dispatchOfficer = User::factory()->create(['role' => 'dispatch_officer', 'status' => 'active']);

        $this->actingAs($dispatchOfficer)
            ->get(route('dispatch.ledger'))
            ->assertOk()
            ->assertSee('No inventory movements found.')
            ->assertDontSee('No Data');
    }
}
