<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DashboardSummaryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CompleteWorkflowIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_complete_garage_workflow_links_records_and_keeps_inventory_and_receivables_separate(): void
    {
        Carbon::setTestNow('2026-08-31 08:00:00');
        $records = $this->baseRecords();

        $purchase = $this->createPurchaseThroughWorkflow($records, 40000);
        $this->assertSame(0.0, $this->garageBalance($records));

        $haulId = $this->haul($records, $purchase, 40000);
        $allocationId = $this->allocation($records, $haulId, 40000);

        foreach (['in_transit', 'lifted', 'completed'] as $status) {
            $this->actingAs($records['dispatchOfficer'])
                ->patch(route('dispatch.fuel-lifting.hauls.status', $haulId), $this->statusPayload($status))
                ->assertRedirect(route('dispatch.fuel-lifting'));
        }

        $this->assertDatabaseHas('purchase_items', [
            'id' => $purchase['purchaseItemId'],
            'quantity_hauled_liters' => '40000.00',
            'status' => 'lifted',
        ]);
        $this->assertDatabaseHas('purchases', ['id' => $purchase['purchaseId'], 'status' => 'hauled']);
        $this->assertSame(0.0, $this->garageBalance($records));

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-in.store'), $this->stockInPayload($records, $allocationId, [
                'quantity_liters' => 30000,
            ]))
            ->assertRedirect(route('inventory-officer.inventory.stock-in'));

        $this->actingAs($records['inventoryOfficer'])
            ->from(route('inventory-officer.inventory.stock-in'))
            ->post(route('inventory-officer.inventory.stock-in.store'), $this->stockInPayload($records, $allocationId, [
                'quantity_liters' => 30000,
            ]))
            ->assertRedirect(route('inventory-officer.inventory.stock-in'))
            ->assertSessionHasErrors('stock_in');

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-in.store'), $this->stockInPayload($records, $allocationId, [
                'quantity_liters' => 10000,
                'movement_date' => '2026-08-31 13:00:00',
            ]))
            ->assertRedirect(route('inventory-officer.inventory.stock-in'));

        $this->assertSame(40000.0, $this->garageBalance($records));
        $this->assertSame(2, DB::table('inventory_movements')->where('movement_type', 'stock_in')->count());
        $this->assertDatabaseHas('haul_allocations', ['id' => $allocationId, 'status' => 'received']);

        $sale = $this->createSaleThroughWorkflow($records, [
            'sale_code' => 'SLS-WORKFLOW-GARAGE',
            'quantity_liters' => 25000,
            'unit_price' => 4,
            'payment_method' => 'bank_transfer',
            'due_date' => '2026-09-30',
        ]);
        $this->assertSame(40000.0, $this->garageBalance($records));
        $this->assertDatabaseHas('receivables', ['sale_id' => $sale['saleId'], 'status' => 'pending']);
        $this->assertSame(0, DB::table('payments')->where('sale_id', $sale['saleId'])->count());

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-out.store'), $this->stockOutPayload($records, $sale, [
                'quantity_liters' => 10000,
            ]))
            ->assertRedirect(route('inventory-officer.inventory.stock-out'));

        $this->actingAs($records['inventoryOfficer'])
            ->from(route('inventory-officer.inventory.stock-out'))
            ->post(route('inventory-officer.inventory.stock-out.store'), $this->stockOutPayload($records, $sale, [
                'quantity_liters' => 10000,
            ]))
            ->assertRedirect(route('inventory-officer.inventory.stock-out'))
            ->assertSessionHasErrors('stock_out');

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-out.store'), $this->stockOutPayload($records, $sale, [
                'quantity_liters' => 15000,
                'stock_out_at' => '2026-08-31 15:00:00',
            ]))
            ->assertRedirect(route('inventory-officer.inventory.stock-out'));

        $this->assertSame(15000.0, $this->garageBalance($records));
        $this->assertSame(2, DB::table('stock_outs')->where('sale_id', $sale['saleId'])->count());
        $this->assertSame(2, DB::table('deliveries')->where('sale_id', $sale['saleId'])->where('source_type', 'garage')->count());
        $this->assertDatabaseHas('sale_items', [
            'id' => $sale['saleItemId'],
            'fulfilled_quantity_liters' => '25000.00',
        ]);

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.payments.store', $sale['saleId']), $this->paymentPayload([
                'amount' => 40000,
                'method' => 'bank_transfer',
                'reference_number' => 'BNK-WORKFLOW-001',
            ]))
            ->assertRedirect(route('sales-officer.sales'));

        $this->actingAs($records['salesOfficer'])
            ->from(route('sales-officer.sales'))
            ->post(route('sales-officer.sales.payments.store', $sale['saleId']), $this->paymentPayload([
                'amount' => 40000,
                'method' => 'bank_transfer',
                'reference_number' => 'BNK-WORKFLOW-001',
            ]))
            ->assertRedirect(route('sales-officer.sales'))
            ->assertSessionHasErrors('payment');

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.payments.store', $sale['saleId']), $this->paymentPayload([
                'amount' => 60000,
                'payment_date' => '2026-09-01',
                'method' => 'cheque',
                'reference_number' => 'CHK-WORKFLOW-002',
            ]))
            ->assertRedirect(route('sales-officer.sales'));

        $this->assertSame(100000.0, $this->paidTotal($sale['saleId']));
        $this->assertDatabaseHas('sales', ['id' => $sale['saleId'], 'status' => 'paid']);
        $this->assertDatabaseHas('receivables', ['sale_id' => $sale['saleId'], 'status' => 'clear']);
        $this->assertSame(15000.0, $this->garageBalance($records));

        $summary = app(DashboardSummaryService::class);
        $stockRow = collect($summary->stockLevels()['rows'])->firstWhere('fuel_type_id', $records['fuelTypeId']);
        $this->assertSame(15000.0, $stockRow['liters']);
        $this->assertSame(0.0, $summary->receivablesMonitoring()['totalOutstanding']);

        Carbon::setTestNow();
    }

    public function test_direct_depot_to_client_workflow_bypasses_garage_inventory(): void
    {
        Carbon::setTestNow('2026-08-31 08:00:00');
        $records = $this->baseRecords();
        $purchase = $this->createPurchaseThroughWorkflow($records, 18000);
        $sale = $this->createSaleThroughWorkflow($records, [
            'sale_code' => 'SLS-WORKFLOW-DIRECT',
            'quantity_liters' => 18000,
            'unit_price' => 5,
            'payment_method' => 'cash_on_delivery',
        ]);
        $haulId = $this->haul($records, $purchase, 18000);
        $allocationId = $this->allocation($records, $haulId, 18000, [
            'destination_type' => 'customer',
            'customer_id' => $records['customerId'],
            'sale_id' => $sale['saleId'],
            'storage_location_id' => null,
        ]);

        foreach (['in_transit', 'lifted', 'completed'] as $status) {
            $this->actingAs($records['dispatchOfficer'])
                ->patch(route('dispatch.fuel-lifting.hauls.status', $haulId), $this->statusPayload($status))
                ->assertRedirect(route('dispatch.fuel-lifting'));
        }

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-out.store'), $this->stockOutPayload($records, $sale, [
                'source_type' => 'depot',
                'storage_location_id' => null,
                'haul_allocation_id' => $allocationId,
                'quantity_liters' => 18000,
            ]))
            ->assertRedirect(route('inventory-officer.inventory.stock-out'));

        $this->assertSame(0.0, $this->garageBalance($records));
        $this->assertSame(0, DB::table('inventory_movements')->count());
        $this->assertSame(0, DB::table('stock_outs')->count());
        $this->assertDatabaseHas('deliveries', [
            'sale_id' => $sale['saleId'],
            'haul_allocation_id' => $allocationId,
            'source_type' => 'depot',
            'actual_quantity_liters' => '18000.00',
            'status' => 'delivered',
        ]);
        $this->assertDatabaseHas('haul_allocations', ['id' => $allocationId, 'status' => 'delivered']);
        $this->assertDatabaseHas('sale_items', [
            'id' => $sale['saleItemId'],
            'fulfilled_quantity_liters' => '18000.00',
        ]);

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.payments.store', $sale['saleId']), $this->paymentPayload([
                'amount' => 90000,
                'method' => 'cash_on_delivery',
            ]))
            ->assertRedirect(route('sales-officer.sales'));

        $this->assertDatabaseHas('receivables', ['sale_id' => $sale['saleId'], 'status' => 'clear']);
        $this->assertSame(0.0, $this->garageBalance($records));
        $this->assertSame(0, DB::table('inventory_movements')->count());

        Carbon::setTestNow();
    }

    public function test_invalid_and_cancelled_workflow_steps_do_not_create_downstream_effects(): void
    {
        $records = $this->baseRecords();
        $purchase = $this->createPurchaseThroughWorkflow($records, 10000);
        DB::table('purchases')->where('id', $purchase['purchaseId'])->update(['status' => 'cancelled']);
        $haulId = $this->haul($records, $purchase, 10000);
        $allocationId = $this->allocation($records, $haulId, 10000);
        $cancelledSale = $this->createSaleThroughWorkflow($records, [
            'sale_code' => 'SLS-WORKFLOW-CANCELLED',
            'quantity_liters' => 5000,
            'unit_price' => 5,
            'status' => 'cancelled',
        ]);

        $this->actingAs($records['dispatchOfficer'])
            ->from(route('dispatch.fuel-lifting'))
            ->patch(route('dispatch.fuel-lifting.hauls.status', $haulId), $this->statusPayload('in_transit'))
            ->assertRedirect(route('dispatch.fuel-lifting'))
            ->assertSessionHasErrors('lifting');

        $this->actingAs($records['inventoryOfficer'])
            ->from(route('inventory-officer.inventory.stock-in'))
            ->post(route('inventory-officer.inventory.stock-in.store'), $this->stockInPayload($records, $allocationId))
            ->assertRedirect(route('inventory-officer.inventory.stock-in'))
            ->assertSessionHasErrors('stock_in');

        $this->actingAs($records['inventoryOfficer'])
            ->from(route('inventory-officer.inventory.stock-out'))
            ->post(route('inventory-officer.inventory.stock-out.store'), $this->stockOutPayload($records, $cancelledSale))
            ->assertRedirect(route('inventory-officer.inventory.stock-out'))
            ->assertSessionHasErrors('stock_out');

        $this->actingAs($records['salesOfficer'])
            ->from(route('sales-officer.sales'))
            ->post(route('sales-officer.sales.payments.store', $cancelledSale['saleId']), $this->paymentPayload([
                'amount' => 25000,
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
        $inventoryOfficer = User::factory()->create(['role' => 'inventory_officer', 'status' => 'active']);
        $dispatchOfficer = User::factory()->create(['role' => 'dispatch_officer', 'status' => 'active']);
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active']);
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active']);
        $depotId = DB::table('depots')->insertGetId([
            'depot_code' => 'DEP-WORKFLOW',
            'name' => 'Workflow Depot',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $garageId = DB::table('storage_locations')->insertGetId([
            'location_code' => 'GAR-WORKFLOW',
            'name' => 'Workflow Garage',
            'type' => 'garage',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $fuelTypeId = DB::table('fuel_types')->insertGetId([
            'code' => 'DSL-WORKFLOW',
            'name' => 'Workflow Diesel',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-WORKFLOW',
            'name' => 'Workflow Customer',
            'company_name' => 'Workflow Customer Co.',
            'payment_status' => 'clear',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-WORKFLOW',
            'capacity_liters' => 50000,
            'truck_type' => 'mixed',
            'status' => 'assigned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('inventoryOfficer', 'dispatchOfficer', 'salesOfficer', 'driver', 'depotId', 'garageId', 'fuelTypeId', 'customerId', 'truckId');
    }

    /**
     * @param array<string, mixed> $records
     * @return array{purchaseId: int, purchaseItemId: int}
     */
    private function createPurchaseThroughWorkflow(array $records, float $quantity): array
    {
        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.purchases.store'), [
                'purchase_date' => '2026-08-31',
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

        return [
            'purchaseId' => (int) $purchase->id,
            'purchaseItemId' => (int) $purchaseItem->id,
        ];
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
            'scheduled_at' => '2026-08-31 09:00:00',
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
            'allocated_at' => '2026-08-31 10:00:00',
            'status' => 'delivered',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $records
     * @param array<string, mixed> $overrides
     * @return array{saleId: int, saleItemId: int}
     */
    private function createSaleThroughWorkflow(array $records, array $overrides = []): array
    {
        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.store'), array_merge([
                'idempotency_key' => (string) Str::uuid(),
                'sale_code' => 'SLS-'.Str::upper(Str::random(8)),
                'customer_id' => $records['customerId'],
                'sale_date' => '2026-08-31',
                'fuel_type_id' => $records['fuelTypeId'],
                'quantity_liters' => 10000,
                'unit_price' => 5,
                'payment_method' => 'cash_on_delivery',
                'payment_terms' => 'cod',
            ], $overrides))
            ->assertRedirect(route('sales-officer.sales'));

        $sale = DB::table('sales')->where('sale_code', $overrides['sale_code'])->first()
            ?? DB::table('sales')->latest('id')->first();
        $saleItem = DB::table('sale_items')->where('sale_id', $sale->id)->first();

        return [
            'saleId' => (int) $sale->id,
            'saleItemId' => (int) $saleItem->id,
        ];
    }

    /**
     * @param array<string, mixed> $records
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function stockInPayload(array $records, int $allocationId, array $overrides = []): array
    {
        return array_filter(array_merge([
            'haul_allocation_id' => $allocationId,
            'storage_location_id' => $records['garageId'],
            'quantity_liters' => 10000,
            'movement_date' => '2026-08-31 12:00:00',
            'remarks' => 'Workflow receipt',
        ], $overrides), fn (mixed $value): bool => $value !== null);
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
            'stock_out_at' => '2026-08-31 14:00:00',
            'remarks' => 'Workflow release',
        ], $overrides), fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function paymentPayload(array $overrides = []): array
    {
        return array_filter(array_merge([
            'idempotency_key' => (string) Str::uuid(),
            'payment_date' => '2026-08-31',
            'amount' => 10000,
            'method' => 'cash_on_delivery',
            'reference_number' => null,
            'remarks' => 'Workflow payment',
        ], $overrides), fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, string>
     */
    private function statusPayload(string $status): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'status' => $status,
        ];
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
