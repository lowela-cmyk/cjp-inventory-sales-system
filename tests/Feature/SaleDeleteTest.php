<?php

namespace Tests\Feature;

use App\Services\DashboardSummaryService;
use App\Services\StockInService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PurchaseFlows;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Part 11: Delete (archive) a delivered order. Only Delivered orders can be deleted (enforced on the server); the sale
 * leaves Receivables, Stock Out and the sales totals, and its fuel stays deducted from its purchase.
 */
class SaleDeleteTest extends AuditTestCase
{
    use PurchaseFlows;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 09:00:00');
    }

    public function test_a_delivered_order_is_archived_and_its_fuel_stays_deducted(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 4000, 'unit_price' => 60], ['delivery_status' => 'delivered', 'sales_order_number' => 'SO-DEL'])->assertSessionHasNoErrors();
        $sale = DB::table('sales')->where('sales_order_number', 'SO-DEL')->first();
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 1000, 'unit_price' => 60], ['sales_order_number' => 'SO-PENDING'])->assertSessionHasNoErrors();
        $pending = DB::table('sales')->where('sales_order_number', 'SO-PENDING')->first();

        // The button shows on the delivered order only.
        $html = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $this->assertStringContainsString('data-confirm-message="Delete order '.$sale->sale_code, $html);
        $this->assertStringNotContainsString('data-confirm-message="Delete order '.$pending->sale_code, $html);

        // The server refuses anything not delivered.
        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->delete(route('sales-officer.sales.destroy', $pending->id))
            ->assertSessionHasErrors(['sale' => 'Only delivered orders can be deleted. '.$pending->sale_code.' is Pending.']);
        $this->assertNull(DB::table('sales')->where('id', $pending->id)->value('deleted_at'));

        $remaining = fn (): float => app(StockInService::class)->records()->firstWhere('purchase_item_id', $premium)->remaining;
        $this->assertSame(15000.0, $remaining());

        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->delete(route('sales-officer.sales.destroy', $sale->id))
            ->assertRedirect(route('sales-officer.sales'))
            ->assertSessionHas('status', 'Order '.$sale->sale_code.' deleted. Its fuel stays deducted from its purchase.');

        // Soft-deleted; payments, receivable and stock-outs kept; the fuel stays deducted.
        $this->assertNotNull(DB::table('sales')->where('id', $sale->id)->value('deleted_at'));
        $this->assertSame(1, DB::table('receivables')->where('sale_id', $sale->id)->count());
        $this->assertSame('released', DB::table('stock_outs')->where('sale_id', $sale->id)->value('status'));
        $this->assertSame(15000.0, $remaining());

        // Gone from Receivables, Stock Out and the totals; marked in the stock-in's Sold QTY list.
        // (The success toast names the order once; the Receivables rows no longer include it.)
        $rows = collect($this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->viewData('sales')->items());
        $this->assertFalse($rows->contains(fn (array $row): bool => $row['sale_code'] === $sale->sale_code));
        $this->assertTrue($rows->contains(fn (array $row): bool => $row['sale_code'] === $pending->sale_code));
        $stockOut = collect($this->actingAs($a['inventory'])->get(route('inventory-officer.inventory.stock-out'))->viewData('stockOut')->items());
        $this->assertFalse($stockOut->contains(fn (array $row): bool => $row['cells'][0] === $sale->sale_code));
        $this->assertSame(60000.0, (float) app(DashboardSummaryService::class)->totalSalesRevenue());
        $this->assertSame([$sale->sale_code.' (deleted order)'], array_column(array_filter(
            app(StockInService::class)->deductions([$premium])[$premium], fn (array $d): bool => $d['liters'] === 4000.0
        ), 'sale_code'));

        // Deleting it again: it no longer exists.
        $this->actingAs($a['sales'])->delete(route('sales-officer.sales.destroy', $sale->id))->assertNotFound();
    }
}
