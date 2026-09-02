<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DashboardSummaryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardSummaryCardsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_summary_cards_use_real_authoritative_records(): void
    {
        Carbon::setTestNow('2026-09-01 10:00:00');
        $records = $this->records();
        $this->dashboardData($records);

        $this->actingAs($records['admin'])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Total Inventory (KL)')
            ->assertSee('45 KL')
            ->assertSee('Total Sales Revenue')
            ->assertSee('PHP 150,000')
            ->assertSee('Outstanding Balance')
            ->assertSee('PHP 50,000')
            ->assertSee('Unlifted Fuel (KL)')
            ->assertSee('50 KL')
            ->assertSee('Active Deliveries')
            ->assertSee('3');

        $this->actingAs($records['inventoryOfficer'])
            ->get(route('inventory-officer.inventory'))
            ->assertOk()
            ->assertSee('Total Inventory')
            ->assertSee('45,000 L')
            ->assertSee('Stock-In Today')
            ->assertSee('50,000 L')
            ->assertSee('Stock-Out Today')
            ->assertSee('10,000 L')
            ->assertSee('Unlifted Fuel')
            ->assertSee('50,000 L')
            ->assertSee('Open Purchases');

        $this->actingAs($records['salesOfficer'])
            ->get(route('sales-officer.sales'))
            ->assertOk()
            ->assertSee('Total Sales')
            ->assertSee('PHP 150,000')
            ->assertSee("Today's Sales")
            ->assertSee('PHP 120,000')
            ->assertSee('Payments Collected')
            ->assertSee('PHP 100,000')
            ->assertSee('Outstanding Receivables')
            ->assertSee('PHP 50,000')
            ->assertSee('Active Customers')
            ->assertSee('1');

        $this->actingAs($records['dispatchOfficer'])
            ->get(route('dispatch.fuel-lifting'))
            ->assertOk()
            ->assertSee('Total Deliveries')
            ->assertSee('Scheduled')
            ->assertSee('Active')
            ->assertSee('Completed')
            ->assertSee('Cancelled')
            ->assertSee('2');

        Carbon::setTestNow();
    }

    public function test_dashboard_summary_cards_show_zero_empty_states_without_mock_values(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $inventoryOfficer = User::factory()->create(['role' => 'inventory_officer', 'status' => 'active']);
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active']);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('0 KL')
            ->assertSee('PHP 0')
            ->assertSee('No data available')
            ->assertDontSee('Premium')
            ->assertDontSee('Diesel')
            ->assertDontSee('PHP 4,580,000')
            ->assertDontSee('PHP10.5M');

        $this->actingAs($inventoryOfficer)
            ->get(route('inventory-officer.inventory'))
            ->assertOk()
            ->assertSee('Total Inventory')
            ->assertSee('0 L')
            ->assertSee('Open Purchases')
            ->assertSee('No records found.');

        $this->actingAs($salesOfficer)
            ->get(route('sales-officer.sales'))
            ->assertOk()
            ->assertSee('Total Sales')
            ->assertSee('PHP 0')
            ->assertSee('Active Customers')
            ->assertSee('No records found.');
    }

    public function test_sales_trend_chart_uses_real_sales_with_zero_filled_periods(): void
    {
        Carbon::setTestNow('2026-09-01 10:00:00');
        $records = $this->records();
        $this->dashboardData($records);

        /** @var DashboardSummaryService $summary */
        $summary = app(DashboardSummaryService::class);
        $monthlyTrend = $summary->salesTrend('month', 2026);
        $yearlyTrend = $summary->salesTrend('year', 2026);

        $this->assertSame(['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], $monthlyTrend['labels']);
        $this->assertSame(0.0, $monthlyTrend['values'][0]);
        $this->assertSame(30000.0, $monthlyTrend['values'][7]);
        $this->assertSame(120000.0, $monthlyTrend['values'][8]);
        $this->assertSame(0.0, $monthlyTrend['values'][9]);
        $this->assertSame(150000.0, $monthlyTrend['total']);
        $this->assertSame('PHP 150,000', $monthlyTrend['formattedTotal']);

        $this->assertSame(['2022', '2023', '2024', '2025', '2026'], $yearlyTrend['labels']);
        $this->assertSame([0.0, 0.0, 0.0, 0.0, 150000.0], $yearlyTrend['values']);
        $this->assertSame('Sales Revenue', $monthlyTrend['chart']['datasets'][0]['label']);
        $this->assertSame($monthlyTrend['values'], $monthlyTrend['chart']['datasets'][0]['data']);
        $this->assertSame('PHP120,000', $monthlyTrend['chart']['datasets'][0]['formattedData'][8]);

        $this->actingAs($records['admin'])
            ->get(route('admin.dashboard', ['trend_period' => 'month', 'trend_year' => 2026]))
            ->assertOk()
            ->assertSee('data-sales-trend-chart', false)
            ->assertSee('Per Month')
            ->assertSee('Jan')
            ->assertSee('Aug')
            ->assertSee('Sep')
            ->assertSee('120000')
            ->assertSee('PHP120,000')
            ->assertSee('Total Sales Revenue')
            ->assertSee('PHP 150,000');

        $this->actingAs($records['admin'])
            ->from(route('admin.dashboard'))
            ->get(route('admin.dashboard', ['trend_period' => 'bogus']))
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHasErrors('trend_period');

        $this->actingAs(User::factory()->create(['role' => 'driver', 'status' => 'active']))
            ->get(route('admin.dashboard', ['trend_period' => 'month']))
            ->assertForbidden();

        Carbon::setTestNow();
    }

    public function test_stock_level_chart_uses_authoritative_inventory_movements_by_fuel_type(): void
    {
        Carbon::setTestNow('2026-09-01 10:00:00');
        $records = $this->records();
        $gasolineFuelId = DB::table('fuel_types')->insertGetId([
            'code' => 'GAS-DASH',
            'name' => 'Dashboard Gasoline',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('fuel_types')->insert([
            'code' => 'E10-DASH',
            'name' => 'Dashboard E10',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $retiredFuelId = DB::table('fuel_types')->insertGetId([
            'code' => 'RET-DASH',
            'name' => 'Dashboard Retired Fuel',
            'status' => 'inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventory_movements')->insert([
            $this->inventoryMovement($records, 'MOV-STOCK-DIESEL-IN', 'in', 50000, '2026-09-01 08:00:00'),
            array_merge($this->inventoryMovement($records, 'MOV-STOCK-GAS-IN', 'in', 20000, '2026-09-01 08:15:00'), [
                'fuel_type_id' => $gasolineFuelId,
            ]),
            array_merge($this->inventoryMovement($records, 'MOV-STOCK-RETIRED-IN', 'in', 999999, '2026-09-01 08:30:00'), [
                'fuel_type_id' => $retiredFuelId,
            ]),
        ]);

        $saleId = $this->sale($records, 'SLS-STOCK-DASH', '2026-09-01', 'confirmed', 120000);
        $saleItemId = DB::table('sale_items')->where('sale_id', $saleId)->value('id');
        $releasedStockOutId = $this->stockOut($records, $saleId, (int) $saleItemId, 'STO-STOCK-RELEASED', 10000, 'released');
        $cancelledStockOutId = $this->stockOut($records, $saleId, (int) $saleItemId, 'STO-STOCK-CANCELLED', 9000, 'cancelled');

        DB::table('inventory_movements')->insert([
            array_merge($this->inventoryMovement($records, 'MOV-STOCK-RELEASED-OUT', 'out', 10000, '2026-09-01 09:00:00'), [
                'reference_type' => 'stock_out',
                'reference_id' => $releasedStockOutId,
            ]),
            array_merge($this->inventoryMovement($records, 'MOV-STOCK-CANCELLED-OUT', 'out', 9000, '2026-09-01 09:15:00'), [
                'reference_type' => 'stock_out',
                'reference_id' => $cancelledStockOutId,
            ]),
        ]);

        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => 'PUR-STOCK-NOT-RECEIVED',
            'depot_id' => $records['depotId'],
            'purchase_date' => '2026-09-01',
            'payment_status' => 'paid',
            'status' => 'ordered',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('purchase_items')->insert([
            'purchase_id' => $purchaseId,
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_ordered_liters' => 888888,
            'unit_cost' => 50,
            'line_total' => 44444400,
            'quantity_hauled_liters' => 0,
            'status' => 'unlifted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('deliveries')->insert([
            'delivery_code' => 'DLV-STOCK-DIRECT',
            'customer_id' => $records['customerId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'source_type' => 'depot',
            'depot_id' => $records['depotId'],
            'truck_id' => $records['truckId'],
            'driver_user_id' => $records['driver']->id,
            'scheduled_at' => '2026-09-01 10:00:00',
            'delivered_at' => '2026-09-01 11:00:00',
            'scheduled_quantity_liters' => 77777,
            'actual_quantity_liters' => 77777,
            'status' => 'delivered',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var DashboardSummaryService $summary */
        $summary = app(DashboardSummaryService::class);
        $stockLevels = $summary->stockLevels();
        $stockByLabel = collect($stockLevels['rows'])->keyBy('label');

        $this->assertSame(60000.0, $summary->totalInventoryLiters());
        $this->assertSame(60000.0, $stockLevels['totalLiters']);
        $this->assertSame(40000.0, $stockByLabel['Dashboard Diesel']['liters']);
        $this->assertSame(0.0, $stockByLabel['Dashboard E10']['liters']);
        $this->assertSame(20000.0, $stockByLabel['Dashboard Gasoline']['liters']);
        $this->assertFalse($stockByLabel->has('Dashboard Retired Fuel'));
        $this->assertSame(['Dashboard Diesel', 'Dashboard E10', 'Dashboard Gasoline'], $stockLevels['chart']['labels']);
        $this->assertSame([40000.0, 0.0, 20000.0], $stockLevels['chart']['datasets'][0]['data']);
        $this->assertSame('Available Stock', $stockLevels['chart']['datasets'][0]['label']);
        $this->assertSame('40,000 L', $stockLevels['chart']['datasets'][0]['formattedData'][0]);

        $before = $this->databaseCounts();

        $this->actingAs($records['admin'])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-stock-level-chart', false)
            ->assertSee('Current Stock By Fuel Type')
            ->assertSee('Dashboard Diesel')
            ->assertSee('Dashboard E10')
            ->assertSee('Dashboard Gasoline')
            ->assertSee('40,000 L')
            ->assertSee('20,000 L')
            ->assertSee('Total Inventory (KL)')
            ->assertSee('60 KL')
            ->assertDontSee('Dashboard Retired Fuel');

        $this->assertSame($before, $this->databaseCounts());

        $this->actingAs(User::factory()->create(['role' => 'driver', 'status' => 'active']))
            ->get(route('admin.dashboard'))
            ->assertForbidden();

        Carbon::setTestNow();
    }

    public function test_receivables_monitoring_uses_real_sales_payments_and_customer_balances(): void
    {
        Carbon::setTestNow('2026-09-01 10:00:00');
        $records = $this->records();
        $secondCustomerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-REC-SECOND',
            'name' => 'Second Receivable Customer',
            'company_name' => 'Second Receivable Co.',
            'payment_status' => 'partial',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $unpaidSaleId = $this->sale($records, 'SLS-REC-UNPAID', '2026-08-01', 'confirmed', 40000);
        DB::table('sales')->where('id', $unpaidSaleId)->update(['payment_method' => 'advance_payment']);
        DB::table('receivables')->where('sale_id', $unpaidSaleId)->update(['due_date' => '2026-08-15']);

        $partialSaleId = $this->sale($records, 'SLS-REC-PARTIAL', '2026-09-01', 'partially_paid', 100000);
        DB::table('sales')->where('id', $partialSaleId)->update(['customer_id' => $secondCustomerId]);
        DB::table('receivables')->where('sale_id', $partialSaleId)->update(['due_date' => '2026-09-30']);

        $paidSaleId = $this->sale($records, 'SLS-REC-PAID', '2026-09-01', 'paid', 50000);
        $cancelledSaleId = $this->sale($records, 'SLS-REC-CANCELLED', '2026-09-01', 'cancelled', 999999);

        DB::table('payments')->insert([
            $this->payment($records, $partialSaleId, 'PAY-REC-PARTIAL-ONE', 25000),
            $this->payment($records, $partialSaleId, 'PAY-REC-PARTIAL-TWO', 15000),
            $this->payment($records, $paidSaleId, 'PAY-REC-PAID', 50000),
            $this->payment($records, $cancelledSaleId, 'PAY-REC-CANCELLED', 999999),
        ]);
        $this->stockOutForSale($records, $unpaidSaleId, (int) DB::table('sale_items')->where('sale_id', $unpaidSaleId)->value('id'), 'STO-REC-UNPAID', 1000);
        $this->stockOutForSale($records, $partialSaleId, (int) DB::table('sale_items')->where('sale_id', $partialSaleId)->value('id'), 'STO-REC-PARTIAL', 1000, null, null, $secondCustomerId);
        $this->stockOutForSale($records, $paidSaleId, (int) DB::table('sale_items')->where('sale_id', $paidSaleId)->value('id'), 'STO-REC-PAID', 1000);

        /** @var DashboardSummaryService $summary */
        $summary = app(DashboardSummaryService::class);
        $monitoring = $summary->receivablesMonitoring();
        $rowsByCode = collect($monitoring['rows'])->keyBy('sale_code');
        $customersByName = collect($monitoring['customerTotals'])->keyBy('customer_name');

        $this->assertSame(100000.0, $summary->outstandingReceivables());
        $this->assertSame(2, $monitoring['outstandingSalesCount']);
        $this->assertSame(60000.0, $rowsByCode['SLS-REC-PARTIAL']['balance']);
        $this->assertSame(40000.0, $rowsByCode['SLS-REC-UNPAID']['balance']);
        $this->assertSame(40000.0, $rowsByCode['SLS-REC-PARTIAL']['paid']);
        $this->assertSame('Partially Paid', $rowsByCode['SLS-REC-PARTIAL']['status_label']);
        $this->assertSame('Overdue', $rowsByCode['SLS-REC-UNPAID']['status_label']);
        $this->assertFalse($rowsByCode->has('SLS-REC-PAID'));
        $this->assertFalse($rowsByCode->has('SLS-REC-CANCELLED'));
        $this->assertSame(60000.0, $customersByName['Second Receivable Customer']['balance']);
        $this->assertSame(40000.0, $customersByName['Dashboard Customer']['balance']);
        $this->assertSame(['Payments Collected', 'Outstanding Receivables'], $monitoring['chart']['labels']);
        $this->assertSame([90000.0, 100000.0], $monitoring['chart']['datasets'][0]['data']);
        $this->assertSame('Receivables Monitoring', $monitoring['chart']['datasets'][0]['label']);
        $this->assertSame('PHP 100,000', $monitoring['chart']['datasets'][0]['formattedData'][1]);

        $before = $this->databaseCounts();

        $this->actingAs($records['admin'])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-receivables-chart', false)
            ->assertSee('Revenue vs Receivables')
            ->assertSee('SLS-REC-PARTIAL')
            ->assertSee('SLS-REC-UNPAID')
            ->assertSee('Second Receivable Customer')
            ->assertSee('PHP 60,000')
            ->assertSee('PHP 40,000')
            ->assertSee('Partially Paid')
            ->assertSee('Overdue')
            ->assertSee('Outstanding Balance')
            ->assertSee('PHP 100,000')
            ->assertDontSee('SLS-REC-PAID')
            ->assertDontSee('SLS-REC-CANCELLED')
            ->assertDontSee('999999');

        $this->actingAs($records['salesOfficer'])
            ->get(route('sales-officer.sales'))
            ->assertOk()
            ->assertSee('SLS-REC-PARTIAL')
            ->assertSee('60,000.00')
            ->assertSee('SLS-REC-UNPAID')
            ->assertSee('40,000.00');

        $this->assertSame($before, $this->databaseCounts());

        $this->actingAs(User::factory()->create(['role' => 'driver', 'status' => 'active']))
            ->get(route('admin.dashboard'))
            ->assertForbidden();

        Carbon::setTestNow();
    }

    public function test_expected_revenue_uses_collected_payments_and_due_receivables_by_year(): void
    {
        Carbon::setTestNow('2026-09-01 10:00:00');
        $records = $this->records();

        $installmentSaleId = $this->sale($records, 'SLS-EXP-INSTALLMENT', '2026-01-10', 'partially_paid', 100000);
        DB::table('sale_items')->insert([
            'sale_id' => $installmentSaleId,
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_liters' => 100,
            'unit_price' => 50,
            'line_total' => 5000,
            'fulfilled_quantity_liters' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('receivables')->where('sale_id', $installmentSaleId)->update(['due_date' => '2026-03-15']);
        $firstScheduleId = DB::table('payment_schedules')->insertGetId([
            'sale_id' => $installmentSaleId,
            'due_date' => '2026-01-31',
            'amount_due' => 50000,
            'status' => 'partial',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $secondScheduleId = DB::table('payment_schedules')->insertGetId([
            'sale_id' => $installmentSaleId,
            'due_date' => '2026-02-28',
            'amount_due' => 50000,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $methodOnlySaleId = $this->sale($records, 'SLS-EXP-METHOD-ONLY', '2026-04-05', 'confirmed', 60000);
        DB::table('sales')->where('id', $methodOnlySaleId)->update(['payment_method' => 'cash_on_delivery']);
        DB::table('receivables')->where('sale_id', $methodOnlySaleId)->update(['due_date' => '2026-04-30']);

        $paidSaleId = $this->sale($records, 'SLS-EXP-PAID', '2026-05-02', 'paid', 40000);
        DB::table('receivables')->where('sale_id', $paidSaleId)->update(['due_date' => '2026-05-31']);

        $cancelledSaleId = $this->sale($records, 'SLS-EXP-CANCELLED', '2026-06-01', 'cancelled', 999999);
        DB::table('receivables')->where('sale_id', $cancelledSaleId)->update(['due_date' => '2026-06-30']);

        DB::table('payments')->insert([
            array_merge($this->payment($records, $installmentSaleId, 'PAY-EXP-JAN', 30000), [
                'payment_schedule_id' => $firstScheduleId,
                'payment_date' => '2026-01-20',
            ]),
            array_merge($this->payment($records, $installmentSaleId, 'PAY-EXP-FEB', 20000), [
                'payment_schedule_id' => $secondScheduleId,
                'payment_date' => '2026-02-20',
            ]),
            array_merge($this->payment($records, $paidSaleId, 'PAY-EXP-PAID', 40000), [
                'payment_schedule_id' => null,
                'payment_date' => '2026-05-05',
            ]),
            array_merge($this->payment($records, $cancelledSaleId, 'PAY-EXP-CANCELLED', 999999), [
                'payment_schedule_id' => null,
                'payment_date' => '2026-06-05',
            ]),
        ]);

        /** @var DashboardSummaryService $summary */
        $summary = app(DashboardSummaryService::class);
        $expected = $summary->expectedRevenue(2026);
        $emptyYear = $summary->expectedRevenue(2027);

        $this->assertSame('Expected Revenue = collected payments within the year + outstanding receivable balances due within the year.', $expected['formula']);
        $this->assertSame(['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], $expected['labels']);
        $this->assertSame(30000.0, $expected['values'][0]);
        $this->assertSame(20000.0, $expected['values'][1]);
        $this->assertSame(55000.0, $expected['values'][2]);
        $this->assertSame(60000.0, $expected['values'][3]);
        $this->assertSame(40000.0, $expected['values'][4]);
        $this->assertSame(0.0, $expected['values'][5]);
        $this->assertSame(205000.0, $expected['totalExpected']);
        $this->assertSame(90000.0, $expected['totalCollected']);
        $this->assertSame(115000.0, $expected['totalDueOutstanding']);
        $this->assertSame('43.9%', $expected['formattedCollectionRate']);
        $this->assertSame('Expected Revenue', $expected['chart']['datasets'][0]['label']);
        $this->assertSame($expected['values'], $expected['chart']['datasets'][0]['data']);
        $this->assertSame('PHP 60,000', $expected['chart']['datasets'][0]['formattedData'][3]);
        $this->assertSame([0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0], $emptyYear['values']);
        $this->assertSame('PHP 0', $emptyYear['formattedTotalExpected']);

        $this->assertSame(205000.0, $summary->totalSalesRevenue());
        $this->assertSame(90000.0, $summary->collectedRevenue());
        $this->assertSame(115000.0, $summary->outstandingReceivables());
        $this->assertSame(205000.0, $summary->salesTrend('month', 2026)['total']);
        $this->assertSame(115000.0, $summary->receivablesMonitoring()['totalOutstanding']);

        $before = $this->databaseCounts();

        $this->actingAs($records['admin'])
            ->get(route('admin.dashboard', ['expected_year' => 2026]))
            ->assertOk()
            ->assertSee('data-expected-revenue-chart', false)
            ->assertSee('Expected Revenue (2026)')
            ->assertSee('PHP 205,000')
            ->assertSee('PHP 90,000')
            ->assertSee('PHP 115,000')
            ->assertSee('60000')
            ->assertDontSee('999999');

        $this->actingAs($records['admin'])
            ->from(route('admin.dashboard'))
            ->get(route('admin.dashboard', ['expected_year' => 1999]))
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHasErrors('expected_year');

        $this->assertSame($before, $this->databaseCounts());

        $this->actingAs(User::factory()->create(['role' => 'driver', 'status' => 'active']))
            ->get(route('admin.dashboard', ['expected_year' => 2026]))
            ->assertForbidden();

        Carbon::setTestNow();
    }

    /**
     * @return array<string, mixed>
     */
    private function records(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $inventoryOfficer = User::factory()->create(['role' => 'inventory_officer', 'status' => 'active']);
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active']);
        $dispatchOfficer = User::factory()->create(['role' => 'dispatch_officer', 'status' => 'active']);
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active']);
        $depotId = DB::table('depots')->insertGetId([
            'depot_code' => 'DEP-DASH',
            'name' => 'Dashboard Depot',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $garageId = DB::table('storage_locations')->insertGetId([
            'location_code' => 'GAR-DASH',
            'name' => 'Dashboard Garage',
            'type' => 'garage',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $fuelTypeId = DB::table('fuel_types')->insertGetId([
            'code' => 'DSL-DASH',
            'name' => 'Dashboard Diesel',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-DASH',
            'name' => 'Dashboard Customer',
            'company_name' => 'Dashboard Customer Co.',
            'payment_status' => 'partial',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('customers')->insert([
            'customer_code' => 'CUS-DASH-INACTIVE',
            'name' => 'Inactive Dashboard Customer',
            'company_name' => 'Inactive Dashboard Co.',
            'payment_status' => 'clear',
            'status' => 'inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-DASH',
            'capacity_liters' => 30000,
            'truck_type' => 'delivery',
            'status' => 'assigned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('admin', 'inventoryOfficer', 'salesOfficer', 'dispatchOfficer', 'driver', 'depotId', 'garageId', 'fuelTypeId', 'customerId', 'truckId');
    }

    /**
     * @param array<string, mixed> $records
     */
    private function dashboardData(array $records): void
    {
        DB::table('inventory_movements')->insert([
            $this->inventoryMovement($records, 'MOV-DASH-IN', 'in', 50000, '2026-09-01 08:00:00'),
            $this->inventoryMovement($records, 'MOV-DASH-OUT', 'out', 10000, '2026-09-01 09:00:00'),
            $this->inventoryMovement($records, 'MOV-DASH-OLD', 'in', 5000, '2026-08-31 09:00:00'),
        ]);

        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => 'PUR-DASH-OPEN',
            'depot_id' => $records['depotId'],
            'purchase_date' => '2026-08-30',
            'payment_status' => 'partial',
            'status' => 'partially_hauled',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $purchaseItemId = DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchaseId,
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_ordered_liters' => 80000,
            'unit_cost' => 50,
            'line_total' => 4000000,
            'quantity_hauled_liters' => 30000,
            'status' => 'partial',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->haul($records, $purchaseId, $purchaseItemId, 'LFT-DASH-COMPLETED', 30000, 'completed');

        $cancelledPurchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => 'PUR-DASH-CANCELLED',
            'depot_id' => $records['depotId'],
            'purchase_date' => '2026-08-30',
            'payment_status' => 'unpaid',
            'status' => 'cancelled',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('purchase_items')->insert([
            'purchase_id' => $cancelledPurchaseId,
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_ordered_liters' => 999999,
            'unit_cost' => 50,
            'line_total' => 49999950,
            'quantity_hauled_liters' => 0,
            'status' => 'unlifted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $todaySaleId = $this->sale($records, 'SLS-DASH-TODAY', '2026-09-01', 'partially_paid', 120000);
        $oldSaleId = $this->sale($records, 'SLS-DASH-OLD', '2026-08-30', 'paid', 30000);
        $cancelledSaleId = $this->sale($records, 'SLS-DASH-CANCELLED', '2026-09-01', 'cancelled', 999999);

        DB::table('payments')->insert([
            $this->payment($records, $todaySaleId, 'PAY-DASH-TODAY', 70000),
            $this->payment($records, $oldSaleId, 'PAY-DASH-OLD', 30000),
            $this->payment($records, $cancelledSaleId, 'PAY-DASH-CANCELLED', 999999),
        ]);

        foreach (['scheduled', 'in_transit', 'incomplete', 'delivered', 'cancelled'] as $status) {
            DB::table('deliveries')->insert([
                'delivery_code' => 'DLV-DASH-'.strtoupper($status),
                'sale_id' => $todaySaleId,
                'customer_id' => $records['customerId'],
                'fuel_type_id' => $records['fuelTypeId'],
                'source_type' => 'garage',
                'storage_location_id' => $records['garageId'],
                'truck_id' => $records['truckId'],
                'driver_user_id' => $records['driver']->id,
                'scheduled_at' => '2026-09-01 12:00:00',
                'scheduled_quantity_liters' => 1000,
                'status' => $status,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $records
     */
    private function inventoryMovement(array $records, string $code, string $direction, float $quantity, string $date): array
    {
        return [
            'movement_code' => $code,
            'storage_location_id' => $records['garageId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'movement_type' => $direction === 'in' ? 'stock_in' : 'stock_out',
            'direction' => $direction,
            'quantity_liters' => $quantity,
            'unit_cost' => 50,
            'reference_type' => 'dashboard_test',
            'reference_id' => 1,
            'movement_date' => $date,
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * @param array<string, mixed> $records
     */
    private function sale(array $records, string $code, string $date, string $status, float $lineTotal): int
    {
        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => $code,
            'customer_id' => $records['customerId'],
            'sale_date' => $date,
            'payment_method' => 'bank_transfer',
            'payment_terms' => 'installment',
            'status' => $status,
            'created_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sale_items')->insert([
            'sale_id' => $saleId,
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_liters' => 1000,
            'unit_price' => $lineTotal / 1000,
            'line_total' => $lineTotal,
            'fulfilled_quantity_liters' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('receivables')->insert([
            'sale_id' => $saleId,
            'due_date' => '2026-09-30',
            'status' => $status === 'paid' ? 'clear' : 'partial',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $saleId;
    }

    /**
     * @param array<string, mixed> $records
     */
    private function payment(array $records, int $saleId, string $code, float $amount): array
    {
        return [
            'payment_code' => $code,
            'sale_id' => $saleId,
            'payment_date' => '2026-09-01',
            'amount' => $amount,
            'method' => 'bank_transfer',
            'received_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * @param array<string, mixed> $records
     */
    private function stockOut(array $records, int $saleId, int $saleItemId, string $code, float $quantity, string $status): int
    {
        return DB::table('stock_outs')->insertGetId([
            'stock_out_code' => $code,
            'sale_id' => $saleId,
            'sale_item_id' => $saleItemId,
            'customer_id' => $records['customerId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'storage_location_id' => $records['garageId'],
            'quantity_liters' => $quantity,
            'stock_out_at' => '2026-09-01 09:00:00',
            'status' => $status,
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_unlifted_fuel_monitoring_uses_completed_multi_lift_aggregation_and_filters(): void
    {
        Carbon::setTestNow('2026-09-01 10:00:00');
        $records = $this->records();
        $secondDepotId = DB::table('depots')->insertGetId([
            'depot_code' => 'DEP-DASH-SOUTH',
            'name' => 'Dashboard South Depot',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $secondFuelTypeId = DB::table('fuel_types')->insertGetId([
            'code' => 'KER-DASH',
            'name' => 'Dashboard Kerosene',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$zeroPurchaseId, $zeroItemId] = $this->purchaseItem($records, 'PUR-UNLIFTED-ZERO', 10000);
        [$singlePurchaseId, $singleItemId] = $this->purchaseItem($records, 'PUR-UNLIFTED-SINGLE', 10000);
        [$multiPurchaseId, $multiItemId] = $this->purchaseItem($records, 'PUR-UNLIFTED-MULTI', 10000);
        [$fullPurchaseId, $fullItemId] = $this->purchaseItem($records, 'PUR-UNLIFTED-FULL', 5000);
        [$overPurchaseId, $overItemId] = $this->purchaseItem($records, 'PUR-UNLIFTED-OVER', 10000);
        [$otherPurchaseId, $otherItemId] = $this->purchaseItem($records, 'PUR-UNLIFTED-OTHER', 20000, $secondDepotId, $secondFuelTypeId);
        [$cancelledPurchaseId, $cancelledItemId] = $this->purchaseItem($records, 'PUR-UNLIFTED-CANCELLED', 999999, null, null, 'cancelled');

        $this->haul($records, $singlePurchaseId, $singleItemId, 'LFT-UNLIFTED-SINGLE-DONE', 4000, 'completed');
        $this->haul($records, $singlePurchaseId, $singleItemId, 'LFT-UNLIFTED-SINGLE-SCHED', 1000, 'scheduled');
        $this->haul($records, $singlePurchaseId, $singleItemId, 'LFT-UNLIFTED-SINGLE-CANCEL', 2000, 'cancelled');
        $this->haul($records, $multiPurchaseId, $multiItemId, 'LFT-UNLIFTED-MULTI-A', 3000, 'completed');
        $this->haul($records, $multiPurchaseId, $multiItemId, 'LFT-UNLIFTED-MULTI-B', 4000, 'completed');
        $this->haul($records, $fullPurchaseId, $fullItemId, 'LFT-UNLIFTED-FULL', 5000, 'completed');
        $this->haul($records, $overPurchaseId, $overItemId, 'LFT-UNLIFTED-OVER', 12000, 'completed');
        $this->haul($records, $otherPurchaseId, $otherItemId, 'LFT-UNLIFTED-OTHER', 5000, 'completed', $secondDepotId, $secondFuelTypeId);
        $this->haul($records, $cancelledPurchaseId, $cancelledItemId, 'LFT-UNLIFTED-CANCELLED', 500000, 'completed');

        /** @var DashboardSummaryService $summary */
        $summary = app(DashboardSummaryService::class);
        $monitoring = $summary->unliftedFuelMonitoring();
        $rowsByCode = collect($monitoring['rows'])->keyBy('purchase_code');
        $fuelBreakdown = collect($monitoring['fuelBreakdown'])->keyBy('label');
        $depotBreakdown = collect($monitoring['depotBreakdown'])->keyBy('label');

        $this->assertSame(34000.0, $summary->unliftedFuelLiters());
        $this->assertSame(31000.0, $summary->liftedFuelLiters());
        $this->assertSame(65000.0, $monitoring['summary']['purchased_liters']);
        $this->assertSame(34000.0, $monitoring['summary']['remaining_liters']);
        $this->assertSame(3, $monitoring['summary']['partial_count']);
        $this->assertSame(1, $monitoring['summary']['unlifted_count']);
        $this->assertSame(2, $monitoring['summary']['lifted_count']);
        $this->assertSame(6000.0, $rowsByCode['PUR-UNLIFTED-SINGLE']['remaining_liters']);
        $this->assertSame(7000.0, $rowsByCode['PUR-UNLIFTED-MULTI']['lifted_liters']);
        $this->assertSame('Partially Lifted', $rowsByCode['PUR-UNLIFTED-MULTI']['lift_status_label']);
        $this->assertSame(10000.0, $rowsByCode['PUR-UNLIFTED-ZERO']['remaining_liters']);
        $this->assertFalse($rowsByCode->has('PUR-UNLIFTED-FULL'));
        $this->assertFalse($rowsByCode->has('PUR-UNLIFTED-OVER'));
        $this->assertFalse($rowsByCode->has('PUR-UNLIFTED-CANCELLED'));
        $this->assertSame(19000.0, $fuelBreakdown['Dashboard Diesel']['liters']);
        $this->assertSame(15000.0, $fuelBreakdown['Dashboard Kerosene']['liters']);
        $this->assertSame(19000.0, $depotBreakdown['Dashboard Depot']['liters']);
        $this->assertSame(15000.0, $depotBreakdown['Dashboard South Depot']['liters']);
        $this->assertSame(['Purchased', 'Lifted', 'Unlifted'], $monitoring['chart']['labels']);
        $this->assertSame([65000.0, 31000.0, 34000.0], $monitoring['chart']['datasets'][0]['data']);

        $partialMonitoring = $summary->unliftedFuelMonitoring(['lifting_status' => 'partial']);
        $partialCodes = collect($partialMonitoring['rows'])->pluck('purchase_code')->all();
        $this->assertContains('PUR-UNLIFTED-SINGLE', $partialCodes);
        $this->assertContains('PUR-UNLIFTED-MULTI', $partialCodes);
        $this->assertContains('PUR-UNLIFTED-OTHER', $partialCodes);
        $this->assertNotContains('PUR-UNLIFTED-ZERO', $partialCodes);
        $this->assertSame(24000.0, $partialMonitoring['summary']['remaining_liters']);

        $before = $this->databaseCounts();

        $this->actingAs($records['admin'])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-unlifted-fuel-chart', false)
            ->assertSee('Unlifted Fuel Monitoring')
            ->assertSee('Total Lifted')
            ->assertSee('31,000 L')
            ->assertSee('Partially Lifted')
            ->assertSee('Dashboard South Depot')
            ->assertSee('Dashboard Kerosene')
            ->assertSee('PUR-UNLIFTED-MULTI')
            ->assertSee('34 KL')
            ->assertDontSee('PUR-UNLIFTED-CANCELLED')
            ->assertDontSee('999999');

        $this->actingAs($records['admin'])
            ->get(route('admin.dashboard', ['unlifted_lifting_status' => 'partial']))
            ->assertOk()
            ->assertSee('PUR-UNLIFTED-SINGLE')
            ->assertSee('PUR-UNLIFTED-MULTI')
            ->assertDontSee('PUR-UNLIFTED-ZERO');

        $this->actingAs($records['admin'])
            ->from(route('admin.dashboard'))
            ->get(route('admin.dashboard', ['unlifted_lifting_status' => 'bogus']))
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHasErrors('unlifted_lifting_status');

        $this->actingAs(User::factory()->create(['role' => 'driver', 'status' => 'active']))
            ->get(route('admin.dashboard'))
            ->assertForbidden();

        $this->assertSame($before, $this->databaseCounts());

        Carbon::setTestNow();
    }

    public function test_inventory_variance_monitoring_uses_stock_out_vs_receivable_reconciliation(): void
    {
        Carbon::setTestNow('2026-09-02 10:00:00');
        $records = $this->records();
        $secondFuelTypeId = DB::table('fuel_types')->insertGetId([
            'code' => 'VAR-GAS',
            'name' => 'Variance Gasoline',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $secondCustomerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-VAR-SECOND',
            'name' => 'Variance Second Customer',
            'company_name' => 'Variance Second Co.',
            'payment_status' => 'partial',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$matchedSaleId, $matchedItemId] = $this->saleWithItem($records, 'SLS-VAR-MATCHED', 1000, 50, 'paid');
        $this->paymentForSale($records, $matchedSaleId, 'PAY-VAR-MATCHED', 50000);
        $this->stockOutForSale($records, $matchedSaleId, $matchedItemId, 'STO-VAR-MATCHED', 1000);

        [$missingStockOutSaleId] = $this->saleWithItem($records, 'SLS-VAR-MISSING-STOCK', 2000, 40, 'unpaid');

        [$mismatchSaleId, $mismatchItemId] = $this->saleWithItem($records, 'SLS-VAR-QTY-MISMATCH', 3000, 45, 'confirmed', null, $secondFuelTypeId);
        $this->stockOutForSale($records, $mismatchSaleId, $mismatchItemId, 'STO-VAR-QTY-MISMATCH', 1000, null, $secondFuelTypeId);

        [$unpaidSaleId, $unpaidItemId] = $this->saleWithItem($records, 'SLS-VAR-UNPAID-VALID', 4000, 55, 'unpaid');
        $this->stockOutForSale($records, $unpaidSaleId, $unpaidItemId, 'STO-VAR-UNPAID-VALID', 4000);

        [$partialSaleId, $partialItemId] = $this->saleWithItem($records, 'SLS-VAR-INSTALLMENT', 5000, 60, 'partially_paid', $secondCustomerId);
        $scheduleId = DB::table('payment_schedules')->insertGetId([
            'sale_id' => $partialSaleId,
            'due_date' => '2026-09-30',
            'amount_due' => 300000,
            'status' => 'partial',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->paymentForSale($records, $partialSaleId, 'PAY-VAR-INSTALLMENT', 120000, $scheduleId);
        $this->stockOutForSale($records, $partialSaleId, $partialItemId, 'STO-VAR-INSTALLMENT', 5000, null, null, $secondCustomerId);

        [$duplicateSaleId, $duplicateItemId] = $this->saleWithItem($records, 'SLS-VAR-DUPLICATE', 6000, 30, 'confirmed');
        $deliveryId = $this->deliveryForSale($records, $duplicateSaleId, $duplicateItemId, 6000);
        $this->stockOutForSale($records, $duplicateSaleId, $duplicateItemId, 'STO-VAR-DUP-A', 3000, $deliveryId);
        $this->stockOutForSale($records, $duplicateSaleId, $duplicateItemId, 'STO-VAR-DUP-B', 3000, $deliveryId);

        [$missingReceivableSaleId, $missingReceivableItemId] = $this->saleWithItem($records, 'SLS-VAR-MISSING-REC', 1500, 70, 'confirmed');
        DB::table('receivables')->where('sale_id', $missingReceivableSaleId)->delete();
        $this->stockOutForSale($records, $missingReceivableSaleId, $missingReceivableItemId, 'STO-VAR-MISSING-REC', 1500);

        [$cancelledSaleId, $cancelledItemId] = $this->saleWithItem($records, 'SLS-VAR-CANCELLED', 999999, 1, 'cancelled');
        $this->stockOutForSale($records, $cancelledSaleId, $cancelledItemId, 'STO-VAR-CANCELLED', 999999, null, null, null, 'cancelled');

        $invalidSaleId = DB::table('sales')->insertGetId([
            'sale_code' => 'SLS-VAR-INVALID-LINK',
            'customer_id' => $records['customerId'],
            'sale_date' => '2026-09-02',
            'payment_method' => 'bank_transfer',
            'payment_terms' => 'cod',
            'status' => 'confirmed',
            'created_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('receivables')->insert([
            'sale_id' => $invalidSaleId,
            'due_date' => '2026-09-30',
            'status' => 'unpaid',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $invalidStockOutId = DB::table('stock_outs')->insertGetId([
            'stock_out_code' => 'STO-VAR-INVALID-LINK',
            'sale_id' => $invalidSaleId,
            'sale_item_id' => null,
            'customer_id' => $records['customerId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'storage_location_id' => $records['garageId'],
            'quantity_liters' => 750,
            'stock_out_at' => '2026-09-02 08:00:00',
            'status' => 'released',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var DashboardSummaryService $summary */
        $summary = app(DashboardSummaryService::class);
        $monitoring = $summary->inventoryVarianceMonitoring();
        $rowsByCode = collect($monitoring['rows'])->keyBy('sale_code');

        $this->assertSame(8, $monitoring['summary']['total_checked']);
        $this->assertSame(3, $monitoring['summary']['matched_count']);
        $this->assertSame(5, $monitoring['summary']['variance_count']);
        $this->assertSame(62.5, $monitoring['summary']['variance_rate']);
        $this->assertSame(-3250.0, $monitoring['summary']['quantity_variance_liters']);
        $this->assertSame('Quantity Mismatch', $rowsByCode['SLS-VAR-QTY-MISMATCH']['reason']);
        $this->assertSame('Missing Stock-Out', $rowsByCode['SLS-VAR-MISSING-STOCK']['reason']);
        $this->assertSame('Duplicate Relationship', $rowsByCode['SLS-VAR-DUPLICATE']['reason']);
        $this->assertSame('Missing Sale/Receivable', $rowsByCode['SLS-VAR-MISSING-REC']['reason']);
        $this->assertFalse($rowsByCode->has('SLS-VAR-UNPAID-VALID'));
        $this->assertFalse($rowsByCode->has('SLS-VAR-INSTALLMENT'));
        $this->assertFalse($rowsByCode->has('SLS-VAR-MATCHED'));
        $this->assertFalse($rowsByCode->has('SLS-VAR-CANCELLED'));
        $this->assertTrue(collect($monitoring['rows'])->contains(fn (array $row): bool => $row['stock_out_code'] === 'STO-VAR-INVALID-LINK'));
        $this->assertDatabaseHas('stock_outs', ['id' => $invalidStockOutId, 'status' => 'released']);

        $matchedOnly = $summary->inventoryVarianceMonitoring(['variance_status' => 'matched']);
        $this->assertSame(3, $matchedOnly['summary']['total_checked']);
        $this->assertSame(0, $matchedOnly['summary']['variance_count']);

        $fuelFiltered = $summary->inventoryVarianceMonitoring(['fuel_type_id' => $secondFuelTypeId]);
        $this->assertSame(1, $fuelFiltered['summary']['total_checked']);
        $this->assertSame(1, $fuelFiltered['summary']['variance_count']);
        $this->assertSame(-2000.0, $fuelFiltered['summary']['quantity_variance_liters']);

        $before = $this->databaseCounts();

        $this->actingAs($records['admin'])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-inventory-variance-chart', false)
            ->assertSee('Inventory Variance')
            ->assertSee('62.5%')
            ->assertSee('-3,250 L')
            ->assertSee('SLS-VAR-QTY-MISMATCH')
            ->assertSee('SLS-VAR-MISSING-STOCK')
            ->assertSee('Missing Stock-Out')
            ->assertSee('Quantity Mismatch')
            ->assertSee('Duplicate Relationship')
            ->assertSee('Invalid Transaction Link')
            ->assertDontSee('SLS-VAR-CANCELLED')
            ->assertDontSee('999999');

        $this->actingAs($records['admin'])
            ->get(route('admin.dashboard', ['variance_status' => 'matched']))
            ->assertOk()
            ->assertSee('No variance detected');

        $this->actingAs($records['admin'])
            ->from(route('admin.dashboard'))
            ->get(route('admin.dashboard', ['variance_status' => 'bogus']))
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHasErrors('variance_status');

        $this->actingAs(User::factory()->create(['role' => 'driver', 'status' => 'active']))
            ->get(route('admin.dashboard'))
            ->assertForbidden();

        $this->assertSame($before, $this->databaseCounts());

        Carbon::setTestNow();
    }

    /**
     * @param array<string, mixed> $records
     * @return array{0: int, 1: int}
     */
    private function purchaseItem(array $records, string $code, float $quantity, ?int $depotId = null, ?int $fuelTypeId = null, string $status = 'ordered'): array
    {
        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => $code,
            'depot_id' => $depotId ?: $records['depotId'],
            'purchase_date' => '2026-08-30',
            'payment_status' => 'paid',
            'status' => $status,
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $purchaseItemId = DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchaseId,
            'fuel_type_id' => $fuelTypeId ?: $records['fuelTypeId'],
            'quantity_ordered_liters' => $quantity,
            'unit_cost' => 50,
            'line_total' => $quantity * 50,
            'quantity_hauled_liters' => 0,
            'status' => 'unlifted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$purchaseId, $purchaseItemId];
    }

    /**
     * @param array<string, mixed> $records
     */
    private function haul(array $records, int $purchaseId, int $purchaseItemId, string $code, float $quantity, string $status, ?int $depotId = null, ?int $fuelTypeId = null): int
    {
        return DB::table('hauls')->insertGetId([
            'haul_code' => $code,
            'purchase_id' => $purchaseId,
            'purchase_item_id' => $purchaseItemId,
            'depot_id' => $depotId ?: $records['depotId'],
            'fuel_type_id' => $fuelTypeId ?: $records['fuelTypeId'],
            'truck_id' => $records['truckId'],
            'driver_user_id' => $records['driver']->id,
            'dr_number' => $code.'-DR',
            'scheduled_at' => '2026-08-31 09:00:00',
            'hauled_at' => $status === 'completed' ? '2026-08-31 11:00:00' : null,
            'source_location' => 'Dashboard Rack',
            'quantity_liters' => $quantity,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $records
     * @return array{0: int, 1: int}
     */
    private function saleWithItem(array $records, string $code, float $quantity, float $unitPrice, string $status, ?int $customerId = null, ?int $fuelTypeId = null): array
    {
        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => $code,
            'customer_id' => $customerId ?: $records['customerId'],
            'sale_date' => '2026-09-02',
            'payment_method' => 'bank_transfer',
            'payment_terms' => 'installment',
            'status' => $status,
            'created_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $saleItemId = DB::table('sale_items')->insertGetId([
            'sale_id' => $saleId,
            'fuel_type_id' => $fuelTypeId ?: $records['fuelTypeId'],
            'quantity_liters' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $quantity * $unitPrice,
            'fulfilled_quantity_liters' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('receivables')->insert([
            'sale_id' => $saleId,
            'due_date' => '2026-09-30',
            'status' => $status === 'paid' ? 'clear' : ($status === 'partially_paid' ? 'partial' : 'unpaid'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$saleId, $saleItemId];
    }

    /**
     * @param array<string, mixed> $records
     */
    private function paymentForSale(array $records, int $saleId, string $code, float $amount, ?int $scheduleId = null): void
    {
        DB::table('payments')->insert([
            'payment_code' => $code,
            'sale_id' => $saleId,
            'payment_schedule_id' => $scheduleId,
            'payment_date' => '2026-09-02',
            'amount' => $amount,
            'method' => 'bank_transfer',
            'reference_number' => $code.'-REF',
            'received_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $records
     */
    private function deliveryForSale(array $records, int $saleId, int $saleItemId, float $quantity): int
    {
        return DB::table('deliveries')->insertGetId([
            'delivery_code' => 'DLV-VAR-'.$saleItemId,
            'sale_id' => $saleId,
            'sale_item_id' => $saleItemId,
            'customer_id' => $records['customerId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'source_type' => 'garage',
            'storage_location_id' => $records['garageId'],
            'truck_id' => $records['truckId'],
            'driver_user_id' => $records['driver']->id,
            'scheduled_at' => '2026-09-02 08:00:00',
            'delivered_at' => '2026-09-02 09:00:00',
            'scheduled_quantity_liters' => $quantity,
            'actual_quantity_liters' => $quantity,
            'status' => 'delivered',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $records
     */
    private function stockOutForSale(array $records, int $saleId, int $saleItemId, string $code, float $quantity, ?int $deliveryId = null, ?int $fuelTypeId = null, ?int $customerId = null, string $status = 'released'): int
    {
        return DB::table('stock_outs')->insertGetId([
            'stock_out_code' => $code,
            'sale_id' => $saleId,
            'sale_item_id' => $saleItemId,
            'customer_id' => $customerId ?: $records['customerId'],
            'fuel_type_id' => $fuelTypeId ?: $records['fuelTypeId'],
            'storage_location_id' => $records['garageId'],
            'delivery_id' => $deliveryId,
            'quantity_liters' => $quantity,
            'stock_out_at' => '2026-09-02 08:00:00',
            'status' => $status,
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function databaseCounts(): array
    {
        return [
            'inventory_movements' => DB::table('inventory_movements')->count(),
            'purchases' => DB::table('purchases')->count(),
            'purchase_items' => DB::table('purchase_items')->count(),
            'deliveries' => DB::table('deliveries')->count(),
            'stock_outs' => DB::table('stock_outs')->count(),
            'sales' => DB::table('sales')->count(),
            'payments' => DB::table('payments')->count(),
        ];
    }
}
