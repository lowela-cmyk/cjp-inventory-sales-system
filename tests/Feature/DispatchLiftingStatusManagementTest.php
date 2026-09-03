<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DispatchLiftingStatusManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_officer_can_progress_lift_status_to_completed_without_inventory_side_effects(): void
    {
        Carbon::setTestNow('2026-08-31 10:00:00');
        $records = $this->baseRecords();
        $haulId = $this->haul($records, ['status' => 'scheduled']);
        $before = $this->sideEffectCounts();

        foreach (['in_transit', 'lifted', 'completed'] as $status) {
            $this->actingAs($records['dispatchOfficer'])
                ->patch(route('dispatch.fuel-lifting.hauls.status', $haulId), $this->statusPayload($status))
                ->assertRedirect(route('dispatch.fuel-lifting'));
        }

        $haul = DB::table('hauls')->where('id', $haulId)->first();
        $this->assertSame('completed', $haul->status);
        $this->assertSame('2026-08-31 10:00:00', $haul->hauled_at);
        $this->assertDatabaseHas('purchase_items', [
            'id' => $records['purchaseItemId'],
            'quantity_hauled_liters' => '10000.00',
            'status' => 'partial',
        ]);
        $this->assertDatabaseHas('purchases', ['id' => $records['purchaseId'], 'status' => 'partially_hauled']);
        $this->assertSame($before, $this->sideEffectCounts());

        Carbon::setTestNow();
    }

    public function test_invalid_transition_repeated_completion_and_manipulated_status_are_blocked(): void
    {
        Carbon::setTestNow('2026-08-31 10:00:00');
        $records = $this->baseRecords();
        $haulId = $this->haul($records, ['status' => 'scheduled']);

        $this->actingAs($records['dispatchOfficer'])
            ->from(route('dispatch.fuel-lifting'))
            ->patch(route('dispatch.fuel-lifting.hauls.status', $haulId), $this->statusPayload('completed'))
            ->assertRedirect(route('dispatch.fuel-lifting'))
            ->assertSessionHasErrors('lifting');

        $this->actingAs($records['dispatchOfficer'])
            ->from(route('dispatch.fuel-lifting'))
            ->patch(route('dispatch.fuel-lifting.hauls.status', $haulId), $this->statusPayload('bogus'))
            ->assertSessionHasErrors('status');

        foreach (['in_transit', 'lifted'] as $status) {
            $this->actingAs($records['dispatchOfficer'])
                ->patch(route('dispatch.fuel-lifting.hauls.status', $haulId), $this->statusPayload($status))
                ->assertRedirect(route('dispatch.fuel-lifting'));
        }

        $payload = $this->statusPayload('completed', ['idempotency_key' => (string) Str::uuid()]);
        $this->actingAs($records['dispatchOfficer'])
            ->patch(route('dispatch.fuel-lifting.hauls.status', $haulId), $payload)
            ->assertRedirect(route('dispatch.fuel-lifting'));
        $hauledAt = DB::table('hauls')->where('id', $haulId)->value('hauled_at');

        $this->actingAs($records['dispatchOfficer'])
            ->patch(route('dispatch.fuel-lifting.hauls.status', $haulId), $payload)
            ->assertRedirect(route('dispatch.fuel-lifting'));
        $this->actingAs($records['dispatchOfficer'])
            ->from(route('dispatch.fuel-lifting'))
            ->patch(route('dispatch.fuel-lifting.hauls.status', $haulId), $this->statusPayload('lifted'))
            ->assertRedirect(route('dispatch.fuel-lifting'))
            ->assertSessionHasErrors('lifting');

        $this->assertSame($hauledAt, DB::table('hauls')->where('id', $haulId)->value('hauled_at'));
        $this->assertSame(10000.0, round((float) DB::table('purchase_items')->where('id', $records['purchaseItemId'])->value('quantity_hauled_liters'), 2));

        Carbon::setTestNow();
    }

    public function test_lifting_status_requires_valid_assignments_quantity_source_and_active_records(): void
    {
        $records = $this->baseRecords();
        $missingDriverHaul = $this->haul($records, ['haul_code' => 'LFT-NO-DRIVER', 'driver_user_id' => $records['salesOfficer']->id]);
        $badTruckHaul = $this->haul($records, ['haul_code' => 'LFT-BAD-TRUCK', 'truck_status' => 'maintenance']);
        $badQuantityHaul = $this->haul($records, ['haul_code' => 'LFT-BAD-QTY', 'quantity_liters' => 60000]);
        $cancelledPurchaseHaul = $this->haul($records, ['haul_code' => 'LFT-CANCELLED-PUR', 'purchase_status' => 'cancelled']);

        foreach ([$missingDriverHaul, $badTruckHaul, $badQuantityHaul, $cancelledPurchaseHaul, 999999] as $haulId) {
            $this->actingAs($records['dispatchOfficer'])
                ->from(route('dispatch.fuel-lifting'))
                ->patch(route('dispatch.fuel-lifting.hauls.status', $haulId), $this->statusPayload('in_transit'))
                ->assertRedirect(route('dispatch.fuel-lifting'))
                ->assertSessionHasErrors('lifting');
        }
    }

    public function test_garage_bound_and_direct_client_lifts_use_same_status_workflow_without_creating_deliveries(): void
    {
        Carbon::setTestNow('2026-08-31 10:00:00');
        $records = $this->baseRecords();
        $garageHaulId = $this->haul($records, ['haul_code' => 'LFT-GARAGE']);
        $directHaulId = $this->haul($records, ['haul_code' => 'LFT-DIRECT', 'destination_type' => 'customer']);
        $beforeDeliveries = DB::table('deliveries')->count();
        $beforeStockOuts = DB::table('stock_outs')->count();
        $beforeMovements = DB::table('inventory_movements')->count();

        foreach ([$garageHaulId, $directHaulId] as $haulId) {
            foreach (['in_transit', 'lifted', 'completed'] as $status) {
                $this->actingAs($records['dispatchOfficer'])
                    ->patch(route('dispatch.fuel-lifting.hauls.status', $haulId), $this->statusPayload($status))
                    ->assertRedirect(route('dispatch.fuel-lifting'));
            }
        }

        $this->assertSame($beforeDeliveries, DB::table('deliveries')->count());
        $this->assertSame($beforeStockOuts, DB::table('stock_outs')->count());
        $this->assertSame($beforeMovements, DB::table('inventory_movements')->count());

        Carbon::setTestNow();
    }

    public function test_partial_and_multiple_lifts_cannot_exceed_available_purchase_fuel(): void
    {
        Carbon::setTestNow('2026-08-31 10:00:00');
        $records = $this->baseRecords();
        $firstHaulId = $this->haul($records, ['haul_code' => 'LFT-PARTIAL-ONE', 'quantity_liters' => 20000]);
        $secondHaulId = $this->haul($records, ['haul_code' => 'LFT-PARTIAL-TWO', 'quantity_liters' => 30000]);
        $overLimitHaulId = $this->haul($records, ['haul_code' => 'LFT-PARTIAL-OVER', 'quantity_liters' => 10000]);
        $before = $this->sideEffectCounts();

        foreach ([$firstHaulId, $secondHaulId] as $haulId) {
            foreach (['in_transit', 'lifted', 'completed'] as $status) {
                $this->actingAs($records['dispatchOfficer'])
                    ->patch(route('dispatch.fuel-lifting.hauls.status', $haulId), $this->statusPayload($status))
                    ->assertRedirect(route('dispatch.fuel-lifting'));
            }
        }

        foreach (['in_transit', 'lifted'] as $status) {
            $this->actingAs($records['dispatchOfficer'])
                ->patch(route('dispatch.fuel-lifting.hauls.status', $overLimitHaulId), $this->statusPayload($status))
                ->assertRedirect(route('dispatch.fuel-lifting'));
        }

        $this->actingAs($records['dispatchOfficer'])
            ->from(route('dispatch.fuel-lifting'))
            ->patch(route('dispatch.fuel-lifting.hauls.status', $overLimitHaulId), $this->statusPayload('completed'))
            ->assertRedirect(route('dispatch.fuel-lifting'))
            ->assertSessionHasErrors('lifting');

        $this->assertDatabaseHas('purchase_items', [
            'id' => $records['purchaseItemId'],
            'quantity_hauled_liters' => '50000.00',
            'status' => 'lifted',
        ]);
        $this->assertDatabaseHas('purchases', ['id' => $records['purchaseId'], 'status' => 'hauled']);
        $this->assertDatabaseHas('hauls', ['id' => $overLimitHaulId, 'status' => 'lifted']);
        $this->assertSame($before, $this->sideEffectCounts());

        Carbon::setTestNow();
    }

    public function test_cancelled_lifts_do_not_reduce_available_purchase_fuel(): void
    {
        Carbon::setTestNow('2026-08-31 10:00:00');
        $records = $this->baseRecords();
        $this->haul($records, ['haul_code' => 'LFT-CANCELLED-AVAILABLE', 'quantity_liters' => 45000, 'status' => 'cancelled']);
        $validHaulId = $this->haul($records, ['haul_code' => 'LFT-AFTER-CANCELLED', 'quantity_liters' => 50000]);

        foreach (['in_transit', 'lifted', 'completed'] as $status) {
            $this->actingAs($records['dispatchOfficer'])
                ->patch(route('dispatch.fuel-lifting.hauls.status', $validHaulId), $this->statusPayload($status))
                ->assertRedirect(route('dispatch.fuel-lifting'));
        }

        $this->assertDatabaseHas('hauls', ['id' => $validHaulId, 'status' => 'completed']);
        $this->assertDatabaseHas('purchase_items', [
            'id' => $records['purchaseItemId'],
            'quantity_hauled_liters' => '50000.00',
            'status' => 'lifted',
        ]);

        Carbon::setTestNow();
    }

    public function test_stale_concurrent_status_update_is_rejected_after_locked_row_changes(): void
    {
        $records = $this->baseRecords();
        $haulId = $this->haul($records, ['status' => 'scheduled']);

        $this->actingAs($records['dispatchOfficer'])
            ->patch(route('dispatch.fuel-lifting.hauls.status', $haulId), $this->statusPayload('in_transit'))
            ->assertRedirect(route('dispatch.fuel-lifting'));

        $this->actingAs($records['dispatchOfficer'])
            ->from(route('dispatch.fuel-lifting'))
            ->patch(route('dispatch.fuel-lifting.hauls.status', $haulId), $this->statusPayload('in_transit'))
            ->assertRedirect(route('dispatch.fuel-lifting'))
            ->assertSessionHasErrors('lifting');

        $this->assertDatabaseHas('hauls', [
            'id' => $haulId,
            'status' => 'in_transit',
        ]);
    }

    public function test_admin_view_shows_real_lift_status_controls_and_unauthorized_roles_are_blocked(): void
    {
        $records = $this->baseRecords();
        $haulId = $this->haul($records, ['status' => 'scheduled']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin)
            ->get(route('admin.fuel-lifting'))
            ->assertOk()
            ->assertSee('LFT-STATUS')
            ->assertSee('Scheduled')
            ->assertSee('name="status"', false)
            ->assertSee(route('admin.fuel-lifting.hauls.status', $haulId), false);

        $this->actingAs($admin)
            ->patch(route('admin.fuel-lifting.hauls.status', $haulId), $this->statusPayload('in_transit'))
            ->assertRedirect(route('admin.fuel-lifting'));

        foreach (['sales_officer', 'inventory_officer', 'driver'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);
            $this->actingAs($user)
                ->patch(route('dispatch.fuel-lifting.hauls.status', $haulId), $this->statusPayload('lifted'))
                ->assertForbidden();
            $this->actingAs($user)
                ->patch(route('admin.fuel-lifting.hauls.status', $haulId), $this->statusPayload('lifted'))
                ->assertForbidden();
        }
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
     * @return array<string, int>
     */
    private function sideEffectCounts(): array
    {
        return [
            'inventory_movements' => DB::table('inventory_movements')->count(),
            'deliveries' => DB::table('deliveries')->count(),
            'stock_outs' => DB::table('stock_outs')->count(),
            'payments' => DB::table('payments')->count(),
            'receivables' => DB::table('receivables')->count(),
        ];
    }

    /**
     * @param array<string, mixed> $records
     * @param array<string, mixed> $overrides
     */
    private function haul(array $records, array $overrides = []): int
    {
        if (isset($overrides['purchase_status'])) {
            DB::table('purchases')->where('id', $records['purchaseId'])->update(['status' => $overrides['purchase_status']]);
        }

        $truckId = $records['truckId'];
        if (isset($overrides['truck_status'])) {
            $truckId = DB::table('trucks')->insertGetId([
                'truck_code' => 'TRK-'.Str::upper(Str::random(8)),
                'capacity_liters' => 30000,
                'truck_type' => 'hauling',
                'status' => $overrides['truck_status'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $haulId = DB::table('hauls')->insertGetId([
            'haul_code' => $overrides['haul_code'] ?? 'LFT-STATUS',
            'purchase_id' => $records['purchaseId'],
            'purchase_item_id' => $records['purchaseItemId'],
            'depot_id' => $records['depotId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'truck_id' => $overrides['truck_id'] ?? $truckId,
            'driver_user_id' => $overrides['driver_user_id'] ?? $records['driver']->id,
            'dr_number' => 'DR-STATUS',
            'scheduled_at' => '2026-09-01 09:00:00',
            'source_location' => 'Status Depot Rack',
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

    /**
     * @return array<string, mixed>
     */
    private function baseRecords(): array
    {
        $dispatchOfficer = User::factory()->create(['role' => 'dispatch_officer', 'status' => 'active']);
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active']);
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active', 'phone' => '09997776666']);
        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-STATUS',
            'capacity_liters' => 30000,
            'truck_type' => 'hauling',
            'status' => 'assigned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $depotId = DB::table('depots')->insertGetId([
            'depot_code' => 'DEP-STATUS',
            'name' => 'Status Depot',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $garageId = DB::table('storage_locations')->insertGetId([
            'location_code' => 'GAR-STATUS',
            'name' => 'Status Garage',
            'type' => 'garage',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $fuelTypeId = DB::table('fuel_types')->insertGetId([
            'code' => 'DSL-LIFT',
            'name' => 'Diesel Lift Status',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-LIFT',
            'name' => 'Lift Status Customer',
            'company_name' => 'Lift Status Customer Co.',
            'location' => 'San Fernando',
            'payment_status' => 'clear',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => 'SLS-LIFT',
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
            'purchase_code' => 'PUR-LIFT',
            'depot_id' => $depotId,
            'purchase_date' => '2026-08-31',
            'payment_status' => 'paid',
            'status' => 'ordered',
            'created_by' => $salesOfficer->id,
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

        return compact('dispatchOfficer', 'salesOfficer', 'driver', 'truckId', 'depotId', 'garageId', 'fuelTypeId', 'customerId', 'saleId', 'purchaseId', 'purchaseItemId');
    }
}
