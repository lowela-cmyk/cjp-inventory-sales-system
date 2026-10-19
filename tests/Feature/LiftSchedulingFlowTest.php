<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * Schedule Lift: rebuilt modal markup, active-driver dropdown, field-level errors, end-to-end save
 * (dispatch table + driver page), and a pickup-depot label that is plain UTF-8 (no "â€”" mojibake).
 */
class LiftSchedulingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_schedule_lift_modal_layout_lists_active_drivers_and_builds_the_depot_label_server_side(): void
    {
        $r = $this->records();
        $inactive = User::factory()->create(['name' => 'Inactive Driver', 'role' => 'driver', 'status' => 'inactive', 'approval_status' => 'approved']);

        foreach ([[$r['dispatch'], 'dispatch.fuel-lifting'], [$r['admin'], 'admin.fuel-lifting']] as [$user, $route]) {
            $html = $this->actingAs($user)->get(route($route))->assertOk()->getContent();

            $this->assertStringContainsString('class="admin-modal admin-modal-xl"', $html);
            $this->assertStringContainsString('<div class="modal-footer">', $html);
            $this->assertStringContainsString('form="lift-schedule-form">Add Schedule</button>', $html);
            $this->assertStringContainsString('class="lift-line" data-lifting-item', $html);
            $this->assertStringContainsString('<option value="'.$r['driver']->id.'"', $html);
            $this->assertStringNotContainsString('Inactive Driver', $html);
            $this->assertStringContainsString('data-pickup="Batangas Energy Terminal '."\u{2014}".' Shell Tabangao, Batangas City"', $html);
            $this->assertStringNotContainsString("\u{00e2}\u{20ac}", $html, 'Mojibake must not appear anywhere on the page.');
            $this->assertStringContainsString('PUR-LIFT-1 | 100,000.00 L remaining | Unleaded | Batangas Energy Terminal', $html);
        }
        $this->assertNotNull($inactive);
    }

    public function test_validation_errors_reopen_the_modal_and_show_under_the_matching_field(): void
    {
        $r = $this->records();

        $this->actingAs($r['dispatch'])->from(route('dispatch.fuel-lifting'))->post(route('dispatch.fuel-lifting.hauls.store'), [
            'idempotency_key' => (string) Str::uuid(), 'form_context' => 'schedule-lift',
            'items' => [['purchase_item_id' => $r['item'], 'quantity_liters' => 1000]],
            'truck_id' => $r['truck'], 'scheduled_at' => '2026-10-15 08:00:00',
        ])->assertSessionHasErrors(['driver_user_id' => 'The driver field is required.']);

        $html = $this->actingAs($r['dispatch'])->withSession([
            'errors' => (new ViewErrorBag)->put('default', new MessageBag(['driver_user_id' => ['The driver field is required.']])),
            '_old_input' => ['form_context' => 'schedule-lift', 'truck_id' => (string) $r['truck'], 'items' => [['purchase_item_id' => (string) $r['item'], 'quantity_liters' => '1000']]],
        ])->get(route('dispatch.fuel-lifting'))->assertOk()->getContent();

        $this->assertStringContainsString('data-open-modal-on-load="dispatch-lift-add"', $html);
        $this->assertMatchesRegularExpression('/id="dispatch_driver_user_id".*?<\/select>\s*<p class="field-error">The driver field is required\.<\/p>/s', $html);
        $this->assertMatchesRegularExpression('/<option value="'.$r['item'].'"[^>]*selected/', $html);
    }

    public function test_dispatch_and_admin_schedule_a_lift_end_to_end_and_the_driver_sees_it(): void
    {
        $r = $this->records();

        $this->actingAs($r['dispatch'])->post(route('dispatch.fuel-lifting.hauls.store'), [
            'idempotency_key' => (string) Str::uuid(), 'form_context' => 'schedule-lift', 'dr_number' => 'DR-E2E-1',
            'items' => [['purchase_item_id' => $r['item'], 'quantity_liters' => 15000]],
            'driver_user_id' => $r['driver']->id, 'truck_id' => $r['truck'], 'scheduled_at' => '2026-10-15 08:00:00',
        ])->assertSessionHasNoErrors()->assertRedirect(route('dispatch.fuel-lifting'));

        $scheduleCode = (string) DB::table('lifting_schedules')->value('schedule_code');
        $this->actingAs($r['dispatch'])->get(route('dispatch.fuel-lifting'))->assertOk()->assertSee($scheduleCode)->assertSee('DR-E2E-1');
        $this->actingAs($r['driver'])->get(route('driver.fuel-lifting'))->assertOk()
            ->assertSee(DB::table('hauls')->value('haul_code'))
            ->assertSee('Batangas Energy Terminal '."\u{2014}".' Shell Tabangao, Batangas City');

        // Admin schedules the remaining quantity from the admin page.
        $truck2 = DB::table('trucks')->insertGetId(['truck_code' => 'TRK-E2E-2', 'capacity_liters' => 90000, 'truck_type' => 'hauling', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($r['admin'])->post(route('admin.fuel-lifting.hauls.store'), [
            'idempotency_key' => (string) Str::uuid(), 'items' => [['purchase_item_id' => $r['item'], 'quantity_liters' => 85000]],
            'driver_user_id' => $r['driver']->id, 'truck_id' => $truck2, 'scheduled_at' => '2026-10-16 08:00:00',
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.fuel-lifting'));

        $this->assertSame(100000.0, (float) DB::table('hauls')->sum('quantity_liters'));
        $this->actingAs($r['admin'])->get(route('admin.fuel-lifting'))->assertOk()->assertSee('No purchases have remaining unlifted quantity');
    }

    /**
     * @return array<string, mixed>
     */
    private function records(): array
    {
        $dispatch = User::factory()->create(['role' => 'dispatch_officer', 'status' => 'active', 'approval_status' => 'approved']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);
        $driver = User::factory()->create(['name' => 'Active Driver', 'role' => 'driver', 'status' => 'active', 'approval_status' => 'approved']);
        DB::table('driver_profiles')->insert(['user_id' => $driver->id, 'driver_code' => 'DRV-001', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        $depot = DB::table('depots')->insertGetId(['depot_code' => 'DEP-BAT', 'name' => 'Batangas Energy Terminal', 'address' => 'Shell Tabangao, Batangas City', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $fuel = (int) DB::table('fuel_types')->where('code', 'UNL')->value('id');
        $truck = DB::table('trucks')->insertGetId(['truck_code' => 'TRK-001', 'plate_number' => 'NFD-4821', 'capacity_liters' => 40000, 'truck_type' => 'hauling', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        $purchase = DB::table('purchases')->insertGetId(['purchase_code' => 'PUR-LIFT-1', 'depot_id' => $depot, 'purchase_date' => '2026-10-12', 'payment_status' => 'unpaid', 'status' => 'ordered', 'workflow_status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $item = DB::table('purchase_items')->insertGetId(['purchase_id' => $purchase, 'fuel_type_id' => $fuel, 'quantity_ordered_liters' => 100000, 'unit_cost' => 50, 'line_total' => 5000000, 'quantity_hauled_liters' => 0, 'status' => 'unlifted', 'created_at' => now(), 'updated_at' => now()]);

        return compact('dispatch', 'admin', 'driver', 'depot', 'fuel', 'truck', 'item');
    }
}
