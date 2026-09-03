<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class FunctionalTestingTest extends TestCase
{
    use RefreshDatabase;

    public function test_authentication_login_failure_success_and_logout_flow(): void
    {
        $user = User::factory()->create([
            'email' => 'functional-admin@example.com',
            'role' => 'admin',
            'status' => 'active',
            'password' => 'password',
        ]);

        $this->post(route('login.store'), [
            'username' => $user->email,
            'role' => 'admin',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('username');
        $this->assertGuest();

        $this->post(route('login.store'), [
            'username' => $user->email,
            'role' => 'admin',
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_major_role_pages_are_accessible_to_their_assigned_users(): void
    {
        $records = $this->baseRecords();

        $pages = [
            'admin' => [
                route('admin.dashboard'),
                route('admin.inventory'),
                route('admin.ledger'),
                route('admin.fuel-lifting'),
                route('admin.sales'),
                route('admin.reports'),
                route('admin.alerts'),
                route('admin.user-management'),
            ],
            'inventoryOfficer' => [
                route('inventory-officer.inventory'),
                route('inventory-officer.inventory.stock-in'),
                route('inventory-officer.inventory.stock-out'),
                route('inventory-officer.ledger'),
                route('inventory-officer.ledger.transactions'),
                route('inventory-officer.alerts'),
            ],
            'salesOfficer' => [
                route('sales-officer.sales'),
                route('sales-officer.sales.customers'),
                route('sales-officer.alerts'),
            ],
            'dispatchOfficer' => [
                route('dispatch.fuel-lifting'),
                route('dispatch.fuel-lifting.hauled'),
                route('dispatch.ledger'),
                route('dispatch.alerts'),
            ],
            'driver' => [
                route('driver.assigned-deliveries'),
                route('driver.assigned-deliveries.completed'),
                route('driver.fuel-lifting'),
                route('driver.fuel-lifting.hauled'),
                route('driver.fuel-lifting.no-schedule'),
                route('driver.fuel-lifting.no-hauled'),
            ],
        ];

        foreach ($pages as $userKey => $urls) {
            foreach ($urls as $url) {
                $this->actingAs($records[$userKey])
                    ->get($url)
                    ->assertOk();
            }
        }
    }

    public function test_admin_user_management_success_and_validation_failure(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin)
            ->post(route('admin.user-management.staff.store'), [
                'name' => 'Functional Inventory Officer',
                'email' => 'functional-inventory@example.com',
                'phone' => '09170000001',
                'role' => 'inventory_officer',
                'status' => 'active',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertRedirect(route('admin.user-management', ['tab' => 'office']));

        $this->assertDatabaseHas('users', [
            'email' => 'functional-inventory@example.com',
            'role' => 'inventory_officer',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.user-management.staff.store'), [
                'name' => '',
                'email' => 'functional-inventory@example.com',
                'role' => 'driver',
                'status' => 'blocked',
                'password' => 'short',
                'password_confirmation' => 'different',
            ])
            ->assertSessionHasErrors(['name', 'email', 'role', 'status', 'password']);
    }

    public function test_purchase_stock_in_inventory_success_and_failed_over_receipt(): void
    {
        $records = $this->baseRecords();
        $purchase = $this->purchase($records, 8000);
        $haulId = $this->haul($records, $purchase, 8000, 'completed');
        $allocationId = $this->garageAllocation($records, $haulId, 8000);

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-in.store'), [
                'haul_allocation_id' => $allocationId,
                'storage_location_id' => $records['garageId'],
                'quantity_liters' => 5000,
                'movement_date' => '2026-09-04 08:00:00',
                'remarks' => 'Functional stock-in',
            ])
            ->assertRedirect(route('inventory-officer.inventory.stock-in'));

        $this->assertDatabaseHas('inventory_movements', [
            'reference_id' => $allocationId,
            'movement_type' => 'stock_in',
            'direction' => 'in',
            'quantity_liters' => '5000.00',
        ]);

        $this->actingAs($records['inventoryOfficer'])
            ->from(route('inventory-officer.inventory.stock-in'))
            ->post(route('inventory-officer.inventory.stock-in.store'), [
                'haul_allocation_id' => $allocationId,
                'storage_location_id' => $records['garageId'],
                'quantity_liters' => 4000,
                'movement_date' => '2026-09-04 09:00:00',
            ])
            ->assertRedirect(route('inventory-officer.inventory.stock-in'))
            ->assertSessionHasErrors('stock_in');

        $this->assertSame(1, DB::table('inventory_movements')->count());
    }

    public function test_customer_sale_stock_out_payment_success_and_failed_overpayment(): void
    {
        $records = $this->baseRecords();
        $this->seedGarageStock($records, 12000);

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.customers.store'), [
                'name' => 'Functional Buyer',
                'company_name' => 'Functional Buyer Co.',
                'location' => 'Batangas City',
                'email' => 'buyer@example.test',
                'phone' => '09170000002',
                'payment_status' => 'clear',
                'status' => 'active',
            ])
            ->assertRedirect(route('sales-officer.sales.customers'));

        $customerId = (int) DB::table('customers')->where('company_name', 'Functional Buyer Co.')->value('id');

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.store'), [
                'idempotency_key' => (string) Str::uuid(),
                'sale_code' => 'SLS-FUNC-001',
                'customer_id' => $customerId,
                'sale_date' => '2026-09-04',
                'fuel_type_id' => $records['fuelTypeId'],
                'quantity_liters' => 3000,
                'unit_price' => 60,
                'payment_method' => 'bank_transfer',
                'payment_terms' => 'installment',
                'due_date' => '2026-09-30',
            ])
            ->assertRedirect(route('sales-officer.sales'));

        $sale = DB::table('sales')->where('sale_code', 'SLS-FUNC-001')->first();
        $saleItemId = (int) DB::table('sale_items')->where('sale_id', $sale->id)->value('id');

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-out.store'), [
                'idempotency_key' => (string) Str::uuid(),
                'source_type' => 'garage',
                'sale_item_id' => $saleItemId,
                'storage_location_id' => $records['garageId'],
                'quantity_liters' => 3000,
                'stock_out_at' => '2026-09-04 10:00:00',
                'remarks' => 'Functional stock-out',
            ])
            ->assertRedirect(route('inventory-officer.inventory.stock-out'));

        $this->assertDatabaseHas('stock_outs', [
            'sale_id' => $sale->id,
            'quantity_liters' => '3000.00',
            'status' => 'released',
        ]);

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.payments.store', $sale->id), [
                'idempotency_key' => (string) Str::uuid(),
                'payment_date' => '2026-09-04',
                'amount' => 100000,
                'method' => 'bank_transfer',
                'reference_number' => 'BNK-FUNC-001',
            ])
            ->assertRedirect(route('sales-officer.sales'));

        $this->actingAs($records['salesOfficer'])
            ->from(route('sales-officer.sales'))
            ->post(route('sales-officer.sales.payments.store', $sale->id), [
                'idempotency_key' => (string) Str::uuid(),
                'payment_date' => '2026-09-04',
                'amount' => 90000,
                'method' => 'bank_transfer',
                'reference_number' => 'BNK-FUNC-002',
            ])
            ->assertRedirect(route('sales-officer.sales'))
            ->assertSessionHasErrors('payment');

        $this->assertSame(100000.0, round((float) DB::table('payments')->where('sale_id', $sale->id)->sum('amount'), 2));
        $this->assertDatabaseHas('receivables', ['sale_id' => $sale->id, 'status' => 'partial']);
    }

    public function test_dispatch_and_driver_delivery_success_and_failed_invalid_status_update(): void
    {
        Carbon::setTestNow('2026-09-04 07:00:00');
        $records = $this->baseRecords();
        $this->seedGarageStock($records, 10000);
        $sale = $this->manualSale($records, 4000);
        $stockOutId = $this->manualStockOut($records, $sale['saleId'], $sale['saleItemId'], 4000);

        $this->actingAs($records['dispatchOfficer'])
            ->post(route('dispatch.fuel-lifting.deliveries.store'), [
                'idempotency_key' => (string) Str::uuid(),
                'source_type' => 'garage',
                'stock_out_id' => $stockOutId,
                'driver_user_id' => $records['driver']->id,
                'truck_id' => $records['truckId'],
                'scheduled_at' => '2026-09-05 08:00:00',
                'quantity_liters' => 4000,
            ])
            ->assertRedirect(route('dispatch.fuel-lifting'));

        $deliveryId = (int) DB::table('deliveries')->where('sale_id', $sale['saleId'])->value('id');

        $this->actingAs($records['driver'])
            ->patch(route('driver.assigned-deliveries.pickup', $deliveryId), [
                'idempotency_key' => (string) Str::uuid(),
            ])
            ->assertRedirect(route('driver.assigned-deliveries'));

        $this->actingAs($records['driver'])
            ->patch(route('driver.assigned-deliveries.status', $deliveryId), [
                'idempotency_key' => (string) Str::uuid(),
                'status' => 'delivered',
            ])
            ->assertRedirect(route('driver.assigned-deliveries'));

        $this->assertDatabaseHas('deliveries', [
            'id' => $deliveryId,
            'status' => 'delivered',
            'actual_quantity_liters' => '4000.00',
        ]);

        $this->actingAs($records['driver'])
            ->from(route('driver.assigned-deliveries'))
            ->patch(route('driver.assigned-deliveries.status', $deliveryId), [
                'idempotency_key' => (string) Str::uuid(),
                'status' => 'incomplete',
            ])
            ->assertRedirect(route('driver.assigned-deliveries'))
            ->assertSessionHasErrors('delivery');

        Carbon::setTestNow();
    }

    public function test_dashboard_reports_and_analytics_pages_render_real_system_totals(): void
    {
        $records = $this->baseRecords();
        $this->seedGarageStock($records, 6000);
        $sale = $this->manualSale($records, 2000);
        $this->manualStockOut($records, $sale['saleId'], $sale['saleItemId'], 2000);

        $this->actingAs($records['admin'])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('6 KL')
            ->assertSee('PHP 100,000');

        $this->actingAs($records['admin'])
            ->get(route('admin.reports', ['period' => 'date', 'date' => '2026-09-04']))
            ->assertOk()
            ->assertSee('Reports and A.I Insights')
            ->assertSee('PHP 100,000.00');

        $this->actingAs($records['admin'])
            ->get(route('admin.reports.export', ['period' => 'date', 'date' => '2026-09-04']))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_ai_reporting_feature_routes_validate_filters_and_do_not_call_provider_without_data(): void
    {
        Carbon::setTestNow('2026-09-04 07:00:00');
        Http::fake();
        config([
            'services.ai.provider' => 'groq',
            'services.ai.api_key' => 'test-key',
            'services.ai.model' => 'openai/gpt-oss-20b',
            'services.ai.base_url' => 'https://api.groq.com/openai/v1',
        ]);

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin)
            ->from(route('admin.reports'))
            ->post(route('admin.reports.revenue-insight'), [
                'period' => 'range',
                'start_date' => '2026-09-10',
                'end_date' => '2026-09-01',
            ])
            ->assertRedirect(route('admin.reports'))
            ->assertSessionHasErrors('end_date');

        $this->actingAs($admin)
            ->post(route('admin.reports.business-insight'), [
                'period' => 'date',
                'date' => '2024-01-01',
                'month' => '2024-01',
                'year' => '2024',
            ])
            ->assertRedirect()
            ->assertSessionHas('businessInsightNotice', 'Insufficient business data for AI insight generation.');

        $this->actingAs($admin)
            ->post(route('admin.dashboard.inventory-variance-explanation'), [
                'variance_date_from' => '2024-01-31',
                'variance_date_to' => '2024-01-01',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('variance_date_to');

        Http::assertNothingSent();
        Carbon::setTestNow();
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
            'depot_code' => 'DEP-FUNC',
            'name' => 'Functional Depot',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $garageId = DB::table('storage_locations')->insertGetId([
            'location_code' => 'GAR-FUNC',
            'name' => 'Functional Garage',
            'type' => 'garage',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $fuelTypeId = DB::table('fuel_types')->insertGetId([
            'code' => 'DSL-FUNC',
            'name' => 'Functional Diesel',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-FUNC',
            'name' => 'Functional Customer',
            'company_name' => 'Functional Customer Co.',
            'payment_status' => 'clear',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-FUNC',
            'capacity_liters' => 10000,
            'truck_type' => 'mixed',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('admin', 'inventoryOfficer', 'salesOfficer', 'dispatchOfficer', 'driver', 'depotId', 'garageId', 'fuelTypeId', 'customerId', 'truckId');
    }

    /**
     * @param array<string, mixed> $records
     * @return array{purchaseId: int, purchaseItemId: int}
     */
    private function purchase(array $records, float $quantity): array
    {
        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => 'PUR-FUNC-'.Str::upper(Str::random(5)),
            'depot_id' => $records['depotId'],
            'purchase_date' => '2026-09-04',
            'payment_status' => 'paid',
            'status' => 'hauled',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $purchaseItemId = DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchaseId,
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_ordered_liters' => $quantity,
            'unit_cost' => 50,
            'line_total' => $quantity * 50,
            'quantity_hauled_liters' => $quantity,
            'status' => 'lifted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('purchaseId', 'purchaseItemId');
    }

    /**
     * @param array<string, mixed> $records
     * @param array{purchaseId: int, purchaseItemId: int} $purchase
     */
    private function haul(array $records, array $purchase, float $quantity, string $status = 'scheduled'): int
    {
        return DB::table('hauls')->insertGetId([
            'haul_code' => 'LFT-FUNC-'.Str::upper(Str::random(5)),
            'purchase_id' => $purchase['purchaseId'],
            'purchase_item_id' => $purchase['purchaseItemId'],
            'depot_id' => $records['depotId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'truck_id' => $records['truckId'],
            'driver_user_id' => $records['driver']->id,
            'scheduled_at' => '2026-09-04 07:00:00',
            'hauled_at' => $status === 'completed' ? '2026-09-04 08:00:00' : null,
            'quantity_liters' => $quantity,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $records
     */
    private function garageAllocation(array $records, int $haulId, float $quantity): int
    {
        return DB::table('haul_allocations')->insertGetId([
            'haul_id' => $haulId,
            'fuel_type_id' => $records['fuelTypeId'],
            'destination_type' => 'garage',
            'storage_location_id' => $records['garageId'],
            'quantity_liters' => $quantity,
            'allocated_at' => '2026-09-04 08:00:00',
            'status' => 'delivered',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $records
     */
    private function seedGarageStock(array $records, float $quantity): void
    {
        DB::table('inventory_movements')->insert([
            'movement_code' => 'MOV-FUNC-'.Str::upper(Str::random(6)),
            'storage_location_id' => $records['garageId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'movement_type' => 'beginning',
            'direction' => 'in',
            'quantity_liters' => $quantity,
            'unit_cost' => 50,
            'reference_type' => 'functional-test',
            'reference_id' => 0,
            'movement_date' => '2026-09-04 06:00:00',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $records
     * @return array{saleId: int, saleItemId: int}
     */
    private function manualSale(array $records, float $quantity): array
    {
        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => 'SLS-FUNC-'.Str::upper(Str::random(6)),
            'customer_id' => $records['customerId'],
            'sale_date' => '2026-09-04',
            'payment_method' => 'bank_transfer',
            'payment_terms' => 'installment',
            'status' => 'confirmed',
            'created_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $saleItemId = DB::table('sale_items')->insertGetId([
            'sale_id' => $saleId,
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_liters' => $quantity,
            'unit_price' => 50,
            'line_total' => $quantity * 50,
            'fulfilled_quantity_liters' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('receivables')->insert([
            'sale_id' => $saleId,
            'due_date' => '2026-09-30',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('saleId', 'saleItemId');
    }

    /**
     * @param array<string, mixed> $records
     */
    private function manualStockOut(array $records, int $saleId, int $saleItemId, float $quantity): int
    {
        DB::table('sale_items')
            ->where('id', $saleItemId)
            ->update([
                'fulfilled_quantity_liters' => DB::raw('fulfilled_quantity_liters + '.$quantity),
                'updated_at' => now(),
            ]);

        return DB::table('stock_outs')->insertGetId([
            'stock_out_code' => 'STO-FUNC-'.Str::upper(Str::random(6)),
            'sale_id' => $saleId,
            'sale_item_id' => $saleItemId,
            'customer_id' => $records['customerId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'storage_location_id' => $records['garageId'],
            'quantity_liters' => $quantity,
            'stock_out_at' => '2026-09-04 10:00:00',
            'status' => 'released',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
