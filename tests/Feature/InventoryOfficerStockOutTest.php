<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryOfficerStockOutTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_officer_records_garage_stock_out_and_deducts_inventory_once(): void
    {
        $records = $this->baseRecords();
        $this->garageMovement($records, ['quantity_liters' => 20000]);
        $sale = $this->sale($records, ['quantity_liters' => 15000]);

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-out.store'), $this->stockOutPayload($records, $sale, [
                'quantity_liters' => 12000,
            ]))
            ->assertRedirect(route('inventory-officer.inventory.stock-out'));

        $stockOut = DB::table('stock_outs')->first();
        $this->assertNotNull($stockOut);
        $this->assertSame('released', $stockOut->status);
        $this->assertNotNull($stockOut->inventory_movement_id);
        $this->assertDatabaseHas('inventory_movements', [
            'id' => $stockOut->inventory_movement_id,
            'storage_location_id' => $records['garageId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'movement_type' => 'stock_out',
            'direction' => 'out',
            'quantity_liters' => '12000.00',
            'reference_type' => 'stock_out',
            'reference_id' => $stockOut->id,
        ]);
        $this->assertSame('garage', $stockOut->source_type);
        $this->assertSame(8000.0, $this->garageBalance($records));
        $this->assertDatabaseHas('sale_items', [
            'id' => $sale['saleItemId'],
            'fulfilled_quantity_liters' => '12000.00',
        ]);
    }

    public function test_garage_stock_out_allows_partial_then_rejects_release_beyond_remaining_sale_quantity(): void
    {
        $records = $this->baseRecords();
        $this->garageMovement($records, ['quantity_liters' => 30000]);
        $sale = $this->sale($records, ['quantity_liters' => 20000]);

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-out.store'), $this->stockOutPayload($records, $sale, [
                'quantity_liters' => 12000,
            ]))
            ->assertRedirect(route('inventory-officer.inventory.stock-out'));

        $this->actingAs($records['inventoryOfficer'])
            ->from(route('inventory-officer.inventory.stock-out'))
            ->post(route('inventory-officer.inventory.stock-out.store'), $this->stockOutPayload($records, $sale, [
                'quantity_liters' => 9000,
            ]))
            ->assertRedirect(route('inventory-officer.inventory.stock-out'))
            ->assertSessionHasErrors('stock_out');

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-out.store'), $this->stockOutPayload($records, $sale, [
                'quantity_liters' => 8000,
            ]))
            ->assertRedirect(route('inventory-officer.inventory.stock-out'));

        $this->assertSame(10000.0, $this->garageBalance($records));
        $this->assertSame(2, DB::table('stock_outs')->count());
        $this->assertDatabaseHas('sale_items', [
            'id' => $sale['saleItemId'],
            'fulfilled_quantity_liters' => '20000.00',
        ]);
    }

    public function test_stock_out_rejects_insufficient_inventory_invalid_quantities_and_wrong_sale_item(): void
    {
        $records = $this->baseRecords();
        $this->garageMovement($records, ['quantity_liters' => 5000]);
        $sale = $this->sale($records, ['quantity_liters' => 10000]);

        $this->actingAs($records['inventoryOfficer'])
            ->from(route('inventory-officer.inventory.stock-out'))
            ->post(route('inventory-officer.inventory.stock-out.store'), $this->stockOutPayload($records, $sale, [
                'quantity_liters' => 6000,
            ]))
            ->assertRedirect(route('inventory-officer.inventory.stock-out'))
            ->assertSessionHasErrors('stock_out');

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-out.store'), $this->stockOutPayload($records, $sale, [
                'quantity_liters' => 0,
            ]))
            ->assertSessionHasErrors('quantity_liters');

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-out.store'), $this->stockOutPayload($records, ['saleItemId' => 999999]))
            ->assertSessionHasErrors('sale_item_id');

        $this->assertSame(5000.0, $this->garageBalance($records));
        $this->assertSame(0, DB::table('stock_outs')->count());
        $this->assertSame(1, DB::table('inventory_movements')->count());
    }

    public function test_cancelled_or_draft_sales_are_not_eligible_for_stock_out(): void
    {
        $records = $this->baseRecords();
        $this->garageMovement($records, ['quantity_liters' => 20000]);
        $cancelledSale = $this->sale($records, ['status' => 'cancelled']);
        $draftSale = $this->sale($records, ['sale_code' => 'SLS-DRAFT', 'status' => 'draft']);

        foreach ([$cancelledSale, $draftSale] as $sale) {
            $this->actingAs($records['inventoryOfficer'])
                ->from(route('inventory-officer.inventory.stock-out'))
                ->post(route('inventory-officer.inventory.stock-out.store'), $this->stockOutPayload($records, $sale))
                ->assertRedirect(route('inventory-officer.inventory.stock-out'))
                ->assertSessionHasErrors('stock_out');
        }

        $this->assertSame(20000.0, $this->garageBalance($records));
        $this->assertSame(0, DB::table('stock_outs')->count());
    }

    public function test_direct_depot_delivery_fulfills_sale_without_reducing_garage_inventory(): void
    {
        $records = $this->baseRecords();
        $this->garageMovement($records, ['quantity_liters' => 9000]);
        $sale = $this->sale($records, ['quantity_liters' => 10000]);
        $allocationId = $this->directAllocation($records, $sale, ['quantity_liters' => 10000]);

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-out.store'), $this->stockOutPayload($records, $sale, [
                'source_type' => 'depot',
                'storage_location_id' => null,
                'haul_allocation_id' => $allocationId,
                'quantity_liters' => 10000,
            ]))
            ->assertRedirect(route('inventory-officer.inventory.stock-out'));

        $this->assertSame(9000.0, $this->garageBalance($records));
        $this->assertSame(1, DB::table('stock_outs')->where('source_type', 'depot')->count());
        $this->assertDatabaseHas('stock_outs', [
            'sale_id' => $sale['saleId'],
            'haul_allocation_id' => $allocationId,
            'source_type' => 'depot',
            'quantity_liters' => '10000.00',
            'status' => 'released',
        ]);
        $this->assertSame(1, DB::table('inventory_movements')->count());
        $this->assertDatabaseHas('sale_items', [
            'id' => $sale['saleItemId'],
            'fulfilled_quantity_liters' => '10000.00',
        ]);
    }

    public function test_cancelled_stock_in_source_is_not_available_for_garage_stock_out(): void
    {
        $records = $this->baseRecords();
        $sale = $this->sale($records, ['quantity_liters' => 5000]);
        $allocationId = $this->directAllocation($records, $sale, [
            'destination_type' => 'garage',
            'storage_location_id' => $records['garageId'],
            'customer_id' => null,
            'sale_id' => null,
            'quantity_liters' => 10000,
            'status' => 'received',
        ]);

        $this->garageMovement($records, [
            'quantity_liters' => 10000,
            'reference_type' => 'haul_allocation',
            'reference_id' => $allocationId,
        ]);
        DB::table('haul_allocations')->where('id', $allocationId)->update(['status' => 'cancelled']);

        $this->actingAs($records['inventoryOfficer'])
            ->from(route('inventory-officer.inventory.stock-out'))
            ->post(route('inventory-officer.inventory.stock-out.store'), $this->stockOutPayload($records, $sale, [
                'quantity_liters' => 5000,
            ]))
            ->assertRedirect(route('inventory-officer.inventory.stock-out'))
            ->assertSessionHasErrors('stock_out');

        $this->assertSame(0, DB::table('stock_outs')->count());
        $this->assertDatabaseHas('sale_items', [
            'id' => $sale['saleItemId'],
            'fulfilled_quantity_liters' => '0.00',
        ]);
    }

    public function test_direct_depot_delivery_rejects_invalid_purchase_haul_relationship(): void
    {
        $records = $this->baseRecords();
        $this->garageMovement($records, ['quantity_liters' => 9000]);
        $sale = $this->sale($records, ['quantity_liters' => 10000]);
        $allocationId = $this->directAllocation($records, $sale, ['quantity_liters' => 10000]);
        $haulId = DB::table('haul_allocations')->where('id', $allocationId)->value('haul_id');
        $otherPurchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => 'PUR-WRONG-DIRECT',
            'depot_id' => $records['depotId'],
            'purchase_date' => '2026-08-29',
            'payment_status' => 'paid',
            'status' => 'hauled',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('hauls')->where('id', $haulId)->update(['purchase_id' => $otherPurchaseId]);

        $this->actingAs($records['inventoryOfficer'])
            ->from(route('inventory-officer.inventory.stock-out'))
            ->post(route('inventory-officer.inventory.stock-out.store'), $this->stockOutPayload($records, $sale, [
                'source_type' => 'depot',
                'storage_location_id' => null,
                'haul_allocation_id' => $allocationId,
                'quantity_liters' => 10000,
            ]))
            ->assertRedirect(route('inventory-officer.inventory.stock-out'))
            ->assertSessionHasErrors('stock_out');

        $this->assertSame(9000.0, $this->garageBalance($records));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('deliveries'));
        $this->assertDatabaseHas('sale_items', [
            'id' => $sale['saleItemId'],
            'fulfilled_quantity_liters' => '0.00',
        ]);
    }

    public function test_duplicate_stock_out_submission_token_does_not_double_deduct_inventory(): void
    {
        $records = $this->baseRecords();
        $this->garageMovement($records, ['quantity_liters' => 30000]);
        $sale = $this->sale($records, ['quantity_liters' => 30000]);
        $payload = $this->stockOutPayload($records, $sale, [
            'quantity_liters' => 12000,
        ]);

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-out.store'), $payload)
            ->assertRedirect(route('inventory-officer.inventory.stock-out'));

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-out.store'), $payload)
            ->assertRedirect(route('inventory-officer.inventory.stock-out'));

        $this->assertSame(18000.0, $this->garageBalance($records));
        $this->assertSame(1, DB::table('stock_outs')->count());
        $this->assertSame(2, DB::table('inventory_movements')->count());
        $this->assertDatabaseHas('sale_items', [
            'id' => $sale['saleItemId'],
            'fulfilled_quantity_liters' => '12000.00',
        ]);
    }

    public function test_duplicate_garage_stock_out_physical_release_is_rejected(): void
    {
        $records = $this->baseRecords();
        $this->garageMovement($records, ['quantity_liters' => 30000]);
        $sale = $this->sale($records, ['quantity_liters' => 30000]);
        $payload = $this->stockOutPayload($records, $sale, [
            'quantity_liters' => 10000,
            'stock_out_at' => '2026-08-30 11:00:00',
        ]);

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-out.store'), $payload)
            ->assertRedirect(route('inventory-officer.inventory.stock-out'));

        $this->actingAs($records['inventoryOfficer'])
            ->from(route('inventory-officer.inventory.stock-out'))
            ->post(route('inventory-officer.inventory.stock-out.store'), array_merge($payload, [
                'idempotency_key' => (string) Str::uuid(),
            ]))
            ->assertRedirect(route('inventory-officer.inventory.stock-out'))
            ->assertSessionHasErrors('stock_out');

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-out.store'), array_merge($payload, [
                'idempotency_key' => (string) Str::uuid(),
                'stock_out_at' => '2026-08-30 12:00:00',
            ]))
            ->assertRedirect(route('inventory-officer.inventory.stock-out'));

        $this->assertSame(10000.0, $this->garageBalance($records));
        $this->assertSame(2, DB::table('stock_outs')->count());
        $this->assertSame(3, DB::table('inventory_movements')->count());
        $this->assertDatabaseHas('sale_items', [
            'id' => $sale['saleItemId'],
            'fulfilled_quantity_liters' => '20000.00',
        ]);
    }

    public function test_direct_depot_stock_out_requires_completed_lift_and_rejects_duplicate_physical_release(): void
    {
        $records = $this->baseRecords();
        $this->garageMovement($records, ['quantity_liters' => 9000]);
        $pendingSale = $this->sale($records, ['quantity_liters' => 10000]);
        $pendingAllocationId = $this->directAllocation($records, $pendingSale, [
            'haul_status' => 'in_transit',
        ]);

        $this->actingAs($records['inventoryOfficer'])
            ->from(route('inventory-officer.inventory.stock-out'))
            ->post(route('inventory-officer.inventory.stock-out.store'), $this->stockOutPayload($records, $pendingSale, [
                'source_type' => 'depot',
                'storage_location_id' => null,
                'haul_allocation_id' => $pendingAllocationId,
                'quantity_liters' => 10000,
            ]))
            ->assertRedirect(route('inventory-officer.inventory.stock-out'))
            ->assertSessionHasErrors('stock_out');

        $sale = $this->sale($records, ['quantity_liters' => 20000]);
        $allocationId = $this->directAllocation($records, $sale, ['quantity_liters' => 20000]);
        $payload = $this->stockOutPayload($records, $sale, [
            'source_type' => 'depot',
            'storage_location_id' => null,
            'haul_allocation_id' => $allocationId,
            'quantity_liters' => 10000,
            'stock_out_at' => '2026-08-30 11:00:00',
        ]);

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-out.store'), $payload)
            ->assertRedirect(route('inventory-officer.inventory.stock-out'));

        $this->actingAs($records['inventoryOfficer'])
            ->from(route('inventory-officer.inventory.stock-out'))
            ->post(route('inventory-officer.inventory.stock-out.store'), array_merge($payload, [
                'idempotency_key' => (string) Str::uuid(),
            ]))
            ->assertRedirect(route('inventory-officer.inventory.stock-out'))
            ->assertSessionHasErrors('stock_out');

        $this->assertSame(9000.0, $this->garageBalance($records));
        $this->assertSame(1, DB::table('stock_outs')->where('source_type', 'depot')->count());
        $this->assertDatabaseHas('sale_items', [
            'id' => $pendingSale['saleItemId'],
            'fulfilled_quantity_liters' => '0.00',
        ]);
        $this->assertDatabaseHas('sale_items', [
            'id' => $sale['saleItemId'],
            'fulfilled_quantity_liters' => '10000.00',
        ]);
    }

    public function test_direct_depot_release_respects_already_scheduled_direct_deliveries(): void
    {
        $records = $this->baseRecords();
        $sale = $this->sale($records, ['quantity_liters' => 20000]);
        $allocationId = $this->directAllocation($records, $sale, ['quantity_liters' => 20000]);

        DB::table('stock_outs')->insert([
            'stock_out_code' => 'STO-PENDING-DIRECT',
            'sale_id' => $sale['saleId'],
            'sale_item_id' => $sale['saleItemId'],
            'customer_id' => $records['customerId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'source_type' => 'depot',
            'storage_location_id' => null,
            'depot_id' => $records['depotId'],
            'haul_allocation_id' => $allocationId,
            'quantity_liters' => 12000,
            'stock_out_at' => '2026-08-30 12:00:00',
            'status' => 'released',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($records['inventoryOfficer'])
            ->from(route('inventory-officer.inventory.stock-out'))
            ->post(route('inventory-officer.inventory.stock-out.store'), $this->stockOutPayload($records, $sale, [
                'source_type' => 'depot',
                'storage_location_id' => null,
                'haul_allocation_id' => $allocationId,
                'quantity_liters' => 9000,
            ]))
            ->assertRedirect(route('inventory-officer.inventory.stock-out'))
            ->assertSessionHasErrors('stock_out');

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-out.store'), $this->stockOutPayload($records, $sale, [
                'source_type' => 'depot',
                'storage_location_id' => null,
                'haul_allocation_id' => $allocationId,
                'quantity_liters' => 8000,
                'stock_out_at' => '2026-08-30 13:00:00',
            ]))
            ->assertRedirect(route('inventory-officer.inventory.stock-out'));

        $this->assertSame(2, DB::table('stock_outs')->where('source_type', 'depot')->count());
        $this->assertDatabaseHas('sale_items', [
            'id' => $sale['saleItemId'],
            'fulfilled_quantity_liters' => '8000.00',
        ]);
    }

    public function test_non_inventory_roles_cannot_create_stock_out(): void
    {
        $records = $this->baseRecords();
        $this->garageMovement($records, ['quantity_liters' => 10000]);
        $sale = $this->sale($records);
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active']);

        $this->actingAs($salesOfficer)
            ->post(route('inventory-officer.inventory.stock-out.store'), $this->stockOutPayload($records, $sale))
            ->assertForbidden();

        $this->assertSame(0, DB::table('stock_outs')->count());
        $this->assertSame(10000.0, $this->garageBalance($records));
    }

    /**
     * @param array<string, mixed> $records
     * @param array<string, mixed> $sale
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
            'quantity_liters' => 5000,
            'stock_out_at' => '2026-08-30 11:00:00',
            'remarks' => 'Release to client',
        ], $overrides), fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $records
     * @param array<string, mixed> $overrides
     */
    private function garageMovement(array $records, array $overrides = []): void
    {
        DB::table('inventory_movements')->insert(array_merge([
            'movement_code' => 'MOV-'.Str::upper(Str::random(8)),
            'storage_location_id' => $records['garageId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'movement_type' => 'stock_in',
            'direction' => 'in',
            'quantity_liters' => 10000,
            'unit_cost' => 50,
            'reference_type' => 'test',
            'reference_id' => 1,
            'movement_date' => '2026-08-30 08:00:00',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $records
     * @param array<string, mixed> $overrides
     * @return array<string, int>
     */
    private function sale(array $records, array $overrides = []): array
    {
        $saleId = DB::table('sales')->insertGetId(array_merge([
            'sale_code' => 'SLS-'.Str::upper(Str::random(8)),
            'customer_id' => $records['customerId'],
            'sale_date' => '2026-08-30',
            'payment_method' => 'cash_on_delivery',
            'payment_terms' => 'cod',
            'status' => 'confirmed',
            'created_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], collect($overrides)->only(['sale_code', 'status'])->all()));

        $saleItemId = DB::table('sale_items')->insertGetId([
            'sale_id' => $saleId,
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_liters' => $overrides['quantity_liters'] ?? 10000,
            'unit_price' => 62.50,
            'line_total' => (($overrides['quantity_liters'] ?? 10000) * 62.50),
            'fulfilled_quantity_liters' => $overrides['fulfilled_quantity_liters'] ?? 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('saleId', 'saleItemId');
    }

    /**
     * @param array<string, mixed> $records
     * @param array<string, mixed> $sale
     * @param array<string, mixed> $overrides
     */
    private function directAllocation(array $records, array $sale, array $overrides = []): int
    {
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active']);
        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-'.Str::upper(Str::random(8)),
            'capacity_liters' => 40000,
            'truck_type' => 'hauling',
            'status' => 'assigned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $quantity = $overrides['quantity_liters'] ?? 10000;
        $haulStatus = $overrides['haul_status'] ?? 'completed';
        $isCompletedHaul = $haulStatus === 'completed';
        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => 'PUR-'.Str::upper(Str::random(8)),
            'depot_id' => $records['depotId'],
            'purchase_date' => '2026-08-29',
            'payment_status' => 'paid',
            'status' => $isCompletedHaul ? 'hauled' : 'partially_hauled',
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
            'quantity_hauled_liters' => $isCompletedHaul ? $quantity : 0,
            'status' => $isCompletedHaul ? 'lifted' : 'unlifted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $haulId = DB::table('hauls')->insertGetId([
            'haul_code' => 'LFT-'.Str::upper(Str::random(8)),
            'purchase_id' => $purchaseId,
            'purchase_item_id' => $purchaseItemId,
            'depot_id' => $records['depotId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'truck_id' => $truckId,
            'driver_user_id' => $driver->id,
            'scheduled_at' => '2026-08-30 07:00:00',
            'quantity_liters' => $quantity,
            'status' => $haulStatus,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('haul_allocations')->insertGetId(array_merge([
            'haul_id' => $haulId,
            'fuel_type_id' => $records['fuelTypeId'],
            'destination_type' => 'customer',
            'customer_id' => $records['customerId'],
            'sale_id' => $sale['saleId'],
            'quantity_liters' => 10000,
            'allocated_at' => '2026-08-30 08:30:00',
            'status' => 'delivered',
            'created_at' => now(),
            'updated_at' => now(),
        ], collect($overrides)->except('haul_status')->all()));
    }

    /**
     * @param array<string, mixed> $records
     */
    private function garageBalance(array $records): float
    {
        $balance = DB::table('inventory_movements')
            ->where('storage_location_id', $records['garageId'])
            ->where('fuel_type_id', $records['fuelTypeId'])
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN quantity_liters ELSE -quantity_liters END), 0) as balance")
            ->value('balance');

        return round((float) $balance, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function baseRecords(): array
    {
        $inventoryOfficer = User::factory()->create(['role' => 'inventory_officer', 'status' => 'active']);
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active']);
        $depotId = DB::table('depots')->insertGetId([
            'depot_code' => 'DEP-STOCK-OUT',
            'name' => 'Source Depot',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $fuelTypeId = DB::table('fuel_types')->insertGetId([
            'code' => 'DSL',
            'name' => 'Diesel',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $garageId = DB::table('storage_locations')->insertGetId([
            'location_code' => 'GAR-STOCK-OUT',
            'name' => 'CJP Garage',
            'type' => 'garage',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-STOCK-OUT',
            'name' => 'Stock Out Customer',
            'company_name' => 'Stock Out Customer Co.',
            'payment_status' => 'clear',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('inventoryOfficer', 'salesOfficer', 'depotId', 'fuelTypeId', 'garageId', 'customerId');
    }
}
