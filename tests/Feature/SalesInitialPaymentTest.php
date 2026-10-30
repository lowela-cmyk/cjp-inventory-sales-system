<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesInitialPaymentTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_form_records_unpaid_partial_and_full_sales_through_existing_payments(): void
    {
        $records = $this->baseRecords();

        $cases = [
            ['number' => 'SO-INITIAL-UNPAID', 'amount' => '0.00', 'sale_status' => 'confirmed', 'receivable_status' => 'pending'],
            ['number' => 'SO-INITIAL-PARTIAL', 'amount' => '2500.00', 'sale_status' => 'partially_paid', 'receivable_status' => 'partial'],
            ['number' => 'SO-INITIAL-FULL', 'amount' => '10250.00', 'sale_status' => 'paid', 'receivable_status' => 'clear'],
        ];

        foreach ($cases as $case) {
            $this->actingAs($records['salesOfficer'])
                ->post(route('sales-officer.sales.store'), $this->salePayload($records, [
                    'sales_order_number' => $case['number'],
                    'amount_paid' => $case['amount'],
                ]))
                ->assertRedirect(route('sales-officer.sales'));

            $sale = DB::table('sales')->where('sales_order_number', $case['number'])->first();

            $this->assertNotNull($sale);
            $this->assertMatchesRegularExpression('/^SLS-(PREM|UNL|DSL|F1)-\d{3,}$/', $sale->sale_code);
            $this->assertSame($case['sale_status'], $sale->status);
            $this->assertSame('pending', $sale->delivery_status);
            $this->assertDatabaseHas('sale_items', [
                'sale_id' => $sale->id,
                'quantity_liters' => '1000.00',
                'unit_price' => '10.25',
                'line_total' => '10250.00',
            ]);
            $this->assertDatabaseHas('receivables', [
                'sale_id' => $sale->id,
                'status' => $case['receivable_status'],
            ]);
            $this->assertSame(0, DB::table('stock_outs')->where('sale_id', $sale->id)->count());

            $payments = DB::table('payments')->where('sale_id', $sale->id)->get();
            if ((float) $case['amount'] === 0.0) {
                $this->assertCount(0, $payments);
            } else {
                $this->assertCount(1, $payments);
                $this->assertSame((float) $case['amount'], (float) $payments->first()->amount);
                $this->assertSame('cash_on_delivery', $payments->first()->method);
                $this->assertMatchesRegularExpression('/^PAY-\d{6}-[A-Z0-9]{5}$/', $payments->first()->payment_code);
            }
        }

        $this->assertSame(3, DB::table('sales')->pluck('sale_code')->unique()->count());
    }

    public function test_create_form_rejects_negative_payment_and_server_calculated_overpayment(): void
    {
        $records = $this->baseRecords();

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.store'), $this->salePayload($records, [
                'sales_order_number' => 'SO-NEGATIVE',
                'amount_paid' => '-0.01',
            ]))
            ->assertSessionHasErrors('amount_paid');

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.store'), $this->salePayload($records, [
                'sales_order_number' => 'SO-OVERPAY',
                'amount_paid' => '10250.01',
            ]))
            ->assertSessionHasErrors('amount_paid');

        $this->assertSame(0, DB::table('sales')->count());
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0, DB::table('stock_outs')->count());
    }

    public function test_sales_and_admin_create_forms_show_payment_summary_without_restoring_order_id_input(): void
    {
        $records = $this->baseRecords();

        foreach ([
            [$records['salesOfficer'], route('sales-officer.sales')],
            [$records['admin'], route('admin.sales')],
        ] as [$user, $route]) {
            $this->actingAs($user)
                ->get($route)
                ->assertOk()
                ->assertSee('Amount Payment')
                // Part 9 mockup: the computed Payment Status replaces the old total / balance preview in Record Sales.
                ->assertSee('data-sales-payment-status-preview', false)
                ->assertDontSee('data-sales-balance-preview', false)
                ->assertSee('name="amount_paid"', false)
                ->assertSee('name="payment_method"', false)
                ->assertSee('name="sales_order_number"', false)
                ->assertDontSee('name="sale_code"', false);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function salePayload(array $records, array $overrides = []): array
    {
        return array_merge([
            'idempotency_key' => (string) Str::uuid(),
            'sales_order_number' => 'SO-'.Str::upper(Str::random(8)),
            'customer_id' => $records['customerId'],
            'sale_date' => '2026-09-24',
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_liters' => '1000',
            'unit_price' => '10.25',
            'amount_paid' => '0.00',
            'payment_method' => 'cash_on_delivery',
            'payment_terms' => 'installment',
            'status' => 'confirmed',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseRecords(): array
    {
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-INITIAL-PAY',
            'name' => 'Initial Payment Customer',
            'company_name' => 'Initial Payment Company',
            'payment_status' => 'clear',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $fuelTypeId = (int) DB::table('fuel_types')
            ->where('code', 'DSL')
            ->where('status', 'active')
            ->value('id');

        $this->assertGreaterThan(0, $fuelTypeId);

        return compact('salesOfficer', 'admin', 'customerId', 'fuelTypeId');
    }
}
