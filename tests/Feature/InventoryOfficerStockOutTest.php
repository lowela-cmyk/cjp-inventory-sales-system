<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\PurchaseStockService;
use App\Services\SaleConfirmationService;
use App\Services\StockOutReleaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Stock-out happens automatically when a sale is delivered (StockOutReleaseService); there is no manual
 * "+ Record Stock-Out" entry. Part 2 Button Revisions (October 22, 2026): a delivered line is deducted from its
 * PURCHASE (tanks are retired), at the purchase's cost.
 */
class InventoryOfficerStockOutTest extends TestCase
{
    use RefreshDatabase;

    public function test_automatic_stock_out_uses_the_purchase_cost_and_actual_payments_for_profit(): void
    {
        $records = $this->baseRecords();
        $purchase = $this->purchase($records, 100000, 60);
        $sale = $this->sale($records, [[$purchase, 100000, 65]]);
        $this->pay($records, $sale['saleId'], 6500000);

        $this->assertNull($this->deliver($records, $sale['saleId']));

        $stockOut = DB::table('stock_outs')->first();
        $this->assertSame(60.0, (float) $stockOut->unit_cost);
        $this->assertSame($purchase, (int) $stockOut->purchase_item_id);
        $this->assertSame('depot', $stockOut->source_type);

        $this->actingAs($records['inventoryOfficer'])
            ->get(route('inventory-officer.inventory.stock-out'))
            ->assertOk()
            ->assertSeeTextInOrder(['Cost / Unit', 'Total Cost', 'Price / Unit', 'Total Price', 'Total Paid', 'Source', 'Profit'])
            ->assertSeeText('6,000,000.00')
            ->assertSeeText('6,500,000.00')
            ->assertSeeText('500,000.00');

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->get(route('admin.inventory'))->assertOk()->assertSeeText('Cost / Unit');
    }

    public function test_partial_payment_is_allocated_across_releases_without_duplication(): void
    {
        $records = $this->baseRecords();
        $first = $this->purchase($records, 60000, 60);
        $second = $this->purchase($records, 40000, 60);
        // Two lines: 60,000 L and 40,000 L at 65 = 6,500,000; 3,250,000 paid.
        $sale = $this->sale($records, [[$first, 60000, 65], [$second, 40000, 65]]);
        $this->pay($records, $sale['saleId'], 3250000);

        $this->assertNull($this->deliver($records, $sale['saleId']));

        $html = $this->actingAs($records['inventoryOfficer'])->get(route('inventory-officer.inventory.stock-out'))->assertOk()->getContent();
        // Each release carries its share of the payment: 1,950,000 and 1,300,000, never the whole amount twice.
        $this->assertStringContainsString('1,950,000.00', $html);
        $this->assertStringContainsString('1,300,000.00', $html);
        $this->assertSame(2, DB::table('stock_outs')->where('status', 'released')->count());
    }

    public function test_delivery_deducts_the_purchase_once_even_when_repeated(): void
    {
        $records = $this->baseRecords();
        $purchase = $this->purchase($records, 10000, 50);
        $sale = $this->sale($records, [[$purchase, 4000, 65]]);

        $this->assertNull($this->deliver($records, $sale['saleId']));
        $this->assertNull($this->deliver($records, $sale['saleId']));
        // Releasing a fully released line again does nothing.
        $this->assertNull(app(StockOutReleaseService::class)->releaseSaleItem($sale['itemId'], '2026-08-30 12:00:00', $records['salesOfficer']->id));

        $this->assertSame(1, DB::table('stock_outs')->where('sale_id', $sale['saleId'])->count());
        $this->assertSame(6000.0, $this->remaining($purchase));
        $this->assertDatabaseHas('sale_items', ['id' => $sale['itemId'], 'fulfilled_quantity_liters' => '4000.00']);
    }

    public function test_cancelled_draft_or_undelivered_sales_are_not_eligible_for_stock_out(): void
    {
        $records = $this->baseRecords();
        $purchase = $this->purchase($records, 10000, 50);

        foreach ([['status' => 'draft'], ['status' => 'cancelled'], ['delivery_status' => 'pending']] as $overrides) {
            $sale = $this->sale($records, [[$purchase, 1000, 65]], $overrides + ['delivery_status' => 'delivered']);
            $this->assertSame('The selected sale is not eligible for stock-out.', app(StockOutReleaseService::class)->releaseSaleItem($sale['itemId'], '2026-08-30 12:00:00', $records['salesOfficer']->id));
        }

        $this->assertSame(0, DB::table('stock_outs')->count());
        $this->assertSame(10000.0, $this->remaining($purchase));
    }

    public function test_a_depot_to_client_sale_is_only_delivered_through_its_lift(): void
    {
        $records = $this->baseRecords();
        $purchase = $this->purchase($records, 10000, 50);
        $sale = $this->sale($records, [[$purchase, 3000, 65]], ['delivery_type' => 'depot_to_client']);

        $this->assertSame('This Depot to Client sale is delivered through its lift. Add it to a lift in Fuel Lifting and complete the lift first.', $this->deliver($records, $sale['saleId']));
        $this->assertSame(0, DB::table('stock_outs')->count());
        $this->assertSame(10000.0, $this->remaining($purchase));
    }

