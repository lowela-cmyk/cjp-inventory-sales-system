<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FuelCatalogRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_approved_fuels_are_active_and_have_exact_codes_and_display_names(): void
    {
        $expected = [
            'DSL' => 'Diesel',
            'F1' => 'F1',
            'PREM' => 'Premium',
            'UNL' => 'Unleaded',
        ];

        $activeFuels = DB::table('fuel_types')
            ->where('status', 'active')
            ->orderBy('code')
            ->pluck('name', 'code')
            ->all();

        $this->assertSame($expected, $activeFuels);
        $this->assertCount(4, $activeFuels);

        // Verify inactive historical fuels can exist in table but are excluded from active list
        $inactiveId = DB::table('fuel_types')->insertGetId([
            'code' => 'BIO',
            'name' => 'BIODIESEL',
            'status' => 'inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('fuel_types', ['id' => $inactiveId, 'status' => 'inactive']);

        $activeAfterInsert = DB::table('fuel_types')
            ->where('status', 'active')
            ->orderBy('code')
            ->pluck('name', 'code')
            ->all();

        $this->assertSame($expected, $activeAfterInsert);
    }

    public function test_purchase_validation_rejects_inactive_or_unapproved_fuel_type(): void
    {
        $inventoryOfficer = User::factory()->create(['role' => 'inventory_officer', 'status' => 'active']);
        $depotId = DB::table('depots')->where('status', 'active')->value('id');

        $inactiveFuelId = DB::table('fuel_types')->insertGetId([
            'code' => 'OLD_GAS',
            'name' => 'Old Gasoline',
            'status' => 'inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $nonExistentFuelId = 999999;

        // Inactive fuel rejected
        $this->actingAs($inventoryOfficer)
            ->post(route('inventory-officer.inventory.purchases.store'), [
                'purchase_date' => '2026-09-16',
                'depot_id' => $depotId,
                'fuel_type_id' => $inactiveFuelId,
                'quantity_ordered_liters' => 1000,
                'unit_cost' => 50,
                'payment_status' => 'unpaid',
                'status' => 'ordered',
            ])
            ->assertSessionHasErrors('fuel_type_id');

        // Non-existent fuel rejected
        $this->actingAs($inventoryOfficer)
            ->post(route('inventory-officer.inventory.purchases.store'), [
                'purchase_date' => '2026-09-16',
                'depot_id' => $depotId,
                'fuel_type_id' => $nonExistentFuelId,
                'quantity_ordered_liters' => 1000,
                'unit_cost' => 50,
                'payment_status' => 'unpaid',
                'status' => 'ordered',
            ])
            ->assertSessionHasErrors('fuel_type_id');
    }

    public function test_sale_validation_rejects_inactive_or_unapproved_fuel_type(): void
    {
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active']);
        $customerId = DB::table('customers')->where('status', 'active')->value('id');

        $inactiveFuelId = DB::table('fuel_types')->insertGetId([
            'code' => 'OLD_DIESEL',
            'name' => 'Old Diesel',
            'status' => 'inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Top-level fuel_type_id
        $this->actingAs($salesOfficer)
            ->post(route('sales-officer.sales.store'), [
                'idempotency_key' => (string) Str::uuid(),
                'sale_code' => 'SLS-INACTIVE-FUEL',
                'customer_id' => $customerId,
                'sale_date' => '2026-09-16',
                'fuel_type_id' => $inactiveFuelId,
                'quantity_liters' => 1000,
                'unit_price' => 60,
                'payment_method' => 'cash_on_delivery',
                'status' => 'confirmed',
            ])
            ->assertSessionHasErrors('fuel_type_id');

        // Nested items.*.fuel_type_id
        $this->actingAs($salesOfficer)
            ->post(route('sales-officer.sales.store'), [
                'idempotency_key' => (string) Str::uuid(),
                'sale_code' => 'SLS-INACTIVE-ITEM',
                'customer_id' => $customerId,
                'sale_date' => '2026-09-16',
                'items' => [
                    [
                        'fuel_type_id' => $inactiveFuelId,
                        'quantity_liters' => 500,
                        'unit_price' => 60,
                    ],
                ],
                'payment_method' => 'cash_on_delivery',
                'status' => 'confirmed',
            ])
            ->assertSessionHasErrors('items.0.fuel_type_id');
    }

    public function test_lifting_filters_reject_inactive_or_unapproved_fuel_type(): void
    {
        $dispatchOfficer = User::factory()->create(['role' => 'dispatch_officer', 'status' => 'active']);
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active']);

        $inactiveFuelId = DB::table('fuel_types')->insertGetId([
            'code' => 'HISTORIC_1',
            'name' => 'Historic Fuel',
            'status' => 'inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Dispatch lifting filter
        $this->actingAs($dispatchOfficer)
            ->get(route('dispatch.fuel-lifting', ['fuel_type_id' => $inactiveFuelId]))
            ->assertSessionHasErrors('fuel_type_id');

        // Driver lifting filter
        $this->actingAs($driver)
            ->get(route('driver.fuel-lifting', ['fuel_type_id' => $inactiveFuelId]))
            ->assertSessionHasErrors('fuel_type_id');
    }

    public function test_dashboard_and_ai_filters_reject_inactive_or_unapproved_fuel_type(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $inactiveFuelId = DB::table('fuel_types')->insertGetId([
            'code' => 'HISTORIC_2',
            'name' => 'Historic Fuel 2',
            'status' => 'inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Dashboard unlifted filter
        $this->actingAs($admin)
            ->get(route('admin.dashboard', ['unlifted_fuel_type_id' => $inactiveFuelId]))
            ->assertSessionHasErrors('unlifted_fuel_type_id');

        // Dashboard variance filter
        $this->actingAs($admin)
            ->get(route('admin.dashboard', ['variance_fuel_type_id' => $inactiveFuelId]))
            ->assertSessionHasErrors('variance_fuel_type_id');

        // AI explanation filter
        $this->actingAs($admin)
            ->post(route('admin.dashboard.inventory-variance-explanation'), [
                'variance_fuel_type_id' => $inactiveFuelId,
            ])
            ->assertSessionHasErrors('variance_fuel_type_id');
    }
}
