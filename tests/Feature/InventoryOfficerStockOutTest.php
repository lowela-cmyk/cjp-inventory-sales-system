<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StockOutFinancialService;
use App\Services\StockOutReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Stock-out happens automatically when a sale is delivered (StockOutReleaseService); there is no manual
 * "+ Record Stock-Out" entry any more. These tests drive that automatic release directly.
 */
class InventoryOfficerStockOutTest extends TestCase
{
    use RefreshDatabase;

    public function test_automatic_stock_out_uses_inventory_cost_and_actual_payments_for_profit(): void
    {
        $records = $this->baseRecords();
        $this->garageMovement($records, ['quantity_liters' => 100000, 'unit_cost' => 60]);
        $sale = $this->sale($records, ['quantity_liters' => 100000, 'unit_price' => 65]);

        DB::table('payments')->insert([
            'payment_code' => 'PAY-STOCK-OUT-FULL',
            'sale_id' => $sale['saleId'],
            'payment_date' => '2026-08-30',
            'amount' => 6500000,
            'method' => 'cash_on_delivery',
            'received_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNull($this->release($records, $sale, 100000));

        $stockOut = DB::table('stock_outs')->first();
        $this->assertSame(60.0, (float) $stockOut->unit_cost);
        $this->assertDatabaseHas('inventory_movements', [
            'id' => $stockOut->inventory_movement_id,
            'unit_cost' => '60.00',
        ]);

        $this->actingAs($records['inventoryOfficer'])
            ->get(route('inventory-officer.inventory.stock-out'))
            ->assertOk()
            ->assertSeeTextInOrder([
                'Cost / Unit',
                'Total Cost',
                'Price / Unit',
                'Total Price',
                'Total Paid',
                'Source',
                'Profit',
            ])
            ->assertSeeText('6,000,000.00')
            ->assertSeeText('6,500,000.00')
            ->assertSeeText('500,000.00');

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)
            ->get(route('admin.inventory'))
            ->assertOk()
            ->assertSeeText('Cost / Unit')
            ->assertSeeText('6,000,000.00')
            ->assertSeeText('500,000.00');
    }

    public function test_partial_payment_is_allocated_across_releases_without_duplication(): void
    {
        $records = $this->baseRecords();
        $this->garageMovement($records, ['quantity_liters' => 100000, 'unit_cost' => 60]);
        $sale = $this->sale($records, ['quantity_liters' => 100000, 'unit_price' => 65]);

        DB::table('payments')->insert([
            'payment_code' => 'PAY-STOCK-OUT-PARTIAL',
            'sale_id' => $sale['saleId'],
            'payment_date' => '2026-08-30',
            'amount' => 3250000,
            'method' => 'bank_transfer',
            'received_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (['2026-08-30 11:00:00', '2026-08-30 12:00:00'] as $stockOutAt) {
            $this->assertNull($this->release($records, $sale, 50000));
        }

        $financials = app(StockOutFinancialService::class);
        $rows = $financials->query()->orderBy('stock_outs.id')->get()->map(fn (object $row): array => $financials->calculate($row));

        $this->assertCount(2, $rows);
        $this->assertSame(3250000.0, round((float) $rows->sum('total_paid'), 2));
        $this->assertSame(6000000.0, round((float) $rows->sum('total_cost'), 2));
        $this->assertSame(-2750000.0, round((float) $rows->sum('profit'), 2));
    }

    public function test_sale_release_records_garage_stock_out_and_deducts_inventory_once(): void
    {
        $records = $this->baseRecords();
        $this->garageMovement($records, ['quantity_liters' => 20000]);
        $sale = $this->sale($records, ['quantity_liters' => 15000]);

        $this->assertNull($this->release($records, $sale, 12000));

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

        $this->assertNull($this->release($records, $sale, 12000));

        $this->assertNotNull($this->release($records, $sale, 9000));

        $this->assertNull($this->release($records, $sale, 8000));

        $this->assertSame(10000.0, $this->garageBalance($records));
        $this->assertSame(2, DB::table('stock_outs')->count());
        $this->assertDatabaseHas('sale_items', [
            'id' => $sale['saleItemId'],
            'fulfilled_quantity_liters' => '20000.00',
        ]);
    }

    public function test_cancelled_draft_or_undelivered_sales_are_not_eligible_for_stock_out(): void
    {
        $records = $this->baseRecords();
        $this->garageMovement($records, ['quantity_liters' => 20000]);
        $cancelledSale = $this->sale($records, ['status' => 'cancelled']);
        $draftSale = $this->sale($records, ['sale_code' => 'SLS-DRAFT', 'status' => 'draft']);
        $pendingSale = $this->sale($records, ['sale_code' => 'SLS-PENDING', 'delivery_status' => 'pending']);

        foreach ([$cancelledSale, $draftSale, $pendingSale] as $sale) {
            $this->assertNotNull($this->release($records, $sale, 5000));
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

        $this->assertNull($this->release($records, $sale, 10000));

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

        $this->assertNotNull($this->release($records, $sale, 5000));

        $this->assertSame(0, DB::table('stock_outs')->count());
        $this->assertDatabaseHas('sale_items', [
            'id' => $sale['saleItemId'],
            'fulfilled_quantity_liters' => '0.00',
        ]);
    }

    public function test_insufficient_inventory_and_unknown_sale_items_are_rejected(): void
    {
        $records = $this->baseRecords();
        $this->garageMovement($records, ['quantity_liters' => 5000]);
        $sale = $this->sale($records, ['quantity_liters' => 10000]);

        $this->assertSame('Insufficient available garage inventory to complete delivery.', $this->release($records, $sale, 6000));
        $this->assertSame('The selected sale is not eligible for stock-out.', $this->release($records, ['saleItemId' => 999999], 1000));

        $this->assertSame(5000.0, $this->garageBalance($records));
        $this->assertSame(0, DB::table('stock_outs')->count());
    }

    public function test_manual_stock_out_entry_is_removed(): void
    {
        $this->assertFalse(Route::has('inventory-officer.inventory.stock-out.store'));
        $this->assertFalse(Route::has('admin.inventory.stock-out.store'));

        $records = $this->baseRecords();
        $this->actingAs($records['inventoryOfficer'])
            ->post('/inventory-officer/inventory/stock-out', ['quantity_liters' => 100])
            ->assertStatus(405);

        $this->actingAs($records['inventoryOfficer'])
            ->get(route('inventory-officer.inventory.stock-out'))
            ->assertOk()
            ->assertDontSee('+ Record Stock-Out')
            ->assertDontSee('io-stockout-add');
    }

    /**
     * Release through the same service the sale delivery flow uses. Returns the error, or null on success.
     *
     * @param  array<string, mixed>  $records
     * @param  array<string, mixed>  $sale
     */
    private function release(array $records, array $sale, float $quantity, string $stockOutAt = '2026-08-30 11:00:00'): ?string
    {
        return DB::transaction(fn (): ?string => app(StockOutReleaseService::class)->releaseSaleItemFromGarage(
            (int) $sale['saleItemId'],
            $quantity,
            $stockOutAt,
            (int) $records['inventoryOfficer']->id,
            (int) $records['garageId'],
            'Release to client',
            allowPreparation: false,
        ));
    }

    /**
     * @param  array<string, mixed>  $records
     * @param  array<string, mixed>  $overrides
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
     * @param  array<string, mixed>  $records
     * @param  array<string, mixed>  $overrides
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
            'delivery_status' => 'delivered',
            'created_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], collect($overrides)->only(['sale_code', 'status', 'delivery_status'])->all()));

        $unitPrice = $overrides['unit_price'] ?? 62.50;
        $saleItemId = DB::table('sale_items')->insertGetId([
            'sale_id' => $saleId,
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_liters' => $overrides['quantity_liters'] ?? 10000,
            'unit_price' => $unitPrice,
            'line_total' => (($overrides['quantity_liters'] ?? 10000) * $unitPrice),
            'fulfilled_quantity_liters' => $overrides['fulfilled_quantity_liters'] ?? 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('saleId', 'saleItemId');
    }

    /**
     * @param  array<string, mixed>  $records
     * @param  array<string, mixed>  $sale
     * @param  array<string, mixed>  $overrides
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
     * @param  array<string, mixed>  $records
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
        $fuelTypeId = DB::table('fuel_types')->where('code', 'DSL')->value('id');
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
