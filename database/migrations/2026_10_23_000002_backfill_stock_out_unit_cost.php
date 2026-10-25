<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stock-In revisions (October 23, 2026): a stock-out keeps the cost/liter it was released at, so editing the purchase
 * later never changes Total Cost, Profit or the dashboard margin. Releases saved without one take their purchase's
 * current cost/liter.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('stock_outs')
            ->join('purchase_items', 'purchase_items.id', '=', 'stock_outs.purchase_item_id')
            ->whereNull('stock_outs.unit_cost')
            ->orderBy('stock_outs.id')
            ->get(['stock_outs.id', 'purchase_items.unit_cost'])
            ->each(fn (object $row) => DB::table('stock_outs')->where('id', $row->id)->update(['unit_cost' => $row->unit_cost]));
    }

    public function down(): void
    {
        // The saved costs stay: they are the values the releases were reported at.
    }
};
