<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DispatchDriverAssignmentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_officer_assigns_driver_to_assigned_garage_delivery_without_side_effects(): void
    {
        $records = $this->baseRecords();
        $deliveryId = $this->garageDelivery($records, ['driver_user_id' => null]);
        $before = $this->sideEffectCounts($records);

        $this->actingAs($records['dispatchOfficer'])
            ->patch(route('dispatch.fuel-lifting.deliveries.assignment', $deliveryId), $this->assignmentPayload($records))
            ->assertRedirect(route('dispatch.fuel-lifting'));

        $this->assertDatabaseHas('deliveries', [
            'id' => $deliveryId,
            'driver_user_id' => $records['driver']->id,
            'truck_id' => $records['truckId'],
            'status' => 'scheduled',
        ]);
        $this->assertSame($before, $this->sideEffectCounts($records));
    }

    public function test_assignment_works_for_direct_depot_delivery(): void
    {
        $records = $this->baseRecords();
        $allocationId = $this->directAllocation($records);
        $deliveryId = $this->depotDelivery($records, $allocationId, ['driver_user_id' => null]);

        $this->actingAs($records['dispatchOfficer'])
            ->patch(route('dispatch.fuel-lifting.deliveries.assignment', $deliveryId), $this->assignmentPayload($records))
            ->assertRedirect(route('dispatch.fuel-lifting'));

        $this->assertDatabaseHas('deliveries', [
            'id' => $deliveryId,
            'source_type' => 'depot',
            'driver_user_id' => $records['driver']->id,
            'truck_id' => $records['truckId'],
        ]);
    }

    public function test_assignment_rejects_invalid_non_driver_inactive_driver_conflicts_capacity_and_tampered_truck(): void
    {
        $records = $this->baseRecords();
        $deliveryId = $this->garageDelivery($records, ['driver_user_id' => null]);
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active']);
        $inactiveDriver = User::factory()->create(['role' => 'driver', 'status' => 'inactive']);
        $smallTruckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-SMALL',
            'capacity_liters' => 1000,
            'truck_type' => 'delivery',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([$salesOfficer->id, $inactiveDriver->id, 999999] as $driverId) {
            $this->actingAs($records['dispatchOfficer'])
                ->from(route('dispatch.fuel-lifting'))
                ->patch(route('dispatch.fuel-lifting.deliveries.assignment', $deliveryId), $this->assignmentPayload($records, ['driver_user_id' => $driverId]))
                ->assertRedirect(route('dispatch.fuel-lifting'))
                ->assertSessionHasErrors('delivery');
        }

        $this->actingAs($records['dispatchOfficer'])
            ->from(route('dispatch.fuel-lifting'))
            ->patch(route('dispatch.fuel-lifting.deliveries.assignment', $deliveryId), $this->assignmentPayload($records, ['truck_id' => $smallTruckId]))
            ->assertRedirect(route('dispatch.fuel-lifting'))
            ->assertSessionHasErrors('delivery');

        DB::table('deliveries')->where('id', $deliveryId)->update(['truck_id' => $smallTruckId]);

        $this->actingAs($records['dispatchOfficer'])
            ->from(route('dispatch.fuel-lifting'))
            ->patch(route('dispatch.fuel-lifting.deliveries.assignment', $deliveryId), $this->assignmentPayload($records, ['truck_id' => $smallTruckId]))
            ->assertRedirect(route('dispatch.fuel-lifting'))
            ->assertSessionHasErrors('delivery');

        DB::table('deliveries')->where('id', $deliveryId)->update(['truck_id' => $records['truckId']]);

        $this->garageDelivery($records, ['delivery_code' => 'DLV-CONFLICT', 'stock_out_code' => 'STO-CONFLICT', 'sale_code' => 'SLS-CONFLICT']);

        $this->actingAs($records['dispatchOfficer'])
            ->from(route('dispatch.fuel-lifting'))
            ->patch(route('dispatch.fuel-lifting.deliveries.assignment', $deliveryId), $this->assignmentPayload($records))
            ->assertRedirect(route('dispatch.fuel-lifting'))
            ->assertSessionHasErrors('delivery');
    }

    public function test_assignment_rejects_active_lift_conflicts_for_driver_and_truck(): void
    {
        $records = $this->baseRecords();
        $deliveryId = $this->garageDelivery($records, ['driver_user_id' => null]);
        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => 'PUR-HAUL-CONFLICT',
            'depot_id' => $records['depotId'],
            'purchase_date' => '2026-08-31',
            'payment_status' => 'paid',
            'status' => 'ordered',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $purchaseItemId = DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchaseId,
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_ordered_liters' => 10000,
            'unit_cost' => 50,
            'line_total' => 500000,
            'quantity_hauled_liters' => 0,
            'status' => 'unlifted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('hauls')->insert([
            'haul_code' => 'LFT-HAUL-CONFLICT',
            'purchase_id' => $purchaseId,
            'purchase_item_id' => $purchaseItemId,
            'depot_id' => $records['depotId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'truck_id' => $records['truckId'],
            'driver_user_id' => $records['driver']->id,
            'scheduled_at' => '2026-09-01 09:00:00',
            'quantity_liters' => 10000,
            'status' => 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($records['dispatchOfficer'])
            ->from(route('dispatch.fuel-lifting'))
            ->patch(route('dispatch.fuel-lifting.deliveries.assignment', $deliveryId), $this->assignmentPayload($records))
            ->assertRedirect(route('dispatch.fuel-lifting'))
            ->assertSessionHasErrors('delivery');
    }

    public function test_reassignment_is_allowed_before_dispatch_and_blocked_after_completion(): void
    {
        $records = $this->baseRecords();
        $deliveryId = $this->garageDelivery($records);
        $newDriver = User::factory()->create(['role' => 'driver', 'status' => 'active', 'name' => 'Reassigned Driver']);
        DB::table('driver_profiles')->insert([
            'user_id' => $newDriver->id,
            'driver_code' => 'DRV-REASSIGN',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($records['dispatchOfficer'])
            ->patch(route('dispatch.fuel-lifting.deliveries.assignment', $deliveryId), $this->assignmentPayload($records, ['driver_user_id' => $newDriver->id]))
            ->assertRedirect(route('dispatch.fuel-lifting'));

        $this->assertDatabaseHas('deliveries', ['id' => $deliveryId, 'driver_user_id' => $newDriver->id]);

        DB::table('deliveries')->where('id', $deliveryId)->update(['status' => 'delivered', 'delivered_at' => now()]);

        $this->actingAs($records['dispatchOfficer'])
            ->from(route('dispatch.fuel-lifting'))
            ->patch(route('dispatch.fuel-lifting.deliveries.assignment', $deliveryId), $this->assignmentPayload($records))
            ->assertRedirect(route('dispatch.fuel-lifting'))
            ->assertSessionHasErrors('delivery');

        $this->assertDatabaseHas('deliveries', ['id' => $deliveryId, 'driver_user_id' => $newDriver->id]);
    }

    public function test_dispatch_requires_valid_assignment_and_duplicate_dispatch_is_blocked(): void
    {
        $records = $this->baseRecords();
        $deliveryId = $this->garageDelivery($records, ['driver_user_id' => null]);

        $this->actingAs($records['dispatchOfficer'])
            ->from(route('dispatch.fuel-lifting'))
            ->patch(route('dispatch.fuel-lifting.deliveries.status', $deliveryId), $this->statusPayload('in_transit'))
            ->assertRedirect(route('dispatch.fuel-lifting'))
            ->assertSessionHasErrors('delivery');

        $this->actingAs($records['dispatchOfficer'])
            ->patch(route('dispatch.fuel-lifting.deliveries.assignment', $deliveryId), $this->assignmentPayload($records))
            ->assertRedirect(route('dispatch.fuel-lifting'));

        $this->actingAs($records['dispatchOfficer'])
            ->patch(route('dispatch.fuel-lifting.deliveries.status', $deliveryId), $this->statusPayload('in_transit'))
            ->assertRedirect(route('dispatch.fuel-lifting'));

        $this->actingAs($records['dispatchOfficer'])
            ->from(route('dispatch.fuel-lifting'))
            ->patch(route('dispatch.fuel-lifting.deliveries.status', $deliveryId), $this->statusPayload('in_transit'))
            ->assertRedirect(route('dispatch.fuel-lifting'))
            ->assertSessionHasErrors('delivery');
    }

    public function test_authorization_and_driver_visibility_remain_scoped(): void
    {
        $records = $this->baseRecords();
        $deliveryId = $this->garageDelivery($records, ['driver_user_id' => null]);

        foreach (['sales_officer', 'inventory_officer', 'driver'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);
            $this->actingAs($user)
                ->patch(route('dispatch.fuel-lifting.deliveries.assignment', $deliveryId), $this->assignmentPayload($records))
                ->assertForbidden();
        }

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)
            ->patch(route('admin.fuel-lifting.deliveries.assignment', $deliveryId), $this->assignmentPayload($records))
            ->assertRedirect(route('admin.fuel-lifting'));

        $otherDriver = User::factory()->create(['role' => 'driver', 'status' => 'active', 'name' => 'Other Assignment Driver']);
        $this->actingAs($records['driver'])
            ->get(route('driver.fuel-lifting'))
            ->assertOk()
            ->assertSee('DLV-ASSIGN')
            ->assertDontSee('deliveries.assignment');

        $this->actingAs($otherDriver)
            ->get(route('driver.fuel-lifting'))
            ->assertOk()
            ->assertDontSee('DLV-ASSIGN');
    }

    /**
     * @param array<string, mixed> $records
     * @param array<string, mixed> $overrides
     */
    private function assignmentPayload(array $records, array $overrides = []): array
    {
        return array_merge([
            'idempotency_key' => (string) Str::uuid(),
            'driver_user_id' => $records['driver']->id,
            'truck_id' => $records['truckId'],
        ], $overrides);
    }

    private function statusPayload(string $status): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'status' => $status,
        ];
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
            'delivery_code' => $overrides['delivery_code'] ?? 'DLV-ASSIGN',
            'sale_id' => $sale['saleId'],
            'sale_item_id' => $sale['saleItemId'],
            'customer_id' => $records['customerId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'source_type' => 'garage',
            'storage_location_id' => $records['garageId'],
            'truck_id' => array_key_exists('truck_id', $overrides) ? $overrides['truck_id'] : $records['truckId'],
            'driver_user_id' => array_key_exists('driver_user_id', $overrides) ? $overrides['driver_user_id'] : $records['driver']->id,
            'scheduled_at' => '2026-09-01 09:00:00',
            'scheduled_quantity_liters' => 10000,
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
    private function sale(array $records, array $overrides = []): array
    {
        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => $overrides['sale_code'] ?? 'SLS-ASSIGN',
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
            'stock_out_code' => $overrides['stock_out_code'] ?? 'STO-ASSIGN',
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
     */
    private function directAllocation(array $records): int
    {
        $sale = $this->sale($records, ['sale_code' => 'SLS-DEPOT-ASSIGN']);
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
            'quantity_ordered_liters' => 10000,
            'unit_cost' => 50,
            'line_total' => 500000,
            'quantity_hauled_liters' => 10000,
            'status' => 'lifted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $haulId = DB::table('hauls')->insertGetId([
            'haul_code' => 'LFT-'.Str::upper(Str::random(8)),
            'purchase_id' => $purchaseId,
            'purchase_item_id' => $purchaseItemId,
            'depot_id' => $records['depotId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'truck_id' => $records['truckId'],
            'driver_user_id' => $records['driver']->id,
            'scheduled_at' => '2026-08-31 07:00:00',
            'quantity_liters' => 10000,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('haul_allocations')->insertGetId([
            'haul_id' => $haulId,
            'fuel_type_id' => $records['fuelTypeId'],
            'destination_type' => 'customer',
            'customer_id' => $records['customerId'],
            'sale_id' => $sale['saleId'],
            'quantity_liters' => 10000,
            'allocated_at' => '2026-08-31 08:00:00',
            'status' => 'planned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $records
     * @param array<string, mixed> $overrides
     */
    private function depotDelivery(array $records, int $allocationId, array $overrides = []): int
    {
        $allocation = DB::table('haul_allocations')->where('id', $allocationId)->first();

        return DB::table('deliveries')->insertGetId([
            'delivery_code' => $overrides['delivery_code'] ?? 'DLV-DEPOT-ASSIGN',
            'sale_id' => $allocation->sale_id,
            'customer_id' => $allocation->customer_id,
            'fuel_type_id' => $allocation->fuel_type_id,
            'source_type' => 'depot',
            'depot_id' => $records['depotId'],
            'haul_allocation_id' => $allocationId,
            'truck_id' => array_key_exists('truck_id', $overrides) ? $overrides['truck_id'] : $records['truckId'],
            'driver_user_id' => array_key_exists('driver_user_id', $overrides) ? $overrides['driver_user_id'] : $records['driver']->id,
            'scheduled_at' => '2026-09-01 09:00:00',
            'scheduled_quantity_liters' => 10000,
            'status' => 'scheduled',
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
            'garage_balance' => (int) DB::table('inventory_movements')
                ->where('storage_location_id', $records['garageId'])
                ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN quantity_liters ELSE -quantity_liters END), 0) as balance")
                ->value('balance'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function baseRecords(): array
    {
        $dispatchOfficer = User::factory()->create(['role' => 'dispatch_officer', 'status' => 'active']);
        $inventoryOfficer = User::factory()->create(['role' => 'inventory_officer', 'status' => 'active']);
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active']);
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active', 'phone' => '09998887777']);
        DB::table('driver_profiles')->insert([
            'user_id' => $driver->id,
            'driver_code' => 'DRV-ASSIGN',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-ASSIGN',
            'capacity_liters' => 30000,
            'truck_type' => 'delivery',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $depotId = DB::table('depots')->insertGetId([
            'depot_code' => 'DEP-ASSIGN',
            'name' => 'Assignment Depot',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $garageId = DB::table('storage_locations')->insertGetId([
            'location_code' => 'GAR-ASSIGN',
            'name' => 'Assignment Garage',
            'type' => 'garage',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $fuelTypeId = DB::table('fuel_types')->insertGetId([
            'code' => 'DSL-ASSIGN',
            'name' => 'Diesel Assignment',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-ASSIGN',
            'name' => 'Dispatch Assignment Customer',
            'company_name' => 'Dispatch Assignment Customer Co.',
            'location' => 'Lara, Pampanga',
            'payment_status' => 'clear',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('dispatchOfficer', 'inventoryOfficer', 'salesOfficer', 'driver', 'truckId', 'depotId', 'garageId', 'fuelTypeId', 'customerId');
    }
}
