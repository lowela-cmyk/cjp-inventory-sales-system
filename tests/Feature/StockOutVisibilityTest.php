<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PurchaseFlows;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Inventory > Stock Out (Part 2 Button Revisions, October 22, 2026): Standard and Depot to Client deliveries both
 * appear with their Delivery Type, the purchase each release was deducted from (the F1 portion names its F1
 * purchase) and their delivery details: the order's truck, driver and date (Standard), or the lift's truck, driver,
 * lift date, ATL and DR Number (Depot to Client).
 */
class StockOutVisibilityTest extends AuditTestCase
{
    use PurchaseFlows;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-22 09:00:00');
    }

    public function test_stock_out_shows_both_delivery_types_with_delivery_details_and_purchase_ids(): void
    {
        $a = $this->actors();
        DB::table('trucks')->where('id', $a['truck'])->update(['plate_number' => 'ABC-123']);
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $f1 = $this->purchaseLine($a, 'F1', 5000);
        $codes = DB::table('purchase_items')->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')->pluck('purchases.purchase_code', 'purchase_items.id');

        // Standard: delivered by its own truck and driver, with a 10% F1 portion.
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 2000, 'unit_price' => 50, 'f1_percentage' => 10, 'f1_purchase_item_id' => $f1, 'f1_unit_price' => 40], [
            'sales_order_number' => 'SO-STANDARD', 'delivery_truck_id' => $a['truck'], 'delivery_driver_user_id' => $a['driver']->id, 'delivery_date' => '2026-10-21 14:00', 'delivery_status' => 'delivered',
        ])->assertSessionHasNoErrors();
        $standard = (string) DB::table('sales')->where('sales_order_number', 'SO-STANDARD')->value('sale_code');

        // Depot to Client: delivered by its lift.
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 3000, 'unit_price' => 50], ['delivery_type' => 'depot_to_client', 'sales_order_number' => 'SO-DEPOT'])->assertSessionHasNoErrors();
        $depot = DB::table('sales')->where('sales_order_number', 'SO-DEPOT')->first(['id', 'sale_code']);
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 10000, 'atl_number' => 'ATL-7788']], [(int) $depot->id])->assertSessionHasNoErrors();
        $this->completeLift($a, $this->lastHaulId());

        foreach ([[$a['admin'], 'admin.inventory.stock-out'], [$a['inventory'], 'inventory-officer.inventory.stock-out']] as [$user, $route]) {
            $rows = $this->stockOutRows($user, $route);

            $main = $rows->first(fn (array $row): bool => $row['cells'][0] === $standard && $row['cells'][5] === 'Premium');
            $this->assertSame(['Standard', 'Premium', $codes[$premium], '1,800.00'], [$main['cells'][4], $main['cells'][5], $main['cells'][6], $main['cells'][7]]);
            $this->assertSame(['TRK-AUD / ABC-123', $a['driver']->name, '10/21/2026 02:00 PM', 'Delivered'], [$main['delivery']['truck'], $main['delivery']['driver'], $main['delivery']['date'], $main['delivery']['status']]);
            // The F1 portion names its F1 purchase and is priced at the F1 Price.
            $f1Row = $rows->first(fn (array $row): bool => $row['cells'][0] === $standard && $row['cells'][5] === 'F1 (F1 portion)');
            $this->assertSame([$codes[$f1], '200.00', '40.00', '8,000.00'], [$f1Row['cells'][6], $f1Row['cells'][7], $f1Row['cells'][10], $f1Row['cells'][11]]);

            $lifted = $rows->first(fn (array $row): bool => $row['cells'][0] === $depot->sale_code);
            $this->assertSame(['Depot to Client', $codes[$premium], '3,000.00'], [$lifted['cells'][4], $lifted['cells'][6], $lifted['cells'][7]]);
            $this->assertSame(['TRK-AUD / ABC-123', $a['driver']->name, 'ATL-7788', 'DR-2026-001', 'Delivered'], [$lifted['delivery']['truck'], $lifted['delivery']['driver'], $lifted['delivery']['atl'], $lifted['delivery']['dr'], $lifted['delivery']['status']]);
        }

        // The Delivery Type filter.
        $rows = $this->stockOutRows($a['inventory'], 'inventory-officer.inventory.stock-out', ['delivery_type' => 'depot_to_client']);
        $this->assertSame([$depot->sale_code], $rows->pluck('cells.0')->unique()->values()->all());
        $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory.stock-out'))->assertOk()
            // Part 4 #6: related values share a column (each still its own export column) so the table fits.
            ->assertSee('<span class="th-line" data-export-part>Delivery Type</span>', false)
            ->assertSee('<span class="th-line" data-export-part>Purchase ID</span>', false)
            ->assertSee('Delivery Date / Status');

        // Summary cards follow the purchases: 25,000 L bought, 1,800 + 3,000 Premium and 200 F1 delivered.
        $cards = $this->cards($a['inventory']);
        $this->assertSame('20,000 L', $cards['Total Inventory']);
        $this->assertSame('5,000 L', $cards['Stock-Out Today']);
    }

    public function test_a_cancelled_sale_keeps_its_stock_out_visible_as_cancelled_and_restores_the_purchase(): void
    {
        $a = $this->actors();
        $diesel = $this->purchaseLine($a, 'DSL', 10000);
        $this->recordOrder($a, ['purchase_item_id' => $diesel, 'quantity_liters' => 4000, 'unit_price' => 60], ['sales_order_number' => 'SO-VOID', 'delivery_status' => 'delivered'])->assertSessionHasNoErrors();
        $sale = DB::table('sales')->where('sales_order_number', 'SO-VOID')->first(['id', 'sale_code']);
        $this->assertSame(6000.0, $this->remaining($diesel));

        $this->actingAs($a['sales'])->patch(route('sales-officer.sales.cancel', $sale->id), ['cancel_reason' => 'customer_cancelled'])->assertRedirect(route('sales-officer.sales'));

        $row = $this->stockOutRows($a['inventory'], 'inventory-officer.inventory.stock-out')->first(fn (array $row): bool => $row['cells'][0] === $sale->sale_code);
        $this->assertSame('Cancelled', $row['cells'][14]);
        $this->assertSame('—', $row['cells'][15]);
        $this->assertSame(10000.0, $this->remaining($diesel));
    }

    /**
     * @param  array<string, mixed>  $query
     * @return Collection<int, array<string, mixed>>
     */
    private function stockOutRows(User $user, string $route, array $query = [])
    {
        $rows = collect();
        $this->actingAs($user)->get(route($route, $query))
            ->assertOk()
            ->assertViewHas('stockOut', function ($paginator) use (&$rows): bool {
                $rows = collect($paginator->items());

                return true;
            });

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private function cards(User $user): array
    {
        $cards = [];
        $this->actingAs($user)->get(route('inventory-officer.inventory'))
            ->assertOk()
            ->assertViewHas('summaryCards', function (array $summary) use (&$cards): bool {
                $cards = collect($summary)->pluck('value', 'label')->all();

                return true;
            });

        return $cards;
    }
}
