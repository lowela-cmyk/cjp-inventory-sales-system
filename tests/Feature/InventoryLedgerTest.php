<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\InventoryLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryLedgerTest extends TestCase
{
    use RefreshDatabase;

    public function test_ledger_shows_active_purchase_progress_and_transactions_keep_zero_lift_purchases(): void
    {
        $records = $this->baseRecords();
        $purchase = $this->purchase($records, 'PUR-ZERO-LIFT', 100000);

        $rows = app(InventoryLedgerService::class)->rows();

        $this->assertContains('PUR-ZERO-LIFT', $rows['ledger']->flatten()->all());
        $this->assertContains('100,000.00', $rows['ledger']->flatten()->all());
        $this->assertContains('0.00', $rows['ledger']->flatten()->all());
        $this->assertContains('Incomplete', $rows['ledger']->flatten()->all());

        $transaction = $rows['transactions']->firstWhere('purchase_item_id', $purchase['purchaseItemId']);
        $this->assertNotNull($transaction);
        $this->assertSame('PUR-ZERO-LIFT', $transaction['purchase_code']);
        $this->assertCount(0, $transaction['lifts']);

        $this->actingAs($records['inventoryOfficer'])
            ->get(route('inventory-officer.ledger.transactions'))
            ->assertOk()
            ->assertSee('PUR-ZERO-LIFT')
            ->assertSee('No lift assignments have been created for this purchase yet.');
    }

    public function test_multiple_lift_transactions_roll_up_to_one_purchase_and_final_trip_can_be_smaller(): void
    {
        $records = $this->baseRecords();
        $purchase = $this->purchase($records, 'PUR-100K-MULTI', 100000);

        $this->haul($records, $purchase, 'LFT-40K-A', 40000, 'completed', '2026-09-01 08:00:00');
        $this->haul($records, $purchase, 'LFT-40K-B', 40000, 'completed', '2026-09-02 08:00:00');

        $rows = app(InventoryLedgerService::class)->rows();
        $active = $rows['ledger']->first(fn ($row): bool => $row[0] === 'PUR-100K-MULTI');

        $this->assertSame(['PUR-100K-MULTI', 'Diesel', 'CJP Depot', '100,000.00', '80,000.00', '20,000.00', 'Partially Lifted'], $active);

        $this->haul($records, $purchase, 'LFT-20K-FINAL', 20000, 'completed', '2026-09-03 08:00:00');

        $rows = app(InventoryLedgerService::class)->rows();
        $this->assertFalse($rows['ledger']->contains(fn ($row): bool => $row[0] === 'PUR-100K-MULTI'));

        $transaction = $rows['transactions']->firstWhere('purchase_item_id', $purchase['purchaseItemId']);
        $this->assertSame('Complete', $transaction['status']);
        $this->assertSame('0.00', $transaction['cells'][5]);
        $this->assertSame(['LFT-40K-A', 'LFT-40K-B', 'LFT-20K-FINAL'], $transaction['lifts']->pluck('code')->all());
    }

    public function test_cancelled_lifts_remain_historical_but_do_not_count_toward_total_lifted(): void
    {
        $records = $this->baseRecords();
        $purchase = $this->purchase($records, 'PUR-CANCELLED-LIFT', 50000);

        $this->haul($records, $purchase, 'LFT-CANCELLED', 40000, 'cancelled');
        $this->haul($records, $purchase, 'LFT-COMPLETED', 25000, 'completed');

        $rows = app(InventoryLedgerService::class)->rows();
        $active = $rows['ledger']->first(fn ($row): bool => $row[0] === 'PUR-CANCELLED-LIFT');
        $transaction = $rows['transactions']->firstWhere('purchase_item_id', $purchase['purchaseItemId']);

        $this->assertSame('25,000.00', $active[4]);
        $this->assertSame('25,000.00', $active[5]);
        $this->assertSame(['LFT-CANCELLED', 'LFT-COMPLETED'], $transaction['lifts']->pluck('code')->all());
        $this->assertFalse($transaction['lifts'][0]['counts_as_lifted']);
        $this->assertTrue($transaction['lifts'][1]['counts_as_lifted']);
    }

    public function test_view_transactions_modal_displays_all_lift_blocks_and_hover_details_from_database(): void
    {
        $records = $this->baseRecords();
        $purchase = $this->purchase($records, 'PUR-MODAL-LIFTS', 100000);

        $this->haul($records, $purchase, 'LFT-MODAL-1', 40000, 'completed');
        $this->haul($records, $purchase, 'LFT-MODAL-2', 40000, 'completed');
        $this->haul($records, $purchase, 'LFT-MODAL-3', 20000, 'scheduled');

        $this->actingAs($records['inventoryOfficer'])
            ->get(route('inventory-officer.ledger.transactions'))
            ->assertOk()
            ->assertSee('VIEW TRANSACTIONS')
            ->assertSee('PUR-MODAL-LIFTS')
            ->assertSee('Lift 1')
            ->assertSee('Lift 2')
            ->assertSee('Lift 3')
            ->assertSee('LFT-MODAL-1')
            ->assertSee('LFT-MODAL-2')
            ->assertSee('LFT-MODAL-3')
            ->assertSee('Lift/Transaction ID')
            ->assertSee('Driver One')
            ->assertSee('TRK-LEDGER');
    }

    public function test_dispatch_creates_lift_assignments_under_the_same_purchase_and_blocks_invalid_quantities(): void
    {
        $records = $this->baseRecords();
        $purchase = $this->purchase($records, 'PUR-DISPATCH-CREATE', 100000);

        $payload = [
            'idempotency_key' => (string) Str::uuid(),
            'purchase_item_id' => $purchase['purchaseItemId'],
            'driver_user_id' => $records['driver']->id,
            'truck_id' => $records['truckId'],
            'scheduled_at' => '2026-09-04 08:00:00',
            'quantity_liters' => 40000,
        ];

        $this->actingAs($records['dispatchOfficer'])
            ->post(route('dispatch.fuel-lifting.hauls.store'), $payload)
            ->assertRedirect(route('dispatch.fuel-lifting'));

        $this->assertSame(1, DB::table('hauls')->where('purchase_id', $purchase['purchaseId'])->count());

        $payload['idempotency_key'] = (string) Str::uuid();
        $payload['scheduled_at'] = '2026-09-05 08:00:00';

        $this->actingAs($records['dispatchOfficer'])
            ->post(route('dispatch.fuel-lifting.hauls.store'), $payload)
            ->assertRedirect(route('dispatch.fuel-lifting'));

        $this->assertSame(2, DB::table('hauls')->where('purchase_id', $purchase['purchaseId'])->count());

        $payload['idempotency_key'] = (string) Str::uuid();
        $payload['scheduled_at'] = '2026-09-06 08:00:00';
        $payload['quantity_liters'] = 40000;

        $this->actingAs($records['dispatchOfficer'])
            ->post(route('dispatch.fuel-lifting.hauls.store'), $payload)
            ->assertSessionHasErrors('lift');

        $payload['idempotency_key'] = (string) Str::uuid();
        $payload['quantity_liters'] = 20000;

        $this->actingAs($records['dispatchOfficer'])
            ->post(route('dispatch.fuel-lifting.hauls.store'), $payload)
            ->assertRedirect(route('dispatch.fuel-lifting'));

        $this->assertSame(3, DB::table('hauls')->where('purchase_id', $purchase['purchaseId'])->count());
    }

    public function test_dispatch_blocks_truck_capacity_violation_and_duplicate_submission(): void
    {
        $records = $this->baseRecords();
        $purchase = $this->purchase($records, 'PUR-DISPATCH-VALIDATION', 90000);

        $payload = [
            'idempotency_key' => (string) Str::uuid(),
            'purchase_item_id' => $purchase['purchaseItemId'],
            'driver_user_id' => $records['driver']->id,
            'truck_id' => $records['truckId'],
            'scheduled_at' => '2026-09-04 08:00:00',
            'quantity_liters' => 45000,
        ];

        $this->actingAs($records['dispatchOfficer'])
            ->post(route('dispatch.fuel-lifting.hauls.store'), $payload)
            ->assertSessionHasErrors('lift');

        $payload['idempotency_key'] = (string) Str::uuid();
        $payload['quantity_liters'] = 40000;

        $this->actingAs($records['dispatchOfficer'])
            ->post(route('dispatch.fuel-lifting.hauls.store'), $payload)
            ->assertRedirect(route('dispatch.fuel-lifting'));

        $payload['idempotency_key'] = (string) Str::uuid();

        $this->actingAs($records['dispatchOfficer'])
            ->post(route('dispatch.fuel-lifting.hauls.store'), $payload)
            ->assertSessionHasErrors('lift');

        $this->assertSame(1, DB::table('hauls')->where('purchase_id', $purchase['purchaseId'])->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function baseRecords(): array
    {
        $inventoryOfficer = User::factory()->create(['role' => 'inventory_officer', 'status' => 'active']);
        $dispatchOfficer = User::factory()->create(['role' => 'dispatch_officer', 'status' => 'active']);
        $driver = User::factory()->create(['name' => 'Driver One', 'role' => 'driver', 'status' => 'active']);

        DB::table('driver_profiles')->insert([
            'user_id' => $driver->id,
            'driver_code' => 'DRV-ONE',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $depotId = DB::table('depots')->insertGetId([
            'depot_code' => 'DEP-LEDGER',
            'name' => 'CJP Depot',
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
        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-LEDGER',
            'plate_number' => 'CJP-100',
            'capacity_liters' => 40000,
            'truck_type' => 'hauling',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('inventoryOfficer', 'dispatchOfficer', 'driver', 'depotId', 'fuelTypeId', 'truckId');
    }

    /**
     * @param array<string, mixed> $records
     * @return array<string, int>
     */
    private function purchase(array $records, string $code, float $quantity): array
    {
        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => $code,
            'depot_id' => $records['depotId'],
            'purchase_date' => '2026-09-01',
            'payment_status' => 'paid',
            'status' => 'ordered',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $purchaseItemId = DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchaseId,
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_ordered_liters' => $quantity,
            'unit_cost' => 50,
            'line_total' => $quantity * 50,
            'quantity_hauled_liters' => 0,
            'status' => 'unlifted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('purchaseId', 'purchaseItemId');
    }

    /**
     * @param array<string, mixed> $records
     * @param array<string, int> $purchase
     */
    private function haul(array $records, array $purchase, string $code, float $quantity, string $status, string $scheduledAt = '2026-09-01 08:00:00'): int
    {
        return DB::table('hauls')->insertGetId([
            'haul_code' => $code,
            'purchase_id' => $purchase['purchaseId'],
            'purchase_item_id' => $purchase['purchaseItemId'],
            'depot_id' => $records['depotId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'truck_id' => $records['truckId'],
            'driver_user_id' => $records['driver']->id,
            'scheduled_at' => $scheduledAt,
            'hauled_at' => $status === 'completed' ? $scheduledAt : null,
            'quantity_liters' => $quantity,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
