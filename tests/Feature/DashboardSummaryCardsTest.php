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
            ->assertDontSee('Dashboard Retired Fuel')
            ->assertDontSee('888888');

        $this->assertSame($before, $this->databaseCounts());

        $this->actingAs(User::factory()->create(['role' => 'driver', 'status' => 'active']))
            ->get(route('admin.dashboard'))
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
        DB::table('purchase_items')->insert([
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
