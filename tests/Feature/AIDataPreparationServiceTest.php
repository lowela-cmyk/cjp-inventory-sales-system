<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AIDataPreparationService;
use App\Services\DashboardSummaryService;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AIDataPreparationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_payload_prepares_compact_authoritative_business_data(): void
    {
        Carbon::setTestNow('2026-09-02 10:00:00');
        $records = $this->records();
        $this->businessData($records);

        /** @var AIDataPreparationService $aiData */
        $aiData = app(AIDataPreparationService::class);
        /** @var DashboardSummaryService $summary */
        $summary = app(DashboardSummaryService::class);

        $payload = $aiData->prepareForUser($records['admin'], [
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-02',
            'trend_period' => 'month',
            'trend_year' => 2026,
            'expected_year' => 2026,
            'limit' => 2,
        ]);

        $this->assertSame(['reporting_period', 'payload_policy', 'revenue', 'sales_trends', 'inventory', 'fuel_lifting', 'receivables', 'inventory_variance'], array_keys($payload));
        $this->assertSame('filtered', $payload['reporting_period']['scope']);
        $this->assertSame('2026-09-01', $payload['reporting_period']['date_from']);
        $this->assertSame('2026-09-02', $payload['reporting_period']['date_to']);

        $this->assertSame(195000.0, $payload['revenue']['total_valid_sales']);
        $this->assertSame(2, $payload['revenue']['valid_sales_count']);
        $this->assertSame(75000.0, $payload['revenue']['collected_revenue']);
        $this->assertSame(120000.0, $payload['revenue']['outstanding_receivables']);
        $this->assertSame(195999.0, $payload['revenue']['expected_revenue']);
        $this->assertSame(38.5, $payload['revenue']['collection_percentage']);
        $this->assertSame($summary->expectedRevenue(2026)['totalExpected'], $payload['revenue']['expected_revenue']);

        $september = collect($payload['sales_trends']['series'])->firstWhere('label', 'Sep');
        $this->assertSame(195000.0, $september['sales_total']);
        $this->assertSame(3500.0, $september['quantity_sold_liters']);
        $this->assertSame(3500.0, $payload['sales_trends']['total_quantity_sold_liters']);
        $this->assertSame(2, $payload['sales_trends']['valid_sales_count']);
        $this->assertSame(195000.0, $payload['sales_trends']['previous_period_comparison']['current_period_sales']);
        $this->assertSame(0.0, $payload['sales_trends']['previous_period_comparison']['previous_period_sales']);
        $this->assertNull($payload['sales_trends']['previous_period_comparison']['percentage_change']);
        $this->assertSame('increase', $payload['sales_trends']['previous_period_comparison']['direction']);
        $this->assertSame('Sep', $payload['sales_trends']['peak_period']['label']);
        $fuelSales = collect($payload['sales_trends']['fuel_type_breakdown'])->keyBy('fuel_type');
        $this->assertSame(3000.0, $fuelSales['AI Diesel']['quantity_liters']);
        $this->assertSame(160000.0, $fuelSales['AI Diesel']['sales_total']);
        $this->assertSame(500.0, $fuelSales['AI E10']['quantity_liters']);

        $this->assertSame($summary->stockLevels()['totalLiters'], $payload['inventory']['current_stock_liters']);
        $this->assertSame('liters', $payload['inventory']['quantity_unit']);
        $movements = collect($payload['inventory']['recorded_movement_summary_liters'])->keyBy('direction');
        $this->assertSame(15000.0, $movements['in']['quantity_liters']);
        $this->assertSame(1000.0, $movements['out']['quantity_liters']);
        $this->assertStringContainsString('depot fuel pending lifting', $payload['inventory']['separation_note']);

        $unlifted = $summary->unliftedFuelMonitoring(['date_from' => '2026-09-01', 'date_to' => '2026-09-02'], 2);
        $this->assertSame($unlifted['summary']['purchased_liters'], $payload['fuel_lifting']['summary']['purchased_liters']);
        $this->assertSame($unlifted['summary']['lifted_liters'], $payload['fuel_lifting']['summary']['lifted_liters']);
        $this->assertSame($unlifted['summary']['remaining_liters'], $payload['fuel_lifting']['summary']['unlifted_liters']);
        $this->assertLessThanOrEqual(2, count($payload['fuel_lifting']['sample_open_items']));

        $variance = $summary->inventoryVarianceMonitoring(['date_from' => '2026-09-01', 'date_to' => '2026-09-02'], 2);
        $this->assertSame($variance['summary']['total_checked'], $payload['inventory_variance']['summary']['transactions_checked']);
        $this->assertSame($variance['summary']['variance_count'], $payload['inventory_variance']['summary']['variance_count']);
        $this->assertSame($variance['summary']['quantity_variance_liters'], $payload['inventory_variance']['summary']['quantity_difference_liters']);
        $this->assertLessThanOrEqual(2, count($payload['inventory_variance']['sample_variances']));
        $this->assertContains('Missing Stock-Out', collect($payload['inventory_variance']['reason_breakdown'])->pluck('reason')->all());

        $encodedPayload = json_encode($payload);
        $this->assertIsString($encodedPayload);
        $this->assertStringNotContainsString('Sensitive AI Customer', $encodedPayload);
        $this->assertStringNotContainsString('sensitive@example.com', $encodedPayload);
        $this->assertStringNotContainsString('gsk_', $encodedPayload);

        Carbon::setTestNow();
    }

    public function test_ai_payload_returns_zero_structures_for_empty_data(): void
    {
        Carbon::setTestNow('2026-09-02 10:00:00');

        $payload = app(AIDataPreparationService::class)->prepare([
            'date_from' => '2024-01-01',
            'date_to' => '2024-01-31',
            'trend_period' => 'month',
            'trend_year' => 2024,
            'expected_year' => 2024,
        ]);

        $this->assertSame(0.0, $payload['revenue']['total_valid_sales']);
        $this->assertSame(0.0, $payload['revenue']['collected_revenue']);
        $this->assertSame(0.0, $payload['revenue']['outstanding_receivables']);
        $this->assertSame(0.0, $payload['inventory']['current_stock_liters']);
        $this->assertSame(0.0, $payload['fuel_lifting']['summary']['unlifted_liters']);
        $this->assertSame(0, $payload['inventory_variance']['summary']['transactions_checked']);
        $this->assertSame([], $payload['inventory_variance']['sample_variances']);

        Carbon::setTestNow();
    }

    public function test_ai_payload_validates_date_filters(): void
    {
        $this->expectException(ValidationException::class);

        app(AIDataPreparationService::class)->prepare([
            'date_from' => '2026-09-02',
            'date_to' => '2026-09-01',
        ]);
    }

    public function test_non_admin_user_cannot_prepare_company_wide_ai_payload(): void
    {
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active']);

        $this->expectException(AuthorizationException::class);

        app(AIDataPreparationService::class)->prepareForUser($driver);
    }

    /**
     * @return array<string, mixed>
     */
    private function records(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active']);
        $inventoryOfficer = User::factory()->create(['role' => 'inventory_officer', 'status' => 'active']);
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active']);
        $depotId = DB::table('depots')->insertGetId([
            'depot_code' => 'DPT-AI',
            'name' => 'AI Depot',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $garageId = DB::table('storage_locations')->insertGetId([
            'location_code' => 'GAR-AI',
            'name' => 'AI Garage',
            'type' => 'garage',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $dieselId = DB::table('fuel_types')->insertGetId([
            'code' => 'DSL-AI',
            'name' => 'AI Diesel',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $e10Id = DB::table('fuel_types')->insertGetId([
            'code' => 'E10-AI',
            'name' => 'AI E10',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-AI',
            'plate_number' => 'AI-1234',
            'capacity_liters' => 10000,
            'truck_type' => 'mixed',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-AI',
            'name' => 'Sensitive AI Customer',
            'company_name' => 'Sensitive AI Customer Co.',
            'email' => 'sensitive@example.com',
            'payment_status' => 'partial',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('admin', 'salesOfficer', 'inventoryOfficer', 'driver', 'depotId', 'garageId', 'dieselId', 'e10Id', 'truckId', 'customerId');
    }

    /**
     * @param  array<string, mixed>  $records
     */
    private function businessData(array $records): void
    {
        $saleOneId = $this->sale($records, 'SLS-AI-ONE', '2026-09-02', 'confirmed');
        $saleOneDieselItemId = $this->saleItem($saleOneId, $records['dieselId'], 1000, 60);
        $this->saleItem($saleOneId, $records['e10Id'], 500, 70);
        $this->receivable($saleOneId, 'pending');
        $this->payment($records, $saleOneId, 'PAY-AI-ONE', 50000);

        $saleTwoId = $this->sale($records, 'SLS-AI-TWO', '2026-09-01', 'partially_paid', 'installment');
        $this->saleItem($saleTwoId, $records['dieselId'], 2000, 50);
        $this->receivable($saleTwoId, 'partial');
        $scheduleId = DB::table('payment_schedules')->insertGetId([
            'sale_id' => $saleTwoId,
            'due_date' => '2026-09-30',
            'amount_due' => 100000,
            'status' => 'partial',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->payment($records, $saleTwoId, 'PAY-AI-TWO', 25000, $scheduleId);

        $oldSaleId = $this->sale($records, 'SLS-AI-OLD', '2025-12-31', 'confirmed');
        $this->saleItem($oldSaleId, $records['dieselId'], 999, 1);
        $this->receivable($oldSaleId, 'pending');

        $stockOutId = DB::table('stock_outs')->insertGetId([
            'stock_out_code' => 'STO-AI-ONE',
            'sale_id' => $saleOneId,
            'sale_item_id' => $saleOneDieselItemId,
            'customer_id' => $records['customerId'],
            'fuel_type_id' => $records['dieselId'],
            'storage_location_id' => $records['garageId'],
            'quantity_liters' => 1000,
            'stock_out_at' => '2026-09-02 09:00:00',
            'status' => 'released',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventory_movements')->insert([
            $this->inventoryMovement($records, 'MOV-AI-DIESEL-IN', $records['dieselId'], 'in', 10000, '2026-09-01 08:00:00', 'beginning', 0),
            $this->inventoryMovement($records, 'MOV-AI-E10-IN', $records['e10Id'], 'in', 5000, '2026-09-01 08:30:00', 'beginning', 0),
            $this->inventoryMovement($records, 'MOV-AI-DIESEL-OUT', $records['dieselId'], 'out', 1000, '2026-09-02 09:05:00', 'stock_out', $stockOutId),
        ]);

        [$partialPurchaseId, $partialItemId] = $this->purchaseItem($records, 'PUR-AI-PARTIAL', $records['dieselId'], 10000);
        [$unliftedPurchaseId, $unliftedItemId] = $this->purchaseItem($records, 'PUR-AI-UNLIFTED', $records['e10Id'], 8000);
        [$fullPurchaseId, $fullItemId] = $this->purchaseItem($records, 'PUR-AI-FULL', $records['dieselId'], 5000);

        $this->haul($records, $partialPurchaseId, $partialItemId, 'LFT-AI-PARTIAL', 4000, 'completed');
        $this->haul($records, $fullPurchaseId, $fullItemId, 'LFT-AI-FULL', 5000, 'completed');
        $this->haul($records, $unliftedPurchaseId, $unliftedItemId, 'LFT-AI-IGNORED', 3000, 'scheduled');
    }

    /**
     * @param  array<string, mixed>  $records
     */
    private function sale(array $records, string $code, string $date, string $status, string $terms = 'cod'): int
    {
        return DB::table('sales')->insertGetId([
            'sale_code' => $code,
            'customer_id' => $records['customerId'],
            'sale_date' => $date,
            'payment_method' => 'bank_transfer',
            'payment_terms' => $terms,
            'status' => $status,
            'created_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function saleItem(int $saleId, int $fuelTypeId, float $quantity, float $unitPrice): int
    {
        return DB::table('sale_items')->insertGetId([
            'sale_id' => $saleId,
            'fuel_type_id' => $fuelTypeId,
            'quantity_liters' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $quantity * $unitPrice,
            'fulfilled_quantity_liters' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function receivable(int $saleId, string $status): void
    {
        DB::table('receivables')->insert([
            'sale_id' => $saleId,
            'due_date' => '2026-09-30',
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $records
     */
    private function payment(array $records, int $saleId, string $code, float $amount, ?int $scheduleId = null): void
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
     * @param  array<string, mixed>  $records
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

    /**
     * @param  array<string, mixed>  $records
     * @return array{0: int, 1: int}
     */
    private function purchaseItem(array $records, string $code, int $fuelTypeId, float $quantity): array
    {
        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => $code,
            'depot_id' => $records['depotId'],
            'purchase_date' => '2026-09-01',
            'payment_status' => 'paid',
            'status' => 'ordered',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $purchaseItemId = DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchaseId,
            'fuel_type_id' => $fuelTypeId,
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
     * @param  array<string, mixed>  $records
     */
    private function haul(array $records, int $purchaseId, int $purchaseItemId, string $code, float $quantity, string $status): void
    {
        DB::table('hauls')->insert([
            'haul_code' => $code,
            'purchase_id' => $purchaseId,
            'purchase_item_id' => $purchaseItemId,
            'depot_id' => $records['depotId'],
            'fuel_type_id' => $records['dieselId'],
            'truck_id' => $records['truckId'],
            'driver_user_id' => $records['driver']->id,
            'dr_number' => $code.'-DR',
            'scheduled_at' => '2026-09-01 08:00:00',
            'hauled_at' => $status === 'completed' ? '2026-09-01 10:00:00' : null,
            'source_location' => 'AI Depot Rack',
            'quantity_liters' => $quantity,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
