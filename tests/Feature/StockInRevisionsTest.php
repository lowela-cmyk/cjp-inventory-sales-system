<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DashboardService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PurchaseFlows;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Inventory > Stock-In (October 23, 2026): a purchase is stocked in the moment it is recorded (no lift, no tank),
 * sales deduct from the purchase they select as soon as they are saved, and cancelling a sale gives the liters back.
 */
class StockInRevisionsTest extends AuditTestCase
{
    use PurchaseFlows;

    protected function setUp(): void
    {
        parent::setUp();
        // Purchases in PurchaseFlows are dated 10/14/2026.
        Carbon::setTestNow('2026-10-14 09:00:00');
    }

    public function test_recording_a_purchase_stocks_it_in_and_sales_deduct_from_it_end_to_end(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 60000, 55);
        $code = (string) DB::table('purchases')->join('purchase_items', 'purchase_items.purchase_id', '=', 'purchases.id')->where('purchase_items.id', $premium)->value('purchase_code');

        // 1. Recorded purchase = Stock-In row right away: Available, nothing out, all of it remaining.
        $stockIn = DB::table('stock_ins')->where('purchase_item_id', $premium)->first();
        $this->assertSame([60000.0, 55.0, 3300000.0, 'active', '2026-10-14'], [(float) $stockIn->quantity_liters, (float) $stockIn->unit_cost, (float) $stockIn->total_cost, $stockIn->status, substr((string) $stockIn->stock_in_date, 0, 10)]);
        $row = $this->stockInRow($a['inventory'], $code);
        $this->assertSame(['10/14/2026', 'Premium', '60,000.00', '55.00', '3,300,000.00', '0.00', '60,000.00', 'Available'], [$row['date'], $row['fuel'], $row['purchased'], $row['unit_cost'], $row['total_cost'], $row['stock_out'], $row['remaining'], $row['status']]);

        // 2. Record Sales lists it as "PUR-… – PREMIUM – 60,000 L in stock – ₱55.00/L".
        $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->assertSee($code.' – PREMIUM – 60,000 L in stock – ₱55.00/L');

