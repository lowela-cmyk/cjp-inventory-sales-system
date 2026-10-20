<?php

namespace Tests\Feature;

use App\Services\LiftAllocationService;
use App\Services\StockLotService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Audit\AuditTestCase;

/**
 * PART 9 (October 19, 2026): Purchases Payment Mode / Bank, Schedule Lift rows (Depot to Garage / Depot to Client)
 * with ATL, and Record Sales Delivery Type, F1 Percentage (deducted from F1 stock) and read-only Payment Status.
 */
class Part9RevisionsTest extends AuditTestCase
{
    // ----- F1 Percentage -------------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function f1Cases(): array
    {
        $cases = [];
        foreach (['PREM', 'UNL'] as $code) {
            foreach ([10, 15, 17] as $percentage) {
                $cases["$code $percentage%"] = [$code, $percentage];
            }
        }

        return $cases;
    }

    #[DataProvider('f1Cases')]
    public function test_f1_portion_is_deducted_from_f1_stock_and_the_rest_from_the_stock_source(string $code, int $percentage): void
    {
        $a = $this->actors();
        $fuelId = $this->fuel($code);
        $f1Id = $this->fuel('F1');
        $lot = $this->stockLot($fuelId, 10000, 55);
        $f1Lot = $this->stockLot($f1Id, 5000, 40);
        $f1Liters = round(4000 * $percentage / 100, 2);
        $mainLiters = 4000 - $f1Liters;

        // 4,000 L at ₱90: the line total stays 4,000 × 90 whatever the F1 split.
        $this->recordSale($a, [['stock_lot_id' => $lot, 'quantity_liters' => 4000, 'unit_price' => 90, 'f1_percentage' => $percentage]])->assertSessionHasNoErrors();
        $saleId = (int) DB::table('sales')->value('id');
        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $saleId, 'fuel_type_id' => $fuelId, 'quantity_liters' => 4000, 'line_total' => 360000,
            'f1_percentage' => $percentage, 'f1_quantity_liters' => $f1Liters, 'main_quantity_liters' => $mainLiters,
        ]);

        // Until delivery, the F1 liters are held back from the F1 lot.
        $this->assertSame(5000 - $f1Liters, $this->lot($f1Lot)->available);
        $this->assertSame(10000 - $mainLiters, $this->lot($lot)->available);

        $this->deliver($a, $saleId)->assertSessionHasNoErrors();

        $this->assertDatabaseHas('stock_outs', ['sale_id' => $saleId, 'fuel_type_id' => $fuelId, 'quantity_liters' => $mainLiters, 'status' => 'released', 'unit_cost' => 55]);
        $this->assertDatabaseHas('stock_outs', ['sale_id' => $saleId, 'fuel_type_id' => $f1Id, 'quantity_liters' => $f1Liters, 'status' => 'released']);
        $this->assertSame(4000.0, (float) DB::table('stock_outs')->where('sale_id', $saleId)->sum('quantity_liters'), 'Total delivered is still the ordered liters.');
        $this->assertDatabaseHas('sale_items', ['sale_id' => $saleId, 'fulfilled_quantity_liters' => 4000]);
        $this->assertSame(10000 - $mainLiters, $this->lot($lot)->on_hand);
        $this->assertSame(5000 - $f1Liters, $this->lot($f1Lot)->on_hand);
        $this->assertSame(5000 - $f1Liters, $this->lot($f1Lot)->available);

        // Stock-Out lists both releases; the F1 row is not credited to the main fuel's stock source.
        $html = $this->actingAs($a['admin'])->get(route('admin.inventory.stock-out'))->assertOk()->getContent();
        $this->assertStringContainsString(number_format($f1Liters, 2), $html);
        $this->assertStringContainsString(number_format($mainLiters, 2), $html);
    }

    public function test_the_modal_shows_the_breakdown_row_and_f1_options(): void
    {
        $a = $this->actors();
        $html = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $form = $this->between($html, 'id="record-sale-form"', '</form>');

        // Mockup order: Delivery Type, Sales Order, Order Date, Customer Name, Fuel Type | Quantity Ordered, Price | F1
        // Percentage, breakdown, Line Total; then Amount Payment ... Due Date, Payment Status.
        $labels = ['Order Details', 'Delivery Type', 'Sales Order', 'Order Date', 'Customer Name', 'Fuel Type', 'Quantity Ordered', '>Price<', 'F1 Percentage',
            'Ordered Amount', 'F1 Amount', 'Total Amount', 'Line Total', 'Order Payment Details', 'Amount Payment', 'Reference Number', 'Date of Payment',
            'Payment Terms', 'Payment Method', 'Due Date', 'Payment Status'];
        $position = 0;
        foreach ($labels as $label) {
            $found = strpos($form, $label, $position);
            $this->assertNotFalse($found, "$label is missing or out of order");
            $position = $found;
        }
        foreach (['Garage to Client', 'Depot to Client', '>10%<', '>15%<', '>17%<', 'Select F1 Percentage', 'Select Payment Method', 'Select Payment Terms'] as $text) {
            $this->assertStringContainsString($text, $form);
        }
        $this->assertStringContainsString('<div class="f1-breakdown" data-f1-breakdown hidden>', $form, 'The breakdown appears once an F1 Percentage applies.');
        $this->assertStringContainsString('data-f1-field', $form);
        $this->assertStringContainsString('data-fuel-type="Diesel"', $form);
        $this->assertStringContainsString('data-fuel-type="Unleaded"', $form);
        $this->assertStringContainsString('data-fuel-type="Premium"', $form);
        $this->assertStringContainsString('data-f1-codes="UNL,PREM"', $form);
        $this->assertStringContainsString('form="record-sale-form">Add Order</button>', $html);
    }

    public function test_schedule_lift_follows_the_mockup_layout(): void
    {
        $a = $this->actors();
        $html = $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting'))->assertOk()->getContent();
        $form = $this->between($html, 'id="lift-schedule-form"', 'data-modal-close>Cancel');

        $position = 0;
        foreach (['Truck ID', 'Driver', 'Lift Date', 'Purchase ID', 'Fuel / Remaining', 'Amount to Lift', 'Authority to Load', 'Enter ATL', '+ Add Purchase', 'Remove Purchase',
            'Lift Details', 'Allocation / Delivery', 'Remove Lift', 'Tank', 'In Stock', 'Order ID / Sales Order', 'Amount', '+ Add Lift', 'Add Schedule'] as $label) {
            $found = strpos($form, $label, $position);
            $this->assertNotFalse($found, "$label is missing or out of order");
            $position = $found;
        }
        $this->assertStringContainsString('<option value="garage" selected>Depot to Garage</option>', $form);
        $this->assertStringContainsString('class="lift-line" data-lifting-item', $form);
    }

    public function test_f1_percentage_counts_as_zero_on_other_fuel_types(): void
    {
        $a = $this->actors();
        $f1Id = $this->fuel('F1');
        $dieselLot = $this->stockLot($this->fuel('DSL'), 10000);
        $f1Lot = $this->stockLot($f1Id, 5000);

        // Diesel and F1 itself carry no F1 portion, even when a percentage is sent.
        $this->recordSale($a, [
            ['stock_lot_id' => $dieselLot, 'quantity_liters' => 2000, 'unit_price' => 80, 'f1_percentage' => 10],
            ['stock_lot_id' => $f1Lot, 'quantity_liters' => 1000, 'unit_price' => 70, 'f1_percentage' => 17],
        ])->assertSessionHasNoErrors();
        $saleId = (int) DB::table('sales')->value('id');
        $this->assertSame(0, DB::table('sale_items')->where('sale_id', $saleId)->where(fn ($q) => $q->where('f1_percentage', '>', 0)->orWhere('f1_quantity_liters', '>', 0))->count());

        $this->deliver($a, $saleId)->assertSessionHasNoErrors();
        $this->assertSame(1000.0, (float) DB::table('stock_outs')->where('sale_id', $saleId)->where('fuel_type_id', $f1Id)->sum('quantity_liters'), 'Only the F1 line itself leaves the F1 tank.');
        $this->assertSame(4000.0, $this->lot($f1Lot)->on_hand);
    }

    public function test_diesel_sale_saves_with_no_f1_percentage_and_no_f1_stock_deduction_even_if_f1_percentage_is_submitted(): void
    {
        $a = $this->actors();
        $dieselFuelId = $this->fuel('DSL');
        $f1FuelId = $this->fuel('F1');
        $dieselLot = $this->stockLot($dieselFuelId, 10000);
        $f1Lot = $this->stockLot($f1FuelId, 5000);

        // 1. Garage to Client: Diesel sale with f1_percentage submitted
        $this->recordSale($a, [
            ['stock_lot_id' => $dieselLot, 'quantity_liters' => 2500, 'unit_price' => 85, 'f1_percentage' => 15],
        ])->assertSessionHasNoErrors();

        $saleId = (int) DB::table('sales')->latest('id')->value('id');
        $saleItem = DB::table('sale_items')->where('sale_id', $saleId)->first();
        $this->assertNotNull($saleItem);
        $this->assertEquals(0, (float) $saleItem->f1_percentage);
        $this->assertEquals(0, (float) $saleItem->f1_quantity_liters);
        $this->assertEquals(2500.0, (float) $saleItem->main_quantity_liters);

        // Deliver sale and confirm NO F1 stock deduction occurs
        $this->deliver($a, $saleId)->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('stock_outs')->where('sale_id', $saleId)->where('fuel_type_id', $f1FuelId)->count(), 'No F1 stock out created.');
        $this->assertSame(2500.0, (float) DB::table('stock_outs')->where('sale_id', $saleId)->where('fuel_type_id', $dieselFuelId)->sum('quantity_liters'));
        $this->assertSame(5000.0, $this->lot($f1Lot)->on_hand, 'F1 tank on hand is unchanged.');

        // 2. Depot to Client: Diesel sale with f1_percentage submitted
        [$itemId, $tank] = $this->purchase($a, 10000, $dieselFuelId);
        $this->withoutStockLots();

        $this->recordSale($a, [
            ['fuel_type_id' => $dieselFuelId, 'quantity_liters' => 3000, 'unit_price' => 80, 'f1_percentage' => 10],
        ], ['delivery_type' => 'depot_to_client', 'sales_order_number' => 'SO-DSL-DEPOT'])
            ->assertSessionHasNoErrors();

        $depotSaleId = (int) DB::table('sales')->latest('id')->value('id');
        $depotItem = DB::table('sale_items')->where('sale_id', $depotSaleId)->first();
        $this->assertNotNull($depotItem);
        $this->assertEquals(0, (float) $depotItem->f1_percentage);
        $this->assertEquals(0, (float) $depotItem->f1_quantity_liters);
        $this->assertEquals(3000.0, (float) $depotItem->main_quantity_liters);

        // Schedule and complete lift: no F1 deduction from garage F1 tanks
        $this->schedule($a, [[
            'purchase_item_id' => $itemId,
            'quantity_liters' => 10000,
            'lifts' => [
                ['type' => 'garage', 'storage_location_id' => $tank, 'quantity_liters' => 7000],
                ['type' => 'client', 'sale_id' => $depotSaleId, 'quantity_liters' => 3000],
            ],
        ]])->assertSessionHasNoErrors();

        $haulId = (int) DB::table('hauls')->where('purchase_item_id', $itemId)->value('id');
        foreach (['in_transit', 'lifted'] as $status) {
            $this->actingAs($a['driver'])->patch(route('driver.fuel-lifting.hauls.status', $haulId), ['idempotency_key' => $this->uuid(), 'lifting_status' => $status])
                ->assertSessionHasNoErrors();
        }

        $this->assertDatabaseHas('sales', ['id' => $depotSaleId, 'delivery_status' => 'delivered']);
        $this->assertSame(0, DB::table('stock_outs')->where('sale_id', $depotSaleId)->where('fuel_type_id', $f1FuelId)->count(), 'No F1 stock out created for depot sale.');
        $this->assertSame(5000.0, $this->lot($f1Lot)->on_hand, 'F1 tank on hand is still intact.');
    }

    public function test_insufficient_f1_stock_is_rejected_before_saving(): void
    {
        $a = $this->actors();
        $premLot = $this->stockLot($this->fuel('PREM'), 10000);
        $this->stockLot($this->fuel('F1'), 300);

        // 10% of 4,000 L needs 400 L of F1; only 300 L exists.
        $this->recordSale($a, [['stock_lot_id' => $premLot, 'quantity_liters' => 4000, 'unit_price' => 90, 'f1_percentage' => 10]])
            ->assertSessionHasErrors(['items.0.f1_percentage' => 'Not enough F1 stock for the F1 portion: 400.00 L needed, 300.00 L of F1 available in the garage tanks.']);
        $this->assertSame(0, DB::table('sales')->count());

        // F1 already promised to an undelivered sale is not available to the next one.
        $this->stockLot($this->fuel('F1'), 200);
        $this->recordSale($a, [['stock_lot_id' => $premLot, 'quantity_liters' => 4000, 'unit_price' => 90, 'f1_percentage' => 10]])->assertSessionHasNoErrors();
        $this->recordSale($a, [['stock_lot_id' => $premLot, 'quantity_liters' => 2000, 'unit_price' => 90, 'f1_percentage' => 10]])
            ->assertSessionHasErrors(['items.0.f1_percentage' => 'Not enough F1 stock for the F1 portion: 200.00 L needed, 100.00 L of F1 available in the garage tanks.']);
        $this->assertSame(1, DB::table('sales')->count());
    }

    public function test_an_f1_lot_sold_directly_counts_against_the_f1_portion_of_the_same_sale(): void
    {
        $a = $this->actors();
        $premLot = $this->stockLot($this->fuel('PREM'), 10000);
        $f1Lot = $this->stockLot($this->fuel('F1'), 500);

        // 300 L of F1 sold as its own line leaves 200 L, short of the 400 L F1 portion.
        $this->recordSale($a, [
            ['stock_lot_id' => $f1Lot, 'quantity_liters' => 300, 'unit_price' => 70],
            ['stock_lot_id' => $premLot, 'quantity_liters' => 4000, 'unit_price' => 90, 'f1_percentage' => 10],
        ])->assertSessionHasErrors('items.1.f1_percentage');
        $this->assertSame(0, DB::table('sales')->count());
    }

    public function test_editing_a_sale_keeps_or_changes_its_f1_split(): void
    {
        $a = $this->actors();
        $premLot = $this->stockLot($this->fuel('PREM'), 10000);
        $this->stockLot($this->fuel('F1'), 5000);
        $this->recordSale($a, [['stock_lot_id' => $premLot, 'quantity_liters' => 4000, 'unit_price' => 90, 'f1_percentage' => 10]])->assertSessionHasNoErrors();
        $saleId = (int) DB::table('sales')->value('id');
        $edit = fn (array $line) => $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.update', $saleId), [
            'edit_sale_id' => $saleId, 'customer_id' => $a['customer'], 'sale_date' => '2026-10-15', 'payment_method' => 'cash_on_delivery',
            'payment_terms' => 'installment', 'items' => [$line + ['stock_lot_id' => $premLot, 'quantity_liters' => 4000, 'unit_price' => 90]],
        ]);

        // The Edit form resubmits the line's F1 Percentage, so saving it unchanged keeps the split.
        $html = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="sale_f1_'.$saleId.'_0" name="items\[0\]\[f1_percentage\]" data-f1-percentage\s*>.*?<option value="10" selected>10%<\/option>/s', $html);
        $edit(['f1_percentage' => 10])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sale_items', ['sale_id' => $saleId, 'f1_percentage' => 10, 'f1_quantity_liters' => 400]);

        $edit(['f1_percentage' => 17])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sale_items', ['sale_id' => $saleId, 'f1_percentage' => 17, 'f1_quantity_liters' => 680, 'main_quantity_liters' => 3320, 'line_total' => 360000]);
    }

    public function test_delivery_is_refused_and_rolled_back_when_the_f1_tanks_run_short(): void
    {
        $a = $this->actors();
        $premLot = $this->stockLot($this->fuel('PREM'), 10000);
        $f1Lot = $this->stockLot($this->fuel('F1'), 1000);
        $this->recordSale($a, [['stock_lot_id' => $premLot, 'quantity_liters' => 4000, 'unit_price' => 90, 'f1_percentage' => 10]])->assertSessionHasNoErrors();
        $saleId = (int) DB::table('sales')->value('id');
        // The F1 tank loses stock outside the sale (e.g. a stock adjustment): only 100 L remain.
        DB::table('inventory_movements')->where('id', $f1Lot)->update(['quantity_liters' => 100]);

        $this->deliver($a, $saleId)->assertSessionHasErrors(['delivery_status' => 'Insufficient F1 stock in the garage tanks for the F1 portion (400.00 L).']);
        $this->assertSame(0, DB::table('stock_outs')->where('sale_id', $saleId)->count(), 'The Premium release is rolled back too.');
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'delivery_status' => 'pending']);
    }

    // ----- Delivery Type / Depot to Client ------------------------------------------------------------

    public function test_depot_to_client_sale_is_lifted_from_the_depot_through_schedule_lift(): void
    {
        $a = $this->actors();
        [$itemId, $tank] = $this->purchase($a, 10000);
        $this->withoutStockLots();

        // Depot to Client: no stock source, the line names its fuel. Customer Name is kept.
        $this->recordSale($a, [['fuel_type_id' => $a['fuel'], 'quantity_liters' => 4000, 'unit_price' => 70]], ['delivery_type' => 'depot_to_client', 'sales_order_number' => 'SH0957143'])
            ->assertSessionHasNoErrors();
        $saleId = (int) DB::table('sales')->value('id');
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'delivery_type' => 'depot_to_client', 'customer_id' => $a['customer']]);
        $this->assertDatabaseHas('sale_items', ['sale_id' => $saleId, 'stock_in_movement_id' => null, 'fuel_type_id' => $a['fuel']]);

        // It cannot be marked delivered from garage stock.
        $this->deliver($a, $saleId)->assertSessionHasErrors(['delivery_status' => 'This Depot to Client sale is released through its lift. Schedule a Depot to Client lift for it in Fuel Lifting first.']);

        // Schedule Lift offers it as "Customer / Sales Order No." for the same fuel.
        $html = $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting'))->assertOk()->getContent();
        $this->assertStringContainsString('Audit Co. / SH0957143 | Diesel | 4,000.00 L open', $html);
        $this->assertStringContainsString('placeholder="Enter ATL"', $html);

        $this->schedule($a, [[
            'purchase_item_id' => $itemId, 'quantity_liters' => 10000, 'atl_number' => 'ATL-2026-001',
            'lifts' => [
                ['type' => 'garage', 'storage_location_id' => $tank, 'quantity_liters' => 6000],
                ['type' => 'client', 'sale_id' => $saleId, 'quantity_liters' => 4000],
            ],
        ]])->assertSessionHasNoErrors();
        $haulId = (int) DB::table('hauls')->value('id');
        $this->assertDatabaseHas('hauls', ['id' => $haulId, 'atl_number' => 'ATL-2026-001']);
        $this->assertDatabaseHas('haul_allocations', ['haul_id' => $haulId, 'destination_type' => 'garage', 'storage_location_id' => $tank, 'quantity_liters' => 6000]);
        $this->assertDatabaseHas('haul_allocations', ['haul_id' => $haulId, 'destination_type' => 'customer', 'sale_id' => $saleId, 'customer_id' => $a['customer'], 'quantity_liters' => 4000]);

        // The View modal shows the ATL and the lift rows.
        $html = $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting'))->assertOk()->getContent();
        $this->assertStringContainsString('ATL: ATL-2026-001', $html);
        $this->assertStringContainsString('Depot to Client: Audit Co. / SH0957143 &middot; 4,000.00 L', $html);
        // Fully lifted orders are no longer offered.
        $this->assertStringNotContainsString('Audit Co. / SH0957143 | Diesel', $html);

        foreach (['in_transit', 'lifted'] as $status) {
            $this->actingAs($a['driver'])->patch(route('driver.fuel-lifting.hauls.status', $haulId), ['idempotency_key' => $this->uuid(), 'lifting_status' => $status])
                ->assertSessionHasNoErrors();
        }

        $this->assertDatabaseHas('sales', ['id' => $saleId, 'delivery_status' => 'delivered']);
        $this->assertDatabaseHas('stock_outs', ['sale_id' => $saleId, 'source_type' => 'depot', 'quantity_liters' => 4000, 'status' => 'released', 'storage_location_id' => null]);
        $this->assertSame(6000.0, $this->tankBalance($tank, $a['fuel']), 'Only the Depot to Garage row reaches the tank.');
    }

    #[DataProvider('f1Cases')]
    public function test_depot_to_client_f1_is_loaded_at_the_garage_and_deducted_from_the_f1_tank_on_lift_completion(string $code, int $percentage): void
    {
        $a = $this->actors();
        $fuelId = $this->fuel($code);
        $f1Id = $this->fuel('F1');
        $f1Lot = $this->stockLot($f1Id, 5000, 40);
        $f1Tank = (int) DB::table('inventory_movements')->where('id', $f1Lot)->value('storage_location_id');
        [$itemId, $tank] = $this->purchase($a, 10000, $fuelId);
        $f1Liters = round(4000 * $percentage / 100, 2);
        $mainLiters = 4000 - $f1Liters;
        $this->withoutStockLots();

        $this->recordSale($a, [['fuel_type_id' => $fuelId, 'quantity_liters' => 4000, 'unit_price' => 90, 'f1_percentage' => $percentage]], ['delivery_type' => 'depot_to_client', 'sales_order_number' => 'SH-F1'])
            ->assertSessionHasNoErrors();
        $saleId = (int) DB::table('sales')->value('id');
        $this->assertDatabaseHas('sale_items', ['sale_id' => $saleId, 'f1_quantity_liters' => $f1Liters, 'main_quantity_liters' => $mainLiters, 'line_total' => 360000]);
        $this->assertSame(5000 - $f1Liters, $this->lot($f1Lot)->available, 'The F1 is held back from the moment the order is recorded.');

        // Schedule Lift offers the main liters from the depot, plus the F1 to load at the garage.
        $html = $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting'))->assertOk()->getContent();
        $this->assertStringContainsString('Audit Co. / SH-F1 | '.DB::table('fuel_types')->where('id', $fuelId)->value('name').' | '.number_format($mainLiters, 2).' L open + F1 '.number_format($f1Liters, 2).' L at garage', $html);
        $this->assertStringContainsString('data-f1="'.$f1Liters.'"', $html);

        // The F1 tank is required on the order's lift row.
        $client = ['type' => 'client', 'sale_id' => $saleId, 'quantity_liters' => $mainLiters];
        $line = fn (array $clientRow): array => [['purchase_item_id' => $itemId, 'quantity_liters' => 10000, 'atl_number' => 'ATL-F1', 'lifts' => [
            ['type' => 'garage', 'storage_location_id' => $tank, 'quantity_liters' => 10000 - $mainLiters], $clientRow,
        ]]];
        $this->schedule($a, $line($client))->assertSessionHasErrors(['items.0.lifts.1.f1_storage_location_id' => 'Select the garage F1 tank to load '.number_format($f1Liters, 2).' L of F1 from.']);
        $this->schedule($a, $line($client + ['f1_storage_location_id' => $f1Tank]))->assertSessionHasNoErrors();
        $haulId = (int) DB::table('hauls')->where('purchase_item_id', $itemId)->value('id');
        $this->assertDatabaseHas('haul_allocations', ['haul_id' => $haulId, 'sale_id' => $saleId, 'quantity_liters' => $mainLiters, 'f1_quantity_liters' => $f1Liters, 'f1_storage_location_id' => $f1Tank]);
        $this->assertSame(5000.0, $this->tankBalance($f1Tank, $f1Id), 'Nothing leaves the F1 tank until the lift is completed.');

        // Dispatch's View modal and the driver's lift details show the F1 to load at the garage.
        $tankName = (string) DB::table('storage_locations')->where('id', $f1Tank)->value('name');
        $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting'))->assertOk()
            ->assertSee('Load F1 at the garage: '.number_format($f1Liters, 2).' L from '.$tankName);
        $this->actingAs($a['driver'])->get(route('driver.fuel-lifting'))->assertOk()
            ->assertSee('Load F1 at Garage')->assertSee(number_format($f1Liters, 2).' L from '.$tankName)->assertSee('ATL-F1');

        foreach (['in_transit', 'lifted'] as $status) {
            $this->actingAs($a['driver'])->patch(route('driver.fuel-lifting.hauls.status', $haulId), ['idempotency_key' => $this->uuid(), 'lifting_status' => $status])
                ->assertSessionHasNoErrors();
        }

        $this->assertDatabaseHas('sales', ['id' => $saleId, 'delivery_status' => 'delivered']);
        $this->assertDatabaseHas('stock_outs', ['sale_id' => $saleId, 'source_type' => 'depot', 'fuel_type_id' => $fuelId, 'quantity_liters' => $mainLiters, 'status' => 'released']);
        $this->assertDatabaseHas('stock_outs', ['sale_id' => $saleId, 'source_type' => 'garage', 'fuel_type_id' => $f1Id, 'storage_location_id' => $f1Tank, 'quantity_liters' => $f1Liters, 'status' => 'released']);
        $this->assertSame(1, DB::table('stock_outs')->where('sale_id', $saleId)->where('fuel_type_id', $f1Id)->count(), 'F1 is deducted once.');
        $this->assertSame(5000 - $f1Liters, $this->tankBalance($f1Tank, $f1Id));
        $this->assertSame(5000 - $f1Liters, $this->lot($f1Lot)->available, 'Once released, the F1 is no longer also held back.');
        $this->assertDatabaseHas('sale_items', ['sale_id' => $saleId, 'fulfilled_quantity_liters' => 4000]);
        $this->assertSame(10000 - $mainLiters, $this->tankBalance($tank, $fuelId));
    }

    public function test_depot_to_client_f1_is_rejected_when_f1_stock_is_insufficient(): void
    {
        $a = $this->actors();
        $premId = $this->fuel('PREM');
        $f1Id = $this->fuel('F1');
        [$itemId, $tank] = $this->purchase($a, 10000, $premId);
        $this->withoutStockLots();

        // Not enough F1 anywhere: the order itself is refused.
        $this->stockLot($f1Id, 300);
        $this->recordSale($a, [['fuel_type_id' => $premId, 'quantity_liters' => 4000, 'unit_price' => 90, 'f1_percentage' => 10]], ['delivery_type' => 'depot_to_client'])
            ->assertSessionHasErrors(['items.0.f1_percentage' => 'Not enough F1 stock for the F1 portion: 400.00 L needed, 300.00 L of F1 available in the garage tanks.']);
        $this->assertSame(0, DB::table('sales')->count());

        // Enough F1 in total, but not in the tank chosen for loading.
        $tanks = DB::table('storage_locations')->where('type', 'garage')->where('status', 'active')->where('fuel_type_id', $f1Id)->orderBy('tank_number')->pluck('id');
        $secondLot = $this->stockLot($f1Id, 500);
        DB::table('inventory_movements')->where('id', $secondLot)->update(['storage_location_id' => $tanks[1]]);
        $this->recordSale($a, [['fuel_type_id' => $premId, 'quantity_liters' => 4000, 'unit_price' => 90, 'f1_percentage' => 10]], ['delivery_type' => 'depot_to_client'])
            ->assertSessionHasNoErrors();
        $saleId = (int) DB::table('sales')->value('id');
        $line = fn (int $f1Tank): array => [['purchase_item_id' => $itemId, 'quantity_liters' => 10000, 'lifts' => [
            ['type' => 'garage', 'storage_location_id' => $tank, 'quantity_liters' => 6400],
            ['type' => 'client', 'sale_id' => $saleId, 'quantity_liters' => 3600, 'f1_storage_location_id' => $f1Tank],
        ]]];
        $tankName = (string) DB::table('storage_locations')->where('id', $tanks[0])->value('name');

        $this->schedule($a, $line((int) $tanks[0]))
            ->assertSessionHasErrors(['items.0.lifts.1.f1_storage_location_id' => $tankName.' has only 300.00 L of F1 for the 400.00 L to load.']);
        $this->assertSame(0, DB::table('hauls')->where('purchase_item_id', $itemId)->count());
        // A tank of another fuel is not an F1 tank.
        $this->schedule($a, $line($tank))->assertSessionHasErrors(['items.0.lifts.1.f1_storage_location_id' => 'Select the garage F1 tank to load 400.00 L of F1 from.']);
        $this->schedule($a, $line((int) $tanks[1]))->assertSessionHasNoErrors();
    }

    public function test_depot_to_client_has_no_f1_step_for_other_fuel_types(): void
    {
        $a = $this->actors();
        $f1Id = $this->fuel('F1');
        $f1Lot = $this->stockLot($f1Id, 5000);
        [$itemId, $tank] = $this->purchase($a, 10000);
        $this->withoutStockLots();

        // Diesel: an F1 Percentage sent anyway counts as 0%.
        $this->recordSale($a, [['fuel_type_id' => $a['fuel'], 'quantity_liters' => 4000, 'unit_price' => 80, 'f1_percentage' => 10]], ['delivery_type' => 'depot_to_client'])
            ->assertSessionHasNoErrors();
        $saleId = (int) DB::table('sales')->value('id');
        $this->assertDatabaseHas('sale_items', ['sale_id' => $saleId, 'f1_percentage' => 0, 'f1_quantity_liters' => 0, 'main_quantity_liters' => 4000]);

        $html = $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting'))->assertOk()->getContent();
        $this->assertStringContainsString('Audit Co. / '.DB::table('sales')->value('sale_code').' | Diesel | 4,000.00 L open</option>', $html);
        $this->assertStringContainsString('data-f1="0"', $html);

        // No F1 tank is asked for, and completion touches no F1 tank.
        $this->schedule($a, [['purchase_item_id' => $itemId, 'quantity_liters' => 10000, 'lifts' => [
            ['type' => 'garage', 'storage_location_id' => $tank, 'quantity_liters' => 6000],
            ['type' => 'client', 'sale_id' => $saleId, 'quantity_liters' => 4000],
        ]]])->assertSessionHasNoErrors();
        $haulId = (int) DB::table('hauls')->where('purchase_item_id', $itemId)->value('id');
        $this->assertDatabaseHas('haul_allocations', ['haul_id' => $haulId, 'sale_id' => $saleId, 'f1_quantity_liters' => 0, 'f1_storage_location_id' => null]);
        foreach (['in_transit', 'lifted'] as $status) {
            $this->actingAs($a['driver'])->patch(route('driver.fuel-lifting.hauls.status', $haulId), ['idempotency_key' => $this->uuid(), 'lifting_status' => $status])->assertSessionHasNoErrors();
        }

        $this->assertDatabaseHas('sales', ['id' => $saleId, 'delivery_status' => 'delivered']);
        $this->assertSame(0, DB::table('stock_outs')->where('sale_id', $saleId)->where('fuel_type_id', $f1Id)->count());
        $this->assertSame(5000.0, $this->lot($f1Lot)->on_hand);
        $this->actingAs($a['driver'])->get(route('driver.fuel-lifting'))->assertOk()->assertSee('Load F1 at Garage');
    }

    public function test_stock_source_is_required_only_for_garage_to_client(): void
    {
        $a = $this->actors();
        $this->withoutStockLots();
        $line = ['fuel_type_id' => $a['fuel'], 'quantity_liters' => 100, 'unit_price' => 70];

        $this->recordSale($a, [$line])->assertSessionHasErrors(['items.0.stock_lot_id' => 'Select a stock source for this line.']);
        $this->recordSale($a, [$line], ['delivery_type' => 'depot_to_client'])->assertSessionHasNoErrors();
        $this->recordSale($a, [['quantity_liters' => 100, 'unit_price' => 70]], ['delivery_type' => 'depot_to_client'])->assertSessionHasErrors('items.0.fuel_type_id');
    }

    // ----- Schedule Lift rows ---------------------------------------------------------------------------

    // Part 9 (October 20): the lift rows add up to at most the Amount to Lift; the rest stays on the truck.
    public function test_lift_rows_cannot_add_up_to_more_than_the_amount_to_lift(): void
    {
        $a = $this->actors();
        [$itemId, $tank] = $this->purchase($a, 10000);
        $rows = fn (float ...$liters): array => array_map(fn (float $l): array => ['type' => 'garage', 'storage_location_id' => $tank, 'quantity_liters' => $l], $liters);

        $this->schedule($a, [['purchase_item_id' => $itemId, 'quantity_liters' => 10000, 'lifts' => $rows(6000, 4500)]])
            ->assertSessionHasErrors(['items.0.lifts' => 'The lift rows add up to 10,500.00 L, more than the Amount to Lift of 10,000.00 L.']);
        $this->assertSame(0, DB::table('hauls')->count());

        // Exactly the Amount to Lift is still fine.
        $this->schedule($a, [['purchase_item_id' => $itemId, 'quantity_liters' => 10000, 'lifts' => $rows(6000, 4000)]])->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('haul_allocations')->where('destination_type', 'garage')->where('receive_on_completion', true)->count());
    }

    public function test_lift_rows_below_the_amount_to_lift_save_and_the_rest_stays_on_the_truck(): void
    {
        $a = $this->actors();
        DB::table('trucks')->where('id', $a['truck'])->update(['capacity_liters' => 40000]);
        [$itemId, $tank] = $this->purchase($a, 40000);
        $this->stockLot($a['fuel'], 12000); // the tank already holds 12,000 L

        $this->schedule($a, [['purchase_item_id' => $itemId, 'quantity_liters' => 40000, 'lifts' => [
            ['type' => 'garage', 'storage_location_id' => $tank, 'quantity_liters' => 12000],
        ]]])->assertSessionHasNoErrors();

        $haulId = $this->scheduledHaulId();
        $this->assertDatabaseHas('hauls', ['id' => $haulId, 'quantity_liters' => 40000]);
        // Only the allocated row is stored: no leftover goes to a default tank.
        $this->assertSame(1, DB::table('haul_allocations')->where('haul_id', $haulId)->count());
        $this->assertSame(['allocated' => 12000.0, 'delivered' => 0.0, 'remaining' => 28000.0], app(LiftAllocationService::class)->loadSummaryByHaul([$haulId])[$haulId]);
        $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting'))->assertOk()
            ->assertSee('Remaining on truck: 28,000.00 L', false);
    }

    public function test_a_garage_row_cannot_exceed_the_tanks_free_space_of_its_24kl_capacity(): void
    {
        $a = $this->actors();
        [$itemId, $tank] = $this->purchase($a, 30000);
        $this->stockLot($a['fuel'], 12000);

        // The dropdown shows stock, capacity and free space.
        $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting'))->assertOk()
            ->assertSee('Diesel Tank 1 | stock 12,000.00 L | capacity 24,000.00 L | free 12,000.00 L')
            ->assertDontSee('capacity not set');

        $line = fn (array ...$lifts): array => [['purchase_item_id' => $itemId, 'quantity_liters' => 30000, 'lifts' => $lifts]];
        $garage = fn (int $tankId, float $liters): array => ['type' => 'garage', 'storage_location_id' => $tankId, 'quantity_liters' => $liters];
        $full = 'Diesel Tank 1 has only 12,000.00 L of free space (stock 12,000.00 L of 24,000.00 L).';

        $this->schedule($a, $line($garage($tank, 12000.01)))->assertSessionHasErrors(['items.0.lifts.0.quantity_liters' => $full]);
        // Two rows to the same tank count together.
        $this->schedule($a, $line($garage($tank, 6000), $garage($tank, 6001)))->assertSessionHasErrors(['items.0.lifts.1.quantity_liters' => $full]);
        $this->assertSame(0, DB::table('hauls')->whereNotNull('lifting_schedule_id')->count());

        // Rows to the same tank up to its free space, the rest to another tank, are fine.
        $tank2 = (int) DB::table('storage_locations')->where('type', 'garage')->where('fuel_type_id', $a['fuel'])->where('tank_number', 2)->value('id');
        $this->schedule($a, $line($garage($tank, 6000), $garage($tank, 6000), $garage($tank2, 18000)))->assertSessionHasNoErrors();

        // A scheduled lift reserves its space: Tank 1 has none left for the next lift.
        $html = $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting'))->assertOk()->getContent();
        $this->assertStringContainsString('Diesel Tank 1 | stock 12,000.00 L | capacity 24,000.00 L | incoming 12,000.00 L | free 0.00 L', $html);
    }

    public function test_lift_completion_adds_only_the_allocated_liters_and_never_fills_a_tank_past_24kl(): void
    {
        $a = $this->actors();
        DB::table('trucks')->where('id', $a['truck'])->update(['capacity_liters' => 40000]);
        [$itemId, $tank] = $this->purchase($a, 40000);
        $this->stockLot($a['fuel'], 12000);

        $this->schedule($a, [['purchase_item_id' => $itemId, 'quantity_liters' => 40000, 'lifts' => [
            ['type' => 'garage', 'storage_location_id' => $tank, 'quantity_liters' => 12000],
        ]]])->assertSessionHasNoErrors();
        $this->completeLift($a, $this->scheduledHaulId());

        // 12,000 + 12,000 = 24,000 L: the 28,000 L left on the truck never reach the tank.
        $this->assertSame(24000.0, $this->tankBalance($tank, $a['fuel']));

        // Stock that changed after scheduling is re-checked on completion.
        [$itemId2] = $this->purchase($a, 5000);
        $tank2 = (int) DB::table('storage_locations')->where('type', 'garage')->where('fuel_type_id', $a['fuel'])->where('tank_number', 2)->value('id');
        $this->schedule($a, [['purchase_item_id' => $itemId2, 'quantity_liters' => 5000, 'lifts' => [
            ['type' => 'garage', 'storage_location_id' => $tank2, 'quantity_liters' => 5000],
        ]]])->assertSessionHasNoErrors();
        $haul2 = $this->scheduledHaulId();
        $this->fillTank($tank2, $a['fuel'], 20000);

        $this->completeLift($a, $haul2);
        $this->assertSame(20000.0, $this->tankBalance($tank2, $a['fuel']), 'The 5,000 L would pass 24,000 L, so nothing is posted.');
        $this->assertDatabaseHas('haul_allocations', ['haul_id' => $haul2, 'destination_type' => 'garage', 'status' => 'planned']);
        $this->assertSame('lifted', DB::table('hauls')->where('id', $haul2)->value('status'), 'The lift itself still completes.');
    }

    public function test_hauled_tab_shows_allocated_and_delivered_quantities(): void
    {
        $a = $this->actors();
        [$itemId, $tank] = $this->purchase($a, 10000);
        $this->withoutStockLots();
        $this->recordSale($a, [['fuel_type_id' => $a['fuel'], 'quantity_liters' => 3000, 'unit_price' => 70]], ['delivery_type' => 'depot_to_client', 'sales_order_number' => 'SH0957143'])
            ->assertSessionHasNoErrors();
        $saleId = (int) DB::table('sales')->value('id');

        $this->schedule($a, [['purchase_item_id' => $itemId, 'quantity_liters' => 10000, 'lifts' => [
            ['type' => 'garage', 'storage_location_id' => $tank, 'quantity_liters' => 6000],
            ['type' => 'client', 'sale_id' => $saleId, 'quantity_liters' => 3000],
        ]]])->assertSessionHasNoErrors();
        $haulId = (int) DB::table('hauls')->value('id');
        $this->completeLift($a, $haulId);
        $this->actingAs($a['dispatch'])->patch(route('dispatch.fuel-lifting.hauls.status', $haulId), ['idempotency_key' => $this->uuid(), 'status' => 'completed'])
            ->assertSessionHasNoErrors();

        // An old lift without rows shows 0.00 L.
        $old = DB::table('hauls')->where('id', $haulId)->first();
        $oldScheduleId = DB::table('lifting_schedules')->insertGetId(['schedule_code' => 'LFT-OLD-1', 'depot_id' => $old->depot_id, 'truck_id' => $old->truck_id, 'driver_user_id' => $old->driver_user_id, 'scheduled_at' => '2026-09-01 08:00:00', 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('hauls')->insert(['lifting_schedule_id' => $oldScheduleId, 'haul_code' => 'LFT-OLD-1-01', 'purchase_id' => $old->purchase_id, 'purchase_item_id' => $old->purchase_item_id, 'depot_id' => $old->depot_id, 'fuel_type_id' => $old->fuel_type_id, 'truck_id' => $old->truck_id, 'driver_user_id' => $old->driver_user_id, 'scheduled_at' => '2026-09-01 08:00:00', 'quantity_liters' => 500, 'status' => 'completed', 'created_at' => now(), 'updated_at' => now()]);

        $html = $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting.hauled'))->assertOk()->getContent();
        $table = $this->between($html, 'data-tab-panel="hauled"', '</table>');
        $this->assertStringContainsString('<th>Lifted</th>
                            <th>Allocated Quantity</th>
                            <th>Delivered Quantity</th>', $table);
        $this->assertMatchesRegularExpression('/10,000\.00 L<\/td>\s*<td>6,000\.00 L<\/td>\s*<td>3,000\.00 L<\/td>/', $table);
        $this->assertMatchesRegularExpression('/500\.00 L<\/td>\s*<td>0\.00 L<\/td>\s*<td>0\.00 L<\/td>/', $table);

        // The View modal shows both figures and what was left on the truck.
        $modal = $this->between($html, 'id="dispatch-haul-'.$haulId.'"', 'Lift Details');
        $this->assertStringContainsString('<div class="detail-label">Allocated Quantity</div><div class="detail-value">6,000.00 L</div>', $modal);
        $this->assertStringContainsString('<div class="detail-label">Delivered Quantity</div><div class="detail-value">3,000.00 L</div>', $modal);
        $this->assertStringContainsString('<div class="detail-label">Remaining on Truck</div><div class="detail-value">1,000.00 L</div>', $modal);
    }

    public function test_a_client_row_needs_an_open_depot_to_client_order_of_the_same_fuel_with_enough_liters(): void
    {
        $a = $this->actors();
        [$itemId, $tank] = $this->purchase($a, 10000);
        $garageSale = $this->stockLot($a['fuel'], 5000);
        $this->recordSale($a, [['stock_lot_id' => $garageSale, 'quantity_liters' => 1000, 'unit_price' => 70]])->assertSessionHasNoErrors();
        $garageSaleId = (int) DB::table('sales')->orderByDesc('id')->value('id');
        $this->withoutStockLots();
        $this->recordSale($a, [['fuel_type_id' => $this->fuel('UNL'), 'quantity_liters' => 1000, 'unit_price' => 70]], ['delivery_type' => 'depot_to_client'])->assertSessionHasNoErrors();
        $otherFuelSaleId = (int) DB::table('sales')->orderByDesc('id')->value('id');
        $this->recordSale($a, [['fuel_type_id' => $a['fuel'], 'quantity_liters' => 1000, 'unit_price' => 70]], ['delivery_type' => 'depot_to_client'])->assertSessionHasNoErrors();
        $depotSaleId = (int) DB::table('sales')->orderByDesc('id')->value('id');
        $line = fn (int $saleId, float $liters): array => [['purchase_item_id' => $itemId, 'quantity_liters' => 10000, 'lifts' => [
            ['type' => 'garage', 'storage_location_id' => $tank, 'quantity_liters' => 10000 - $liters],
            ['type' => 'client', 'sale_id' => $saleId, 'quantity_liters' => $liters],
        ]]];

        $this->schedule($a, $line($garageSaleId, 1000))->assertSessionHasErrors(['items.0.lifts.1.sale_id' => 'Select an open Depot to Client sales order.']);
        $this->schedule($a, $line($otherFuelSaleId, 1000))->assertSessionHasErrors(['items.0.lifts.1.sale_id' => "This sales order has no open liters of the purchase's fuel type."]);
        $this->schedule($a, $line($depotSaleId, 1500))->assertSessionHasErrors(['items.0.lifts.1.quantity_liters' => 'This sales order has only 1,000.00 L left to lift.']);
        $this->assertSame(0, DB::table('hauls')->where('purchase_item_id', $itemId)->count());
    }

    // ----- Payment Status --------------------------------------------------------------------------------

    public function test_payment_status_is_read_only_and_follows_recorded_payments(): void
    {
        $a = $this->actors();
        $lot = $this->stockLot($a['fuel'], 10000);
        foreach ([0, 30000, 70000] as $paid) {
            $this->recordSale($a, [['stock_lot_id' => $lot, 'quantity_liters' => 1000, 'unit_price' => 70]], ['amount_paid' => $paid])->assertSessionHasNoErrors();
        }
        $ids = DB::table('sales')->orderBy('id')->pluck('id')->all();

        $html = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        foreach (array_combine($ids, ['Unpaid', 'Partially Paid', 'Fully Paid']) as $saleId => $label) {
            $this->assertStringContainsString('<label>Payment Status</label><output data-payment-status>'.$label.'</output>', $this->between($html, 'id="so-sales-edit-'.$saleId.'"', 'id="sale_status_'.$saleId.'"'));
        }
        // The order Status dropdown keeps its name; Payment Status is never a submitted field.
        $this->assertStringContainsString('<label for="sale_status">Status</label>', $html);
        $this->assertStringNotContainsString('name="sale_payment_status"', $html);
    }

    // ----- Purchases: Payment Mode / Bank ---------------------------------------------------------------

    public function test_purchase_payment_mode_and_bank_selection(): void
    {
        $a = $this->actors();
        $post = fn (array $extra) => $this->actingAs($a['inventory'])->from(route('inventory-officer.inventory'))->post(route('inventory-officer.inventory.purchases.store'), $extra + [
            'idempotency_key' => $this->uuid(), 'purchase_date' => '2026-10-14', 'depot_id' => $a['depot'], 'fuel_type_id' => $a['fuel'],
            'quantity_ordered_liters' => 1000, 'unit_cost' => 50, 'payment_status' => 'unpaid',
        ]);

        $this->defaultPaymentMode = false;
        $post([])->assertSessionHasErrors(['payment_mode' => 'Select the payment mode.']);
        $post(['payment_mode' => 'bank_transfer'])->assertSessionHasErrors(['bank_name' => 'Select the bank for a Bank Transfer or Cheque payment.']);
        $post(['payment_mode' => 'cheque', 'bank_name' => 'UCPB'])->assertSessionHasErrors('bank_name');
        $this->assertSame(0, DB::table('purchases')->count());

        $post(['payment_mode' => 'bank_transfer', 'bank_name' => 'BPI'])->assertSessionHasNoErrors();
        $post(['payment_mode' => 'cash', 'bank_name' => 'BDO'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('purchases', ['payment_mode' => 'bank_transfer', 'bank_name' => 'BPI']);
        // Cash clears the bank.
        $this->assertDatabaseHas('purchases', ['payment_mode' => 'cash', 'bank_name' => null]);

        // Editing to Cash clears the bank too.
        $itemId = (int) DB::table('purchase_items')->orderBy('id')->value('id');
        $this->actingAs($a['inventory'])->patch(route('inventory-officer.inventory.purchases.update', $itemId), [
            'purchase_date' => '2026-10-14', 'depot_id' => $a['depot'], 'fuel_type_id' => $a['fuel'], 'quantity_ordered_liters' => 1000,
            'unit_cost' => 50, 'payment_status' => 'paid', 'payment_mode' => 'cash', 'bank_name' => 'BPI',
        ])->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('purchases')->whereNotNull('bank_name')->count());

        $html = $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory'))->assertOk()->getContent();
        $this->assertStringContainsString('<span class="th-line" data-export-part>Payment Mode</span><span class="th-line" data-export-part>Bank</span>', $html);
        $this->assertStringContainsString('data-payment-mode="bank_name"', $html);
        foreach (['Bank Transfer', 'Cash', 'Cheque', '>BPI<', '>BDO<', '>RCBC<', 'Bank Selection'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
    }

    public function test_existing_purchases_without_a_payment_mode_still_load(): void
    {
        $a = $this->actors();
        $this->stockLot($a['fuel'], 1000); // inserted directly, as purchases recorded before Part 9

        $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory'))->assertOk()->assertSee('N/A');
    }

    // ----- helpers ---------------------------------------------------------------------------------------

    private function fuel(string $code): int
    {
        return (int) DB::table('fuel_types')->where('code', $code)->value('id');
    }

    private function lot(int $lotId): \stdClass
    {
        return app(StockLotService::class)->lots([$lotId])->first();
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     */
    private function recordSale(array $a, array $items, array $extra = [])
    {
        return $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->post(route('sales-officer.sales.store'), $extra + [
            'idempotency_key' => $this->uuid(), 'form_context' => 'record-sale', 'customer_id' => $a['customer'], 'sale_date' => '2026-10-15',
            'items' => $items, 'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment',
        ]);
    }

    /**
     * @param  array<string, mixed>  $a
     */
    private function deliver(array $a, int $saleId)
    {
        return $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.delivery-status.update', $saleId), ['delivery_status' => 'delivered']);
    }

    /**
     * @param  array<string, mixed>  $a
     * @return array{0: int, 1: int}
     */
    private function purchase(array $a, int $liters, ?int $fuelId = null): array
    {
        $fuelId ??= $a['fuel'];
        $this->actingAs($a['inventory'])->post(route('inventory-officer.inventory.purchases.store'), [
            'idempotency_key' => $this->uuid(), 'purchase_date' => '2026-10-14', 'depot_id' => $a['depot'], 'fuel_type_id' => $fuelId,
            'quantity_ordered_liters' => $liters, 'unit_cost' => 50, 'payment_status' => 'unpaid', 'payment_mode' => 'cash',
        ])->assertSessionHasNoErrors();
        $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory'))->assertOk();

        return [
            (int) DB::table('purchase_items')->orderByDesc('id')->value('id'),
            (int) DB::table('storage_locations')->where('type', 'garage')->where('status', 'active')->where('fuel_type_id', $fuelId)->orderBy('tank_number')->value('id'),
        ];
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
     * The driver takes a lift In Transit, then Lifted (which settles its rows).
     *
     * @param  array<string, mixed>  $a
     */
    private function completeLift(array $a, int $haulId): void
    {
        foreach (['in_transit', 'lifted'] as $status) {
            $this->actingAs($a['driver'])->patch(route('driver.fuel-lifting.hauls.status', $haulId), ['idempotency_key' => $this->uuid(), 'lifting_status' => $status])
                ->assertSessionHasNoErrors();
        }
    }

    /** The newest lift scheduled through Schedule Lift (stock lots made by the tests have no schedule). */
    private function scheduledHaulId(): int
    {
        return (int) DB::table('hauls')->whereNotNull('lifting_schedule_id')->orderByDesc('id')->value('id');
    }

    /** Stock that reaches a tank outside the lift being tested (e.g. another receipt after it was scheduled). */
    private function fillTank(int $tankId, int $fuelTypeId, float $liters): void
    {
        DB::table('inventory_movements')->insert([
            'movement_code' => 'MOV-FILL-'.$tankId.'-'.(int) $liters, 'storage_location_id' => $tankId, 'fuel_type_id' => $fuelTypeId, 'movement_type' => 'adjustment',
            'direction' => 'in', 'quantity_liters' => $liters, 'unit_cost' => 50, 'reference_type' => 'adjustment', 'reference_id' => 0,
            'movement_date' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function tankBalance(int $tankId, int $fuelTypeId): float
    {
        return round((float) DB::table('inventory_movements')->where('storage_location_id', $tankId)->where('fuel_type_id', $fuelTypeId)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN quantity_liters ELSE -quantity_liters END), 0) as balance")->value('balance'), 2);
    }

    private function between(string $html, string $start, string $end): string
    {
        $from = strpos($html, $start);
        $this->assertNotFalse($from, "Missing: $start");
        $to = strpos($html, $end, $from);

        return substr($html, $from, $to === false ? null : $to - $from);
    }
}
