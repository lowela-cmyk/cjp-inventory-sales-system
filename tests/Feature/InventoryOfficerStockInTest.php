<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryOfficerStockInTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_officer_can_view_confirmed_stock_in_records(): void
    {
        $records = $this->stockInRecords();

        DB::table('inventory_movements')->insert([
            'movement_code' => 'MOV-STOCK-IN',
            'storage_location_id' => $records['garageId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'movement_type' => 'stock_in',
            'direction' => 'in',
            'quantity_liters' => 15000,
            'unit_cost' => 52.50,
            'reference_type' => 'haul_allocation',
            'reference_id' => $records['garageAllocationId'],
            'movement_date' => '2026-08-30 08:00:00',
            'remarks' => 'Received into garage tank',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventory_movements')->insert([
            'movement_code' => 'MOV-BEGINNING',
            'storage_location_id' => $records['garageId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'movement_type' => 'beginning',
            'direction' => 'in',
            'quantity_liters' => 1000,
            'unit_cost' => 40,
            'reference_type' => 'opening_balance',
            'reference_id' => 1,
            'movement_date' => '2026-08-29 08:00:00',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($records['inventoryOfficer'])
            ->get(route('inventory-officer.inventory.stock-in'))
            ->assertOk()
            ->assertSee('MOV-STOCK-IN')
            ->assertSee('Diesel')
            ->assertSee('CJP Garage')
            ->assertSee('Received into garage tank')
            ->assertDontSee('MOV-BEGINNING');
    }

    public function test_inventory_officer_records_stock_in_from_garage_haul_allocation(): void
    {
        $records = $this->stockInRecords();

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-in.store'), [
                'haul_allocation_id' => $records['garageAllocationId'],
                'storage_location_id' => $records['garageId'],
                'quantity_liters' => 36000,
                'movement_date' => '2026-08-30 09:15:00',
                'remarks' => 'Actual garage receipt',
            ])
            ->assertRedirect(route('inventory-officer.inventory.stock-in'));

        $this->assertDatabaseHas('inventory_movements', [
            'storage_location_id' => $records['garageId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'movement_type' => 'stock_in',
            'direction' => 'in',
            'quantity_liters' => '36000.00',
            'unit_cost' => '52.50',
            'reference_type' => 'haul_allocation',
            'reference_id' => $records['garageAllocationId'],
            'movement_date' => '2026-08-30 09:15:00',
            'remarks' => 'Actual garage receipt',
            'created_by' => $records['inventoryOfficer']->id,
        ]);

        $this->assertSame(0, DB::table('hauls')->where('haul_code', 'MOV-000001')->count());
        $this->assertSame(36000.0, $this->garageBalance($records));
    }

    public function test_stock_in_rejects_over_receipt_and_prevents_duplicate_full_receipts(): void
    {
        $records = $this->stockInRecords();
        $payload = [
            'haul_allocation_id' => $records['garageAllocationId'],
            'storage_location_id' => $records['garageId'],
            'quantity_liters' => 40000,
            'movement_date' => '2026-08-30 09:15:00',
        ];

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-in.store'), $payload)
            ->assertRedirect(route('inventory-officer.inventory.stock-in'));

        $this->actingAs($records['inventoryOfficer'])
            ->from(route('inventory-officer.inventory.stock-in'))
            ->post(route('inventory-officer.inventory.stock-in.store'), $payload)
            ->assertRedirect(route('inventory-officer.inventory.stock-in'))
            ->assertSessionHasErrors('stock_in');

        $this->assertSame(40000.0, $this->garageBalance($records));
        $this->assertSame(1, DB::table('inventory_movements')->count());
        $this->assertDatabaseHas('haul_allocations', [
            'id' => $records['garageAllocationId'],
            'status' => 'received',
        ]);
    }

    public function test_stock_in_allows_partial_receipts_without_exceeding_remaining_allocation(): void
    {
        $records = $this->stockInRecords();

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-in.store'), [
                'haul_allocation_id' => $records['garageAllocationId'],
                'storage_location_id' => $records['garageId'],
                'quantity_liters' => 36000,
                'movement_date' => '2026-08-30 09:15:00',
            ])
            ->assertRedirect(route('inventory-officer.inventory.stock-in'));

        $this->actingAs($records['inventoryOfficer'])
            ->from(route('inventory-officer.inventory.stock-in'))
            ->post(route('inventory-officer.inventory.stock-in.store'), [
                'haul_allocation_id' => $records['garageAllocationId'],
                'storage_location_id' => $records['garageId'],
                'quantity_liters' => 5000,
                'movement_date' => '2026-08-30 10:15:00',
            ])
            ->assertRedirect(route('inventory-officer.inventory.stock-in'))
            ->assertSessionHasErrors('stock_in');

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-in.store'), [
                'haul_allocation_id' => $records['garageAllocationId'],
                'storage_location_id' => $records['garageId'],
                'quantity_liters' => 4000,
                'movement_date' => '2026-08-30 10:30:00',
            ])
            ->assertRedirect(route('inventory-officer.inventory.stock-in'));

        $this->assertSame(40000.0, $this->garageBalance($records));
        $this->assertSame(2, DB::table('inventory_movements')->count());
        $this->assertDatabaseHas('haul_allocations', [
            'id' => $records['garageAllocationId'],
            'status' => 'received',
        ]);
    }

    public function test_stock_in_rejects_invalid_garage_client_and_cancelled_sources(): void
    {
        $records = $this->stockInRecords();
        $otherGarageId = DB::table('storage_locations')->insertGetId([
            'location_code' => 'GAR-OTHER',
            'name' => 'Other Garage',
            'type' => 'garage',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-in.store'), [
                'haul_allocation_id' => $records['garageAllocationId'],
                'storage_location_id' => $otherGarageId,
                'quantity_liters' => 1000,
                'movement_date' => '2026-08-30 09:15:00',
            ])
            ->assertSessionHasErrors('stock_in');

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-in.store'), [
                'haul_allocation_id' => $records['customerAllocationId'],
                'storage_location_id' => $records['garageId'],
                'quantity_liters' => 1000,
                'movement_date' => '2026-08-30 09:15:00',
            ])
            ->assertSessionHasErrors('stock_in');

        DB::table('haul_allocations')->where('id', $records['garageAllocationId'])->update(['status' => 'cancelled']);

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-in.store'), [
                'haul_allocation_id' => $records['garageAllocationId'],
                'storage_location_id' => $records['garageId'],
                'quantity_liters' => 1000,
                'movement_date' => '2026-08-30 09:15:00',
            ])
            ->assertSessionHasErrors('stock_in');

        $this->assertSame(0, DB::table('inventory_movements')->count());
    }

    public function test_mixed_haul_allocation_only_adds_garage_quantity_to_inventory(): void
    {
        $records = $this->stockInRecords();

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-in.store'), [
                'haul_allocation_id' => $records['garageAllocationId'],
                'storage_location_id' => $records['garageId'],
                'quantity_liters' => 40000,
                'movement_date' => '2026-08-30 09:15:00',
                'remarks' => 'Garage portion only',
            ])
            ->assertRedirect(route('inventory-officer.inventory.stock-in'));

        $this->assertSame(40000.0, $this->garageBalance($records));
        $this->assertSame(1, DB::table('inventory_movements')->count());

        $this->actingAs($records['inventoryOfficer'])
            ->get(route('inventory-officer.inventory'))
            ->assertOk()
            ->assertSee('PUR-STOCK-IN')
            ->assertSee('LFT-STOCK-IN')
            ->assertSee('40,000.00')
            ->assertSee('Direct Client Allocation')
            ->assertSee('Garage Received With Direct');
    }

    public function test_stock_in_rejects_invalid_purchase_haul_relationship_without_inventory_effect(): void
    {
        $records = $this->stockInRecords();
        $otherPurchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => 'PUR-WRONG-LINK',
            'depot_id' => $records['depotId'],
            'purchase_date' => '2026-08-29',
            'payment_status' => 'paid',
            'status' => 'hauled',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('hauls')->where('id', $records['haulId'])->update(['purchase_id' => $otherPurchaseId]);

        $this->actingAs($records['inventoryOfficer'])
            ->from(route('inventory-officer.inventory.stock-in'))
            ->post(route('inventory-officer.inventory.stock-in.store'), [
                'haul_allocation_id' => $records['garageAllocationId'],
                'storage_location_id' => $records['garageId'],
                'quantity_liters' => 1000,
                'movement_date' => '2026-08-30 09:15:00',
            ])
            ->assertRedirect(route('inventory-officer.inventory.stock-in'))
            ->assertSessionHasErrors('stock_in');

        $this->assertSame(0, DB::table('inventory_movements')->count());
    }

    public function test_stock_in_rejects_haul_allocations_that_exceed_hauled_quantity(): void
    {
        $records = $this->stockInRecords();

        DB::table('haul_allocations')->where('id', $records['customerAllocationId'])->update([
            'quantity_liters' => 50000,
        ]);

        $this->actingAs($records['inventoryOfficer'])
            ->from(route('inventory-officer.inventory.stock-in'))
            ->post(route('inventory-officer.inventory.stock-in.store'), [
                'haul_allocation_id' => $records['garageAllocationId'],
                'storage_location_id' => $records['garageId'],
                'quantity_liters' => 1000,
                'movement_date' => '2026-08-30 09:15:00',
            ])
            ->assertRedirect(route('inventory-officer.inventory.stock-in'))
            ->assertSessionHasErrors('stock_in');

        $this->assertSame(0, DB::table('inventory_movements')->count());
    }

    public function test_stock_in_validation_requires_positive_quantity_and_real_records(): void
    {
        $records = $this->stockInRecords();

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-in.store'), [
                'haul_allocation_id' => 999999,
                'storage_location_id' => $records['garageId'],
                'quantity_liters' => 0,
                'movement_date' => 'not-a-date',
            ])
            ->assertSessionHasErrors(['haul_allocation_id', 'quantity_liters', 'movement_date']);

        $this->assertSame(0, DB::table('inventory_movements')->count());
    }

    public function test_non_inventory_roles_cannot_create_stock_in(): void
    {
        $records = $this->stockInRecords();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active']);
        $payload = [
            'haul_allocation_id' => $records['garageAllocationId'],
            'storage_location_id' => $records['garageId'],
            'quantity_liters' => 1000,
            'movement_date' => '2026-08-30 09:15:00',
        ];

        $this->actingAs($admin)
            ->post(route('inventory-officer.inventory.stock-in.store'), $payload)
            ->assertForbidden();

        $this->actingAs($salesOfficer)
            ->post(route('inventory-officer.inventory.stock-in.store'), $payload)
            ->assertForbidden();

        $this->assertSame(0, DB::table('inventory_movements')->count());
    }

    public function test_purchase_creation_alone_does_not_create_garage_stock(): void
    {
        $records = $this->baseRecords();

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.purchases.store'), [
                'purchase_date' => '2026-08-30',
                'depot_id' => $records['depotId'],
                'fuel_type_id' => $records['fuelTypeId'],
                'quantity_ordered_liters' => 40000,
                'unit_cost' => 52.50,
                'payment_status' => 'unpaid',
                'status' => 'ordered',
            ])
            ->assertRedirect(route('inventory-officer.inventory'));

        $this->assertSame(0, DB::table('hauls')->count());
        $this->assertSame(0, DB::table('haul_allocations')->count());
        $this->assertSame(0, DB::table('inventory_movements')->count());
    }

    public function test_admin_monitoring_reflects_inventory_officer_stock_in_ledger(): void
    {
        $records = $this->stockInRecords();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-in.store'), [
                'haul_allocation_id' => $records['garageAllocationId'],
                'storage_location_id' => $records['garageId'],
                'quantity_liters' => 40000,
                'movement_date' => '2026-08-30 09:15:00',
                'remarks' => 'Visible to admin monitoring',
            ]);

        $this->actingAs($admin)
            ->get(route('admin.inventory'))
            ->assertOk()
            ->assertSee('MOV-000001')
            ->assertSee('PUR-STOCK-IN')
            ->assertSee('CJP Garage')
            ->assertSee('40,000.00');
    }

    /**
     * @param array<string, mixed> $records
     */
    private function garageBalance(array $records): float
    {
        $incoming = (float) DB::table('inventory_movements')
            ->where('storage_location_id', $records['garageId'])
            ->where('fuel_type_id', $records['fuelTypeId'])
            ->where('direction', 'in')
            ->sum('quantity_liters');

        $outgoing = (float) DB::table('inventory_movements')
            ->where('storage_location_id', $records['garageId'])
            ->where('fuel_type_id', $records['fuelTypeId'])
            ->where('direction', 'out')
            ->sum('quantity_liters');

        return round($incoming - $outgoing, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function stockInRecords(): array
    {
        $records = $this->baseRecords();
        $driver = User::factory()->create([
            'role' => 'driver',
            'status' => 'active',
        ]);

        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-STOCK-IN',
            'capacity_liters' => 40000,
            'truck_type' => 'hauling',
            'status' => 'assigned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $garageId = DB::table('storage_locations')->insertGetId([
            'location_code' => 'GAR-CJP',
            'name' => 'CJP Garage',
            'type' => 'garage',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-STOCK-IN',
            'name' => 'Direct Customer',
            'company_name' => 'Direct Customer Co.',
            'payment_status' => 'clear',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => 'PUR-STOCK-IN',
            'depot_id' => $records['depotId'],
            'purchase_date' => '2026-08-29',
            'payment_status' => 'paid',
            'status' => 'hauled',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $purchaseItemId = DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchaseId,
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_ordered_liters' => 80000,
            'unit_cost' => 52.50,
            'line_total' => 4200000,
            'quantity_hauled_liters' => 80000,
            'status' => 'lifted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $haulId = DB::table('hauls')->insertGetId([
            'haul_code' => 'LFT-STOCK-IN',
            'purchase_id' => $purchaseId,
            'purchase_item_id' => $purchaseItemId,
            'depot_id' => $records['depotId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'truck_id' => $truckId,
            'driver_user_id' => $driver->id,
            'scheduled_at' => '2026-08-30 07:00:00',
            'hauled_at' => '2026-08-30 08:00:00',
            'quantity_liters' => 80000,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $garageAllocationId = DB::table('haul_allocations')->insertGetId([
            'haul_id' => $haulId,
            'fuel_type_id' => $records['fuelTypeId'],
            'destination_type' => 'garage',
            'storage_location_id' => $garageId,
            'quantity_liters' => 40000,
            'allocated_at' => '2026-08-30 08:30:00',
            'status' => 'delivered',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerAllocationId = DB::table('haul_allocations')->insertGetId([
            'haul_id' => $haulId,
            'fuel_type_id' => $records['fuelTypeId'],
            'destination_type' => 'customer',
            'customer_id' => $customerId,
            'quantity_liters' => 40000,
            'allocated_at' => '2026-08-30 08:30:00',
            'status' => 'delivered',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return array_merge($records, compact(
            'truckId',
            'garageId',
            'customerId',
            'purchaseId',
            'purchaseItemId',
            'haulId',
            'garageAllocationId',
            'customerAllocationId'
        ));
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
            'depot_code' => 'DEP-STOCK-IN',
            'name' => 'Source Depot',
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