        // 3. A sale deducts when it is saved (Standard, not delivered yet)...
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 10000, 'unit_price' => 65], ['sales_order_number' => 'SO-STD'])->assertSessionHasNoErrors();
        $row = $this->stockInRow($a['inventory'], $code);
        $this->assertSame(['10,000.00', '50,000.00', 'Partially Used'], [$row['stock_out'], $row['remaining'], $row['status']]);
        $this->assertSame('Not delivered yet', $row['deductions'][0]['stock_out']);
        $this->assertSame('Standard', $row['deductions'][0]['delivery_type']);

        // ...and so does a Depot to Client sale. More than what is left is rejected.
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 50001, 'unit_price' => 65], ['delivery_type' => 'depot_to_client'])->assertSessionHasErrors('items.0.quantity_liters');
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 5000, 'unit_price' => 65], ['delivery_type' => 'depot_to_client', 'sales_order_number' => 'SO-DTC'])->assertSessionHasNoErrors();
        $depotSale = $this->lastSaleId();
        $row = $this->stockInRow($a['inventory'], $code);
        $this->assertSame(['15,000.00', '45,000.00'], [$row['stock_out'], $row['remaining']]);
        $this->assertSame(['Depot to Client', 'Standard'], array_column($row['deductions'], 'delivery_type'));

        // 4. Delivered: it appears in Stock Out with its purchase ID, at the purchase's cost/liter. Remaining is unchanged.
        $standardSale = (int) DB::table('sales')->where('sales_order_number', 'SO-STD')->value('id');
        $this->deliverOrder($a, $standardSale)->assertSessionHasNoErrors();
        $stockOut = collect($this->viewData($a['inventory'], 'inventory-officer.inventory.stock-out', 'stockOut')->items())->first();
        $this->assertSame(['Standard', $code, '10,000.00', '55.00', '550,000.00'], [$stockOut['cells'][4], $stockOut['cells'][6], $stockOut['cells'][7], $stockOut['cells'][8], $stockOut['cells'][9]]);
        $row = $this->stockInRow($a['inventory'], $code);
        $this->assertSame(['15,000.00', '45,000.00'], [$row['stock_out'], $row['remaining']]);
        $this->assertStringStartsWith('STO-', $row['deductions'][1]['stock_out']);

        // 5. Editing the purchase: not below what sales took; a later cost change leaves past Stock Out rows as released.
        $edit = fn (array $changes) => $this->actingAs($a['inventory'])->from(route('inventory-officer.inventory'))
            ->patch(route('inventory-officer.inventory.purchases.update', $premium), $changes + [
                'purchase_date' => '2026-10-14', 'depot_id' => $a['depot'], 'fuel_type_id' => $this->fuel('PREM'),
                'quantity_ordered_liters' => 60000, 'unit_cost' => 55, 'payment_status' => 'unpaid', 'payment_mode' => 'cash',
            ]);
        $edit(['quantity_ordered_liters' => 14999])->assertSessionHasErrors('quantity_ordered_liters');
        $edit(['fuel_type_id' => $this->fuel('DSL')])->assertSessionHasErrors('fuel_type_id');
        $edit(['quantity_ordered_liters' => 40000, 'unit_cost' => 56])->assertSessionHasNoErrors();
        $row = $this->stockInRow($a['inventory'], $code);
        $this->assertSame(['40,000.00', '56.00', '2,240,000.00', '15,000.00', '25,000.00'], [$row['purchased'], $row['unit_cost'], $row['total_cost'], $row['stock_out'], $row['remaining']]);
        $stockOut = collect($this->viewData($a['inventory'], 'inventory-officer.inventory.stock-out', 'stockOut')->items())->first();
        $this->assertSame(['55.00', '550,000.00'], [$stockOut['cells'][8], $stockOut['cells'][9]]);
        $this->assertSame(55.0, (float) DB::table('stock_outs')->where('sale_id', $standardSale)->value('unit_cost'));

        // 6. Cancelling a purchase sales took from is blocked, naming the sales.
        $this->actingAs($a['inventory'])->from(route('inventory-officer.inventory'))
            ->patch(route('inventory-officer.inventory.purchases.cancel', $premium))
            ->assertSessionHasErrors(['purchase' => 'This purchase cannot be cancelled: 2 sale(s) already deducted fuel from it ('.implode(', ', DB::table('sales')->orderBy('sale_code')->pluck('sale_code')->all()).'). Cancel those sales first.']);

        // 7. Cancelling a sale gives its liters back.
        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))
            ->patch(route('sales-officer.sales.cancel', $depotSale), ['cancel_reason' => 'duplicate_entry'])->assertSessionHasNoErrors();
        $row = $this->stockInRow($a['inventory'], $code);
        $this->assertSame(['10,000.00', '30,000.00', 'Partially Used'], [$row['stock_out'], $row['remaining'], $row['status']]);
        $this->assertCount(1, $row['deductions']);
    }

    public function test_a_cancelled_purchase_voids_its_stock_in_and_a_fully_sold_one_is_depleted(): void
    {
        $a = $this->actors();
        $diesel = $this->purchaseLine($a, 'DSL', 8000);
        $unleaded = $this->purchaseLine($a, 'UNL', 3000);

        $this->actingAs($a['inventory'])->patch(route('inventory-officer.inventory.purchases.cancel', $diesel))->assertSessionHasNoErrors();
        $this->assertSame('voided', DB::table('stock_ins')->where('purchase_item_id', $diesel)->value('status'));
        $this->assertNotNull(DB::table('stock_ins')->where('purchase_item_id', $diesel)->value('voided_at'));

        $this->recordOrder($a, ['purchase_item_id' => $unleaded, 'quantity_liters' => 3000, 'unit_price' => 60])->assertSessionHasNoErrors();

        $rows = collect($this->viewData($a['inventory'], 'inventory-officer.inventory.stock-in', 'stockIn')->items())->keyBy('fuel');
        $this->assertSame(['Cancelled', '0.00'], [$rows['Diesel']['status'], $rows['Diesel']['remaining']]);
        $this->assertSame(['Depleted', '3,000.00', '0.00'], [$rows['Unleaded']['status'], $rows['Unleaded']['stock_out'], $rows['Unleaded']['remaining']]);

        // A cancelled purchase leaves the sales dropdown and the totals.
        $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->assertDontSee('– DIESEL –');
        $this->assertSame('0 L', $this->cards($a['inventory'])['Total Inventory']);
    }

    public function test_kpi_cards_and_filters_read_the_stock_ins(): void
    {
        $a = $this->actors();
        $north = DB::table('depots')->insertGetId(['depot_code' => 'DEP-N', 'name' => 'North Depot', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $this->actingAs($a['inventory'])->post(route('inventory-officer.inventory.purchases.store'), [
            'idempotency_key' => (string) Str::uuid(), 'purchase_date' => '2026-10-10', 'depot_id' => $north, 'fuel_type_id' => $this->fuel('DSL'),
            'quantity_ordered_liters' => 7000, 'unit_cost' => 50, 'payment_status' => 'paid', 'payment_mode' => 'cash',
        ])->assertSessionHasNoErrors();
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 4000, 'unit_price' => 60], ['delivery_status' => 'delivered'])->assertSessionHasNoErrors();
        DB::table('stock_outs')->update(['stock_out_at' => '2026-10-14 08:00:00']);

        // Total = 16,000 + 7,000 remaining; today's stock-in is the 10/14 purchase; 4,000 L released today.
        $cards = $this->cards($a['inventory']);
        $this->assertSame(['23,000 L', '20,000 L', '4,000 L', '2'], [$cards['Total Inventory'], $cards['Stock-In Today'], $cards['Stock-Out Today'], $cards['Open Purchases']]);

        // Filters narrow the table and the cards; the other tabs stay hidden.
        $cards = $this->cards($a['inventory'], 'inventory-officer.inventory.stock-in', ['depot_id' => $north]);
        $this->assertSame(['7,000 L', '0 L', '0 L'], [$cards['Total Inventory'], $cards['Stock-In Today'], $cards['Stock-Out Today']]);
        $cards = $this->cards($a['inventory'], 'inventory-officer.inventory.stock-in', ['date' => '2026-10-10']);
        $this->assertSame(['7,000 L', '7,000 L'], [$cards['Total Inventory'], $cards['Stock-In on 10/10/2026']]);
        $cards = $this->cards($a['inventory'], 'inventory-officer.inventory.stock-in', ['status' => 'partially_used']);
        $this->assertSame(['16,000 L', '4,000 L'], [$cards['Total Inventory'], $cards['Stock-Out Today']]);

        $html = $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory.stock-in', ['fuel_type_id' => $this->fuel('DSL')]))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<section data-tab-panel="stock-in"\s*>/', $html);
        foreach (['purchases', 'stock-out', 'depots'] as $tab) {
            $this->assertMatchesRegularExpression('/<section data-tab-panel="'.$tab.'"\s+hidden\s*>/', $html);
        }
        $panel = $this->tabPanel($html, 'stock-in');
        $tbody = substr($panel, strpos($panel, '<tbody>'));
        $this->assertStringContainsString('North Depot', $tbody);
        $this->assertStringNotContainsString('Premium', $tbody);
        // Date is a dropdown of stock-in dates.
        $this->assertMatchesRegularExpression('/<select name="date"[^>]*>.*<option value="2026-10-14"[^>]*>10\/14\/2026<\/option>/s', $panel);

        // The table: the agreed columns, no tank or garage fields, no manual Stock-In.
        foreach (['Purchase ID', 'Date', 'Fuel Type', 'Depot', 'Qty Purchased (L)', 'Cost / Liter', 'Total Cost', 'Stock Out (L)', 'Remaining (L)', 'Status', 'Actions'] as $column) {
            $this->assertStringContainsString('<th>'.$column.'</th>', str_replace(' class="inventory-status-column"', '', $panel));
        }
        foreach (['Garage', 'Tank', 'Record Stock-In', 'Add Stock In', 'Pipeline'] as $retired) {
            $this->assertStringNotContainsString($retired, $panel);
        }
    }

    public function test_the_dashboard_reads_the_same_stock_in_figures(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $this->purchaseLine($a, 'DSL', 5000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 5000, 'unit_price' => 60])->assertSessionHasNoErrors();

        $inventory = app(DashboardService::class)->inventory();
        $this->assertSame(20000.0, $inventory['totals']['stock_liters']);
        $this->assertSame(['Premium' => 15000.0, 'Unleaded' => 0.0, 'Diesel' => 5000.0, 'F1' => 0.0], $inventory['totals']['stock_by_fuel']);
        $this->assertSame(['Completed', 'Uncompleted'], $inventory['purchasedChart']['labels']);
        $this->assertSame('20,000 L', $this->cards($a['inventory'])['Total Inventory']);
    }

    public function test_unlifted_fuel_card_and_dashboard_depot_balance_agree(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 40000, 55);

        // 3,600 L delivered straight from the depot (Standard), 5,000 L carried by a lift the driver marked Lifted.
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 3600, 'unit_price' => 60], ['delivery_status' => 'delivered'])->assertSessionHasNoErrors();
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 5000, 'unit_price' => 60], ['delivery_type' => 'depot_to_client'])->assertSessionHasNoErrors();
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 5000]], [$this->lastSaleId()])->assertSessionHasNoErrors();
        $this->completeLift($a, $this->lastHaulId());

        // Still at the depot: 40,000 - 3,600 - 5,000 = 31,400 L on both screens (the card used to say 40,000 L).
        $this->assertSame(31400.0, app(DashboardService::class)->inventory()['totals']['remaining_liters']);
        $this->assertSame('31,400 L', $this->cards($a['inventory'])['Unlifted Fuel']);
    }

    public function test_purchases_written_outside_purchases_get_their_stock_in(): void
    {
        $a = $this->actors();
        $lot = $this->stockLot($this->fuel('UNL'), 9000);
        $this->assertFalse(DB::table('stock_ins')->where('purchase_item_id', $lot)->exists());

        // Page loads never write: the stock-in comes from `php artisan stock-in:sync` (idempotent).
        $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory.stock-in'))->assertOk();
        $this->assertFalse(DB::table('stock_ins')->where('purchase_item_id', $lot)->exists());
        $this->artisan('stock-in:sync')->expectsOutputToContain('Created 1 stock-in record(s)')->assertSuccessful();
        $this->artisan('stock-in:sync')->expectsOutputToContain('Created 0 stock-in record(s)')->assertSuccessful();

        $this->assertSame(1, DB::table('stock_ins')->where('purchase_item_id', $lot)->count());
        $this->assertSame(9000.0, (float) DB::table('stock_ins')->where('purchase_item_id', $lot)->value('quantity_liters'));
    }

    /** @return array<string, mixed> the Stock-In row of a purchase */
    private function stockInRow(User $user, string $purchaseCode): array
    {
        $row = collect($this->viewData($user, 'inventory-officer.inventory.stock-in', 'stockIn')->items())->firstWhere('purchase_code', $purchaseCode);
        $this->assertNotNull($row, 'No Stock-In row for '.$purchaseCode);

        return $row;
    }

    private function viewData(User $user, string $route, string $key, array $query = []): mixed
    {
        return $this->actingAs($user)->get(route($route, $query))->assertOk()->viewData($key);
    }

    /** @return array<string, string> label => value */
    private function cards(User $user, string $route = 'inventory-officer.inventory', array $query = []): array
    {
        return collect($this->viewData($user, $route, 'summaryCards', $query))->pluck('value', 'label')->all();
    }
}
