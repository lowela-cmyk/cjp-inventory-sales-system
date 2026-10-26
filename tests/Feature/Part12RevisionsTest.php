<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\PurchaseFlows;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Part 12 (October 26, 2026): settled sales take no payment, Cancel Sale lives in the Edit modal, one status per
 * column, Apply Changes, date range filters, alert cards and their View link, and decided account requests.
 */
class Part12RevisionsTest extends AuditTestCase
{
    use PurchaseFlows;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-26 09:00:00');
    }

    public function test_a_settled_sale_offers_no_payment_and_the_server_refuses_one(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 10000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 100, 'unit_price' => 50])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();

        $page = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $this->assertStringContainsString('data-open-payment-form="so-payment-history-'.$saleId.'"', $page);
        $this->assertStringContainsString('+ Add New Payment', $page);

        $this->pay($a, $saleId, 5000)->assertSessionHasNoErrors();

        $page = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-open-payment-form="so-payment-history-'.$saleId.'"', $page);
        $this->assertStringNotContainsString('+ Add New Payment', $page);
        $this->assertStringNotContainsString('data-payment-form-toggle', $page);

        $this->pay($a, $saleId, 1)->assertSessionHasErrors();
        $this->assertSame(1, DB::table('payments')->where('sale_id', $saleId)->count());
    }

    public function test_cancel_sale_is_in_the_edit_modal_and_receivables_show_one_status_per_column(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 10000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 100, 'unit_price' => 50])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $this->pay($a, $saleId, 1000)->assertSessionHasNoErrors();

        $page = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        // No Cancel button in the table row; the Edit modal footer has "Cancel Sale" (opens the reason form) and Close.
        $this->assertStringNotContainsString('data-modal-open="so-sale-cancel-'.$saleId.'"', $page);
        $this->assertStringContainsString('data-modal-swap="so-sale-cancel-'.$saleId.'">Cancel Sale</button>', $page);
        $this->assertMatchesRegularExpression('/form="sale-update-'.$saleId.'">Apply Changes<\/button>.*?Cancel Sale<\/button>\s*<button class="btn btn-pill btn-secondary" type="button" data-modal-close>Close<\/button>/s', $page);
        $this->assertStringContainsString('<th>Delivery Status</th>', $page);
        $this->assertStringContainsString('<th>Payment Status</th>', $page);

        // The cancel itself still needs a reason and keeps its audit trail.
        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.cancel', $saleId), [])
            ->assertSessionHasErrors('cancel_reason');
        $this->actingAs($a['sales'])->patch(route('sales-officer.sales.cancel', $saleId), ['cancel_reason' => 'wrong_customer'])
            ->assertSessionHasNoErrors();
        $this->assertSame('cancelled', DB::table('sales')->where('id', $saleId)->value('status'));
        $this->assertDatabaseHas('activity_logs', ['route_name' => 'sales-officer.sales.cancel', 'method' => 'PATCH', 'user_id' => $a['sales']->id]);
    }

    public function test_edit_modals_save_with_apply_changes_and_inventory_tables_have_one_value_per_column(): void
    {
        $a = $this->actors();
        $this->purchaseLine($a, 'PREM', 10000);

        $page = $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory'))->assertOk()->getContent();
        $this->assertStringContainsString('>Apply Changes</button>', $page);
        $this->assertStringNotContainsString('>Save Changes</button>', $page);
        foreach (['Inventory Status', 'Purchase Status', 'Payment Status'] as $header) {
            $this->assertStringContainsString('<th class="inventory-status-column">'.$header.'</th>', $page);
        }

        $stockOut = $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory.stock-out'))->assertOk()->getContent();
        $this->assertStringContainsString('<th>Customer Name</th>', $stockOut);
        $this->assertStringContainsString('<th>Company Name</th>', $stockOut);

        $stockIn = $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory.stock-in'))->assertOk()->getContent();
        // The long helper paragraph is gone from Edit Stock-In; rules show as field errors.
        $this->assertStringNotContainsString('Saving updates purchase', $stockIn);
    }

    public function test_date_filters_are_from_to_ranges_and_old_single_day_links_still_work(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 10000);
        foreach (['2026-10-10', '2026-10-15', '2026-10-20'] as $day) {
            $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 10, 'unit_price' => 50], ['sale_date' => $day])->assertSessionHasNoErrors();
        }
        $dates = fn (array $query): array => collect($this->actingAs($a['sales'])->get(route('sales-officer.sales', $query))->assertOk()->viewData('sales')->items())
            ->pluck('sale_date')->sort()->values()->all();

        $this->assertSame(['2026-10-10', '2026-10-15'], $dates(['date_from' => '2026-10-10', 'date_to' => '2026-10-16']));
        $this->assertSame(['2026-10-15', '2026-10-20'], $dates(['date_from' => '2026-10-15']));
        // A reversed range is put in order; an old ?date= link means that one day.
        $this->assertSame(['2026-10-10', '2026-10-15'], $dates(['date_from' => '2026-10-16', 'date_to' => '2026-10-10']));
        $this->assertSame(['2026-10-20'], $dates(['date' => '2026-10-20']));
        // Works together with search.
        $this->assertSame([], $dates(['date_from' => '2026-10-10', 'date_to' => '2026-10-16', 'search' => 'NO-SUCH-ORDER']));

        $page = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $this->assertStringContainsString('<input type="date" name="date_from"', $page);
        $this->assertStringContainsString('data-date-range-clear', $page);
        $this->assertStringNotContainsString('Transaction date (All)', $page);
    }

    public function test_alert_cards_hide_the_alert_id_and_view_opens_the_highlighted_record(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 10000);
        $purchase = DB::table('purchases')->first(['id', 'purchase_code']);
        $alert = DB::table('alerts')->where('reference_type', 'purchase')->first();

        $page = $this->actingAs($a['admin'])->get(route('admin.alerts'))->assertOk()->getContent();
        $this->assertStringNotContainsString($alert->alert_code, $page);
        $this->assertStringNotContainsString('Purchase / purchase #', $page);
        $this->assertStringContainsString('<span class="alert-title">'.$purchase->purchase_code.' Created</span>', $page);
        // The alert ID can still be searched.
        $this->actingAs($a['admin'])->get(route('admin.alerts', ['search' => $alert->alert_code]))->assertOk()->assertSee($purchase->purchase_code.' Created');

        $this->actingAs($a['admin'])->get(route('admin.alerts.view', $alert->id))
            ->assertRedirect(route('admin.inventory', ['search' => $purchase->purchase_code, 'highlight' => $purchase->purchase_code]));
        $this->assertDatabaseHas('alert_reads', ['alert_id' => $alert->id, 'user_id' => $a['admin']->id]);
        $this->actingAs($a['inventory'])->get(route('inventory-officer.alerts.view', $alert->id))
            ->assertRedirect(route('inventory-officer.inventory', ['search' => $purchase->purchase_code, 'highlight' => $purchase->purchase_code]));

        // A lift alert opens the Schedule tab on the lift (Dispatch and Admin).
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 5000]])->assertSessionHasNoErrors();
        $liftAlert = DB::table('alerts')->where('reference_type', 'haul')->first();
        $code = (string) DB::table('lifting_schedules')->value('schedule_code');
        $this->actingAs($a['dispatch'])->get(route('dispatch.alerts.view', $liftAlert->id))
            ->assertRedirect(route('dispatch.fuel-lifting', ['search' => $code, 'highlight' => $code]));
        $this->actingAs($a['dispatch'])->get(route('dispatch.fuel-lifting', ['search' => $code, 'highlight' => $code]))->assertOk()
            ->assertViewHas('scheduledRows', fn ($rows): bool => $rows->pluck('cells.0')->all() === [$code]);

        // Deleted record: a toast on the Alerts page, not an error.
        $this->actingAs($a['admin'])->delete(route('admin.fuel-lifting.hauls.destroy', $this->lastHaulId()))->assertSessionHasNoErrors();
        $this->actingAs($a['dispatch'])->get(route('dispatch.alerts.view', $liftAlert->id))
            ->assertRedirect(route('dispatch.alerts'))
            ->assertSessionHas('status', 'This record is no longer available.');

        // A role cannot open an alert type it does not see.
        $this->actingAs($a['sales'])->get(route('sales-officer.alerts.view', $alert->id))->assertNotFound();
    }

    public function test_decided_account_requests_lose_their_buttons_and_cannot_be_decided_again(): void
    {
        $a = $this->actors();
        $pending = User::factory()->create(['name' => 'Pending Person', 'role' => 'driver', 'status' => 'active', 'approval_status' => 'pending']);

        $html = fn (): string => $this->actingAs($a['admin'])->get(route('admin.account-requests'))->assertOk()->getContent();
        $this->assertSame(1, substr_count($html(), '>Approve</button>'));
        $this->assertStringContainsString('data-confirm-message="Reject the account request of Pending Person?', $html());

        $this->actingAs($a['admin'])->patch(route('admin.account-requests.update', $pending), ['approval_status' => 'approved'])
            ->assertRedirect(route('admin.account-requests'))->assertSessionHas('status');
        $this->assertSame(0, substr_count($html(), '>Approve</button>'));
        $this->assertSame(0, substr_count($html(), '>Reject</button>'));

        $this->actingAs($a['admin'])->patch(route('admin.account-requests.update', $pending), ['approval_status' => 'rejected'])
            ->assertSessionHasErrors(['approval_status' => 'This account request was already approved.']);
        $this->assertSame('approved', $pending->refresh()->approval_status);
    }

    private function pay(array $a, int $saleId, float $amount)
    {
        return $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->post(route('sales-officer.sales.payments.store', $saleId), [
            'idempotency_key' => (string) Str::uuid(), 'payment_sale_id' => $saleId, 'amount' => $amount, 'method' => 'cash_on_delivery', 'payment_date' => '2026-10-26',
        ]);
    }
}
