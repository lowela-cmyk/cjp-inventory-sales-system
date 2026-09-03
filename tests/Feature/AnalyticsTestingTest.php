<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DashboardSummaryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AnalyticsTestingTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_cards_sales_trends_stock_levels_and_receivables_match_database_totals(): void
    {
        Carbon::setTestNow('2026-09-04 10:00:00');
        $records = $this->records();
        $gasolineId = $this->fuelType('ANA-GAS', 'Analytics Gasoline');

        DB::table('inventory_movements')->insert([
            $this->inventoryMovement($records, 'MOV-ANA-DIESEL-IN', $records['fuelTypeId'], 'in', 80000, '2026-09-04 07:00:00', 'beginning', 1),
            $this->inventoryMovement($records, 'MOV-ANA-DIESEL-OUT', $records['fuelTypeId'], 'out', 12500, '2026-09-04 09:00:00', 'adjustment', 1),
            $this->inventoryMovement($records, 'MOV-ANA-GAS-IN', $gasolineId, 'in', 22500, '2026-09-03 07:00:00', 'beginning', 2),
        ]);

        $cancelledStockOutId = $this->stockOut($records, $this->saleWithItem($records, 'SLS-ANA-CANCELLED-STOCK', '2026-09-04', 9000, 1, 'cancelled'), 'STO-ANA-CANCELLED', 9000, 'cancelled');
        DB::table('inventory_movements')->insert($this->inventoryMovement($records, 'MOV-ANA-CANCELLED-OUT', $records['fuelTypeId'], 'out', 9000, '2026-09-04 09:30:00', 'stock_out', $cancelledStockOutId));

        $augustSale = $this->saleWithItem($records, 'SLS-ANA-AUG', '2026-08-20', 30000, 1);
        $septemberPartial = $this->saleWithItem($records, 'SLS-ANA-SEP-PARTIAL', '2026-09-04', 100000, 1, 'partially_paid');
        $septemberPaid = $this->saleWithItem($records, 'SLS-ANA-SEP-PAID', '2026-09-04', 40000, 1, 'paid', $gasolineId);
        $cancelledSale = $this->saleWithItem($records, 'SLS-ANA-CANCELLED', '2026-09-04', 999999, 1, 'cancelled');
        $this->stockOut($records, $septemberPartial, 'STO-ANA-PARTIAL', 100000);
        $this->stockOut($records, $septemberPaid, 'STO-ANA-PAID', 40000);
        $this->payment($records, $septemberPartial['saleId'], 'PAY-ANA-PARTIAL-A', 25000, '2026-09-04');
        $this->payment($records, $septemberPartial['saleId'], 'PAY-ANA-PARTIAL-B', 15000, '2026-09-04');
        $this->payment($records, $septemberPaid['saleId'], 'PAY-ANA-PAID', 40000, '2026-09-04');
        $this->payment($records, $cancelledSale['saleId'], 'PAY-ANA-CANCELLED', 999999, '2026-09-04');

        DB::table('deliveries')->insert([
            'delivery_code' => 'DLV-ANA-DIRECT',
            'sale_id' => $augustSale['saleId'],
            'sale_item_id' => $augustSale['saleItemId'],
            'customer_id' => $records['customerId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'source_type' => 'depot',
            'depot_id' => $records['depotId'],
            'truck_id' => $records['truckId'],
            'driver_user_id' => $records['driver']->id,
            'scheduled_at' => '2026-09-04 11:00:00',
            'delivered_at' => '2026-09-04 12:00:00',
            'scheduled_quantity_liters' => 30000,
            'actual_quantity_liters' => 30000,
            'status' => 'delivered',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var DashboardSummaryService $summary */
        $summary = app(DashboardSummaryService::class);
        $adminSummary = $summary->adminSummary();
        $inventoryCards = collect($summary->inventoryCards())->keyBy('label');
        $salesCards = collect($summary->salesCards())->keyBy('label');
        $stockLevels = $summary->stockLevels();
        $stockByFuel = collect($stockLevels['rows'])->keyBy('label');
        $receivables = $summary->receivablesMonitoring();
        $monthlyTrend = $summary->salesTrend('month', 2026);

        $this->assertSame(90000.0, $summary->totalInventoryLiters());
        $this->assertSame('90 KL', $adminSummary['metricCards'][0][1]);
        $this->assertSame('90,000 L', $inventoryCards['Total Inventory']['value']);
        $this->assertSame('80,000 L', $inventoryCards['Stock-In Today']['value']);
        $this->assertSame('12,500 L', $inventoryCards['Stock-Out Today']['value']);
        $this->assertSame(67500.0, $stockByFuel['Analytics Diesel']['liters']);
        $this->assertSame(22500.0, $stockByFuel['Analytics Gasoline']['liters']);
        $this->assertSame([67500.0, 22500.0], $stockLevels['chart']['datasets'][0]['data']);
        $this->assertSame(['67,500 L', '22,500 L'], $stockLevels['chart']['datasets'][0]['formattedData']);

        $this->assertSame(170000.0, $summary->totalSalesRevenue());
        $this->assertSame('PHP 170,000', $salesCards['Total Sales']['value']);
        $this->assertSame('PHP 140,000', $salesCards["Today's Sales"]['value']);
        $this->assertSame([0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 30000.0, 140000.0, 0.0, 0.0, 0.0], $monthlyTrend['values']);
        $this->assertSame($monthlyTrend['values'], $monthlyTrend['chart']['datasets'][0]['data']);

        $this->assertSame(80000.0, $summary->collectedRevenue());
        $this->assertSame(90000.0, $summary->outstandingReceivables());
        $this->assertSame('PHP 80,000', $salesCards['Payments Collected']['value']);
        $this->assertSame('PHP 90,000', $salesCards['Outstanding Receivables']['value']);
        $this->assertSame([80000.0, 90000.0], $receivables['chart']['datasets'][0]['data']);
        $this->assertSame(60000.0, collect($receivables['rows'])->firstWhere('sale_code', 'SLS-ANA-SEP-PARTIAL')['balance']);
        $this->assertSame(30000.0, collect($receivables['rows'])->firstWhere('sale_code', 'SLS-ANA-AUG')['balance']);
        $this->assertFalse(collect($receivables['rows'])->contains('sale_code', 'SLS-ANA-CANCELLED'));

        $this->actingAs($records['admin'])
            ->get(route('admin.dashboard', ['trend_period' => 'month', 'trend_year' => 2026]))
            ->assertOk()
            ->assertSee('90 KL')
            ->assertSee('PHP 170,000')
            ->assertSee('PHP 90,000')
            ->assertSee('140000')
            ->assertSee('67,500 L')
            ->assertDontSee('999999');

        Carbon::setTestNow();
    }

    public function test_expected_revenue_unlifted_fuel_filters_and_zero_periods_are_stable(): void
    {
        Carbon::setTestNow('2026-09-04 10:00:00');
        $records = $this->records();
        $gasolineId = $this->fuelType('ANA-EXP-GAS', 'Analytics Expected Gasoline');
        $southDepotId = $this->depot('DEP-ANA-SOUTH', 'Analytics South Depot');

        $installmentSale = $this->saleWithItem($records, 'SLS-ANA-INSTALLMENT', '2026-02-01', 120000, 1, 'partially_paid');
        DB::table('receivables')->where('sale_id', $installmentSale['saleId'])->update(['due_date' => '2026-03-15']);
        $this->payment($records, $installmentSale['saleId'], 'PAY-ANA-INSTALLMENT-A', 45000, '2026-02-15');
        $this->payment($records, $installmentSale['saleId'], 'PAY-ANA-INSTALLMENT-B', 15000, '2026-03-01');

        $futureDueSale = $this->saleWithItem($records, 'SLS-ANA-FUTURE-DUE', '2026-04-01', 50000, 1, 'confirmed');
        DB::table('receivables')->where('sale_id', $futureDueSale['saleId'])->update(['due_date' => '2026-04-30']);
        $outsideYearSale = $this->saleWithItem($records, 'SLS-ANA-OUTSIDE-YEAR', '2025-12-15', 70000, 1, 'confirmed');
        DB::table('receivables')->where('sale_id', $outsideYearSale['saleId'])->update(['due_date' => '2027-01-15']);
        $cancelledSale = $this->saleWithItem($records, 'SLS-ANA-VOID-EXPECTED', '2026-06-01', 999999, 1, 'cancelled');
        $this->payment($records, $cancelledSale['saleId'], 'PAY-ANA-VOID', 999999, '2026-06-02');

        $dieselPartial = $this->purchaseItem($records, 'PUR-ANA-DIESEL-PARTIAL', 50000);
        $dieselUnlifted = $this->purchaseItem($records, 'PUR-ANA-DIESEL-UNLIFTED', 20000);
        $gasPartial = $this->purchaseItem($records, 'PUR-ANA-GAS-PARTIAL', 40000, $southDepotId, $gasolineId);
        $cancelledPurchase = $this->purchaseItem($records, 'PUR-ANA-CANCELLED', 999999, null, null, 'cancelled');
        $this->haul($records, $dieselPartial, 'LFT-ANA-DIESEL-ONE', 12500, 'completed');
        $this->haul($records, $dieselPartial, 'LFT-ANA-DIESEL-TWO', 7500, 'completed');
        $this->haul($records, $gasPartial, 'LFT-ANA-GAS', 10000, 'completed', $southDepotId, $gasolineId);
        $this->haul($records, $cancelledPurchase, 'LFT-ANA-CANCELLED', 999999, 'completed');

        /** @var DashboardSummaryService $summary */
        $summary = app(DashboardSummaryService::class);
        $expected = $summary->expectedRevenue(2026);
        $unlifted = $summary->unliftedFuelMonitoring();
        $dieselOnly = $summary->unliftedFuelMonitoring(['fuel_type_id' => $records['fuelTypeId']]);
        $southOnly = $summary->unliftedFuelMonitoring(['depot_id' => $southDepotId]);
        $noData = $summary->unliftedFuelMonitoring(['date_from' => '2024-01-01', 'date_to' => '2024-01-31']);
        $emptyTrend = $summary->salesTrend('month', 2024);
        $emptyExpected = $summary->expectedRevenue(2024);

        $this->assertSame(60000.0, $expected['totalCollected']);
        $this->assertSame(110000.0, $expected['totalDueOutstanding']);
        $this->assertSame(170000.0, $expected['totalExpected']);
        $this->assertSame(45000.0, $expected['values'][1]);
        $this->assertSame(75000.0, $expected['values'][2]);
        $this->assertSame(50000.0, $expected['values'][3]);
        $this->assertSame(35.3, $expected['collectionRate']);
        $this->assertSame($expected['values'], $expected['chart']['datasets'][0]['data']);

        $this->assertSame(110000.0, $unlifted['summary']['purchased_liters']);
        $this->assertSame(30000.0, $unlifted['summary']['lifted_liters']);
        $this->assertSame(80000.0, $unlifted['summary']['remaining_liters']);
        $this->assertSame([110000.0, 30000.0, 80000.0], $unlifted['chart']['datasets'][0]['data']);
        $this->assertSame(50000.0, $dieselOnly['summary']['remaining_liters']);
        $this->assertSame(30000.0, $southOnly['summary']['remaining_liters']);
        $this->assertSame(0.0, $noData['summary']['remaining_liters']);
        $this->assertSame([0.0, 0.0, 0.0], $noData['chart']['datasets'][0]['data']);
        $this->assertSame(array_fill(0, 12, 0.0), $emptyTrend['values']);
        $this->assertSame(0.0, $emptyExpected['totalExpected']);
        $this->assertSame(0.0, $emptyExpected['collectionRate']);

        $this->actingAs($records['admin'])
            ->get(route('admin.dashboard', [
                'expected_year' => 2026,
                'unlifted_fuel_type_id' => $records['fuelTypeId'],
                'unlifted_depot_id' => $records['depotId'],
                'unlifted_lifting_status' => 'partial',
            ]))
            ->assertOk()
            ->assertSee('PHP 170,000')
            ->assertSee('30,000 L')
            ->assertSee('PUR-ANA-DIESEL-PARTIAL')
            ->assertDontSee('PUR-ANA-CANCELLED')
            ->assertDontSee('999999');

        Carbon::setTestNow();
    }

    public function test_inventory_variance_filters_rates_and_quantity_variance_are_correct(): void
    {
        $records = $this->records();
        $gasolineId = $this->fuelType('ANA-VAR-GAS', 'Analytics Variance Gasoline');
        $matched = $this->saleWithItem($records, 'SLS-ANA-VAR-MATCHED', '2026-09-01', 1000, 50, 'paid');
        $missingStock = $this->saleWithItem($records, 'SLS-ANA-VAR-MISSING', '2026-09-02', 2000, 40, 'unpaid');
        $mismatch = $this->saleWithItem($records, 'SLS-ANA-VAR-MISMATCH', '2026-09-03', 3000, 45, 'confirmed', $gasolineId);
        $financialMismatch = $this->saleWithItem($records, 'SLS-ANA-VAR-FINANCE', '2026-09-04', 4000, 20, 'paid');
        $cancelled = $this->saleWithItem($records, 'SLS-ANA-VAR-CANCELLED', '2026-09-04', 999999, 1, 'cancelled');
        $this->stockOut($records, $matched, 'STO-ANA-VAR-MATCHED', 1000);
        $this->stockOut($records, $mismatch, 'STO-ANA-VAR-MISMATCH', 1000, 'released', $gasolineId);
        $this->stockOut($records, $financialMismatch, 'STO-ANA-VAR-FINANCE', 4000);
        $this->stockOut($records, $cancelled, 'STO-ANA-VAR-CANCELLED', 999999, 'cancelled');
        $this->payment($records, $matched['saleId'], 'PAY-ANA-VAR-MATCHED', 50000, '2026-09-01');
        $this->payment($records, $financialMismatch['saleId'], 'PAY-ANA-VAR-OVER-A', 90000, '2026-09-04');
        DB::table('payments')->insert($this->paymentRow($records, $cancelled['saleId'], 'PAY-ANA-VAR-CANCELLED', 999999, '2026-09-04'));

        /** @var DashboardSummaryService $summary */
        $summary = app(DashboardSummaryService::class);
        $variance = $summary->inventoryVarianceMonitoring();
        $rows = collect($variance['rows'])->keyBy('sale_code');
        $gasOnly = $summary->inventoryVarianceMonitoring(['fuel_type_id' => $gasolineId]);
        $dateOnly = $summary->inventoryVarianceMonitoring(['date_from' => '2026-09-03', 'date_to' => '2026-09-04']);
        $matchedOnly = $summary->inventoryVarianceMonitoring(['variance_status' => 'matched']);
        $noData = $summary->inventoryVarianceMonitoring(['date_from' => '2024-01-01', 'date_to' => '2024-01-31']);

        $this->assertSame(4, $variance['summary']['total_checked']);
        $this->assertSame(1, $variance['summary']['matched_count']);
        $this->assertSame(3, $variance['summary']['variance_count']);
        $this->assertSame(75.0, $variance['summary']['variance_rate']);
        $this->assertSame(-4000.0, $variance['summary']['quantity_variance_liters']);
        $this->assertSame([1, 3], $variance['chart']['datasets'][0]['data']);
        $this->assertSame('Missing Stock-Out', $rows['SLS-ANA-VAR-MISSING']['reason']);
        $this->assertSame('Quantity Mismatch', $rows['SLS-ANA-VAR-MISMATCH']['reason']);
        $this->assertSame('Financial Record Mismatch', $rows['SLS-ANA-VAR-FINANCE']['reason']);
        $this->assertFalse($rows->has('SLS-ANA-VAR-CANCELLED'));
        $this->assertSame(1, $gasOnly['summary']['total_checked']);
        $this->assertSame(-2000.0, $gasOnly['summary']['quantity_variance_liters']);
        $this->assertSame(2, $dateOnly['summary']['total_checked']);
        $this->assertSame(1, $matchedOnly['summary']['total_checked']);
        $this->assertSame(0, $matchedOnly['summary']['variance_count']);
        $this->assertSame(0, $noData['summary']['total_checked']);
        $this->assertSame(0.0, $noData['summary']['variance_rate']);

        $this->actingAs($records['admin'])
            ->get(route('admin.dashboard', [
                'variance_fuel_type_id' => $gasolineId,
                'variance_date_from' => '2026-09-03',
                'variance_date_to' => '2026-09-03',
            ]))
            ->assertOk()
            ->assertSee('100.0%')
            ->assertSee('SLS-ANA-VAR-MISMATCH')
            ->assertDontSee('SLS-ANA-VAR-CANCELLED')
            ->assertDontSee('999999');
    }

    /**
     * @return array<string, mixed>
     */
    private function records(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $inventoryOfficer = User::factory()->create(['role' => 'inventory_officer', 'status' => 'active']);
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active']);
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active']);
        $depotId = $this->depot('DEP-ANA', 'Analytics Depot');
        $garageId = DB::table('storage_locations')->insertGetId([
            'location_code' => 'GAR-ANA',
            'name' => 'Analytics Garage',
            'type' => 'garage',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $fuelTypeId = $this->fuelType('ANA-DSL', 'Analytics Diesel');
        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-ANA',
            'name' => 'Analytics Customer',
            'company_name' => 'Analytics Customer Co.',
            'payment_status' => 'partial',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-ANA',
            'capacity_liters' => 120000,
            'truck_type' => 'mixed',
            'status' => 'assigned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('admin', 'inventoryOfficer', 'salesOfficer', 'driver', 'depotId', 'garageId', 'fuelTypeId', 'customerId', 'truckId');
    }

    private function depot(string $code, string $name): int
    {
        return DB::table('depots')->insertGetId([
            'depot_code' => $code,
            'name' => $name,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function fuelType(string $code, string $name): int
    {
        return DB::table('fuel_types')->insertGetId([
            'code' => $code,
            'name' => $name,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $records
     * @return array{purchaseId: int, purchaseItemId: int}
     */
    private function purchaseItem(array $records, string $code, float $quantity, ?int $depotId = null, ?int $fuelTypeId = null, string $status = 'ordered'): array
    {
        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => $code,
            'depot_id' => $depotId ?: $records['depotId'],
            'purchase_date' => '2026-09-04',
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

        return ['purchaseId' => $purchaseId, 'purchaseItemId' => $purchaseItemId];
    }

    /**
     * @param array<string, mixed> $records
     * @param array{purchaseId: int, purchaseItemId: int} $purchase
     */
    private function haul(array $records, array $purchase, string $code, float $quantity, string $status, ?int $depotId = null, ?int $fuelTypeId = null): int
    {
        return DB::table('hauls')->insertGetId([
            'haul_code' => $code,
            'purchase_id' => $purchase['purchaseId'],
            'purchase_item_id' => $purchase['purchaseItemId'],
            'depot_id' => $depotId ?: $records['depotId'],
            'fuel_type_id' => $fuelTypeId ?: $records['fuelTypeId'],
            'truck_id' => $records['truckId'],
            'driver_user_id' => $records['driver']->id,
            'scheduled_at' => '2026-09-04 08:00:00',
            'hauled_at' => $status === 'completed' ? '2026-09-04 09:00:00' : null,
            'quantity_liters' => $quantity,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $records
     * @return array{saleId: int, saleItemId: int}
     */
    private function saleWithItem(array $records, string $code, string $date, float $quantity, float $unitPrice, string $status = 'confirmed', ?int $fuelTypeId = null): array
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
            'status' => $status === 'paid' ? 'clear' : ($status === 'partially_paid' ? 'partial' : 'pending'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['saleId' => $saleId, 'saleItemId' => $saleItemId];
    }

    /**
     * @param array<string, mixed> $records
     * @param array{saleId: int, saleItemId: int} $sale
     */
    private function stockOut(array $records, array $sale, string $code, float $quantity, string $status = 'released', ?int $fuelTypeId = null): int
    {
        return DB::table('stock_outs')->insertGetId([
            'stock_out_code' => $code,
            'sale_id' => $sale['saleId'],
            'sale_item_id' => $sale['saleItemId'],
            'customer_id' => $records['customerId'],
            'fuel_type_id' => $fuelTypeId ?: $records['fuelTypeId'],
            'storage_location_id' => $records['garageId'],
            'quantity_liters' => $quantity,
            'stock_out_at' => '2026-09-04 09:00:00',
            'status' => $status,
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $records
     */
    private function payment(array $records, int $saleId, string $code, float $amount, string $date): void
    {
        DB::table('payments')->insert($this->paymentRow($records, $saleId, $code, $amount, $date));
    }

    /**
     * @param array<string, mixed> $records
     * @return array<string, mixed>
     */
    private function paymentRow(array $records, int $saleId, string $code, float $amount, string $date): array
    {
        return [
            'payment_code' => $code,
            'sale_id' => $saleId,
            'payment_date' => $date,
            'amount' => $amount,
            'method' => 'bank_transfer',
            'reference_number' => $code.'-REF',
            'received_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * @param array<string, mixed> $records
     * @return array<string, mixed>
     */
    private function inventoryMovement(array $records, string $code, int $fuelTypeId, string $direction, float $quantity, string $date, string $type, int $referenceId): array
    {
        return [
            'movement_code' => $code,
            'storage_location_id' => $records['garageId'],
            'fuel_type_id' => $fuelTypeId,
            'movement_type' => $type,
            'direction' => $direction,
            'quantity_liters' => $quantity,
            'unit_cost' => 50,
            'reference_type' => $type,
            'reference_id' => $referenceId,
            'movement_date' => $date,
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
