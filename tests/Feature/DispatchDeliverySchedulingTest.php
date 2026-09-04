<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DispatchDeliverySchedulingTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_officer_schedules_garage_to_client_delivery_from_released_stock_out(): void
    {
        Carbon::setTestNow('2026-08-31 08:00:00');
        $records = $this->baseRecords();
        $sale = $this->sale($records, ['quantity_liters' => 12000]);
        $stockOutId = $this->stockOut($records, $sale, ['quantity_liters' => 12000]);

        $this->actingAs($records['dispatchOfficer'])
            ->post(route('dispatch.fuel-lifting.deliveries.store'), $this->payload($records, [
                'source_type' => 'garage',
                'stock_out_id' => $stockOutId,
                'quantity_liters' => 12000,
            ]))
            ->assertRedirect(route('dispatch.fuel-lifting'));

        $delivery = DB::table('deliveries')->first();
        $this->assertNotNull($delivery);
        $this->assertSame('garage', $delivery->source_type);
        $this->assertSame('scheduled', $delivery->status);
        $this->assertSame($sale['saleId'], (int) $delivery->sale_id);
        $this->assertSame($sale['saleItemId'], (int) $delivery->sale_item_id);
        $this->assertSame($records['driver']->id, (int) $delivery->driver_user_id);
        $this->assertSame($records['truckId'], (int) $delivery->truck_id);
        $this->assertDatabaseHas('stock_outs', ['id' => $stockOutId, 'delivery_id' => $delivery->id]);
        $this->assertSame(1, DB::table('inventory_movements')->count());

        $this->actingAs($records['dispatchOfficer'])
            ->get(route('dispatch.fuel-lifting'))
            ->assertOk()
            ->assertSee('DLV-000001')
            ->assertSee('SLS-DSP')
            ->assertSee('STO-DSP')
            ->assertSee('Scheduled');

        $this->actingAs($records['driver'])
            ->get(route('driver.fuel-lifting'))
            ->assertOk()
            ->assertSee('DLV-000001')
            ->assertSee('SLS-DSP')
            ->assertSee('STO-DSP')
            ->assertSee('Scheduled');

        Carbon::setTestNow();
    }

    public function test_depot_delivery_assignment_uses_remaining_completed_lift_quantity(): void
    {
        Carbon::setTestNow('2026-08-31 08:00:00');
        $records = $this->baseRecords();
        $sale = $this->sale($records, ['quantity_liters' => 20000]);
        $allocationId = $this->directAllocation($records, $sale, ['quantity_liters' => 20000]);

        $this->actingAs($records['dispatchOfficer'])
            ->post(route('dispatch.fuel-lifting.deliveries.store'), $this->payload($records, [
                'source_type' => 'depot',
                'haul_allocation_id' => $allocationId,
                'quantity_liters' => 8000,
            ]))
            ->assertRedirect(route('dispatch.fuel-lifting'));

        $this->actingAs($records['dispatchOfficer'])
            ->post(route('dispatch.fuel-lifting.deliveries.store'), $this->payload($records, [
                'source_type' => 'depot',
                'haul_allocation_id' => $allocationId,
                'scheduled_at' => '2026-09-01 10:00:00',
                'quantity_liters' => 12000,
            ]))
            ->assertRedirect(route('dispatch.fuel-lifting'));

        $this->actingAs($records['dispatchOfficer'])
            ->from(route('dispatch.fuel-lifting'))
            ->post(route('dispatch.fuel-lifting.deliveries.store'), $this->payload($records, [
                'source_type' => 'depot',
                'haul_allocation_id' => $allocationId,
                'scheduled_at' => '2026-09-01 11:00:00',
                'quantity_liters' => 1,
            ]))
            ->assertRedirect(route('dispatch.fuel-lifting'))
            ->assertSessionHasErrors('delivery');

        $this->assertSame(2, DB::table('deliveries')->where('haul_allocation_id', $allocationId)->count());
        $this->assertSame('scheduled', DB::table('deliveries')->where('haul_allocation_id', $allocationId)->value('status'));

        Carbon::setTestNow();
    }

    public function test_depot_delivery_assignment_rejects_non_completed_lift_transaction(): void
    {
        Carbon::setTestNow('2026-08-31 08:00:00');
        $records = $this->baseRecords();
        $sale = $this->sale($records, ['quantity_liters' => 10000]);
        $allocationId = $this->directAllocation($records, $sale, [
            'quantity_liters' => 10000,
            'haul_status' => 'in_transit',
        ]);

        $this->actingAs($records['dispatchOfficer'])
            ->from(route('dispatch.fuel-lifting'))
            ->post(route('dispatch.fuel-lifting.deliveries.store'), $this->payload($records, [
                'source_type' => 'depot',
                'haul_allocation_id' => $allocationId,
                'quantity_liters' => 10000,
            ]))
            ->assertRedirect(route('dispatch.fuel-lifting'))
            ->assertSessionHasErrors('delivery');

        $this->actingAs($records['dispatchOfficer'])
            ->get(route('dispatch.fuel-lifting'))
            ->assertOk()
            ->assertDontSee('Allocation #'.$allocationId);

        $this->assertSame(0, DB::table('deliveries')->count());

        Carbon::setTestNow();
    }

    public function test_dispatch_officer_schedules_partial_depot_to_client_delivery_from_direct_allocation(): void
    {
        Carbon::setTestNow('2026-08-31 08:00:00');
        $records = $this->baseRecords();
        $sale = $this->sale($records, ['quantity_liters' => 20000]);
        $allocationId = $this->directAllocation($records, $sale, ['quantity_liters' => 20000]);

        $this->actingAs($records['dispatchOfficer'])
            ->post(route('dispatch.fuel-lifting.deliveries.store'), $this->payload($records, [
                'source_type' => 'depot',
                'haul_allocation_id' => $allocationId,
                'quantity_liters' => 8000,
            ]))
            ->assertRedirect(route('dispatch.fuel-lifting'));

        $this->assertDatabaseHas('deliveries', [
            'source_type' => 'depot',
            'haul_allocation_id' => $allocationId,
            'sale_item_id' => $sale['saleItemId'],
            'scheduled_quantity_liters' => '8000.00',
            'actual_quantity_liters' => null,
            'status' => 'scheduled',
        ]);
        $this->assertSame(0, DB::table('stock_outs')->count());
        $this->assertSame(0, DB::table('inventory_movements')->count());
        $this->assertDatabaseHas('sale_items', [
            'id' => $sale['saleItemId'],
            'fulfilled_quantity_liters' => '0.00',
        ]);

        $this->actingAs($records['dispatchOfficer'])
            ->get(route('dispatch.fuel-lifting'))
            ->assertOk()
            ->assertSee('Allocation #'.$allocationId)
            ->assertSee('8,000.00');

        Carbon::setTestNow();
    }

    public function test_admin_can_schedule_delivery_through_admin_dispatch_endpoint(): void
    {
        Carbon::setTestNow('2026-08-31 08:00:00');
        $records = $this->baseRecords();
        $sale = $this->sale($records, ['quantity_liters' => 10000]);
        $stockOutId = $this->stockOut($records, $sale, ['quantity_liters' => 10000]);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin)
            ->post(route('admin.fuel-lifting.deliveries.store'), $this->payload($records, [
                'source_type' => 'garage',
                'stock_out_id' => $stockOutId,
                'quantity_liters' => 10000,
            ]))
            ->assertRedirect(route('admin.fuel-lifting'));

        $this->assertDatabaseHas('deliveries', [
            'sale_id' => $sale['saleId'],
            'source_type' => 'garage',
            'status' => 'scheduled',
        ]);

        Carbon::setTestNow();
    }

    public function test_delivery_scheduling_rejects_invalid_excessive_duplicate_and_conflicting_records(): void
    {
        Carbon::setTestNow('2026-08-31 08:00:00');
        $records = $this->baseRecords();
        $sale = $this->sale($records, ['quantity_liters' => 10000]);
        $stockOutId = $this->stockOut($records, $sale, ['quantity_liters' => 10000]);
        $allocationId = $this->directAllocation($records, $sale, ['quantity_liters' => 15000]);

        $this->actingAs($records['dispatchOfficer'])
            ->from(route('dispatch.fuel-lifting'))
            ->post(route('dispatch.fuel-lifting.deliveries.store'), $this->payload($records, [
                'source_type' => 'garage',
                'stock_out_id' => $stockOutId,
                'quantity_liters' => 10001,
            ]))
            ->assertRedirect(route('dispatch.fuel-lifting'))
            ->assertSessionHasErrors('delivery');

        $this->actingAs($records['dispatchOfficer'])
            ->from(route('dispatch.fuel-lifting'))
            ->post(route('dispatch.fuel-lifting.deliveries.store'), $this->payload($records, [
                'source_type' => 'depot',
                'haul_allocation_id' => $allocationId,
                'quantity_liters' => 15001,
            ]))
            ->assertRedirect(route('dispatch.fuel-lifting'))
            ->assertSessionHasErrors('delivery');

        $this->actingAs($records['dispatchOfficer'])
            ->from(route('dispatch.fuel-lifting'))
            ->post(route('dispatch.fuel-lifting.deliveries.store'), $this->payload($records, [
                'driver_user_id' => $records['salesOfficer']->id,
            ]))
            ->assertSessionHasErrors('driver_user_id');

        $payload = $this->payload($records, [
            'source_type' => 'garage',
            'stock_out_id' => $stockOutId,
            'quantity_liters' => 10000,
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $this->actingAs($records['dispatchOfficer'])
            ->post(route('dispatch.fuel-lifting.deliveries.store'), $payload)
            ->assertRedirect(route('dispatch.fuel-lifting'));
        $this->actingAs($records['dispatchOfficer'])
            ->post(route('dispatch.fuel-lifting.deliveries.store'), $payload)
            ->assertRedirect(route('dispatch.fuel-lifting'));

        $this->assertSame(1, DB::table('deliveries')->count());

        $otherSale = $this->sale($records, ['sale_code' => 'SLS-DSP-TWO', 'quantity_liters' => 10000]);
        $otherStockOutId = $this->stockOut($records, $otherSale, ['stock_out_code' => 'STO-DSP-TWO', 'quantity_liters' => 10000]);
        $this->actingAs($records['dispatchOfficer'])
            ->from(route('dispatch.fuel-lifting'))
            ->post(route('dispatch.fuel-lifting.deliveries.store'), $this->payload($records, [
                'source_type' => 'garage',
                'stock_out_id' => $otherStockOutId,
                'quantity_liters' => 10000,
            ]))
            ->assertRedirect(route('dispatch.fuel-lifting'))
            ->assertSessionHasErrors('delivery');

        Carbon::setTestNow();
    }

    public function test_truck_capacity_and_schedule_date_are_validated_without_partial_writes(): void
    {
        Carbon::setTestNow('2026-08-31 08:00:00');
        $records = $this->baseRecords(['truck_capacity_liters' => 5000]);
        $sale = $this->sale($records, ['quantity_liters' => 8000]);
        $stockOutId = $this->stockOut($records, $sale, ['quantity_liters' => 8000]);

        $this->actingAs($records['dispatchOfficer'])
            ->from(route('dispatch.fuel-lifting'))
            ->post(route('dispatch.fuel-lifting.deliveries.store'), $this->payload($records, [
                'source_type' => 'garage',
                'stock_out_id' => $stockOutId,
                'quantity_liters' => 8000,
            ]))
            ->assertRedirect(route('dispatch.fuel-lifting'))
            ->assertSessionHasErrors('delivery');

        $this->actingAs($records['dispatchOfficer'])
            ->from(route('dispatch.fuel-lifting'))
            ->post(route('dispatch.fuel-lifting.deliveries.store'), $this->payload($records, [
                'source_type' => 'garage',
                'stock_out_id' => $stockOutId,
                'scheduled_at' => '2026-08-30 09:00:00',
                'quantity_liters' => 8000,
            ]))
            ->assertRedirect(route('dispatch.fuel-lifting'))
            ->assertSessionHasErrors('delivery');

        $this->assertSame(0, DB::table('deliveries')->count());
        $this->assertDatabaseHas('stock_outs', ['id' => $stockOutId, 'delivery_id' => null]);

        Carbon::setTestNow();
    }

    public function test_unauthorized_roles_cannot_schedule_deliveries_or_view_dispatch_page(): void
    {
        $records = $this->baseRecords();
        $sale = $this->sale($records);
        $stockOutId = $this->stockOut($records, $sale);

        foreach (['sales_officer', 'inventory_officer', 'driver'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);
            $this->actingAs($user)->get(route('dispatch.fuel-lifting'))->assertForbidden();
            $this->actingAs($user)
                ->post(route('dispatch.fuel-lifting.deliveries.store'), $this->payload($records, [
                    'stock_out_id' => $stockOutId,
                ]))
                ->assertForbidden();
            $this->actingAs($user)
                ->post(route('admin.fuel-lifting.deliveries.store'), $this->payload($records, [
                    'stock_out_id' => $stockOutId,
                ]))
                ->assertForbidden();
        }

        $this->assertSame(0, DB::table('deliveries')->count());
    }

    public function test_delivery_scheduling_does_not_modify_sales_payments_receivables_or_inventory(): void
    {
        Carbon::setTestNow('2026-08-31 08:00:00');
        $records = $this->baseRecords();
        $sale = $this->sale($records, ['quantity_liters' => 10000]);
        $stockOutId = $this->stockOut($records, $sale, ['quantity_liters' => 10000]);
        $this->payment($sale['saleId'], $records);
        DB::table('receivables')->insert([
            'sale_id' => $sale['saleId'],
            'due_date' => '2026-09-30',
            'status' => 'partial',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $before = [
            'payments' => DB::table('payments')->count(),
            'receivables' => DB::table('receivables')->count(),
            'inventory_movements' => DB::table('inventory_movements')->count(),
            'sale_fulfilled' => DB::table('sale_items')->where('id', $sale['saleItemId'])->value('fulfilled_quantity_liters'),
        ];

        $this->actingAs($records['dispatchOfficer'])
            ->post(route('dispatch.fuel-lifting.deliveries.store'), $this->payload($records, [
                'source_type' => 'garage',
                'stock_out_id' => $stockOutId,
                'quantity_liters' => 10000,
            ]))
            ->assertRedirect(route('dispatch.fuel-lifting'));

        $this->assertSame($before['payments'], DB::table('payments')->count());
        $this->assertSame($before['receivables'], DB::table('receivables')->count());
        $this->assertSame($before['inventory_movements'], DB::table('inventory_movements')->count());
        $this->assertSame((string) $before['sale_fulfilled'], (string) DB::table('sale_items')->where('id', $sale['saleItemId'])->value('fulfilled_quantity_liters'));

        Carbon::setTestNow();
    }

    /**
     * @param array<string, mixed> $records
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $records, array $overrides = []): array
    {
        return array_filter(array_merge([
            'idempotency_key' => (string) Str::uuid(),
            'source_type' => 'garage',
            'stock_out_id' => null,
            'haul_allocation_id' => null,
            'driver_user_id' => $records['driver']->id,
            'truck_id' => $records['truckId'],
            'scheduled_at' => '2026-09-01 09:00:00',
            'quantity_liters' => 10000,
        ], $overrides), fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $records
     * @param array<string, mixed> $overrides
     * @return array<string, int>
     */
    private function sale(array $records, array $overrides = []): array
    {
        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => $overrides['sale_code'] ?? 'SLS-DSP',
            'customer_id' => $records['customerId'],
            'sale_date' => '2026-08-31',
            'payment_method' => 'cash_on_delivery',
            'payment_terms' => 'cod',
            'status' => $overrides['status'] ?? 'confirmed',
            'created_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $saleItemId = DB::table('sale_items')->insertGetId([
            'sale_id' => $saleId,
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_liters' => $overrides['quantity_liters'] ?? 10000,
            'unit_price' => 60,
            'line_total' => ($overrides['quantity_liters'] ?? 10000) * 60,
            'fulfilled_quantity_liters' => $overrides['fulfilled_quantity_liters'] ?? 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('saleId', 'saleItemId');
    }

    /**
     * @param array<string, mixed> $records
     * @param array<string, mixed> $sale
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
            'quantity_liters' => $overrides['quantity_liters'] ?? 10000,
            'reference_type' => 'stock_out',
            'reference_id' => 1,
            'movement_date' => '2026-08-31 07:00:00',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('stock_outs')->insertGetId([
            'stock_out_code' => $overrides['stock_out_code'] ?? 'STO-DSP',
            'sale_id' => $sale['saleId'],
            'sale_item_id' => $sale['saleItemId'],
            'customer_id' => $records['customerId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'storage_location_id' => $records['garageId'],
            'delivery_id' => $overrides['delivery_id'] ?? null,
            'inventory_movement_id' => null,
            'quantity_liters' => $overrides['quantity_liters'] ?? 10000,
            'stock_out_at' => '2026-08-31 07:00:00',
            'status' => $overrides['status'] ?? 'released',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $records
     * @param array<string, mixed> $sale
     * @param array<string, mixed> $overrides
     */
    private function directAllocation(array $records, array $sale, array $overrides = []): int
    {
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
            'haul_code' => 'LFT-'.Str::upper(Str::random(8)),
            'purchase_id' => $purchaseId,
            'purchase_item_id' => $purchaseItemId,
            'depot_id' => $records['depotId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'truck_id' => $records['haulingTruckId'],
            'driver_user_id' => $records['driver']->id,
            'scheduled_at' => '2026-08-31 06:00:00',
            'hauled_at' => '2026-08-31 07:00:00',
            'quantity_liters' => 20000,
            'status' => $overrides['haul_status'] ?? 'completed',
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
            'allocated_at' => '2026-08-31 07:30:00',
            'status' => $overrides['status'] ?? 'delivered',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function payment(int $saleId, array $records): void
    {
        DB::table('payments')->insert([
            'payment_code' => 'PAY-'.Str::upper(Str::random(8)),
            'sale_id' => $saleId,
            'payment_date' => '2026-08-31',
            'amount' => 1000,
            'method' => 'cash_on_delivery',
            'received_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active', 'phone' => '09990001111']);
        DB::table('driver_profiles')->insert([
            'user_id' => $driver->id,
            'driver_code' => 'DRV-DSP',
            'license_number' => 'LIC-DSP',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-DSP',
            'plate_number' => 'DSP-100',
            'capacity_liters' => $overrides['truck_capacity_liters'] ?? 30000,
            'truck_type' => 'delivery',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $haulingTruckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-HAUL-DSP',
            'plate_number' => 'DSP-200',
            'capacity_liters' => 30000,
            'truck_type' => 'hauling',
            'status' => 'assigned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $depotId = DB::table('depots')->insertGetId([
            'depot_code' => 'DEP-DSP',
            'name' => 'Dispatch Depot',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $garageId = DB::table('storage_locations')->insertGetId([
            'location_code' => 'GAR-DSP',
            'name' => 'Dispatch Garage',
            'type' => 'garage',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $fuelTypeId = DB::table('fuel_types')->insertGetId([
            'code' => 'DSL-DSP',
            'name' => 'Diesel Dispatch',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-DSP',
            'name' => 'Dispatch Customer',
            'company_name' => 'Dispatch Customer Co.',
            'location' => 'Lara, Pampanga',
            'payment_status' => 'clear',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('dispatchOfficer', 'inventoryOfficer', 'salesOfficer', 'driver', 'truckId', 'haulingTruckId', 'depotId', 'garageId', 'fuelTypeId', 'customerId');
    }
}
