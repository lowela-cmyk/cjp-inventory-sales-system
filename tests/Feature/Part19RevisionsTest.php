<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PurchaseFlows;
use Tests\Feature\Audit\AuditTestCase;

class Part19RevisionsTest extends AuditTestCase
{
    use PurchaseFlows;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-02 09:00:00');
    }

    public function test_new_sale_dates_default_to_today_and_existing_sales_can_edit_line_values(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $f1 = $this->purchaseLine($a, 'F1', 5000);

        $page = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $this->assertStringContainsString('id="sale_payment_date" name="payment_date" type="date" value="2026-11-02"', $page);
        $this->assertStringContainsString('id="sale_due_date" name="due_date" type="date" value="2026-11-02"', $page);

        $this->recordOrder($a, [
            'purchase_item_id' => $premium, 'quantity_liters' => 1000, 'unit_price' => 70,
            'f1_percentage' => 10, 'f1_purchase_item_id' => $f1, 'f1_unit_price' => 60,
        ])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();

        $this->actingAs($a['sales'])->patch(route('sales-officer.sales.update', $saleId), [
            'edit_sale_id' => $saleId, 'customer_id' => $a['customer'], 'sale_date' => '2026-11-01',
            'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment', 'due_date' => '2026-11-30',
            'items' => [[
                'purchase_item_id' => $premium, 'quantity_liters' => 1200, 'unit_price' => 75,
                'f1_percentage' => 15, 'f1_purchase_item_id' => $f1, 'f1_unit_price' => 65,
            ]],
        ])->assertSessionHasNoErrors();

        $item = DB::table('sale_items')->where('sale_id', $saleId)->first();
        $this->assertSame([1200.0, 15.0, 180.0, 75.0, 65.0, 88200.0], [
            (float) $item->quantity_liters, (float) $item->f1_percentage, (float) $item->f1_quantity_liters,
            (float) $item->unit_price, (float) $item->f1_unit_price, (float) $item->line_total,
        ]);
        $this->assertDatabaseHas('receivables', ['sale_id' => $saleId, 'due_date' => '2026-11-30']);
        $this->assertDatabaseHas('sale_change_logs', ['sale_id' => $saleId, 'field' => 'items.0.quantity_liters', 'old_value' => '1000', 'new_value' => '1200']);
        $this->assertDatabaseHas('sale_change_logs', ['sale_id' => $saleId, 'field' => 'items.0.f1_percentage', 'old_value' => '10', 'new_value' => '15']);
    }

    public function test_refund_edits_are_bounded_by_the_remaining_refundable_balance(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 10000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 1000, 'unit_price' => 65], ['amount_paid' => 10000])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $this->actingAs($a['sales'])->patch(route('sales-officer.sales.cancel', $saleId), ['cancel_reason' => 'duplicate_entry'])->assertSessionHasNoErrors();

        $refundToken = (string) Str::uuid();
        $refundPayload = [
            'idempotency_key' => $refundToken, 'amount' => 6000, 'refund_date' => '2026-11-02', 'method' => 'bank_transfer', 'reference_number' => 'REF-1', 'remarks' => 'Initial refund',
        ];
        $this->actingAs($a['sales'])->post(route('sales-officer.sales.refunds.store', $saleId), $refundPayload)->assertSessionHasNoErrors();
        $this->actingAs($a['sales'])->post(route('sales-officer.sales.refunds.store', $saleId), $refundPayload)->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('sale_refunds')->where('sale_id', $saleId)->count());
        $refundId = (int) DB::table('sale_refunds')->where('sale_id', $saleId)->value('id');

        $this->actingAs($a['sales'])->patch(route('sales-officer.sales.refunds.update', [$saleId, $refundId]), [
            'amount' => 7000, 'refund_date' => '2026-11-03', 'method' => 'cash', 'reference_number' => 'REF-2', 'remarks' => 'Corrected refund',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sale_refunds', ['id' => $refundId, 'amount' => '7000.00', 'method' => 'cash', 'reference_number' => 'REF-2']);

        $this->actingAs($a['sales'])->patch(route('sales-officer.sales.refunds.update', [$saleId, $refundId]), [
            'amount' => 11000, 'refund_date' => '2026-11-03', 'method' => 'cash',
        ])->assertSessionHasErrors('amount');
        $this->assertSame(7000.0, (float) DB::table('sale_refunds')->where('id', $refundId)->value('amount'));
        $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertSee('Refund due: ₱3,000.00');
    }
}
