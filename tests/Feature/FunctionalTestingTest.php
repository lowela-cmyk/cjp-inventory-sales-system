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

    public function test_customer_sale_stock_out_payment_success_and_failed_overpayment(): void
    {
        // Payments are dated 2026-09-04 with a 2026-09-30 due date: freeze "today" so the receivable is
        // partial, not overdue, whenever the suite runs.
        Carbon::setTestNow('2026-09-04 09:00:00');
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
                'sales_order_number' => 'SO-FUNC-001',
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

        $sale = DB::table('sales')->where('sales_order_number', 'SO-FUNC-001')->first();

        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'delivery_status' => 'pending']);
        $this->assertSame(0, DB::table('stock_outs')->where('sale_id', $sale->id)->count());

        $this->actingAs($records['salesOfficer'])
            ->patch(route('sales-officer.sales.delivery-status.update', $sale->id), ['delivery_status' => 'delivered'])
            ->assertRedirect(route('sales-officer.sales'));

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
            // The overpayment error is shown under the Amount field (key "amount", since commit 5003805).
            ->assertSessionHasErrors(['amount' => 'Amount paid cannot exceed the remaining balance of PHP 80,000.00.']);

        $this->assertSame(100000.0, round((float) DB::table('payments')->where('sale_id', $sale->id)->sum('amount'), 2));
        $this->assertDatabaseHas('receivables', ['sale_id' => $sale->id, 'status' => 'partial']);
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
            // 6,000 L purchased - 2,000 L delivered.
            ->assertSeeInOrder(['Total Inventory in Stock', '4.00 KL'])
            ->assertSeeInOrder(['Total Delivered', '2.00 KL'])
            ->assertSeeInOrder(['Unpaid Amount', 'PHP 100,000.00']);

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
            ->post(route('admin.dashboard.insight'), [
                'sales_date_from' => '2024-01-31',
                'sales_date_to' => '2024-01-01',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('sales_date_to');

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
        $fuelTypeId = (int) (DB::table('fuel_types')->where('code', 'DSL')->value('id') ?? DB::table('fuel_types')->insertGetId([
            'code' => 'DSL',
            'name' => 'DIESEL',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]));
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
     * @param  array<string, mixed>  $records
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
     * @param  array<string, mixed>  $records
     * @param  array{purchaseId: int, purchaseItemId: int}  $purchase
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
     * @param  array<string, mixed>  $records
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
     * @param  array<string, mixed>  $records
     */
    /** Part 2 revisions: the stock is a purchase of the fuel. */
    private ?int $fixturePurchase = null;

    private function seedGarageStock(array $records, float $quantity): void
    {
        $this->fixturePurchase = $this->stockLot((int) $records['fuelTypeId'], $quantity);
    }

    /**
     * @param  array<string, mixed>  $records
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
            'purchase_item_id' => $this->fixturePurchase,
            'quantity_liters' => $quantity,
            'main_quantity_liters' => $quantity,
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
     * @param  array<string, mixed>  $records
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
            // Deducted from the fixture purchase at its depot.
            'source_type' => 'depot',
            'portion' => 'main',
            'purchase_item_id' => $this->fixturePurchase,
            'depot_id' => DB::table('purchase_items')->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')->where('purchase_items.id', $this->fixturePurchase)->value('purchases.depot_id'),
            'quantity_liters' => $quantity,
            'stock_out_at' => '2026-09-04 10:00:00',
            'status' => 'released',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
