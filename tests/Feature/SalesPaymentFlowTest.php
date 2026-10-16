<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Sale -> partial initial payment -> second payment -> fully paid, for Sales Officer and Admin,
 * with field-level validation messages and a fully paid sale that can still be edited.
 */
class SalesPaymentFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string}>
     */
    public static function roles(): array
    {
        return ['sales officer' => ['sales_officer', 'sales-officer'], 'admin' => ['admin', 'admin']];
    }

    #[DataProvider('roles')]
    public function test_partial_then_second_payment_reaches_paid_and_every_total_updates(string $role, string $prefix): void
    {
        [$user, $customer, $fuel] = $this->records($role);

        // Record the sale with an initial partial payment, using every payment field.
        $this->actingAs($user)->post(route($prefix.'.sales.store'), [
            'idempotency_key' => (string) Str::uuid(), 'form_context' => 'record-sale', 'customer_id' => $customer, 'sale_date' => '2026-10-12',
            'items' => [['fuel_type_id' => $fuel, 'quantity_liters' => 100, 'unit_price' => 100]],
            'amount_paid' => 4000, 'payment_method' => 'cheque', 'payment_reference_number' => 'CHK-1001',
            'payment_date' => '2026-10-13', 'payment_remarks' => 'Down payment', 'payment_terms' => 'installment', 'due_date' => '2026-11-12', 'status' => 'confirmed',
        ])->assertSessionHasNoErrors()->assertRedirect(route($prefix.'.sales'));

        $saleId = (int) DB::table('sales')->value('id');
        $this->assertDatabaseHas('payments', ['sale_id' => $saleId, 'amount' => '4000.00', 'method' => 'cheque', 'reference_number' => 'CHK-1001', 'payment_date' => '2026-10-13', 'remarks' => 'Down payment', 'received_by' => $user->id]);
        $this->assertSame('partially_paid', DB::table('sales')->where('id', $saleId)->value('status'));

        $page = $this->actingAs($user)->get(route($prefix.'.sales'))->assertOk();
        $page->assertSee('PHP 4,000.00')->assertSee('PHP 6,000.00')->assertSee('+ Add New Payment')->assertSee('name="amount"', false);

        // Second payment: more than the balance is rejected on the Amount field.
        $this->actingAs($user)->from(route($prefix.'.sales'))->post(route($prefix.'.sales.payments.store', $saleId), [
            'idempotency_key' => (string) Str::uuid(), 'payment_sale_id' => $saleId, 'amount' => 6000.01, 'method' => 'cash_on_delivery', 'payment_date' => '2026-10-14',
        ])->assertSessionHasErrors(['amount' => 'Amount paid cannot exceed the remaining balance of PHP 6,000.00.']);

        // The error is shown under the Amount field inside the reopened Payment History modal.
        $this->actingAs($user)->withSession([
            'errors' => (new \Illuminate\Support\ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag(['amount' => ['Amount paid cannot exceed the remaining balance of PHP 6,000.00.']])),
            '_old_input' => ['payment_sale_id' => (string) $saleId, 'amount' => '6000.01'],
        ])->get(route($prefix.'.sales'))
            ->assertOk()
            ->assertSee('data-open-modal-on-load="so-payment-history-'.$saleId.'"', false)
            ->assertSee('<p class="field-error form-row-message">Amount paid cannot exceed the remaining balance of PHP 6,000.00.</p>', false);

        $this->actingAs($user)->post(route($prefix.'.sales.payments.store', $saleId), [
            'idempotency_key' => (string) Str::uuid(), 'payment_sale_id' => $saleId, 'amount' => 6000, 'method' => 'bank_transfer',
            'reference_number' => 'BNK-2002', 'payment_date' => '2026-10-14', 'remarks' => 'Balance settled',
        ])->assertSessionHasNoErrors()->assertSessionHas('payment_sale_id', $saleId);

        $this->assertSame(10000.0, (float) DB::table('payments')->where('sale_id', $saleId)->sum('amount'));
        $this->assertSame('paid', DB::table('sales')->where('id', $saleId)->value('status'));
        $this->assertSame('clear', DB::table('receivables')->where('sale_id', $saleId)->value('status'));

        $this->actingAs($user)->get(route($prefix.'.sales'))
            ->assertOk()
            ->assertSee('PHP 10,000.00')
            ->assertSee('BNK-2002')
            ->assertSee('Paid (set by payments)');

        // A fully paid sale stays editable (H02): change only the order number and due date.
        $this->actingAs($user)->from(route($prefix.'.sales'))->patch(route($prefix.'.sales.update', $saleId), [
            'edit_sale_id' => $saleId, 'sales_order_number' => 'SO-EDITED', 'customer_id' => $customer, 'sale_date' => '2026-10-12',
            'items' => [['fuel_type_id' => $fuel, 'quantity_liters' => '100.00', 'unit_price' => '100.00']],
            'payment_method' => 'cheque', 'payment_terms' => 'installment', 'due_date' => '2026-11-30',
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'sales_order_number' => 'SO-EDITED', 'status' => 'paid']);

        // No further payment is possible on a paid sale.
        $this->actingAs($user)->from(route($prefix.'.sales'))->post(route($prefix.'.sales.payments.store', $saleId), [
            'idempotency_key' => (string) Str::uuid(), 'amount' => 1, 'method' => 'cash_on_delivery', 'payment_date' => '2026-10-15',
        ])->assertSessionHasErrors('payment');
    }

    #[DataProvider('roles')]
    public function test_record_sales_payment_fields_validate_next_to_the_field(string $role, string $prefix): void
    {
        [$user, $customer, $fuel] = $this->records($role);
        $base = [
            'form_context' => 'record-sale', 'customer_id' => $customer, 'sale_date' => '2026-10-12',
            'items' => [['fuel_type_id' => $fuel, 'quantity_liters' => 10, 'unit_price' => 100]],
            'payment_terms' => 'installment', 'status' => 'confirmed',
        ];

        $this->actingAs($user)->from(route($prefix.'.sales'))->post(route($prefix.'.sales.store'), $base + [
            'idempotency_key' => (string) Str::uuid(), 'amount_paid' => 1000.01, 'payment_method' => 'cash_on_delivery',
        ])->assertSessionHasErrors('amount_paid');

        $this->actingAs($user)->from(route($prefix.'.sales'))->post(route($prefix.'.sales.store'), $base + [
            'idempotency_key' => (string) Str::uuid(), 'amount_paid' => 500, 'payment_method' => 'cheque',
        ])->assertSessionHasErrors('payment_reference_number');

        $this->actingAs($user)->from(route($prefix.'.sales'))->post(route($prefix.'.sales.store'), $base + [
            'idempotency_key' => (string) Str::uuid(), 'amount_paid' => 500, 'payment_method' => 'cash_on_delivery', 'payment_date' => '2026-10-11',
        ])->assertSessionHasErrors('payment_date');

        $this->assertSame(0, DB::table('sales')->count());

        $this->actingAs($user)->withSession([
            'errors' => (new \Illuminate\Support\ViewErrorBag)->put('default', new \Illuminate\Support\MessageBag(['payment_reference_number' => ['Enter the cheque or bank reference number for this payment.']])),
            '_old_input' => ['form_context' => 'record-sale', 'amount_paid' => '500', 'payment_method' => 'cheque'],
        ])->get(route($prefix.'.sales'))
            ->assertOk()
            ->assertSee('data-open-modal-on-load="so-sales-add"', false)
            ->assertSee('<p class="field-error form-row-message">Enter the cheque or bank reference number for this payment.</p>', false);
    }

    /**
     * @return array{0: User, 1: int, 2: int}
     */
    private function records(string $role): array
    {
        $user = User::factory()->create(['role' => $role, 'status' => 'active', 'approval_status' => 'approved']);
        $customer = DB::table('customers')->insertGetId(['customer_code' => 'CUS-PAY', 'name' => 'Payment Customer', 'company_name' => 'Payment Co.', 'payment_status' => 'clear', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $fuel = (int) DB::table('fuel_types')->where('code', 'DSL')->value('id');

        return [$user, $customer, $fuel];
    }
}
