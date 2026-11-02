<?php

namespace Tests\Feature;

use App\Services\DashboardSummaryService;
use App\Services\InventoryLedgerService;
use App\Services\PurchaseService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\PurchaseFlows;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Part 19 fixes (November 3, 2026): the gaps found by the Part 19 verification.
 */
class Part19FixesTest extends AuditTestCase
{
    use PurchaseFlows;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
    }

    // ----- Inventory ---------------------------------------------------------------------------------------------

    public function test_depot_edit_saves_and_a_rejected_edit_reopens_with_the_typed_values_and_field_errors(): void
    {
        $a = $this->actors();
        DB::table('depots')->insert(['depot_code' => 'DEP-TAKEN', 'name' => 'Other Depot', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $form = ['edit_depot_id' => $a['depot'], 'name' => 'Audit Terminal North', 'address' => 'Pier 3', 'contact_person' => 'Ana', 'phone' => '0917', 'status' => 'inactive'];

        $this->actingAs($a['inventory'])->patch(route('inventory-officer.inventory.depots.update', $a['depot']), $form + ['depot_code' => 'dep-aud'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('depots', ['id' => $a['depot'], 'depot_code' => 'DEP-AUD', 'name' => 'Audit Terminal North', 'address' => 'Pier 3', 'status' => 'inactive']);

        $this->actingAs($a['inventory'])->from(route('inventory-officer.inventory.depots'))
            ->patch(route('inventory-officer.inventory.depots.update', $a['depot']), array_merge($form, ['depot_code' => 'DEP-TAKEN', 'name' => 'Typed Name']))
            ->assertSessionHasErrors('depot_code');
        $this->assertDatabaseHas('depots', ['id' => $a['depot'], 'depot_code' => 'DEP-AUD', 'name' => 'Audit Terminal North']);

        $html = $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory.depots'))->getContent();
        $this->assertStringContainsString('data-open-modal-on-load="io-depot-edit-'.$a['depot'].'"', $html);
        $this->assertStringContainsString('value="Typed Name"', $html);
        $this->assertMatchesRegularExpression('/id="depot_depot_code_'.$a['depot'].'"[^>]*aria-invalid="true"\s*><p class="field-error form-row-message">/', $html);
        // The Add Depot form does not pick up the rejected edit.
        $this->assertStringContainsString('id="depot_name_master" name="name" type="text" maxlength="255" value=""', $html);
    }

    public function test_a_cancelled_purchase_shows_cancelled_and_counts_nowhere(): void
    {
        $a = $this->actors();
        $kept = $this->purchaseLine($a, 'PREM', 10000);
        $cancelled = $this->purchaseLine($a, 'PREM', 4000);
        $this->actingAs($a['inventory'])->patch(route('inventory-officer.inventory.purchases.cancel', $cancelled))->assertSessionHasNoErrors();

        $rows = collect(app(PurchaseService::class)->purchaseRows(null)->items())->keyBy('id');
        $this->assertSame(['Cancelled', '0.00'], [$rows[$cancelled]['cells'][7], $rows[$cancelled]['cells'][15]]);
        $this->assertSame(['In Stock', '10,000.00'], [$rows[$kept]['cells'][7], $rows[$kept]['cells'][15]]);

        $cards = collect(app(DashboardSummaryService::class)->inventoryCards())->keyBy('label');
        $this->assertSame(['10,000 L', '10,000 L', '1'], [$cards['Total Inventory']['value'], $cards['Unlifted Fuel']['value'], $cards['Open Purchases']['value']]);
        $ledger = app(InventoryLedgerService::class)->rows();
        $this->assertSame([$kept], $ledger['transactions']->pluck('purchase_item_id')->all());
        // Its history stays.
        $this->assertDatabaseHas('purchase_status_histories', ['new_status' => 'cancelled']);
    }

    // ----- Sales corrections -------------------------------------------------------------------------------------

    public function test_a_sale_with_a_payment_can_correct_quantity_f1_and_prices_and_shows_its_change_history(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $f1 = $this->purchaseLine($a, 'F1', 5000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 1000, 'unit_price' => 70, 'f1_percentage' => 10, 'f1_purchase_item_id' => $f1, 'f1_unit_price' => 60], ['amount_paid' => 1000])
            ->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();

        $this->correct($a, $saleId, ['purchase_item_id' => $premium, 'f1_purchase_item_id' => $f1, 'quantity_liters' => 1200, 'unit_price' => 75, 'f1_percentage' => 15, 'f1_unit_price' => 65])
            ->assertSessionHasNoErrors();

        $item = DB::table('sale_items')->where('sale_id', $saleId)->first();
        // 1,020 L x 75 + 180 L x 65.
        $this->assertSame([1200.0, 1020.0, 180.0, 88200.0], [(float) $item->quantity_liters, (float) $item->main_quantity_liters, (float) $item->f1_quantity_liters, (float) $item->line_total]);
        $this->assertSame(1, DB::table('payments')->where('sale_id', $saleId)->count());
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'status' => 'partially_paid']);
        $this->assertDatabaseHas('sale_change_logs', ['sale_id' => $saleId, 'field' => 'items.0.unit_price', 'old_value' => '70', 'new_value' => '75']);

        $html = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->getContent();
        $this->assertStringContainsString('Change History', $html);
        $this->assertStringContainsString('<td>Line 1 QTY</td><td>1000</td><td>1200</td>', $html);
    }

    public function test_unsafe_corrections_are_refused_and_nothing_changes(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 2000);
        $other = $this->purchaseLine($a, 'PREM', 9000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 1000, 'unit_price' => 65], ['amount_paid' => 60000])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();

        // Below what was paid (50,000 < 60,000).
        $this->correct($a, $saleId, ['purchase_item_id' => $premium, 'quantity_liters' => 1000, 'unit_price' => 50])->assertSessionHasErrors('sale');
        // More than the purchase still has.
        $this->correct($a, $saleId, ['purchase_item_id' => $premium, 'quantity_liters' => 5000, 'unit_price' => 65])->assertSessionHasErrors('items.0.quantity_liters');
        // Another source.
        $this->correct($a, $saleId, ['purchase_item_id' => $other, 'quantity_liters' => 1000, 'unit_price' => 65])->assertSessionHasErrors('items.0.purchase_item_id');
        // An F1 portion with no F1 source of its own.
        $this->correct($a, $saleId, ['purchase_item_id' => $premium, 'quantity_liters' => 1000, 'unit_price' => 65, 'f1_percentage' => 10, 'f1_unit_price' => 60])->assertSessionHasErrors('items.0.f1_percentage');

        $this->assertDatabaseHas('sale_items', ['sale_id' => $saleId, 'purchase_item_id' => $premium, 'quantity_liters' => 1000, 'unit_price' => 65, 'line_total' => 65000]);
        $this->assertSame(0, DB::table('sale_change_logs')->where('sale_id', $saleId)->count());
    }

    public function test_a_delivered_sale_corrects_its_prices_only_and_keeps_its_stock_out(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 10000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 1000, 'unit_price' => 50], ['amount_paid' => 10000])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $this->deliverOrder($a, $saleId)->assertSessionHasNoErrors();
        $stockOuts = DB::table('stock_outs')->where('sale_id', $saleId)->get(['id', 'quantity_liters', 'status', 'unit_cost'])->toArray();

        $this->correct($a, $saleId, ['purchase_item_id' => $premium, 'quantity_liters' => 900, 'unit_price' => 50])->assertSessionHasErrors('items.0.quantity_liters');
        $this->correct($a, $saleId, ['purchase_item_id' => $premium, 'quantity_liters' => 1000, 'unit_price' => 52])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sale_items', ['sale_id' => $saleId, 'quantity_liters' => 1000, 'unit_price' => 52, 'line_total' => 52000, 'fulfilled_quantity_liters' => 1000]);
        $this->assertEquals($stockOuts, DB::table('stock_outs')->where('sale_id', $saleId)->get(['id', 'quantity_liters', 'status', 'unit_cost'])->toArray());
        $this->assertSame(9000.0, $this->remaining($premium));
    }

    public function test_deleting_an_untouched_draft_keeps_its_change_history(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 10000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 1000, 'unit_price' => 65], ['status' => 'draft'])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'status' => 'draft']);
        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.update', $saleId), [
            'edit_sale_id' => $saleId, 'customer_id' => $a['customer'], 'sale_date' => '2026-10-15', 'sales_order_number' => 'SO-DRAFT-1',
            'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment', 'status' => 'draft',
        ])->assertSessionHasNoErrors();
        $saleCode = (string) DB::table('sales')->where('id', $saleId)->value('sale_code');
        $logId = (int) DB::table('sale_change_logs')->where('sale_id', $saleId)->where('field', 'sales_order_number')->value('id');
        $this->assertGreaterThan(0, $logId);

        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.cancel', $saleId))->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sales', ['id' => $saleId]);
        $this->assertDatabaseHas('sale_change_logs', ['id' => $logId, 'sale_id' => null, 'sale_code' => $saleCode, 'field' => 'sales_order_number', 'new_value' => 'SO-DRAFT-1']);
    }

    // ----- Refunds -----------------------------------------------------------------------------------------------

    public function test_refunds_use_the_payment_methods_valid_dates_and_keep_field_history(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 10000);
        // Paid on the sale date, 2026-10-15.
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 1000, 'unit_price' => 65], ['amount_paid' => 10000])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $this->actingAs($a['sales'])->patch(route('sales-officer.sales.cancel', $saleId), ['cancel_reason' => 'duplicate_entry'])->assertSessionHasNoErrors();
        $refund = fn (array $data) => $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->post(route('sales-officer.sales.refunds.store', $saleId), $data + [
            'idempotency_key' => $this->uuid(), 'refund_sale_id' => $saleId, 'amount' => 4000, 'refund_date' => '2026-11-01', 'method' => 'bank_transfer',
        ]);

        $refund(['method' => 'Bank Transfer'])->assertSessionHasErrors('method');
        $refund(['refund_date' => '2026-11-03'])->assertSessionHasErrors('refund_date');
        $refund(['refund_date' => '2026-10-14'])->assertSessionHasErrors('refund_date');
        $token = $this->uuid();
        $refund(['idempotency_key' => $token])->assertSessionHasNoErrors();
        $refund(['idempotency_key' => $token])->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('sale_refunds')->where('sale_id', $saleId)->count());
        $refundId = (int) DB::table('sale_refunds')->where('sale_id', $saleId)->value('id');
        $this->assertDatabaseHas('sale_change_logs', ['sale_id' => $saleId, 'field' => 'refunds.'.$refundId.'.amount', 'old_value' => null, 'new_value' => '4000']);

        $this->actingAs($a['sales'])->patch(route('sales-officer.sales.refunds.update', [$saleId, $refundId]), [
            'refund_sale_id' => $saleId, 'refund_id' => $refundId, 'amount' => 4500, 'refund_date' => '2026-11-02', 'method' => 'cheque', 'reference_number' => 'CHK-9',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sale_refunds', ['id' => $refundId, 'amount' => 4500, 'method' => 'cheque', 'reference_number' => 'CHK-9']);
        foreach ([['amount', '4000', '4500'], ['method', 'bank_transfer', 'cheque'], ['refund_date', '2026-11-01', '2026-11-02'], ['reference_number', null, 'CHK-9']] as [$field, $old, $new]) {
            $this->assertDatabaseHas('sale_change_logs', ['sale_id' => $saleId, 'field' => 'refunds.'.$refundId.'.'.$field, 'old_value' => $old, 'new_value' => $new]);
        }

        // A refund saved with a label before Part 19 is read as its value.
        DB::table('sale_refunds')->where('id', $refundId)->update(['method' => 'Bank Transfer']);
        $html = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->getContent();
        $this->assertMatchesRegularExpression('/id="refund_method_'.$refundId.'" name="method" required>.*<option value="bank_transfer" selected>/s', $html);
        $this->assertStringContainsString('Refund due: ₱5,500.00', $html);
    }

    public function test_the_sales_page_works_before_the_part_19_migrations(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Dropping tables commits on MariaDB and would break the other tests; checked on SQLite.');
        }

        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 10000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 1000, 'unit_price' => 65], ['amount_paid' => 1000])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 500, 'unit_price' => 65], ['amount_paid' => 1000])->assertSessionHasNoErrors();
        $cancelledId = $this->lastSaleId();
        $this->actingAs($a['sales'])->patch(route('sales-officer.sales.cancel', $cancelledId), ['cancel_reason' => 'duplicate_entry'])->assertSessionHasNoErrors();
        Schema::drop('sale_change_logs');
        Schema::drop('sale_refunds');

        $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->assertDontSee('Customer Refunds');
        $this->actingAs($a['sales'])->get(route('sales-officer.sales.customers'))->assertOk();
        $this->actingAs($a['sales'])->post(route('sales-officer.sales.refunds.store', $cancelledId), ['amount' => 100, 'refund_date' => '2026-11-01', 'method' => 'cheque'])
            ->assertSessionHasErrors('refund');
        $this->correct($a, $saleId, ['purchase_item_id' => $premium, 'quantity_liters' => 1100, 'unit_price' => 65])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sale_items', ['sale_id' => $saleId, 'quantity_liters' => 1100]);
    }

    public function test_the_sales_page_works_when_the_sale_code_audit_column_is_not_installed_yet(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Dropping columns on MariaDB would alter the test schema for the remaining tests; checked on SQLite.');
        }

        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 10000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 1000, 'unit_price' => 65], ['amount_paid' => 1000])
            ->assertSessionHasNoErrors();

        Schema::table('sale_change_logs', function ($table): void {
            $table->dropIndex(['sale_code']);
            $table->dropColumn('sale_code');
        });

        $this->actingAs($a['sales'])->get(route('sales-officer.sales'))
            ->assertOk()
            ->assertDontSee('Internal Server Error');
    }

    // ----- Fuel Lifting ------------------------------------------------------------------------------------------

    public function test_a_multi_lift_schedule_completes_only_when_every_lift_line_is_empty(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $premium2 = $this->purchaseLine($a, 'PREM', 20000);
        $this->scheduleLift($a, [
            ['purchase_item_id' => $premium, 'quantity_liters' => 10000, 'atl_number' => 'ATL-P'],
            ['purchase_item_id' => $premium2, 'quantity_liters' => 8000, 'atl_number' => 'ATL-P2'],
        ])->assertSessionHasNoErrors();
        [$first, $second] = DB::table('hauls')->whereNotNull('lifting_schedule_id')->orderBy('id')->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $this->completeLift($a, $first);
        $this->assertSame(2, DB::table('hauls')->whereIn('id', [$first, $second])->where('status', 'lifted')->count());
        $codes = DB::table('hauls')->whereIn('id', [$first, $second])->pluck('haul_code', 'id');
        $complete = fn () => $this->actingAs($a['dispatch'])->from(route('dispatch.fuel-lifting'))
            ->patch(route('dispatch.fuel-lifting.hauls.status', $first), ['idempotency_key' => $this->uuid(), 'status' => 'completed']);
        $unload = fn (int $haul, string $fuel, float $liters) => $this->actingAs($a['dispatch'])->from(route('dispatch.fuel-lifting'))
            ->post(route('dispatch.fuel-lifting.hauls.unload-to-garage', $haul), ['unload' => [$haul => [$this->tank($fuel) => $liters]]]);

        $complete()->assertSessionHasErrors(['lifting' => 'This lift cannot be completed while fuel remains on the truck: '.$codes[$first].' 10,000.00 L, '.$codes[$second].' 8,000.00 L. Deliver or unload the remaining fuel first.']);

        // Only the second line still has fuel (a partial unload of it too): nothing completes.
        $unload($first, 'PREM', 10000)->assertSessionHasNoErrors();
        $unload($second, 'PREM', 5000)->assertSessionHasNoErrors();
        $complete()->assertSessionHasErrors(['lifting' => 'This lift cannot be completed while fuel remains on the truck: '.$codes[$second].' 3,000.00 L. Deliver or unload the remaining fuel first.']);
        $this->assertSame(2, DB::table('hauls')->whereIn('id', [$first, $second])->where('status', 'lifted')->count());

        $unload($second, 'PREM', 3000)->assertSessionHasNoErrors();
        $complete()->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('hauls')->whereIn('id', [$first, $second])->where('status', 'completed')->count());
    }

    public function test_the_report_lists_completed_lifts_with_fuel_on_the_truck_and_changes_nothing(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 10000, 'atl_number' => 'ATL-P']])->assertSessionHasNoErrors();
        $haulId = $this->lastHaulId();
        $this->completeLift($a, $haulId);
        // A lift completed before Part 19 (the truck was never emptied).
        DB::table('hauls')->where('id', $haulId)->update(['status' => 'completed']);
        $before = DB::table('hauls')->where('id', $haulId)->first();
        $code = (string) $before->haul_code;

        $this->artisan('lifts:completed-with-fuel')
            ->expectsOutputToContain($code)
            ->expectsOutputToContain('1 Completed lift(s) still have fuel on the truck. Nothing was changed.')
            ->assertExitCode(0);
        $this->assertEquals($before, DB::table('hauls')->where('id', $haulId)->first());
    }

    /**
     * Edit Sales with one order line (sources unchanged unless the test changes them).
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $line
     */
    private function correct(array $a, int $saleId, array $line)
    {
        $sale = DB::table('sales')->where('id', $saleId)->first();

        return $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.update', $saleId), [
            'edit_sale_id' => $saleId, 'customer_id' => $sale->customer_id, 'sale_date' => $sale->sale_date,
            'payment_method' => $sale->payment_method, 'payment_terms' => $sale->payment_terms, 'items' => [$line],
        ]);
    }

    private function tank(string $fuelCode): int
    {
        $tank = DB::table('storage_locations')->where('type', 'garage')->where('status', 'active')->where('fuel_type_id', $this->fuel($fuelCode))->orderBy('tank_number')->value('id');
        $this->assertNotNull($tank);

        return (int) $tank;
    }
}
