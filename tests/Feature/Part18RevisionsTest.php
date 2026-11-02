<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Concerns\PurchaseFlows;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Part 18 (System Analysis "Part 18, Nov 1 2026"), Sales: the line table's Purchase ID wraps instead of overlapping,
 * one label-left / input-right alignment in the edit section, Delivery Details when a Standard order becomes Garage to
 * Client, only editable fields in the edit sections, and no Delivery Details inputs for Depot to Client.
 */
class Part18RevisionsTest extends AuditTestCase
{
    use PurchaseFlows;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 09:00:00');
    }

    // ----- 1. Line-items table ---------------------------------------------------------------------------------

    public function test_the_combined_purchase_id_wraps_in_its_own_wide_column(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $f1 = $this->purchaseLine($a, 'F1', 5000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 3000, 'unit_price' => 65, 'f1_percentage' => 10, 'f1_purchase_item_id' => $f1, 'f1_unit_price' => 60])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        [$premiumCode, $f1Code] = [$this->purchaseCode($premium), $this->purchaseCode($f1)];

        $modal = $this->saleModal($a, $saleId);
        $this->assertStringContainsString('<colgroup><col><col class="sales-items-col-source"><col span="8"></colgroup>', $modal);
        $this->assertStringContainsString('<td class="sales-items-source"><span>'.$premiumCode.'</span><span>+ F1 '.$f1Code.'</span></td>', $modal);

        // No rule of the line table keeps its cells on one line (that made Purchase ID run over QTY).
        foreach (['css/app.css', 'css/part9.css'] as $file) {
            preg_match_all('/\.sales-items-table[^{]*\{[^}]*\}/', File::get(resource_path($file)), $rules);
            $this->assertNotEmpty($rules[0], $file);
            foreach ($rules[0] as $rule) {
                $this->assertStringNotContainsString('nowrap', $rule, $file);
            }
        }
        $css = File::get(resource_path('css/app.css'));
        $this->assertStringContainsString(".admin-modal-sales .sales-items-table .sales-items-col-source {\n    width: 20%;", $css);
        $this->assertStringContainsString(".admin-modal-sales .sales-items-table .sales-items-source span {\n    display: block;", $css);
    }

    // ----- 2. Field alignment ----------------------------------------------------------------------------------

    public function test_every_edit_field_uses_the_label_left_input_right_row(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 1000, 'unit_price' => 70])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();

        $edit = $this->editSection($this->saleModal($a, $saleId));
        // Fuel Type, Fuel Price and the F1 fields sit in the same edit grid as Sales Order Number and Payment Method.
        $this->assertMatchesRegularExpression('#name="sales_order_number".*name="items\[0\]\[purchase_item_id\]".*name="items\[0\]\[unit_price\]".*name="items\[0\]\[f1_purchase_item_id\]".*name="payment_method"#s', $edit);
        $this->assertStringNotContainsString('data-stock-hint', $edit);

        $css = File::get(resource_path('css/app.css'));
        $this->assertStringContainsString(".admin-modal-sales .sale-edit-fields .sale-pair > .form-row,\n.admin-modal-sales .sale-edit-fields .sale-f1-row > .form-row {\n    grid-template-columns: 158px minmax(0, 1fr);", $css);
        $this->assertStringContainsString(".admin-modal-sales .form-row {\n    grid-template-columns: 158px minmax(0, 1fr);", $css);
        $this->assertStringContainsString(".admin-modal-sales .sale-edit-fields .sale-f1-row {\n    grid-template-columns: minmax(0, 1fr);", $css);
    }

    // ----- 3. Garage to Client Delivery Details ----------------------------------------------------------------

    public function test_assigning_a_garage_lot_asks_for_the_delivery_details_in_the_same_step(): void
    {
        $a = $this->actors();
        [$premium, $lot] = $this->garageLot($a);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 1000, 'unit_price' => 70])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();

        // The garage lot's Assign form carries the Delivery Details (required); the lift ones do not.
        $modal = $this->saleModal($a, $saleId);
        $garageForm = substr($modal, strpos($modal, 'value="garage_lot"'));
        $garageForm = substr($garageForm, 0, strpos($garageForm, '</form>'));
        foreach (['delivery_truck_id', 'delivery_driver_user_id', 'delivery_date'] as $field) {
            $this->assertMatchesRegularExpression('/name="'.$field.'"[^>]*required/', $garageForm);
        }
        $this->assertStringContainsString('name="delivery_status"', $garageForm);
        $liftForm = substr($modal, strpos($modal, 'value="scheduled_lift"'), 1500);
        $this->assertStringNotContainsString('name="delivery_truck_id"', substr($liftForm, 0, strpos($liftForm, '</form>')));

        // Without them the order stays Standard.
        $assign = fn (array $extra = []) => $this->actingAs($a['sales'])->from(route('sales-officer.sales'))
            ->post(route('sales-officer.sales.assign-source', $saleId), ['edit_sale_id' => $saleId, 'source_type' => 'garage_lot', 'source_id' => $lot] + $extra);
        $assign()->assertSessionHasErrors(['delivery_truck_id', 'delivery_driver_user_id', 'delivery_date']);
        $assign(['delivery_truck_id' => $a['truck']])->assertSessionHasErrors(['delivery_driver_user_id', 'delivery_date']);
        $this->assertSame('standard', DB::table('sales')->where('id', $saleId)->value('delivery_type'));

        // With them it becomes Garage to Client with its truck, driver and date (Pending by default).
        $assign($this->garageDelivery($a))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'delivery_type' => 'garage_to_client', 'delivery_truck_id' => $a['truck'], 'delivery_driver_user_id' => $a['driver']->id, 'delivery_status' => 'pending']);
        $this->assertSame('2026-10-28 10:00', date('Y-m-d H:i', strtotime((string) DB::table('sales')->where('id', $saleId)->value('delivery_date'))));

        // Then they are editable like any Garage to Client order, and still required, until it is Delivered.
        $edit = $this->editSection($this->saleModal($a, $saleId));
        $this->assertMatchesRegularExpression('/id="sale_'.$saleId.'_delivery_truck_id" name="delivery_truck_id" required/', $edit);
        $this->assertMatchesRegularExpression('/<option value="'.$a['truck'].'" selected>/', $edit);
        $update = fn (array $extra) => $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.update', $saleId), $extra + [
            'edit_sale_id' => $saleId, 'customer_id' => $a['customer'], 'sale_date' => '2026-10-15', 'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment',
            'items' => [['purchase_item_id' => 'garage:'.$lot, 'quantity_liters' => 1000, 'unit_price' => 70]],
        ]);
        $update([])->assertSessionHasErrors(['delivery_truck_id', 'delivery_driver_user_id', 'delivery_date']);
        $update(['delivery_date' => '2026-10-29 08:30'] + $this->garageDelivery($a))->assertSessionHasNoErrors();
        $this->assertStringStartsWith('2026-10-29 08:30', (string) DB::table('sales')->where('id', $saleId)->value('delivery_date'));

        $this->deliverOrder($a, $saleId)->assertSessionHasNoErrors();
        $this->assertStringNotContainsString('name="delivery_truck_id"', $this->editSection($this->saleModal($a, $saleId)));
    }

    public function test_a_garage_lot_assigned_as_delivered_is_deducted_once(): void
    {
        $a = $this->actors();
        [$premium, $lot] = $this->garageLot($a);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 1000, 'unit_price' => 70])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();

        $this->actingAs($a['sales'])->post(route('sales-officer.sales.assign-source', $saleId), ['source_type' => 'garage_lot', 'source_id' => $lot, 'delivery_status' => 'delivered'] + $this->garageDelivery($a))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'delivery_type' => 'garage_to_client', 'delivery_status' => 'delivered']);
        $this->assertSame(1, DB::table('stock_outs')->where('sale_id', $saleId)->where('garage_stock_id', $lot)->count());
    }

    public function test_record_sales_garage_to_client_requires_the_delivery_details(): void
    {
        $a = $this->actors();
        [, $lot] = $this->garageLot($a);
        $line = ['purchase_item_id' => 'garage:'.$lot, 'quantity_liters' => 500, 'unit_price' => 60];

        $this->recordOrder($a, $line, ['delivery_type' => 'garage_to_client'])->assertSessionHasErrors(['delivery_truck_id', 'delivery_driver_user_id', 'delivery_date']);
        $this->assertSame(0, DB::table('sales')->count());
        $this->recordOrder($a, $line, ['delivery_type' => 'garage_to_client'] + $this->garageDelivery($a))->assertSessionHasNoErrors();

        // Record Sales: the section is shown for Garage to Client only, with required fields.
        $html = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $record = substr($html, strpos($html, 'id="so-sales-add"'), strpos($html, 'id="so-sales-edit-') - strpos($html, 'id="so-sales-add"'));
        $this->assertMatchesRegularExpression('/<div data-delivery-details\s+hidden\s*>/', $record);
        $this->assertMatchesRegularExpression('/id="sale_delivery_truck_id" name="delivery_truck_id" required\s+disabled/', $record);
        $this->assertStringContainsString("const showDeliveryDetails = select.value === 'garage_to_client';", File::get(resource_path('js/app.js')));
    }

    // ----- 4. Only editable fields in the edit sections --------------------------------------------------------

    public function test_the_edit_section_shows_only_what_can_still_change(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 1000, 'unit_price' => 70])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();

        // An open order without payments: its customer, date, line and status can still change.
        $edit = $this->editSection($this->saleModal($a, $saleId));
        $this->assertSame(['delivery_status', 'sales_order_number', 'sale_date', 'customer_id', 'items[0][quantity_liters]', 'items[0][purchase_item_id]', 'items[0][unit_price]', 'items[0][f1_purchase_item_id]', 'items[0][f1_unit_price]', 'items[0][f1_percentage]', 'payment_method', 'payment_terms', 'due_date', 'status'], $this->fieldNames($edit));
        $this->assertNoReadOnlyRows($edit);

        // A payment fixes customer, date, line and status (sent back unchanged, hidden).
        $this->actingAs($a['sales'])->post(route('sales-officer.sales.payments.store', $saleId), [
            'idempotency_key' => (string) Str::uuid(), 'payment_date' => '2026-10-15', 'amount' => 100, 'method' => 'cash_on_delivery',
        ])->assertSessionHasNoErrors();
        $edit = $this->editSection($this->saleModal($a, $saleId));
        $this->assertSame(['delivery_status', 'sales_order_number', 'payment_method', 'payment_terms', 'due_date'], $this->fieldNames($edit));
        $this->assertNoReadOnlyRows($edit);

        // Delivered: only Sales Order No., Payment Method, Payment Terms and Due Date (no "Delivered: ..." note).
        $this->deliverOrder($a, $saleId)->assertSessionHasNoErrors();
        $edit = $this->editSection($this->saleModal($a, $saleId));
        $this->assertSame(['sales_order_number', 'payment_method', 'payment_terms', 'due_date'], $this->fieldNames($edit));
        $this->assertNoReadOnlyRows($edit);
        $this->assertStringNotContainsString('Update Delivery', $edit);

        // ...and that form still saves.
        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.update', $saleId), [
            'edit_sale_id' => $saleId, 'sales_order_number' => 'SO-DELIVERED', 'customer_id' => $a['customer'], 'sale_date' => '2026-10-15',
            'payment_method' => 'cheque', 'payment_terms' => 'installment', 'due_date' => '2026-11-30',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'sales_order_number' => 'SO-DELIVERED', 'payment_method' => 'cheque']);
    }

    public function test_a_paid_open_order_saves_without_its_hidden_line(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 1000, 'unit_price' => 70], ['amount_paid' => 500])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();

        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.update', $saleId), [
            'edit_sale_id' => $saleId, 'sales_order_number' => 'SO-PAID', 'customer_id' => $a['customer'], 'sale_date' => '2026-10-15',
            'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'sales_order_number' => 'SO-PAID']);
        $this->assertDatabaseHas('sale_items', ['sale_id' => $saleId, 'purchase_item_id' => $premium, 'quantity_liters' => 1000]);
    }

    public function test_edit_purchase_keeps_read_only_values_in_the_cards(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 10000);
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 2000]])->assertSessionHasNoErrors();
        $this->completeLift($a, $this->lastHaulId());

        $html = $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory'))->assertOk()->getContent();
        $modal = substr($html, strpos($html, 'title="Edit Purchase Record"') ?: 0);
        $modal = substr($modal, strpos($modal, 'data-detail-pages'));
        $edit = substr($modal, strpos($modal, '<div class="form-grid"'), strpos($modal, 'Apply Changes') - strpos($modal, '<div class="form-grid"'));
        $this->assertStringNotContainsString('form-locked-value', $edit);
        $this->assertStringNotContainsString('<label>Purchase Status</label>', $edit);
        $this->assertStringNotContainsString('<output', $edit);
        $this->assertMatchesRegularExpression('/type="hidden" name="unit_cost" value="[^"]*" data-locked-field="unit_cost"/', $edit);
        // The locked values are in the Purchase Details card.
        $this->assertMatchesRegularExpression('#Cost/Liter</div><div class="detail-value">50.00</div>.*Purchase Status</div>#s', $modal);
    }

    // ----- 5. Depot to Client ----------------------------------------------------------------------------------

    public function test_depot_to_client_has_no_delivery_inputs_and_shows_its_lift(): void
    {
        $a = $this->actors();
        DB::table('trucks')->where('id', $a['truck'])->update(['name' => 'Isuzu Giga']);
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        // Delivery fields sent with a Depot to Client order are ignored.
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 2000, 'unit_price' => 70], ['delivery_type' => 'depot_to_client'] + $this->garageDelivery($a))->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'delivery_truck_id' => null, 'delivery_driver_user_id' => null, 'delivery_date' => null]);

        $modal = $this->saleModal($a, $saleId);
        $edit = $this->editSection($modal);
        foreach (['delivery_truck_id', 'delivery_driver_user_id', 'delivery_date', 'delivery_status'] as $field) {
            $this->assertStringNotContainsString('name="'.$field.'"', $edit);
        }
        $this->assertStringNotContainsString('Update Delivery', $edit);

        $delivery = fn (): array => collect($this->actingAs($a['sales'])->get(route('sales-officer.sales'))->viewData('sales')->items())->firstWhere('id', $saleId)['detail_pages'][1]['fields'];
        $this->assertSame(['Lift ID' => 'Not on a lift yet', 'Truck ID' => 'Not on a lift yet', 'Driver Name' => 'Not on a lift yet', 'Delivery Date' => 'Not on a lift yet'], array_slice($delivery(), 0, 4));

        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 2000, 'atl_number' => 'ATL-1']], [$saleId])->assertSessionHasNoErrors();
        $fields = $delivery();
        $this->assertSame(DB::table('hauls')->where('id', $this->lastHaulId())->value('haul_code'), $fields['Lift ID']);
        $this->assertStringContainsString('Isuzu Giga', $fields['Truck ID']);
        $this->assertSame($a['driver']->name, $fields['Driver Name']);
        $this->assertSame('10/16/2026 08:00 AM', $fields['Delivery Date']);
    }

    public function test_a_depot_to_client_delivery_status_is_set_by_its_lift_only(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 2000, 'unit_price' => 70], ['delivery_type' => 'depot_to_client'])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $manual = fn (string $status) => $this->actingAs($a['sales'])->from(route('sales-officer.sales'))
            ->patch(route('sales-officer.sales.delivery-status.update', $saleId), ['delivery_status' => $status]);
        $refused = 'A Depot to Client order is delivered through its lift, so its delivery status cannot be changed here.';

        // Every manual status is refused, before and while it is on a lift; nothing leaves stock.
        foreach (['out_for_delivery', 'delivered', 'pending'] as $status) {
            $manual($status)->assertSessionHasErrors(['delivery_status' => $refused]);
        }
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 2000, 'atl_number' => 'ATL-1']], [$saleId])->assertSessionHasNoErrors();
        $manual('delivered')->assertSessionHasErrors(['delivery_status' => $refused]);
        $manual('delivered')->assertSessionHasErrors(['delivery_status' => $refused]);
        $this->actingAs($a['admin'])->from(route('admin.sales'))->patch(route('admin.sales.delivery-status.update', $saleId), ['delivery_status' => 'delivered'])
            ->assertSessionHasErrors(['delivery_status' => $refused]);
        $this->assertSame('pending', DB::table('sales')->where('id', $saleId)->value('delivery_status'));
        $this->assertSame(0, DB::table('stock_outs')->where('sale_id', $saleId)->count());

        // The lift delivers it.
        $this->completeLift($a, $this->lastHaulId());
        $this->assertSame('delivered', DB::table('sales')->where('id', $saleId)->value('delivery_status'));

        // A Standard order can still be delivered by hand.
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 500, 'unit_price' => 70])->assertSessionHasNoErrors();
        $this->deliverOrder($a, $this->lastSaleId())->assertSessionHasNoErrors();
        $this->assertSame('delivered', DB::table('sales')->where('id', $this->lastSaleId())->value('delivery_status'));
    }

    // ----- helpers ---------------------------------------------------------------------------------------------

    private function saleModal(array $a, int $saleId): string
    {
        $html = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $modal = substr($html, strpos($html, 'id="so-sales-edit-'.$saleId.'"'));

        return substr($modal, 0, strpos($modal, 'id="so-payment-history-'.$saleId.'"'));
    }

    private function editSection(string $modal): string
    {
        $start = strpos($modal, 'data-sale-edit-fields');
        $this->assertNotFalse($start, 'The edit section is missing.');

        return substr($modal, $start, strpos($modal, '<div class="modal-footer">', $start) - $start);
    }

    /** @return array<int, string> the visible inputs of an edit section, in order */
    private function fieldNames(string $edit): array
    {
        preg_match_all('/<(?:input|select)\b(?![^>]*type="hidden")[^>]*\bname="([^"]+)"/', $edit, $matches);

        return $matches[1];
    }

    private function assertNoReadOnlyRows(string $edit): void
    {
        // A label with a read-only value instead of an input, or the old Delivered note.
        $this->assertDoesNotMatchRegularExpression('#<label>(?!Inventory)[^<]*</label>\s*<output#', $edit);
        $this->assertDoesNotMatchRegularExpression('#<label[^>]*>[^<]*</label>\s*<output#', $edit);
        foreach (['data-payment-status', 'set by payments', 'Delivered: the order details', '<label>Delivery Type</label>', '<label>Payment Status</label>'] as $text) {
            $this->assertStringNotContainsString($text, $edit);
        }
    }

    /** @return array{0: int, 1: int} the Premium purchase and a garage lot of 3,000 L from it */
    private function garageLot(array $a): array
    {
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 5000]])->assertSessionHasNoErrors();
        $haul = $this->lastHaulId();
        $this->completeLift($a, $haul);
        $tank = (int) DB::table('storage_locations')->where('type', 'garage')->where('status', 'active')->where('fuel_type_id', $this->fuel('PREM'))->where('tank_number', 1)->value('id');
        $this->actingAs($a['admin'])->post(route('admin.fuel-lifting.hauls.unload-to-garage', $haul), ['unload' => [$haul => [$tank => 3000]]])->assertSessionHasNoErrors();

        return [$premium, (int) DB::table('garage_stocks')->where('haul_id', $haul)->value('id')];
    }

    private function purchaseCode(int $purchaseItemId): string
    {
        return (string) DB::table('purchase_items')->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')->where('purchase_items.id', $purchaseItemId)->value('purchases.purchase_code');
    }
}
