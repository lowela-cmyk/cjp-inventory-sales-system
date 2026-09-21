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
        $this->assertSame(['DSL', 'F1', 'PREM', 'UNL'], DB::table('fuel_types')->where('status', 'active')->orderBy('code')->pluck('code')->all());
        $this->assertSame([
            'Diesel Tank 1',
            'Diesel Tank 2',
            'F1 Tank 1',
            'F1 Tank 2',
            'Premium Tank 1',
            'Premium Tank 2',
            'Unleaded Tank 1',
            'Unleaded Tank 2',
        ], DB::table('storage_locations')
            ->where('type', 'garage')
            ->where('status', 'active')
            ->orderBy('name')
            ->pluck('name')
            ->all());
    }

    public function test_admin_can_manage_inventory_and_create_purchase_records(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $fuelTypeId = DB::table('fuel_types')->where('code', 'DSL')->value('id');
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

    public function test_fuel_types_catalog_is_fixed_and_creation_routes_are_not_available(): void
    {
        $inventoryOfficer = User::factory()->create(['role' => 'inventory_officer', 'status' => 'active', 'approval_status' => 'approved']);
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active', 'approval_status' => 'approved']);

        // Fuel-type creation routes must not exist
        $this->actingAs($inventoryOfficer)
            ->post('/inventory-officer/inventory/fuel-types', [
                'code' => 'BIO',
                'name' => 'Biodiesel',
                'status' => 'active',
            ])
            ->assertNotFound();

        $this->actingAs($inventoryOfficer)
            ->post('/admin/inventory/fuel-types', [
                'code' => 'BIO',
                'name' => 'Biodiesel',
                'status' => 'active',
            ])
            ->assertNotFound();

        // Unauthorized role cannot access master data endpoints
        $this->actingAs($salesOfficer)
            ->post('/inventory-officer/inventory/depots', [
                'depot_code' => 'DEP-HACK',
                'name' => 'Unauthorized Depot',
                'status' => 'active',
            ])
            ->assertForbidden();

        // Fixed approved catalog verification
        $fuelTypeId = DB::table('fuel_types')->where('code', 'DSL')->value('id');
        $this->assertNotNull($fuelTypeId);
        $this->assertSame(2, DB::table('storage_locations')
            ->where('type', 'garage')
            ->where('status', 'active')
            ->where('fuel_type_id', $fuelTypeId)
            ->whereIn('tank_number', [1, 2])
            ->count());
        $this->assertSame(4, DB::table('fuel_types')->where('status', 'active')->count());
        $this->assertSame(8, DB::table('storage_locations')->where('type', 'garage')->where('status', 'active')->count());
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
