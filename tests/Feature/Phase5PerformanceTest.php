<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PurchaseService;
use App\Services\StockInService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Phase5PerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_monitoring_purchase_batch_loading_prevents_n_plus_one_queries(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        $depotId = DB::table('depots')->insertGetId([
            'depot_code' => 'DEP-BATCH',
            'name' => 'Batch Depot',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fuelTypeId = DB::table('fuel_types')->where('code', 'DSL')->value('id');

        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-BATCH-1',
            'plate_number' => 'ABC-1234',
            'capacity_liters' => 50000,
            'truck_type' => 'hauling',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create 25 purchases, each with a haul having a withdrawal receipt
        for ($i = 1; $i <= 25; $i++) {
            $purchaseId = DB::table('purchases')->insertGetId([
                'purchase_code' => sprintf('PUR-BATCH-%03d', $i),
                'depot_id' => $depotId,
                'purchase_date' => '2026-08-20',
                'payment_status' => 'unpaid',
                'status' => 'ordered',
                'created_by' => $admin->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $purchaseItemId = DB::table('purchase_items')->insertGetId([
                'purchase_id' => $purchaseId,
                'fuel_type_id' => $fuelTypeId,
                'quantity_ordered_liters' => 10000,
                'unit_cost' => 50,
                'line_total' => 500000,
                'quantity_hauled_liters' => 10000,
                'status' => 'lifted',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('hauls')->insert([
                'haul_code' => sprintf('HAUL-BATCH-%03d', $i),
                'purchase_id' => $purchaseId,
                'purchase_item_id' => $purchaseItemId,
                'depot_id' => $depotId,
                'fuel_type_id' => $fuelTypeId,
                'truck_id' => $truckId,
                'driver_user_id' => $admin->id,
                'scheduled_at' => now(),
                'quantity_liters' => 10000,
                'withdrawal_receipt_path' => 'receipts/batch-'.$i.'.pdf',
                'withdrawal_receipt_uploaded_at' => now(),
                'status' => 'completed',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($admin)
            ->get(route('admin.inventory'));

        $response->assertOk();

        $queries = DB::getQueryLog();

        // Ensure NO per-row individual queries exist (N+1 query check)
        $individualHaulQueries = collect($queries)->filter(function (array $query): bool {
            return (bool) preg_match('/where\s+[`"]?purchase_item_id[`"]?\s*=\s*\?/i', $query['query']);
        });

        $this->assertCount(0, $individualHaulQueries, 'Withdrawal receipts must be batch loaded in a single query rather than individual per-item queries.');

        // Batch queries to hauls table remain constant regardless of item count
        $haulQueries = collect($queries)->filter(function (array $query): bool {
            $sql = strtolower($query['query']);

            return str_contains($sql, 'from `hauls`') || str_contains($sql, 'from "hauls"');
        });

        $this->assertLessThanOrEqual(5, $haulQueries->count());
    }

    public function test_inventory_officer_purchases_are_paginated_at_twenty_five_per_page(): void
    {
        $officer = User::factory()->create([
            'role' => 'inventory_officer',
            'status' => 'active',
        ]);

        $depotId = DB::table('depots')->insertGetId([
            'depot_code' => 'DEP-PAGE',
            'name' => 'Pagination Depot',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fuelTypeId = DB::table('fuel_types')->where('code', 'DSL')->value('id');

        // Create 30 purchases
        for ($i = 1; $i <= 30; $i++) {
            $purchaseId = DB::table('purchases')->insertGetId([
                'purchase_code' => sprintf('PUR-PAGE-%03d', $i),
                'depot_id' => $depotId,
                'purchase_date' => now()->subDays(30 - $i)->toDateString(),
                'payment_status' => 'unpaid',
                'status' => 'ordered',
                'created_by' => $officer->id,
                'created_at' => now()->subDays(30 - $i),
                'updated_at' => now()->subDays(30 - $i),
            ]);

            DB::table('purchase_items')->insert([
                'purchase_id' => $purchaseId,
                'fuel_type_id' => $fuelTypeId,
                'quantity_ordered_liters' => 1000,
                'unit_cost' => 50,
                'line_total' => 50000,
                'quantity_hauled_liters' => 0,
                'status' => 'unlifted',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Page 1 should contain the latest 25 (PUR-PAGE-030 down to PUR-PAGE-006)
        $responsePage1 = $this->actingAs($officer)
            ->get(route('inventory-officer.inventory', ['purchases_page' => 1]));

        $responsePage1->assertOk()
            ->assertSee('PUR-PAGE-030')
            ->assertDontSee('PUR-PAGE-005');

        // Page 2 should contain the remaining 5 (PUR-PAGE-005 down to PUR-PAGE-001)
        $responsePage2 = $this->actingAs($officer)
            ->get(route('inventory-officer.inventory', ['purchases_page' => 2]));

        $responsePage2->assertOk()
            ->assertSee('PUR-PAGE-001')
            ->assertDontSee('PUR-PAGE-030');
    }

    public function test_inventory_officer_stock_in_is_paginated(): void
    {
        $officer = User::factory()->create([
            'role' => 'inventory_officer',
            'status' => 'active',
        ]);

        $garageId = DB::table('storage_locations')->insertGetId([
            'location_code' => 'GAR-PAGE-TEST',
            'name' => 'Pagination Garage',
            'type' => 'garage',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $depotId = DB::table('depots')->insertGetId([
            'depot_code' => 'DEP-PAGE-SI',
            'name' => 'Stock In Depot',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fuelTypeId = DB::table('fuel_types')->where('code', 'DSL')->value('id');

        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-PAGE-SI',
            'plate_number' => 'XYZ-5678',
            'capacity_liters' => 50000,
            'truck_type' => 'hauling',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => 'PUR-PAGE-SI-1',
            'depot_id' => $depotId,
            'purchase_date' => '2026-08-20',
            'payment_status' => 'paid',
            'status' => 'ordered',
            'created_by' => $officer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $purchaseItemId = DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchaseId,
            'fuel_type_id' => $fuelTypeId,
            'quantity_ordered_liters' => 300000,
            'unit_cost' => 50,
            'line_total' => 15000000,
            'quantity_hauled_liters' => 300000,
            'status' => 'lifted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $haulId = DB::table('hauls')->insertGetId([
            'haul_code' => 'HAUL-PAGE-SI-1',
            'purchase_id' => $purchaseId,
            'purchase_item_id' => $purchaseItemId,
            'depot_id' => $depotId,
            'fuel_type_id' => $fuelTypeId,
            'truck_id' => $truckId,
            'driver_user_id' => $officer->id,
            'scheduled_at' => now(),
            'quantity_liters' => 300000,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        for ($i = 1; $i <= 30; $i++) {
            $allocationId = DB::table('haul_allocations')->insertGetId([
                'haul_id' => $haulId,
                'fuel_type_id' => $fuelTypeId,
                'destination_type' => 'garage',
                'storage_location_id' => $garageId,
                'quantity_liters' => 1000,
                'status' => 'received',
                'created_at' => now()->subDays(30 - $i),
                'updated_at' => now()->subDays(30 - $i),
            ]);

            DB::table('inventory_movements')->insert([
                'movement_code' => sprintf('MOV-PAGE-%03d', $i),
                'storage_location_id' => $garageId,
                'fuel_type_id' => $fuelTypeId,
                'direction' => 'in',
                'movement_type' => 'stock_in',
                'quantity_liters' => 1000,
                'unit_cost' => 50,
                'reference_type' => 'haul_allocation',
                'reference_id' => $allocationId,
                'movement_date' => now()->subDays(30 - $i)->toDateTimeString(),
                'created_by' => $officer->id,
                'created_at' => now()->subDays(30 - $i),
                'updated_at' => now()->subDays(30 - $i),
            ]);
        }

        $page1 = $this->actingAs($officer)
            ->get(route('inventory-officer.inventory.stock-in', ['stock_in_page' => 1]));

        $page1->assertOk()
            ->assertSee('MOV-PAGE-030')
            ->assertDontSee('MOV-PAGE-005');

        $page2 = $this->actingAs($officer)
            ->get(route('inventory-officer.inventory.stock-in', ['stock_in_page' => 2]));

        $page2->assertOk()
            ->assertSee('MOV-PAGE-001')
            ->assertDontSee('MOV-PAGE-030');
    }

    public function test_sales_officer_sales_and_customers_are_paginated(): void
    {
        $salesOfficer = User::factory()->create([
            'role' => 'sales_officer',
            'status' => 'active',
        ]);

        $fuelTypeId = DB::table('fuel_types')->where('code', 'DSL')->value('id');

        for ($i = 1; $i <= 30; $i++) {
            $customerId = DB::table('customers')->insertGetId([
                'customer_code' => sprintf('CUST-PAGE-%03d', $i),
                'name' => sprintf('Customer Page %03d', $i),
                'company_name' => sprintf('Company %03d', $i),
                'status' => 'active',
                'payment_status' => 'clear',
                'created_at' => now()->subDays(30 - $i),
                'updated_at' => now()->subDays(30 - $i),
            ]);

            $saleId = DB::table('sales')->insertGetId([
                'sale_code' => sprintf('SALE-PAGE-%03d', $i),
                'sales_order_number' => sprintf('SO-PAGE-%03d', $i),
                'customer_id' => $customerId,
                'sale_date' => now()->subDays(30 - $i)->toDateString(),
                'payment_method' => 'cash_on_delivery',
                'status' => 'confirmed',
                'created_by' => $salesOfficer->id,
                'created_at' => now()->subDays(30 - $i),
                'updated_at' => now()->subDays(30 - $i),
            ]);

            DB::table('sale_items')->insert([
                'sale_id' => $saleId,
                'fuel_type_id' => $fuelTypeId,
                'quantity_liters' => 500,
                'unit_price' => 60,
                'line_total' => 30000,
                'fulfilled_quantity_liters' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('receivables')->insert([
                'sale_id' => $saleId,
                'due_date' => now()->addDays(15)->toDateString(),
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Test Sales Pagination
        $salesPage1 = $this->actingAs($salesOfficer)
            ->get(route('sales-officer.sales', ['sales_page' => 1]));

        $salesPage1->assertOk()
            ->assertSee('SALE-PAGE-030')
            ->assertDontSee('SALE-PAGE-005');

        $salesPage2 = $this->actingAs($salesOfficer)
            ->get(route('sales-officer.sales', ['sales_page' => 2]));

        $salesPage2->assertOk()
            ->assertSee('SALE-PAGE-001')
            ->assertDontSee('SALE-PAGE-030');

        // Test Customer Pagination
        $customersPage1 = $this->actingAs($salesOfficer)
            ->get(route('sales-officer.sales.customers', ['customers_page' => 1]));

        $customersPage1->assertOk()
            ->assertSee('CUST-PAGE-030')
            ->assertDontSee('CUST-PAGE-005');

        $customersPage2 = $this->actingAs($salesOfficer)
            ->get(route('sales-officer.sales.customers', ['customers_page' => 2]));

        $customersPage2->assertOk()
            ->assertSee('CUST-PAGE-001')
            ->assertDontSee('CUST-PAGE-030');
    }

    public function test_admin_user_management_pagination_and_full_export(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'status' => 'active',
        ]);

        for ($i = 1; $i <= 30; $i++) {
            $driver = User::factory()->create([
                'name' => sprintf('Driver Page %03d', $i),
                'email' => sprintf('driver%03d@test.example', $i),
                'role' => 'driver',
                'status' => 'active',
            ]);

            DB::table('driver_profiles')->insert([
                'user_id' => $driver->id,
                'driver_code' => sprintf('DRV-PAGE-%03d', $i),
                'license_number' => sprintf('LIC-%03d', $i),
                'status' => 'available',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Page 1 should not contain all drivers
        $responsePage1 = $this->actingAs($admin)
            ->get(route('admin.user-management', ['tab' => 'drivers', 'drivers_page' => 1]));

        $responsePage1->assertOk();

        // Page 2 should contain the remaining drivers
        $responsePage2 = $this->actingAs($admin)
            ->get(route('admin.user-management', ['tab' => 'drivers', 'drivers_page' => 2]));

        $responsePage2->assertOk();

        // Export should contain ALL 30 drivers regardless of pagination
        $exportResponse = $this->actingAs($admin)
            ->get(route('admin.user-management.export', ['tab' => 'drivers']));

        $exportResponse->assertOk();
        $content = $exportResponse->getContent();

        $this->assertStringContainsString('DRV-PAGE-001', $content);
        $this->assertStringContainsString('DRV-PAGE-030', $content);
    }

    public function test_purchase_service_methods_directly(): void
    {
        $officer = User::factory()->create([
            'role' => 'inventory_officer',
            'status' => 'active',
        ]);

        $depotId = DB::table('depots')->insertGetId([
            'depot_code' => 'DEP-DIRECT',
            'name' => 'Direct Test Depot',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fuelTypeId = DB::table('fuel_types')->where('code', 'DSL')->value('id');

        $purchaseService = app(PurchaseService::class);

        // Test Store
        $purchaseId = $purchaseService->createPurchase([
            'purchase_date' => '2026-08-30',
            'depot_id' => $depotId,
            'fuel_type_id' => $fuelTypeId,
            'quantity_ordered_liters' => '10000',
            'unit_cost' => '50.00',
            'payment_status' => 'unpaid',
            'status' => 'ordered',
        ], $officer->id);

        $this->assertIsInt($purchaseId);

        $purchase = DB::table('purchases')->where('id', $purchaseId)->first();
        $this->assertNotNull($purchase);

        $item = DB::table('purchase_items')->where('purchase_id', $purchase->id)->first();
        $this->assertSame(500000.0, (float) $item->line_total);

        // Test Update
        $purchaseService->updatePurchase($purchase->id, [
            'purchase_date' => '2026-08-31',
            'depot_id' => $depotId,
            'fuel_type_id' => $fuelTypeId,
            'quantity_ordered_liters' => '12000',
            'unit_cost' => '50.00',
            'payment_status' => 'partial',
            'status' => 'ordered',
        ]);

        $updatedItem = DB::table('purchase_items')->where('purchase_id', $purchase->id)->first();
        $this->assertSame(600000.0, (float) $updatedItem->line_total);

        // Test Cancel
        $purchaseService->cancelPurchase($purchase->id);

        $cancelledPurchase = DB::table('purchases')->where('id', $purchase->id)->first();
        $this->assertSame('cancelled', $cancelledPurchase->status);
    }

    public function test_stock_in_service_validates_garage_destination(): void
    {
        $officer = User::factory()->create([
            'role' => 'inventory_officer',
            'status' => 'active',
        ]);

        $garageId = DB::table('storage_locations')->insertGetId([
            'location_code' => 'GAR-SI-DIR',
            'name' => 'Direct Garage',
            'type' => 'garage',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $depotId = DB::table('depots')->insertGetId([
            'depot_code' => 'DEP-SI-DIR',
            'name' => 'Direct SI Depot',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fuelTypeId = DB::table('fuel_types')->where('code', 'DSL')->value('id');

        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-SI-DIR',
            'plate_number' => 'DIR-1234',
            'capacity_liters' => 50000,
            'truck_type' => 'hauling',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => 'PUR-SI-DIRECT',
            'depot_id' => $depotId,
            'purchase_date' => '2026-08-20',
            'payment_status' => 'paid',
            'status' => 'ordered',
            'created_by' => $officer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $purchaseItemId = DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchaseId,
            'fuel_type_id' => $fuelTypeId,
            'quantity_ordered_liters' => 10000,
            'unit_cost' => 50,
            'line_total' => 500000,
            'quantity_hauled_liters' => 10000,
            'status' => 'lifted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $haulId = DB::table('hauls')->insertGetId([
            'haul_code' => 'HAUL-SI-DIRECT',
            'purchase_id' => $purchaseId,
            'purchase_item_id' => $purchaseItemId,
            'depot_id' => $depotId,
            'fuel_type_id' => $fuelTypeId,
            'truck_id' => $truckId,
            'driver_user_id' => $officer->id,
            'scheduled_at' => now(),
            'quantity_liters' => 10000,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUST-SI-DIR',
            'name' => 'Direct Customer',
            'company_name' => 'Customer Co',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $allocationId = DB::table('haul_allocations')->insertGetId([
            'haul_id' => $haulId,
            'fuel_type_id' => $fuelTypeId,
            'destination_type' => 'customer', // not garage
            'customer_id' => $customerId,
            'quantity_liters' => 5000,
            'status' => 'planned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $stockInService = app(StockInService::class);

        $error = $stockInService->recordStockIn([
            'haul_allocation_id' => $allocationId,
            'storage_location_id' => $garageId,
            'quantity_liters' => 5000,
            'movement_date' => now()->toDateTimeString(),
            'remarks' => 'Testing validation',
        ], $officer->id);

        $this->assertSame('The selected stock-in source is invalid.', $error);
    }
}
