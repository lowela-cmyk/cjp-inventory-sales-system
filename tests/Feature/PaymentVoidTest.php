<?php

namespace Tests\Feature;

use App\Services\AIDataPreparationService;
use App\Services\DashboardService;
use App\Services\DashboardSummaryService;
use App\Services\PaymentVoidService;
use App\Services\StockOutFinancialService;
use App\Support\ActivePayments;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PurchaseFlows;
use Tests\Feature\Audit\AuditTestCase;

/**
 * A voided payment stays in the payments table and the payment history, records who voided it, when and why, and
 * is left out of every total, balance, status, latest-payment date and monthly collection figure.
 */
class PaymentVoidTest extends AuditTestCase
{
    use PurchaseFlows;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-28 09:00:00');
    }

    public function test_a_voided_payment_stays_in_the_history_but_stops_counting(): void
    {
        $a = $this->actors();
        $saleId = $this->order($a);
        $kept = $this->pay($a, $saleId, 1000, '2026-10-20');
        $voided = $this->pay($a, $saleId, 3000, '2026-10-27');
        $this->assertSame(['partially_paid', 'partially_paid'], $this->statuses($saleId));

        $this->actingAs($a['admin'])->patch(route('admin.sales.payments.void', [$saleId, $voided]), ['void_reason' => 'Bounced bank transfer'])
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.sales'));

        // The row is kept, with who, when and why; its amount is unchanged.
        $row = DB::table('payments')->find($voided);
        $this->assertSame([3000.0, $a['admin']->id, 'Bounced bank transfer', '2026-10-28 09:00:00'], [(float) $row->amount, (int) $row->voided_by, $row->void_reason, (string) $row->voided_at]);
        $this->assertSame(2, DB::table('payments')->where('sale_id', $saleId)->count());
        $this->assertSame([$kept], ActivePayments::query()->where('sale_id', $saleId)->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $this->assertTrue(DB::table('sale_change_logs')->where('sale_id', $saleId)->where('field', 'payments.'.$row->payment_code.'.voided')->where('changed_by', $a['admin']->id)->exists());

        // Status, balance and latest payment date come from the remaining 1,000 only.
        $this->assertSame(['partially_paid', 'partially_paid'], $this->statuses($saleId));
        $sale = $this->salesRow($a, $saleId);
        $this->assertSame(['1,000.00', '4,000.00', '2026-10-20'], [$sale['total_paid'], $sale['balance'], (string) $sale['latest_payment_date']]);

        // History: both rows, the voided one labelled and not numbered as an installment.
        $history = collect($sale['payments'])->keyBy('id');
        $this->assertSame(['Installment #1', 'Recorded'], [$history[$kept]['sequence'], $history[$kept]['status']]);
        $this->assertSame(['Voided', 'Voided', true], [$history[$voided]['sequence'], $history[$voided]['status'], $history[$voided]['voided']]);
        $this->assertStringContainsString('Bounced bank transfer', $history[$voided]['void_note']);
        $this->assertStringContainsString($a['admin']->name, $history[$voided]['void_note']);

        // Dashboard and report totals.
        $trend = app(DashboardService::class)->salesTrend([]);
        $this->assertSame([1000.0, 4000.0, ['paid' => 0, 'unpaid' => 0, 'partial' => 1]], [$trend['totals']['collected'], $trend['totals']['unpaid'], $trend['totals']['payment_status']]);
        $summary = app(DashboardSummaryService::class);
        Cache::flush();
        $this->assertSame([1000.0, 4000.0], [$summary->collectedRevenue(), $summary->outstandingReceivables()]);
        $this->assertSame(1000.0, $summary->expectedRevenue(2026)['collectedValues'][9]);
        $aiPeriod = new \ReflectionMethod(AIDataPreparationService::class, 'periodCollectedRevenue');
        $this->assertSame(1000.0, (float) $aiPeriod->invoke(app(AIDataPreparationService::class), ['2026-10-01', '2026-10-31']));
    }

    public function test_voiding_the_only_payment_makes_the_customer_unpaid_and_the_full_balance_payable_again(): void
    {
        $a = $this->actors();
        $saleId = $this->order($a);
        $paymentId = $this->pay($a, $saleId, 2000, '2026-10-27');

        app(PaymentVoidService::class)->void($paymentId, $a['admin']->id, 'Recorded on the wrong order', $saleId);

        $this->assertNotContains($this->statuses($saleId)[0], ['paid', 'partially_paid']);
        $this->assertNotSame('partial', $this->statuses($saleId)[1]);
        $sale = $this->salesRow($a, $saleId);
        $this->assertSame(['0.00', '5,000.00', null], [$sale['total_paid'], $sale['balance'], $sale['latest_payment_date']]);
        $this->assertSame(['paid' => 0, 'unpaid' => 1, 'partial' => 0], app(DashboardService::class)->salesTrend([])['totals']['payment_status']);
        Cache::flush();
        $this->assertSame(0.0, app(DashboardSummaryService::class)->expectedRevenue(2026)['collectedValues'][9]);

        // The voided 2,000 no longer reduces the balance, and an identical re-entry is not treated as a duplicate.
        $this->pay($a, $saleId, 2000, '2026-10-27');
        $this->pay($a, $saleId, 3000, '2026-10-28');
        $this->assertSame(['paid', 'paid'], $this->statuses($saleId));
        $this->assertSame(['paid' => 1, 'unpaid' => 0, 'partial' => 0], app(DashboardService::class)->salesTrend([])['totals']['payment_status']);
        // Only the payments that count are numbered.
        $this->assertSame(['Voided', 'Installment #1', 'Installment #2'], array_column($this->salesRow($a, $saleId)['payments'], 'sequence'));
    }

    public function test_the_stock_out_paid_figure_ignores_voided_payments(): void
    {
        $a = $this->actors();
        $saleId = $this->order($a);
        $this->deliverOrder($a, $saleId)->assertSessionHasNoErrors();
        $this->assertTrue(DB::table('stock_outs')->where('sale_id', $saleId)->exists(), 'The delivered order has a stock-out release.');
        $paymentId = $this->pay($a, $saleId, 2000, '2026-10-27');
        $paid = fn (): float => (float) app(StockOutFinancialService::class)->query()->where('sales.id', $saleId)->first()?->sale_paid;
        $this->assertSame(2000.0, $paid());

        app(PaymentVoidService::class)->void($paymentId, $a['admin']->id, 'Duplicate entry', $saleId);

        $this->assertSame(0.0, $paid());
    }

    public function test_only_an_admin_can_void_with_a_reason_once(): void
    {
        $a = $this->actors();
        $saleId = $this->order($a);
        $paymentId = $this->pay($a, $saleId, 2000, '2026-10-27');
        $route = route('admin.sales.payments.void', [$saleId, $paymentId]);

        $this->actingAs($a['sales'])->patch($route, ['void_reason' => 'Not allowed'])->assertForbidden();
        $this->actingAs($a['admin'])->patch($route, ['void_reason' => ''])->assertSessionHasErrors('void_reason');
        $this->actingAs($a['admin'])->patch(route('admin.sales.payments.void', [$saleId + 999, $paymentId]), ['void_reason' => 'Wrong sale'])->assertNotFound();
        $this->assertNull(DB::table('payments')->where('id', $paymentId)->value('voided_at'));

        $this->actingAs($a['admin'])->patch($route, ['void_reason' => 'Customer cheque bounced'])->assertSessionHasNoErrors();
        $this->actingAs($a['admin'])->patch($route, ['void_reason' => 'Second attempt'])->assertSessionHasErrors('void_reason');
        $this->assertSame('Customer cheque bounced', DB::table('payments')->where('id', $paymentId)->value('void_reason'));
        $this->assertSame(1, DB::table('sale_change_logs')->where('sale_id', $saleId)->where('field', 'like', 'payments.%.voided')->count());

        // Sales officers see the voided row but no Void button.
        $html = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $this->assertStringNotContainsString('payments/'.$paymentId.'/void', $html);
        $this->assertStringContainsString('Customer cheque bounced', $html);
    }

    private function order(array $a): int
    {
        $premium = $this->purchaseLine($a, 'PREM', 10000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 100, 'unit_price' => 50])->assertSessionHasNoErrors();

        return $this->lastSaleId();
    }

    private function pay(array $a, int $saleId, float $amount, string $date): int
    {
        $this->actingAs($a['sales'])->post(route('sales-officer.sales.payments.store', $saleId), [
            'idempotency_key' => (string) Str::uuid(), 'payment_sale_id' => $saleId, 'amount' => $amount, 'method' => 'cash_on_delivery', 'payment_date' => $date,
        ])->assertSessionHasNoErrors();

        return (int) DB::table('payments')->where('sale_id', $saleId)->max('id');
    }

    /** @return array{0: string, 1: string} */
    private function statuses(int $saleId): array
    {
        return [(string) DB::table('sales')->where('id', $saleId)->value('status'), (string) DB::table('receivables')->where('sale_id', $saleId)->value('status')];
    }

    /** @return array<string, mixed> */
    private function salesRow(array $a, int $saleId): array
    {
        return collect($this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->viewData('sales')->items())->firstWhere('id', $saleId);
    }
}
