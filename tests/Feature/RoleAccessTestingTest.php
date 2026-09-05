<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class RoleAccessTestingTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_users_are_redirected_from_protected_write_and_sensitive_actions(): void
    {
        $records = $this->baseRecords();
        $sale = $this->sale($records, 1000, 50);
        $purchase = $this->purchase($records, 1000);
        $stockOutId = $this->stockOut($records, $sale, 1000);
        $allocationId = $this->garageAllocation($records, $purchase, 1000);
        $haulId = $this->haul($records, $purchase, 1000);

        foreach ($this->protectedWriteActions($records, $sale, $purchase, $stockOutId, $allocationId, $haulId) as $action) {
            $this->call($action['method'], $action['url'], $action['payload'])
                ->assertRedirect(route('login'));
        }

        $this->assertGuest();
        $this->assertSame(0, DB::table('payments')->count());
    }

    public function test_each_role_is_denied_direct_write_access_to_other_role_actions_without_side_effects(): void
    {
        $records = $this->baseRecords();
        $sale = $this->sale($records, 1000, 50);
        $purchase = $this->purchase($records, 1000);
        $stockOutId = $this->stockOut($records, $sale, 1000);
        $allocationId = $this->garageAllocation($records, $purchase, 1000);
        $haulId = $this->haul($records, $purchase, 1000);

        $before = $this->tableCounts();
        $roles = [
            'admin' => $records['admin'],
            'inventory_officer' => $records['inventoryOfficer'],
            'sales_officer' => $records['salesOfficer'],
            'dispatch_officer' => $records['dispatchOfficer'],
            'driver' => $records['driver'],
        ];

        foreach ($this->protectedWriteActions($records, $sale, $purchase, $stockOutId, $allocationId, $haulId) as $action) {
            foreach ($roles as $role => $user) {
                if (in_array($role, $action['allowed_roles'], true)) {
                    continue;
                }

                $this->actingAs($user)
                    ->call($action['method'], $action['url'], $action['payload'])
                    ->assertForbidden();
            }
        }

        $this->assertSame($before, $this->tableCounts());
    }

    public function test_admin_permissions_are_explicitly_limited_to_admin_and_admin_dispatch_routes(): void
    {
        Http::fake();
        $records = $this->baseRecords();
        $sale = $this->sale($records, 1000, 50);
        $purchase = $this->purchase($records, 1000);
        $haulId = $this->haul($records, $purchase, 1000);

        $this->actingAs($records['admin'])
            ->post(route('admin.user-management.staff.store'), [
                'name' => 'Admin Created Staff',
                'email' => 'admin-created-staff@example.test',
                'phone' => '09170000001',
                'role' => 'sales_officer',
                'status' => 'active',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertRedirect(route('admin.user-management', ['tab' => 'office']));

        $this->actingAs($records['admin'])
            ->post(route('admin.reports.business-insight'), ['period' => 'all'])
            ->assertRedirect();

        $this->actingAs($records['admin'])
            ->post(route('inventory-officer.inventory.purchases.store'), $this->purchasePayload($records))
            ->assertForbidden();

        $this->actingAs($records['admin'])
            ->post(route('sales-officer.sales.store'), $this->salePayload($records))
            ->assertForbidden();

        $this->actingAs($records['admin'])
            ->patch(route('driver.fuel-lifting.hauls.status', $haulId), [
                'idempotency_key' => (string) Str::uuid(),
                'lifting_status' => 'in_transit',
            ])
            ->assertForbidden();
    }

    public function test_request_and_url_role_tampering_cannot_bypass_backend_authorization(): void
    {
        $records = $this->baseRecords();

        $this->actingAs($records['driver'])
            ->get(route('admin.user-management', [
                'role' => 'admin',
                'redirect' => route('admin.user-management'),
            ]))
            ->assertForbidden()
            ->assertDontSee('Sensitive Admin Staff');

        $this->actingAs($records['salesOfficer'])
            ->post(route('admin.user-management.staff.store'), [
                'name' => 'Escalated User',
                'email' => 'escalated@example.test',
                'role' => 'admin',
                'status' => 'active',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'escalated@example.test']);

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('sales-officer.sales.store'), array_merge($this->salePayload($records), [
                'role' => 'sales_officer',
                'created_by' => $records['salesOfficer']->id,
                'redirect' => route('sales-officer.sales'),
            ]))
            ->assertForbidden();

        $this->assertSame(0, DB::table('sales')->count());
    }

    public function test_restricted_pages_do_not_expose_other_role_data_in_forbidden_responses(): void
    {
        $records = $this->baseRecords();
        $sale = $this->sale($records, 1000, 50, 'SLS-SECRET-RBAC');
        $this->stockOut($records, $sale, 1000);

        $this->actingAs($records['driver'])
            ->get(route('admin.sales'))
            ->assertForbidden()
            ->assertDontSee('SLS-SECRET-RBAC')
            ->assertDontSee('Sensitive Customer Co.');

        $this->actingAs($records['salesOfficer'])
            ->get(route('inventory-officer.inventory'))
            ->assertForbidden()
            ->assertDontSee('PUR-SECRET-RBAC');

        $this->actingAs($records['inventoryOfficer'])
            ->get(route('dispatch.fuel-lifting'))
            ->assertForbidden()
            ->assertDontSee('STO-SECRET-RBAC');
    }

    public function test_driver_direct_id_access_is_scoped_to_owned_lifting_tasks(): void
    {
        $records = $this->baseRecords();
        $otherDriver = User::factory()->create(['role' => 'driver', 'status' => 'active']);
        $purchase = $this->purchase($records, 1000);
        $ownedHaulId = $this->haul($records, $purchase, 500);
        $otherHaulId = $this->haul($records, $purchase, 500, ['driver_user_id' => $otherDriver->id]);

        $this->actingAs($records['driver'])
            ->patch(route('driver.fuel-lifting.hauls.status', $otherHaulId), [
                'idempotency_key' => (string) Str::uuid(),
                'lifting_status' => 'in_transit',
            ])
            ->assertSessionHasErrors(['lifting' => 'The selected lifting task is not assigned to your driver account.']);

        $this->assertDatabaseHas('hauls', ['id' => $ownedHaulId, 'driver_user_id' => $records['driver']->id]);
        $this->assertDatabaseHas('hauls', ['id' => $otherHaulId, 'status' => 'scheduled']);
    }

    public function test_inactive_authenticated_accounts_are_denied_by_role_middleware(): void
    {
        $inactiveAdmin = User::factory()->create(['role' => 'admin', 'status' => 'inactive']);
        $inactiveDriver = User::factory()->create(['role' => 'driver', 'status' => 'inactive']);

        $this->actingAs($inactiveAdmin)
            ->get(route('admin.dashboard'))
            ->assertForbidden();

        $this->assertGuest();

        $this->actingAs($inactiveDriver)
            ->get(route('driver.fuel-lifting'))
            ->assertForbidden();

        $this->assertGuest();
    }

    /**
     * @param array<string, mixed> $records
     * @param array{saleId: int, saleItemId: int} $sale
     * @param array{purchaseId: int, purchaseItemId: int} $purchase
     * @return array<int, array{method: string, url: string, payload: array<string, mixed>, allowed_roles: array<int, string>}>
     */
    private function protectedWriteActions(array $records, array $sale, array $purchase, int $stockOutId, int $allocationId, int $haulId): array
    {
        return [
            [
                'method' => 'POST',
                'url' => route('admin.user-management.staff.store'),
                'payload' => [
                    'name' => 'RBAC Staff',
                    'email' => 'rbac-staff@example.test',
                    'role' => 'inventory_officer',
                    'status' => 'active',
                    'password' => 'password',
                    'password_confirmation' => 'password',
                ],
                'allowed_roles' => ['admin'],
            ],
            [
                'method' => 'POST',
                'url' => route('admin.reports.revenue-insight'),
                'payload' => ['period' => 'all'],
                'allowed_roles' => ['admin'],
            ],
            [
                'method' => 'POST',
                'url' => route('inventory-officer.inventory.purchases.store'),
                'payload' => $this->purchasePayload($records),
                'allowed_roles' => ['inventory_officer'],
            ],
            [
                'method' => 'PATCH',
                'url' => route('inventory-officer.inventory.purchases.update', $purchase['purchaseItemId']),
                'payload' => $this->purchasePayload($records),
                'allowed_roles' => ['inventory_officer'],
            ],
            [
                'method' => 'PATCH',
                'url' => route('inventory-officer.inventory.purchases.cancel', $purchase['purchaseItemId']),
                'payload' => [],
                'allowed_roles' => ['inventory_officer'],
            ],
            [
                'method' => 'POST',
                'url' => route('inventory-officer.inventory.stock-in.store'),
                'payload' => [
                    'haul_allocation_id' => $allocationId,
                    'storage_location_id' => $records['garageId'],
                    'quantity_liters' => 500,
                    'movement_date' => '2026-09-04 08:00:00',
                ],
                'allowed_roles' => ['inventory_officer'],
            ],
            [
                'method' => 'POST',
                'url' => route('inventory-officer.inventory.stock-out.store'),
                'payload' => [
                    'idempotency_key' => (string) Str::uuid(),
                    'source_type' => 'garage',
                    'sale_item_id' => $sale['saleItemId'],
                    'storage_location_id' => $records['garageId'],
                    'quantity_liters' => 500,
                    'stock_out_at' => '2026-09-04 09:00:00',
                ],
                'allowed_roles' => ['inventory_officer'],
            ],
            [
                'method' => 'POST',
                'url' => route('sales-officer.sales.customers.store'),
                'payload' => [
                    'name' => 'RBAC Customer',
                    'company_name' => 'RBAC Customer Co.',
                    'payment_status' => 'clear',
                    'status' => 'active',
                ],
                'allowed_roles' => ['sales_officer'],
            ],
            [
                'method' => 'POST',
                'url' => route('sales-officer.sales.store'),
                'payload' => $this->salePayload($records),
                'allowed_roles' => ['sales_officer'],
            ],
            [
                'method' => 'PATCH',
                'url' => route('sales-officer.sales.cancel', $sale['saleId']),
                'payload' => [],
                'allowed_roles' => ['sales_officer'],
            ],
            [
                'method' => 'POST',
                'url' => route('sales-officer.sales.payments.store', $sale['saleId']),
                'payload' => [
                    'idempotency_key' => (string) Str::uuid(),
                    'payment_date' => '2026-09-04',
                    'amount' => 100,
                    'method' => 'cash_on_delivery',
                ],
                'allowed_roles' => ['sales_officer'],
            ],
            [
                'method' => 'PATCH',
                'url' => route('dispatch.fuel-lifting.hauls.truck', $haulId),
                'payload' => [
                    'idempotency_key' => (string) Str::uuid(),
                    'truck_id' => $records['truckId'],
                ],
                'allowed_roles' => ['dispatch_officer'],
            ],
            [
                'method' => 'PATCH',
                'url' => route('driver.fuel-lifting.hauls.status', $haulId),
                'payload' => [
                    'idempotency_key' => (string) Str::uuid(),
                    'lifting_status' => 'in_transit',
                ],
                'allowed_roles' => ['driver'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function baseRecords(): array
    {
        $admin = User::factory()->create(['name' => 'Sensitive Admin Staff', 'role' => 'admin', 'status' => 'active']);
        $inventoryOfficer = User::factory()->create(['role' => 'inventory_officer', 'status' => 'active']);
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active']);
        $dispatchOfficer = User::factory()->create(['role' => 'dispatch_officer', 'status' => 'active']);
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active']);
        $depotId = DB::table('depots')->insertGetId([
            'depot_code' => 'DEP-RBAC',
            'name' => 'RBAC Depot',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $garageId = DB::table('storage_locations')->insertGetId([
            'location_code' => 'GAR-RBAC',
            'name' => 'RBAC Garage',
            'type' => 'garage',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $fuelTypeId = DB::table('fuel_types')->insertGetId([
            'code' => 'DSL-RBAC',
            'name' => 'RBAC Diesel',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-RBAC',
            'name' => 'Sensitive Customer',
            'company_name' => 'Sensitive Customer Co.',
            'payment_status' => 'clear',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-RBAC',
            'capacity_liters' => 5000,
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
            'purchase_code' => 'PUR-SECRET-RBAC',
            'depot_id' => $records['depotId'],
            'purchase_date' => '2026-09-04',
            'payment_status' => 'paid',
            'status' => 'ordered',
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
            'quantity_hauled_liters' => 0,
            'status' => 'unlifted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('purchaseId', 'purchaseItemId');
    }

    /**
     * @param array<string, mixed> $records
     * @param array{purchaseId: int, purchaseItemId: int} $purchase
     * @param array<string, mixed> $overrides
     */
    private function haul(array $records, array $purchase, float $quantity, array $overrides = []): int
    {
        return DB::table('hauls')->insertGetId(array_merge([
            'haul_code' => 'LFT-RBAC-'.Str::upper(Str::random(5)),
            'purchase_id' => $purchase['purchaseId'],
            'purchase_item_id' => $purchase['purchaseItemId'],
            'depot_id' => $records['depotId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'truck_id' => $records['truckId'],
            'driver_user_id' => $records['driver']->id,
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'quantity_liters' => $quantity,
            'status' => 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $records
     * @param array{purchaseId: int, purchaseItemId: int} $purchase
     */
    private function garageAllocation(array $records, array $purchase, float $quantity): int
    {
        $haulId = $this->haul($records, $purchase, $quantity, [
            'haul_code' => 'LFT-ALLOC-RBAC',
            'status' => 'completed',
            'hauled_at' => '2026-09-04 08:00:00',
        ]);

        return DB::table('haul_allocations')->insertGetId([
            'haul_id' => $haulId,
            'fuel_type_id' => $records['fuelTypeId'],
            'destination_type' => 'garage',
            'storage_location_id' => $records['garageId'],
            'quantity_liters' => $quantity,
            'allocated_at' => '2026-09-04 08:30:00',
            'status' => 'delivered',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $records
     * @return array{saleId: int, saleItemId: int}
     */
    private function sale(array $records, float $quantity, float $unitPrice, ?string $code = null): array
    {
        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => $code ?: 'SLS-RBAC-'.Str::upper(Str::random(5)),
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
            'unit_price' => $unitPrice,
            'line_total' => $quantity * $unitPrice,
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
     * @param array{saleId: int, saleItemId: int} $sale
     */
    private function stockOut(array $records, array $sale, float $quantity): int
    {
        return DB::table('stock_outs')->insertGetId([
            'stock_out_code' => 'STO-SECRET-RBAC',
            'sale_id' => $sale['saleId'],
            'sale_item_id' => $sale['saleItemId'],
            'customer_id' => $records['customerId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'storage_location_id' => $records['garageId'],
            'quantity_liters' => $quantity,
            'stock_out_at' => '2026-09-04 09:00:00',
            'status' => 'released',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $records
     * @return array<string, mixed>
     */
    private function purchasePayload(array $records): array
    {
        return [
            'purchase_date' => '2026-09-04',
            'depot_id' => $records['depotId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_ordered_liters' => 100,
            'unit_cost' => 50,
            'payment_status' => 'paid',
            'status' => 'ordered',
        ];
    }

    /**
     * @param array<string, mixed> $records
     * @return array<string, mixed>
     */
    private function salePayload(array $records): array
    {
        return [
            'idempotency_key' => (string) Str::uuid(),
            'customer_id' => $records['customerId'],
            'sale_date' => '2026-09-04',
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_liters' => 100,
            'unit_price' => 50,
            'payment_method' => 'cash_on_delivery',
            'payment_terms' => 'cod',
        ];
    }

    /**
     * @return array<string, int>
     */
    private function tableCounts(): array
    {
        return [
            'users' => User::count(),
            'purchases' => DB::table('purchases')->count(),
            'inventory_movements' => DB::table('inventory_movements')->count(),
            'sales' => DB::table('sales')->count(),
            'customers' => DB::table('customers')->count(),
            'stock_outs' => DB::table('stock_outs')->count(),
            'payments' => DB::table('payments')->count(),
            'hauls' => DB::table('hauls')->count(),
        ];
    }
}