    public function test_insufficient_purchase_stock_and_unknown_sale_items_are_rejected(): void
    {
        $records = $this->baseRecords();
        $purchase = $this->purchase($records, 10000, 50);
        $sale = $this->sale($records, [[$purchase, 4000, 65]]);
        // The purchase is corrected down to 3,000 L before delivery.
        DB::table('purchase_items')->where('id', $purchase)->update(['quantity_ordered_liters' => 3000, 'line_total' => 150000]);

        $this->assertStringContainsString('has only 3,000.00 L of Diesel left for the 4,000.00 L', (string) $this->deliver($records, $sale['saleId']));
        $this->assertDatabaseHas('sales', ['id' => $sale['saleId'], 'delivery_status' => 'pending']);
        $this->assertSame(0, DB::table('stock_outs')->count());

        $this->assertSame('The selected sale is not eligible for stock-out.', app(StockOutReleaseService::class)->releaseSaleItem(999999, '2026-08-30 12:00:00', $records['salesOfficer']->id));
    }

    public function test_cancelling_a_delivered_sale_gives_the_liters_back_to_the_purchase(): void
    {
        $records = $this->baseRecords();
        $purchase = $this->purchase($records, 10000, 50);
        $sale = $this->sale($records, [[$purchase, 2500, 65]]);
        $this->assertNull($this->deliver($records, $sale['saleId']));
        $this->assertSame(7500.0, $this->remaining($purchase));

        $this->actingAs($records['salesOfficer'])->patch(route('sales-officer.sales.cancel', $sale['saleId']), ['cancel_reason' => 'customer_cancelled'])
            ->assertRedirect(route('sales-officer.sales'));

        $this->assertDatabaseHas('stock_outs', ['sale_id' => $sale['saleId'], 'status' => 'cancelled']);
        $this->assertSame(10000.0, $this->remaining($purchase));
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
     * Marks the sale Delivered the way the Sales page does. Returns the error, or null on success.
     *
     * @param  array<string, mixed>  $records
     */
    private function deliver(array $records, int $saleId): ?string
    {
        try {
            app(SaleConfirmationService::class)->updateDeliveryStatus($saleId, 'delivered', $records['salesOfficer']->id);
        } catch (\RuntimeException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $records
     */
    private function purchase(array $records, float $liters, float $unitCost): int
    {
        $code = 'PUR-SO-'.(DB::table('purchases')->count() + 1);
        $purchaseId = DB::table('purchases')->insertGetId(['purchase_code' => $code, 'depot_id' => $records['depotId'], 'purchase_date' => '2026-08-29', 'payment_status' => 'paid', 'status' => 'ordered', 'workflow_status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        return (int) DB::table('purchase_items')->insertGetId(['purchase_id' => $purchaseId, 'fuel_type_id' => $records['fuelTypeId'], 'quantity_ordered_liters' => $liters, 'unit_cost' => $unitCost, 'line_total' => $liters * $unitCost, 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @param  array<string, mixed>  $records
     * @param  array<int, array{0: int, 1: float, 2: float}>  $lines  purchase line, liters, price
     * @param  array<string, mixed>  $overrides
     * @return array{saleId: int, itemId: int}
     */
    private function sale(array $records, array $lines, array $overrides = []): array
    {
        $saleId = DB::table('sales')->insertGetId(array_merge([
            'sale_code' => 'SLS-SO-'.(DB::table('sales')->count() + 1),
            'customer_id' => $records['customerId'],
            'sale_date' => '2026-08-30',
            'payment_method' => 'cash_on_delivery',
            'payment_terms' => 'installment',
            'status' => 'confirmed',
            'delivery_status' => 'pending',
            'delivery_type' => 'standard',
            'created_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        $itemId = 0;
        foreach ($lines as [$purchase, $liters, $price]) {
            $itemId = (int) DB::table('sale_items')->insertGetId(['sale_id' => $saleId, 'fuel_type_id' => $records['fuelTypeId'], 'purchase_item_id' => $purchase, 'quantity_liters' => $liters, 'main_quantity_liters' => $liters, 'unit_price' => $price, 'line_total' => $liters * $price, 'fulfilled_quantity_liters' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('receivables')->insert(['sale_id' => $saleId, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        return ['saleId' => $saleId, 'itemId' => $itemId];
    }

    /**
     * @param  array<string, mixed>  $records
     */
    private function pay(array $records, int $saleId, float $amount): void
    {
        DB::table('payments')->insert(['payment_code' => 'PAY-SO-'.$saleId, 'sale_id' => $saleId, 'payment_date' => '2026-08-30', 'amount' => $amount, 'method' => 'cash_on_delivery', 'received_by' => $records['salesOfficer']->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function remaining(int $purchaseItemId): float
    {
        return (float) app(PurchaseStockService::class)->line($purchaseItemId)->on_hand;
    }

    /**
     * @return array<string, mixed>
     */
    private function baseRecords(): array
    {
        $inventoryOfficer = User::factory()->create(['role' => 'inventory_officer', 'status' => 'active']);
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active']);
        $depotId = DB::table('depots')->insertGetId(['depot_code' => 'DEP-STOCK-OUT', 'name' => 'Source Depot', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $fuelTypeId = (int) DB::table('fuel_types')->where('code', 'DSL')->value('id');
        $customerId = DB::table('customers')->insertGetId(['customer_code' => 'CUS-STOCK-OUT', 'name' => 'Stock Out Customer', 'company_name' => 'Stock Out Customer Co.', 'payment_status' => 'clear', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        return compact('inventoryOfficer', 'salesOfficer', 'depotId', 'fuelTypeId', 'customerId');
    }
}
