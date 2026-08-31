<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DriverDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_dashboard_loads_authenticated_driver_assignments_and_profile(): void
    {
        $records = $this->baseRecords();
        $this->garageDelivery($records, ['delivery_code' => 'DLV-DRV-GARAGE', 'stock_out_code' => 'STO-DRV-GARAGE']);
        $allocation = $this->directAllocation($records, ['haul_code' => 'LFT-DRV-DIRECT']);
        $this->depotDelivery($records, $allocation['allocationId'], ['delivery_code' => 'DLV-DRV-DEPOT', 'status' => 'in_transit']);
        $this->directAllocation($records, ['haul_code' => 'LFT-DRV-GARAGE', 'destination_type' => 'garage']);

        $this->actingAs($records['driver'])
            ->get(route('driver.fuel-lifting'))
            ->assertOk()
            ->assertSee($records['driver']->name)
            ->assertSee('DRV-DASH')
            ->assertSee('LIC-DASH')
            ->assertSee('Assigned')
            ->assertSee('Scheduled')
            ->assertSee('Active')
            ->assertSee('Completed')
            ->assertSee('DLV-DRV-GARAGE')
            ->assertSee('STO-DRV-GARAGE')
            ->assertSee('DLV-DRV-DEPOT')
            ->assertSee('LFT-DRV-DIRECT')
            ->assertSee('LFT-DRV-GARAGE')
            ->assertSee('Dashboard Depot')
            ->assertSee('Dashboard Garage')
            ->assertSee('Dashboard Customer Co.')
            ->assertSee('Diesel Dashboard')
            ->assertSee('TRK-DRV-DLV')
            ->assertSee('Truck-ID')
            ->assertSee('Lifting Status');
    }

    public function test_driver_completed_assignments_and_search_remain_scoped_to_authenticated_driver(): void
    {
        $records = $this->baseRecords();
        $other = $this->baseRecords([
            'driver_name' => 'Other Dashboard Driver',
            'driver_code' => 'DRV-OTHER',
            'truck_code' => 'TRK-OTHER-DLV',
            'customer_company' => 'Other Dashboard Co.',
        ]);
        $this->garageDelivery($records, [
            'delivery_code' => 'DLV-DRV-DONE',
            'stock_out_code' => 'STO-DRV-DONE',
            'status' => 'delivered',
            'delivered_at' => '2026-09-02 10:00:00',
            'actual_quantity_liters' => 10000,
        ]);
        $this->directAllocation($records, ['haul_code' => 'LFT-DRV-DONE', 'haul_status' => 'completed']);
        $this->garageDelivery($other, ['delivery_code' => 'DLV-OTHER-DONE', 'stock_out_code' => 'STO-OTHER-DONE', 'status' => 'delivered']);

        $this->actingAs($records['driver'])
            ->get(route('driver.fuel-lifting.hauled', ['search' => 'DONE']))
            ->assertOk()
            ->assertSee('DLV-DRV-DONE')
            ->assertSee('LFT-DRV-DONE')
            ->assertSee('Delivered')
            ->assertSee('Completed')
            ->assertDontSee('DLV-OTHER-DONE')
            ->assertDontSee('Other Dashboard Co.');

        $this->actingAs($records['driver'])
            ->get(route('driver.fuel-lifting', ['search' => 'DLV-OTHER-DONE']))
            ->assertOk()
            ->assertDontSee('Other Dashboard Co.');
    }

    public function test_assigned_lifting_task_filters_are_scoped_to_the_authenticated_driver(): void
    {
        $records = $this->baseRecords();
        $other = $this->baseRecords([
            'driver_name' => 'Other Filter Driver',
            'driver_code' => 'DRV-FILTER-OTHER',
            'truck_code' => 'TRK-FILTER-OTHER',
            'customer_company' => 'Other Filter Co.',
        ]);
        $this->directAllocation($records, ['haul_code' => 'LFT-FILTER-GARAGE', 'destination_type' => 'garage']);
        $this->directAllocation($records, ['haul_code' => 'LFT-FILTER-CLIENT', 'destination_type' => 'customer']);
        $this->directAllocation($other, ['haul_code' => 'LFT-FILTER-OTHER', 'destination_type' => 'garage']);

        $this->actingAs($records['driver'])
            ->get(route('driver.fuel-lifting', [
                'task_status' => 'scheduled',
                'source_type' => 'depot',
                'destination_type' => 'garage',
                'fuel_type_id' => $records['fuelTypeId'],
                'date_from' => '2026-09-01',
                'date_to' => '2026-09-01',
                'driver_user_id' => $other['driver']->id,
            ]))
            ->assertOk()
            ->assertSee('LFT-FILTER-GARAGE')
            ->assertSee('Dashboard Garage')
            ->assertDontSee('LFT-FILTER-CLIENT')
            ->assertDontSee('LFT-FILTER-OTHER')
            ->assertDontSee('Other Filter Co.');

        $this->actingAs($records['driver'])
            ->get(route('driver.fuel-lifting', ['task_status' => 'bogus']))
            ->assertSessionHasErrors('task_status');
    }

    public function test_driver_dashboard_empty_state_authorization_and_read_only_contract(): void
    {
        $records = $this->baseRecords();
        $before = $this->sideEffectCounts($records);

        $this->actingAs($records['driver'])
            ->get(route('driver.fuel-lifting'))
            ->assertOk()
            ->assertSee('No Assignment')
            ->assertSee('No Schedules')
            ->assertDontSee('DLV-');

        $this->assertSame($before, $this->sideEffectCounts($records));

        foreach (['admin', 'inventory_officer', 'sales_officer', 'dispatch_officer'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);
            $this->actingAs($user)
                ->get(route('driver.fuel-lifting'))
                ->assertForbidden();
        }
    }

    public function test_driver_can_progress_owned_lifting_status_without_inventory_or_financial_side_effects(): void
    {
        $records = $this->baseRecords();
        $garageHaul = $this->directAllocation($records, ['haul_code' => 'LFT-DRIVER-GARAGE', 'destination_type' => 'garage']);
        $clientHaul = $this->directAllocation($records, ['haul_code' => 'LFT-DRIVER-CLIENT', 'destination_type' => 'customer']);
        $before = $this->sideEffectCounts($records);
        $beforeHauledQuantity = DB::table('purchase_items')->where('id', $garageHaul['purchaseItemId'])->value('quantity_hauled_liters');

        $this->actingAs($records['driver'])
            ->get(route('driver.fuel-lifting'))
            ->assertOk()
            ->assertSee('LFT-DRIVER-GARAGE')
            ->assertSee('LFT-DRIVER-CLIENT')
            ->assertSee('Dashboard Garage')
            ->assertSee('Dashboard Customer Co.')
            ->assertSee('name="lifting_status"', false)
            ->assertSee(route('driver.fuel-lifting.hauls.status', $garageHaul['haulId']), false);

        $this->actingAs($records['driver'])
            ->patch(route('driver.fuel-lifting.hauls.status', $garageHaul['haulId']), $this->liftingStatusPayload('in_transit'))
            ->assertRedirect(route('driver.fuel-lifting'));

        $this->assertDatabaseHas('hauls', [
            'id' => $garageHaul['haulId'],
            'status' => 'in_transit',
            'hauled_at' => null,
        ]);

        $this->actingAs($records['driver'])
            ->patch(route('driver.fuel-lifting.hauls.status', $garageHaul['haulId']), $this->liftingStatusPayload('lifted'))
            ->assertRedirect(route('driver.fuel-lifting'));

        $this->actingAs($records['driver'])
            ->patch(route('driver.fuel-lifting.hauls.status', $clientHaul['haulId']), $this->liftingStatusPayload('in_transit'))
            ->assertRedirect(route('driver.fuel-lifting'));

        $this->assertDatabaseHas('hauls', ['id' => $garageHaul['haulId'], 'status' => 'lifted']);
        $this->assertDatabaseHas('hauls', ['id' => $clientHaul['haulId'], 'status' => 'in_transit']);
        $this->assertSame($beforeHauledQuantity, DB::table('purchase_items')->where('id', $garageHaul['purchaseItemId'])->value('quantity_hauled_liters'));
        $this->assertSame($before, $this->sideEffectCounts($records));
    }

    public function test_driver_lifting_status_blocks_skipped_transitions_duplicates_and_manipulated_ids(): void
    {
        $records = $this->baseRecords();
        $other = $this->baseRecords([
            'driver_name' => 'Other Status Driver',
            'driver_code' => 'DRV-STATUS-OTHER',
            'truck_code' => 'TRK-STATUS-OTHER',
            'customer_company' => 'Other Status Co.',
        ]);
        $haul = $this->directAllocation($records, ['haul_code' => 'LFT-DRIVER-STATUS']);
        $otherHaul = $this->directAllocation($other, ['haul_code' => 'LFT-DRIVER-OTHER']);
        $completedHaul = $this->directAllocation($records, ['haul_code' => 'LFT-DRIVER-DONE', 'haul_status' => 'completed']);

        $this->actingAs($records['driver'])
            ->from(route('driver.fuel-lifting'))
            ->patch(route('driver.fuel-lifting.hauls.status', $haul['haulId']), $this->liftingStatusPayload('lifted'))
            ->assertRedirect(route('driver.fuel-lifting'))
            ->assertSessionHasErrors('lifting');

        $payload = $this->liftingStatusPayload('in_transit');
        $this->actingAs($records['driver'])
            ->patch(route('driver.fuel-lifting.hauls.status', $haul['haulId']), $payload)
            ->assertRedirect(route('driver.fuel-lifting'));
        $this->actingAs($records['driver'])
            ->patch(route('driver.fuel-lifting.hauls.status', $haul['haulId']), $payload)
            ->assertRedirect(route('driver.fuel-lifting'));

        $this->assertDatabaseHas('hauls', ['id' => $haul['haulId'], 'status' => 'in_transit']);

        $this->actingAs($records['driver'])
            ->from(route('driver.fuel-lifting'))
            ->patch(route('driver.fuel-lifting.hauls.status', $haul['haulId']), $this->liftingStatusPayload('in_transit'))
            ->assertRedirect(route('driver.fuel-lifting'))
            ->assertSessionHasErrors('lifting');

        foreach ([$otherHaul['haulId'], $completedHaul['haulId'], 999999] as $haulId) {
            $this->actingAs($records['driver'])
                ->from(route('driver.fuel-lifting'))
                ->patch(route('driver.fuel-lifting.hauls.status', $haulId), $this->liftingStatusPayload('in_transit'))
                ->assertRedirect(route('driver.fuel-lifting'))
                ->assertSessionHasErrors('lifting');
        }

        $this->assertDatabaseHas('hauls', ['id' => $otherHaul['haulId'], 'status' => 'scheduled']);
        $this->assertDatabaseHas('hauls', ['id' => $completedHaul['haulId'], 'status' => 'completed']);
    }

    public function test_driver_lifting_status_requires_valid_driver_truck_quantity_and_allocations(): void
    {
        $records = $this->baseRecords();
        $badTruckHaul = $this->directAllocation($records, ['haul_code' => 'LFT-DRV-BAD-TRUCK']);
        DB::table('trucks')->where('id', $records['haulingTruckId'])->update(['status' => 'maintenance']);

        $this->actingAs($records['driver'])
            ->from(route('driver.fuel-lifting'))
            ->patch(route('driver.fuel-lifting.hauls.status', $badTruckHaul['haulId']), $this->liftingStatusPayload('in_transit'))
            ->assertRedirect(route('driver.fuel-lifting'))
            ->assertSessionHasErrors('lifting');

        $records = $this->baseRecords([
            'driver_code' => 'DRV-BAD-QTY',
            'truck_code' => 'TRK-BAD-QTY',
        ]);
        $badQuantityHaul = $this->directAllocation($records, ['haul_code' => 'LFT-DRV-BAD-QTY', 'quantity_liters' => 60000]);

        $this->actingAs($records['driver'])
            ->from(route('driver.fuel-lifting'))
            ->patch(route('driver.fuel-lifting.hauls.status', $badQuantityHaul['haulId']), $this->liftingStatusPayload('in_transit'))
            ->assertRedirect(route('driver.fuel-lifting'))
            ->assertSessionHasErrors('lifting');

        $records = $this->baseRecords([
            'driver_code' => 'DRV-BAD-ALLOC',
            'truck_code' => 'TRK-BAD-ALLOC',
        ]);
        $badAllocationHaul = $this->directAllocation($records, ['haul_code' => 'LFT-DRV-BAD-ALLOC']);
        DB::table('haul_allocations')->where('id', $badAllocationHaul['allocationId'])->update(['customer_id' => null]);

        $this->actingAs($records['driver'])
            ->from(route('driver.fuel-lifting'))
            ->patch(route('driver.fuel-lifting.hauls.status', $badAllocationHaul['haulId']), $this->liftingStatusPayload('in_transit'))
            ->assertRedirect(route('driver.fuel-lifting'))
            ->assertSessionHasErrors('lifting');
    }

    public function test_only_driver_role_can_update_driver_lifting_status(): void
    {
        $records = $this->baseRecords();
        $haul = $this->directAllocation($records, ['haul_code' => 'LFT-DRIVER-RBAC']);

        foreach (['admin', 'inventory_officer', 'sales_officer', 'dispatch_officer'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);

            $this->actingAs($user)
                ->patch(route('driver.fuel-lifting.hauls.status', $haul['haulId']), $this->liftingStatusPayload('in_transit'))
                ->assertForbidden();
        }

        $this->assertDatabaseHas('hauls', ['id' => $haul['haulId'], 'status' => 'scheduled']);
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
            'delivery_code' => $overrides['delivery_code'] ?? 'DLV-DRV-GARAGE',
            'sale_id' => $sale['saleId'],
            'sale_item_id' => $sale['saleItemId'],
            'customer_id' => $records['customerId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'source_type' => 'garage',
            'storage_location_id' => $records['garageId'],
            'truck_id' => $records['truckId'],
            'driver_user_id' => $records['driver']->id,
            'scheduled_at' => '2026-09-01 09:00:00',
            'delivered_at' => $overrides['delivered_at'] ?? null,
            'scheduled_quantity_liters' => 10000,
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
        $sale = $this->sale($records, ['sale_code' => $overrides['sale_code'] ?? 'SLS-DRV-'.Str::upper(Str::random(6))]);
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
            'haul_code' => $overrides['haul_code'] ?? 'LFT-DRV-DIRECT',
            'purchase_id' => $purchaseId,
            'purchase_item_id' => $purchaseItemId,
            'depot_id' => $records['depotId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'truck_id' => $records['haulingTruckId'],
            'driver_user_id' => $records['driver']->id,
            'scheduled_at' => '2026-09-01 08:00:00',
            'hauled_at' => ($overrides['haul_status'] ?? 'scheduled') === 'completed' ? '2026-09-01 09:00:00' : null,
            'source_location' => 'Dashboard Depot Rack',
            'quantity_liters' => $overrides['quantity_liters'] ?? 10000,
            'status' => $overrides['haul_status'] ?? 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $allocationId = DB::table('haul_allocations')->insertGetId([
            'haul_id' => $haulId,
            'fuel_type_id' => $records['fuelTypeId'],
            'destination_type' => $overrides['destination_type'] ?? 'customer',
            'storage_location_id' => ($overrides['destination_type'] ?? 'customer') === 'garage' ? $records['garageId'] : null,
            'customer_id' => ($overrides['destination_type'] ?? 'customer') === 'customer' ? $records['customerId'] : null,
            'sale_id' => ($overrides['destination_type'] ?? 'customer') === 'customer' ? $sale['saleId'] : null,
            'quantity_liters' => $overrides['allocation_quantity_liters'] ?? 10000,
            'allocated_at' => '2026-09-01 08:30:00',
            'status' => 'planned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('haulId', 'allocationId', 'purchaseItemId');
    }

    /**
     * @param array<string, mixed> $records
     * @param array<string, mixed> $overrides
     */
    private function depotDelivery(array $records, int $allocationId, array $overrides = []): int
    {
        $allocation = DB::table('haul_allocations')->where('id', $allocationId)->first();

        return DB::table('deliveries')->insertGetId([
            'delivery_code' => $overrides['delivery_code'] ?? 'DLV-DRV-DEPOT',
            'sale_id' => $allocation->sale_id,
            'customer_id' => $allocation->customer_id,
            'fuel_type_id' => $allocation->fuel_type_id,
            'source_type' => 'depot',
            'depot_id' => $records['depotId'],
            'haul_allocation_id' => $allocationId,
            'truck_id' => $records['truckId'],
            'driver_user_id' => $records['driver']->id,
            'scheduled_at' => '2026-09-01 10:00:00',
            'scheduled_quantity_liters' => 5000,
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
            'sale_code' => $overrides['sale_code'] ?? 'SLS-DRV-'.Str::upper(Str::random(6)),
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
            'stock_out_code' => $overrides['stock_out_code'] ?? 'STO-DRV-'.Str::upper(Str::random(6)),
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
     */
    private function liftingStatusPayload(string $status, array $overrides = []): array
    {
        return array_merge([
            'idempotency_key' => (string) Str::uuid(),
            'lifting_status' => $status,
        ], $overrides);
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
            'name' => $overrides['driver_name'] ?? 'Dashboard Driver',
            'phone' => '09990003333',
        ]);
        DB::table('driver_profiles')->insert([
            'user_id' => $driver->id,
            'driver_code' => $overrides['driver_code'] ?? 'DRV-DASH',
            'license_number' => 'LIC-DASH',
            'emergency_contact' => '09991112222',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => $overrides['truck_code'] ?? 'TRK-DRV-DLV',
            'plate_number' => 'DRV-'.Str::upper(Str::random(6)),
            'capacity_liters' => 30000,
            'truck_type' => 'delivery',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $haulingTruckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-DRV-HAUL-'.Str::upper(Str::random(4)),
            'plate_number' => 'HDRV-'.Str::upper(Str::random(6)),
            'capacity_liters' => 30000,
            'truck_type' => 'hauling',
            'status' => 'assigned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $depotId = DB::table('depots')->insertGetId([
            'depot_code' => 'DEP-'.Str::upper(Str::random(8)),
            'name' => 'Dashboard Depot',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $garageId = DB::table('storage_locations')->insertGetId([
            'location_code' => 'GAR-'.Str::upper(Str::random(8)),
            'name' => 'Dashboard Garage',
            'type' => 'garage',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $fuelTypeId = DB::table('fuel_types')->insertGetId([
            'code' => 'FUEL-'.Str::upper(Str::random(8)),
            'name' => $overrides['fuel_name'] ?? 'Diesel Dashboard '.Str::upper(Str::random(6)),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-'.Str::upper(Str::random(8)),
            'name' => 'Dashboard Customer',
            'company_name' => $overrides['customer_company'] ?? 'Dashboard Customer Co.',
            'location' => 'Dashboard, Pampanga',
            'payment_status' => 'clear',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('dispatchOfficer', 'inventoryOfficer', 'salesOfficer', 'driver', 'truckId', 'haulingTruckId', 'depotId', 'garageId', 'fuelTypeId', 'customerId');
    }
}
