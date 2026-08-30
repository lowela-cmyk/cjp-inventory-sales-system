<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryOfficerPurchaseManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_officer_can_view_real_purchase_records_and_details(): void
    {
        $records = $this->createPurchaseRecord();

        $this->actingAs($records['inventoryOfficer'])
            ->get(route('inventory-officer.inventory'))
            ->assertOk()
            ->assertSee('PUR-EXISTING')
            ->assertSee('Diesel')
            ->assertSee('Main Depot')
            ->assertSee('3,750,000.00')
            ->assertSee('Created By')
            ->assertSee('Inventory User')
            ->assertDontSee('Petron A');
    }

    public function test_inventory_officer_can_create_purchase_with_server_calculated_total(): void
    {
        $records = $this->baseRecords();

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.purchases.store'), [
                'purchase_date' => '2026-08-30',
                'depot_id' => $records['depotId'],
                'fuel_type_id' => $records['fuelTypeId'],
                'quantity_ordered_liters' => '40000.50',
                'unit_cost' => '51.25',
                'receipt_reference' => 'DR-NEW',
                'payment_status' => 'unpaid',
                'status' => 'ordered',
                'line_total' => '1',
                'created_by' => 999,
            ])
            ->assertRedirect(route('inventory-officer.inventory'));

        $purchase = DB::table('purchases')->where('receipt_reference', 'DR-NEW')->first();
        $this->assertNotNull($purchase);
        $this->assertSame($records['inventoryOfficer']->id, $purchase->created_by);

        $this->assertDatabaseHas('purchase_items', [
            'purchase_id' => $purchase->id,
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_hauled_liters' => '0.00',
            'status' => 'unlifted',
        ]);

        $item = DB::table('purchase_items')->where('purchase_id', $purchase->id)->first();
        $this->assertSame(2050025.63, (float) $item->line_total);
        $this->assertSame(0, DB::table('inventory_movements')->count());
        $this->assertSame(0, DB::table('hauls')->count());
        $this->assertSame(0, DB::table('deliveries')->count());
    }

    public function test_purchase_create_validation_rejects_invalid_values(): void
    {
        $records = $this->baseRecords();

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.purchases.store'), [
                'purchase_date' => '2026-08-30',
                'depot_id' => 999999,
                'fuel_type_id' => 999999,
                'quantity_ordered_liters' => 0,
                'unit_cost' => -1,
                'payment_status' => 'paid',
                'status' => 'ordered',
            ])
            ->assertSessionHasErrors(['depot_id', 'fuel_type_id', 'quantity_ordered_liters', 'unit_cost']);

        $this->assertSame(0, DB::table('purchases')->count());
    }

    public function test_inventory_officer_can_update_purchase_before_hauling(): void
    {
        $records = $this->createPurchaseRecord();

        $this->actingAs($records['inventoryOfficer'])
            ->patch(route('inventory-officer.inventory.purchases.update', $records['purchaseItemId']), [
                'purchase_date' => '2026-08-31',
                'depot_id' => $records['depotId'],
                'fuel_type_id' => $records['fuelTypeId'],
                'quantity_ordered_liters' => 60000,
                'unit_cost' => 70,
                'receipt_reference' => 'DR-UPDATED',
                'payment_status' => 'paid',
                'status' => 'ordered',
            ])
            ->assertRedirect(route('inventory-officer.inventory'));

        $this->assertDatabaseHas('purchases', [
            'id' => $records['purchaseId'],
            'receipt_reference' => 'DR-UPDATED',
            'payment_status' => 'paid',
            'created_by' => $records['inventoryOfficer']->id,
        ]);

        $item = DB::table('purchase_items')->where('id', $records['purchaseItemId'])->first();
        $this->assertSame(4200000.0, (float) $item->line_total);
    }

    public function test_purchase_quantity_changes_are_blocked_after_hauling_activity(): void
    {
        $records = $this->createPurchaseRecord();
        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-DEP',
            'capacity_liters' => 40000,
            'truck_type' => 'hauling',
            'status' => 'assigned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active']);

        DB::table('hauls')->insert([
            'haul_code' => 'LFT-DEP',
            'purchase_id' => $records['purchaseId'],
            'purchase_item_id' => $records['purchaseItemId'],
            'depot_id' => $records['depotId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'truck_id' => $truckId,
            'driver_user_id' => $driver->id,
            'scheduled_at' => '2026-08-31 08:00:00',
            'quantity_liters' => 10000,
            'status' => 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($records['inventoryOfficer'])
            ->patch(route('inventory-officer.inventory.purchases.update', $records['purchaseItemId']), [
                'purchase_date' => '2026-08-20',
                'depot_id' => $records['depotId'],
                'fuel_type_id' => $records['fuelTypeId'],
                'quantity_ordered_liters' => 99999,
                'unit_cost' => 75,
                'receipt_reference' => 'DR-EXISTING',
                'payment_status' => 'partial',
                'status' => 'partially_hauled',
            ])
            ->assertSessionHasErrors('purchase');

        $item = DB::table('purchase_items')->where('id', $records['purchaseItemId'])->first();
        $this->assertSame(50000.0, (float) $item->quantity_ordered_liters);
        $this->assertSame(3750000.0, (float) $item->line_total);
    }

    public function test_purchase_search_filters_records(): void
    {
        $records = $this->createPurchaseRecord();
        $otherDepotId = DB::table('depots')->insertGetId([
            'depot_code' => 'DEP-OTHER',
            'name' => 'Other Depot',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('purchases')->insert([
            'purchase_code' => 'PUR-OTHER',
            'depot_id' => $otherDepotId,
            'purchase_date' => '2026-08-21',
            'payment_status' => 'paid',
            'status' => 'ordered',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($records['inventoryOfficer'])
            ->get(route('inventory-officer.inventory', ['search' => 'Main Depot']))
            ->assertOk()
            ->assertSee('PUR-EXISTING')
            ->assertDontSee('PUR-OTHER');
    }

    public function test_non_inventory_roles_cannot_manage_purchases(): void
    {
        $records = $this->baseRecords();
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $payload = [
            'purchase_date' => '2026-08-30',
            'depot_id' => $records['depotId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_ordered_liters' => 40000,
            'unit_cost' => 50,
            'payment_status' => 'unpaid',
            'status' => 'ordered',
        ];

        $this->actingAs($salesOfficer)
            ->post(route('inventory-officer.inventory.purchases.store'), $payload)
            ->assertForbidden();

        $this->actingAs($admin)
            ->post(route('inventory-officer.inventory.purchases.store'), $payload)
            ->assertForbidden();

        $this->assertSame(0, DB::table('purchases')->count());
    }

    public function test_admin_monitoring_reflects_inventory_officer_purchase_records(): void
    {
        $records = $this->baseRecords();

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.purchases.store'), [
                'purchase_date' => '2026-08-30',
                'depot_id' => $records['depotId'],
                'fuel_type_id' => $records['fuelTypeId'],
                'quantity_ordered_liters' => 40000,
                'unit_cost' => 50,
                'receipt_reference' => 'DR-ADMIN-MONITOR',
                'payment_status' => 'unpaid',
                'status' => 'ordered',
            ]);

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin)
            ->get(route('admin.inventory'))
            ->assertOk()
            ->assertSee('DR-ADMIN-MONITOR')
            ->assertSee('Diesel');
    }

    /**
     * @return array<string, mixed>
     */
    private function createPurchaseRecord(): array
    {
        $records = $this->baseRecords();

        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => 'PUR-EXISTING',
            'depot_id' => $records['depotId'],
            'purchase_date' => '2026-08-20',
            'receipt_reference' => 'DR-EXISTING',
            'payment_status' => 'partial',
            'status' => 'ordered',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $purchaseItemId = DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchaseId,
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_ordered_liters' => 50000,
            'unit_cost' => 75,
            'line_total' => 3750000,
            'quantity_hauled_liters' => 0,
            'status' => 'unlifted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return array_merge($records, [
            'purchaseId' => $purchaseId,
            'purchaseItemId' => $purchaseItemId,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseRecords(): array
    {
        $inventoryOfficer = User::factory()->create([
            'name' => 'Inventory User',
            'role' => 'inventory_officer',
            'status' => 'active',
        ]);

        $depotId = DB::table('depots')->insertGetId([
            'depot_code' => 'DEP-MAIN',
            'name' => 'Main Depot',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fuelTypeId = DB::table('fuel_types')->insertGetId([
            'code' => 'DSL',
            'name' => 'Diesel',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('inventoryOfficer', 'depotId', 'fuelTypeId');
    }
}
