<?php

namespace Tests\Feature\Audit;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;

/**
 * AUD-H02 (HIGH): a fully paid sale cannot be edited from either Edit modal. The Status select only
 * offers draft/confirmed/partially_paid/unpaid, so the browser submits "draft" and the server rejects
 * it ("cannot be changed to draft"). Changing only the Sales Order No. or due date always fails.
 */
#[Group('audit')]
class H02PaidSaleEditTest extends AuditTestCase
{
    public function test_sales_officer_can_change_the_order_number_of_a_paid_sale_with_the_form_defaults(): void
    {
        $a = $this->actors();
        $saleId = $this->paidSale($a);

        $html = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $status = $this->browserDefault($html, 'sale_status_'.$saleId);

        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))
            ->patch(route('sales-officer.sales.update', $saleId), $this->formPayload($a, $status))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sales', ['id' => $saleId, 'sales_order_number' => 'SO-AUD-EDITED']);
    }

    public function test_admin_can_change_the_order_number_of_a_paid_sale_with_the_form_defaults(): void
    {
        $a = $this->actors();
        $saleId = $this->paidSale($a);

        $html = $this->actingAs($a['admin'])->get(route('admin.sales'))->assertOk()->getContent();
        $status = $this->browserDefault($html, 'sale_status_'.$saleId);

        $this->actingAs($a['admin'])->from(route('admin.sales'))
            ->patch(route('admin.sales.update', $saleId), $this->formPayload($a, $status))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('sales', ['id' => $saleId, 'sales_order_number' => 'SO-AUD-EDITED']);
    }

    private function paidSale(array $a): int
    {
        $this->actingAs($a['sales'])->post(route('sales-officer.sales.store'), [
            'idempotency_key' => $this->uuid(), 'sales_order_number' => 'SO-AUD-1', 'customer_id' => $a['customer'], 'sale_date' => '2026-10-10',
            'items' => [['fuel_type_id' => $a['fuel'], 'quantity_liters' => 100, 'unit_price' => 50]],
            'amount_paid' => 5000, 'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment', 'status' => 'confirmed',
        ])->assertSessionHasNoErrors();

        $saleId = (int) DB::table('sales')->value('id');
        $this->assertSame('paid', DB::table('sales')->where('id', $saleId)->value('status'));

        return $saleId;
    }

    private function formPayload(array $a, ?string $status): array
    {
        return array_filter([
            'sales_order_number' => 'SO-AUD-EDITED', 'customer_id' => $a['customer'], 'sale_date' => '2026-10-10',
            'items' => [['fuel_type_id' => $a['fuel'], 'quantity_liters' => '100.00', 'unit_price' => '50.00']],
            'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment', 'status' => $status,
        ], fn ($value): bool => $value !== null);
    }

    /** What a browser submits for the Status <select>: the selected option, else the first; null when there is no select. */
    private function browserDefault(string $html, string $selectId): ?string
    {
        if (! preg_match('/<select[^>]*id="'.preg_quote($selectId, '/').'"[^>]*>(.*?)<\/select>/s', $html, $select)) {
            return null;
        }
        preg_match_all('/<option value="([^"]*)"([^>]*)>/', $select[1], $options, PREG_SET_ORDER);
        foreach ($options as $option) {
            if (str_contains($option[2], 'selected')) {
                return $option[1];
            }
        }

        return $options[0][1];
    }
}
