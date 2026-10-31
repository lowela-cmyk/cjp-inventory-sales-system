<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\GarageStockService;
use App\Services\PurchaseStockService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\PurchaseFlows;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Part 16 (October 29, 2026 doc): F1 from the depot or the garage for every delivery type; one compact stock hint;
 * no duplicate purchase in a lift; a lift editable while Scheduled; the Garage tab per tank with View Tank Stock.
 */
class Part16RevisionsTest extends AuditTestCase
{
    use PurchaseFlows;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 09:00:00');
    }

    public function test_the_f1_dropdown_lists_f1_purchases_and_f1_garage_tanks_for_every_delivery_type(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $f1Depot = $this->purchaseLine($a, 'F1', 3000);
        $lot = $this->f1Lot($a, 2000);

        $form = $this->recordSalesForm($a);
        $f1Select = substr($form, strpos($form, 'data-sale-f1-purchase'), 3000);
        $this->assertStringContainsString('<option value="'.$f1Depot.'" data-source="depot"', $f1Select);
        $this->assertMatchesRegularExpression('/<option value="garage:'.$lot.'" data-source="garage" data-garage-f1[^>]*>[^<]*F1 Tank 1/', $f1Select);
        $this->assertStringNotContainsString('No F1 stock', $f1Select);

        // The server accepts either source whatever the delivery type, and checks the liters of the one picked.
        $premiumLot = $this->premiumLot($a);
        foreach (['standard', 'depot_to_client', 'garage_to_client'] as $type) {
            $main = $type === 'garage_to_client' ? 'garage:'.$premiumLot : $premium;
            $this->recordOrder($a, ['purchase_item_id' => $main, 'quantity_liters' => 1000, 'unit_price' => 50, 'f1_percentage' => 10, 'f1_purchase_item_id' => 'garage:'.$lot, 'f1_unit_price' => 40], ['delivery_type' => $type])
                ->assertSessionHasNoErrors();
            $this->assertSame($lot, (int) DB::table('sale_items')->where('sale_id', $this->lastSaleId())->value('f1_garage_stock_id'), $type);
            $this->recordOrder($a, ['purchase_item_id' => $main, 'quantity_liters' => 1000, 'unit_price' => 50, 'f1_percentage' => 10, 'f1_purchase_item_id' => $f1Depot, 'f1_unit_price' => 40], ['delivery_type' => $type])
                ->assertSessionHasNoErrors();
        }
        // 3 orders x 100 L promised from the 2,000 L lot: 1,700 L left; asking for more than that is refused.
        $this->assertSame(1700.0, app(GarageStockService::class)->lot($lot)->available);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 20000, 'unit_price' => 50, 'f1_percentage' => 10, 'f1_purchase_item_id' => 'garage:'.$lot, 'f1_unit_price' => 40])
            ->assertSessionHasErrors('items.0.f1_purchase_item_id');
    }

    public function test_a_depot_to_client_f1_portion_from_a_garage_lot_is_deducted_once_when_delivered(): void
    {
        // Client (Part 16 answer 2): trucks pass through the garage, so a Depot to Client order may take its F1 portion
        // from an F1 garage lot. It is held while the order is open, and leaves the tank once, when the lift delivers it.
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $lot = $this->f1Lot($a, 2000);
        $f1Tank = $this->tank('F1', 1);
        $tankContents = fn (): float => (float) app(GarageStockService::class)->tanks()->get($f1Tank)->contents;

        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 4000, 'unit_price' => 50, 'f1_percentage' => 10, 'f1_purchase_item_id' => 'garage:'.$lot, 'f1_unit_price' => 40], ['delivery_type' => 'depot_to_client'])
            ->assertSessionHasNoErrors();
        $order = $this->lastSaleId();

        // Open order: the 400 L are held (not available to other orders) but still in the tank.
        $this->assertSame([2000.0, 1600.0, 2000.0], [app(GarageStockService::class)->lot($lot)->in_garage, app(GarageStockService::class)->lot($lot)->available, $tankContents()]);

        // The lift carries the order's Premium; the driver's Lifted delivers the order.
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 4000]], [$order])->assertSessionHasNoErrors();
        $haulId = $this->lastHaulId();
        $this->assertSame(2000.0, $tankContents(), 'scheduling deducts nothing');
        $this->completeLift($a, $haulId);
        $this->assertSame('delivered', DB::table('sales')->where('id', $order)->value('delivery_status'));

        $f1Release = fn () => DB::table('stock_outs')->where('sale_id', $order)->where('portion', 'f1')->where('status', 'released');
        $this->assertSame(1, $f1Release()->count());
        $this->assertDatabaseHas('stock_outs', ['sale_id' => $order, 'portion' => 'f1', 'source_type' => 'garage', 'garage_stock_id' => $lot, 'storage_location_id' => $f1Tank, 'quantity_liters' => 400, 'status' => 'released']);
        // Freed tank space: 2,000 - 400 = 1,600 L left, 24,000 - 1,600 = 22,400 L free.
        $this->assertSame(1600.0, $tankContents());
        $this->assertSame(22400.0, (float) app(GarageStockService::class)->tanks()->get($f1Tank)->free);
        $this->assertSame([1600.0, 1600.0], [app(GarageStockService::class)->lot($lot)->in_garage, app(GarageStockService::class)->lot($lot)->available]);

        // Only once: completing the lift afterwards releases nothing more.
        $this->actingAs($a['dispatch'])->patch(route('dispatch.fuel-lifting.hauls.status', $haulId), ['idempotency_key' => (string) Str::uuid(), 'status' => 'completed'])->assertSessionHasNoErrors();
        $this->assertSame(1, $f1Release()->count());
        $this->assertSame(1600.0, $tankContents());
    }

    public function test_the_tank_breakdown_is_each_purchases_share_of_the_tanks_current_stock(): void
    {
        // Client (Part 16 answer 1, option A): 1,500 L + 1,500 L in the tank = 50% / 50% (of the stock, not of 24,000 L).
        $a = $this->actors();
        $tank = $this->tank('DSL', 1);
        $first = $this->purchaseLine($a, 'DSL', 1500);
        $second = $this->purchaseLine($a, 'DSL', 1500);
        $this->liftAndUnload($a, $first, 1500, [$tank => 1500]);
        $this->liftAndUnload($a, $second, 1500, [$tank => 1500]);

        $row = app(GarageStockService::class)->tab()['tankRows']->firstWhere('id', $tank);
        $this->assertSame([50.0, 50.0], $row->purchases->pluck('percent')->all());
        $this->assertSame([1500.0, 1500.0], $row->purchases->pluck('liters')->all());
        $page = $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory.garage'))->getContent();
        $modal = substr($page, strpos($page, 'id="garage-tank-'.$tank.'"'), 6000);
        $this->assertSame(2, substr_count($modal, '<span>50%</span>'));
        $this->assertStringContainsString('Quantity: <strong>3,000.00 L</strong>', $modal);
    }

    public function test_the_stock_hint_is_one_line_outside_the_field_cells_with_one_entry_per_fuel(): void
    {
        $a = $this->actors();
        $this->premiumLot($a);
        $this->purchaseLine($a, 'DSL', 5000);

        $form = $this->recordSalesForm($a);
        $this->assertSame(1, substr_count($form, 'data-stock-hint '));
        // Not inside the Fuel Type cell: the cell ends before the hint starts.
        $cell = substr($form, strpos($form, 'for="sale_purchase_item_id_0"'));
        $this->assertGreaterThan(strpos($cell, '</select>'), strpos($cell, 'data-stock-hint '));
        $this->assertStringContainsString('<span data-stock-hint-fuel="PREM" hidden>Premium: 8,000 L in the garage (', $form);
        $this->assertStringNotContainsString('data-stock-hint-fuel="DSL"', $form, 'Diesel still has depot stock');
        $this->assertStringContainsString('<span data-stock-hint-summary>Not in the depot: Premium 8,000 L', $form);
    }

    public function test_a_purchase_cannot_be_added_twice_to_one_lift(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 5000], ['purchase_item_id' => $premium, 'quantity_liters' => 5000]])
            ->assertSessionHasErrors('items.1.purchase_item_id');
        $this->assertSame(0, DB::table('hauls')->count());
    }

    public function test_a_scheduled_lift_can_be_edited_under_the_schedule_lift_rules(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $premiumTwo = $this->purchaseLine($a, 'PREM', 6000);
        $f1 = $this->purchaseLine($a, 'F1', 3000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 4000, 'unit_price' => 50], ['delivery_type' => 'depot_to_client', 'sales_order_number' => 'SO-ONE'])->assertSessionHasNoErrors();
        $orderOne = $this->lastSaleId();
        $this->recordOrder($a, ['purchase_item_id' => $premiumTwo, 'quantity_liters' => 2000, 'unit_price' => 50], ['delivery_type' => 'depot_to_client', 'sales_order_number' => 'SO-TWO'])->assertSessionHasNoErrors();
        $orderTwo = $this->lastSaleId();
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 10000, 'atl_number' => 'ATL-1'], ['purchase_item_id' => $f1, 'quantity_liters' => 1000]], [$orderOne])->assertSessionHasNoErrors();
        $haulId = (int) DB::table('hauls')->orderBy('id')->value('id');
        $scheduleId = (int) DB::table('hauls')->where('id', $haulId)->value('lifting_schedule_id');
        $this->assertSame(10000.0, $this->depot($premium));

        // The View modal offers Edit while Scheduled (the lift's row is keyed by its newest line).
        $rowHaul = (int) DB::table('hauls')->where('lifting_schedule_id', $scheduleId)->max('id');
        $page = $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting'))->assertOk()->getContent();
        $this->assertStringContainsString('data-modal-swap="dispatch-haul-'.$rowHaul.'-edit">Edit</button>', $page);
        $this->assertStringContainsString('id="dispatch-haul-'.$rowHaul.'-edit-form"', $page);

        // Edit: another truck and driver, a new date and DR, Premium 10,000 -> 12,000 (its own 10,000 L count as
        // available), F1 removed, a second Premium purchase added, and the deliveries swapped.
        [$truck, $driver] = $this->secondTruckAndDriver();
        $this->editLift($a, $haulId, [
            ['purchase_item_id' => $premium, 'quantity_liters' => 12000, 'atl_number' => 'ATL-1B'],
            ['purchase_item_id' => $premiumTwo, 'quantity_liters' => 6000],
        ], [$orderOne, $orderTwo], ['truck_id' => $truck, 'driver_user_id' => $driver->id, 'scheduled_at' => '2026-10-18 07:30:00', 'dr_number' => 'DR-EDITED'])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Lift LFT-PREM-001 updated.');

        $this->assertDatabaseHas('lifting_schedules', ['id' => $scheduleId, 'truck_id' => $truck, 'driver_user_id' => $driver->id, 'dr_number' => 'DR-EDITED', 'scheduled_at' => '2026-10-18 07:30:00']);
        $lines = DB::table('hauls')->where('lifting_schedule_id', $scheduleId)->whereNull('deleted_at')->orderBy('id')->get(['purchase_item_id', 'quantity_liters', 'atl_number', 'haul_code', 'truck_id']);
        $this->assertSame([[$premium, 12000.0, 'ATL-1B', 'LFT-PREM-001-01'], [$premiumTwo, 6000.0, null, 'LFT-PREM-001-03']], $lines->map(fn ($l): array => [(int) $l->purchase_item_id, (float) $l->quantity_liters, $l->atl_number, $l->haul_code])->all());
        $this->assertSame([$truck], $lines->pluck('truck_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());
        $this->assertSame('cancelled', DB::table('hauls')->where('lifting_schedule_id', $scheduleId)->where('purchase_item_id', $f1)->value('status'));
        // Stock follows: Premium 20,000 - 12,000 = 8,000 in the depot; F1 back to 3,000; the second purchase fully on the lift.
        $this->assertSame([8000.0, 3000.0, 0.0], [$this->depot($premium), $this->depot($f1), $this->depot($premiumTwo)]);
        $this->assertSame([$orderOne, $orderTwo], DB::table('haul_allocations')->where('status', 'planned')->orderBy('sale_id')->pluck('sale_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame('assigned', DB::table('trucks')->where('id', $truck)->value('status'));
        $this->assertNotSame('assigned', DB::table('trucks')->where('id', $a['truck'])->value('status'));
        $this->assertDatabaseHas('activity_logs', ['route_name' => 'dispatch.fuel-lifting.hauls.lift', 'succeeded' => true]);

        // The same checks as Schedule Lift: depot stock (own liters included), mixed fuels, duplicates.
        $this->editLift($a, $haulId, [['purchase_item_id' => $premium, 'quantity_liters' => 20001]], [], ['truck_id' => $truck, 'driver_user_id' => $driver->id])->assertSessionHasErrors('lift');
        $this->editLift($a, $haulId, [['purchase_item_id' => $premium, 'quantity_liters' => 1000], ['purchase_item_id' => $premium, 'quantity_liters' => 1000]], [], ['truck_id' => $truck, 'driver_user_id' => $driver->id])->assertSessionHasErrors('items.1.purchase_item_id');
        $diesel = $this->purchaseLine($a, 'DSL', 5000);
        $this->editLift($a, $haulId, [['purchase_item_id' => $premium, 'quantity_liters' => 1000], ['purchase_item_id' => $diesel, 'quantity_liters' => 1000]], [], ['truck_id' => $truck, 'driver_user_id' => $driver->id])
            ->assertSessionHasErrors(['lift' => 'All purchases in one lift must be the same fuel type (F1 may be added).']);
        $this->assertSame(8000.0, $this->depot($premium), 'a refused edit changes nothing');

        // A lift whose fuel changes gets the next Lift ID of that fuel.
        $this->editLift($a, $haulId, [['purchase_item_id' => $diesel, 'quantity_liters' => 5000]], [], ['truck_id' => $truck, 'driver_user_id' => $driver->id])
            ->assertSessionHasNoErrors()->assertSessionHas('status', 'Lift LFT-DSL-001 updated.');
        $this->assertSame('LFT-DSL-001-04', DB::table('hauls')->where('lifting_schedule_id', $scheduleId)->whereNull('deleted_at')->value('haul_code'));
    }

    public function test_a_lift_is_read_only_once_in_transit(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 10000]])->assertSessionHasNoErrors();
        $haulId = $this->lastHaulId();
        $this->actingAs($a['driver'])->patch(route('driver.fuel-lifting.hauls.status', $haulId), ['idempotency_key' => (string) Str::uuid(), 'lifting_status' => 'in_transit'])->assertSessionHasNoErrors();

        $page = $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting'))->assertOk()->getContent();
        $this->assertStringNotContainsString('dispatch-haul-'.$haulId.'-edit', $page);

        $this->actingAs($a['dispatch'])->patchJson(route('dispatch.fuel-lifting.hauls.lift', $haulId), $this->liftPayload($a, [['purchase_item_id' => $premium, 'quantity_liters' => 5000]]))
            ->assertStatus(422)->assertJsonValidationErrors('lift');
        $this->editLift($a, $haulId, [['purchase_item_id' => $premium, 'quantity_liters' => 5000]])
            ->assertSessionHasErrors(['lift' => 'Only a Scheduled lift can be edited. This lift is already In Transit or done.']);
        $this->assertSame(10000.0, (float) DB::table('hauls')->where('id', $haulId)->value('quantity_liters'));
        $this->assertDatabaseHas('activity_logs', ['route_name' => 'dispatch.fuel-lifting.hauls.lift', 'succeeded' => false]);
    }

    public function test_the_garage_tab_lists_one_row_per_tank_and_the_view_shows_stock_by_purchase_and_lots(): void
    {
        $a = $this->actors();
        $first = $this->purchaseLine($a, 'DSL', 4000);
        $second = $this->purchaseLine($a, 'DSL', 4000);
        $tank1 = $this->tank('DSL', 1);
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->liftAndUnload($a, $first, 3000, [$tank1 => 3000]);
        Carbon::setTestNow('2026-10-15 10:00:00');
        $this->liftAndUnload($a, $second, 1000, [$tank1 => 1000]);
        $codes = DB::table('purchase_items')->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')->whereIn('purchase_items.id', [$first, $second])->orderBy('purchase_items.id')->pluck('purchase_code')->all();

        foreach ([[$a['inventory'], 'inventory-officer.inventory.garage'], [$a['dispatch'], 'dispatch.fuel-lifting.garage']] as [$user, $route]) {
            $page = $this->actingAs($user)->get(route($route))->assertOk()->getContent();
            $this->assertSame(8, substr_count($page, 'data-modal-open="garage-tank-'));
            $this->assertMatchesRegularExpression('#<td><strong>Diesel Tank 1</strong></td>\s*<td class="inventory-number">24,000.00 L</td>\s*<td class="inventory-number">4,000.00 L</td>\s*<td>'.$codes[0].', '.$codes[1].'</td>#', $page);

            $modal = substr($page, strpos($page, 'id="garage-tank-'.$tank1.'"'), 8000);
            $this->assertStringContainsString('Quantity: <strong>4,000.00 L</strong>', $modal);
            $this->assertStringContainsString('Remaining Capacity: <strong>20,000.00 L</strong>', $modal);
            // 3,000 of 4,000 = 75%, 1,000 = 25%.
            $this->assertMatchesRegularExpression('#'.$codes[0].'</span>\s*<span>75%</span>\s*<strong>3,000.00 L</strong>#', $modal);
            $this->assertMatchesRegularExpression('#'.$codes[1].'</span>\s*<span>25%</span>\s*<strong>1,000.00 L</strong>#', $modal);
            // Lots newest first, with the unload date.
            $this->assertMatchesRegularExpression('#'.$codes[1].'</td>.*Oct 15, 2026 10:00 AM.*'.$codes[0].'</td>.*Oct 14, 2026 10:00 AM#s', $modal);

            // Search by purchase and the Date Unloaded range narrow the tanks; Export carries the lots.
            $this->assertSame(1, substr_count($this->actingAs($user)->get(route($route, ['search' => $codes[1]]))->getContent(), 'data-modal-open="garage-tank-'));
            $this->assertSame(1, substr_count($this->actingAs($user)->get(route($route, ['unloaded_from' => '2026-10-15', 'unloaded_to' => '2026-10-15']))->getContent(), 'data-modal-open="garage-tank-'));
            $this->assertStringContainsString('<th class="export-only" hidden>Lots (newest first)</th>', $page);
        }
    }

    private function depot(int $purchaseItemId): float
    {
        return (float) app(PurchaseStockService::class)->line($purchaseItemId)->remaining_in_depot;
    }

    private function tank(string $fuelCode, int $number): int
    {
        $this->actingAs(User::query()->where('role', 'admin')->first())->get(route('admin.inventory'))->assertOk();

        return (int) DB::table('storage_locations')->where('type', 'garage')->where('status', 'active')->where('fuel_type_id', $this->fuel($fuelCode))->where('tank_number', $number)->value('id');
    }

    /** Lifts $liters of a purchase on a free truck and unloads them into the given tanks. */
    private function liftAndUnload(array $a, int $purchaseItemId, float $liters, array $perTank): void
    {
        $truck = DB::table('trucks')->insertGetId(['truck_code' => 'TRK-'.Str::upper(Str::random(4)), 'capacity_liters' => 40000, 'truck_type' => 'hauling', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        $this->scheduleLift($a, [['purchase_item_id' => $purchaseItemId, 'quantity_liters' => $liters]], [], ['truck_id' => $truck])->assertSessionHasNoErrors();
        $haulId = $this->lastHaulId();
        $this->completeLift($a, $haulId);
        $this->actingAs($a['admin'])->from(route('admin.fuel-lifting'))->post(route('admin.fuel-lifting.hauls.unload-to-garage', $haulId), ['unload' => [$haulId => $perTank]])->assertSessionHasNoErrors();
    }

    private function f1Lot(array $a, float $liters): int
    {
        $this->liftAndUnload($a, $this->purchaseLine($a, 'F1', $liters), $liters, [$this->tank('F1', 1) => $liters]);

        return (int) DB::table('garage_stocks')->orderByDesc('id')->value('id');
    }

    private function premiumLot(array $a): int
    {
        $this->liftAndUnload($a, $this->purchaseLine($a, 'PREM', 8000), 8000, [$this->tank('PREM', 1) => 8000]);

        return (int) DB::table('garage_stocks')->orderByDesc('id')->value('id');
    }

    /** @return array{0: int, 1: User} */
    private function secondTruckAndDriver(): array
    {
        $truck = DB::table('trucks')->insertGetId(['truck_code' => 'TRK-EDIT', 'name' => 'Edit Tanker', 'capacity_liters' => 30000, 'truck_type' => 'hauling', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active', 'approval_status' => 'approved']);
        DB::table('driver_profiles')->insert(['user_id' => $driver->id, 'driver_code' => 'DRV-EDIT', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);

        return [$truck, $driver];
    }

    /** @return array<string, mixed> */
    private function liftPayload(array $a, array $items, array $saleIds = [], array $extra = []): array
    {
        return $extra + [
            'form_context' => 'edit-lift', 'items' => $items,
            'deliveries' => array_map(fn (int $saleId): array => ['sale_id' => $saleId], $saleIds),
            'driver_user_id' => $a['driver']->id, 'truck_id' => $a['truck'], 'scheduled_at' => '2026-10-16 08:00:00', 'dr_number' => 'DR-2026-001',
        ];
    }

    private function editLift(array $a, int $haulId, array $items, array $saleIds = [], array $extra = []): TestResponse
    {
        return $this->actingAs($a['dispatch'])->from(route('dispatch.fuel-lifting'))->patch(route('dispatch.fuel-lifting.hauls.lift', $haulId), $this->liftPayload($a, $items, $saleIds, $extra));
    }

    /** The Record Sales form. */
    private function recordSalesForm(array $a): string
    {
        $html = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $start = strpos($html, 'id="so-sales-add"');

        return substr($html, $start, strpos($html, 'class="modal-backdrop"', $start + 20) - $start);
    }
}
