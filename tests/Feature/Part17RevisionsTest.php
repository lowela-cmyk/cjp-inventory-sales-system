<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Concerns\PurchaseFlows;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Part 17 (System Analysis "Part 16, Oct 31"): paged Sales View and Edit Purchase, a confirm before Delivered, who
 * recorded / last updated a sale, liters in the Record Sales breakdown, Driver Name and Truck back on Stock Out.
 * (No Delete of delivered orders: SaleDeleteTest.)
 */
class Part17RevisionsTest extends AuditTestCase
{
    use PurchaseFlows;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 09:00:00');
    }

    public function test_the_sales_view_shows_order_delivery_and_payment_details_one_section_at_a_time(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 3000, 'unit_price' => 70], ['sales_order_number' => 'SO-VTFHIO'])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();

        $html = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $modal = substr($html, strpos($html, 'id="so-sales-edit-'.$saleId.'"'));
        $modal = substr($modal, 0, strpos($modal, 'id="so-payment-history-'.$saleId.'"'));

        // Three sections in order; only the first is visible; the pager says 1 of 3; the old flat list is gone.
        $this->assertSame(3, substr_count($modal, 'data-detail-page '));
        $this->assertSame(2, preg_match_all('/data-detail-page\s+hidden/', $modal));
        $this->assertMatchesRegularExpression('#<h3 class="detail-page-title">Order Details</h3>.*<h3 class="detail-page-title">Delivery Details</h3>.*<h3 class="detail-page-title">Payment Details</h3>#s', $modal);
        $this->assertStringContainsString('<span data-detail-page-label>1 out of 3</span>', $modal);
        $this->assertStringContainsString('data-detail-page-step="-1" aria-label="Previous section" disabled', $modal);
        $this->assertStringContainsString('data-detail-page-step="1" aria-label="Next section"', $modal);
        foreach (['Delivery Type', 'Sales Order Number', 'Transaction Date', 'Customer Name', 'Company Name', 'Fuel Type', 'Quantity Ordered', 'Purchase ID', 'Truck ID', 'Driver Name', 'Delivery Date', 'Quantity Fulfilled', 'Payment Status', 'Due Date', 'Latest Payment Date', 'Receivable Status', 'Delivery Status', 'Payment Terms', 'Payment Method', 'Order Status'] as $label) {
            $this->assertStringContainsString('>'.$label.'</div>', $modal);
        }
        $this->assertStringContainsString('RECORD SALES RECEIVABLES', $modal);
        $this->assertStringNotContainsString('<div class="detail-grid">', $modal);

        $pages = collect($this->actingAs($a['sales'])->get(route('sales-officer.sales'))->viewData('sales')->items())->firstWhere('id', $saleId)['detail_pages'];
        $this->assertSame(['Order Details', 'Delivery Details', 'Payment Details'], array_column($pages, 'title'));
        $this->assertSame(['Delivery Type', 'Sales Order Number', 'Transaction Date', 'Customer Name', 'Company Name', 'Fuel Type', 'Quantity Ordered', 'Purchase ID', 'Recorded By', 'Last Updated By'], array_keys($pages[0]['fields']));
        $this->assertSame(['Truck ID', 'Driver Name', 'Delivery Date', 'Quantity Fulfilled'], array_keys($pages[1]['fields']));
        $this->assertSame(['Payment Status', 'Due Date', 'Latest Payment Date', 'Receivable Status', 'Delivery Status', 'Payment Terms', 'Payment Method', 'Order Status'], array_keys($pages[2]['fields']));
        $this->assertSame('SO-VTFHIO', $pages[0]['fields']['Sales Order Number']);

        // The line-items table and the editable fields follow the sections; the footer actions are unchanged.
        $this->assertMatchesRegularExpression('#data-detail-pages.*sales-items-table.*name="sales_order_number".*Payment History.*Apply Changes.*Cancel Sale.*Close#s', $modal);

        $this->assertStringContainsString("pager.querySelector('[data-detail-page-label]').textContent = `\${next + 1} out of \${pages.length}`;", File::get(resource_path('js/app.js')));
    }

    public function test_a_depot_to_client_view_names_the_lift_truck_and_driver(): void
    {
        $a = $this->actors();
        DB::table('trucks')->where('id', $a['truck'])->update(['name' => 'Isuzu Giga']);
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 2000, 'unit_price' => 70], ['delivery_type' => 'depot_to_client'])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 2000, 'atl_number' => 'ATL-1']], [$saleId])->assertSessionHasNoErrors();

        $row = collect($this->actingAs($a['sales'])->get(route('sales-officer.sales'))->viewData('sales')->items())->firstWhere('id', $saleId);
        $delivery = $row['detail_pages'][1]['fields'];
        $this->assertSame(DB::table('hauls')->where('id', $this->lastHaulId())->value('haul_code'), $delivery['Lift ID']);
        $this->assertStringContainsString('Isuzu Giga', $delivery['Truck ID']);
        $this->assertSame($a['driver']->name, $delivery['Driver Name']);
    }

    public function test_marking_delivered_asks_for_confirmation_first(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 1000, 'unit_price' => 70])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();

        $html = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $confirm = 'data-confirm-when="delivery_status=delivered" data-confirm-message="This sale will be deducted from stock (Stock Out). Continue?"';
        // Update Delivery in the View, and Record Sales recorded straight as Delivered.
        $this->assertStringContainsString('id="sale-delivery-'.$saleId.'" method="POST" action="'.route('sales-officer.sales.delivery-status.update', $saleId).'" '.$confirm, $html);
        $this->assertStringContainsString('<form id="record-sale-form" method="POST" action="'.route('sales-officer.sales.store').'" '.$confirm, $html);
        $this->assertStringNotContainsString('Selecting Delivered will create Stock-Out', $html);

        $js = File::get(resource_path('js/app.js'));
        $this->assertStringContainsString("if (confirmation && form.dataset.confirmed !== 'true' && confirmApplies(form)) {", $js);
        $this->assertStringContainsString('return Boolean(field) && !field.disabled && field.value === value;', $js);
    }

    public function test_the_sale_shows_who_recorded_it_and_who_last_updated_it(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 1000, 'unit_price' => 70])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $row = fn (): array => collect($this->actingAs($a['admin'])->get(route('admin.sales'))->viewData('sales')->items())->firstWhere('id', $saleId);

        $this->assertSame($a['sales']->name.' · 10/14/2026 09:00 AM', $row()['recorded']);
        $this->assertSame('N/A · 10/14/2026 09:00 AM', $row()['updated']);

        // Admin edits it an hour later; then a payment counts as an update too.
        Carbon::setTestNow('2026-10-14 10:00:00');
        $this->actingAs($a['admin'])->from(route('admin.sales'))->patch(route('admin.sales.update', $saleId), [
            'edit_sale_id' => $saleId, 'sales_order_number' => 'SO-EDIT', 'customer_id' => $a['customer'], 'sale_date' => '2026-10-15',
            'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment',
            'items' => [['purchase_item_id' => $premium, 'quantity_liters' => 1000, 'unit_price' => 70]],
        ])->assertSessionHasNoErrors();
        $this->assertSame($a['admin']->id, (int) DB::table('sales')->where('id', $saleId)->value('updated_by'));
        $this->assertSame($a['admin']->name.' · 10/14/2026 10:00 AM', $row()['updated']);
        $this->assertSame($a['sales']->name.' · 10/14/2026 09:00 AM', $row()['detail_pages'][0]['fields']['Recorded By']);
        $this->assertSame($a['admin']->name.' · 10/14/2026 10:00 AM', $row()['detail_pages'][0]['fields']['Last Updated By']);

        Carbon::setTestNow('2026-10-14 11:00:00');
        $this->actingAs($a['sales'])->post(route('sales-officer.sales.payments.store', $saleId), [
            'idempotency_key' => (string) Str::uuid(), 'payment_date' => '2026-10-15', 'amount' => 100, 'method' => 'cash_on_delivery',
        ])->assertSessionHasNoErrors();
        $this->assertSame($a['sales']->name.' · 10/14/2026 11:00 AM', $row()['updated']);

        // The table shows it as a tooltip on the Order-ID and keeps it in Export (hidden columns).
        $html = $this->actingAs($a['admin'])->get(route('admin.sales'))->getContent();
        $this->assertStringContainsString('<td title="Recorded by '.e($a['sales']->name).' · 10/14/2026 09:00 AM&#10;Last updated by '.e($a['sales']->name).' · 10/14/2026 11:00 AM" data-sale-actors>', $html);
        $this->assertStringContainsString('<th class="export-only" hidden>Recorded By</th>', $html);
        $this->assertStringContainsString('<th class="export-only" hidden>Last Updated By</th>', $html);
    }

    public function test_the_record_sales_breakdown_shows_liters_and_the_totals_bar_stays_in_pesos(): void
    {
        $a = $this->actors();
        $html = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $form = substr($html, strpos($html, 'id="record-sale-form"'), 30000);
        foreach (['data-f1-ordered>0.00 L', 'data-f1-main>0.00 L', 'data-f1-amount>0.00 L', 'data-f1-total>0.00 L', 'data-sales-main-total>0.00<', 'data-sales-f1-total>0.00<', 'data-sales-line-total>0.00<'] as $output) {
            $this->assertStringContainsString($output, $form);
        }

        $js = File::get(resource_path('js/app.js'));
        $this->assertStringContainsString("set('[data-f1-main]', `\${format(main)} L`);", $js);
        $this->assertStringContainsString("set('[data-f1-amount]', `\${format(f1)} L`);", $js);
        $this->assertStringContainsString("set('[data-f1-total]', `\${format(main + f1)} L`);", $js);
        $this->assertStringContainsString("set('[data-sales-main-total]', format(mainTotal));", $js);
        $this->assertStringContainsString("set('[data-sales-line-total]', format(lineTotal));", $js);

        $css = File::get(resource_path('css/part9.css'));
        $this->assertStringNotContainsString('[data-f1-main], [data-f1-amount], [data-f1-total])::before', $css);
        $this->assertStringNotContainsString('[data-f1-ordered]::after', $css);
        $this->assertStringContainsString('.admin-modal-sales-wide .sale-line-total-row output::before', $css);

        // Edit Sales uses the same breakdown partial, so its initial outputs keep the same units.
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 1800, 'unit_price' => 70])->assertSessionHasNoErrors();
        $editHtml = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $this->assertStringContainsString('name="items[0][quantity_liters]" type="number"', $editHtml);
        $this->assertMatchesRegularExpression('/name="items\[0\]\[quantity_liters\]"[^>]*value="1800(?:\.00)?"/', $editHtml);
        $this->assertSame(2, substr_count($editHtml, 'data-f1-ordered>0.00 L'));
        $this->assertSame(2, substr_count($editHtml, 'data-f1-main>0.00 L'));
        $this->assertSame(2, substr_count($editHtml, 'data-f1-amount>0.00 L'));
        $this->assertSame(2, substr_count($editHtml, 'data-f1-total>0.00 L'));
    }

    public function test_edit_purchase_shows_two_sections_and_keeps_the_part_15_locks(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 10000, 55);
        $modalOf = function () use ($a, $premium): string {
            $html = $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory'))->assertOk()->getContent();
            $modal = substr($html, strpos($html, 'id="io-purchase-edit-'.$premium.'"'));

            return substr($modal, 0, strpos($modal, '</form>', strpos($modal, 'modal-footer')) ?: 20000);
        };

        $modal = $modalOf();
        $this->assertSame(2, substr_count($modal, '<h3 class="detail-page-title">Purchase Details</h3>'));
        $this->assertStringContainsString('<span data-detail-page-label>1 of 2</span>', $modal);
        $this->assertMatchesRegularExpression('#Quantity Purchased</div><div class="detail-value">10,000.00 L.*Cost/Liter</div><div class="detail-value">55.00.*data-detail-page\s+hidden.*Withdrawal Receipts.*Created By.*Updated At#s', $modal);
        // Editable fields below; Cancel Purchase while Pending and unused.
        $this->assertMatchesRegularExpression('#data-detail-pages.*name="purchase_date".*name="unit_cost".*Apply Changes.*Cancel Purchase#s', $modal);

        $pages = collect($this->actingAs($a['inventory'])->get(route('inventory-officer.inventory'))->viewData('purchases')->items())->firstWhere('id', $premium)['detail_pages'];
        $this->assertSame(['Date', 'Fuel', 'Depot', 'Quantity Purchased', 'Quantity Hauled', 'Cost/Liter', 'Total Cost', 'Payment Mode', 'Bank', 'Purchase Status'], array_keys($pages[0]['fields']));
        $this->assertSame(['Withdrawal Receipts', 'Latest Withdrawal Upload', 'Purchase Status', 'Item Status', 'Created By', 'Created At', 'Updated At'], array_slice(array_keys($pages[1]['fields']), 0, 7));

        // Once lifted, the Part 15 lock shows the stock fields read-only and Cancel Purchase is gone.
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 2000, 'atl_number' => 'ATL-9']])->assertSessionHasNoErrors();
        $this->completeLift($a, $this->lastHaulId());
        $modal = $modalOf();
        $this->assertStringContainsString('data-locked-field="unit_cost"', $modal);
        $this->assertStringNotContainsString('Cancel Purchase', $modal);
        $this->assertStringContainsString('data-detail-pages', $modal);
    }

    public function test_stock_out_shows_driver_name_and_truck_name_and_exports_them(): void
    {
        $a = $this->actors();
        DB::table('trucks')->where('id', $a['truck'])->update(['name' => 'Isuzu Giga', 'truck_code' => 'ABC-1234']);
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 1000, 'unit_price' => 70], [
            'delivery_truck_id' => $a['truck'], 'delivery_driver_user_id' => $a['driver']->id, 'delivery_status' => 'delivered',
        ])->assertSessionHasNoErrors();

        $html = $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory.stock-out'))->assertOk()->getContent();
        $table = substr($html, strpos($html, 'stock-out-table'), 20000);
        $this->assertStringContainsString('<th>Delivery Type</th>', $table);
        $this->assertMatchesRegularExpression('#<th>Delivery Type</th>\s*.*?<th>Driver Name</th>\s*<th>Truck</th>#s', $table);
        $this->assertStringNotContainsString('<th class="export-only" hidden>Truck</th>', $table);
        $this->assertStringNotContainsString('<th class="export-only" hidden>Driver</th>', $table);
        $this->assertStringContainsString('<td class="stock-out-name stock-out-crew">'.e($a['driver']->name).'</td>', $table);
        // The truck's name, not its plate.
        $this->assertStringContainsString('<td class="stock-out-name stock-out-crew">Isuzu Giga</td>', $table);
        // Actions stays pinned while the wider table scrolls inside its box.
        $this->assertStringContainsString('inventory-table-actions stock-out-table', $html);
    }

    public function test_standard_can_be_assigned_to_a_scheduled_lift_and_is_deducted_once_with_history(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 2000, 'unit_price' => 70])->assertSessionHasNoErrors();
        $sale = $this->lastSaleId();

        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 5000]], [$sale])->assertSessionHasNoErrors();
        $haul = $this->lastHaulId();
        $this->assertSame('depot_to_client', DB::table('sales')->where('id', $sale)->value('delivery_type'));
        $this->assertDatabaseHas('haul_allocations', ['haul_id' => $haul, 'sale_id' => $sale, 'status' => 'planned', 'quantity_liters' => 2000]);
        $this->assertSame(15000.0, $this->available($premium), 'The Standard reservation moved from depot promises to the scheduled lift.');
        $this->assertSame(0, DB::table('stock_outs')->where('sale_id', $sale)->count());

        $this->completeLift($a, $haul);
        $this->assertSame(1, DB::table('stock_outs')->where('sale_id', $sale)->where('status', 'released')->count());
        $this->assertSame(2000.0, (float) DB::table('stock_outs')->where('sale_id', $sale)->sum('quantity_liters'));
        $note = DB::table('sale_source_changes')->where('sale_id', $sale)->value('source_label');
        $this->assertSame(DB::table('hauls')->where('id', $haul)->value('haul_code'), $note);

        $page = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk();
        $page->assertSee('Changed from Standard to Depot to Client ('.$note.')');
        $page->assertSee('Source changed');
        $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory.stock-out'))->assertOk()->assertSee('Changed from Standard to Depot to Client ('.$note.')');
    }

    public function test_standard_can_be_assigned_to_truck_leftover_and_garage_lot_once(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 5000]])->assertSessionHasNoErrors();
        $haul = $this->lastHaulId();
        $this->completeLift($a, $haul);

        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 2000, 'unit_price' => 70])->assertSessionHasNoErrors();
        $truckSale = $this->lastSaleId();
        $this->actingAs($a['dispatch'])->post(route('dispatch.fuel-lifting.hauls.truck-deliveries.store', $haul), ['sale_id' => $truckSale])->assertSessionHasNoErrors();
        $this->assertSame('depot_to_client', DB::table('sales')->where('id', $truckSale)->value('delivery_type'));
        $this->assertSame(1, DB::table('stock_outs')->where('sale_id', $truckSale)->where('status', 'released')->count());

        $tank = (int) DB::table('storage_locations')->where('fuel_type_id', $this->fuel('PREM'))->where('tank_number', 1)->value('id');
        $this->actingAs($a['admin'])->post(route('admin.fuel-lifting.hauls.unload-to-garage', $haul), ['unload' => [$haul => [$tank => 3000]]])->assertSessionHasNoErrors();
        $lot = (int) DB::table('garage_stocks')->where('haul_id', $haul)->value('id');
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 1000, 'unit_price' => 70])->assertSessionHasNoErrors();
        $garageSale = $this->lastSaleId();
        $this->actingAs($a['sales'])->post(route('sales-officer.sales.assign-source', $garageSale), ['source_type' => 'garage_lot', 'source_id' => $lot] + $this->garageDelivery($a))->assertSessionHasNoErrors();
        $this->assertSame('garage_to_client', DB::table('sales')->where('id', $garageSale)->value('delivery_type'));
        $this->assertDatabaseHas('sale_items', ['sale_id' => $garageSale, 'garage_stock_id' => $lot, 'purchase_item_id' => $premium]);
        $this->deliverOrder($a, $garageSale)->assertSessionHasNoErrors();
        $this->deliverOrder($a, $garageSale)->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('stock_outs')->where('sale_id', $garageSale)->where('status', 'released')->count());
        $this->assertDatabaseHas('sale_source_changes', ['sale_id' => $garageSale, 'to_delivery_type' => 'garage_to_client', 'garage_stock_id' => $lot]);
    }

    public function test_standard_source_assignment_rejects_wrong_fuel_insufficient_source_and_delivered_orders(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 10000);
        $diesel = $this->purchaseLine($a, 'DSL', 10000);
        $this->scheduleLift($a, [['purchase_item_id' => $diesel, 'quantity_liters' => 1000]])->assertSessionHasNoErrors();
        $dieselHaul = $this->lastHaulId();
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 2000, 'unit_price' => 70])->assertSessionHasNoErrors();
        $sale = $this->lastSaleId();
        $this->actingAs($a['sales'])->post(route('sales-officer.sales.assign-source', $sale), ['source_type' => 'scheduled_lift', 'source_id' => $dieselHaul])->assertSessionHasErrors('source');

        $secondTruck = DB::table('trucks')->insertGetId(['truck_code' => 'TRK-SECOND', 'capacity_liters' => 30000, 'truck_type' => 'hauling', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 1000]], [], ['truck_id' => $secondTruck])->assertSessionHasNoErrors();
        $premiumHaul = $this->lastHaulId();
        $this->actingAs($a['sales'])->post(route('sales-officer.sales.assign-source', $sale), ['source_type' => 'scheduled_lift', 'source_id' => $premiumHaul])->assertSessionHasErrors('source');

        $this->deliverOrder($a, $sale)->assertSessionHasNoErrors();
        $this->actingAs($a['sales'])->post(route('sales-officer.sales.assign-source', $sale), ['source_type' => 'scheduled_lift', 'source_id' => $premiumHaul])->assertSessionHasErrors('source');
    }
}
