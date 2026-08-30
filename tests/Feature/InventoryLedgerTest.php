<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_officer_ledger_displays_stock_in_once_with_positive_balance(): void
    {
        $records = $this->baseRecords();
        $this->movement($records, [
            'movement_code' => 'MOV-IN-ONE',
            'movement_type' => 'stock_in',
            'direction' => 'in',
            'quantity_liters' => 40000,
            'remarks' => 'Confirmed stock-in',
        ]);

        $this->actingAs($records['inventoryOfficer'])
            ->get(route('inventory-officer.ledger'))
            ->assertOk()
            ->assertSee('MOV-IN-ONE')
            ->assertSee('Stock In')
            ->assertSee('40,000.00')
            ->assertSee('Confirmed stock-in')
            ->assertDontSee('PUR-000002');
    }

    public function test_stock_out_movement_decreases_running_balance(): void
    {
        $records = $this->baseRecords();
        $this->movement($records, [
            'movement_code' => 'MOV-IN-BAL',
            'movement_type' => 'stock_in',
            'direction' => 'in',
            'quantity_liters' => 50000,
            'movement_date' => '2026-08-30 08:00:00',
        ]);
        $stockOutId = $this->stockOut($records);
        $this->movement($records, [
            'movement_code' => 'MOV-OUT-BAL',
            'movement_type' => 'stock_out',
            'direction' => 'out',
            'quantity_liters' => 12500,
            'reference_type' => 'stock_out',
            'reference_id' => $stockOutId,
            'movement_date' => '2026-08-30 09:00:00',
        ]);

        $this->actingAs($records['inventoryOfficer'])
            ->get(route('inventory-officer.ledger'))
            ->assertOk()
            ->assertSee('STO-LEDGER / SLS-LEDGER')
            ->assertSeeInOrder(['MOV-OUT-BAL', '12,500.00', '37,500.00', 'MOV-IN-BAL', '50,000.00']);
    }

    public function test_cancelled_stock_out_without_movement_does_not_affect_ledger(): void
    {
        $records = $this->baseRecords();
        $this->movement($records, [
            'movement_code' => 'MOV-IN-CANCEL',
            'movement_type' => 'stock_in',
            'direction' => 'in',
            'quantity_liters' => 20000,
        ]);
        $this->stockOut($records, ['status' => 'cancelled', 'stock_out_code' => 'STO-CANCELLED']);

        $this->actingAs($records['inventoryOfficer'])
            ->get(route('inventory-officer.ledger'))
            ->assertOk()
            ->assertSee('MOV-IN-CANCEL')
            ->assertSee('20,000.00')
            ->assertDontSee('STO-CANCELLED');
    }

    public function test_running_balances_are_independent_by_fuel_and_garage(): void
    {
        $records = $this->baseRecords();
        $e10Id = DB::table('fuel_types')->insertGetId([
            'code' => 'E10',
            'name' => 'E10',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $otherGarageId = DB::table('storage_locations')->insertGetId([
            'location_code' => 'GAR-OTHER',
            'name' => 'Other Garage',
            'type' => 'garage',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->movement($records, ['movement_code' => 'MOV-DIESEL-IN', 'quantity_liters' => 30000]);
        $this->movement(array_merge($records, ['fuelTypeId' => $e10Id]), ['movement_code' => 'MOV-E10-IN', 'quantity_liters' => 7000]);
        $this->movement(array_merge($records, ['garageId' => $otherGarageId]), ['movement_code' => 'MOV-OTHER-GARAGE-IN', 'quantity_liters' => 11000]);

        $this->actingAs($records['inventoryOfficer'])
            ->get(route('inventory-officer.ledger'))
            ->assertOk()
            ->assertSee('Diesel')
            ->assertSee('E10')
            ->assertSee('CJP Garage')
            ->assertSee('Other Garage')
            ->assertSee('30,000.00')
            ->assertSee('7,000.00')
            ->assertSee('11,000.00');
    }

    public function test_search_filters_display_without_changing_running_balance(): void
    {
        $records = $this->baseRecords();
        $this->movement($records, [
            'movement_code' => 'MOV-DIESEL-IN',
            'movement_type' => 'stock_in',
            'direction' => 'in',
            'quantity_liters' => 40000,
            'movement_date' => '2026-08-30 08:00:00',
        ]);
        $this->movement($records, [
            'movement_code' => 'MOV-DIESEL-OUT',
            'movement_type' => 'stock_out',
            'direction' => 'out',
            'quantity_liters' => 10000,
            'movement_date' => '2026-08-30 09:00:00',
            'remarks' => 'Filtered outbound',
        ]);

        $this->actingAs($records['inventoryOfficer'])
            ->get(route('inventory-officer.ledger', ['search' => 'Filtered outbound']))
            ->assertOk()
            ->assertSee('MOV-DIESEL-OUT')
            ->assertSee('30,000.00')
            ->assertDontSee('MOV-DIESEL-IN');
    }

    public function test_admin_ledger_reads_same_inventory_movement_data(): void
    {
        $records = $this->baseRecords();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->movement($records, [
            'movement_code' => 'MOV-ADMIN-LEDGER',
            'movement_type' => 'beginning',
            'direction' => 'in',
            'quantity_liters' => 9000,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.ledger'))
            ->assertOk()
            ->assertSee('MOV-ADMIN-LEDGER')
            ->assertSee('Beginning')
            ->assertSee('9,000.00');
    }

    public function test_empty_ledgers_load_without_mock_rows(): void
    {
        $inventoryOfficer = User::factory()->create(['role' => 'inventory_officer', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($inventoryOfficer)
            ->get(route('inventory-officer.ledger'))
            ->assertOk()
            ->assertSee('No inventory movements found.')
            ->assertDontSee('PUR-000001');

        $this->actingAs($admin)
            ->get(route('admin.ledger'))
            ->assertOk()
            ->assertSee('No inventory movements found.')
            ->assertDontSee('PUR-000001');
    }

    public function test_latest_ledger_balance_matches_current_inventory_aggregate(): void
    {
        $records = $this->baseRecords();
        $this->movement($records, ['movement_code' => 'MOV-BAL-IN', 'quantity_liters' => 45000]);
        $this->movement($records, [
            'movement_code' => 'MOV-BAL-OUT',
            'movement_type' => 'stock_out',
            'direction' => 'out',
            'quantity_liters' => 12000,
            'movement_date' => '2026-08-30 10:00:00',
        ]);

        $aggregate = (float) DB::table('inventory_movements')
            ->where('storage_location_id', $records['garageId'])
            ->where('fuel_type_id', $records['fuelTypeId'])
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN quantity_liters ELSE -quantity_liters END), 0) as balance")
            ->value('balance');

        $this->actingAs($records['inventoryOfficer'])
            ->get(route('inventory-officer.ledger'))
            ->assertOk()
            ->assertSee(number_format($aggregate, 2));
    }

    public function test_duplicate_stock_in_submission_does_not_create_duplicate_ledger_effects(): void
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
            ->post(route('inventory-officer.inventory.stock-in.store'), $payload)
            ->assertSessionHasErrors('stock_in');

        $this->assertSame(1, DB::table('inventory_movements')->count());

        $this->actingAs($records['inventoryOfficer'])
            ->get(route('inventory-officer.ledger'))
            ->assertOk()
            ->assertSee('40,000.00');
    }

    /**
     * @param array<string, mixed> $records
     * @param array<string, mixed> $overrides
     */
    private function movement(array $records, array $overrides = []): int
    {
        return DB::table('inventory_movements')->insertGetId(array_merge([
            'movement_code' => 'MOV-LEDGER',
            'storage_location_id' => $records['garageId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'movement_type' => 'stock_in',
            'direction' => 'in',
            'quantity_liters' => 10000,
            'unit_cost' => 50,
            'reference_type' => 'test',
            'reference_id' => 1,
            'movement_date' => '2026-08-30 08:00:00',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $records
     * @param array<string, mixed> $overrides
     */
    private function stockOut(array $records, array $overrides = []): int
    {
        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => uniqid('CUS-'),
            'name' => 'Ledger Customer',
            'company_name' => 'Ledger Customer Co.',
            'payment_status' => 'clear',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => 'SLS-LEDGER',
            'customer_id' => $customerId,
            'sale_date' => '2026-08-30',
            'payment_method' => 'cash_on_delivery',
            'payment_terms' => 'cod',
            'status' => 'paid',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $saleItemId = DB::table('sale_items')->insertGetId([
            'sale_id' => $saleId,
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_liters' => 12500,
            'unit_price' => 70,
            'line_total' => 875000,
            'fulfilled_quantity_liters' => 12500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('stock_outs')->insertGetId(array_merge([
            'stock_out_code' => 'STO-LEDGER',
            'sale_id' => $saleId,
            'sale_item_id' => $saleItemId,
            'customer_id' => $customerId,
            'fuel_type_id' => $records['fuelTypeId'],
            'storage_location_id' => $records['garageId'],
            'quantity_liters' => 12500,
            'stock_out_at' => '2026-08-30 09:00:00',
            'status' => 'released',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private function stockInRecords(): array
    {
        $records = $this->baseRecords();
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active']);
        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-LEDGER',
            'capacity_liters' => 40000,
            'truck_type' => 'hauling',
            'status' => 'assigned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => 'PUR-LEDGER',
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
            'quantity_ordered_liters' => 40000,
            'unit_cost' => 50,
            'line_total' => 2000000,
            'quantity_hauled_liters' => 40000,
            'status' => 'lifted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $haulId = DB::table('hauls')->insertGetId([
            'haul_code' => 'LFT-LEDGER',
            'purchase_id' => $purchaseId,
            'purchase_item_id' => $purchaseItemId,
            'depot_id' => $records['depotId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'truck_id' => $truckId,
            'driver_user_id' => $driver->id,
            'scheduled_at' => '2026-08-30 07:00:00',
            'quantity_liters' => 40000,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $garageAllocationId = DB::table('haul_allocations')->insertGetId([
            'haul_id' => $haulId,
            'fuel_type_id' => $records['fuelTypeId'],
            'destination_type' => 'garage',
            'storage_location_id' => $records['garageId'],
            'quantity_liters' => 40000,
            'allocated_at' => '2026-08-30 08:30:00',
            'status' => 'delivered',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return array_merge($records, compact('garageAllocationId'));
    }

    /**
     * @return array<string, mixed>
     */
    private function baseRecords(): array
    {
        $inventoryOfficer = User::factory()->create([
            'role' => 'inventory_officer',
            'status' => 'active',
        ]);
        $depotId = DB::table('depots')->insertGetId([
            'depot_code' => 'DEP-LEDGER',
            'name' => 'Ledger Depot',
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
        $garageId = DB::table('storage_locations')->insertGetId([
            'location_code' => 'GAR-LEDGER',
            'name' => 'CJP Garage',
            'type' => 'garage',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('inventoryOfficer', 'depotId', 'fuelTypeId', 'garageId');
    }
}
