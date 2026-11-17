<?php

namespace App\Services;

use App\Support\F1Split;
use Illuminate\Support\Facades\DB;

/** Evidence guard, never a historical repair. All portions must have their own matching released rows. */
final class DeliveryIntegrityService
{
    public function assertReleased(int $saleId): void
    {
        $sale = DB::table('sales')->find($saleId);
        $items = DB::table('sale_items')->where('sale_id', $saleId)->get()->keyBy('id');
        if (! $sale || $items->isEmpty()) throw new \RuntimeException('Delivery has no billable items.');
        $releases = DB::table('stock_outs')->where('sale_id', $saleId)->where('status', 'released')->get();
        foreach ($releases as $release) {
            $item = $items->get($release->sale_item_id);
            $fuel = $release->portion === 'f1' ? F1Split::f1FuelId() : $item?->fuel_type_id;
            if (! $item || (int) $release->customer_id !== (int) $sale->customer_id || (int) $release->fuel_type_id !== (int) $fuel || (float) $release->quantity_liters <= 0) {
                throw new \RuntimeException('Delivery has an invalid sale, customer, fuel or quantity relationship. Reconciliation requires review.');
            }
        }
        foreach ($items as $item) {
            foreach (['main' => (float) $item->quantity_liters - (float) $item->f1_quantity_liters, 'f1' => (float) $item->f1_quantity_liters] as $portion => $expected) {
                $actual = (float) $releases->where('sale_item_id', $item->id)->filter(fn ($r) => ($r->portion === 'f1' ? 'f1' : 'main') === $portion)->sum('quantity_liters');
                if (abs($expected - $actual) > .005) throw new \RuntimeException('Delivery stock-outs do not reconcile with the ordered quantity. Historical quantities and movements require review; none were invented.');
            }
        }
    }
}
