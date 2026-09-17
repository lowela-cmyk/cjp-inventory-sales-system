<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\OperationalDataRepairService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase3OperationalAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_repair_data_service_and_command_execute_idempotently(): void
    {
        $repairService = app(OperationalDataRepairService::class);

        // Run repair service
        $result = $repairService->run();
        $this->assertIsArray($result);
        $this->assertSame(0, $result['reconciliation']['sales_missing_receivables']);
        $this->assertSame(0, $result['reconciliation']['overpaid_sales']);
        $this->assertSame(0, $result['reconciliation']['over_fulfilled_items']);
        $this->assertSame(0, $result['reconciliation']['unfulfilled_paid_sales']);
        $this->assertSame(0, $result['reconciliation']['released_without_movement']);
        $this->assertSame(0, $result['reconciliation']['negative_inventory_balances']);

        // Run artisan command
        $exitCode = Artisan::call('cjp:repair-data');
        $this->assertSame(0, $exitCode);

        // Run readiness audit in strict turnover mode
        $auditExitCode = Artisan::call('cjp:readiness-audit', ['--turnover' => true]);
        $this->assertSame(0, $auditExitCode);
    }

    public function test_legacy_sale_is_reconciled_with_proper_movement_and_stock_out(): void
    {
        $repairService = app(OperationalDataRepairService::class);
        $repairService->seedMasterData();

        $salesOfficer = DB::table('users')->where('role', 'sales_officer')->first();
        $customer = DB::table('customers')->first();
        $dieselFuel = DB::table('fuel_types')->where('code', 'DSL')->first();

        // Create legacy unfulfilled paid sale
        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => 'SLS-000001',
            'customer_id' => $customer->id,
            'sale_date' => '2026-09-01',
            'payment_method' => 'cash_on_delivery',
            'payment_terms' => 'cod',
            'status' => 'paid',
            'created_by' => $salesOfficer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemId = DB::table('sale_items')->insertGetId([
            'sale_id' => $saleId,
            'fuel_type_id' => $dieselFuel->id,
            'quantity_liters' => 50.00,
            'unit_price' => 78.00,
            'line_total' => 3900.00,
            'fulfilled_quantity_liters' => 0.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payments')->insert([
            'payment_code' => 'PAY-000001',
            'sale_id' => $saleId,
            'payment_date' => '2026-09-01',
            'amount' => 3900.00,
            'method' => 'cash_on_delivery',
            'received_by' => $salesOfficer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $repairService = app(OperationalDataRepairService::class);
        $repaired = $repairService->repairLegacySale();

        $this->assertTrue($repaired);

        // Assert item is now fulfilled
        $this->assertDatabaseHas('sale_items', [
            'id' => $itemId,
            'fulfilled_quantity_liters' => '50.00',
        ]);

        // Assert stock out exists and is released
        $this->assertDatabaseHas('stock_outs', [
            'sale_id' => $saleId,
            'sale_item_id' => $itemId,
            'status' => 'released',
            'quantity_liters' => '50.00',
        ]);

        // Assert inventory movement exists
        $this->assertDatabaseHas('inventory_movements', [
            'fuel_type_id' => $dieselFuel->id,
            'movement_type' => 'stock_out',
            'direction' => 'out',
            'quantity_liters' => '50.00',
        ]);
    }

    public function test_all_four_approved_fuels_have_reconciled_acceptance_workflows(): void
    {
        $repairService = app(OperationalDataRepairService::class);
        $repairService->seedMasterData();
        $workflows = $repairService->seedOperationalAcceptanceData();

        $this->assertCount(4, $workflows);
        $this->assertArrayHasKey('DSL', $workflows);
        $this->assertArrayHasKey('UNL', $workflows);
        $this->assertArrayHasKey('PREM', $workflows);
        $this->assertArrayHasKey('F1', $workflows);

        foreach (['DSL', 'UNL', 'PREM', 'F1'] as $fuelCode) {
            $fuelId = DB::table('fuel_types')->where('code', $fuelCode)->value('id');
            $this->assertNotNull($fuelId);

            // Purchase ordered == hauled
            $purchase = DB::table('purchase_items')->where('fuel_type_id', $fuelId)->first();
            $this->assertNotNull($purchase);
            $this->assertEquals($purchase->quantity_ordered_liters, $purchase->quantity_hauled_liters);
            $this->assertSame('lifted', $purchase->status);

            // Hauls are completed
            $haul = DB::table('hauls')->where('fuel_type_id', $fuelId)->first();
            $this->assertNotNull($haul);
            $this->assertSame('completed', $haul->status);

            // Haul allocations exist and match haul quantity
            $allocTotal = DB::table('haul_allocations')
                ->where('haul_id', $haul->id)
                ->where('status', '!=', 'cancelled')
                ->sum('quantity_liters');
            $this->assertEquals((float) $haul->quantity_liters, (float) $allocTotal);

            // Sales are paid and fulfilled
            $saleItem = DB::table('sale_items')->where('fuel_type_id', $fuelId)->first();
            $this->assertNotNull($saleItem);
            $this->assertEquals($saleItem->quantity_liters, $saleItem->fulfilled_quantity_liters);

            // Stock out exists and is released
            $stockOut = DB::table('stock_outs')->where('sale_item_id', $saleItem->id)->first();
            $this->assertNotNull($stockOut);
            $this->assertSame('released', $stockOut->status);
            $this->assertEquals($saleItem->quantity_liters, $stockOut->quantity_liters);

            // Receivable is clear
            $receivable = DB::table('receivables')->where('sale_id', $saleItem->sale_id)->first();
            $this->assertNotNull($receivable);
            $this->assertSame('clear', $receivable->status);
        }

        // Direct depot allocation check for UNL
        $directAllocation = DB::table('haul_allocations')
            ->where('destination_type', 'customer')
            ->first();
        $this->assertNotNull($directAllocation);
        $this->assertSame('delivered', $directAllocation->status);

        // Direct depot stock-out has no garage movement
        $directStockOut = DB::table('stock_outs')
            ->where('source_type', 'depot')
            ->where('haul_allocation_id', $directAllocation->id)
            ->first();
        $this->assertNotNull($directStockOut);
        $this->assertNull($directStockOut->inventory_movement_id);
        $this->assertNull($directStockOut->storage_location_id);
    }

    public function test_authenticated_http_workflows_cover_garage_and_direct_depot_fulfillment(): void
    {
        $repairService = app(OperationalDataRepairService::class);
        $repairService->seedMasterData();

        $inventoryOfficer = User::where('role', 'inventory_officer')->first();
        $salesOfficer = User::where('role', 'sales_officer')->first();
        $dispatchOfficer = User::where('role', 'dispatch_officer')->first();
        $driverUser = User::where('role', 'driver')->first();

        $depot = DB::table('depots')->where('status', 'active')->first();
        $truck = DB::table('trucks')->where('status', 'available')->first();
        $customer = DB::table('customers')->where('status', 'active')->first();
        $diesel = DB::table('fuel_types')->where('code', 'DSL')->first();
        $tank = DB::table('storage_locations')->where('fuel_type_id', $diesel->id)->where('type', 'garage')->first();

        // 1. HTTP Purchase
        $this->actingAs($inventoryOfficer)
            ->post(route('inventory-officer.inventory.purchases.store'), [
                'purchase_date' => '2026-09-15',
                'depot_id' => $depot->id,
                'fuel_type_id' => $diesel->id,
                'quantity_ordered_liters' => '20000',
                'unit_cost' => '48.50',
                'payment_status' => 'paid',
                'status' => 'ordered',
            ])
            ->assertRedirect(route('inventory-officer.inventory'));

        $purchaseItem = DB::table('purchase_items')->where('unit_cost', '48.50')->first();
        $this->assertNotNull($purchaseItem);

        // 2. HTTP Haul Lifting
        $this->actingAs($dispatchOfficer)
            ->post(route('dispatch.fuel-lifting.hauls.store'), [
                'idempotency_key' => (string) Str::uuid(),
                'purchase_item_id' => $purchaseItem->id,
                'driver_user_id' => $driverUser->id,
                'truck_id' => $truck->id,
                'dr_number' => 'DR-HTTP-TEST-001',
                'scheduled_at' => '2026-09-15 10:00:00',
                'quantity_liters' => '20000',
            ])
            ->assertRedirect(route('dispatch.fuel-lifting'));

        $haul = DB::table('hauls')->where('purchase_item_id', $purchaseItem->id)->first();
        $this->assertNotNull($haul);

        // Transition haul to completed
        foreach (['in_transit', 'lifted', 'completed'] as $status) {
            $this->actingAs($dispatchOfficer)
                ->patch(route('dispatch.fuel-lifting.hauls.status', $haul->id), [
                    'idempotency_key' => (string) Str::uuid(),
                    'status' => $status,
                ])
                ->assertRedirect(route('dispatch.fuel-lifting'));
        }

        // Allocate to Garage
        $allocationId = DB::table('haul_allocations')->insertGetId([
            'haul_id' => $haul->id,
            'fuel_type_id' => $diesel->id,
            'destination_type' => 'garage',
            'storage_location_id' => $tank->id,
            'customer_id' => null,
            'sale_id' => null,
            'quantity_liters' => 20000.00,
            'allocated_at' => now(),
            'status' => 'delivered',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. HTTP Stock-In
        $this->actingAs($inventoryOfficer)
            ->post(route('inventory-officer.inventory.stock-in.store'), [
                'haul_allocation_id' => $allocationId,
                'storage_location_id' => $tank->id,
                'quantity_liters' => '20000',
                'movement_date' => '2026-09-15 14:00:00',
            ])
            ->assertRedirect(route('inventory-officer.inventory.stock-in'));

        $this->assertDatabaseHas('haul_allocations', [
            'id' => $allocationId,
            'status' => 'received',
        ]);

        // 4. HTTP Sale Creation with Auto Garage Stock-Out
        $this->actingAs($salesOfficer)
            ->post(route('sales-officer.sales.store'), [
                'idempotency_key' => (string) Str::uuid(),
                'customer_id' => $customer->id,
                'sale_date' => '2026-09-15',
                'payment_method' => 'bank_transfer',
                'payment_terms' => 'cod',
                'status' => 'confirmed',
                'items' => [
                    [
                        'fuel_type_id' => $diesel->id,
                        'quantity_liters' => '5000',
                        'unit_price' => '58.00',
                    ],
                ],
            ])
            ->assertRedirect(route('sales-officer.sales'));

        $sale = DB::table('sales')->where('created_by', $salesOfficer->id)->orderByDesc('id')->first();
        $this->assertNotNull($sale);

        // Released garage stock-out and inventory movement created automatically
        $this->assertDatabaseHas('stock_outs', [
            'sale_id' => $sale->id,
            'source_type' => 'garage',
            'status' => 'released',
            'quantity_liters' => '5000.00',
        ]);

        $this->assertDatabaseHas('inventory_movements', [
            'storage_location_id' => $tank->id,
            'movement_type' => 'stock_out',
            'direction' => 'out',
            'quantity_liters' => '5000.00',
        ]);

        // 5. HTTP Payment
        $this->actingAs($salesOfficer)
            ->post(route('sales-officer.sales.payments.store', $sale->id), [
                'idempotency_key' => (string) Str::uuid(),
                'payment_date' => '2026-09-15',
                'amount' => '290000.00',
                'method' => 'bank_transfer',
                'reference_number' => 'BNK-HTTP-TEST-001',
            ])
            ->assertRedirect(route('sales-officer.sales'));

        $this->assertDatabaseHas('receivables', [
            'sale_id' => $sale->id,
            'status' => 'clear',
        ]);

        $this->assertDatabaseHas('sales', [
            'id' => $sale->id,
            'status' => 'paid',
        ]);
    }
}
