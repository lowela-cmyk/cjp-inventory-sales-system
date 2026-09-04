<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DispatchDeliveryStatusManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_delivery_list_and_detail_use_real_scheduled_delivery_data(): void
    {
        $records = $this->baseRecords();
        $deliveryId = $this->garageDelivery($records);

        $this->actingAs($records['dispatchOfficer'])
            ->get(route('dispatch.fuel-lifting'))
            ->assertOk()
            ->assertSee('DLV-STAT')
            ->assertSee('SLS-STAT')
            ->assertSee('STO-STAT')
            ->assertSee('Dispatch Status Customer Co.')
            ->assertSee($records['driver']->name)
            ->assertSee('TRK-STAT')
            ->assertSee('Scheduled')
            ->assertSee('Fuel Type')
            ->assertSee('Diesel Status');

        $this->assertSame('scheduled', DB::table('deliveries')->where('id', $deliveryId)->value('status'));
    }

    public function test_dispatch_officer_can_move_delivery_from_scheduled_to_in_transit_to_delivered(): void
    {
        Carbon::setTestNow('2026-08-31 10:00:00');
        $records = $this->baseRecords();
        $deliveryId = $this->garageDelivery($records);
        $before = $this->sideEffectCounts($records);

        $this->actingAs($records['dispatchOfficer'])
            ->patch(route('dispatch.fuel-lifting.deliveries.status', $deliveryId), $this->statusPayload('in_transit'))
            ->assertRedirect(route('dispatch.fuel-lifting'));

        $this->assertDatabaseHas('deliveries', ['id' => $deliveryId, 'status' => 'in_transit', 'delivered_at' => null]);

        $this->actingAs($records['dispatchOfficer'])
            ->patch(route('dispatch.fuel-lifting.deliveries.status', $deliveryId), $this->statusPayload('delivered'))
            ->assertRedirect(route('dispatch.fuel-lifting'));

        $delivery = DB::table('deliveries')->where('id', $deliveryId)->first();
        $this->assertSame('delivered', $delivery->status);
        $this->assertSame('2026-08-31 10:00:00', $delivery->delivered_at);
        $this->assertSame(10000.0, round((float) $delivery->actual_quantity_liters, 2));
        $this->assertSame($before, $this->sideEffectCounts($records));

        $this->actingAs($records['dispatchOfficer'])
            ->get(route('dispatch.fuel-lifting.hauled'))
            ->assertOk()
            ->assertSee('DLV-STAT')
            ->assertSee('Delivered');

        Carbon::setTestNow();
    }

    public function test_invalid_status_transitions_and_repeated_completion_are_blocked(): void
    {
        Carbon::setTestNow('2026-08-31 10:00:00');
        $records = $this->baseRecords();
        $deliveryId = $this->garageDelivery($records);

        $this->actingAs($records['dispatchOfficer'])
            ->from(route('dispatch.fuel-lifting'))
            ->patch(route('dispatch.fuel-lifting.deliveries.status', $deliveryId), $this->statusPayload('delivered'))
            ->assertRedirect(route('dispatch.fuel-lifting'))
            ->assertSessionHasErrors('delivery');

        $this->assertDatabaseHas('deliveries', ['id' => $deliveryId, 'status' => 'scheduled', 'delivered_at' => null]);

        $this->actingAs($records['dispatchOfficer'])
            ->patch(route('dispatch.fuel-lifting.deliveries.status', $deliveryId), $this->statusPayload('in_transit'))
            ->assertRedirect(route('dispatch.fuel-lifting'));

        $payload = $this->statusPayload('delivered', ['idempotency_key' => (string) Str::uuid()]);
        $this->actingAs($records['dispatchOfficer'])
            ->patch(route('dispatch.fuel-lifting.deliveries.status', $deliveryId), $payload)
            ->assertRedirect(route('dispatch.fuel-lifting'));
        $this->actingAs($records['dispatchOfficer'])
            ->patch(route('dispatch.fuel-lifting.deliveries.status', $deliveryId), $payload)
            ->assertRedirect(route('dispatch.fuel-lifting'));

        $deliveredAt = DB::table('deliveries')->where('id', $deliveryId)->value('delivered_at');

        $this->actingAs($records['dispatchOfficer'])
            ->from(route('dispatch.fuel-lifting'))
            ->patch(route('dispatch.fuel-lifting.deliveries.status', $deliveryId), $this->statusPayload('in_transit'))
            ->assertRedirect(route('dispatch.fuel-lifting'))
            ->assertSessionHasErrors('delivery');

        $this->assertSame($deliveredAt, DB::table('deliveries')->where('id', $deliveryId)->value('delivered_at'));

        Carbon::setTestNow();
    }

    public function test_direct_depot_delivery_status_completion_does_not_touch_garage_inventory(): void
    {
        Carbon::setTestNow('2026-08-31 11:00:00');
        $records = $this->baseRecords();
        $allocationId = $this->directAllocation($records, ['quantity_liters' => 10000]);
        $saleItemId = (int) DB::table('sale_items')
            ->where('sale_id', DB::table('haul_allocations')->where('id', $allocationId)->value('sale_id'))
            ->value('id');
        DB::table('sale_items')->where('id', $saleItemId)->update(['fulfilled_quantity_liters' => 0]);
        $deliveryId = $this->depotDelivery($records, $allocationId, ['scheduled_quantity_liters' => 10000]);
        $before = $this->sideEffectCounts($records);

        $this->actingAs($records['dispatchOfficer'])
            ->patch(route('dispatch.fuel-lifting.deliveries.status', $deliveryId), $this->statusPayload('in_transit'))
            ->assertRedirect(route('dispatch.fuel-lifting'));
        $this->actingAs($records['dispatchOfficer'])
            ->patch(route('dispatch.fuel-lifting.deliveries.status', $deliveryId), $this->statusPayload('delivered'))
            ->assertRedirect(route('dispatch.fuel-lifting'));

        $this->assertDatabaseHas('deliveries', [
            'id' => $deliveryId,
            'source_type' => 'depot',
            'status' => 'delivered',
            'actual_quantity_liters' => '10000.00',
            'sale_item_id' => $saleItemId,
        ]);
        $this->assertDatabaseHas('haul_allocations', ['id' => $allocationId, 'status' => 'delivered']);
        $this->assertSame($before, $this->sideEffectCounts($records));
        $this->assertDatabaseHas('sale_items', [
            'id' => $saleItemId,
            'fulfilled_quantity_liters' => '10000.00',
        ]);

        Carbon::setTestNow();
    }

    public function test_admin_can_update_status_but_other_roles_and_invalid_ids_are_blocked(): void
    {
        $records = $this->baseRecords();
        $deliveryId = $this->garageDelivery($records);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin)
            ->patch(route('admin.fuel-lifting.deliveries.status', $deliveryId), $this->statusPayload('in_transit'))
            ->assertRedirect(route('admin.fuel-lifting'));

        $this->assertDatabaseHas('deliveries', ['id' => $deliveryId, 'status' => 'in_transit']);

        $this->actingAs($records['dispatchOfficer'])
            ->from(route('dispatch.fuel-lifting'))
            ->patch(route('dispatch.fuel-lifting.deliveries.status', 999999), $this->statusPayload('in_transit'))
            ->assertRedirect(route('dispatch.fuel-lifting'))
            ->assertSessionHasErrors('delivery');

        foreach (['sales_officer', 'inventory_officer', 'driver'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);
            $this->actingAs($user)
                ->patch(route('dispatch.fuel-lifting.deliveries.status', $deliveryId), $this->statusPayload('delivered'))
                ->assertForbidden();
            $this->actingAs($user)
                ->patch(route('admin.fuel-lifting.deliveries.status', $deliveryId), $this->statusPayload('delivered'))
                ->assertForbidden();
        }
    }

    public function test_driver_sees_only_assigned_delivery_without_dispatch_status_controls(): void
    {
        $records = $this->baseRecords();
        $this->garageDelivery($records);
        $otherDriver = User::factory()->create(['role' => 'driver', 'status' => 'active', 'name' => 'Other Driver']);
        $this->garageDelivery($records, ['delivery_code' => 'DLV-OTHER', 'stock_out_code' => 'STO-OTHER', 'sale_code' => 'SLS-OTHER', 'driver_user_id' => $otherDriver->id]);

        $this->actingAs($records['driver'])
            ->get(route('driver.fuel-lifting'))
            ->assertOk()
            ->assertSee('DLV-STAT')
            ->assertDontSee('DLV-OTHER')
            ->assertDontSee('name="status"', false)
            ->assertDontSee('dispatch.fuel-lifting.deliveries.status');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function statusPayload(string $status, array $overrides = []): array
    {
        return array_merge([
            'idempotency_key' => (string) Str::uuid(),
            'status' => $status,
        ], $overrides);
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
            'delivery_code' => $overrides['delivery_code'] ?? 'DLV-STAT',
            'sale_id' => $sale['saleId'],
            'sale_item_id' => $sale['saleItemId'],
            'customer_id' => $records['customerId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'source_type' => 'garage',
            'storage_location_id' => $records['garageId'],
            'truck_id' => $records['truckId'],
            'driver_user_id' => $overrides['driver_user_id'] ?? $records['driver']->id,
            'scheduled_at' => '2026-09-01 09:00:00',
            'scheduled_quantity_liters' => $overrides['scheduled_quantity_liters'] ?? 10000,
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
            'sale_code' => $overrides['sale_code'] ?? 'SLS-STAT',
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
            'stock_out_code' => $overrides['stock_out_code'] ?? 'STO-STAT',
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
     * @param array<string, mixed> $overrides
     */
    private function directAllocation(array $records, array $overrides = []): int
    {
        $sale = $this->sale($records, ['sale_code' => 'SLS-DEPOT-STAT']);
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
            'quantity_liters' => $overrides['quantity_liters'] ?? 10000,
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
            'delivery_code' => $overrides['delivery_code'] ?? 'DLV-DEPOT-STAT',
            'sale_id' => $allocation->sale_id,
            'customer_id' => $allocation->customer_id,
            'fuel_type_id' => $allocation->fuel_type_id,
            'source_type' => 'depot',
            'depot_id' => $records['depotId'],
            'haul_allocation_id' => $allocationId,
            'truck_id' => $records['truckId'],
            'driver_user_id' => $records['driver']->id,
            'scheduled_at' => '2026-09-01 09:00:00',
            'scheduled_quantity_liters' => $overrides['scheduled_quantity_liters'] ?? 10000,
            'status' => $overrides['status'] ?? 'scheduled',
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
            'driver_code' => 'DRV-STAT',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-STAT',
            'capacity_liters' => 30000,
            'truck_type' => 'delivery',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $depotId = DB::table('depots')->insertGetId([
            'depot_code' => 'DEP-STAT',
            'name' => 'Status Depot',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $garageId = DB::table('storage_locations')->insertGetId([
            'location_code' => 'GAR-STAT',
            'name' => 'Status Garage',
            'type' => 'garage',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $fuelTypeId = DB::table('fuel_types')->insertGetId([
            'code' => 'DSL-STAT',
            'name' => 'Diesel Status',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-STAT',
            'name' => 'Dispatch Status Customer',
            'company_name' => 'Dispatch Status Customer Co.',
            'location' => 'Lara, Pampanga',
            'payment_status' => 'clear',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('dispatchOfficer', 'inventoryOfficer', 'salesOfficer', 'driver', 'truckId', 'depotId', 'garageId', 'fuelTypeId', 'customerId');
    }
}
