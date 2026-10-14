<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\StockOutReleaseService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Every Stock-Out source must surface in Inventory > Stock Out with its reference, date, fuel,
 * source location, quantity and status, and keep the inventory cards consistent.
 *
 * Haul allocations are inserted directly: no current app flow creates them (see the report),
 * everything downstream of the allocation goes through the normal HTTP routes.
 */
class StockOutVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-12 09:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_sale_delivered_from_garage_appears_in_stock_out_and_reduces_inventory(): void
    {
        $records = $this->records();
        $this->receiveIntoGarage($records, 20000);
        $saleId = $this->confirmedSale($records, 'SO-GARAGE', 6000);

        $before = $this->cards($records['inventory']);
        $this->assertSame('20,000 L', $before['Total Inventory']);
        $this->assertSame('0 L', $before['Stock-Out Today']);

        $this->actingAs($records['sales'])
            ->patch(route('sales-officer.sales.delivery-status.update', $saleId), ['delivery_status' => 'delivered'])
            ->assertRedirect(route('sales-officer.sales'))
            ->assertSessionHasNoErrors();

        $stockOut = DB::table('stock_outs')->where('sale_id', $saleId)->first();
        $this->assertSame('garage', $stockOut->source_type);
        $this->assertSame('released', $stockOut->status);
        $this->assertDatabaseHas('inventory_movements', ['reference_type' => 'stock_out', 'reference_id' => $stockOut->id, 'direction' => 'out', 'quantity_liters' => '6000.00']);

        $saleCode = (string) DB::table('sales')->where('id', $saleId)->value('sale_code');
        foreach ([[$records['admin'], 'admin.inventory.stock-out'], [$records['inventory'], 'inventory-officer.inventory.stock-out']] as [$user, $route]) {
            $row = $this->stockOutRow($user, $route, [], $saleCode);
            $this->assertSame('10/12/2026 09:00 AM', $row[1]);
            $this->assertSame('Diesel', $row[4]);
            $this->assertSame('6,000.00', $row[5]);
            $this->assertSame('Garage — '.$records['garageName'], $row[11]);
            $this->assertSame('Released', $row[12]);
        }

        $after = $this->cards($records['inventory']);
        $this->assertSame('14,000 L', $after['Total Inventory']);
        $this->assertSame('6,000 L', $after['Stock-Out Today']);

        // Filters: search, date and fuel include/exclude it; a depot filter cannot match a pooled garage release.
        $this->assertNotNull($this->stockOutRow($records['admin'], 'admin.inventory.stock-out', ['search' => $saleCode], $saleCode));
        $this->assertNotNull($this->stockOutRow($records['admin'], 'admin.inventory.stock-out', ['search' => $records['garageName']], $saleCode));
        $this->assertNull($this->stockOutRow($records['admin'], 'admin.inventory.stock-out', ['search' => 'NO-SUCH-REF'], $saleCode));
        $this->assertNotNull($this->stockOutRow($records['admin'], 'admin.inventory.stock-out', ['date' => '2026-10-12'], $saleCode));
        $this->assertNull($this->stockOutRow($records['admin'], 'admin.inventory.stock-out', ['date' => '2026-10-11'], $saleCode));
        $this->assertNotNull($this->stockOutRow($records['admin'], 'admin.inventory.stock-out', ['fuel_type_id' => $records['fuel']], $saleCode));
        $this->assertNull($this->stockOutRow($records['admin'], 'admin.inventory.stock-out', ['fuel_type_id' => $records['otherFuel']], $saleCode));
        $this->assertNull($this->stockOutRow($records['admin'], 'admin.inventory.stock-out', ['depot_id' => $records['depot']], $saleCode));
        $this->assertNotNull($this->stockOutRow($records['admin'], 'admin.inventory.stock-out', ['status' => 'released'], $saleCode));
    }

    public function test_sale_delivered_from_direct_depot_allocation_appears_in_stock_out_without_touching_garage(): void
    {
        $records = $this->records();
        $this->receiveIntoGarage($records, 20000);
        $saleId = $this->confirmedSale($records, 'SO-DIRECT', 5000);
        $this->completedHaul($records, 'LFT-DIRECT', 5000, ['destination_type' => 'customer', 'sale_id' => $saleId, 'customer_id' => $records['customer']]);

        $this->actingAs($records['sales'])
            ->patch(route('sales-officer.sales.delivery-status.update', $saleId), ['delivery_status' => 'delivered'])
            ->assertSessionHasNoErrors();

        $stockOut = DB::table('stock_outs')->where('sale_id', $saleId)->first();
        $this->assertSame('depot', $stockOut->source_type);
        $this->assertSame((int) $records['depot'], (int) $stockOut->depot_id);
        $this->assertNull($stockOut->inventory_movement_id);

        $saleCode = (string) DB::table('sales')->where('id', $saleId)->value('sale_code');
        $row = $this->stockOutRow($records['inventory'], 'inventory-officer.inventory.stock-out', [], $saleCode);
        $this->assertSame('5,000.00', $row[5]);
        $this->assertSame('Depot — Southern Terminal', $row[11]);
        $this->assertSame('Released', $row[12]);

        // Direct releases never enter the garage, so the garage-based cards do not move.
        $cards = $this->cards($records['inventory']);
        $this->assertSame('20,000 L', $cards['Total Inventory']);
        $this->assertSame('0 L', $cards['Stock-Out Today']);

        $this->assertNotNull($this->stockOutRow($records['admin'], 'admin.inventory.stock-out', ['depot_id' => $records['depot']], $saleCode));
        $this->assertNotNull($this->stockOutRow($records['admin'], 'admin.inventory.stock-out', ['search' => 'Southern Terminal'], $saleCode));
        $this->assertNull($this->stockOutRow($records['admin'], 'admin.inventory.stock-out', ['depot_id' => $records['otherDepot']], $saleCode));

        // The lift behind the release is reflected in Ledger > Transaction.
        $this->actingAs($records['admin'])->get(route('admin.ledger'))
            ->assertOk()
            ->assertViewHas('transactions', fn ($rows): bool => $rows->contains(fn (array $row): bool => $row['purchase_code'] === 'PUR-LFT-DIRECT' && $row['lifted_liters'] === '5,000 L'));
    }

    public function test_automatic_garage_stock_out_appears_in_stock_out_and_reduces_inventory(): void
    {
        $records = $this->records();
        $this->receiveIntoGarage($records, 20000);
        [$saleId, $saleItemId] = $this->deliveredUnfulfilledSale($records, 'SO-MANUAL-GARAGE', 3000);

        $this->assertNull($this->release($records, $saleItemId, 3000, '2026-10-12 08:30:00'));

        $row = $this->stockOutRow($records['admin'], 'admin.inventory.stock-out', [], 'SLS-SO-MANUAL-GARAGE');
        $this->assertSame('10/12/2026 08:30 AM', $row[1]);
        $this->assertSame('3,000.00', $row[5]);
        $this->assertSame('Garage — '.$records['garageName'], $row[11]);
        $this->assertSame('Released', $row[12]);

        $cards = $this->cards($records['inventory']);
        $this->assertSame('17,000 L', $cards['Total Inventory']);
        $this->assertSame('3,000 L', $cards['Stock-Out Today']);
    }

    public function test_automatic_direct_depot_release_appears_in_stock_out(): void
    {
        $records = $this->records();
        [$saleId, $saleItemId] = $this->deliveredUnfulfilledSale($records, 'SO-MANUAL-DEPOT', 4000);
        $allocationId = $this->completedHaul($records, 'LFT-MANUAL', 4000, ['destination_type' => 'customer', 'sale_id' => $saleId, 'customer_id' => $records['customer']]);

        $this->assertNull($this->release($records, $saleItemId, 4000, '2026-10-12 08:45:00'));

        $row = $this->stockOutRow($records['inventory'], 'inventory-officer.inventory.stock-out', ['depot_id' => $records['depot']], 'SLS-SO-MANUAL-DEPOT');
        $this->assertSame('4,000.00', $row[5]);
        $this->assertSame('Depot — Southern Terminal', $row[11]);
        $this->assertSame('Released', $row[12]);
        $this->assertDatabaseHas('haul_allocations', ['id' => $allocationId, 'status' => 'delivered']);
    }

    public function test_cancelled_sale_keeps_its_stock_out_visible_as_cancelled_and_restores_inventory(): void
    {
        $records = $this->records();
        $this->receiveIntoGarage($records, 20000);
        $saleId = $this->confirmedSale($records, 'SO-CANCEL', 2000);
        $this->actingAs($records['sales'])->patch(route('sales-officer.sales.delivery-status.update', $saleId), ['delivery_status' => 'delivered']);

        $this->actingAs($records['sales'])
            ->patch(route('sales-officer.sales.cancel', $saleId), ['reason' => 'Customer returned order'])
            ->assertSessionHasNoErrors();

        $saleCode = (string) DB::table('sales')->where('id', $saleId)->value('sale_code');
        $row = $this->stockOutRow($records['admin'], 'admin.inventory.stock-out', [], $saleCode);
        $this->assertSame('2,000.00', $row[5]);
        $this->assertSame('Cancelled', $row[12]);
        $this->assertNull($this->stockOutRow($records['admin'], 'admin.inventory.stock-out', ['status' => 'released'], $saleCode));
        $this->assertNotNull($this->stockOutRow($records['admin'], 'admin.inventory.stock-out', ['status' => 'cancelled'], $saleCode));

        $cards = $this->cards($records['inventory']);
        $this->assertSame('20,000 L', $cards['Total Inventory']);
        $this->assertSame('0 L', $cards['Stock-Out Today']);
    }

    /**
     * @return array<string, mixed>
     */
    private function records(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);
        $inventory = User::factory()->create(['role' => 'inventory_officer', 'status' => 'active', 'approval_status' => 'approved']);
        $sales = User::factory()->create(['role' => 'sales_officer', 'status' => 'active', 'approval_status' => 'approved']);
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active', 'approval_status' => 'approved']);
        $depot = DB::table('depots')->insertGetId(['depot_code' => 'DEP-SO', 'name' => 'Southern Terminal', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $otherDepot = DB::table('depots')->insertGetId(['depot_code' => 'DEP-SO-2', 'name' => 'Northern Terminal', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $fuel = (int) DB::table('fuel_types')->where('code', 'DSL')->value('id');
        $otherFuel = (int) DB::table('fuel_types')->where('code', 'UNL')->value('id');
        $garage = (int) DB::table('storage_locations')->where('type', 'garage')->where('fuel_type_id', $fuel)->where('status', 'active')->value('id')
            ?: DB::table('storage_locations')->insertGetId(['location_code' => 'GAR-SO', 'name' => 'Diesel Tank 1', 'type' => 'garage', 'fuel_type_id' => $fuel, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $garageName = (string) DB::table('storage_locations')->where('id', $garage)->value('name');
        $truck = DB::table('trucks')->insertGetId(['truck_code' => 'TRK-SO', 'capacity_liters' => 40000, 'truck_type' => 'hauling', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        $customer = DB::table('customers')->insertGetId(['customer_code' => 'CUS-SO', 'name' => 'Stock Out Customer', 'company_name' => 'Stock Out Co.', 'payment_status' => 'clear', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertGreaterThan(0, $fuel);
        $this->assertGreaterThan(0, $otherFuel);

        return compact('admin', 'inventory', 'sales', 'driver', 'depot', 'otherDepot', 'fuel', 'otherFuel', 'garage', 'garageName', 'truck', 'customer');
    }

    /**
     * Purchase + completed lift (+ allocation) at the Southern Terminal. Returns the allocation id.
     *
     * @param  array<string, mixed>  $records
     * @param  array<string, mixed>  $allocation
     */
    private function completedHaul(array $records, string $code, float $quantity, array $allocation): int
    {
        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => 'PUR-'.$code, 'depot_id' => $records['depot'], 'purchase_date' => '2026-10-10', 'payment_status' => 'paid',
            'status' => 'hauled', 'workflow_status' => 'completed', 'created_by' => $records['inventory']->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $itemId = DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchaseId, 'fuel_type_id' => $records['fuel'], 'quantity_ordered_liters' => $quantity, 'unit_cost' => 50,
            'line_total' => $quantity * 50, 'quantity_hauled_liters' => $quantity, 'status' => 'lifted', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $haulId = DB::table('hauls')->insertGetId([
            'haul_code' => $code, 'purchase_id' => $purchaseId, 'purchase_item_id' => $itemId, 'depot_id' => $records['depot'],
            'fuel_type_id' => $records['fuel'], 'truck_id' => $records['truck'], 'driver_user_id' => $records['driver']->id,
            'scheduled_at' => '2026-10-11 07:00:00', 'hauled_at' => '2026-10-11 10:00:00', 'quantity_liters' => $quantity,
            'status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('haul_allocations')->insertGetId(array_merge([
            'haul_id' => $haulId, 'fuel_type_id' => $records['fuel'], 'quantity_liters' => $quantity,
            'allocated_at' => '2026-10-11 10:00:00', 'status' => 'planned', 'created_at' => now(), 'updated_at' => now(),
        ], $allocation));
    }

    /**
     * @param  array<string, mixed>  $records
     */
    private function receiveIntoGarage(array $records, float $quantity): void
    {
        $allocationId = $this->completedHaul($records, 'LFT-GARAGE-'.Str::upper(Str::random(4)), $quantity, [
            'destination_type' => 'garage', 'storage_location_id' => $records['garage'],
        ]);

        $this->actingAs($records['inventory'])->post(route('inventory-officer.inventory.stock-in.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'haul_allocation_id' => $allocationId,
            'storage_location_id' => $records['garage'],
            'quantity_liters' => $quantity,
            'movement_date' => '2026-10-11 12:00:00',
        ])->assertSessionHasNoErrors();
    }

    /**
     * @param  array<string, mixed>  $records
     */
    private function confirmedSale(array $records, string $orderNumber, float $quantity): int
    {
        $this->actingAs($records['sales'])->post(route('sales-officer.sales.store'), [
            'idempotency_key' => (string) Str::uuid(),
            'sales_order_number' => $orderNumber,
            'customer_id' => $records['customer'],
            'sale_date' => '2026-10-12',
            'items' => [['fuel_type_id' => $records['fuel'], 'quantity_liters' => $quantity, 'unit_price' => 60]],
            'amount_paid' => 0,
            'payment_method' => 'cash_on_delivery',
            'payment_terms' => 'cod',
            'status' => 'confirmed',
        ])->assertSessionHasNoErrors();

        return (int) DB::table('sales')->where('sales_order_number', $orderNumber)->value('id');
    }

    /**
     * A delivered sale that still has quantity to release, the state the manual Stock-Out form serves.
     *
     * @param  array<string, mixed>  $records
     * @return array{0: int, 1: int}
     */
    private function deliveredUnfulfilledSale(array $records, string $orderNumber, float $quantity): array
    {
        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => 'SLS-'.$orderNumber, 'sales_order_number' => $orderNumber, 'customer_id' => $records['customer'], 'sale_date' => '2026-10-12',
            'payment_method' => 'cash_on_delivery', 'payment_terms' => 'cod', 'status' => 'confirmed', 'delivery_status' => 'delivered',
            'created_by' => $records['sales']->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $saleItemId = DB::table('sale_items')->insertGetId([
            'sale_id' => $saleId, 'fuel_type_id' => $records['fuel'], 'quantity_liters' => $quantity, 'unit_price' => 60,
            'line_total' => $quantity * 60, 'fulfilled_quantity_liters' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('receivables')->insert(['sale_id' => $saleId, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        return [$saleId, $saleItemId];
    }

    /**
     * Returns the Stock Out row cells whose reference matches, or null when the row is not listed.
     *
     * @param  array<string, mixed>  $query
     * @return array<int, string>|null
     */
    private function stockOutRow(User $user, string $route, array $query, string $reference): ?array
    {
        $rows = null;
        $this->actingAs($user)->get(route($route, $query))
            ->assertOk()
            ->assertViewHas('stockOut', function ($paginator) use (&$rows): bool {
                $rows = collect($paginator->items());

                return true;
            });

        return $rows->first(fn (array $row): bool => $row['cells'][0] === $reference)['cells'] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function cards(User $user): array
    {
        $cards = [];
        $this->actingAs($user)->get(route('inventory-officer.inventory'))
            ->assertOk()
            ->assertViewHas('summaryCards', function (array $summary) use (&$cards): bool {
                $cards = collect($summary)->pluck('value', 'label')->all();

                return true;
            });

        return $cards;
    }

    /**
     * Stock-out is automatic on delivery: release through the same service the sale flow uses.
     *
     * @param  array<string, mixed>  $records
     */
    private function release(array $records, int $saleItemId, float $quantity, string $stockOutAt): ?string
    {
        return DB::transaction(fn (): ?string => app(StockOutReleaseService::class)->releaseSaleItemFromGarage(
            $saleItemId, $quantity, $stockOutAt, (int) $records['inventory']->id, allowPreparation: false,
        ));
    }
}
