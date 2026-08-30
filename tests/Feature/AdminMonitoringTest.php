<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_monitoring_pages_display_real_database_records(): void
    {
        $records = $this->createMonitoringRecords();

        $this->actingAs($records['admin'])
            ->get(route('admin.inventory'))
            ->assertOk()
            ->assertSee('PUR-MONITOR')
            ->assertSee('Premium Monitor')
            ->assertSee('MOV-MONITOR-IN')
            ->assertSee('SLS-MONITOR')
            ->assertSee('Monitor Customer')
            ->assertDontSee('Petron A');

        $this->actingAs($records['admin'])
            ->get(route('admin.ledger'))
            ->assertOk()
            ->assertSee('PUR-MONITOR')
            ->assertSee('LFT-MONITOR')
            ->assertSee('Stock In');

        $this->actingAs($records['admin'])
            ->get(route('admin.fuel-lifting'))
            ->assertOk()
            ->assertSee('LFT-MONITOR')
            ->assertSee('TRK-MONITOR')
            ->assertSee('Monitor Driver')
            ->assertSee('In Transit');

        $this->actingAs($records['admin'])
            ->get(route('admin.sales'))
            ->assertOk()
            ->assertSee('SLS-MONITOR')
            ->assertSee('Monitor Customer Company')
            ->assertSee('PAY-MONITOR')
            ->assertSee('600,000.00');

        $this->actingAs($records['admin'])
            ->get(route('admin.alerts'))
            ->assertOk()
            ->assertSee('ALT-MONITOR')
            ->assertSee('Monitor alert message')
            ->assertDontSee('ALT-000001 - Stock critically low');
    }

    public function test_admin_monitoring_search_filters_real_records(): void
    {
        $records = $this->createMonitoringRecords();

        DB::table('fuel_types')->insert([
            'code' => 'OTHER',
            'name' => 'Other Fuel',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($records['admin'])
            ->get(route('admin.inventory', ['search' => 'Premium Monitor']))
            ->assertOk()
            ->assertSee('PUR-MONITOR')
            ->assertDontSee('Other Fuel');

        $this->actingAs($records['admin'])
            ->get(route('admin.sales', ['search' => 'Monitor Customer']))
            ->assertOk()
            ->assertSee('SLS-MONITOR')
            ->assertSee('Monitor Customer Company');

        $this->actingAs($records['admin'])
            ->get(route('admin.alerts', ['search' => 'ALT-MONITOR']))
            ->assertOk()
            ->assertSee('Monitor alert message');
    }

    public function test_monitoring_pages_have_empty_states_and_do_not_create_records(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $before = DB::table('purchases')->count()
            + DB::table('sales')->count()
            + DB::table('hauls')->count()
            + DB::table('alerts')->count();

        $this->actingAs($admin)->get(route('admin.inventory'))->assertOk()->assertSee('No records found.');
        $this->actingAs($admin)->get(route('admin.ledger'))->assertOk()->assertSee('No inventory movements found.');
        $this->actingAs($admin)->get(route('admin.fuel-lifting'))->assertOk()->assertSee('No records found.');
        $this->actingAs($admin)->get(route('admin.sales'))->assertOk()->assertSee('No records found.');
        $this->actingAs($admin)->get(route('admin.alerts'))->assertOk()->assertSee('No records found.');

        $after = DB::table('purchases')->count()
            + DB::table('sales')->count()
            + DB::table('hauls')->count()
            + DB::table('alerts')->count();

        $this->assertSame($before, $after);
    }

    public function test_non_admin_users_are_blocked_from_monitoring_pages(): void
    {
        $user = User::factory()->create([
            'role' => 'sales_officer',
            'status' => 'active',
        ]);

        $this->actingAs($user)->get(route('admin.inventory'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.ledger'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.fuel-lifting'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.sales'))->assertForbidden();
        $this->actingAs($user)->get(route('admin.alerts'))->assertForbidden();
    }

    /**
     * @return array<string, mixed>
     */
    private function createMonitoringRecords(): array
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $driver = User::factory()->create([
            'name' => 'Monitor Driver',
            'role' => 'driver',
            'phone' => '09175550123',
            'status' => 'active',
        ]);

        $depotId = DB::table('depots')->insertGetId([
            'depot_code' => 'DEP-MONITOR',
            'name' => 'Monitor Depot',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $storageLocationId = DB::table('storage_locations')->insertGetId([
            'location_code' => 'GAR-MONITOR',
            'name' => 'Monitor Garage',
            'type' => 'garage',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fuelTypeId = DB::table('fuel_types')->insertGetId([
            'code' => 'PMON',
            'name' => 'Premium Monitor',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-MONITOR',
            'name' => 'Monitor Customer',
            'company_name' => 'Monitor Customer Company',
            'location' => 'Nasugbu',
            'email' => 'monitor-customer@example.com',
            'phone' => '09170000000',
            'payment_status' => 'partial',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-MONITOR',
            'capacity_liters' => 40000,
            'truck_type' => 'hauling',
            'status' => 'assigned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => 'PUR-MONITOR',
            'depot_id' => $depotId,
            'purchase_date' => '2026-08-20',
            'receipt_reference' => 'DR-MONITOR',
            'payment_status' => 'partial',
            'status' => 'partially_hauled',
            'created_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $purchaseItemId = DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchaseId,
            'fuel_type_id' => $fuelTypeId,
            'quantity_ordered_liters' => 100000,
            'unit_cost' => 50,
            'line_total' => 5000000,
            'quantity_hauled_liters' => 40000,
            'status' => 'partial',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventory_movements')->insert([
            'movement_code' => 'MOV-MONITOR-IN',
            'storage_location_id' => $storageLocationId,
            'fuel_type_id' => $fuelTypeId,
            'movement_type' => 'stock_in',
            'direction' => 'in',
            'quantity_liters' => 40000,
            'unit_cost' => 50,
            'reference_type' => 'purchase_item',
            'reference_id' => $purchaseItemId,
            'movement_date' => '2026-08-21 08:00:00',
            'created_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => 'SLS-MONITOR',
            'customer_id' => $customerId,
            'sale_date' => '2026-08-22',
            'payment_method' => 'bank_transfer',
            'payment_terms' => 'installment',
            'status' => 'partially_paid',
            'created_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $saleItemId = DB::table('sale_items')->insertGetId([
            'sale_id' => $saleId,
            'fuel_type_id' => $fuelTypeId,
            'quantity_liters' => 10000,
            'unit_price' => 90,
            'line_total' => 900000,
            'fulfilled_quantity_liters' => 5000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payments')->insert([
            'payment_code' => 'PAY-MONITOR',
            'sale_id' => $saleId,
            'payment_date' => '2026-08-23',
            'amount' => 600000,
            'method' => 'bank_transfer',
            'reference_number' => 'BNK-MONITOR',
            'received_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('receivables')->insert([
            'sale_id' => $saleId,
            'due_date' => '2026-09-01',
            'status' => 'partial',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('stock_outs')->insert([
            'stock_out_code' => 'STO-MONITOR',
            'sale_id' => $saleId,
            'sale_item_id' => $saleItemId,
            'customer_id' => $customerId,
            'fuel_type_id' => $fuelTypeId,
            'storage_location_id' => $storageLocationId,
            'quantity_liters' => 5000,
            'stock_out_at' => '2026-08-24 08:00:00',
            'status' => 'released',
            'created_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('hauls')->insert([
            'haul_code' => 'LFT-MONITOR',
            'purchase_id' => $purchaseId,
            'purchase_item_id' => $purchaseItemId,
            'depot_id' => $depotId,
            'fuel_type_id' => $fuelTypeId,
            'truck_id' => $truckId,
            'driver_user_id' => $driver->id,
            'dr_number' => 'DR-LFT-MONITOR',
            'scheduled_at' => '2026-08-25 08:00:00',
            'source_location' => 'Monitor Source',
            'quantity_liters' => 40000,
            'status' => 'in_transit',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('deliveries')->insert([
            'delivery_code' => 'DEL-MONITOR',
            'sale_id' => $saleId,
            'sale_item_id' => $saleItemId,
            'customer_id' => $customerId,
            'fuel_type_id' => $fuelTypeId,
            'source_type' => 'garage',
            'storage_location_id' => $storageLocationId,
            'truck_id' => $truckId,
            'driver_user_id' => $driver->id,
            'scheduled_at' => '2026-08-26 08:00:00',
            'scheduled_quantity_liters' => 5000,
            'status' => 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('alerts')->insert([
            'alert_code' => 'ALT-MONITOR',
            'type' => 'haul',
            'severity' => 'warning',
            'title' => 'Monitor alert title',
            'message' => 'Monitor alert message',
            'reference_type' => 'hauls',
            'reference_id' => 1,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['admin' => $admin];
    }
}
