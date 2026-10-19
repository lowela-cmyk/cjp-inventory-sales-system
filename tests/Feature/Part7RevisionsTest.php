<?php

namespace Tests\Feature;

use App\Services\LiftAllocationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Audit\AuditTestCase;

/**
 * PART 7 (October 14, 2026): receipt viewer, allocation at scheduling (audit C01) and truck availability.
 */
class Part7RevisionsTest extends AuditTestCase
{
    public function test_schedule_lift_sends_the_whole_amount_to_the_garage_tank(): void
    {
        $a = $this->actors();
        [$itemId, $tank] = $this->purchase($a, 10000);

        // The Part 7 direct_liters / sale_id fields are not read any more: Depot to Client rows come as lifts[] (Part 9).
        $this->schedule($a, [['purchase_item_id' => $itemId, 'quantity_liters' => 10000, 'storage_location_id' => $tank, 'direct_liters' => 4000, 'sale_id' => 999]])
            ->assertSessionHasNoErrors();
        $haulId = (int) DB::table('hauls')->value('id');

        $this->assertDatabaseHas('haul_allocations', ['haul_id' => $haulId, 'destination_type' => 'garage', 'storage_location_id' => $tank, 'quantity_liters' => 10000, 'status' => 'planned', 'receive_on_completion' => true]);
        $this->assertSame(0, DB::table('haul_allocations')->where('destination_type', 'customer')->count());

        foreach (['in_transit', 'lifted'] as $status) {
            $this->actingAs($a['driver'])->patch(route('driver.fuel-lifting.hauls.status', $haulId), ['idempotency_key' => $this->uuid(), 'lifting_status' => $status])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(10000.0, $this->tankBalance($tank, $a['fuel']));
        $this->assertDatabaseHas('haul_allocations', ['haul_id' => $haulId, 'destination_type' => 'garage', 'status' => 'received']);
    }

    public function test_a_historical_garage_and_depot_split_still_settles_both_parts(): void
    {
        $a = $this->actors();
        [$itemId, $tank] = $this->purchase($a, 10000);
        [$saleId, $saleItemId] = $this->openSale($a, 4000);
        $this->schedule($a, [['purchase_item_id' => $itemId, 'quantity_liters' => 10000, 'storage_location_id' => $tank]])->assertSessionHasNoErrors();
        $haulId = (int) DB::table('hauls')->value('id');

        // Reshape it the way lifts scheduled before Part 8 look in the data: 6,000 L garage + 4,000 L direct to a sale.
        DB::table('haul_allocations')->where('haul_id', $haulId)->update(['quantity_liters' => 6000]);
        DB::table('haul_allocations')->insert([
            'haul_id' => $haulId, 'fuel_type_id' => $a['fuel'], 'destination_type' => 'customer', 'customer_id' => $a['customer'], 'sale_id' => $saleId,
            'quantity_liters' => 4000, 'receive_on_completion' => true, 'status' => 'planned', 'allocated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['in_transit', 'lifted'] as $status) {
            $this->actingAs($a['driver'])->patch(route('driver.fuel-lifting.hauls.status', $haulId), ['idempotency_key' => $this->uuid(), 'lifting_status' => $status])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(6000.0, $this->tankBalance($tank, $a['fuel']));
        $this->assertDatabaseHas('haul_allocations', ['haul_id' => $haulId, 'destination_type' => 'customer', 'status' => 'delivered']);
        $this->assertDatabaseHas('stock_outs', ['sale_id' => $saleId, 'source_type' => 'depot', 'quantity_liters' => 4000, 'status' => 'released']);
        $this->assertDatabaseHas('sale_items', ['id' => $saleItemId, 'fulfilled_quantity_liters' => 4000]);
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'delivery_status' => 'delivered']);

        // The historical depot release is still shown on Stock-Out.
        $this->actingAs($a['admin'])->get(route('admin.inventory.stock-out'))->assertOk()->assertSee('Depot — Audit Terminal');
    }

    public function test_tank_errors_show_under_the_right_field(): void
    {
        $a = $this->actors();
        [$itemId, $tank] = $this->purchase($a, 10000);
        $otherFuelTank = (int) DB::table('storage_locations')->where('type', 'garage')->where('status', 'active')->where('fuel_type_id', '!=', $a['fuel'])->value('id');
        DB::table('storage_locations')->where('id', $tank)->update(['capacity_liters' => 5000]);

        $line = ['purchase_item_id' => $itemId, 'quantity_liters' => 8000];
        $this->schedule($a, [$line + ['storage_location_id' => $otherFuelTank]])
            ->assertSessionHasErrors(['items.0.storage_location_id' => 'The tank must hold the same fuel type as the purchase.']);
        $this->schedule($a, [$line + ['storage_location_id' => $tank]])
            ->assertSessionHasErrors('items.0.quantity_liters');
        $this->assertSame(0, DB::table('hauls')->count());

        // Part 9 lift rows: the same errors show under the row's own field.
        $this->schedule($a, [$line + ['lifts' => [['type' => 'garage', 'storage_location_id' => $otherFuelTank, 'quantity_liters' => 8000]]]])
            ->assertSessionHasErrors(['items.0.lifts.0.storage_location_id' => 'The tank must hold the same fuel type as the purchase.']);
        $this->schedule($a, [$line + ['lifts' => [['type' => 'garage', 'storage_location_id' => $tank, 'quantity_liters' => 8000]]]])
            ->assertSessionHasErrors('items.0.lifts.0.quantity_liters');
        $this->assertSame(0, DB::table('hauls')->count());

        // Part 9 restores Depot to Client: each lift row offers a tank or a sales order.
        $html = $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="items\[0\]\[lifts\]\[0\]\[storage_location_id\]" data-lift-field="storage_location_id" data-allocation-tank/', $html);
        $this->assertStringContainsString('name="items[0][lifts][0][sale_id]"', $html);
        $this->assertStringContainsString('<option value="client" >Depot to Client</option>', $html);
        $this->assertStringNotContainsString('direct_liters', $html);
        $this->assertStringNotContainsString('garage_liters', $html);
        $this->assertStringContainsString('free 5,000.00 L', $html);
    }

    public function test_truck_is_in_use_while_in_transit_and_available_again_once_the_driver_finishes(): void
    {
        $a = $this->actors();
        [$itemId, $tank] = $this->purchase($a, 20000);
        $line = fn (): array => [['purchase_item_id' => $itemId, 'quantity_liters' => 5000, 'garage_liters' => 5000, 'storage_location_id' => $tank, 'direct_liters' => 0]];

        $this->schedule($a, $line())->assertSessionHasNoErrors();
        $haulId = (int) DB::table('hauls')->value('id');
        $this->assertSame('assigned', $this->truckStatus($a));

        $this->actingAs($a['driver'])->patch(route('driver.fuel-lifting.hauls.status', $haulId), ['idempotency_key' => $this->uuid(), 'lifting_status' => 'in_transit']);
        $this->assertSame('in_use', $this->truckStatus($a));
        $this->assertTruckSelectable($a, false);

        $this->actingAs($a['driver'])->patch(route('driver.fuel-lifting.hauls.status', $haulId), ['idempotency_key' => $this->uuid(), 'lifting_status' => 'lifted']);
        $this->assertSame('available', $this->truckStatus($a));
        $this->assertTruckSelectable($a, true);
        $trucksTab = $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting.trucks'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/TRK-AUD.*?>Available<.*?<td>0<\/td>/s', $trucksTab);

        // The same truck can be scheduled again right away.
        $this->schedule($a, $line())->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('hauls')->where('truck_id', $a['truck'])->count());
    }

    public function test_cancelling_a_lift_frees_the_truck_and_its_allocation(): void
    {
        $a = $this->actors();
        [$itemId, $tank] = $this->purchase($a, 10000);
        $this->schedule($a, [['purchase_item_id' => $itemId, 'quantity_liters' => 5000, 'garage_liters' => 5000, 'storage_location_id' => $tank, 'direct_liters' => 0]]);
        $haulId = (int) DB::table('hauls')->value('id');

        $this->actingAs($a['dispatch'])->patch(route('dispatch.fuel-lifting.hauls.status', $haulId), ['idempotency_key' => $this->uuid(), 'status' => 'cancelled'])->assertSessionHasNoErrors();

        $this->assertSame('available', $this->truckStatus($a));
        $this->assertTruckSelectable($a, true);
        $this->assertDatabaseHas('haul_allocations', ['haul_id' => $haulId, 'status' => 'cancelled']);
    }

    public function test_backfill_dry_run_changes_nothing_and_apply_creates_unconfirmed_garage_allocations(): void
    {
        $a = $this->actors();
        [$itemId] = $this->purchase($a, 10000);
        $this->schedule($a, [['purchase_item_id' => $itemId, 'quantity_liters' => 7000]]);
        $haulId = (int) DB::table('hauls')->value('id');
        DB::table('haul_allocations')->delete(); // a lift scheduled before Part 7

        Artisan::call('cjp:backfill-lift-allocations');
        $this->assertStringContainsString('DRY RUN: 1 garage allocation(s) would be created', Artisan::output());
        $this->assertSame(0, DB::table('haul_allocations')->count());

        Artisan::call('cjp:backfill-lift-allocations', ['--apply' => true]);
        $this->assertDatabaseHas('haul_allocations', ['haul_id' => $haulId, 'destination_type' => 'garage', 'quantity_liters' => 7000, 'status' => 'planned', 'receive_on_completion' => false]);
        $this->assertTrue(app(LiftAllocationService::class)->backfillPlan()->isEmpty());
    }

    public function test_receipt_column_opens_an_image_only_lightbox_and_files_are_role_gated(): void
    {
        Storage::fake('local');
        $a = $this->actors();
        [$itemId, $tank] = $this->purchase($a, 10000);
        $this->schedule($a, [['purchase_item_id' => $itemId, 'quantity_liters' => 5000, 'garage_liters' => 5000, 'storage_location_id' => $tank, 'direct_liters' => 0]]);
        $haulId = (int) DB::table('hauls')->value('id');
        foreach (['in_transit', 'lifted'] as $status) {
            $this->actingAs($a['driver'])->patch(route('driver.fuel-lifting.hauls.status', $haulId), ['idempotency_key' => $this->uuid(), 'lifting_status' => $status]);
        }
        $this->actingAs($a['driver'])->post(route('driver.fuel-lifting.hauls.withdrawal-receipt.store', $haulId), [
            'withdrawal_receipt' => UploadedFile::fake()->image('receipt.png', 40, 40),
        ])->assertSessionHasNoErrors();

        foreach ([[$a['inventory'], 'inventory-officer.inventory'], [$a['admin'], 'admin.inventory']] as [$user, $route]) {
            $html = $this->actingAs($user)->get(route($route))->assertOk()->getContent();
            $this->assertStringContainsString('-receipts">View Receipt</button>', $html);
            $this->assertStringNotContainsString('>Uploaded<', $this->between($html, '<section data-tab-panel="purchases"', '</section>'));
            $this->assertMatchesRegularExpression('/data-receipt-lightbox>.*?<img data-src="'.preg_quote(route('withdrawal-receipts.show', $haulId), '/').'"/s', $html);
        }

        $url = route('withdrawal-receipts.show', $haulId);
        $this->actingAs($a['inventory'])->get($url)->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->actingAs($a['admin'])->get($url)->assertOk();
        foreach (['sales', 'dispatch', 'driver'] as $role) {
            $this->actingAs($a[$role])->get($url)->assertForbidden();
        }
        auth()->logout();
        $this->get($url)->assertRedirect(route('login'));
        // Stored on the private disk under a random name, never under public/.
        $path = (string) DB::table('hauls')->where('id', $haulId)->value('withdrawal_receipt_path');
        $this->assertMatchesRegularExpression('/^withdrawal-receipts\/[0-9a-f-]{36}\.png$/', $path);
        Storage::disk('local')->assertExists($path);
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array{0: int, 1: int}
     */
    private function purchase(array $a, int $liters): array
    {
        $this->actingAs($a['inventory'])->post(route('inventory-officer.inventory.purchases.store'), [
            'idempotency_key' => $this->uuid(), 'purchase_date' => '2026-10-14', 'depot_id' => $a['depot'], 'fuel_type_id' => $a['fuel'],
            'quantity_ordered_liters' => $liters, 'unit_cost' => 50, 'payment_status' => 'unpaid',
        ])->assertSessionHasNoErrors();
        // Opening Inventory creates the standard garage tanks.
        $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory'))->assertOk();

        return [
            (int) DB::table('purchase_items')->orderByDesc('id')->value('id'),
            (int) DB::table('storage_locations')->where('type', 'garage')->where('status', 'active')->where('fuel_type_id', $a['fuel'])->orderBy('tank_number')->value('id'),
        ];
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array{0: int, 1: int}
     */
    private function openSale(array $a, int $liters): array
    {
        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => 'SLS-P7', 'customer_id' => $a['customer'], 'sale_date' => '2026-10-14', 'payment_method' => 'cash_on_delivery',
            'payment_terms' => 'cod', 'status' => 'confirmed', 'delivery_status' => 'pending', 'created_by' => $a['sales']->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $itemId = DB::table('sale_items')->insertGetId([
            'sale_id' => $saleId, 'fuel_type_id' => $a['fuel'], 'quantity_liters' => $liters, 'unit_price' => 60, 'line_total' => $liters * 60,
            'fulfilled_quantity_liters' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('receivables')->insert(['sale_id' => $saleId, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        return [$saleId, $itemId];
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<int, array<string, mixed>>  $items
     */
    private function schedule(array $a, array $items)
    {
        return $this->actingAs($a['dispatch'])->from(route('dispatch.fuel-lifting'))->post(route('dispatch.fuel-lifting.hauls.store'), [
            'idempotency_key' => $this->uuid(), 'items' => $items, 'driver_user_id' => $a['driver']->id, 'truck_id' => $a['truck'],
            'scheduled_at' => '2026-10-14 08:00:00', 'form_context' => 'schedule-lift',
        ]);
    }

    /**
     * @param  array<string, mixed>  $a
     */
    private function truckStatus(array $a): string
    {
        return (string) DB::table('trucks')->where('id', $a['truck'])->value('status');
    }

    /**
     * @param  array<string, mixed>  $a
     */
    private function assertTruckSelectable(array $a, bool $expected): void
    {
        $select = $this->between($this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting'))->getContent(), 'id="dispatch_truck_id"', '</select>');
        $this->assertSame($expected, str_contains($select, 'value="'.$a['truck'].'"'));
    }

    private function tankBalance(int $tankId, int $fuelTypeId): float
    {
        return round((float) DB::table('inventory_movements')->where('storage_location_id', $tankId)->where('fuel_type_id', $fuelTypeId)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN quantity_liters ELSE -quantity_liters END), 0) as balance")->value('balance'), 2);
    }

    private function between(string $html, string $start, string $end): string
    {
        $from = strpos($html, $start);
        $this->assertNotFalse($from, 'Missing '.$start);

        return substr($html, $from, strpos($html, $end, $from) - $from);
    }
}
