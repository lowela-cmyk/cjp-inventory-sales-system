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
