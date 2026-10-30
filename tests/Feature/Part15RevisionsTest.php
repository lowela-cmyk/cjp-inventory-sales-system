<?php

namespace Tests\Feature;

use App\Services\DashboardSummaryService;
use App\Services\PurchaseService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PurchaseFlows;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Part 15 (October 30, 2026): Cancel Purchase only for a Pending, unused purchase; stock fields locked once lifted;
 * trucks named (never by plate) outside the Trucks tab; one fuel type per lift.
 */
class Part15RevisionsTest extends AuditTestCase
{
    use PurchaseFlows;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 09:00:00');
    }

    public function test_cancel_purchase_shows_only_for_an_unscheduled_unused_purchase_and_cancels_it(): void
    {
        $a = $this->actors();
        $pending = $this->purchaseLine($a, 'PREM', 10000);

        $modal = $this->purchaseModal($a['inventory'], 'inventory-officer.inventory', $pending);
        $this->assertStringContainsString('>Cancel Purchase</button>', $modal);
        $this->assertStringContainsString('data-modal-close>Close</button>', $modal);
        $this->assertStringNotContainsString('>Cancel</button>', $modal);

        $this->actingAs($a['inventory'])->from(route('inventory-officer.inventory'))
            ->patch(route('inventory-officer.inventory.purchases.cancel', $pending))
            ->assertSessionHasNoErrors()->assertRedirect(route('inventory-officer.inventory'));

        $purchase = DB::table('purchases')->where('id', DB::table('purchase_items')->where('id', $pending)->value('purchase_id'))->first();
        $this->assertSame(['cancelled', 'cancelled'], [$purchase->status, $purchase->workflow_status]);
        $this->assertDatabaseHas('activity_logs', ['route_name' => 'inventory-officer.inventory.purchases.cancel', 'user_id' => $a['inventory']->id, 'succeeded' => true]);

        // A cancelled purchase: red row, no Cancel Purchase, gone from Schedule Lift, Record Sales and the totals.
        $rows = collect($this->actingAs($a['inventory'])->get(route('inventory-officer.inventory'))->viewData('purchases')->items());
        $this->assertSame('row-danger', $rows->firstWhere('id', $pending)['class']);
        $this->assertStringNotContainsString('Cancel Purchase', $this->purchaseModal($a['inventory'], 'inventory-officer.inventory', $pending));
        $this->assertStringNotContainsString('<option value="'.$pending.'" data-depot-id', $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting'))->getContent());
        $this->assertFalse(collect($this->actingAs($a['sales'])->get(route('sales-officer.sales'))->viewData('purchaseOptions') ?? [])->contains('id', $pending));
        $this->assertSame(0.0, app(DashboardSummaryService::class)->totalInventoryLiters());

        // Same rules from the Admin pages.
        $adminPending = $this->purchaseLine($a, 'DSL', 5000);
        $this->assertStringContainsString('>Cancel Purchase</button>', $this->purchaseModal($a['admin'], 'admin.inventory', $adminPending));
        $this->actingAs($a['admin'])->from(route('admin.inventory'))
            ->patch(route('admin.inventory.purchases.cancel', $adminPending))->assertSessionHasNoErrors();
        $this->assertSame('cancelled', DB::table('purchases')->where('id', DB::table('purchase_items')->where('id', $adminPending)->value('purchase_id'))->value('status'));
    }

    public function test_cancel_purchase_is_hidden_and_refused_once_scheduled_lifted_completed_or_sold_from(): void
    {
        $a = $this->actors();
        $scheduled = $this->purchaseLine($a, 'PREM', 10000);
        $this->scheduleLift($a, [['purchase_item_id' => $scheduled, 'quantity_liters' => 10000]])->assertSessionHasNoErrors();

        $sold = $this->purchaseLine($a, 'UNL', 10000);
        $this->recordOrder($a, ['purchase_item_id' => $sold, 'quantity_liters' => 1000, 'unit_price' => 60])->assertSessionHasNoErrors();

        $completed = $this->purchaseLine($a, 'DSL', 8000);
        $truck = DB::table('trucks')->insertGetId(['truck_code' => 'TRK-P15', 'capacity_liters' => 30000, 'truck_type' => 'hauling', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        $this->scheduleLift($a, [['purchase_item_id' => $completed, 'quantity_liters' => 8000]], [], ['truck_id' => $truck])->assertSessionHasNoErrors();
        $haulId = $this->lastHaulId();
        $this->completeLift($a, $haulId);
        $this->actingAs($a['dispatch'])->patch(route('dispatch.fuel-lifting.hauls.status', $haulId), ['idempotency_key' => (string) Str::uuid(), 'status' => 'completed'])
            ->assertSessionHasNoErrors();
        $this->assertSame('completed', DB::table('purchases')->where('id', DB::table('purchase_items')->where('id', $completed)->value('purchase_id'))->value('workflow_status'));

        foreach ([$scheduled, $sold, $completed] as $id) {
            $this->assertStringNotContainsString('Cancel Purchase', $this->purchaseModal($a['inventory'], 'inventory-officer.inventory', $id));

            // Bypassing the button: refused with 422, nothing changes.
            $this->actingAs($a['inventory'])->patchJson(route('inventory-officer.inventory.purchases.cancel', $id))
                ->assertStatus(422)->assertJsonValidationErrors('purchase');
            $this->assertNotSame('cancelled', DB::table('purchases')->where('id', DB::table('purchase_items')->where('id', $id)->value('purchase_id'))->value('status'));
        }

        // The form gets the reason as an error; the attempt is in the audit log as not succeeded.
        $this->actingAs($a['inventory'])->from(route('inventory-officer.inventory'))
            ->patch(route('inventory-officer.inventory.purchases.cancel', $scheduled))->assertSessionHasErrors('purchase');
        $this->assertDatabaseHas('activity_logs', ['route_name' => 'inventory-officer.inventory.purchases.cancel', 'succeeded' => false]);

        // A cancelled lift no longer blocks it.
        $this->actingAs($a['dispatch'])->patch(route('dispatch.fuel-lifting.hauls.status', DB::table('hauls')->where('purchase_item_id', $scheduled)->value('id')), [
            'idempotency_key' => (string) Str::uuid(), 'status' => 'cancelled',
        ])->assertSessionHasNoErrors();
        $this->assertStringContainsString('>Cancel Purchase</button>', $this->purchaseModal($a['inventory'], 'inventory-officer.inventory', $scheduled));
    }

    public function test_lifted_purchase_locks_stock_fields_and_keeps_payment_fields_editable(): void
    {
        $a = $this->actors();
        $lifted = $this->purchaseLine($a, 'PREM', 10000, 50);
        $this->scheduleLift($a, [['purchase_item_id' => $lifted, 'quantity_liters' => 6000]])->assertSessionHasNoErrors();
        $this->completeLift($a, $this->lastHaulId());

        $modal = $this->purchaseModal($a['inventory'], 'inventory-officer.inventory', $lifted);
        $this->assertStringContainsString(PurchaseService::LOCKED_MESSAGE, $modal);
        foreach (['purchase_date', 'fuel_type_id', 'depot_id', 'quantity_ordered_liters', 'unit_cost'] as $field) {
            $this->assertStringContainsString('data-locked-field="'.$field.'"', $modal);
        }
        $this->assertStringNotContainsString('type="date"', $modal);
        $this->assertStringContainsString('name="payment_status"', $modal);

        $base = ['purchase_date' => '2026-10-14', 'depot_id' => $a['depot'], 'fuel_type_id' => $this->fuel('PREM'), 'quantity_ordered_liters' => 10000, 'unit_cost' => 50, 'payment_status' => 'unpaid', 'payment_mode' => 'cash'];

        // Changing locked fields is rejected on the server, field by field.
        $this->actingAs($a['inventory'])->from(route('inventory-officer.inventory'))
            ->patch(route('inventory-officer.inventory.purchases.update', $lifted), array_merge($base, ['purchase_date' => '2026-10-13', 'unit_cost' => 55, 'quantity_ordered_liters' => 12000, 'fuel_type_id' => $this->fuel('DSL')]))
            ->assertSessionHasErrors(['purchase_date' => PurchaseService::LOCKED_MESSAGE, 'unit_cost' => PurchaseService::LOCKED_MESSAGE, 'quantity_ordered_liters' => PurchaseService::LOCKED_MESSAGE, 'fuel_type_id' => PurchaseService::LOCKED_MESSAGE]);
        $this->assertDatabaseHas('purchase_items', ['id' => $lifted, 'unit_cost' => 50, 'quantity_ordered_liters' => 10000]);

        // Edit Stock-In is locked the same way.
        $this->actingAs($a['inventory'])->from(route('inventory-officer.inventory.stock-in'))
            ->patch(route('inventory-officer.inventory.stock-in.update', $lifted), array_merge($base, ['quantity_ordered_liters' => 9000]))
            ->assertSessionHasErrors(['quantity_ordered_liters' => PurchaseService::LOCKED_MESSAGE]);

        // Payment Status, Payment Mode and Bank stay editable.
        $this->actingAs($a['inventory'])->from(route('inventory-officer.inventory'))
            ->patch(route('inventory-officer.inventory.purchases.update', $lifted), array_merge($base, ['payment_status' => 'paid', 'payment_mode' => 'bank_transfer', 'bank_name' => 'BDO']))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('purchases', ['id' => DB::table('purchase_items')->where('id', $lifted)->value('purchase_id'), 'payment_status' => 'paid', 'payment_mode' => 'bank_transfer', 'bank_name' => 'BDO']);

        // A purchase not lifted yet stays fully editable, with the existing "not below what is sold" rule.
        $pending = $this->purchaseLine($a, 'UNL', 10000);
        $this->recordOrder($a, ['purchase_item_id' => $pending, 'quantity_liters' => 4000, 'unit_price' => 60])->assertSessionHasNoErrors();
        $pendingModal = $this->purchaseModal($a['inventory'], 'inventory-officer.inventory', $pending);
        $this->assertStringNotContainsString(PurchaseService::LOCKED_MESSAGE, $pendingModal);
        $this->assertStringContainsString('name="purchase_date" type="date"', $pendingModal);
        $pendingData = array_merge($base, ['fuel_type_id' => $this->fuel('UNL')]);
        $this->actingAs($a['inventory'])->from(route('inventory-officer.inventory'))
            ->patch(route('inventory-officer.inventory.purchases.update', $pending), array_merge($pendingData, ['quantity_ordered_liters' => 3000]))
            ->assertSessionHasErrors('quantity_ordered_liters');
        $this->actingAs($a['inventory'])->from(route('inventory-officer.inventory'))
            ->patch(route('inventory-officer.inventory.purchases.update', $pending), array_merge($pendingData, ['purchase_date' => '2026-10-12', 'unit_cost' => 52]))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('purchase_items', ['id' => $pending, 'unit_cost' => 52]);
    }

    public function test_truck_dropdowns_show_the_name_or_code_and_never_the_plate(): void
    {
        $a = $this->actors();
        DB::table('trucks')->where('id', $a['truck'])->update(['name' => 'Big Blue', 'plate_number' => 'XYZ-999']);
        $unnamed = DB::table('trucks')->insertGetId(['truck_code' => 'TRK-NONAME', 'plate_number' => 'NNN-111', 'capacity_liters' => 20000, 'truck_type' => 'hauling', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);

        $lifting = $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting'))->assertOk()->getContent();
        $form = $this->between($lifting, 'id="lift-schedule-form"', '</form>');
        $this->assertStringContainsString('>Big Blue – 30,000.00 L – Available</option>', $form);
        $this->assertStringContainsString('>TRK-NONAME – 20,000.00 L – Available</option>', $form);
        $this->assertStringContainsString('<option value="'.$unnamed.'" >TRK-NONAME</option>', $lifting);
        foreach (['XYZ-999', 'NNN-111'] as $plate) {
            $this->assertStringNotContainsString($plate, $form);
        }

        // Sales (Record / Edit Sales delivery truck) and Stock Out never show plates.
        $sales = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $this->assertStringContainsString('>Big Blue</option>', $sales);
        $this->assertStringNotContainsString('XYZ-999', $sales);
        $this->assertStringNotContainsString('XYZ-999', $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory.stock-out'))->getContent());

        // The plate stays in the Trucks tab.
        $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting.trucks'))->assertOk()->assertSee('XYZ-999');
    }

    public function test_a_lift_carries_one_fuel_type(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 10000);
        $premiumTwo = $this->purchaseLine($a, 'PREM', 10000);
        $diesel = $this->purchaseLine($a, 'DSL', 10000);

        // Every option carries its fuel and depot, so the form can list only the first purchase's fuel.
        $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting'))->assertOk()
            ->assertSee('data-fuel-type-id="'.$this->fuel('DSL').'"', false)
            ->assertSee('data-lifting-fuel-note', false);

        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 5000], ['purchase_item_id' => $diesel, 'quantity_liters' => 5000]])
            ->assertSessionHasErrors(['lift' => 'All purchases in one lift must be the same fuel type (F1 may be added).']);
        $this->assertSame(0, DB::table('hauls')->count());

        // One fuel, plus F1 as the blend portion, is fine.
        $f1 = $this->purchaseLine($a, 'F1', 5000);
        $this->assertStringContainsString('data-f1', $this->between($this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting'))->getContent(), '<option value="'.$f1.'"', '</option>'));
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 5000], ['purchase_item_id' => $premiumTwo, 'quantity_liters' => 5000], ['purchase_item_id' => $f1, 'quantity_liters' => 2000]])
            ->assertSessionHasNoErrors();
        $this->assertSame(3, DB::table('hauls')->count());
    }

    private function purchaseModal($user, string $route, int $purchaseItemId): string
    {
        $html = $this->actingAs($user)->get(route($route))->assertOk()->getContent();

        return $this->between($html, 'id="io-purchase-edit-'.$purchaseItemId.'"', 'class="modal-backdrop"');
    }

    private function between(string $html, string $start, string $end): string
    {
        $from = strpos($html, $start);
        $this->assertNotFalse($from, 'Missing '.$start);
        $to = strpos($html, $end, $from + strlen($start));

        return substr($html, $from, $to === false ? null : $to - $from);
    }
}
