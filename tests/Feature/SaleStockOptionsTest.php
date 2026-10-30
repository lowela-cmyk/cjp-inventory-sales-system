<?php

namespace Tests\Feature;

use App\Services\PurchaseStockService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PurchaseFlows;
use Tests\Feature\Audit\AuditTestCase;

/**
 * October 31, 2026: Record Sales lists every purchase that has liters for the delivery type (however the purchase was
 * written), hides purchases with nothing to sell, and says where a purchase's fuel is when the depot is empty. Fuel
 * received into the garage tanks before Part 13 becomes Part 13 garage lots.
 */
class SaleStockOptionsTest extends AuditTestCase
{
    use PurchaseFlows;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 09:00:00');
    }

    public function test_a_purchase_inserted_directly_is_sellable_and_gets_its_stock_in(): void
    {
        $a = $this->actors();
        $item = $this->insertPurchase($a, 'PUR-DIRECT', 'UNL', 9000);
        $this->assertFalse(DB::table('stock_ins')->where('purchase_item_id', $item)->exists());

        // Record Sales lists it without any new purchase being recorded first.
        $this->assertStringContainsString('<option value="'.$item.'" data-source="depot"', $this->recordSalesForm($a));
        $this->recordOrder($a, ['purchase_item_id' => $item, 'quantity_liters' => 1000, 'unit_price' => 60])->assertSessionHasNoErrors();

        // Reading stock-ins creates the missing row; a direct edit of the purchase is picked up too, never twice.
        $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory.stock-in'))->assertOk()->assertSee('PUR-DIRECT');
        $this->assertSame(1, DB::table('stock_ins')->where('purchase_item_id', $item)->count());
        DB::table('purchase_items')->where('id', $item)->update(['quantity_ordered_liters' => 9500]);
        $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory.stock-in'))->assertOk();
        $this->assertSame([9500.0], DB::table('stock_ins')->where('purchase_item_id', $item)->pluck('quantity_liters')->map(fn ($l): float => (float) $l)->all());
        $this->artisan('stock-in:sync')->expectsOutputToContain('Created 0 stock-in record(s); voided 0; refreshed 0.')->assertSuccessful();
    }

    public function test_purchases_with_no_liters_for_the_delivery_type_are_hidden_and_a_hint_says_where_the_fuel_is(): void
    {
        $a = $this->actors();
        $sold = $this->purchaseLine($a, 'DSL', 5000);
        $this->recordOrder($a, ['purchase_item_id' => $sold, 'quantity_liters' => 5000, 'unit_price' => 60])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $lifted = $this->purchaseLine($a, 'UNL', 10000);
        $this->scheduleLift($a, [['purchase_item_id' => $lifted, 'quantity_liters' => 10000]])->assertSessionHasNoErrors();
        $haulId = $this->lastHaulId();
        $this->completeLift($a, $haulId);

        // Fully promised (0 L available) and fully lifted (0 L in the depot) purchases are not offered for a new order.
        $form = $this->recordSalesForm($a);
        $this->assertStringNotContainsString('<option value="'.$sold.'" data-source="depot"', $form);
        $this->assertStringNotContainsString('<option value="'.$lifted.'" data-source="depot"', $form);
        $code = (string) DB::table('purchase_items')->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')->where('purchase_items.id', $lifted)->value('purchase_code');
        $this->assertStringContainsString('<li>'.$code.' has 0 L in the depot but 10,000 L on a truck — deliver it from the truck in Fuel Lifting.</li>', $form);
        // The order that already uses the 0-L purchase keeps it in its Edit modal.
        $this->assertMatchesRegularExpression('/<option value="'.$sold.'" data-source="depot"[^>]*selected/', $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->getContent());

        // Unloaded to the garage: the hint points to Garage to Client.
        $this->actingAs($a['admin'])->get(route('admin.inventory'))->assertOk();
        $tank = (int) DB::table('storage_locations')->where('type', 'garage')->where('status', 'active')->where('fuel_type_id', $this->fuel('UNL'))->where('tank_number', 1)->value('id');
        $this->actingAs($a['admin'])->from(route('admin.fuel-lifting'))->post(route('admin.fuel-lifting.hauls.unload-to-garage', $haulId), ['unload' => [$haulId => [$tank => 6000]]])->assertSessionHasNoErrors();
        $this->assertStringContainsString('<li>'.$code.' has 0 L in the depot but 6,000 L in the garage and 4,000 L on a truck — use Garage to Client.</li>', $this->recordSalesForm($a));

        // The server still refuses a depot order from it.
        $this->recordOrder($a, ['purchase_item_id' => $lifted, 'quantity_liters' => 100, 'unit_price' => 60])->assertSessionHasErrors();
        $this->assertNotNull($saleId);
    }

    public function test_each_delivery_type_lists_only_its_own_stock(): void
    {
        $a = $this->actors();
        $depot = $this->purchaseLine($a, 'PREM', 10000);
        $toGarage = $this->purchaseLine($a, 'PREM', 8000);
        $this->scheduleLift($a, [['purchase_item_id' => $toGarage, 'quantity_liters' => 8000]])->assertSessionHasNoErrors();
        $haulId = $this->lastHaulId();
        $this->completeLift($a, $haulId);
        $this->actingAs($a['admin'])->get(route('admin.inventory'))->assertOk();
        $tank = (int) DB::table('storage_locations')->where('type', 'garage')->where('status', 'active')->where('fuel_type_id', $this->fuel('PREM'))->where('tank_number', 1)->value('id');
        $this->actingAs($a['admin'])->from(route('admin.fuel-lifting'))->post(route('admin.fuel-lifting.hauls.unload-to-garage', $haulId), ['unload' => [$haulId => [$tank => 8000]]])->assertSessionHasNoErrors();
        $lot = (int) DB::table('garage_stocks')->value('id');

        $form = $this->recordSalesForm($a);
        // Standard / Depot to Client: depot purchases only (the lifted one has nothing in the depot).
        $this->assertStringContainsString('<option value="'.$depot.'" data-source="depot"', $form);
        $this->assertStringNotContainsString('<option value="'.$toGarage.'" data-source="depot"', $form);
        // Garage to Client: the garage lot (app.js shows only "garage" options for that type).
        $this->assertStringContainsString('<option value="garage:'.$lot.'" data-source="garage"', $form);

        // The server enforces it: a Standard order can't take the garage lot, Garage to Client can.
        $this->recordOrder($a, ['purchase_item_id' => 'garage:'.$lot, 'quantity_liters' => 100, 'unit_price' => 60])->assertSessionHasErrors();
        $this->recordOrder($a, ['purchase_item_id' => 'garage:'.$lot, 'quantity_liters' => 100, 'unit_price' => 60], ['delivery_type' => 'garage_to_client'])->assertSessionHasNoErrors();
        $this->recordOrder($a, ['purchase_item_id' => $depot, 'quantity_liters' => 100, 'unit_price' => 60], ['delivery_type' => 'depot_to_client'])->assertSessionHasNoErrors();
    }

    public function test_fuel_received_into_the_old_garage_tanks_becomes_garage_lots(): void
    {
        $a = $this->actors();
        $this->actingAs($a['admin'])->get(route('admin.inventory'))->assertOk();
        $diesel = $this->fuel('DSL');
        $tank1 = (int) DB::table('storage_locations')->where('type', 'garage')->where('status', 'active')->where('fuel_type_id', $diesel)->where('tank_number', 1)->value('id');
        $tank2 = (int) DB::table('storage_locations')->where('type', 'garage')->where('status', 'active')->where('fuel_type_id', $diesel)->where('tank_number', 2)->value('id');

        // The September seed shape: 40,000 L lifted into Diesel Tank 1 through a "garage" allocation; an older 50 L
        // release from the tank's beginning balance; 15,000 L sold from the tank afterwards.
        $item = $this->insertPurchase($a, 'PUR-LEGACY', 'DSL', 40000, 'hauled');
        $purchaseId = (int) DB::table('purchase_items')->where('id', $item)->value('purchase_id');
        $haul = DB::table('hauls')->insertGetId(['haul_code' => 'LFT-LEGACY', 'purchase_id' => $purchaseId, 'purchase_item_id' => $item, 'depot_id' => $a['depot'], 'fuel_type_id' => $diesel, 'truck_id' => $a['truck'], 'driver_user_id' => $a['driver']->id, 'scheduled_at' => '2026-09-08 08:00:00', 'hauled_at' => '2026-09-08 11:30:00', 'quantity_liters' => 40000, 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
        $allocation = DB::table('haul_allocations')->insertGetId(['haul_id' => $haul, 'fuel_type_id' => $diesel, 'destination_type' => 'garage', 'storage_location_id' => $tank1, 'quantity_liters' => 40000, 'allocated_at' => '2026-09-08 11:30:00', 'status' => 'received', 'created_at' => now(), 'updated_at' => now()]);
        $sale = DB::table('sales')->insertGetId(['sale_code' => 'SLS-LEGACY', 'customer_id' => $a['customer'], 'sale_date' => '2026-09-09', 'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment', 'status' => 'paid', 'delivery_status' => 'delivered', 'created_at' => now(), 'updated_at' => now()]);
        $saleItem = DB::table('sale_items')->insertGetId(['sale_id' => $sale, 'fuel_type_id' => $diesel, 'quantity_liters' => 15050, 'main_quantity_liters' => 15050, 'unit_price' => 60, 'line_total' => 903000, 'fulfilled_quantity_liters' => 15050, 'created_at' => now(), 'updated_at' => now()]);
        $release = fn (string $code, float $liters, string $at): int => DB::table('stock_outs')->insertGetId(['stock_out_code' => $code, 'sale_id' => $sale, 'sale_item_id' => $saleItem, 'customer_id' => $a['customer'], 'fuel_type_id' => $diesel, 'source_type' => 'garage', 'storage_location_id' => $tank1, 'quantity_liters' => $liters, 'stock_out_at' => $at, 'status' => 'released', 'created_at' => now(), 'updated_at' => now()]);
        $beginning = $release('STO-OLD-50', 50, '2026-09-07 14:00:00');
        $sold = $release('STO-OLD-15000', 15000, '2026-09-09 14:00:00');
        $this->assertSame(0.0, (float) app(PurchaseStockService::class)->line($item)->in_garage);

        $migration = require database_path('migrations/2026_10_31_000001_convert_legacy_garage_fuel_to_lots.php');
        $migration->up();
        $migration->up(); // re-running changes nothing

        // 40,000 - 15,000 = 25,000 L in the garage: Tank 1 holds 24,000, the other 1,000 go to Tank 2.
        $lots = DB::table('garage_stocks')->where('purchase_item_id', $item)->orderBy('id')->get(['storage_location_id', 'liters', 'haul_allocation_id', 'lot_code']);
        $this->assertSame([[$tank1, 24000.0], [$tank2, 1000.0]], $lots->map(fn ($lot): array => [(int) $lot->storage_location_id, (float) $lot->liters])->all());
        $this->assertSame([$allocation], $lots->pluck('haul_allocation_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());
        // The 15,000 L release is charged to the purchase; the 50 L from before the lift is not.
        $this->assertSame($item, (int) DB::table('stock_outs')->where('id', $sold)->value('purchase_item_id'));
        $this->assertNull(DB::table('stock_outs')->where('id', $beginning)->value('purchase_item_id'));
        $line = app(PurchaseStockService::class)->line($item);
        $this->assertSame([25000.0, 0.0, 0.0, 15000.0, 25000.0], [$line->in_garage, $line->remaining_in_depot, $line->on_truck, $line->released, $line->on_hand]);

        // Sellable through Garage to Client, and the hint points there.
        $form = $this->recordSalesForm($a);
        $this->assertSame(2, substr_count($form, 'data-source="garage"'));
        $this->assertStringContainsString('<li>PUR-LEGACY has 0 L in the depot but 25,000 L in the garage — use Garage to Client.</li>', $form);

        $migration->down();
        $this->assertSame(0, DB::table('garage_stocks')->count());
        $this->assertNull(DB::table('stock_outs')->where('id', $sold)->value('purchase_item_id'));
    }

    public function test_legacy_fuel_that_does_not_fit_in_the_tanks_is_skipped_and_reported(): void
    {
        $a = $this->actors();
        $this->actingAs($a['admin'])->get(route('admin.inventory'))->assertOk();
        $diesel = $this->fuel('DSL');
        [$tank1, $tank2] = collect([1, 2])->map(fn (int $n): int => (int) DB::table('storage_locations')->where('type', 'garage')->where('status', 'active')->where('fuel_type_id', $diesel)->where('tank_number', $n)->value('id'))->all();

        // Tank 2 already holds 10,000 L from a Part 13 unload.
        $current = $this->purchaseLine($a, 'DSL', 10000);
        $this->scheduleLift($a, [['purchase_item_id' => $current, 'quantity_liters' => 10000]])->assertSessionHasNoErrors();
        $this->completeLift($a, $this->lastHaulId());
        $this->actingAs($a['admin'])->from(route('admin.fuel-lifting'))->post(route('admin.fuel-lifting.hauls.unload-to-garage', $this->lastHaulId()), ['unload' => [$this->lastHaulId() => [$tank2 => 10000]]])->assertSessionHasNoErrors();

        // A legacy lift of 50,000 L received into the garage: 24,000 + 14,000 fit, 12,000 do not.
        $item = $this->insertPurchase($a, 'PUR-BIG', 'DSL', 50000, 'hauled');
        $haul = DB::table('hauls')->insertGetId(['haul_code' => 'LFT-BIG', 'purchase_id' => DB::table('purchase_items')->where('id', $item)->value('purchase_id'), 'purchase_item_id' => $item, 'depot_id' => $a['depot'], 'fuel_type_id' => $diesel, 'truck_id' => $a['truck'], 'driver_user_id' => $a['driver']->id, 'scheduled_at' => '2026-09-08 08:00:00', 'hauled_at' => '2026-09-08 11:30:00', 'quantity_liters' => 50000, 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('haul_allocations')->insert(['haul_id' => $haul, 'fuel_type_id' => $diesel, 'destination_type' => 'garage', 'storage_location_id' => $tank1, 'quantity_liters' => 50000, 'allocated_at' => '2026-09-08 11:30:00', 'status' => 'received', 'created_at' => now(), 'updated_at' => now()]);

        $migration = require database_path('migrations/2026_10_31_000001_convert_legacy_garage_fuel_to_lots.php');
        $migration->up();

        $this->assertSame([[$tank1, 24000.0], [$tank2, 14000.0]], DB::table('garage_stocks')->where('purchase_item_id', $item)->orderBy('id')->get(['storage_location_id', 'liters'])->map(fn ($lot): array => [(int) $lot->storage_location_id, (float) $lot->liters])->all());
        $this->assertSame([['purchase_code' => 'PUR-BIG', 'fuel' => 'Diesel', 'liters' => 12000.0]], $migration->skipped);
        // No tank is over its 24,000 L.
        $tanks = collect(app(\App\Services\GarageStockService::class)->tab()['fuels'])->flatMap(fn (array $fuel) => $fuel['tanks']);
        $this->assertTrue($tanks->every(fn (object $tank): bool => $tank->contents <= $tank->capacity));
        $this->assertSame(24000.0, (float) $tanks->firstWhere('id', $tank2)->contents);
    }

    private function insertPurchase(array $a, string $code, string $fuelCode, float $liters, string $status = 'ordered'): int
    {
        $purchaseId = DB::table('purchases')->insertGetId(['purchase_code' => $code, 'depot_id' => $a['depot'], 'purchase_date' => '2026-09-08', 'payment_status' => 'paid', 'status' => $status, 'workflow_status' => $status === 'hauled' ? 'completed' : 'pending', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('purchase_items')->insertGetId(['purchase_id' => $purchaseId, 'fuel_type_id' => $this->fuel($fuelCode), 'quantity_ordered_liters' => $liters, 'unit_cost' => 50, 'line_total' => $liters * 50, 'quantity_hauled_liters' => $status === 'hauled' ? $liters : 0, 'status' => $status === 'hauled' ? 'lifted' : 'unlifted', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** The Record Sales form (its first order line). */
    private function recordSalesForm(array $a): string
    {
        $html = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $start = strpos($html, 'id="so-sales-add"');
        $this->assertNotFalse($start);

        return substr($html, $start, strpos($html, 'class="modal-backdrop"', $start + 20) - $start);
    }
}
