<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DispatchDeliveryMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_officer_monitors_active_completed_and_cancelled_delivery_data(): void
    {
        $records = $this->baseRecords();
        $garageDeliveryId = $this->garageDelivery($records, ['delivery_code' => 'DLV-MON-GARAGE', 'stock_out_code' => 'STO-MON-GARAGE']);
        $allocation = $this->directAllocation($records, ['haul_code' => 'LFT-MON-DIRECT']);
        $depotDeliveryId = $this->depotDelivery($records, $allocation['allocationId'], [
            'delivery_code' => 'DLV-MON-DEPOT',
            'status' => 'in_transit',
        ]);
        $this->depotDelivery($records, $allocation['allocationId'], [
            'delivery_code' => 'DLV-MON-DONE',
            'status' => 'delivered',
            'delivered_at' => '2026-09-02 10:00:00',
            'actual_quantity_liters' => 4000,
        ]);
        $this->garageDelivery($records, [
            'delivery_code' => 'DLV-MON-CANCEL',
            'stock_out_code' => 'STO-MON-CANCEL',
            'status' => 'cancelled',
        ]);

        $this->actingAs($records['dispatchOfficer'])
            ->get(route('dispatch.fuel-lifting'))
            ->assertOk()
            ->assertSee('DLV-MON-GARAGE')
            ->assertSee('STO-MON-GARAGE')
            ->assertSee('Dispatch Monitor Garage')
            ->assertSee('DLV-MON-DEPOT')
            ->assertSee('LFT-MON-DIRECT')
            ->assertSee('Dispatch Monitor Depot')
            ->assertSee('Lifting Status')
            ->assertSee('Completed')
            ->assertSee('Monitor Customer Co.')
            ->assertSee($records['driver']->name)
            ->assertSee('TRK-MON-DLV')
            ->assertSee('Diesel Monitor');

        $this->actingAs($records['dispatchOfficer'])
            ->get(route('dispatch.fuel-lifting.hauled'))
            ->assertOk()
            ->assertSee('DLV-MON-DONE')
            ->assertSee('Delivered')
            ->assertSee('DLV-MON-CANCEL')
            ->assertSee('Cancelled');

        $this->assertDatabaseHas('deliveries', ['id' => $garageDeliveryId, 'status' => 'scheduled']);
        $this->assertDatabaseHas('deliveries', ['id' => $depotDeliveryId, 'status' => 'in_transit']);
    }

    public function test_delivery_monitoring_search_filters_and_summary_counts_use_real_records(): void
    {
        $records = $this->baseRecords();
        $other = $this->baseRecords([
            'driver_name' => 'Other Monitor Driver',
            'fuel_name' => 'Kerosene Monitor',
            'truck_code' => 'TRK-MON-OTHER',
            'customer_company' => 'Other Monitor Co.',
        ]);
        $allocation = $this->directAllocation($records, ['haul_code' => 'LFT-FILTER-MATCH']);
        $this->depotDelivery($records, $allocation['allocationId'], [
            'delivery_code' => 'DLV-FILTER-MATCH',
            'scheduled_at' => '2026-09-03 09:00:00',
            'status' => 'in_transit',
        ]);
        $this->garageDelivery($other, [
            'delivery_code' => 'DLV-FILTER-OTHER',
            'stock_out_code' => 'STO-FILTER-OTHER',
            'scheduled_at' => '2026-09-04 09:00:00',
            'status' => 'scheduled',
        ]);

        $this->actingAs($records['dispatchOfficer'])
            ->get(route('dispatch.fuel-lifting', [
                'search' => 'LFT-FILTER-MATCH',
                'source_type' => 'depot',
                'fuel_type_id' => $records['fuelTypeId'],
                'driver_user_id' => $records['driver']->id,
                'truck_id' => $records['truckId'],
                'date_from' => '2026-09-03',
                'date_to' => '2026-09-03',
            ]))
            ->assertOk()
            ->assertSee('DLV-FILTER-MATCH')
            ->assertSee('LFT-FILTER-MATCH')
            ->assertSee('Total Deliveries')
            ->assertSee('Active')
            ->assertDontSee('DLV-FILTER-OTHER');
    }

    public function test_monitoring_is_read_only_and_role_protected(): void
    {
        $records = $this->baseRecords();
        $this->garageDelivery($records);
        $before = $this->sideEffectCounts($records);

        $this->actingAs($records['dispatchOfficer'])
            ->get(route('dispatch.fuel-lifting', ['status' => 'scheduled']))
            ->assertOk();

        $this->assertSame($before, $this->sideEffectCounts($records));

        foreach (['sales_officer', 'inventory_officer', 'driver'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);
            $this->actingAs($user)
                ->get(route('dispatch.fuel-lifting'))
                ->assertForbidden();
        }

        $this->actingAs($records['dispatchOfficer'])
            ->get(route('dispatch.fuel-lifting', ['status' => 'bogus']))
            ->assertSessionHasErrors('status');
    }

    /**
     * @param array<string, mixed> $records
     * @param array<string, mixed> $overrides
     */
    private function garageDelivery(array $records, array $overrides = []): int
    {
        $sale = $this->sale($records, $overrides);
        $stockOutId = $this->stockOut($records, $sale, $overrides);

        $deliveryId = DB::table('deliveries')->insertGetId([
            'delivery_code' => $overrides['delivery_code'] ?? 'DLV-MON-GAR',
            'sale_id' => $sale['saleId'],
            'sale_item_id' => $sale['saleItemId'],
            'customer_id' => $records['customerId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'source_type' => 'garage',
            'storage_location_id' => $records['garageId'],
            'truck_id' => $records['truckId'],
            'driver_user_id' => $records['driver']->id,
            'scheduled_at' => $overrides['scheduled_at'] ?? '2026-09-01 09:00:00',
            'delivered_at' => $overrides['delivered_at'] ?? null,
            'scheduled_quantity_liters' => $overrides['scheduled_quantity_liters'] ?? 10000,
            'actual_quantity_liters' => $overrides['actual_quantity_liters'] ?? null,
            'status' => $overrides['status'] ?? 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('stock_outs')->where('id', $stockOutId)->update(['delivery_id' => $deliveryId]);

        return $deliveryId;
    }

    /**
     * @param array<string, mixed> $records
     * @param array<string, mixed> $overrides
     * @return array<string, int>
     */
    private function directAllocation(array $records, array $overrides = []): array
    {
        $sale = $this->sale($records, ['sale_code' => $overrides['sale_code'] ?? 'SLS-MON-DIRECT']);
        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => 'PUR-'.Str::upper(Str::random(8)),
            'depot_id' => $records['depotId'],
            'purchase_date' => '2026-08-31',
            'payment_status' => 'paid',
            'status' => 'hauled',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $purchaseItemId = DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchaseId,
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_ordered_liters' => 20000,
            'unit_cost' => 50,
            'line_total' => 1000000,
            'quantity_hauled_liters' => 20000,
            'status' => 'lifted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $haulId = DB::table('hauls')->insertGetId([
            'haul_code' => $overrides['haul_code'] ?? 'LFT-MON-DIRECT',
            'purchase_id' => $purchaseId,
            'purchase_item_id' => $purchaseItemId,
            'depot_id' => $records['depotId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'truck_id' => $records['haulingTruckId'],
            'driver_user_id' => $records['driver']->id,
            'scheduled_at' => '2026-08-31 07:00:00',
            'hauled_at' => '2026-08-31 08:00:00',
            'source_location' => 'Monitor Depot Rack',
            'quantity_liters' => 20000,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $allocationId = DB::table('haul_allocations')->insertGetId([
            'haul_id' => $haulId,
            'fuel_type_id' => $records['fuelTypeId'],
            'destination_type' => 'customer',
            'customer_id' => $records['customerId'],
            'sale_id' => $sale['saleId'],
            'quantity_liters' => 10000,
            'allocated_at' => '2026-08-31 08:30:00',
            'status' => 'planned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('haulId', 'allocationId');
    }

    /**
     * @param array<string, mixed> $records
     * @param array<string, mixed> $overrides
     */
    private function depotDelivery(array $records, int $allocationId, array $overrides = []): int
    {
        $allocation = DB::table('haul_allocations')->where('id', $allocationId)->first();

        return DB::table('deliveries')->insertGetId([
            'delivery_code' => $overrides['delivery_code'] ?? 'DLV-MON-DEPOT',
            'sale_id' => $allocation->sale_id,
            'customer_id' => $allocation->customer_id,
            'fuel_type_id' => $allocation->fuel_type_id,
            'source_type' => 'depot',
            'depot_id' => $records['depotId'],
            'haul_allocation_id' => $allocationId,
            'truck_id' => $records['truckId'],
            'driver_user_id' => $records['driver']->id,
            'scheduled_at' => $overrides['scheduled_at'] ?? '2026-09-02 09:00:00',
            'delivered_at' => $overrides['delivered_at'] ?? null,
            'scheduled_quantity_liters' => $overrides['scheduled_quantity_liters'] ?? 6000,
            'actual_quantity_liters' => $overrides['actual_quantity_liters'] ?? null,
            'status' => $overrides['status'] ?? 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $records
     * @param array<string, mixed> $overrides
     * @return array<string, int>
     */
    private function sale(array $records, array $overrides = []): array
    {
        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => $overrides['sale_code'] ?? 'SLS-MON-'.Str::upper(Str::random(6)),
            'customer_id' => $records['customerId'],
            'sale_date' => '2026-08-31',
            'payment_method' => 'cash_on_delivery',
            'payment_terms' => 'cod',
            'status' => 'confirmed',
            'created_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $saleItemId = DB::table('sale_items')->insertGetId([
            'sale_id' => $saleId,
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_liters' => 10000,
            'unit_price' => 60,
            'line_total' => 600000,
            'fulfilled_quantity_liters' => 10000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('saleId', 'saleItemId');
    }

    /**
     * @param array<string, mixed> $records
     * @param array<string, int> $sale
     * @param array<string, mixed> $overrides
     */
    private function stockOut(array $records, array $sale, array $overrides = []): int
    {
        DB::table('inventory_movements')->insert([
            'movement_code' => 'MOV-'.Str::upper(Str::random(8)),
            'storage_location_id' => $records['garageId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'movement_type' => 'stock_out',
            'direction' => 'out',
            'quantity_liters' => 10000,
            'reference_type' => 'stock_out',
            'reference_id' => 1,
            'movement_date' => '2026-08-31 08:00:00',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('stock_outs')->insertGetId([
            'stock_out_code' => $overrides['stock_out_code'] ?? 'STO-MON-'.Str::upper(Str::random(6)),
            'sale_id' => $sale['saleId'],
            'sale_item_id' => $sale['saleItemId'],
            'customer_id' => $records['customerId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'storage_location_id' => $records['garageId'],
            'quantity_liters' => 10000,
            'stock_out_at' => '2026-08-31 08:00:00',
            'status' => 'released',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $records
     * @return array<string, int>
     */
    private function sideEffectCounts(array $records): array
    {
        return [
            'payments' => DB::table('payments')->count(),
            'receivables' => DB::table('receivables')->count(),
            'stock_outs' => DB::table('stock_outs')->count(),
            'inventory_movements' => DB::table('inventory_movements')->count(),
            'hauls' => DB::table('hauls')->count(),
            'deliveries' => DB::table('deliveries')->count(),
            'garage_balance' => (int) DB::table('inventory_movements')
                ->where('storage_location_id', $records['garageId'])
                ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN quantity_liters ELSE -quantity_liters END), 0) as balance")
                ->value('balance'),
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function baseRecords(array $overrides = []): array
    {
        $dispatchOfficer = User::factory()->create(['role' => 'dispatch_officer', 'status' => 'active']);
        $inventoryOfficer = User::factory()->create(['role' => 'inventory_officer', 'status' => 'active']);
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active']);
        $driver = User::factory()->create([
            'role' => 'driver',
            'status' => 'active',
            'name' => $overrides['driver_name'] ?? 'Monitor Driver',
            'phone' => '09990002222',
        ]);
        DB::table('driver_profiles')->insert([
            'user_id' => $driver->id,
            'driver_code' => 'DRV-'.Str::upper(Str::random(8)),
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => $overrides['truck_code'] ?? 'TRK-MON-DLV',
            'plate_number' => 'MON-'.Str::upper(Str::random(6)),
            'capacity_liters' => 30000,
            'truck_type' => 'delivery',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $haulingTruckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-MON-HAUL-'.Str::upper(Str::random(4)),
            'plate_number' => 'HAUL-'.Str::upper(Str::random(6)),
            'capacity_liters' => 30000,
            'truck_type' => 'hauling',
            'status' => 'assigned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $depotId = DB::table('depots')->insertGetId([
            'depot_code' => 'DEP-'.Str::upper(Str::random(8)),
            'name' => 'Dispatch Monitor Depot',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $garageId = DB::table('storage_locations')->insertGetId([
            'location_code' => 'GAR-'.Str::upper(Str::random(8)),
            'name' => 'Dispatch Monitor Garage',
            'type' => 'garage',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $fuelTypeId = DB::table('fuel_types')->insertGetId([
            'code' => 'FUEL-'.Str::upper(Str::random(8)),
            'name' => $overrides['fuel_name'] ?? 'Diesel Monitor',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-'.Str::upper(Str::random(8)),
            'name' => $overrides['customer_name'] ?? 'Monitor Customer',
            'company_name' => $overrides['customer_company'] ?? 'Monitor Customer Co.',
            'location' => 'Monitoring, Pampanga',
            'payment_status' => 'clear',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('dispatchOfficer', 'inventoryOfficer', 'salesOfficer', 'driver', 'truckId', 'haulingTruckId', 'depotId', 'garageId', 'fuelTypeId', 'customerId');
    }
}
