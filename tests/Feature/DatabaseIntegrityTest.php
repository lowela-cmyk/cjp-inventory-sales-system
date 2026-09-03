<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DatabaseIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_constraints_enforce_required_unique_and_foreign_key_integrity(): void
    {
        $records = $this->baseRecords();

        $this->assertQueryFails(fn (): int => DB::table('depots')->insertGetId([
            'depot_code' => 'DEP-DB',
            'name' => 'Duplicate Depot Code',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->assertQueryFails(fn (): int => DB::table('purchases')->insertGetId([
            'purchase_code' => 'PUR-MISSING-DATE',
            'depot_id' => $records['depotId'],
            'purchase_date' => null,
            'payment_status' => 'paid',
            'status' => 'ordered',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->assertQueryFails(fn (): int => DB::table('purchase_items')->insertGetId([
            'purchase_id' => 999999,
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_ordered_liters' => 1000,
            'unit_cost' => 50,
            'line_total' => 50000,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->assertSame(1, DB::table('depots')->where('depot_code', 'DEP-DB')->count());
        $this->assertSame(0, DB::table('purchases')->where('purchase_code', 'PUR-MISSING-DATE')->count());
        $this->assertSame(0, DB::table('purchase_items')->where('purchase_id', 999999)->count());
    }

    public function test_user_foreign_key_delete_rules_match_the_existing_schema(): void
    {
        $records = $this->baseRecords();
        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => 'PUR-NULL-CREATOR',
            'depot_id' => $records['depotId'],
            'purchase_date' => '2026-09-04',
            'payment_status' => 'paid',
            'status' => 'ordered',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $records['inventoryOfficer']->delete();

        $this->assertDatabaseHas('purchases', [
            'id' => $purchaseId,
            'created_by' => null,
        ]);

        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active']);
        DB::table('driver_profiles')->insert([
            'user_id' => $driver->id,
            'driver_code' => 'DRV-DB',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertQueryFails(fn (): ?bool => $driver->delete());
        $this->assertDatabaseHas('users', ['id' => $driver->id, 'role' => 'driver']);
        $this->assertDatabaseHas('driver_profiles', ['user_id' => $driver->id]);
    }

    public function test_purchase_stock_in_and_inventory_links_stay_consistent(): void
    {
        $records = $this->baseRecords();
        $purchase = $this->createPurchaseViaRoute($records, 30000);
        $haulId = $this->completedGarageHaul($records, $purchase, 30000);
        $allocationId = DB::table('haul_allocations')->insertGetId([
            'haul_id' => $haulId,
            'fuel_type_id' => $records['fuelTypeId'],
            'destination_type' => 'garage',
            'storage_location_id' => $records['garageId'],
            'quantity_liters' => 30000,
            'allocated_at' => '2026-09-04 09:00:00',
            'status' => 'delivered',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-in.store'), [
                'haul_allocation_id' => $allocationId,
                'storage_location_id' => $records['garageId'],
                'quantity_liters' => 30000,
                'movement_date' => '2026-09-04 10:00:00',
                'remarks' => 'Database integrity stock-in',
            ])
            ->assertRedirect(route('inventory-officer.inventory.stock-in'));

        $this->assertDatabaseHas('purchase_items', [
            'id' => $purchase['purchaseItemId'],
            'purchase_id' => $purchase['purchaseId'],
            'quantity_hauled_liters' => '30000.00',
            'status' => 'lifted',
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'reference_type' => 'haul_allocation',
            'reference_id' => $allocationId,
            'storage_location_id' => $records['garageId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'movement_type' => 'stock_in',
            'direction' => 'in',
            'quantity_liters' => '30000.00',
        ]);
        $this->assertSame(30000.0, $this->garageBalance($records));
        $this->assertNoBrokenOperationalLinks();
    }

    public function test_sales_stock_out_delivery_payment_and_receivable_links_stay_consistent(): void
    {
        $records = $this->baseRecords();
        $this->seedGarageStock($records, 22000);
        $sale = $this->createSaleViaRoute($records, [
            'sale_code' => 'SLS-DB-LINKS',
            'quantity_liters' => 12000,
            'unit_price' => 6.25,
        ]);

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-out.store'), [
                'idempotency_key' => (string) Str::uuid(),
                'source_type' => 'garage',
                'sale_item_id' => $sale['saleItemId'],
                'storage_location_id' => $records['garageId'],
                'quantity_liters' => 12000,
                'stock_out_at' => '2026-09-04 11:00:00',
                'remarks' => 'Database integrity stock-out',
            ])
            ->assertRedirect(route('inventory-officer.inventory.stock-out'));

        $stockOut = DB::table('stock_outs')->where('sale_id', $sale['saleId'])->first();
        $delivery = DB::table('deliveries')->where('id', $stockOut->delivery_id)->first();

        $this->assertNotNull($stockOut->inventory_movement_id);
        $this->assertSame((int) $sale['saleItemId'], (int) $stockOut->sale_item_id);
        $this->assertSame((int) $delivery->id, (int) $stockOut->delivery_id);
        $this->assertSame('delivered', $delivery->status);
        $this->assertDatabaseHas('inventory_movements', [
            'id' => $stockOut->inventory_movement_id,
            'reference_type' => 'stock_out',
            'reference_id' => $stockOut->id,
            'direction' => 'out',
            'quantity_liters' => '12000.00',
        ]);
        $this->assertDatabaseHas('sale_items', [
            'id' => $sale['saleItemId'],
            'fulfilled_quantity_liters' => '12000.00',
        ]);

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.payments.store', $sale['saleId']), [
                'idempotency_key' => (string) Str::uuid(),
                'payment_date' => '2026-09-04',
                'amount' => 75000,
                'method' => 'bank_transfer',
                'reference_number' => 'BANK-DB-001',
                'remarks' => 'Database integrity payment',
            ])
            ->assertRedirect(route('sales-officer.sales'));

        $this->assertDatabaseHas('payments', [
            'sale_id' => $sale['saleId'],
            'amount' => '75000.00',
            'received_by' => $records['salesOfficer']->id,
        ]);
        $this->assertDatabaseHas('sales', ['id' => $sale['saleId'], 'status' => 'paid']);
        $this->assertDatabaseHas('receivables', ['sale_id' => $sale['saleId'], 'status' => 'clear']);
        $this->assertSame(10000.0, $this->garageBalance($records));
        $this->assertNoBrokenOperationalLinks();
    }

    public function test_failed_transactions_duplicates_and_cancelled_records_do_not_leave_inconsistent_data(): void
    {
        $records = $this->baseRecords();

        try {
            DB::transaction(function () use ($records): void {
                $saleId = DB::table('sales')->insertGetId([
                    'sale_code' => 'SLS-ROLLBACK',
                    'customer_id' => $records['customerId'],
                    'sale_date' => '2026-09-04',
                    'payment_method' => 'cash_on_delivery',
                    'payment_terms' => 'cod',
                    'status' => 'confirmed',
                    'created_by' => $records['salesOfficer']->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('sale_items')->insert([
                    'sale_id' => $saleId,
                    'fuel_type_id' => 999999,
                    'quantity_liters' => 1000,
                    'unit_price' => 5,
                    'line_total' => 5000,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

            $this->fail('Expected the invalid sale item foreign key to roll back the transaction.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('sales', ['sale_code' => 'SLS-ROLLBACK']);
        }

        $sale = $this->createSaleViaRoute($records, [
            'sale_code' => 'SLS-DB-DUPLICATE',
            'quantity_liters' => 5000,
            'unit_price' => 4,
        ]);

        $this->actingAs($records['salesOfficer'])
            ->from(route('sales-officer.sales'))
            ->post(route('sales-officer.sales.store'), $this->salePayload($records, [
                'sale_code' => 'SLS-DB-DUPLICATE',
                'quantity_liters' => 3000,
                'unit_price' => 7,
            ]))
            ->assertRedirect(route('sales-officer.sales'))
            ->assertSessionHasErrors('sale_code');

        $this->assertSame(1, DB::table('sales')->where('sale_code', 'SLS-DB-DUPLICATE')->count());
        $this->assertSame(1, DB::table('sale_items')->where('sale_id', $sale['saleId'])->count());
        $this->assertSame(1, DB::table('receivables')->where('sale_id', $sale['saleId'])->count());

        DB::table('sales')->where('id', $sale['saleId'])->update(['status' => 'cancelled']);

        $this->actingAs($records['salesOfficer'])
            ->from(route('sales-officer.sales'))
            ->post(route('sales-officer.sales.payments.store', $sale['saleId']), [
                'idempotency_key' => (string) Str::uuid(),
                'payment_date' => '2026-09-04',
                'amount' => 20000,
                'method' => 'cash_on_delivery',
            ])
            ->assertRedirect(route('sales-officer.sales'))
            ->assertSessionHasErrors('payment');

        $this->assertSame(0, DB::table('payments')->where('sale_id', $sale['saleId'])->count());
        $this->assertDatabaseHas('receivables', ['sale_id' => $sale['saleId'], 'status' => 'pending']);
        $this->assertNoBrokenOperationalLinks();
    }

    /**
     * @param callable(): mixed $callback
     */
    private function assertQueryFails(callable $callback): void
    {
        try {
            $callback();
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected database query to fail.');
    }

    /**
     * @return array<string, mixed>
     */
    private function baseRecords(): array
    {
        $inventoryOfficer = User::factory()->create(['role' => 'inventory_officer', 'status' => 'active']);
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active']);
        $dispatchOfficer = User::factory()->create(['role' => 'dispatch_officer', 'status' => 'active']);
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active']);
        $depotId = DB::table('depots')->insertGetId([
            'depot_code' => 'DEP-DB',
            'name' => 'Database Integrity Depot',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $garageId = DB::table('storage_locations')->insertGetId([
            'location_code' => 'GAR-DB',
            'name' => 'Database Integrity Garage',
            'type' => 'garage',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $fuelTypeId = DB::table('fuel_types')->insertGetId([
            'code' => 'DSL-DB',
            'name' => 'Database Integrity Diesel',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-DB',
            'name' => 'Database Customer',
            'company_name' => 'Database Customer Co.',
            'payment_status' => 'clear',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-DB',
            'capacity_liters' => 40000,
            'truck_type' => 'mixed',
            'status' => 'assigned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact(
            'inventoryOfficer',
            'salesOfficer',
            'dispatchOfficer',
            'driver',
            'depotId',
            'garageId',
            'fuelTypeId',
            'customerId',
            'truckId'
        );
    }

    /**
     * @param array<string, mixed> $records
     * @return array{purchaseId: int, purchaseItemId: int}
     */
    private function createPurchaseViaRoute(array $records, float $quantity): array
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

        return [
            'purchaseId' => (int) $purchase->id,
            'purchaseItemId' => (int) $purchaseItem->id,
        ];
    }

    /**
     * @param array<string, mixed> $records
     * @param array{purchaseId: int, purchaseItemId: int} $purchase
     */
    private function completedGarageHaul(array $records, array $purchase, float $quantity): int
    {
        $haulId = DB::table('hauls')->insertGetId([
            'haul_code' => 'LFT-DB',
            'purchase_id' => $purchase['purchaseId'],
            'purchase_item_id' => $purchase['purchaseItemId'],
            'depot_id' => $records['depotId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'truck_id' => $records['truckId'],
            'driver_user_id' => $records['driver']->id,
            'scheduled_at' => '2026-09-04 08:00:00',
            'quantity_liters' => $quantity,
            'status' => 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['in_transit', 'lifted', 'completed'] as $status) {
            $this->actingAs($records['dispatchOfficer'])
                ->patch(route('dispatch.fuel-lifting.hauls.status', $haulId), [
                    'idempotency_key' => (string) Str::uuid(),
                    'status' => $status,
                ])
                ->assertRedirect(route('dispatch.fuel-lifting'));
        }

        return $haulId;
    }

    /**
     * @param array<string, mixed> $records
     * @param array<string, mixed> $overrides
     * @return array{saleId: int, saleItemId: int}
     */
    private function createSaleViaRoute(array $records, array $overrides = []): array
    {
        $payload = $this->salePayload($records, $overrides);

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.store'), $payload)
            ->assertRedirect(route('sales-officer.sales'));

        $sale = DB::table('sales')->where('sale_code', $payload['sale_code'])->first();
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
    private function salePayload(array $records, array $overrides = []): array
    {
        return array_merge([
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
    }

    /**
     * @param array<string, mixed> $records
     */
    private function seedGarageStock(array $records, float $quantity): void
    {
        DB::table('inventory_movements')->insert([
            'movement_code' => 'MOV-OPENING-DB',
            'storage_location_id' => $records['garageId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'movement_type' => 'beginning',
            'direction' => 'in',
            'quantity_liters' => $quantity,
            'unit_cost' => 50,
            'reference_type' => 'opening_balance',
            'reference_id' => 1,
            'movement_date' => '2026-09-04 07:00:00',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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

    private function assertNoBrokenOperationalLinks(): void
    {
        $this->assertSame(0, DB::table('purchase_items')
            ->leftJoin('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->whereNull('purchases.id')
            ->count());
        $this->assertSame(0, DB::table('hauls')
            ->leftJoin('purchase_items', 'purchase_items.id', '=', 'hauls.purchase_item_id')
            ->whereNull('purchase_items.id')
            ->count());
        $this->assertSame(0, DB::table('haul_allocations')
            ->leftJoin('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
            ->whereNull('hauls.id')
            ->count());
        $this->assertSame(0, DB::table('sale_items')
            ->leftJoin('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereNull('sales.id')
            ->count());
        $this->assertSame(0, DB::table('stock_outs')
            ->leftJoin('sales', 'sales.id', '=', 'stock_outs.sale_id')
            ->leftJoin('inventory_movements', 'inventory_movements.id', '=', 'stock_outs.inventory_movement_id')
            ->whereNull('sales.id')
            ->orWhereNull('inventory_movements.id')
            ->count());
        $this->assertSame(0, DB::table('payments')
            ->leftJoin('sales', 'sales.id', '=', 'payments.sale_id')
            ->whereNull('sales.id')
            ->count());
        $this->assertSame(0, DB::table('receivables')
            ->leftJoin('sales', 'sales.id', '=', 'receivables.sale_id')
            ->whereNull('sales.id')
            ->count());
        $this->assertSame(0, DB::table('deliveries')
            ->leftJoin('customers', 'customers.id', '=', 'deliveries.customer_id')
            ->leftJoin('fuel_types', 'fuel_types.id', '=', 'deliveries.fuel_type_id')
            ->whereNull('customers.id')
            ->orWhereNull('fuel_types.id')
            ->count());
    }
}
