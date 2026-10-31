<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PurchaseFlows;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Part 17 #4 (replaces Part 11's Delete of delivered orders): a Delivered order already left stock (Stock Out), so it
 * can't be deleted. No Delete button is shown and the server refuses every delete; open orders use Cancel Sale.
 */
class SaleDeleteTest extends AuditTestCase
{
    use PurchaseFlows;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 09:00:00');
    }

    public function test_orders_cannot_be_deleted_and_no_delete_button_is_shown(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 4000, 'unit_price' => 60], ['delivery_status' => 'delivered', 'sales_order_number' => 'SO-DEL'])->assertSessionHasNoErrors();
        $sale = DB::table('sales')->where('sales_order_number', 'SO-DEL')->first();
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 1000, 'unit_price' => 60], ['sales_order_number' => 'SO-PENDING'])->assertSessionHasNoErrors();
        $pending = DB::table('sales')->where('sales_order_number', 'SO-PENDING')->first();

        foreach ([[$a['admin'], 'admin.sales'], [$a['sales'], 'sales-officer.sales']] as [$user, $route]) {
            $html = $this->actingAs($user)->get(route($route))->assertOk()->getContent();
            $this->assertStringNotContainsString('data-confirm-message="Delete order', $html);
            $this->assertStringNotContainsString('/sales/'.$sale->id.'" method="POST"', $html);
        }

        // The Sales Officer still may not call it at all; Admin is refused with the reason.
        $this->actingAs($a['sales'])->delete(route('sales-officer.sales.destroy', $sale->id))->assertForbidden();
        $this->actingAs($a['admin'])->from(route('admin.sales'))->delete(route('admin.sales.destroy', $sale->id))
            ->assertRedirect(route('admin.sales'))
            ->assertSessionHasErrors(['sale' => $sale->sale_code.' is Delivered: its fuel was already deducted from stock (Stock Out), so it cannot be deleted.']);
        $this->actingAs($a['admin'])->from(route('admin.sales'))->delete(route('admin.sales.destroy', $pending->id))
            ->assertSessionHasErrors(['sale' => $pending->sale_code.' is not delivered: use Cancel Sale in its View instead.']);

        $this->assertNull(DB::table('sales')->where('id', $sale->id)->value('deleted_at'));
        $this->assertNull(DB::table('sales')->where('id', $pending->id)->value('deleted_at'));
        $this->assertSame('released', DB::table('stock_outs')->where('sale_id', $sale->id)->value('status'));
        $this->assertSame(16000.0, $this->remaining($premium));
    }
}
