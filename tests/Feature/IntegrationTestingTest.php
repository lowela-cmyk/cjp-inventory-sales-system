<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AIDataPreparationService;
use App\Services\DashboardSummaryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class IntegrationTestingTest extends TestCase
{
    use RefreshDatabase;

    public function test_end_to_end_arbitrary_allocation_workflow_feeds_dashboard_analytics_and_ai_reports(): void
    {
        Carbon::setTestNow('2026-09-04 08:00:00');
        Config::set('services.ai.api_key', 'test-key');
        Config::set('services.ai.provider', 'groq');
        Config::set('services.ai.base_url', 'https://api.groq.test/openai/v1');
        Http::fake([
            'api.groq.test/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'Integrated analytics show controlled receivables and separated depot inventory.']],
                ],
            ]),
        ]);

        $records = $this->baseRecords();
        $purchase = $this->createPurchase($records, 100000);
        $this->assertSame(0.0, $this->garageBalance($records), 'Purchase alone must not increase garage stock.');

        $garageSale = $this->createSale($records, [
            'sale_code' => 'SLS-INT-GARAGE',
            'quantity_liters' => 40000,
            'unit_price' => 5,
            'payment_method' => 'bank_transfer',
            'payment_terms' => 'cod',
            'due_date' => '2026-09-30',
        ]);
        $directSale = $this->createSale($records, [
            'sale_code' => 'SLS-INT-DIRECT',
            'quantity_liters' => 35000,
            'unit_price' => 6,
            'payment_method' => 'cash_on_delivery',
            'payment_terms' => 'cod',
            'due_date' => '2026-09-30',
        ]);

        $this->assertSame(0.0, $this->garageBalance($records), 'Sale alone must not deduct garage stock.');
        $this->assertSame(0, DB::table('payments')->whereIn('sale_id', [$garageSale['saleId'], $directSale['saleId']])->count(), 'Payment method alone is not a payment.');

        $haulId = $this->haul($records, $purchase, 100000);
        $garageAllocationId = $this->allocation($records, $haulId, 65000, [
            'destination_type' => 'garage',
            'storage_location_id' => $records['garageId'],
        ]);
        $directAllocationId = $this->allocation($records, $haulId, 35000, [
            'destination_type' => 'customer',
            'storage_location_id' => null,
            'customer_id' => $records['customerId'],
            'sale_id' => $directSale['saleId'],
        ]);

        $this->progressHaul($records, $haulId);
        $this->assertDatabaseHas('purchase_items', [
            'id' => $purchase['purchaseItemId'],
            'quantity_hauled_liters' => '100000.00',
            'status' => 'lifted',
        ]);

        $this->stockIn($records, $garageAllocationId, 25000, '2026-09-04 11:00:00');
        $this->stockIn($records, $garageAllocationId, 40000, '2026-09-04 12:00:00');
        $this->assertSame(65000.0, $this->garageBalance($records));
        $this->assertDatabaseHas('haul_allocations', ['id' => $garageAllocationId, 'status' => 'received']);

        $firstStockOut = $this->stockOut($records, $garageSale, [
            'quantity_liters' => 15000,
            'stock_out_at' => '2026-09-04 13:00:00',
        ]);
        $this->actingAs($records['inventoryOfficer'])
            ->from(route('inventory-officer.inventory.stock-out'))
            ->post(route('inventory-officer.inventory.stock-out.store'), array_merge($firstStockOut, [
                'idempotency_key' => (string) Str::uuid(),
            ]))
            ->assertRedirect(route('inventory-officer.inventory.stock-out'))
            ->assertSessionHasErrors('stock_out');
        $this->stockOut($records, $garageSale, [
            'quantity_liters' => 25000,
            'stock_out_at' => '2026-09-04 14:00:00',
        ]);

        $this->assertSame(25000.0, $this->garageBalance($records));
        $this->assertSame(2, DB::table('stock_outs')->where('sale_id', $garageSale['saleId'])->count());
        $this->assertSame(2, DB::table('deliveries')->where('sale_id', $garageSale['saleId'])->where('source_type', 'garage')->count());
        $this->assertDatabaseHas('sale_items', [
            'id' => $garageSale['saleItemId'],
            'fulfilled_quantity_liters' => '40000.00',
        ]);

        $this->stockOut($records, $directSale, [
            'source_type' => 'depot',
            'storage_location_id' => null,
            'haul_allocation_id' => $directAllocationId,
            'quantity_liters' => 35000,
            'stock_out_at' => '2026-09-04 15:00:00',
        ]);

        $this->assertSame(25000.0, $this->garageBalance($records), 'Direct depot to client must bypass garage inventory.');
        $this->assertSame(0, DB::table('stock_outs')->where('sale_id', $directSale['saleId'])->count());
        $this->assertDatabaseHas('deliveries', [
            'sale_id' => $directSale['saleId'],
            'haul_allocation_id' => $directAllocationId,
            'source_type' => 'depot',
            'actual_quantity_liters' => '35000.00',
            'status' => 'delivered',
        ]);

        $firstPayment = $this->paymentPayload([
            'payment_date' => '2026-09-04',
            'amount' => 50000,
            'method' => 'bank_transfer',
            'reference_number' => 'BANK-INT-001',
        ]);
        $this->payment($records, $garageSale['saleId'], $firstPayment);
        $this->actingAs($records['salesOfficer'])
            ->from(route('sales-officer.sales'))
            ->post(route('sales-officer.sales.payments.store', $garageSale['saleId']), array_merge($firstPayment, [
                'idempotency_key' => (string) Str::uuid(),
            ]))
            ->assertRedirect(route('sales-officer.sales'))
            ->assertSessionHasErrors('payment');
        $this->payment($records, $garageSale['saleId'], $this->paymentPayload([
            'payment_date' => '2026-09-05',
            'amount' => 150000,
            'method' => 'cheque',
            'reference_number' => 'CHK-INT-002',
        ]));

        $this->assertSame(200000.0, $this->paidTotal($garageSale['saleId']));
        $this->assertSame(0.0, $this->paidTotal($directSale['saleId']));
        $this->assertDatabaseHas('receivables', ['sale_id' => $garageSale['saleId'], 'status' => 'clear']);
        $this->assertDatabaseHas('receivables', ['sale_id' => $directSale['saleId'], 'status' => 'pending']);

        /** @var DashboardSummaryService $summary */
        $summary = app(DashboardSummaryService::class);
        $this->assertSame(25000.0, $summary->totalInventoryLiters());
        $this->assertSame(210000.0, $summary->outstandingReceivables());
        $this->assertSame(410000.0, $summary->totalSalesRevenue());
        $this->assertSame(210000.0, $summary->receivablesMonitoring()['totalOutstanding']);

        $stockRows = collect($summary->stockLevels()['rows'])->keyBy('label');
        $this->assertSame(25000.0, $stockRows['Integration Diesel']['liters']);

        $variance = $summary->inventoryVarianceMonitoring();
        $this->assertSame(2, $variance['summary']['total_checked']);
        $this->assertSame(0, $variance['summary']['variance_count']);

        /** @var AIDataPreparationService $aiData */
        $aiData = app(AIDataPreparationService::class);
        $payload = $aiData->prepareForUser($records['admin'], [
            'date_from' => '2026-09-04',
            'date_to' => '2026-09-05',
            'trend_period' => 'month',
            'trend_year' => 2026,
            'expected_year' => 2026,
            'limit' => 5,
        ]);
        $this->assertSame(410000.0, $payload['revenue']['total_valid_sales']);
        $this->assertSame(200000.0, $payload['revenue']['collected_revenue']);
        $this->assertSame(210000.0, $payload['revenue']['outstanding_receivables']);
        $this->assertSame(25000.0, $payload['inventory']['current_stock_liters']);
        $this->assertStringContainsString('depot fuel pending lifting', $payload['inventory']['separation_note']);

        $this->actingAs($records['admin'])
            ->post(route('admin.reports.business-insight'), [
                'period' => 'range',
                'start_date' => '2026-09-04',
                'end_date' => '2026-09-05',
            ])
            ->assertRedirect(route('admin.reports', [
                'period' => 'range',
                'date' => '2026-09-04',
                'start_date' => '2026-09-04',
                'end_date' => '2026-09-05',
                'month' => '2026-09',
                'year' => '2026',
            ]))
            ->assertSessionHas('businessInsight');

        Http::assertSent(fn ($request): bool => str_contains((string) $request->url(), '/chat/completions')
            && str_contains(json_encode($request->data()) ?: '', '410000'));

        Carbon::setTestNow();
    }

    public function test_cancelled_invalid_and_duplicate_integration_steps_do_not_create_downstream_effects(): void
    {
        $records = $this->baseRecords();
        $purchase = $this->createPurchase($records, 20000);
        $haulId = $this->haul($records, $purchase, 20000);
        $allocationId = $this->allocation($records, $haulId, 20000, [
            'destination_type' => 'garage',
            'storage_location_id' => $records['garageId'],
        ]);
        $cancelledSale = $this->createSale($records, [
            'sale_code' => 'SLS-INT-CANCELLED',
            'quantity_liters' => 10000,
            'unit_price' => 5,
            'status' => 'cancelled',
        ]);

        DB::table('purchases')->where('id', $purchase['purchaseId'])->update(['status' => 'cancelled']);

        $this->actingAs($records['dispatchOfficer'])
            ->from(route('dispatch.fuel-lifting'))
            ->patch(route('dispatch.fuel-lifting.hauls.status', $haulId), [
                'idempotency_key' => (string) Str::uuid(),
                'status' => 'in_transit',
            ])
            ->assertRedirect(route('dispatch.fuel-lifting'))
            ->assertSessionHasErrors('lifting');

        DB::table('hauls')->where('id', $haulId)->update(['status' => 'completed']);
        DB::table('haul_allocations')->where('id', $allocationId)->update(['quantity_liters' => 30000]);

        $this->actingAs($records['inventoryOfficer'])
            ->from(route('inventory-officer.inventory.stock-in'))
            ->post(route('inventory-officer.inventory.stock-in.store'), [
                'haul_allocation_id' => $allocationId,
                'storage_location_id' => $records['garageId'],
                'quantity_liters' => 10000,
                'movement_date' => '2026-09-04 10:00:00',
            ])
            ->assertRedirect(route('inventory-officer.inventory.stock-in'))
            ->assertSessionHasErrors('stock_in');

        $this->actingAs($records['inventoryOfficer'])
            ->from(route('inventory-officer.inventory.stock-out'))
            ->post(route('inventory-officer.inventory.stock-out.store'), $this->stockOutPayload($records, $cancelledSale, [
                'quantity_liters' => 1000,
            ]))
            ->assertRedirect(route('inventory-officer.inventory.stock-out'))
            ->assertSessionHasErrors('stock_out');

        $this->actingAs($records['salesOfficer'])
            ->from(route('sales-officer.sales'))
            ->post(route('sales-officer.sales.payments.store', $cancelledSale['saleId']), $this->paymentPayload([
                'amount' => 50000,
            ]))
            ->assertRedirect(route('sales-officer.sales'))
            ->assertSessionHasErrors('payment');

        $this->assertSame(0, DB::table('inventory_movements')->count());
        $this->assertSame(0, DB::table('stock_outs')->count());
        $this->assertSame(0, DB::table('deliveries')->count());
        $this->assertSame(0, DB::table('payments')->count());
        $this->assertSame(0.0, $this->garageBalance($records));
    }

    /**
     * @return array<string, mixed>
     */
    private function baseRecords(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $inventoryOfficer = User::factory()->create(['role' => 'inventory_officer', 'status' => 'active']);
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active']);
        $dispatchOfficer = User::factory()->create(['role' => 'dispatch_officer', 'status' => 'active']);
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active']);
        $depotId = DB::table('depots')->insertGetId([
            'depot_code' => 'DEP-INT',
            'name' => 'Integration Depot',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $garageId = DB::table('storage_locations')->insertGetId([
            'location_code' => 'GAR-INT',
            'name' => 'Integration Garage',
            'type' => 'garage',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $fuelTypeId = DB::table('fuel_types')->insertGetId([
            'code' => 'DSL-INT',
            'name' => 'Integration Diesel',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-INT',
            'name' => 'Integration Customer',
            'company_name' => 'Integration Customer Co.',
            'payment_status' => 'clear',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-INT',
            'capacity_liters' => 120000,
            'truck_type' => 'mixed',
            'status' => 'assigned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('admin', 'inventoryOfficer', 'salesOfficer', 'dispatchOfficer', 'driver', 'depotId', 'garageId', 'fuelTypeId', 'customerId', 'truckId');
    }

    /**
     * @param array<string, mixed> $records
     * @return array{purchaseId: int, purchaseItemId: int}
     */
    private function createPurchase(array $records, float $quantity): array
    {
        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.purchases.store'), [
                'purchase_date' => '2026-09-04',
                'depot_id' => $records['depotId'],
                'fuel_type_id' => $records['fuelTypeId'],
                'quantity_ordered_liters' => $quantity,
                'unit_cost' => 50,
                'payment_status' => 'paid',
                'status' => 'ordered',
            ])
            ->assertRedirect(route('inventory-officer.inventory'));

        $purchase = DB::table('purchases')->latest('id')->first();
        $purchaseItem = DB::table('purchase_items')->where('purchase_id', $purchase->id)->first();

        return ['purchaseId' => (int) $purchase->id, 'purchaseItemId' => (int) $purchaseItem->id];
    }

    /**
     * @param array<string, mixed> $records
     * @param array<string, mixed> $overrides
     * @return array{saleId: int, saleItemId: int}
     */
    private function createSale(array $records, array $overrides = []): array
    {
        $payload = array_merge([
            'idempotency_key' => (string) Str::uuid(),
            'sale_code' => 'SLS-'.Str::upper(Str::random(8)),
            'customer_id' => $records['customerId'],
            'sale_date' => '2026-09-04',
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_liters' => 10000,
            'unit_price' => 5,
            'payment_method' => 'cash_on_delivery',
            'payment_terms' => 'cod',
            'status' => 'confirmed',
        ], $overrides);

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.store'), $payload)
            ->assertRedirect(route('sales-officer.sales'));

        $sale = DB::table('sales')->where('sale_code', $payload['sale_code'])->first();
        $saleItem = DB::table('sale_items')->where('sale_id', $sale->id)->first();

        return ['saleId' => (int) $sale->id, 'saleItemId' => (int) $saleItem->id];
    }

    /**
     * @param array<string, mixed> $records
     * @param array{purchaseId: int, purchaseItemId: int} $purchase
     */
    private function haul(array $records, array $purchase, float $quantity): int
    {
        return DB::table('hauls')->insertGetId([
            'haul_code' => 'LFT-'.Str::upper(Str::random(8)),
            'purchase_id' => $purchase['purchaseId'],
            'purchase_item_id' => $purchase['purchaseItemId'],
            'depot_id' => $records['depotId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'truck_id' => $records['truckId'],
            'driver_user_id' => $records['driver']->id,
            'scheduled_at' => '2026-09-04 09:00:00',
            'quantity_liters' => $quantity,
            'status' => 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $records
     * @param array<string, mixed> $overrides
     */
    private function allocation(array $records, int $haulId, float $quantity, array $overrides = []): int
    {
        return DB::table('haul_allocations')->insertGetId(array_merge([
            'haul_id' => $haulId,
            'fuel_type_id' => $records['fuelTypeId'],
            'destination_type' => 'garage',
            'storage_location_id' => $records['garageId'],
            'customer_id' => null,
            'sale_id' => null,
            'quantity_liters' => $quantity,
            'allocated_at' => '2026-09-04 09:30:00',
            'status' => 'delivered',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $records
     */
    private function progressHaul(array $records, int $haulId): void
    {
        foreach (['in_transit', 'lifted', 'completed'] as $status) {
            $this->actingAs($records['dispatchOfficer'])
                ->patch(route('dispatch.fuel-lifting.hauls.status', $haulId), [
                    'idempotency_key' => (string) Str::uuid(),
                    'status' => $status,
                ])
                ->assertRedirect(route('dispatch.fuel-lifting'));
        }
    }

    /**
     * @param array<string, mixed> $records
     */
    private function stockIn(array $records, int $allocationId, float $quantity, string $date): void
    {
        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-in.store'), [
                'haul_allocation_id' => $allocationId,
                'storage_location_id' => $records['garageId'],
                'quantity_liters' => $quantity,
                'movement_date' => $date,
                'remarks' => 'Integration stock-in',
            ])
            ->assertRedirect(route('inventory-officer.inventory.stock-in'));
    }

    /**
     * @param array<string, mixed> $records
     * @param array{saleId: int, saleItemId: int} $sale
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function stockOut(array $records, array $sale, array $overrides = []): array
    {
        $payload = $this->stockOutPayload($records, $sale, $overrides);

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-out.store'), $payload)
            ->assertRedirect(route('inventory-officer.inventory.stock-out'));

        return $payload;
    }

    /**
     * @param array<string, mixed> $records
     * @param array{saleId: int, saleItemId: int} $sale
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function stockOutPayload(array $records, array $sale, array $overrides = []): array
    {
        return array_filter(array_merge([
            'idempotency_key' => (string) Str::uuid(),
            'source_type' => 'garage',
            'sale_item_id' => $sale['saleItemId'],
            'storage_location_id' => $records['garageId'],
            'haul_allocation_id' => null,
            'quantity_liters' => 10000,
            'stock_out_at' => '2026-09-04 13:00:00',
            'remarks' => 'Integration stock-out',
        ], $overrides), fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $records
     * @param array<string, mixed> $payload
     */
    private function payment(array $records, int $saleId, array $payload): void
    {
        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.payments.store', $saleId), $payload)
            ->assertRedirect(route('sales-officer.sales'));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function paymentPayload(array $overrides = []): array
    {
        return array_filter(array_merge([
            'idempotency_key' => (string) Str::uuid(),
            'payment_date' => '2026-09-04',
            'amount' => 10000,
            'method' => 'cash_on_delivery',
            'reference_number' => null,
            'remarks' => 'Integration payment',
        ], $overrides), fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $records
     */
    private function garageBalance(array $records): float
    {
        $incoming = (float) DB::table('inventory_movements')
            ->where('storage_location_id', $records['garageId'])
            ->where('fuel_type_id', $records['fuelTypeId'])
            ->where('direction', 'in')
            ->sum('quantity_liters');
        $outgoing = (float) DB::table('inventory_movements')
            ->where('storage_location_id', $records['garageId'])
            ->where('fuel_type_id', $records['fuelTypeId'])
            ->where('direction', 'out')
            ->sum('quantity_liters');

        return round($incoming - $outgoing, 2);
    }

    private function paidTotal(int $saleId): float
    {
        return round((float) DB::table('payments')->where('sale_id', $saleId)->sum('amount'), 2);
    }
}
