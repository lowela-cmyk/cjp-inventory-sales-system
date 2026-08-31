<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class HaulTruckAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_officer_can_reassign_scheduled_depot_to_garage_lift_truck_without_side_effects(): void
    {
        $records = $this->baseRecords();
        $haulId = $this->haul($records, ['destination_type' => 'garage']);
        $replacementTruckId = $this->truck(['truck_code' => 'TRK-HAUL-NEW', 'status' => 'available']);
        $before = $this->sideEffectCounts();

        $this->actingAs($records['dispatchOfficer'])
            ->patch(route('dispatch.fuel-lifting.hauls.truck', $haulId), $this->payload($replacementTruckId))
            ->assertRedirect(route('dispatch.fuel-lifting'));

        $this->assertDatabaseHas('hauls', [
            'id' => $haulId,
            'truck_id' => $replacementTruckId,
            'status' => 'scheduled',
        ]);
        $this->assertSame($before, $this->sideEffectCounts());
    }

    public function test_admin_view_lists_available_haul_trucks_and_can_reassign_direct_client_lift(): void
    {
        $records = $this->baseRecords();
        $haulId = $this->haul($records, ['destination_type' => 'customer']);
        $replacementTruckId = $this->truck([
            'truck_code' => 'TRK-HAUL-CLIENT',
            'plate_number' => 'HCL-100',
            'status' => 'available',
        ]);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin)
            ->get(route('admin.fuel-lifting'))
            ->assertOk()
            ->assertSee('LFT-HAUL')
            ->assertSee('TRK-HAUL-CLIENT')
            ->assertSee('HCL-100')
            ->assertSee(route('admin.fuel-lifting.hauls.truck', $haulId), false);

        $this->actingAs($admin)
            ->patch(route('admin.fuel-lifting.hauls.truck', $haulId), $this->payload($replacementTruckId))
            ->assertRedirect(route('admin.fuel-lifting'));

        $this->assertDatabaseHas('hauls', ['id' => $haulId, 'truck_id' => $replacementTruckId]);
    }

    public function test_haul_truck_assignment_rejects_capacity_inactive_type_conflict_and_bad_ids(): void
    {
        $records = $this->baseRecords();
        $haulId = $this->haul($records, ['quantity_liters' => 10000]);
        $smallTruckId = $this->truck(['truck_code' => 'TRK-HAUL-SMALL', 'capacity_liters' => 1000, 'status' => 'available']);
        $deliveryTruckId = $this->truck(['truck_code' => 'TRK-HAUL-DELIVERY', 'truck_type' => 'delivery', 'status' => 'available']);
        $inactiveTruckId = $this->truck(['truck_code' => 'TRK-HAUL-OFF', 'status' => 'inactive']);
        $conflictTruckId = $this->truck(['truck_code' => 'TRK-HAUL-CONFLICT', 'status' => 'available']);
        $this->haul($records, [
            'haul_code' => 'LFT-HAUL-CONFLICT',
            'truck_id' => $conflictTruckId,
            'scheduled_at' => '2026-09-01 09:00:00',
        ]);

        foreach ([$smallTruckId, $deliveryTruckId, $inactiveTruckId, $conflictTruckId, 999999] as $truckId) {
            $this->actingAs($records['dispatchOfficer'])
                ->from(route('dispatch.fuel-lifting'))
                ->patch(route('dispatch.fuel-lifting.hauls.truck', $haulId), $this->payload($truckId))
                ->assertRedirect(route('dispatch.fuel-lifting'))
                ->assertSessionHasErrors('truck');
        }

        $this->assertDatabaseHas('hauls', [
            'id' => $haulId,
            'truck_id' => $records['currentTruckId'],
        ]);
    }

    public function test_duplicate_assignment_and_reassignment_after_completion_are_blocked(): void
    {
        $records = $this->baseRecords();
        $haulId = $this->haul($records);
        $replacementTruckId = $this->truck(['truck_code' => 'TRK-HAUL-DUP', 'status' => 'available']);
        $payload = $this->payload($replacementTruckId);

        $this->actingAs($records['dispatchOfficer'])
            ->patch(route('dispatch.fuel-lifting.hauls.truck', $haulId), $payload)
            ->assertRedirect(route('dispatch.fuel-lifting'));
        $this->actingAs($records['dispatchOfficer'])
            ->patch(route('dispatch.fuel-lifting.hauls.truck', $haulId), $payload)
            ->assertRedirect(route('dispatch.fuel-lifting'));

        $this->assertDatabaseHas('hauls', ['id' => $haulId, 'truck_id' => $replacementTruckId]);

        DB::table('hauls')->where('id', $haulId)->update(['status' => 'completed']);
        $anotherTruckId = $this->truck(['truck_code' => 'TRK-HAUL-LATE', 'status' => 'available']);

        $this->actingAs($records['dispatchOfficer'])
            ->from(route('dispatch.fuel-lifting'))
            ->patch(route('dispatch.fuel-lifting.hauls.truck', $haulId), $this->payload($anotherTruckId))
            ->assertRedirect(route('dispatch.fuel-lifting'))
            ->assertSessionHasErrors('truck');

        $this->assertDatabaseHas('hauls', ['id' => $haulId, 'truck_id' => $replacementTruckId]);
    }

    public function test_unauthorized_roles_cannot_assign_haul_trucks(): void
    {
        $records = $this->baseRecords();
        $haulId = $this->haul($records);
        $replacementTruckId = $this->truck(['truck_code' => 'TRK-HAUL-RBAC', 'status' => 'available']);

        foreach (['sales_officer', 'inventory_officer', 'driver'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);

            $this->actingAs($user)
                ->patch(route('dispatch.fuel-lifting.hauls.truck', $haulId), $this->payload($replacementTruckId))
                ->assertForbidden();
            $this->actingAs($user)
                ->patch(route('admin.fuel-lifting.hauls.truck', $haulId), $this->payload($replacementTruckId))
                ->assertForbidden();
        }

        $this->assertDatabaseHas('hauls', [
            'id' => $haulId,
            'truck_id' => $records['currentTruckId'],
        ]);
    }

    private function payload(int $truckId, array $overrides = []): array
    {
        return array_merge([
            'idempotency_key' => (string) Str::uuid(),
            'truck_id' => $truckId,
        ], $overrides);
    }

    private function sideEffectCounts(): array
    {
        return [
            'payments' => DB::table('payments')->count(),
            'receivables' => DB::table('receivables')->count(),
            'stock_outs' => DB::table('stock_outs')->count(),
            'inventory_movements' => DB::table('inventory_movements')->count(),
            'deliveries' => DB::table('deliveries')->count(),
            'hauls' => DB::table('hauls')->count(),
        ];
    }

    private function haul(array $records, array $overrides = []): int
    {
        $haulId = DB::table('hauls')->insertGetId([
            'haul_code' => $overrides['haul_code'] ?? 'LFT-HAUL',
            'purchase_id' => $records['purchaseId'],
            'purchase_item_id' => $records['purchaseItemId'],
            'depot_id' => $records['depotId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'truck_id' => $overrides['truck_id'] ?? $records['currentTruckId'],
            'driver_user_id' => $records['driver']->id,
            'scheduled_at' => $overrides['scheduled_at'] ?? '2026-09-01 09:00:00',
            'source_location' => 'Depot Rack',
            'quantity_liters' => $overrides['quantity_liters'] ?? 10000,
            'status' => $overrides['status'] ?? 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('haul_allocations')->insert([
            'haul_id' => $haulId,
            'fuel_type_id' => $records['fuelTypeId'],
            'destination_type' => $overrides['destination_type'] ?? 'garage',
            'storage_location_id' => ($overrides['destination_type'] ?? 'garage') === 'garage' ? $records['garageId'] : null,
            'customer_id' => ($overrides['destination_type'] ?? 'garage') === 'customer' ? $records['customerId'] : null,
            'sale_id' => ($overrides['destination_type'] ?? 'garage') === 'customer' ? $records['saleId'] : null,
            'quantity_liters' => $overrides['quantity_liters'] ?? 10000,
            'allocated_at' => '2026-09-01 09:30:00',
            'status' => 'planned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $haulId;
    }

    private function truck(array $overrides = []): int
    {
        return DB::table('trucks')->insertGetId([
            'truck_code' => $overrides['truck_code'] ?? 'TRK-'.Str::upper(Str::random(8)),
            'plate_number' => $overrides['plate_number'] ?? 'HTR-'.Str::upper(Str::random(6)),
            'capacity_liters' => $overrides['capacity_liters'] ?? 30000,
            'truck_type' => $overrides['truck_type'] ?? 'hauling',
            'status' => $overrides['status'] ?? 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function baseRecords(): array
    {
        $dispatchOfficer = User::factory()->create(['role' => 'dispatch_officer', 'status' => 'active']);
        $inventoryOfficer = User::factory()->create(['role' => 'inventory_officer', 'status' => 'active']);
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active']);
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active']);
        DB::table('driver_profiles')->insert([
            'user_id' => $driver->id,
            'driver_code' => 'DRV-HAUL-'.Str::upper(Str::random(4)),
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $currentTruckId = $this->truck([
            'truck_code' => 'TRK-HAUL-CURRENT-'.Str::upper(Str::random(4)),
            'status' => 'assigned',
        ]);
        $depotId = DB::table('depots')->insertGetId([
            'depot_code' => 'DEP-HAUL-'.Str::upper(Str::random(4)),
            'name' => 'Haul Depot',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $garageId = DB::table('storage_locations')->insertGetId([
            'location_code' => 'GAR-HAUL-'.Str::upper(Str::random(4)),
            'name' => 'Haul Garage',
            'type' => 'garage',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $fuelTypeId = DB::table('fuel_types')->insertGetId([
            'code' => 'FUEL-HAUL-'.Str::upper(Str::random(4)),
            'name' => 'Haul Diesel '.Str::upper(Str::random(4)),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-HAUL-'.Str::upper(Str::random(4)),
            'name' => 'Haul Customer',
            'company_name' => 'Haul Customer Co.',
            'location' => 'San Fernando',
            'payment_status' => 'clear',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => 'SLS-HAUL-'.Str::upper(Str::random(4)),
            'customer_id' => $customerId,
            'sale_date' => '2026-08-31',
            'payment_method' => 'cash_on_delivery',
            'payment_terms' => 'cod',
            'status' => 'confirmed',
            'created_by' => $salesOfficer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => 'PUR-HAUL-'.Str::upper(Str::random(4)),
            'depot_id' => $depotId,
            'purchase_date' => '2026-08-31',
            'payment_status' => 'paid',
            'status' => 'ordered',
            'created_by' => $inventoryOfficer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $purchaseItemId = DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchaseId,
            'fuel_type_id' => $fuelTypeId,
            'quantity_ordered_liters' => 50000,
            'unit_cost' => 50,
            'line_total' => 2500000,
            'quantity_hauled_liters' => 0,
            'status' => 'unlifted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('dispatchOfficer', 'inventoryOfficer', 'salesOfficer', 'driver', 'currentTruckId', 'depotId', 'garageId', 'fuelTypeId', 'customerId', 'saleId', 'purchaseId', 'purchaseItemId');
    }
}
