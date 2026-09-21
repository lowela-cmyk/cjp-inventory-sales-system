<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ConnectedLiftingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_creation_is_idempotent_creates_one_alert_and_ignores_manual_status(): void
    {
        $records = $this->records();
        $key = (string) Str::uuid();
        $payload = [
            'idempotency_key' => $key,
            'purchase_date' => '2026-09-18',
            'depot_id' => $records['depot'],
            'fuel_type_id' => $records['fuel'],
            'quantity_ordered_liters' => 10000,
            'unit_cost' => 50,
            'payment_status' => 'unpaid',
            'status' => 'cancelled',
        ];

        $this->actingAs($records['inventory'])->post(route('inventory-officer.inventory.purchases.store'), $payload)->assertRedirect();
        $this->actingAs($records['inventory'])->post(route('inventory-officer.inventory.purchases.store'), $payload)->assertRedirect();

        $this->assertDatabaseCount('purchases', 1);
        $this->assertDatabaseCount('alerts', 1);
        $this->assertDatabaseCount('purchase_status_histories', 1);
        $this->assertDatabaseHas('purchases', ['status' => 'ordered', 'workflow_status' => 'pending']);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->actingAs($records['inventory'])->get(route('inventory-officer.inventory.stock-in'))
            ->assertOk()
            ->assertSee('Purchase Stock-In Pipeline')
            ->assertSee((string) DB::table('purchases')->value('purchase_code'));

        $alertId = (int) DB::table('alerts')->value('id');
        $this->actingAs($records['inventory'])->get(route('inventory-officer.alerts'))->assertOk()->assertSee('Unread');
        $this->actingAs($records['inventory'])->patch(route('inventory-officer.alerts.read', $alertId))->assertRedirect();
        $this->assertDatabaseHas('alert_reads', ['alert_id' => $alertId, 'user_id' => $records['inventory']->id]);
    }

    public function test_dispatch_can_schedule_multiple_unique_same_depot_purchases_atomically(): void
    {
        $records = $this->records();
        [$first, $second] = $this->purchases($records, [12000, 8000]);

        $this->actingAs($records['dispatch'])->post(route('dispatch.fuel-lifting.hauls.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [
                ['purchase_item_id' => $first, 'quantity_liters' => 7000],
                ['purchase_item_id' => $second, 'quantity_liters' => 8000],
            ],
            'driver_user_id' => $records['driver']->id,
            'truck_id' => $records['truck'],
            'scheduled_at' => '2026-09-19 08:00:00',
        ])->assertRedirect(route('dispatch.fuel-lifting'));

        $this->assertDatabaseCount('lifting_schedules', 1);
        $this->assertDatabaseCount('hauls', 2);
        $this->assertSame(15000.0, (float) DB::table('hauls')->sum('quantity_liters'));
        $this->assertSame(1, DB::table('hauls')->distinct()->count('lifting_schedule_id'));
        $this->assertSame(0, DB::table('hauls')->whereNotNull('source_location')->count());
        $this->assertSame(2, DB::table('purchases')->where('workflow_status', 'scheduled')->count());

        $haulCodes = DB::table('hauls')->orderBy('id')->pluck('haul_code')->all();
        $driverPage = $this->actingAs($records['driver'])->get(route('driver.fuel-lifting'));

        $driverPage->assertOk()
            ->assertSee($haulCodes[0])
            ->assertSee($haulCodes[1])
            ->assertSee('driver-overview-card', false);
        $this->assertSame(2, substr_count($driverPage->getContent(), 'Upload Withdrawal Receipt'));
    }

    public function test_active_lift_reserves_truck_until_cancelled_and_dropdown_only_lists_available_fleet(): void
    {
        $records = $this->records();
        [$first, $second] = $this->purchases($records, [10000, 10000]);
        DB::table('trucks')->where('id', $records['truck'])->update([
            'plate_number' => 'CW-0001', 'name' => 'Workflow Tanker',
        ]);
        $availableTruck = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-CW-AVAILABLE', 'plate_number' => 'CW-0002', 'name' => 'Available Tanker',
            'capacity_liters' => 25000, 'truck_type' => 'hauling', 'status' => 'available',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('trucks')->insert([
            'truck_code' => 'TRK-CW-MAINT', 'plate_number' => 'CW-0003', 'name' => 'Maintenance Tanker',
            'capacity_liters' => 25000, 'truck_type' => 'hauling', 'status' => 'maintenance',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($records['dispatch'])->post(route('dispatch.fuel-lifting.hauls.store'), [
            'idempotency_key' => (string) Str::uuid(), 'purchase_item_id' => $first, 'quantity_liters' => 5000,
            'driver_user_id' => $records['driver']->id, 'truck_id' => $records['truck'], 'scheduled_at' => '2026-09-19 08:00:00',
        ])->assertRedirect(route('dispatch.fuel-lifting'));
        $this->assertDatabaseHas('trucks', ['id' => $records['truck'], 'status' => 'assigned']);

        $this->actingAs($records['dispatch'])->from(route('dispatch.fuel-lifting'))->post(route('dispatch.fuel-lifting.hauls.store'), [
            'idempotency_key' => (string) Str::uuid(), 'purchase_item_id' => $second, 'quantity_liters' => 5000,
            'driver_user_id' => $records['driver']->id, 'truck_id' => $records['truck'], 'scheduled_at' => '2026-09-20 08:00:00',
        ])->assertSessionHasErrors('lift');
        $this->assertDatabaseCount('hauls', 1);

        $page = $this->actingAs($records['dispatch'])->get(route('dispatch.fuel-lifting'));
        $page->assertOk()
            ->assertDontSee('CW-0001 – TRK-CW – 30,000.00 L – Available')
            ->assertSee('CW-0002 – TRK-CW-AVAILABLE – 25,000.00 L – Available')
            ->assertDontSee('CW-0003 – TRK-CW-MAINT');

        $haulId = (int) DB::table('hauls')->value('id');
        $this->actingAs($records['dispatch'])->patch(route('dispatch.fuel-lifting.hauls.status', $haulId), [
            'idempotency_key' => (string) Str::uuid(), 'status' => 'cancelled',
        ])->assertRedirect(route('dispatch.fuel-lifting'));
        $this->assertDatabaseHas('trucks', ['id' => $records['truck'], 'status' => 'available']);

        $this->actingAs($records['dispatch'])->get(route('dispatch.fuel-lifting'))
            ->assertOk()->assertSee('CW-0001 – TRK-CW – 30,000.00 L – Available');
        $this->assertDatabaseHas('trucks', ['id' => $availableTruck, 'status' => 'available']);
    }

    public function test_purchase_edit_recomputes_workflow_status_from_haul_state(): void
    {
        $records = $this->records();
        $this->actingAs($records['inventory'])->post(route('inventory-officer.inventory.purchases.store'), [
            'purchase_date' => '2026-09-18',
            'depot_id' => $records['depot'],
            'fuel_type_id' => $records['fuel'],
            'quantity_ordered_liters' => 10000,
            'unit_cost' => 50,
            'payment_status' => 'unpaid',
        ])->assertRedirect();

        $purchaseId = (int) DB::table('purchases')->value('id');
        $itemId = (int) DB::table('purchase_items')->value('id');
        DB::table('purchases')->where('id', $purchaseId)->update(['workflow_status' => 'scheduled']);

        $payload = [
            'purchase_date' => '2026-09-18',
            'depot_id' => $records['depot'],
            'fuel_type_id' => $records['fuel'],
            'quantity_ordered_liters' => 10000,
            'unit_cost' => 55,
            'payment_status' => 'paid',
            'status' => 'cancelled',
        ];
        $this->actingAs($records['inventory'])->patch(route('inventory-officer.inventory.purchases.update', $itemId), $payload)->assertRedirect();

        $this->assertDatabaseHas('purchases', ['id' => $purchaseId, 'status' => 'ordered', 'workflow_status' => 'pending', 'payment_status' => 'paid']);
        $this->assertDatabaseHas('purchase_status_histories', ['purchase_id' => $purchaseId, 'previous_status' => 'scheduled', 'new_status' => 'pending']);
        $historyCount = DB::table('purchase_status_histories')->count();
        $this->actingAs($records['inventory'])->patch(route('inventory-officer.inventory.purchases.update', $itemId), $payload)->assertRedirect();
        $this->assertSame($historyCount, DB::table('purchase_status_histories')->count());
    }

    public function test_purchase_cancellation_updates_workflow_status_and_removes_stock_in_pipeline_row(): void
    {
        $records = $this->records();
        $this->actingAs($records['inventory'])->post(route('inventory-officer.inventory.purchases.store'), [
            'purchase_date' => '2026-09-18',
            'depot_id' => $records['depot'],
            'fuel_type_id' => $records['fuel'],
            'quantity_ordered_liters' => 10000,
            'unit_cost' => 50,
            'payment_status' => 'unpaid',
        ])->assertRedirect();

        $purchaseId = (int) DB::table('purchases')->value('id');
        $itemId = (int) DB::table('purchase_items')->value('id');
        $this->actingAs($records['inventory'])->patch(route('inventory-officer.inventory.purchases.cancel', $itemId))->assertRedirect();

        $this->assertDatabaseHas('purchases', ['id' => $purchaseId, 'status' => 'cancelled', 'workflow_status' => 'cancelled']);
        $this->actingAs($records['inventory'])->get(route('inventory-officer.inventory.stock-in'))
            ->assertOk()->assertViewHas('stockInPurchases', fn ($rows): bool => $rows->count() === 0);
    }

    public function test_dispatch_can_schedule_partial_then_exact_remaining_quantity(): void
    {
        $records = $this->records();
        [$item] = $this->purchases($records, [10000]);
        $secondTruck = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-CW-2', 'plate_number' => 'CW-0002', 'name' => 'Second Workflow Truck',
            'capacity_liters' => 30000, 'truck_type' => 'hauling', 'status' => 'available',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $base = [
            'purchase_item_id' => $item,
            'driver_user_id' => $records['driver']->id,
            'truck_id' => $records['truck'],
        ];

        $this->actingAs($records['dispatch'])->post(route('dispatch.fuel-lifting.hauls.store'), $base + [
            'idempotency_key' => (string) Str::uuid(), 'scheduled_at' => '2026-09-19 08:00:00', 'quantity_liters' => 6000,
        ])->assertRedirect(route('dispatch.fuel-lifting'));

        $this->actingAs($records['dispatch'])->get(route('dispatch.fuel-lifting'))->assertOk()->assertSee('4,000.00 L remaining');

        $this->actingAs($records['dispatch'])->from(route('dispatch.fuel-lifting'))->post(route('dispatch.fuel-lifting.hauls.store'), array_merge($base, [
            'truck_id' => $secondTruck,
            'idempotency_key' => (string) Str::uuid(), 'scheduled_at' => '2026-09-20 08:00:00', 'quantity_liters' => 4000.01,
        ]))->assertSessionHasErrors('lift');
        $this->assertDatabaseCount('hauls', 1);

        $this->actingAs($records['dispatch'])->post(route('dispatch.fuel-lifting.hauls.store'), array_merge($base, [
            'truck_id' => $secondTruck,
            'idempotency_key' => (string) Str::uuid(), 'scheduled_at' => '2026-09-20 08:00:00', 'quantity_liters' => 4000,
        ]))->assertRedirect(route('dispatch.fuel-lifting'));
        $this->assertDatabaseCount('hauls', 2);
        $this->assertSame(10000.0, (float) DB::table('hauls')->sum('quantity_liters'));
        $this->assertSame(0, DB::table('hauls')->where('status', 'cancelled')->count());
    }

    public function test_dispatch_rejects_mixed_depots_in_one_schedule(): void
    {
        $records = $this->records();
        [$first, $second] = $this->purchases($records, [10000, 10000]);
        $otherDepot = DB::table('depots')->insertGetId([
            'depot_code' => 'DEP-OTHER', 'name' => 'Other Terminal', 'address' => 'Other Road', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('purchases')->where('id', DB::table('purchase_items')->where('id', $second)->value('purchase_id'))
            ->update(['depot_id' => $otherDepot]);

        $this->actingAs($records['dispatch'])->from(route('dispatch.fuel-lifting'))->post(route('dispatch.fuel-lifting.hauls.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [
                ['purchase_item_id' => $first, 'quantity_liters' => 5000],
                ['purchase_item_id' => $second, 'quantity_liters' => 5000],
            ],
            'driver_user_id' => $records['driver']->id,
            'truck_id' => $records['truck'],
            'scheduled_at' => '2026-09-19 08:00:00',
        ])->assertSessionHasErrors('lift');

        $this->assertDatabaseCount('lifting_schedules', 0);
        $this->assertDatabaseCount('hauls', 0);
    }

    public function test_duplicate_purchase_and_combined_capacity_are_rejected_without_partial_rows(): void
    {
        $records = $this->records();
        [$first, $second] = $this->purchases($records, [20000, 20000]);
        $base = [
            'driver_user_id' => $records['driver']->id,
            'truck_id' => $records['truck'],
            'scheduled_at' => '2026-09-19 08:00:00',
        ];

        $this->actingAs($records['dispatch'])->from(route('dispatch.fuel-lifting'))->post(route('dispatch.fuel-lifting.hauls.store'), $base + [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [
                ['purchase_item_id' => $first, 'quantity_liters' => 1000],
                ['purchase_item_id' => $first, 'quantity_liters' => 1000],
            ],
        ])->assertSessionHasErrors('items.1.purchase_item_id');

        $this->actingAs($records['dispatch'])->from(route('dispatch.fuel-lifting'))->post(route('dispatch.fuel-lifting.hauls.store'), $base + [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [
                ['purchase_item_id' => $first, 'quantity_liters' => 16000],
                ['purchase_item_id' => $second, 'quantity_liters' => 16000],
            ],
        ])->assertSessionHasErrors('lift');

        $this->actingAs($records['dispatch'])->from(route('dispatch.fuel-lifting'))->post(route('dispatch.fuel-lifting.hauls.store'), $base + [
            'idempotency_key' => (string) Str::uuid(),
            'items' => [
                ['purchase_item_id' => $first, 'quantity_liters' => 20000.01],
                ['purchase_item_id' => $second, 'quantity_liters' => 1000],
            ],
        ])->assertSessionHasErrors('lift');

        $this->assertDatabaseCount('lifting_schedules', 0);
        $this->assertDatabaseCount('hauls', 0);
    }

    public function test_driver_pickup_uses_official_depot_name_and_address(): void
    {
        $records = $this->records();
        [$item] = $this->purchases($records, [10000]);

        $this->actingAs($records['dispatch'])->post(route('dispatch.fuel-lifting.hauls.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'purchase_item_id' => $item,
            'quantity_liters' => 5000,
            'source_location' => 'Shell',
            'driver_user_id' => $records['driver']->id,
            'truck_id' => $records['truck'],
            'scheduled_at' => '2026-09-19 08:00:00',
        ])->assertRedirect();

        $this->actingAs($records['driver'])->get(route('driver.fuel-lifting'))
            ->assertOk()
            ->assertSee('Southern Terminal')
            ->assertSee('Official Depot Road')
            ->assertDontSee('Shell');
    }

    private function records(): array
    {
        $inventory = User::factory()->create(['role' => 'inventory_officer', 'status' => 'active', 'approval_status' => 'approved']);
        $dispatch = User::factory()->create(['role' => 'dispatch_officer', 'status' => 'active', 'approval_status' => 'approved']);
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active', 'approval_status' => 'approved']);
        DB::table('driver_profiles')->insert(['user_id' => $driver->id, 'driver_code' => 'DRV-CW', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        $depot = DB::table('depots')->insertGetId(['depot_code' => 'DEP-CW', 'name' => 'Southern Terminal', 'address' => 'Official Depot Road', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $fuel = DB::table('fuel_types')->where('code', 'DSL')->value('id') ?: DB::table('fuel_types')->insertGetId(['code' => 'DSL', 'name' => 'Diesel', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $truck = DB::table('trucks')->insertGetId(['truck_code' => 'TRK-CW', 'capacity_liters' => 30000, 'truck_type' => 'hauling', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);

        return compact('inventory', 'dispatch', 'driver', 'depot', 'fuel', 'truck');
    }

    private function purchases(array $records, array $quantities): array
    {
        return collect($quantities)->map(function (int $quantity, int $index) use ($records): int {
            $purchase = DB::table('purchases')->insertGetId([
                'purchase_code' => 'PUR-CW-'.($index + 1), 'depot_id' => $records['depot'], 'purchase_date' => '2026-09-18',
                'payment_status' => 'unpaid', 'status' => 'ordered', 'workflow_status' => 'pending', 'created_by' => $records['inventory']->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return DB::table('purchase_items')->insertGetId([
                'purchase_id' => $purchase, 'fuel_type_id' => $records['fuel'], 'quantity_ordered_liters' => $quantity,
                'unit_cost' => 50, 'line_total' => $quantity * 50, 'quantity_hauled_liters' => 0, 'status' => 'unlifted',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        })->all();
    }
}
