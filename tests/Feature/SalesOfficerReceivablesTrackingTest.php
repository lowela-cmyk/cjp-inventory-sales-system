<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesOfficerReceivablesTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_receivables_page_tracks_unpaid_partial_installment_and_settled_sales(): void
    {
        $records = $this->baseRecords();
        $unpaidSaleId = $this->sale($records, [
            'sale_code' => 'SLS-UNPAID-REC',
            'line_total' => 50000,
            'due_date' => '2026-09-15',
        ]);
        $partialSaleId = $this->sale($records, [
            'sale_code' => 'SLS-PARTIAL-REC',
            'line_total' => 80000,
            'due_date' => '2026-09-20',
        ]);
        $installmentSaleId = $this->sale($records, [
            'sale_code' => 'SLS-INSTALL-REC',
            'line_total' => 120000,
            'payment_terms' => 'installment',
            'due_date' => '2026-10-01',
        ]);
        $settledSaleId = $this->sale($records, [
            'sale_code' => 'SLS-SETTLED-REC',
            'line_total' => 30000,
            'due_date' => '2026-09-01',
        ]);

        $this->payment($partialSaleId, $records, ['payment_code' => 'PAY-PARTIAL-REC', 'amount' => 25000]);
        $this->payment($installmentSaleId, $records, ['payment_code' => 'PAY-INSTALL-ONE', 'amount' => 20000]);
        $this->payment($installmentSaleId, $records, ['payment_code' => 'PAY-INSTALL-TWO', 'amount' => 30000]);
        $this->payment($settledSaleId, $records, ['payment_code' => 'PAY-SETTLED-REC', 'amount' => 30000]);

        $this->actingAs($records['salesOfficer'])
            ->get(route('sales-officer.sales'))
            ->assertOk()
            ->assertSee('SLS-UNPAID-REC')
            ->assertSee('50,000.00')
            ->assertSee('Unpaid')
            ->assertSee('SLS-PARTIAL-REC')
            ->assertSee('25,000.00')
            ->assertSee('55,000.00')
            ->assertSee('Partially Paid')
            ->assertSee('SLS-INSTALL-REC')
            ->assertSee('50,000.00')
            ->assertSee('70,000.00')
            ->assertSee('Installment #2')
            ->assertSee('SLS-SETTLED-REC')
            ->assertSee('Settled')
            ->assertSee('0.00');

        $this->assertSame(0.0, $this->negativeBalanceFor($unpaidSaleId));
        $this->assertSame(0.0, $this->negativeBalanceFor($partialSaleId));
    }

    public function test_overdue_receivables_are_based_only_on_existing_due_dates(): void
    {
        $records = $this->baseRecords();
        $this->sale($records, [
            'sale_code' => 'SLS-OVERDUE-REC',
            'line_total' => 45000,
            'due_date' => '2026-01-01',
        ]);
        $this->sale($records, [
            'sale_code' => 'SLS-NO-DUE-REC',
            'line_total' => 45000,
            'due_date' => null,
        ]);

        $this->actingAs($records['salesOfficer'])
            ->get(route('sales-officer.sales'))
            ->assertOk()
            ->assertSee('SLS-OVERDUE-REC')
            ->assertSee('Overdue')
            ->assertSee('SLS-NO-DUE-REC')
            ->assertSee('N/A');
    }

    public function test_customer_details_show_server_calculated_total_outstanding(): void
    {
        $records = $this->baseRecords();
        $firstSaleId = $this->sale($records, ['line_total' => 100000]);
        $secondSaleId = $this->sale($records, ['sale_code' => 'SLS-CUSTOMER-TWO', 'line_total' => 50000]);

        $this->payment($firstSaleId, $records, ['amount' => 35000]);
        $this->payment($secondSaleId, $records, ['amount' => 50000]);

        $this->actingAs($records['salesOfficer'])
            ->get(route('sales-officer.sales.customers'))
            ->assertOk()
            ->assertSee('Outstanding Receivables')
            ->assertSee('PHP 65,000.00');
    }

    public function test_receivables_search_uses_sale_customer_status_and_payment_method(): void
    {
        $records = $this->baseRecords();
        $this->sale($records, [
            'sale_code' => 'SLS-BANK-REC',
            'line_total' => 40000,
            'payment_method' => 'bank_transfer',
        ]);
        $this->sale($records, [
            'sale_code' => 'SLS-CHEQUE-REC',
            'line_total' => 40000,
            'payment_method' => 'cheque',
        ]);

        $this->actingAs($records['salesOfficer'])
            ->get(route('sales-officer.sales', ['search' => 'bank_transfer']))
            ->assertOk()
            ->assertSee('SLS-BANK-REC')
            ->assertDontSee('SLS-CHEQUE-REC');
    }

    public function test_payment_recording_updates_receivable_tracking_without_inventory_effects(): void
    {
        $records = $this->baseRecords();
        $saleId = $this->sale($records, ['sale_code' => 'SLS-TRACK-PAY', 'line_total' => 60000]);
        $beforeInventory = DB::table('inventory_movements')->count();

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.payments.store', $saleId), [
                'idempotency_key' => (string) Str::uuid(),
                'payment_date' => '2026-08-31',
                'amount' => 60000,
                'method' => 'cash_on_delivery',
            ])
            ->assertRedirect(route('sales-officer.sales'));

        $this->assertDatabaseHas('sales', ['id' => $saleId, 'status' => 'paid']);
        $this->assertDatabaseHas('receivables', ['sale_id' => $saleId, 'status' => 'clear']);
        $this->assertSame($beforeInventory, DB::table('inventory_movements')->count());
        $this->assertSame(0, DB::table('stock_outs')->count());
        $this->assertSame(0, DB::table('hauls')->count());
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('deliveries'));

        $this->actingAs($records['salesOfficer'])
            ->get(route('sales-officer.sales'))
            ->assertOk()
            ->assertSee('SLS-TRACK-PAY')
            ->assertSee('60,000.00')
            ->assertSee('0.00')
            ->assertSee('Settled');
    }

    public function test_admin_can_monitor_receivables_but_other_roles_cannot_access_sales_financial_pages(): void
    {
        $records = $this->baseRecords();
        $this->sale($records, ['sale_code' => 'SLS-ADMIN-REC', 'line_total' => 72000]);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin)
            ->get(route('admin.sales'))
            ->assertOk()
            ->assertSee('SLS-ADMIN-REC')
            ->assertSee('72,000.00')
            ->assertSee('Unpaid');

        foreach (['inventory_officer', 'dispatch_officer', 'driver'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);

            $this->actingAs($user)
                ->get(route('sales-officer.sales'))
                ->assertForbidden();

            $this->actingAs($user)
                ->get(route('admin.sales'))
                ->assertForbidden();
        }
    }

    /**
     * @param array<string, mixed> $records
     * @param array<string, mixed> $overrides
     */
    private function sale(array $records, array $overrides = []): int
    {
        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => $overrides['sale_code'] ?? 'SLS-'.Str::upper(Str::random(8)),
            'customer_id' => $records['customerId'],
            'sale_date' => $overrides['sale_date'] ?? '2026-08-30',
            'payment_method' => $overrides['payment_method'] ?? 'cash_on_delivery',
            'payment_terms' => $overrides['payment_terms'] ?? 'cod',
            'status' => $overrides['status'] ?? 'confirmed',
            'created_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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
            'due_date' => array_key_exists('due_date', $overrides) ? $overrides['due_date'] : '2026-09-30',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $saleId;
    }

    /**
     * @param array<string, mixed> $records
     * @param array<string, mixed> $overrides
     */
    private function payment(int $saleId, array $records, array $overrides = []): void
    {
        DB::table('payments')->insert([
            'payment_code' => $overrides['payment_code'] ?? 'PAY-'.Str::upper(Str::random(8)),
            'sale_id' => $saleId,
            'payment_date' => $overrides['payment_date'] ?? '2026-08-31',
            'amount' => $overrides['amount'] ?? 10000,
            'method' => $overrides['method'] ?? 'cash_on_delivery',
            'reference_number' => $overrides['reference_number'] ?? null,
            'remarks' => $overrides['remarks'] ?? null,
            'received_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function negativeBalanceFor(int $saleId): float
    {
        $saleTotal = (float) DB::table('sale_items')->where('sale_id', $saleId)->sum('line_total');
        $paid = (float) DB::table('payments')->where('sale_id', $saleId)->sum('amount');

        return min(0, round($saleTotal - $paid, 2));
    }

    /**
     * @return array<string, mixed>
     */
    private function baseRecords(): array
    {
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active']);
        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-REC',
            'name' => 'Receivable Customer',
            'company_name' => 'Receivable Customer Co.',
            'payment_status' => 'clear',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $fuelTypeId = DB::table('fuel_types')->insertGetId([
            'code' => 'DSL-REC',
            'name' => 'Diesel Receivable',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('salesOfficer', 'customerId', 'fuelTypeId');
    }
}
