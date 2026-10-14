<?php

namespace Tests\Feature\Audit;

use PHPUnit\Framework\Attributes\Group;
use Illuminate\Support\Facades\DB;

/**
 * AUD-C01 (CRITICAL): fuel lifted through the normal app flow can never become inventory.
 * Scheduling/completing a lift creates no haul_allocations row, so there is nothing to receive
 * into a garage tank (Stock-In) and nothing to release directly to a client.
 */
#[Group('audit')]
class C01LiftCompletionReceivableStockTest extends AuditTestCase
{
    public function test_purchase_to_completed_lift_produces_a_pending_garage_receipt_that_can_be_stocked_in(): void
    {
        $a = $this->actors();

        $this->actingAs($a['inventory'])->post(route('inventory-officer.inventory.purchases.store'), [
            'idempotency_key' => $this->uuid(), 'purchase_date' => '2026-10-10', 'depot_id' => $a['depot'], 'fuel_type_id' => $a['fuel'],
            'quantity_ordered_liters' => 10000, 'unit_cost' => 50, 'payment_status' => 'unpaid',
        ])->assertSessionHasNoErrors();
        $itemId = (int) DB::table('purchase_items')->value('id');

        $this->actingAs($a['dispatch'])->post(route('dispatch.fuel-lifting.hauls.store'), [
            'idempotency_key' => $this->uuid(), 'items' => [['purchase_item_id' => $itemId, 'quantity_liters' => 10000]],
            'driver_user_id' => $a['driver']->id, 'truck_id' => $a['truck'], 'scheduled_at' => '2026-10-11 08:00:00',
        ])->assertSessionHasNoErrors();
        $haulId = (int) DB::table('hauls')->value('id');

        foreach (['in_transit', 'lifted'] as $status) {
            $this->actingAs($a['driver'])->patch(route('driver.fuel-lifting.hauls.status', $haulId), ['idempotency_key' => $this->uuid(), 'lifting_status' => $status])->assertSessionHasNoErrors();
        }
        $this->actingAs($a['dispatch'])->patch(route('dispatch.fuel-lifting.hauls.status', $haulId), ['idempotency_key' => $this->uuid(), 'status' => 'completed'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('hauls', ['id' => $haulId, 'status' => 'completed']);

        // Expected: the completed lift has a destination allocation for its full quantity...
        $this->assertSame(10000.0, (float) DB::table('haul_allocations')->where('haul_id', $haulId)->where('status', '!=', 'cancelled')->sum('quantity_liters'),
            'A completed lift must carry a haul allocation (garage or client) for its lifted quantity.');

        // ...and a garage allocation shows up as a pending receipt the Inventory Officer can post.
        $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory'))
            ->assertOk()
            ->assertSee('Pending Garage Receipts');
    }
}
