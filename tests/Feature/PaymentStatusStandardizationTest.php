<?php

namespace Tests\Feature;

use App\Services\DashboardService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PurchaseFlows;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Standardize Payment Status System-Wide:
 * Only Paid, Unpaid, and Partially Paid are used across Sales, Receivables, Customers, Purchases,
 * Dashboard, Filters, Alerts, and Exports.
 */
class PaymentStatusStandardizationTest extends AuditTestCase
{
    use PurchaseFlows;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-20 09:00:00');
    }

    public function test_sales_receivables_customers_and_dashboard_use_only_paid_unpaid_and_partially_paid(): void
    {
        $a = $this->actors();
        $diesel = $this->purchaseLine($a, 'DSL', 20000);

        $custFull = DB::table('customers')->insertGetId(['customer_code' => 'CUS-FULL', 'name' => 'Full Payer', 'company_name' => 'Full Co', 'payment_status' => 'paid', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $custPart = DB::table('customers')->insertGetId(['customer_code' => 'CUS-PART', 'name' => 'Partial Payer', 'company_name' => 'Partial Co', 'payment_status' => 'paid', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $custZero = DB::table('customers')->insertGetId(['customer_code' => 'CUS-ZERO', 'name' => 'Zero Payer', 'company_name' => 'Zero Co', 'payment_status' => 'paid', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        // 1. Full payment (1,000 L * 50 = 50,000)
        $this->recordOrder($a, ['purchase_item_id' => $diesel, 'quantity_liters' => 1000, 'unit_price' => 50], [
            'customer_id' => $custFull, 'sales_order_number' => 'SO-STD-FULL', 'amount_paid' => 50000, 'due_date' => '2026-11-20',
        ])->assertSessionHasNoErrors();
        $fullSaleId = $this->lastSaleId();

        // 2. Partial payment (20,000 of 50,000) with past due date (must still be Partially Paid, never Overdue)
        $this->recordOrder($a, ['purchase_item_id' => $diesel, 'quantity_liters' => 1000, 'unit_price' => 50], [
            'customer_id' => $custPart, 'sales_order_number' => 'SO-STD-PART', 'amount_paid' => 20000, 'sale_date' => '2026-10-01', 'due_date' => '2026-10-05',
        ])->assertSessionHasNoErrors();
        $partSaleId = $this->lastSaleId();

        // 3. Zero payment with past due date (must still be Unpaid, never Overdue/Pending)
        $this->recordOrder($a, ['purchase_item_id' => $diesel, 'quantity_liters' => 1000, 'unit_price' => 50], [
            'customer_id' => $custZero, 'sales_order_number' => 'SO-STD-ZERO', 'amount_paid' => 0, 'sale_date' => '2026-10-01', 'due_date' => '2026-10-05',
        ])->assertSessionHasNoErrors();
        $zeroSaleId = $this->lastSaleId();

        $this->assertSame('paid', DB::table('receivables')->where('sale_id', $fullSaleId)->value('status'));
        $this->assertSame('partially_paid', DB::table('receivables')->where('sale_id', $partSaleId)->value('status'));
        $this->assertSame('unpaid', DB::table('receivables')->where('sale_id', $zeroSaleId)->value('status'));

        // Verify both Sales Officer and Admin views & filters
        foreach ([$a['sales'], $a['admin']] as $actor) {
            $prefix = $actor->role === 'admin' ? 'admin' : 'sales-officer';
            $salesHtml = $this->actingAs($actor)->get(route($prefix.'.sales'))->assertOk()->getContent();
            $this->assertStringNotContainsString('>Settled<', $salesHtml);
            $this->assertStringNotContainsString('>Fully Paid<', $salesHtml);
            $this->assertStringNotContainsString('>Overdue<', $salesHtml);

            $this->actingAs($actor)->get(route($prefix.'.sales', ['status' => 'paid']))->assertOk()->assertSee('SO-STD-FULL')->assertDontSee('SO-STD-PART');
            $this->actingAs($actor)->get(route($prefix.'.sales', ['status' => 'partially_paid']))->assertOk()->assertSee('SO-STD-PART')->assertDontSee('SO-STD-FULL');
            $this->actingAs($actor)->get(route($prefix.'.sales', ['status' => 'unpaid']))->assertOk()->assertSee('SO-STD-ZERO')->assertDontSee('SO-STD-FULL');

            $custHtml = $this->actingAs($actor)->get(route($prefix.'.sales.customers'))->assertOk()->getContent();
            $this->assertStringNotContainsString('status-tone-success">Clear<', $custHtml);
            $this->assertStringNotContainsString('status-tone-pending">Pending<', $custHtml);
            $this->assertStringNotContainsString('status-tone-danger">Overdue<', $custHtml);
            $this->assertStringNotContainsString('<option value="clear"', $custHtml);
            $this->assertMatchesRegularExpression('/name="payment_status".*?<option value="paid".*?<option value="unpaid".*?<option value="partially_paid"/s', $custHtml);

            $this->actingAs($actor)->get(route($prefix.'.sales.customers', ['payment_status' => 'paid']))->assertOk()
                ->assertViewHas('customers', function ($rows): bool {
                    $companies = collect($rows->items())->pluck('company_name')->all();

                    return in_array('Full Co', $companies, true) && ! in_array('Partial Co', $companies, true) && ! in_array('Zero Co', $companies, true);
                });
            $this->actingAs($actor)->get(route($prefix.'.sales.customers', ['payment_status' => 'partially_paid']))->assertOk()
                ->assertViewHas('customers', fn ($rows) => collect($rows->items())->pluck('company_name')->all() === ['Partial Co']);
            $this->actingAs($actor)->get(route($prefix.'.sales.customers', ['payment_status' => 'unpaid']))->assertOk()
                ->assertViewHas('customers', fn ($rows) => collect($rows->items())->pluck('company_name')->all() === ['Zero Co']);
        }

        $trend = app(DashboardService::class)->salesTrend([]);
        $this->assertSame(['Paid', 'Unpaid', 'Partially Paid'], $trend['paymentChart']['labels']);
        $this->assertSame(['paid' => 1, 'unpaid' => 1, 'partial' => 1], $trend['totals']['payment_status']);
    }

    public function test_voided_cancelled_refunded_and_overpaid_transactions_compute_correct_statuses(): void
    {
        $a = $this->actors();
        $diesel = $this->purchaseLine($a, 'DSL', 20000);

        // Overpayment via UI/POST is rejected
        $this->recordOrder($a, ['purchase_item_id' => $diesel, 'quantity_liters' => 1000, 'unit_price' => 50], [
            'amount_paid' => 55000,
        ])->assertSessionHasErrors('amount_paid');

        // Create a fully paid sale, then void its payment -> reverts to Unpaid
        $this->recordOrder($a, ['purchase_item_id' => $diesel, 'quantity_liters' => 1000, 'unit_price' => 50], [
            'sales_order_number' => 'SO-VOID-TEST', 'amount_paid' => 50000,
        ])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $paymentId = (int) DB::table('payments')->where('sale_id', $saleId)->value('id');
        $this->assertSame('paid', DB::table('receivables')->where('sale_id', $saleId)->value('status'));

        $this->actingAs($a['admin'])->patch(route('admin.sales.payments.void', [$saleId, $paymentId]), ['void_reason' => 'Duplicate entry'])->assertSessionHasNoErrors();
        $this->assertSame('unpaid', DB::table('receivables')->where('sale_id', $saleId)->value('status'));

        // Re-pay in full, then cancel & refund -> cancelled sale is never classified as Paid
        $this->actingAs($a['sales'])->post(route('sales-officer.sales.payments.store', $saleId), [
            'idempotency_key' => (string) Str::uuid(), 'payment_sale_id' => $saleId, 'amount' => 50000, 'method' => 'cash_on_delivery', 'payment_date' => '2026-11-20',
        ])->assertSessionHasNoErrors();
        $this->assertSame('paid', DB::table('receivables')->where('sale_id', $saleId)->value('status'));

        $this->actingAs($a['sales'])->patch(route('sales-officer.sales.cancel', $saleId), ['cancel_reason' => 'customer_cancelled'])->assertSessionHasNoErrors();
        $this->assertSame('unpaid', DB::table('receivables')->where('sale_id', $saleId)->value('status'));

        // Record refund; cancelled sale remains cancelled and never shows Paid
        $this->actingAs($a['sales'])->post(route('sales-officer.sales.refunds.store', $saleId), [
            'idempotency_key' => (string) Str::uuid(), 'refund_sale_id' => $saleId, 'amount' => 50000, 'method' => 'cash_on_delivery', 'refund_date' => '2026-11-20',
        ])->assertSessionHasNoErrors();
        $this->assertSame('unpaid', DB::table('receivables')->where('sale_id', $saleId)->value('status'));
        $this->actingAs($a['sales'])->get(route('sales-officer.sales', ['status' => 'paid']))->assertOk()->assertDontSee('SO-VOID-TEST');
    }

    public function test_purchases_use_paid_unpaid_and_partially_paid_across_roles(): void
    {
        $a = $this->actors();

        foreach ([$a['inventory'], $a['admin']] as $actor) {
            $prefix = $actor->role === 'admin' ? 'admin' : 'inventory-officer';
            $html = $this->actingAs($actor)->get(route($prefix.'.inventory'))->assertOk()->getContent();
            $this->assertStringContainsString('>Partially Paid<', $html);
            $this->assertStringNotContainsString('>Partial<', $html);

            $this->actingAs($actor)->post(route($prefix.'.inventory.purchases.store'), [
                'idempotency_key' => (string) Str::uuid(),
                'purchase_date' => '2026-11-20',
                'depot_id' => $a['depot'],
                'fuel_type_id' => $a['fuel'],
                'quantity_ordered_liters' => 40000,
                'unit_cost' => 50,
                'payment_status' => 'partially_paid',
                'payment_mode' => 'cash',
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(2, DB::table('purchases')->where('payment_status', 'partially_paid')->count());
    }
}
