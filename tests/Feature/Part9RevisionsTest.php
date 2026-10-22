<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Audit\AuditTestCase;

/**
 * PART 9 (October 19, 2026): Purchases Payment Mode / Bank and the read-only Payment Status of Record Sales.
 * Its Schedule Lift rows, tank and garage F1 rules were retired by the Part 2 Button Revisions (October 22, 2026),
 * which Part2ButtonRevisionsTest covers.
 */
class Part9RevisionsTest extends AuditTestCase
{

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
        // The Edit form keeps its order Status dropdown; Payment Status is never a submitted field.
        $this->assertStringContainsString('<label for="sale_status_'.$ids[0].'">Status</label>', $html);
        $this->assertStringNotContainsString('name="sale_payment_status"', $html);
    }

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

    private function between(string $html, string $start, string $end): string
    {
        $from = strpos($html, $start);
        $this->assertNotFalse($from, "Missing: $start");
        $to = strpos($html, $end, $from);

        return substr($html, $from, $to === false ? null : $to - $from);
    }
}
