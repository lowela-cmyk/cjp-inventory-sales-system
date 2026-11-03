<?php

namespace Tests\Feature;

use App\Services\GarageStockService;
use App\Services\PurchaseStockService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PurchaseFlows;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Part 18 add-on (Nov 1 doc, Sales item 1): fuel in a garage tank is mixed, so Garage to Client lines (and F1 portions
 * from the garage) sell from a TANK at its weighted average cost; a release draws its liters from the lots in the tank
 * in proportion to what each lot can give, so every purchase still adds up. Older lot-based orders keep their lot.
 *
 * The tank used throughout: Premium Tank 1 = purchase A 8,000 L at ₱54.00 + purchase B 4,000 L at ₱57.60
 * = 12,000 L worth ₱662,400, average ₱55.20/L.
 */
class Part18TankAverageCostTest extends AuditTestCase
{
    use PurchaseFlows;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 09:00:00');
    }

    // ----- Average cost ----------------------------------------------------------------------------------------

    public function test_the_tank_average_is_the_weighted_cost_of_everything_in_it_and_an_unload_updates_it(): void
    {
        $a = $this->actors();
        [$tank] = $this->premiumTank($a);

        $this->assertSame([12000.0, 662400.0, 55.20], $this->tankFigures($tank));
        $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()
            ->assertSee('<option value="tank:'.$tank.'" data-source="garage" data-fuel-code="PREM" data-fuel-name="Premium" data-available="12000" >Premium Tank 1 · 12,000 L</option>', false)
            ->assertDontSee('Premium Tank 1 · 12,000 L · avg ₱55.20/L');

        // Unloading truck leftover mixes into the tank: 3,000 L at ₱60.00 -> ₱842,400 / 15,000 L = ₱56.16.
        Carbon::setTestNow('2026-10-16 09:00:00');
        $this->liftAndUnload($a, $this->purchaseLine($a, 'PREM', 3000, 60), 3000, [$tank => 3000]);
        $this->assertSame([15000.0, 842400.0, 56.16], $this->tankFigures($tank));

        // View Tank Stock: the tank's average, and each purchase's liters and cost.
        $page = $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory.garage'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#<td><strong>Premium Tank 1</strong></td>\s*<td class="inventory-number">24,000.00 L</td>\s*<td class="inventory-number">15,000.00 L</td>\s*<td class="inventory-number">₱56.16/L</td>#', $page);
        $modal = substr($page, strpos($page, 'id="garage-tank-'.$tank.'"'), 8000);
        $this->assertStringContainsString('Average Cost: <strong>₱56.16/L</strong>', $modal);
        foreach (['8,000.00 L</strong>' => '₱54.00/L', '4,000.00 L</strong>' => '₱57.60/L', '3,000.00 L</strong>' => '₱60.00/L'] as $liters => $cost) {
            $this->assertMatchesRegularExpression('#'.preg_quote($liters, '#').'\s*<span class="garage-tank-share-cost">'.preg_quote($cost, '#').'</span>#', $modal);
        }
    }

    // ----- Cost, margin and the proportional draws ------------------------------------------------------------

    public function test_a_tank_sale_is_costed_at_the_average_and_drawn_from_each_purchase_in_proportion(): void
    {
        $a = $this->actors();
        [$tank, $purchaseA, $purchaseB, $lotA, $lotB] = $this->premiumTank($a);

        $this->garageOrder($a, $tank, 3000)->assertSessionHasNoErrors();
        $sale = $this->lastSaleId();
        $this->assertDatabaseHas('sale_items', ['sale_id' => $sale, 'garage_tank_id' => $tank, 'purchase_item_id' => null, 'garage_stock_id' => null]);
        // Open order: promised from the tank (and from its purchases, as a release would draw it), nothing left it yet.
        $this->assertSame(9000.0, app(GarageStockService::class)->tanks()->get($tank)->available);
        $this->assertSame([2000.0, 1000.0], [app(PurchaseStockService::class)->line($purchaseA)->reserved, app(PurchaseStockService::class)->line($purchaseB)->reserved]);

        $this->deliverOrder($a, $sale)->assertSessionHasNoErrors();
        $stockOut = DB::table('stock_outs')->where('sale_id', $sale)->sole();
        $this->assertSame(['garage', $tank, null, null, 3000.0, 55.20], [$stockOut->source_type, (int) $stockOut->storage_location_id, $stockOut->garage_stock_id, $stockOut->purchase_item_id, (float) $stockOut->quantity_liters, (float) $stockOut->unit_cost]);

        // 8,000 : 4,000 -> 2,000 L from A and 1,000 L from B; their cost is exactly 3,000 x ₱55.20.
        $draws = DB::table('stock_out_draws')->where('stock_out_id', $stockOut->id)->orderBy('id')->get();
        $this->assertSame([[$lotA, $purchaseA, 2000.0, 54.0], [$lotB, $purchaseB, 1000.0, 57.6]], $draws->map(fn (object $draw): array => [(int) $draw->garage_stock_id, (int) $draw->purchase_item_id, (float) $draw->liters, (float) $draw->unit_cost])->all());
        $this->assertSame(165600.0, round($draws->sum(fn (object $draw): float => $draw->liters * $draw->unit_cost), 2));

        // Fuel leaving does not move the average; each purchase still adds up.
        $this->assertSame([9000.0, 496800.0, 55.20], $this->tankFigures($tank));
        foreach ([$purchaseA => [6000.0, 2000.0], $purchaseB => [3000.0, 1000.0]] as $purchase => [$inGarage, $released]) {
            $line = app(PurchaseStockService::class)->line($purchase);
            $this->assertSame([$inGarage, $released, 0.0], [$line->in_garage, $line->released, $line->reserved]);
            $this->assertSame($line->ordered, round($line->remaining_in_depot + $line->on_truck + $line->in_garage + $line->released, 2));
        }

        // Stock Out: the tank and its average, the purchases it drew from; margin at the average cost (3,000 x ₱70).
        $page = $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory.stock-out'))->assertOk();
        $page->assertSee('Garage — Premium Tank 1 (avg ₱55.20/L)')
            ->assertSee($this->code($purchaseA).', '.$this->code($purchaseB))
            ->assertSee($this->code($purchaseA).' 2,000.00 L @ ₱54.00/L · '.$this->code($purchaseB).' 1,000.00 L @ ₱57.60/L')
            ->assertSee('165,600.00')->assertSee('44,400.00');
        // Searching a purchase code finds the tank release.
        $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory.stock-out', ['search' => $this->code($purchaseB)]))->assertOk()->assertSee('Garage — Premium Tank 1 (avg ₱55.20/L)');
        // Sale View: the line's source.
        $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()
            ->assertSee('Garage — Premium Tank 1 (avg ₱55.20/L): '.$this->code($purchaseA).', '.$this->code($purchaseB));
    }

    public function test_proportional_draws_always_add_up_to_the_liters_asked(): void
    {
        $lots = fn (array $available): \Illuminate\Support\Collection => collect($available)->mapWithKeys(fn (float $liters, int $index): array => [$index + 1 => (object) ['id' => $index + 1, 'available' => $liters, 'unloaded_at' => '2026-10-1'.$index]]);
        $sum = fn (array $draws): float => round(array_sum(array_column($draws, 'liters')), 2);

        // Hundredths that don't divide evenly go to the largest remainders; the total is exact.
        $draws = GarageStockService::proportionalDraws($lots([3333.33, 3333.33, 3333.34]), 1000.01);
        $this->assertSame(1000.01, $sum($draws));
        $this->assertSame([333.34, 333.33, 333.34], array_column($draws, 'liters'));
        // 0.005 L vs 4,999.995 L: the tied hundredth goes to the lot holding more.
        $this->assertSame([5000.0], array_column(GarageStockService::proportionalDraws($lots([0.01, 9999.99]), 5000.0), 'liters'));
        // Taking everything empties every lot exactly; more than the lots hold is refused.
        $this->assertSame([1.23, 4.56, 7.89], array_column(GarageStockService::proportionalDraws($lots([1.23, 4.56, 7.89]), 13.68), 'liters'));
        $this->assertNull(GarageStockService::proportionalDraws($lots([1.23, 4.56, 7.89]), 13.69));

        mt_srand(18);
        for ($case = 0; $case < 200; $case++) {
            $available = array_map(fn (): float => mt_rand(0, 2400000) / 100, range(1, mt_rand(1, 6)));
            $liters = round(mt_rand(1, (int) round(array_sum($available) * 100)) / 100, 2);
            if ($liters <= 0) {
                continue;
            }
            $draws = GarageStockService::proportionalDraws($lots($available), $liters);
            $this->assertSame($liters, $sum($draws));
            foreach ($draws as $draw) {
                $this->assertLessThanOrEqual($draw['lot']->available, $draw['liters']);
            }
        }
    }

    // ----- Tank-only selection ---------------------------------------------------------------------------------

    public function test_new_garage_orders_and_assign_source_pick_only_a_tank(): void
    {
        $a = $this->actors();
        [$tank, $purchaseA, , $lotA] = $this->premiumTank($a);

        // A lot is no longer offered or accepted for a new order; a Standard order can't take a tank.
        $form = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $this->assertStringNotContainsString('value="garage:'.$lotA.'"', $form);
        $this->recordOrder($a, ['purchase_item_id' => 'garage:'.$lotA, 'quantity_liters' => 100, 'unit_price' => 70], ['delivery_type' => 'garage_to_client'] + $this->garageDelivery($a))
            ->assertSessionHasErrors(['items.0.garage_tank_id' => 'Select the garage tank to sell from.']);
        $this->recordOrder($a, ['purchase_item_id' => 'tank:'.$tank, 'quantity_liters' => 100, 'unit_price' => 70])->assertSessionHasErrors('items.0.purchase_item_id');
        $this->recordOrder($a, ['purchase_item_id' => $purchaseA, 'garage_tank_id' => $tank, 'quantity_liters' => 100, 'unit_price' => 70])
            ->assertSessionHasErrors(['items.0.purchase_item_id' => 'Only a Garage to Client order sells from a garage tank.']);
        // An F1 portion must come from an F1 source, not the Premium tank.
        $this->garageOrder($a, $tank, 1000, ['f1_percentage' => 10, 'f1_purchase_item_id' => 'tank:'.$tank, 'f1_unit_price' => 40])
            ->assertSessionHasErrors('items.0.f1_purchase_item_id');

        // Two orders can't promise more than the tank holds.
        $this->garageOrder($a, $tank, 10000)->assertSessionHasNoErrors();
        $this->garageOrder($a, $tank, 2001)->assertSessionHasErrors(['items.0.quantity_liters' => 'Only 2,000.00 L of Premium are available in Premium Tank 1 for this order.']);
        $this->assertSame(1, DB::table('sales')->count());

        // Assign Source -> Garage lists the tank with its average, never a lot.
        $this->recordOrder($a, ['purchase_item_id' => $this->purchaseLine($a, 'PREM', 5000), 'quantity_liters' => 1500, 'unit_price' => 70])->assertSessionHasNoErrors();
        $standard = $this->lastSaleId();
        $modal = $this->saleModal($a, $standard);
        $this->assertStringContainsString('<option value="'.$tank.'">Premium Tank 1 · 2,000.00 L available</option>', $modal);
        $this->actingAs($a['sales'])->post(route('sales-officer.sales.assign-source', $standard), ['source_type' => 'garage_lot', 'source_id' => $lotA] + $this->garageDelivery($a))->assertSessionHasErrors('source_type');
        $this->actingAs($a['sales'])->post(route('sales-officer.sales.assign-source', $standard), ['source_type' => 'garage_tank', 'source_id' => $tank] + $this->garageDelivery($a))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sale_items', ['sale_id' => $standard, 'garage_tank_id' => $tank, 'purchase_item_id' => null]);
        $this->assertDatabaseHas('sale_source_changes', ['sale_id' => $standard, 'source_type' => 'garage_tank', 'storage_location_id' => $tank, 'source_label' => 'Premium Tank 1 (avg ₱55.20/L)']);
        $this->assertSame(500.0, app(GarageStockService::class)->tanks()->get($tank)->available);
    }

    // ----- F1 from a tank --------------------------------------------------------------------------------------

    public function test_an_f1_portion_from_a_tank_is_costed_at_the_f1_average_and_drawn_from_the_f1_lots(): void
    {
        $a = $this->actors();
        $f1Tank = $this->tank('F1', 1);
        $f1A = $this->purchaseLine($a, 'F1', 1000, 40);
        $f1B = $this->purchaseLine($a, 'F1', 3000, 44);
        $this->liftAndUnload($a, $f1A, 1000, [$f1Tank => 1000]);
        Carbon::setTestNow('2026-10-15 09:00:00');
        $this->liftAndUnload($a, $f1B, 3000, [$f1Tank => 3000]);
        $this->assertSame([4000.0, 172000.0, 43.0], $this->tankFigures($f1Tank));

        // A Standard Premium order from the depot with 10% F1 from the F1 tank: 400 L of F1.
        $premium = $this->purchaseLine($a, 'PREM', 10000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 4000, 'unit_price' => 60, 'f1_percentage' => 10, 'f1_purchase_item_id' => 'tank:'.$f1Tank, 'f1_unit_price' => 50])->assertSessionHasNoErrors();
        $sale = $this->lastSaleId();
        $this->assertDatabaseHas('sale_items', ['sale_id' => $sale, 'purchase_item_id' => $premium, 'f1_garage_tank_id' => $f1Tank, 'f1_purchase_item_id' => null]);
        $this->deliverOrder($a, $sale)->assertSessionHasNoErrors();

        $f1Out = DB::table('stock_outs')->where('sale_id', $sale)->where('portion', 'f1')->sole();
        $this->assertSame(['garage', $f1Tank, 400.0, 43.0], [$f1Out->source_type, (int) $f1Out->storage_location_id, (float) $f1Out->quantity_liters, (float) $f1Out->unit_cost]);
        $this->assertSame([$f1A => 100.0, $f1B => 300.0], DB::table('stock_out_draws')->where('stock_out_id', $f1Out->id)->pluck('liters', 'purchase_item_id')->map(fn (mixed $liters): float => (float) $liters)->all());
        // The order's own fuel still leaves its depot purchase.
        $this->assertDatabaseHas('stock_outs', ['sale_id' => $sale, 'portion' => 'main', 'source_type' => 'depot', 'purchase_item_id' => $premium, 'quantity_liters' => 3600]);
        // 900 L at ₱40 + 2,700 L at ₱44 = ₱154,800: the F1 average stays ₱43.00.
        $this->assertSame([3600.0, 154800.0, 43.0], $this->tankFigures($f1Tank));
    }

    // ----- No double deduction ---------------------------------------------------------------------------------

    public function test_a_tank_release_is_deducted_once_and_a_voided_one_gives_its_liters_back(): void
    {
        $a = $this->actors();
        [$tank, $purchaseA, $purchaseB] = $this->premiumTank($a);

        // Cancelled before delivery: the promise is freed, nothing left the tank.
        $this->garageOrder($a, $tank, 5000)->assertSessionHasNoErrors();
        $this->actingAs($a['sales'])->patch(route('sales-officer.sales.cancel', $this->lastSaleId()), ['cancel_reason' => 'wrong_customer'])->assertSessionHasNoErrors();
        $this->assertSame([12000.0, 12000.0], [app(GarageStockService::class)->tanks()->get($tank)->contents, app(GarageStockService::class)->tanks()->get($tank)->available]);

        // Delivered twice: released once.
        $this->garageOrder($a, $tank, 3000)->assertSessionHasNoErrors();
        $sale = $this->lastSaleId();
        $this->deliverOrder($a, $sale)->assertSessionHasNoErrors();
        $this->deliverOrder($a, $sale);
        $this->assertSame(1, DB::table('stock_outs')->where('sale_id', $sale)->count());
        $this->assertSame(2, DB::table('stock_out_draws')->count());
        $this->assertSame(3000.0, (float) DB::table('sale_items')->where('sale_id', $sale)->value('fulfilled_quantity_liters'));
        $this->assertSame(9000.0, app(GarageStockService::class)->tanks()->get($tank)->contents);
        $this->assertSame([2000.0, 1000.0], [app(PurchaseStockService::class)->line($purchaseA)->released, app(PurchaseStockService::class)->line($purchaseB)->released]);

        // A voided release counts nowhere: its draws go back to the lots and the purchases.
        DB::table('stock_outs')->where('sale_id', $sale)->update(['status' => 'cancelled']);
        $this->assertSame(12000.0, app(GarageStockService::class)->tanks()->get($tank)->contents);
        $this->assertSame([0.0, 0.0], [app(PurchaseStockService::class)->line($purchaseA)->released, app(PurchaseStockService::class)->line($purchaseB)->released]);
        $this->assertSame([8000.0, 4000.0], [app(PurchaseStockService::class)->line($purchaseA)->in_garage, app(PurchaseStockService::class)->line($purchaseB)->in_garage]);
    }

    // ----- Older lot-based sales -------------------------------------------------------------------------------

    public function test_an_older_open_lot_order_keeps_its_lot_and_tank_draws_leave_its_liters_alone(): void
    {
        $a = $this->actors();
        [$tank, $purchaseA, $purchaseB, $lotA, $lotB] = $this->premiumTank($a);
        // Recorded before tanks: 1,000 L promised from lot B.
        $legacy = $this->legacyLotSale($a, $lotB, $purchaseB, 1000, 'pending');
        $this->assertSame([12000.0, 11000.0], [app(GarageStockService::class)->tanks()->get($tank)->contents, app(GarageStockService::class)->tanks()->get($tank)->available]);

        // Its Edit form keeps the lot; new orders never see it.
        $page = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="so-sales-edit-'.$legacy.'".*?<option value="garage:'.$lotB.'"[^>]*selected/s', $page);
        $record = substr($page, strpos($page, 'id="so-sales-add"'), strpos($page, 'id="so-sales-edit-') - strpos($page, 'id="so-sales-add"'));
        $this->assertStringNotContainsString('value="garage:', $record);

        // A tank sale draws in proportion to what the lots can give: 8,000 : 3,000 (lot B's 1,000 L are held).
        $this->garageOrder($a, $tank, 3000)->assertSessionHasNoErrors();
        $sale = $this->lastSaleId();
        $this->deliverOrder($a, $sale)->assertSessionHasNoErrors();
        $draws = DB::table('stock_out_draws')->orderBy('id')->pluck('liters', 'garage_stock_id')->map(fn (mixed $liters): float => (float) $liters)->all();
        $this->assertSame([$lotA => 2181.82, $lotB => 818.18], $draws);
        $this->assertSame(55.20, (float) DB::table('stock_outs')->where('sale_id', $sale)->value('unit_cost'));

        // The older order still releases from its own lot, at the lot's cost.
        $this->deliverOrder($a, $legacy)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('stock_outs', ['sale_id' => $legacy, 'source_type' => 'garage', 'garage_stock_id' => $lotB, 'purchase_item_id' => $purchaseB, 'quantity_liters' => 1000, 'unit_cost' => 57.60]);
        $this->assertSame(2181.82, app(GarageStockService::class)->lot($lotB)->in_garage);
        $line = app(PurchaseStockService::class)->line($purchaseB);
        $this->assertSame([2181.82, 1818.18], [$line->in_garage, $line->released]);
        $this->assertSame($line->ordered, round($line->remaining_in_depot + $line->on_truck + $line->in_garage + $line->released, 2));
    }

    public function test_a_delivered_lot_sale_keeps_its_cost_purchase_and_label(): void
    {
        $a = $this->actors();
        [$tank, $purchaseA, , $lotA] = $this->premiumTank($a);
        $legacy = $this->legacyLotSale($a, $lotA, $purchaseA, 500, 'delivered');
        $old = DB::table('stock_outs')->insertGetId([
            'stock_out_code' => 'STO-LEGACY', 'sale_id' => $legacy, 'sale_item_id' => DB::table('sale_items')->where('sale_id', $legacy)->value('id'), 'customer_id' => $a['customer'],
            'fuel_type_id' => $this->fuel('PREM'), 'portion' => 'main', 'source_type' => 'garage', 'storage_location_id' => $tank, 'garage_stock_id' => $lotA, 'purchase_item_id' => $purchaseA,
            'quantity_liters' => 500, 'unit_cost' => 54, 'stock_out_at' => '2026-10-15 10:00:00', 'status' => 'released', 'created_at' => now(), 'updated_at' => now(),
        ]);
        // 7,500 L at ₱54 + 4,000 L at ₱57.60 = ₱635,400 / 11,500 L.
        $this->assertSame([11500.0, 635400.0, 55.25], $this->tankFigures($tank));

        $this->garageOrder($a, $tank, 1150)->assertSessionHasNoErrors();
        $this->deliverOrder($a, $this->lastSaleId())->assertSessionHasNoErrors();

        $row = DB::table('stock_outs')->where('id', $old)->first();
        $this->assertSame([$lotA, $purchaseA, 500.0, 54.0, 'released'], [(int) $row->garage_stock_id, (int) $row->purchase_item_id, (float) $row->quantity_liters, (float) $row->unit_cost, $row->status]);
        $this->assertSame(0, DB::table('stock_out_draws')->where('stock_out_id', $old)->count());
        $code = (string) DB::table('garage_stocks')->where('id', $lotA)->value('lot_code');
        $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory.stock-out'))->assertOk()
            ->assertSee('Garage — Premium Tank 1 · '.$code)
            ->assertSee('Garage — Premium Tank 1 (avg ₱55.25/L)');
    }

    // ----- Helpers ---------------------------------------------------------------------------------------------

    /** @return array{0: int, 1: int, 2: int, 3: int, 4: int} Premium Tank 1, purchases A and B, and their lots */
    private function premiumTank(array $a): array
    {
        $tank = $this->tank('PREM', 1);
        $purchaseA = $this->purchaseLine($a, 'PREM', 8000, 54);
        $purchaseB = $this->purchaseLine($a, 'PREM', 4000, 57.60);
        $this->liftAndUnload($a, $purchaseA, 8000, [$tank => 8000]);
        Carbon::setTestNow('2026-10-14 12:00:00');
        $this->liftAndUnload($a, $purchaseB, 4000, [$tank => 4000]);
        Carbon::setTestNow('2026-10-15 09:00:00');

        return [
            $tank, $purchaseA, $purchaseB,
            (int) DB::table('garage_stocks')->where('purchase_item_id', $purchaseA)->value('id'),
            (int) DB::table('garage_stocks')->where('purchase_item_id', $purchaseB)->value('id'),
        ];
    }

    /** @return array{0: float, 1: float, 2: ?float} contents, value, average cost */
    private function tankFigures(int $tank): array
    {
        $row = app(GarageStockService::class)->tanks()->get($tank);

        return [$row->contents, $row->value, $row->avg_cost];
    }

    /** A Garage to Client order recorded before tanks (sold from one lot), as such orders are stored. */
    private function legacyLotSale(array $a, int $lotId, int $purchaseItemId, float $liters, string $deliveryStatus): int
    {
        $sale = DB::table('sales')->insertGetId([
            'sale_code' => 'SLS-LOT-'.Str::upper(Str::random(4)), 'delivery_type' => 'garage_to_client', 'customer_id' => $a['customer'], 'sale_date' => '2026-10-15',
            'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment', 'status' => 'confirmed', 'delivery_status' => $deliveryStatus,
            'delivery_truck_id' => $a['truck'], 'delivery_driver_user_id' => $a['driver']->id, 'delivery_date' => '2026-10-16 10:00:00', 'created_by' => $a['sales']->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sale_items')->insert([
            'sale_id' => $sale, 'fuel_type_id' => $this->fuel('PREM'), 'purchase_item_id' => $purchaseItemId, 'garage_stock_id' => $lotId, 'quantity_liters' => $liters,
            'main_quantity_liters' => $liters, 'f1_quantity_liters' => 0, 'f1_percentage' => 0, 'unit_price' => 70, 'line_total' => $liters * 70,
            'fulfilled_quantity_liters' => $deliveryStatus === 'delivered' ? $liters : 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('receivables')->insert(['sale_id' => $sale, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        return $sale;
    }

    private function garageOrder(array $a, int $tankId, float $liters, array $line = [])
    {
        return $this->recordOrder($a, ['purchase_item_id' => 'tank:'.$tankId, 'quantity_liters' => $liters, 'unit_price' => 70] + $line, ['delivery_type' => 'garage_to_client'] + $this->garageDelivery($a));
    }

    private function tank(string $fuelCode, int $number): int
    {
        $this->actingAs(\App\Models\User::query()->where('role', 'admin')->first() ?? \App\Models\User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']))
            ->get(route('admin.inventory'))->assertOk();

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

    private function saleModal(array $a, int $saleId): string
    {
        $html = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $modal = substr($html, strpos($html, 'id="so-sales-edit-'.$saleId.'"'));

        return substr($modal, 0, strpos($modal, 'id="so-payment-history-'.$saleId.'"'));
    }

    private function code(int $purchaseItemId): string
    {
        return (string) DB::table('purchase_items')->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')->where('purchase_items.id', $purchaseItemId)->value('purchases.purchase_code');
    }
}
