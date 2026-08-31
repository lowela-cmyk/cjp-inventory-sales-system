<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminSalesReportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_time_sales_report_uses_real_records_without_double_counting(): void
    {
        $records = $this->baseRecords();
        $saleId = $this->sale($records, [
            'sale_code' => 'SLS-RPT-MULTI',
            'sale_date' => '2026-08-31',
            'items' => [
                ['fuel_type_id' => $records['dieselId'], 'quantity_liters' => 1000, 'unit_price' => 60, 'line_total' => 60000],
                ['fuel_type_id' => $records['e10Id'], 'quantity_liters' => 2000, 'unit_price' => 45, 'line_total' => 90000],
            ],
        ]);

        $this->payment($saleId, $records, ['payment_code' => 'PAY-RPT-A', 'amount' => 30000, 'method' => 'cash_on_delivery']);
        $this->payment($saleId, $records, ['payment_code' => 'PAY-RPT-B', 'amount' => 40000, 'method' => 'bank_transfer', 'reference_number' => 'BANK-RPT']);

        $this->actingAs($records['admin'])
            ->get(route('admin.reports'))
            ->assertOk()
            ->assertSee('Total Sales')
            ->assertSee('PHP 150,000.00')
            ->assertSee('Transactions')
            ->assertSee('1')
            ->assertSee('Quantity Sold')
            ->assertSee('3,000.00 L')
            ->assertSee('Payments Received')
            ->assertSee('PHP 70,000.00')
            ->assertSee('Outstanding Receivables')
            ->assertSee('PHP 80,000.00')
            ->assertSee('Partially Paid Sales')
            ->assertSee('Diesel Report')
            ->assertSee('E10 Report')
            ->assertSee('Banking Payments')
            ->assertSee('PAY-RPT-A')
            ->assertSee('PAY-RPT-B')
            ->assertDontSee('PHP 300,000.00');
    }

    public function test_report_filters_by_today_specific_date_range_month_and_year(): void
    {
        Carbon::setTestNow('2026-08-31 10:00:00');
        $records = $this->baseRecords();
        $this->sale($records, ['sale_code' => 'SLS-RPT-TODAY', 'sale_date' => '2026-08-31', 'line_total' => 10000]);
        $this->sale($records, ['sale_code' => 'SLS-RPT-AUG-15', 'sale_date' => '2026-08-15', 'line_total' => 20000]);
        $this->sale($records, ['sale_code' => 'SLS-RPT-JULY', 'sale_date' => '2026-07-20', 'line_total' => 30000]);
        $this->sale($records, ['sale_code' => 'SLS-RPT-OLDYEAR', 'sale_date' => '2025-12-31', 'line_total' => 40000]);

        $this->actingAs($records['admin'])
            ->get(route('admin.reports', ['period' => 'today']))
            ->assertOk()
            ->assertSee('SLS-RPT-TODAY')
            ->assertDontSee('SLS-RPT-AUG-15');

        $this->actingAs($records['admin'])
            ->get(route('admin.reports', ['period' => 'date', 'date' => '2026-08-15']))
            ->assertOk()
            ->assertSee('SLS-RPT-AUG-15')
            ->assertDontSee('SLS-RPT-TODAY');

        $this->actingAs($records['admin'])
            ->get(route('admin.reports', ['period' => 'range', 'start_date' => '2026-08-01', 'end_date' => '2026-08-31']))
            ->assertOk()
            ->assertSee('SLS-RPT-TODAY')
            ->assertSee('SLS-RPT-AUG-15')
            ->assertDontSee('SLS-RPT-JULY');

        $this->actingAs($records['admin'])
            ->get(route('admin.reports', ['period' => 'month', 'month' => '2026-07']))
            ->assertOk()
            ->assertSee('SLS-RPT-JULY')
            ->assertDontSee('SLS-RPT-AUG-15');

        $this->actingAs($records['admin'])
            ->get(route('admin.reports', ['period' => 'year', 'year' => '2025']))
            ->assertOk()
            ->assertSee('SLS-RPT-OLDYEAR')
            ->assertDontSee('SLS-RPT-TODAY');

        Carbon::setTestNow();
    }

    public function test_report_shows_payment_installment_and_receivable_status_totals(): void
    {
        $records = $this->baseRecords();
        $paidSaleId = $this->sale($records, ['sale_code' => 'SLS-RPT-PAID', 'line_total' => 50000]);
        $partialSaleId = $this->sale($records, ['sale_code' => 'SLS-RPT-PARTIAL', 'line_total' => 80000, 'payment_terms' => 'installment']);
        $unpaidSaleId = $this->sale($records, ['sale_code' => 'SLS-RPT-UNPAID', 'line_total' => 40000]);

        $scheduleId = DB::table('payment_schedules')->insertGetId([
            'sale_id' => $partialSaleId,
            'due_date' => '2026-09-30',
            'amount_due' => 80000,
            'status' => 'partial',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->payment($paidSaleId, $records, ['amount' => 50000, 'method' => 'advance_payment']);
        $this->payment($partialSaleId, $records, ['amount' => 25000, 'method' => 'cheque', 'reference_number' => 'CHK-RPT', 'payment_schedule_id' => $scheduleId]);

        $this->actingAs($records['admin'])
            ->get(route('admin.reports'))
            ->assertOk()
            ->assertSee('Fully Paid Sales')
            ->assertSee('Partially Paid Sales')
            ->assertSee('Unpaid Sales')
            ->assertSee('Advance Payments')
            ->assertSee('Cheque Payments')
            ->assertSee('SLS-RPT-PARTIAL')
            ->assertSee('PHP 55,000.00')
            ->assertSee('SLS-RPT-UNPAID')
            ->assertSee('PHP 40,000.00')
            ->assertSee('Unpaid');

        $this->assertDatabaseHas('sales', ['id' => $unpaidSaleId, 'status' => 'confirmed']);
    }

    public function test_overdue_receivable_report_only_uses_existing_due_dates(): void
    {
        Carbon::setTestNow('2026-08-31 10:00:00');
        $records = $this->baseRecords();
        $this->sale($records, ['sale_code' => 'SLS-RPT-OVERDUE', 'due_date' => '2026-01-15', 'line_total' => 20000]);
        $this->sale($records, ['sale_code' => 'SLS-RPT-NODUE', 'due_date' => null, 'line_total' => 30000]);

        $this->actingAs($records['admin'])
            ->get(route('admin.reports'))
            ->assertOk()
            ->assertSee('SLS-RPT-OVERDUE')
            ->assertSee('Overdue')
            ->assertSee('SLS-RPT-NODUE')
            ->assertSee('N/A');

        Carbon::setTestNow();
    }

    public function test_invalid_filters_empty_report_export_and_authorization(): void
    {
        $records = $this->baseRecords();

        $this->actingAs($records['admin'])
            ->get(route('admin.reports', ['period' => 'range', 'start_date' => '2026-09-01', 'end_date' => '2026-08-01']))
            ->assertSessionHasErrors('end_date');

        $this->actingAs($records['admin'])
            ->get(route('admin.reports', ['period' => 'date', 'date' => '2024-01-01']))
            ->assertOk()
            ->assertSee('No records found.')
            ->assertSee('PHP 0.00');

        $saleId = $this->sale($records, ['sale_code' => 'SLS-RPT-EXPORT', 'line_total' => 12000]);
        $this->payment($saleId, $records, ['amount' => 12000, 'method' => 'cash_on_delivery']);

        $this->actingAs($records['admin'])
            ->get(route('admin.reports.export'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertSee('CJP Southern Star OPC Sales Report')
            ->assertSee('SLS-RPT-EXPORT')
            ->assertSee('PAY-')
            ->assertSee('12000.00');

        foreach (['sales_officer', 'inventory_officer', 'dispatch_officer', 'driver'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);
            $this->actingAs($user)->get(route('admin.reports'))->assertForbidden();
            $this->actingAs($user)->get(route('admin.reports.export'))->assertForbidden();
        }
    }

    public function test_generating_report_is_read_only_for_inventory_and_payment_flows(): void
    {
        $records = $this->baseRecords();
        $this->sale($records, ['sale_code' => 'SLS-RPT-READONLY', 'line_total' => 22000]);
        $before = [
            'payments' => DB::table('payments')->count(),
            'inventory_movements' => DB::table('inventory_movements')->count(),
            'stock_outs' => DB::table('stock_outs')->count(),
            'deliveries' => DB::table('deliveries')->count(),
            'hauls' => DB::table('hauls')->count(),
        ];

        $this->actingAs($records['admin'])->get(route('admin.reports'))->assertOk();
        $this->actingAs($records['admin'])->get(route('admin.reports.export'))->assertOk();

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table.' changed while generating reports.');
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
            'customer_id' => $overrides['customer_id'] ?? $records['customerId'],
            'sale_date' => $overrides['sale_date'] ?? '2026-08-31',
            'payment_method' => $overrides['payment_method'] ?? 'cash_on_delivery',
            'payment_terms' => $overrides['payment_terms'] ?? 'cod',
            'status' => $overrides['status'] ?? 'confirmed',
            'created_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $items = $overrides['items'] ?? [[
            'fuel_type_id' => $records['dieselId'],
            'quantity_liters' => 1000,
            'unit_price' => ($overrides['line_total'] ?? 50000) / 1000,
            'line_total' => $overrides['line_total'] ?? 50000,
        ]];

        foreach ($items as $item) {
            DB::table('sale_items')->insert([
                'sale_id' => $saleId,
                'fuel_type_id' => $item['fuel_type_id'],
                'quantity_liters' => $item['quantity_liters'],
                'unit_price' => $item['unit_price'],
                'line_total' => $item['line_total'],
                'fulfilled_quantity_liters' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

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
            'payment_schedule_id' => $overrides['payment_schedule_id'] ?? null,
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

    /**
     * @return array<string, mixed>
     */
    private function baseRecords(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active']);
        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-RPT',
            'name' => 'Report Customer',
            'company_name' => 'Report Customer Co.',
            'payment_status' => 'clear',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $dieselId = DB::table('fuel_types')->insertGetId([
            'code' => 'DSL-RPT',
            'name' => 'Diesel Report',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $e10Id = DB::table('fuel_types')->insertGetId([
            'code' => 'E10-RPT',
            'name' => 'E10 Report',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('admin', 'salesOfficer', 'customerId', 'dieselId', 'e10Id');
    }
}
