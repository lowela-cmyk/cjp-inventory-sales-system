<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesOfficerPaymentRecordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_officer_records_partial_and_full_payments_from_authoritative_balance(): void
    {
        $records = $this->baseRecords();
        $saleId = $this->sale($records, ['sale_code' => 'SLS-PAYMENTS', 'line_total' => 100000]);

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.payments.store', $saleId), $this->paymentPayload([
                'amount' => 30000,
                'method' => 'cash_on_delivery',
            ]))
            ->assertRedirect(route('sales-officer.sales'));

        $this->assertDatabaseHas('sales', ['id' => $saleId, 'status' => 'partially_paid']);
        $this->assertDatabaseHas('receivables', ['sale_id' => $saleId, 'status' => 'partial']);

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.payments.store', $saleId), $this->paymentPayload([
                'amount' => 70000,
                'method' => 'advance_payment',
                'remarks' => 'Final collection',
            ]))
            ->assertRedirect(route('sales-officer.sales'));

        $this->assertSame(100000.0, $this->paidTotal($saleId));
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'status' => 'paid']);
        $this->assertDatabaseHas('receivables', ['sale_id' => $saleId, 'status' => 'clear']);
    }

    public function test_payment_history_and_receivable_table_use_real_payment_totals(): void
    {
        $records = $this->baseRecords();
        $saleId = $this->sale($records, ['sale_code' => 'SLS-HISTORY', 'line_total' => 60000]);

        DB::table('payments')->insert([
            'payment_code' => 'PAY-HISTORY',
            'sale_id' => $saleId,
            'payment_date' => '2026-08-31',
            'amount' => 25000,
            'method' => 'bank_transfer',
            'reference_number' => 'BANK-123',
            'remarks' => 'Initial bank payment',
            'received_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($records['salesOfficer'])
            ->get(route('sales-officer.sales'))
            ->assertOk()
            ->assertSee('SLS-HISTORY')
            ->assertSee('25,000.00')
            ->assertSee('35,000.00')
            ->assertSee('Partially Paid')
            ->assertSee('PAY-HISTORY')
            ->assertSee('BANK-123')
            ->assertSee($records['salesOfficer']->name);
    }

    public function test_cheque_and_banking_payments_require_reference_numbers(): void
    {
        $records = $this->baseRecords();
        $saleId = $this->sale($records);

        foreach (['cheque', 'bank_transfer'] as $method) {
            $this->actingAs($records['salesOfficer'])
                ->post(route('sales-officer.sales.payments.store', $saleId), $this->paymentPayload([
                    'method' => $method,
                    'reference_number' => null,
                ]))
                ->assertSessionHasErrors('reference_number');
        }

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.payments.store', $saleId), $this->paymentPayload([
                'method' => 'cheque',
                'reference_number' => 'CHK-001',
            ]))
            ->assertRedirect(route('sales-officer.sales'));

        $this->assertDatabaseHas('payments', [
            'sale_id' => $saleId,
            'method' => 'cheque',
            'reference_number' => 'CHK-001',
        ]);
    }

    public function test_payment_validation_rejects_invalid_amounts_overpayment_cancelled_sale_and_browser_totals(): void
    {
        $records = $this->baseRecords();
        $saleId = $this->sale($records, ['line_total' => 50000]);
        $cancelledSaleId = $this->sale($records, ['sale_code' => 'SLS-CANCELLED-PAY', 'status' => 'cancelled']);

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.payments.store', $saleId), $this->paymentPayload(['amount' => 0]))
            ->assertSessionHasErrors('amount');

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.payments.store', $saleId), $this->paymentPayload(['amount' => -10]))
            ->assertSessionHasErrors('amount');

        $this->actingAs($records['salesOfficer'])
            ->from(route('sales-officer.sales'))
            ->post(route('sales-officer.sales.payments.store', $saleId), $this->paymentPayload(['amount' => 50001]))
            ->assertRedirect(route('sales-officer.sales'))
            ->assertSessionHasErrors('payment');

        $this->actingAs($records['salesOfficer'])
            ->from(route('sales-officer.sales'))
            ->post(route('sales-officer.sales.payments.store', $cancelledSaleId), $this->paymentPayload())
            ->assertRedirect(route('sales-officer.sales'))
            ->assertSessionHasErrors('payment');

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.payments.store', $saleId), $this->paymentPayload([
                'sale_id' => $saleId,
                'customer_id' => $records['customerId'],
                'remaining_balance' => 1,
            ]))
            ->assertSessionHasErrors(['sale_id', 'customer_id', 'remaining_balance']);

        $this->assertSame(0, DB::table('payments')->count());
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'status' => 'confirmed']);
        $this->assertDatabaseHas('receivables', ['sale_id' => $saleId, 'status' => 'pending']);
    }

    public function test_duplicate_payment_submission_does_not_double_record_or_change_inventory(): void
    {
        $records = $this->baseRecords();
        $saleId = $this->sale($records, ['line_total' => 50000]);
        $payload = $this->paymentPayload([
            'idempotency_key' => (string) Str::uuid(),
            'amount' => 20000,
        ]);

        $beforeInventory = DB::table('inventory_movements')->count();

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.payments.store', $saleId), $payload)
            ->assertRedirect(route('sales-officer.sales'));

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.payments.store', $saleId), $payload)
            ->assertRedirect(route('sales-officer.sales'));

        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(20000.0, $this->paidTotal($saleId));
        $this->assertSame($beforeInventory, DB::table('inventory_movements')->count());
        $this->assertSame(0, DB::table('stock_outs')->count());
        $this->assertSame(0, DB::table('hauls')->count());
        $this->assertSame(0, DB::table('deliveries')->count());
    }

    public function test_sale_can_be_paid_through_multiple_installments_with_different_methods(): void
    {
        $records = $this->baseRecords();
        $saleId = $this->sale($records, [
            'sale_code' => 'SLS-INSTALLMENTS',
            'line_total' => 120000,
            'payment_terms' => 'installment',
        ]);

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.payments.store', $saleId), $this->paymentPayload([
                'amount' => 20000,
                'method' => 'cash_on_delivery',
            ]))
            ->assertRedirect(route('sales-officer.sales'));

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.payments.store', $saleId), $this->paymentPayload([
                'amount' => 30000,
                'method' => 'cheque',
                'reference_number' => 'CHK-INST-002',
            ]))
            ->assertRedirect(route('sales-officer.sales'));

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.payments.store', $saleId), $this->paymentPayload([
                'amount' => 70000,
                'method' => 'bank_transfer',
                'reference_number' => 'BANK-INST-003',
            ]))
            ->assertRedirect(route('sales-officer.sales'));

        $this->assertSame(1, DB::table('sales')->where('id', $saleId)->count());
        $this->assertSame(3, DB::table('payments')->where('sale_id', $saleId)->count());
        $this->assertSame(120000.0, $this->paidTotal($saleId));
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'status' => 'paid']);
        $this->assertDatabaseHas('receivables', ['sale_id' => $saleId, 'status' => 'clear']);

        $this->actingAs($records['salesOfficer'])
            ->get(route('sales-officer.sales'))
            ->assertOk()
            ->assertSee('Installment #1')
            ->assertSee('Installment #2')
            ->assertSee('Installment #3')
            ->assertSee('0.00');
    }

    public function test_selected_payment_schedule_tracks_partial_and_final_installments(): void
    {
        $records = $this->baseRecords();
        $saleId = $this->sale($records, [
            'line_total' => 60000,
            'payment_terms' => 'installment',
        ]);
        $firstScheduleId = $this->paymentSchedule($saleId, [
            'due_date' => '2026-09-15',
            'amount_due' => 30000,
        ]);
        $secondScheduleId = $this->paymentSchedule($saleId, [
            'due_date' => '2026-10-15',
            'amount_due' => 30000,
        ]);

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.payments.store', $saleId), $this->paymentPayload([
                'payment_schedule_id' => $firstScheduleId,
                'amount' => 10000,
            ]))
            ->assertRedirect(route('sales-officer.sales'));

        $this->assertDatabaseHas('payment_schedules', ['id' => $firstScheduleId, 'status' => 'partial']);
        $this->assertDatabaseHas('payment_schedules', ['id' => $secondScheduleId, 'status' => 'pending']);
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'status' => 'partially_paid']);

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.payments.store', $saleId), $this->paymentPayload([
                'payment_schedule_id' => $firstScheduleId,
                'amount' => 20000,
                'method' => 'advance_payment',
            ]))
            ->assertRedirect(route('sales-officer.sales'));

        $this->assertDatabaseHas('payment_schedules', ['id' => $firstScheduleId, 'status' => 'paid']);
        $this->assertSame(30000.0, $this->paidTotal($saleId));
    }

    public function test_installment_payment_cannot_exceed_selected_schedule_or_run_after_fully_paid(): void
    {
        $records = $this->baseRecords();
        $saleId = $this->sale($records, ['line_total' => 40000, 'payment_terms' => 'installment']);
        $scheduleId = $this->paymentSchedule($saleId, ['amount_due' => 15000]);

        $this->actingAs($records['salesOfficer'])
            ->from(route('sales-officer.sales'))
            ->post(route('sales-officer.sales.payments.store', $saleId), $this->paymentPayload([
                'payment_schedule_id' => $scheduleId,
                'amount' => 16000,
            ]))
            ->assertRedirect(route('sales-officer.sales'))
            ->assertSessionHasErrors('payment');

        $this->assertSame(0, DB::table('payments')->count());
        $this->assertDatabaseHas('payment_schedules', ['id' => $scheduleId, 'status' => 'pending']);

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.payments.store', $saleId), $this->paymentPayload([
                'amount' => 40000,
            ]))
            ->assertRedirect(route('sales-officer.sales'));

        $this->actingAs($records['salesOfficer'])
            ->from(route('sales-officer.sales'))
            ->post(route('sales-officer.sales.payments.store', $saleId), $this->paymentPayload([
                'amount' => 1,
            ]))
            ->assertRedirect(route('sales-officer.sales'))
            ->assertSessionHasErrors('payment');

        $this->assertSame(1, DB::table('payments')->count());
        $this->assertSame(40000.0, $this->paidTotal($saleId));
    }

    public function test_installment_schedule_must_belong_to_selected_sale(): void
    {
        $records = $this->baseRecords();
        $saleId = $this->sale($records, ['line_total' => 50000, 'payment_terms' => 'installment']);
        $otherSaleId = $this->sale($records, [
            'sale_code' => 'SLS-OTHER-SCHEDULE',
            'line_total' => 50000,
            'payment_terms' => 'installment',
        ]);
        $otherScheduleId = $this->paymentSchedule($otherSaleId, ['amount_due' => 25000]);

        $this->actingAs($records['salesOfficer'])
            ->from(route('sales-officer.sales'))
            ->post(route('sales-officer.sales.payments.store', $saleId), $this->paymentPayload([
                'payment_schedule_id' => $otherScheduleId,
                'amount' => 10000,
            ]))
            ->assertRedirect(route('sales-officer.sales'))
            ->assertSessionHasErrors('payment');

        $this->assertSame(0, DB::table('payments')->count());
        $this->assertDatabaseHas('sales', ['id' => $saleId, 'status' => 'confirmed']);
        $this->assertDatabaseHas('receivables', ['sale_id' => $saleId, 'status' => 'pending']);
    }

    public function test_only_sales_officer_role_can_record_payments_in_existing_sales_workflow(): void
    {
        $records = $this->baseRecords();
        $saleId = $this->sale($records);

        foreach (['admin', 'inventory_officer', 'dispatch_officer', 'driver'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);

            $this->actingAs($user)
                ->post(route('sales-officer.sales.payments.store', $saleId), $this->paymentPayload())
                ->assertForbidden();
        }

        $this->assertSame(0, DB::table('payments')->count());
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function paymentPayload(array $overrides = []): array
    {
        $payload = [
            'idempotency_key' => (string) Str::uuid(),
            'payment_date' => '2026-08-31',
            'amount' => 10000,
            'method' => 'cash_on_delivery',
            'reference_number' => null,
            'remarks' => 'Customer payment',
        ];

        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($payload[$key]);
            } else {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $records
     * @param array<string, mixed> $overrides
     */
    private function sale(array $records, array $overrides = []): int
    {
        $saleId = DB::table('sales')->insertGetId(array_merge([
            'sale_code' => 'SLS-'.Str::upper(Str::random(8)),
            'customer_id' => $records['customerId'],
            'sale_date' => '2026-08-30',
            'payment_method' => 'cash_on_delivery',
            'payment_terms' => 'cod',
            'status' => 'confirmed',
            'created_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], collect($overrides)->only(['sale_code', 'status', 'payment_method', 'payment_terms'])->all()));

        DB::table('sale_items')->insert([
            'sale_id' => $saleId,
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_liters' => 1000,
            'unit_price' => ($overrides['line_total'] ?? 50000) / 1000,
            'line_total' => $overrides['line_total'] ?? 50000,
            'fulfilled_quantity_liters' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('receivables')->insert([
            'sale_id' => $saleId,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $saleId;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function paymentSchedule(int $saleId, array $overrides = []): int
    {
        return DB::table('payment_schedules')->insertGetId(array_merge([
            'sale_id' => $saleId,
            'due_date' => '2026-09-30',
            'amount_due' => 10000,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function paidTotal(int $saleId): float
    {
        return round((float) DB::table('payments')->where('sale_id', $saleId)->sum('amount'), 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseRecords(): array
    {
        $salesOfficer = User::factory()->create([
            'name' => 'Payment Recorder',
            'role' => 'sales_officer',
            'status' => 'active',
        ]);

        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-PAY',
            'name' => 'Payment Customer',
            'company_name' => 'Payment Customer Co.',
            'payment_status' => 'clear',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fuelTypeId = DB::table('fuel_types')->insertGetId([
            'code' => 'DSL-PAY',
            'name' => 'Diesel Payment',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('salesOfficer', 'customerId', 'fuelTypeId');
    }
}
