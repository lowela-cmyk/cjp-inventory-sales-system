<?php

namespace Tests\Feature;

use App\Services\LiftAllocationService;
use App\Services\PurchaseStockService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PurchaseFlows;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Part 11 (October 26, 2026): Admin and Dispatch can delete a lift that is still Scheduled. Deleting undoes the
 * scheduling (depot liters of every purchase on the lift, F1 included; its Depot to Client orders; the truck) and
 * soft-deletes the lift, which drops out of every list and count. Lifts In Transit, Lifted, Completed or Cancelled
 * cannot be deleted, and drivers cannot delete.
 */
class LiftDeleteTest extends AuditTestCase
{
    use PurchaseFlows;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-26 09:00:00');
    }

    public function test_deleting_a_scheduled_lift_frees_its_liters_orders_and_truck(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $f1 = $this->purchaseLine($a, 'F1', 5000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 3000, 'unit_price' => 50, 'f1_percentage' => 10, 'f1_purchase_item_id' => $f1, 'f1_unit_price' => 40], [
            'delivery_type' => 'depot_to_client', 'sales_order_number' => 'SO-DEL',
        ])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $before = ['premium' => $this->depot($premium), 'f1' => $this->depot($f1), 'premium_available' => $this->available($premium), 'f1_available' => $this->available($f1)];

        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 10000], ['purchase_item_id' => $f1, 'quantity_liters' => 2000]], [$saleId])->assertSessionHasNoErrors();
        $haulId = $this->lastHaulId();
        $code = (string) DB::table('lifting_schedules')->value('schedule_code');
        $this->assertSame([$before['premium'] - 10000, $before['f1'] - 2000], [$this->depot($premium), $this->depot($f1)]);
        $this->assertSame('assigned', DB::table('trucks')->where('id', $a['truck'])->value('status'));
        $this->assertFalse(app(LiftAllocationService::class)->directSaleOptions()->contains('sale_id', $saleId));

        // The Schedule table and the View modal offer Delete with the confirmation.
        $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting'))->assertOk()
            ->assertSee('action="'.route('dispatch.fuel-lifting.hauls.destroy', $haulId).'"', false)
            ->assertSee('data-confirm-message="Delete lift '.$code.'? This can\'t be undone."', false)
            ->assertSee('btn btn-danger-outline btn-pill', false);

        $this->actingAs($a['dispatch'])->delete(route('dispatch.fuel-lifting.hauls.destroy', $haulId))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dispatch.fuel-lifting'))
            ->assertSessionHas('status', 'Lift '.$code.' deleted. Its liters, orders and truck are free again.');

        // Liters back in the depot (F1 purchase too), the order free for another lift, the truck Available.
        $this->assertSame([$before['premium'], $before['f1']], [$this->depot($premium), $this->depot($f1)]);
        $this->assertSame([$before['premium_available'], $before['f1_available']], [$this->available($premium), $this->available($f1)]);
        $this->assertTrue(app(LiftAllocationService::class)->directSaleOptions()->contains('sale_id', $saleId));
        $this->assertSame(['cancelled'], DB::table('haul_allocations')->pluck('status')->unique()->values()->all());
        $this->assertSame('available', DB::table('trucks')->where('id', $a['truck'])->value('status'));
        $this->assertSame(0, DB::table('purchases')->whereIn('workflow_status', ['scheduled'])->count());

        // Soft delete: rows kept with who deleted them, plus the request in the audit log.
        $this->assertSame(2, DB::table('hauls')->whereNotNull('deleted_at')->where('deleted_by', $a['dispatch']->id)->count());
        $this->assertDatabaseHas('lifting_schedules', ['schedule_code' => $code, 'status' => 'cancelled', 'deleted_by' => $a['dispatch']->id]);
        $this->assertDatabaseHas('activity_logs', ['user_id' => $a['dispatch']->id, 'route_name' => 'dispatch.fuel-lifting.hauls.destroy', 'method' => 'DELETE', 'succeeded' => true]);

        // Gone from every list and count: Schedule / Hauled tabs, KPI cards, driver page.
        // (The success toast still names the lift on this first page load, so check the table rows, not the page.)
        $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting.hauled'))->assertOk()
            ->assertViewHas('scheduledRows', fn ($rows): bool => $rows->isEmpty())
            ->assertViewHas('deliveredRows', fn ($rows): bool => $rows->isEmpty())
            ->assertViewHas('summaryCards', fn (array $cards): bool => collect($cards)->pluck('value', 'label')->get('Assigned Lifts') === '0');
        $this->actingAs($a['driver'])->get(route('driver.fuel-lifting'))->assertOk()->assertDontSee($code)
            ->assertViewHas('summaryCards', fn (array $cards): bool => collect($cards)->pluck('value', 'label')->get('Assigned Lifts') === '0');

        // The order and the truck can go on a new lift right away.
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 20000]], [$saleId])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('haul_allocations')->where('sale_id', $saleId)->where('status', 'planned')->count());
    }

    public function test_admin_can_delete_and_a_lift_past_scheduled_or_cancelled_cannot_be_deleted(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);

        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 5000]])->assertSessionHasNoErrors();
        $this->actingAs($a['admin'])->delete(route('admin.fuel-lifting.hauls.destroy', $this->lastHaulId()))
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.fuel-lifting'));
        $this->assertNotNull(DB::table('hauls')->where('id', $this->lastHaulId())->value('deleted_at'));
        // A deleted lift is gone: deleting it again is a 404.
        $this->actingAs($a['admin'])->delete(route('admin.fuel-lifting.hauls.destroy', $this->lastHaulId()))->assertNotFound();

        // In Transit: refused (422), nothing changes, no Delete button.
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 5000]])->assertSessionHasNoErrors();
        $inTransit = $this->lastHaulId();
        $this->actingAs($a['driver'])->patch(route('driver.fuel-lifting.hauls.status', $inTransit), ['idempotency_key' => (string) Str::uuid(), 'lifting_status' => 'in_transit'])->assertSessionHasNoErrors();
        $this->actingAs($a['dispatch'])->deleteJson(route('dispatch.fuel-lifting.hauls.destroy', $inTransit))
            ->assertStatus(422)->assertJsonValidationErrors(['lifting' => 'Only a Scheduled lift can be deleted.']);
        $this->actingAs($a['dispatch'])->from(route('dispatch.fuel-lifting'))->delete(route('dispatch.fuel-lifting.hauls.destroy', $inTransit))
            ->assertRedirect(route('dispatch.fuel-lifting'))->assertSessionHasErrors('lifting');
        $this->assertDatabaseHas('hauls', ['id' => $inTransit, 'status' => 'in_transit', 'deleted_at' => null]);
        $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting'))->assertOk()
            ->assertDontSee('action="'.route('dispatch.fuel-lifting.hauls.destroy', $inTransit).'"', false);

        // Lifted / Completed: refused.
        $this->actingAs($a['driver'])->patch(route('driver.fuel-lifting.hauls.status', $inTransit), ['idempotency_key' => (string) Str::uuid(), 'lifting_status' => 'lifted'])->assertSessionHasNoErrors();
        $this->actingAs($a['admin'])->patch(route('admin.fuel-lifting.hauls.status', $inTransit), ['idempotency_key' => (string) Str::uuid(), 'status' => 'completed'])->assertSessionHasNoErrors();
        $this->actingAs($a['admin'])->deleteJson(route('admin.fuel-lifting.hauls.destroy', $inTransit))->assertStatus(422);
        $this->assertDatabaseHas('hauls', ['id' => $inTransit, 'status' => 'completed', 'deleted_at' => null]);
        // Part 12: its 5,000 L are still on the truck (Loaded); returning them frees the truck for the next lift.
        $this->actingAs($a['admin'])->post(route('admin.fuel-lifting.hauls.return-to-depot', $inTransit))->assertSessionHasNoErrors();

        // Cancelled: refused, and no Delete button.
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 5000]])->assertSessionHasNoErrors();
        $cancelled = $this->lastHaulId();
        $this->actingAs($a['dispatch'])->patch(route('dispatch.fuel-lifting.hauls.status', $cancelled), ['idempotency_key' => (string) Str::uuid(), 'status' => 'cancelled'])->assertSessionHasNoErrors();
        $this->actingAs($a['dispatch'])->deleteJson(route('dispatch.fuel-lifting.hauls.destroy', $cancelled))
            ->assertStatus(422)->assertJsonValidationErrors(['lifting' => 'This lift is already cancelled.']);
        $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting.hauled'))->assertOk()
            ->assertDontSee('action="'.route('dispatch.fuel-lifting.hauls.destroy', $cancelled).'"', false);
    }

    public function test_drivers_and_other_roles_cannot_delete_a_lift(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 5000]])->assertSessionHasNoErrors();
        $haulId = $this->lastHaulId();

        foreach (['driver', 'sales', 'inventory'] as $role) {
            $this->actingAs($a[$role])->delete(route('dispatch.fuel-lifting.hauls.destroy', $haulId))->assertForbidden();
            $this->actingAs($a[$role])->delete(route('admin.fuel-lifting.hauls.destroy', $haulId))->assertForbidden();
        }
        // The driver's page has no delete route at all.
        $this->actingAs($a['driver'])->delete('/driver/fuel-lifting/hauls/'.$haulId)->assertNotFound();

        $this->assertDatabaseHas('hauls', ['id' => $haulId, 'status' => 'scheduled', 'deleted_at' => null]);
        $this->actingAs($a['driver'])->get(route('driver.fuel-lifting'))->assertOk()->assertDontSee('btn-danger-outline', false);
    }

    /** Liters of the purchase still in the depot (not on a lift). */
    private function depot(int $purchaseItemId): float
    {
        return (float) app(PurchaseStockService::class)->line($purchaseItemId)->remaining_in_depot;
    }
}
