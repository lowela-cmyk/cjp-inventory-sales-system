<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DashboardService;
use App\Services\GarageStockService;
use App\Services\InventoryLedgerService;
use App\Services\PurchaseStockService;
use App\Services\TruckAvailabilityService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Concerns\PurchaseFlows;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Part 13 (October 28, 2026): row tints only for Unpaid / Cancelled, the Payment Status chart counts customers, fuel
 * left on a truck is unloaded into garage tanks (Tank 1 / Tank 2 per fuel, 24,000 L each) and leaves the garage only
 * through Garage to Client sales; Standard sells only what is left in the depot.
 */
class Part13RevisionsTest extends AuditTestCase
{
    use PurchaseFlows;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-28 09:00:00');
    }

    // ----- 1. Row tints ---------------------------------------------------------------------------------------

    public function test_only_unpaid_and_cancelled_rows_are_tinted_red(): void
    {
        $a = $this->actors();
        User::factory()->create(['role' => 'driver', 'status' => 'active', 'approval_status' => 'pending']);
        User::factory()->create(['role' => 'driver', 'status' => 'active', 'approval_status' => 'rejected']);
        $html = $this->actingAs($a['admin'])->get(route('admin.account-requests'))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('/<tr class="row-/', $html);

        $premium = $this->purchaseLine($a, 'PREM', 10000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 100, 'unit_price' => 50])->assertSessionHasNoErrors();
        $unpaid = $this->lastSaleId();
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 100, 'unit_price' => 50])->assertSessionHasNoErrors();
        $partial = $this->lastSaleId();
        $this->pay($a, $partial, 1000);
        DB::table('customers')->insert(['customer_code' => 'CUS-OFF', 'name' => 'Inactive Co', 'company_name' => 'Inactive Co', 'payment_status' => 'clear', 'status' => 'inactive', 'created_at' => now(), 'updated_at' => now()]);

        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 100, 'unit_price' => 50])->assertSessionHasNoErrors();
        $cancelled = $this->lastSaleId();
        $this->actingAs($a['sales'])->patch(route('sales-officer.sales.cancel', $cancelled), ['cancel_reason' => 'wrong_customer'])->assertSessionHasNoErrors();

        $sales = collect($this->actingAs($a['sales'])->get(route('sales-officer.sales'))->viewData('sales')->items())->keyBy('id');
        $this->assertSame(['row-danger', '', 'row-danger'], [$sales[$unpaid]['class'], $sales[$partial]['class'], $sales[$cancelled]['class']]);
        $customers = collect($this->actingAs($a['sales'])->get(route('sales-officer.sales.customers'))->viewData('customers')->items());
        $this->assertSame([], $customers->pluck('class')->reject(fn (string $class): bool => in_array($class, ['', 'row-danger'], true))->values()->all());
        $this->assertSame('', $customers->firstWhere('name', 'Inactive Co')['class'] ?? '');
    }

    // ----- 2. Payment Status chart counts customers ------------------------------------------------------------

    public function test_the_payment_status_chart_counts_the_same_customers_as_the_kpis(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 10000);
        $second = DB::table('customers')->insertGetId(['customer_code' => 'CUS-2', 'name' => 'Second', 'company_name' => 'Second Co', 'payment_status' => 'clear', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $third = DB::table('customers')->insertGetId(['customer_code' => 'CUS-3', 'name' => 'Third', 'company_name' => 'Third Co', 'payment_status' => 'clear', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[$a['customer'], 5000], [$a['customer'], 5000], [$second, 1000], [$third, 0]] as [$customer, $paid]) {
            $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 100, 'unit_price' => 50], ['customer_id' => $customer])->assertSessionHasNoErrors();
            if ($paid > 0) {
                $this->pay($a, $this->lastSaleId(), $paid);
            }
        }

        $trend = app(DashboardService::class)->salesTrend([]);
        // Customer 1: both sales paid. Second: owes, paid something. Third: owes, paid nothing.
        $this->assertSame(['paid' => 1, 'unpaid' => 1, 'partial' => 1], $trend['totals']['payment_status']);
        $this->assertSame([1, 2], [$trend['totals']['paid_customers'], $trend['totals']['unpaid_customers']]);
        $this->assertSame($trend['totals']['payment_status']['unpaid'] + $trend['totals']['payment_status']['partial'], $trend['totals']['unpaid_customers']);
        $chart = $trend['paymentChart'];
        $this->assertSame(['Paid', 'Unpaid', 'Partially Paid'], $chart['labels']);
        $this->assertSame([1, 1, 1], $chart['datasets'][0]['data']);
        $this->assertSame('Customers', $chart['datasets'][0]['label']);
        $this->actingAs($a['admin'])->get(route('admin.dashboard'))->assertOk()->assertSee('Payment Status (Customers)');
    }

    // ----- 3. Unload to Garage ---------------------------------------------------------------------------------

    public function test_leftover_fuel_is_unloaded_into_the_chosen_tanks_within_their_capacity(): void
    {
        $a = $this->actors();
        $diesel = $this->purchaseLine($a, 'DSL', 60000);
        $this->scheduleLift($a, [['purchase_item_id' => $diesel, 'quantity_liters' => 30000]])->assertSessionHasNoErrors();
        $lift = $this->lastHaulId();
        $this->completeLift($a, $lift);
        [$t1, $t2] = [$this->tank('DSL', 1), $this->tank('DSL', 2)];
        $trucks = app(TruckAvailabilityService::class);
        $this->assertSame(30000.0, $trucks->leftoverOnTruck($a['truck']));

        // The View modal offers the Diesel tanks, each with its own liters field (no Return to Depot any more).
        $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting'))->assertOk()
            ->assertSee('name="unload['.$lift.']['.$t1.']"', false)->assertSee('name="unload['.$lift.']['.$t2.']"', false)
            ->assertSee('Diesel Tank 1')->assertSee('0.00 L / 24,000.00 L — 24,000.00 L free')
            ->assertDontSee('Return to Depot');
        $this->assertFalse(Route::has('dispatch.fuel-lifting.hauls.return-to-depot'));

        // Over a tank's capacity, or into another fuel's tank: refused, nothing written.
        $this->unload($a, $lift, [$t1 => 24001])->assertSessionHasErrors("unload.$lift.$t1");
        $this->unload($a, $lift, [$this->tank('PREM', 1) => 1000])->assertSessionHasErrors("unload.$lift.".$this->tank('PREM', 1));
        $this->assertSame(0, DB::table('garage_stocks')->count());

        // Split: 20,000 L into Tank 1 and 4,000 L into Tank 2; 6,000 L stay on the truck, which stays Loaded.
        $this->unload($a, $lift, [$t1 => 20000, $t2 => 4000])->assertSessionHasNoErrors();
        $tanks = app(GarageStockService::class)->tanks();
        $this->assertSame([20000.0, 4000.0], [$tanks[$t1]->contents, $tanks[$t2]->contents]);
        $this->assertSame([4000.0, 20000.0], [$tanks[$t1]->free, $tanks[$t2]->free]);
        $this->assertSame(6000.0, $trucks->leftoverOnTruck($a['truck']));
        $this->assertSame('loaded', DB::table('trucks')->where('id', $a['truck'])->value('status'));

        // A second lot in Tank 1 may only fill its 4,000 L of free space (two lots, one 24,000 L limit).
        $this->unload($a, $lift, [$t1 => 4001])->assertSessionHasErrors("unload.$lift.$t1");
        $this->unload($a, $lift, [$t1 => 4000, $t2 => 2000])->assertSessionHasNoErrors();
        $tanks = app(GarageStockService::class)->tanks();
        $this->assertSame([24000.0, 6000.0, 0.0], [$tanks[$t1]->contents, $tanks[$t2]->contents, $tanks[$t1]->free]);
        $this->assertSame(2, DB::table('garage_stocks')->where('storage_location_id', $t1)->count());
        $this->assertSame(0.0, $trucks->leftoverOnTruck($a['truck']));
        $this->assertSame('available', DB::table('trucks')->where('id', $a['truck'])->value('status'));
        $this->unload($a, $lift, [$t2 => 1])->assertSessionHasErrors('lifting');

        // The garage keeps its purchase, fuel and cost; the depot gets nothing back; the totals add up.
        $line = app(PurchaseStockService::class)->line($diesel);
        $this->assertSame([30000.0, 0.0, 30000.0, 0.0], [$line->remaining_in_depot, $line->on_truck, $line->in_garage, $line->released]);
        $this->assertSame($line->ordered, $line->remaining_in_depot + $line->on_truck + $line->in_garage + $line->released);
        $this->assertSame(60000.0, $line->on_hand, 'Total Inventory includes the garage.');
        $this->assertDatabaseHas('garage_stocks', ['storage_location_id' => $t2, 'purchase_item_id' => $diesel, 'unit_cost' => 50, 'liters' => 4000, 'truck_id' => $a['truck'], 'unloaded_by' => $a['admin']->id]);

        // Both Garage tabs show Tank 1 and Tank 2 separately, and the lots with their tank.
        foreach ([[$a['inventory'], 'inventory-officer.inventory.garage'], [$a['dispatch'], 'dispatch.fuel-lifting.garage']] as [$user, $route]) {
            $page = $this->actingAs($user)->get(route($route))->assertOk()->getContent();
            // The Garage tab is the open one.
            $this->assertStringContainsString('is-active" type="button" data-tab-target="garage"', $page);
            $this->assertDoesNotMatchRegularExpression('/data-tab-panel="garage"\s+hidden/', $page);
            $this->assertStringContainsString('data-garage-tank="'.$t1.'"', $page);
            $this->assertStringContainsString('data-garage-tank="'.$t2.'"', $page);
            $this->assertStringContainsString('24,000.00 L <small>(100%)</small>', $page);
            $this->assertStringContainsString('6,000.00 L <small>(25%)</small>', $page);
            // Part 16 #5: one row per tank (8), each with a View; the lots are in the tank's View modal:
            // Tank 1: 20,000 + 4,000 (first and second unload), Tank 2: 4,000 + 2,000.
            $this->assertSame(8, substr_count($page, 'data-modal-open="garage-tank-'));
            $this->assertStringContainsString('<td><strong>Diesel Tank 1</strong></td>', $page);
            foreach ([$t1 => 2, $t2 => 2] as $tank => $lots) {
                $modal = substr($page, strpos($page, 'id="garage-tank-'.$tank.'"'), 6000);
                $this->assertSame($lots, substr_count(substr($modal, 0, strpos($modal, '</tbody>')), '<td class="cell-id">'));
            }
            $filtered = $this->actingAs($user)->get(route($route, ['tank_id' => $t2]))->assertOk()->getContent();
            $this->assertSame(1, substr_count($filtered, 'data-modal-open="garage-tank-'));
            $this->assertStringContainsString('<td><strong>Diesel Tank 2</strong></td>', $filtered);
        }
        $this->actingAs($a['admin'])->get(route('admin.dashboard'))->assertOk()->assertSee('Garage Tanks')->assertSee('Tank 1: 24,000.00 L (100%)')->assertSee('Tank 2: 6,000.00 L (25%)');
        $this->actingAs($a['driver'])->get('/dispatch/fuel-lifting/garage')->assertForbidden();
    }

    // ----- 4. Garage to Client ---------------------------------------------------------------------------------

    public function test_garage_to_client_sells_from_its_tank_once_and_standard_only_from_the_depot(): void
    {
        $a = $this->actors();
        $diesel = $this->purchaseLine($a, 'DSL', 60000);
        $this->scheduleLift($a, [['purchase_item_id' => $diesel, 'quantity_liters' => 30000]])->assertSessionHasNoErrors();
        $lift = $this->lastHaulId();
        $this->completeLift($a, $lift);
        $t2 = $this->tank('DSL', 2);
        $this->unload($a, $lift, [$this->tank('DSL', 1) => 24000, $t2 => 6000])->assertSessionHasNoErrors();
        $lot = (int) DB::table('garage_stocks')->where('storage_location_id', $t2)->value('id');

        // Standard can only take what is left in the depot (30,000 L), not the truck / garage liters.
        $this->recordOrder($a, ['purchase_item_id' => $diesel, 'quantity_liters' => 30001, 'unit_price' => 60])
            ->assertSessionHasErrors(['items.0.quantity_liters' => 'Only 30,000.00 L of '.$this->purchaseCode($diesel).' (Diesel) are left in the depot for this order.']);

        // The Garage to Client dropdown names the tank.
        $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()
            ->assertSee('<option value="garage:'.$lot.'" data-source="garage"', false)->assertSee('Diesel Tank 2 · 6,000 L');

        // Two open orders cannot promise more than the lot holds.
        $this->garageOrder($a, $lot, 4000)->assertSessionHasNoErrors();
        $first = $this->lastSaleId();
        $this->garageOrder($a, $lot, 2001)->assertSessionHasErrors(['items.0.quantity_liters' => 'Only 2,000.00 L of Diesel are available in Diesel Tank 2 ('.$this->purchaseCode($diesel).') for this order.']);
        $this->assertDatabaseHas('sale_items', ['sale_id' => $first, 'garage_stock_id' => $lot, 'purchase_item_id' => $diesel]);

        // A lot whose liters are all promised is no longer offered in Record Sales, but stays on its own order's Edit form.
        $this->garageOrder($a, $lot, 2000)->assertSessionHasNoErrors();
        $reserved = $this->lastSaleId();
        $page = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $recordForm = substr($page, strpos($page, 'id="so-sales-add"'), strpos($page, 'id="so-sales-edit-') - strpos($page, 'id="so-sales-add"'));
        $this->assertStringNotContainsString('value="garage:'.$lot.'"', $recordForm);
        $this->assertMatchesRegularExpression('/id="so-sales-edit-'.$reserved.'".*?<option value="garage:'.$lot.'"[^>]*selected/s', $page);
        $this->actingAs($a['sales'])->patch(route('sales-officer.sales.cancel', $reserved), ['cancel_reason' => 'wrong_customer'])->assertSessionHasNoErrors();
        $this->assertSame(6000.0, app(GarageStockService::class)->tanks()[$t2]->contents, 'Nothing leaves the tank before delivery.');

        // Delivered: 4,000 L leave Tank 2 once, from the lot, and free its space; the depot is untouched.
        $this->deliverOrder($a, $first)->assertSessionHasNoErrors();
        $this->deliverOrder($a, $first);
        $stockOut = DB::table('stock_outs')->where('sale_id', $first)->get();
        $this->assertCount(1, $stockOut);
        $this->assertSame(['garage', $lot, $t2, 4000.0], [$stockOut[0]->source_type, (int) $stockOut[0]->garage_stock_id, (int) $stockOut[0]->storage_location_id, (float) $stockOut[0]->quantity_liters]);
        $tank = app(GarageStockService::class)->tanks()[$t2];
        $this->assertSame([2000.0, 22000.0], [$tank->contents, $tank->free]);
        $line = app(PurchaseStockService::class)->line($diesel);
        $this->assertSame([30000.0, 26000.0, 4000.0], [$line->remaining_in_depot, $line->in_garage, $line->released]);
        $this->assertSame($line->ordered, $line->remaining_in_depot + $line->on_truck + $line->in_garage + $line->released);

        // Stock Out names the tank, lot and lift; Receivables shows the delivery type.
        $code = (string) DB::table('garage_stocks')->where('id', $lot)->value('lot_code');
        $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory.stock-out', ['delivery_type' => 'garage_to_client']))->assertOk()
            ->assertSee('Garage — Diesel Tank 2 · '.$code)->assertSee('Garage to Client');
        $this->actingAs($a['sales'])->get(route('sales-officer.sales', ['delivery_type' => 'garage_to_client']))->assertOk()->assertSee('Garage to Client');

        // No double counting: the ledger counts the lift once; the dashboard's stock includes the garage, the depot
        // balance does not.
        $ledger = app(InventoryLedgerService::class)->rows()['transactions']->firstWhere('purchase_code', $this->purchaseCode($diesel));
        $this->assertSame('30,000.00 L', $ledger['details']['Total Lifted']);
        $totals = app(DashboardService::class)->data([], [])['inventory']['totals'];
        $this->assertSame(56000.0, $totals['stock_liters']);
        $this->assertSame(30000.0, $totals['remaining_liters']);
    }

    public function test_the_f1_portion_of_a_garage_order_comes_from_an_f1_tank_or_an_f1_purchase(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $f1 = $this->purchaseLine($a, 'F1', 5000);
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 10000], ['purchase_item_id' => $f1, 'quantity_liters' => 2000]])->assertSessionHasNoErrors();
        $haul = DB::table('hauls')->orderBy('id')->pluck('id')->all();
        $this->completeLift($a, $this->lastHaulId());
        $this->unload($a, (int) $haul[0], [$this->tank('PREM', 1) => 10000, $this->tank('F1', 1) => 2000], [(int) $haul[0], (int) $haul[1]])->assertSessionHasNoErrors();
        $premiumLot = (int) DB::table('garage_stocks')->where('purchase_item_id', $premium)->value('id');
        $f1Lot = (int) DB::table('garage_stocks')->where('purchase_item_id', $f1)->value('id');

        // F1 from the F1 garage tank.
        $this->garageOrder($a, $premiumLot, 1000, ['f1_percentage' => 10, 'f1_purchase_item_id' => 'garage:'.$f1Lot, 'f1_unit_price' => 40], 'delivered')->assertSessionHasNoErrors();
        $sale = $this->lastSaleId();
        $this->assertDatabaseHas('sale_items', ['sale_id' => $sale, 'garage_stock_id' => $premiumLot, 'f1_garage_stock_id' => $f1Lot, 'f1_purchase_item_id' => $f1]);
        $this->assertSame(100.0, (float) DB::table('stock_outs')->where('sale_id', $sale)->where('portion', 'f1')->where('garage_stock_id', $f1Lot)->sum('quantity_liters'));
        $this->assertSame(1900.0, app(GarageStockService::class)->lot($f1Lot)->in_garage);

        // F1 from the F1 purchase in the depot.
        $this->garageOrder($a, $premiumLot, 1000, ['f1_percentage' => 10, 'f1_purchase_item_id' => $f1, 'f1_unit_price' => 40], 'delivered')->assertSessionHasNoErrors();
        $sale = $this->lastSaleId();
        $f1Out = DB::table('stock_outs')->where('sale_id', $sale)->where('portion', 'f1')->first();
        $this->assertSame(['depot', null, 100.0], [$f1Out->source_type, $f1Out->garage_stock_id, (float) $f1Out->quantity_liters]);
        $this->assertSame(1900.0, app(GarageStockService::class)->lot($f1Lot)->in_garage);

        // Part 16 #1: a Standard order may take its F1 portion from an F1 garage tank too; on delivery it leaves the tank.
        $depotPremium = $this->purchaseLine($a, 'PREM', 5000);
        $this->recordOrder($a, ['purchase_item_id' => $depotPremium, 'quantity_liters' => 1000, 'unit_price' => 50, 'f1_percentage' => 10, 'f1_purchase_item_id' => 'garage:'.$f1Lot, 'f1_unit_price' => 40])
            ->assertSessionHasNoErrors();
        $this->deliverOrder($a, $this->lastSaleId())->assertSessionHasNoErrors();
        $this->assertDatabaseHas('stock_outs', ['sale_id' => $this->lastSaleId(), 'portion' => 'f1', 'source_type' => 'garage', 'garage_stock_id' => $f1Lot, 'quantity_liters' => 100]);
        $this->assertSame(1800.0, app(GarageStockService::class)->lot($f1Lot)->in_garage);
    }

    /**
     * @param  array<int, float>  $perTank  tank id => liters
     * @param  array<int, int>|null  $lines  lift lines: one entry per line, tanks matched by fuel
     */
    private function unload(array $a, int $haulId, array $perTank, ?array $lines = null)
    {
        $unload = [];
        if ($lines === null) {
            $unload[$haulId] = $perTank;
        } else {
            foreach ($lines as $line) {
                $fuel = (int) DB::table('hauls')->where('id', $line)->value('fuel_type_id');
                $unload[$line] = array_filter($perTank, fn (int $tank): bool => (int) DB::table('storage_locations')->where('id', $tank)->value('fuel_type_id') === $fuel, ARRAY_FILTER_USE_KEY);
            }
        }

        return $this->actingAs($a['admin'])->from(route('admin.fuel-lifting'))->post(route('admin.fuel-lifting.hauls.unload-to-garage', $haulId), ['unload' => $unload]);
    }

    private function garageOrder(array $a, int $lotId, float $liters, array $line = [], string $status = 'pending')
    {
        return $this->recordOrder($a, ['purchase_item_id' => 'garage:'.$lotId, 'quantity_liters' => $liters, 'unit_price' => 60] + $line, [
            'delivery_type' => 'garage_to_client', 'delivery_status' => $status,
            'delivery_truck_id' => $a['truck'], 'delivery_driver_user_id' => $a['driver']->id, 'delivery_date' => '2026-10-28 10:00',
        ]);
    }

    private function tank(string $fuelCode, int $number): int
    {
        return (int) DB::table('storage_locations')->where('type', 'garage')->where('status', 'active')
            ->where('fuel_type_id', $this->fuel($fuelCode))->where('tank_number', $number)->value('id');
    }

    private function purchaseCode(int $purchaseItemId): string
    {
        return (string) DB::table('purchase_items')->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')->where('purchase_items.id', $purchaseItemId)->value('purchases.purchase_code');
    }

    private function pay(array $a, int $saleId, float $amount): void
    {
        $this->actingAs($a['sales'])->post(route('sales-officer.sales.payments.store', $saleId), [
            'idempotency_key' => (string) Str::uuid(), 'payment_sale_id' => $saleId, 'amount' => $amount, 'method' => 'cash_on_delivery', 'payment_date' => '2026-10-28',
        ])->assertSessionHasNoErrors();
    }
}
