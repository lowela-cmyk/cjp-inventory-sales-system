<?php

namespace Tests\Concerns;

use App\Services\PurchaseStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Part 2 Button Revisions (October 22, 2026): the real HTTP flows of purchase-based stock. Use with AuditTestCase
 * (actors(): admin, inventory, sales, dispatch, driver, depot, fuel (Diesel), truck, customer).
 */
trait PurchaseFlows
{
    protected function fuel(string $code): int
    {
        return (int) DB::table('fuel_types')->where('code', $code)->value('id');
    }

    /**
     * Records a purchase through Inventory and returns its purchase line id.
     *
     * @param  array<string, mixed>  $a
     */
    protected function purchaseLine(array $a, string $fuelCode, float $liters, float $unitCost = 50): int
    {
        $this->actingAs($a['inventory'])->post(route('inventory-officer.inventory.purchases.store'), [
            'idempotency_key' => (string) Str::uuid(), 'purchase_date' => '2026-10-14', 'depot_id' => $a['depot'], 'fuel_type_id' => $this->fuel($fuelCode),
            'quantity_ordered_liters' => $liters, 'unit_cost' => $unitCost, 'payment_status' => 'unpaid', 'payment_mode' => 'cash',
        ])->assertSessionHasNoErrors();

        return (int) DB::table('purchase_items')->orderByDesc('id')->value('id');
    }

    /**
     * Record Sales with one order line.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $line  purchase_item_id, quantity_liters, unit_price, f1_percentage, f1_purchase_item_id, f1_unit_price
     * @param  array<string, mixed>  $extra
     */
    protected function recordOrder(array $a, array $line, array $extra = []): TestResponse
    {
        return $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->post(route('sales-officer.sales.store'), $extra + [
            'idempotency_key' => (string) Str::uuid(), 'form_context' => 'record-sale', 'delivery_type' => 'standard',
            'customer_id' => $a['customer'], 'sale_date' => '2026-10-15', 'items' => [$line],
            'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment',
        ]);
    }

    protected function lastSaleId(): int
    {
        return (int) DB::table('sales')->orderByDesc('id')->value('id');
    }

    /**
     * @param  array<string, mixed>  $a
     */
    protected function deliverOrder(array $a, int $saleId): TestResponse
    {
        return $this->actingAs($a['sales'])->from(route('sales-officer.sales'))
            ->patch(route('sales-officer.sales.delivery-status.update', $saleId), ['delivery_status' => 'delivered']);
    }

    /**
     * Schedule Lift: purchases on the truck and the Depot to Client orders it delivers.
     *
     * @param  array<string, mixed>  $a
     * @param  array<int, array<string, mixed>>  $items  purchase_item_id, quantity_liters, atl_number
     * @param  array<int, int>  $saleIds
     */
    protected function scheduleLift(array $a, array $items, array $saleIds = [], array $extra = []): TestResponse
    {
        return $this->actingAs($a['dispatch'])->from(route('dispatch.fuel-lifting'))->post(route('dispatch.fuel-lifting.hauls.store'), $extra + [
            'idempotency_key' => (string) Str::uuid(), 'form_context' => 'schedule-lift', 'items' => $items,
            'deliveries' => array_map(fn (int $saleId): array => ['sale_id' => $saleId], $saleIds),
            'driver_user_id' => $a['driver']->id, 'truck_id' => $a['truck'], 'scheduled_at' => '2026-10-16 08:00:00', 'dr_number' => 'DR-2026-001',
        ]);
    }

    protected function lastHaulId(): int
    {
        return (int) DB::table('hauls')->whereNotNull('lifting_schedule_id')->orderByDesc('id')->value('id');
    }

    /**
     * The driver takes the lift In Transit, then Lifted (which delivers its Depot to Client orders).
     *
     * @param  array<string, mixed>  $a
     */
    protected function completeLift(array $a, int $haulId): void
    {
        foreach (['in_transit', 'lifted'] as $status) {
            $this->actingAs($a['driver'])->patch(route('driver.fuel-lifting.hauls.status', $haulId), ['idempotency_key' => (string) Str::uuid(), 'lifting_status' => $status])
                ->assertSessionHasNoErrors();
        }
    }

    /** Remaining liters (stock) of a purchase line: ordered - delivered. */
    protected function remaining(int $purchaseItemId): float
    {
        return (float) app(PurchaseStockService::class)->line($purchaseItemId)->on_hand;
    }

    /** Liters a new order can still take: remaining - promised to undelivered orders. */
    protected function available(int $purchaseItemId): float
    {
        return (float) app(PurchaseStockService::class)->line($purchaseItemId)->available;
    }
}
