<?php

namespace App\Services;

use App\Support\F1Split;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Part 2 Button Revisions (October 22, 2026): a delivered sale line is released from its PURCHASES (tanks are
 * retired). Its own fuel (main_quantity_liters) leaves purchase_item_id and its F1 portion (f1_quantity_liters)
 * leaves f1_purchase_item_id. Each release is one stock-out row naming the purchase it was deducted from.
 *
 * Releasing is idempotent per portion: only liters not released yet for the line are deducted, so a sale and the
 * lift that delivers it can never take the same liters twice. Standard sales are released when their Delivery Status
 * becomes Delivered; Depot to Client sales when their lift is completed ($allocation = that lift's delivery row).
 */
class StockOutReleaseService
{
    public function __construct(
        private readonly IdempotencyService $idempotencyService,
        private readonly PurchaseStockService $purchaseStock,
        private readonly GarageStockService $garageStock
    ) {}

    /**
     * @return ?string an error message, or null when the line is fully released
     */
    public function releaseSaleItem(int $saleItemId, string $stockOutAt, int $createdBy, ?object $allocation = null, ?string $remarks = null): ?string
    {
        $item = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'sale_items.fuel_type_id')
            ->where('sale_items.id', $saleItemId)
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', ['confirmed', 'partially_paid', 'paid', 'unpaid'])
            ->where('sales.delivery_status', 'delivered')
            ->lockForUpdate()
            ->first([
                'sale_items.id',
                'sale_items.sale_id',
                'sale_items.fuel_type_id',
                'sale_items.purchase_item_id',
                'sale_items.f1_purchase_item_id',
                'sale_items.garage_stock_id',
                'sale_items.f1_garage_stock_id',
                'sale_items.quantity_liters',
                'sale_items.f1_quantity_liters',
                'sales.customer_id',
                'sales.sale_code',
                'fuel_types.name as fuel_name',
            ]);

        if (! $item) {
            return 'The selected sale is not eligible for stock-out.';
        }

        $f1 = round((float) $item->f1_quantity_liters, 2);
        $main = round((float) $item->quantity_liters - $f1, 2);
        $released = DB::table('stock_outs')
            ->where('sale_item_id', $item->id)
            ->where('status', 'released')
            ->lockForUpdate()
            ->selectRaw("COALESCE(SUM(CASE WHEN stock_outs.portion = 'f1' THEN quantity_liters ELSE 0 END), 0) as f1, COALESCE(SUM(CASE WHEN stock_outs.portion = 'f1' THEN 0 ELSE quantity_liters END), 0) as main")
            ->first();
        $mainDue = round(max(0, $main - (float) $released->main), 2);
        $f1Due = round(max(0, $f1 - (float) $released->f1), 2);

        // A line recorded before purchases were chosen comes from the purchase of its lift (Depot to Client), else from
        // the oldest purchase of its fuel that still holds the liters. The purchase used is then kept on the line.
        $f1FuelId = (int) (F1Split::f1FuelId() ?? 0);
        $mainPurchaseId = $item->purchase_item_id
            ?: ($allocation ? DB::table('hauls')->where('id', $allocation->haul_id)->value('purchase_item_id') : null)
            ?: ($mainDue > 0 ? $this->oldestPurchaseWithStock((int) $item->fuel_type_id, $mainDue) : null);
        $f1PurchaseId = $item->f1_purchase_item_id ?: ($f1Due > 0 ? $this->oldestPurchaseWithStock($f1FuelId, $f1Due) : null);

        $portions = [];
        if ($mainDue > 0) {
            if (! $mainPurchaseId) {
                return 'No purchase of '.$item->fuel_name.' has the '.number_format($mainDue, 2).' L of order '.$item->sale_code.' left.';
            }
            $portions[] = ['main', (int) $mainPurchaseId, (int) $item->fuel_type_id, $mainDue, $item->garage_stock_id ? (int) $item->garage_stock_id : null];
        }
        if ($f1Due > 0) {
            if (! $f1PurchaseId) {
                return 'No F1 purchase has the '.number_format($f1Due, 2).' L F1 portion of order '.$item->sale_code.' left.';
            }
            $portions[] = ['f1', (int) $f1PurchaseId, $f1FuelId, $f1Due, $item->f1_garage_stock_id ? (int) $item->f1_garage_stock_id : null];
        }
        if ($portions === []) {
            return null;
        }

        // Check every portion before writing anything, so a short purchase leaves the line untouched.
        $lines = $this->purchaseStock->lines(array_column($portions, 1), true, true);
        // Part 13: a Garage to Client portion leaves its garage lot (locked); it must still hold the liters.
        $lots = $this->garageStock->lots(['with_empty' => true], null, array_values(array_filter(array_column($portions, 4))) ?: [0], true);
        $neededFromLot = [];
        foreach ($portions as [$portion, , , $liters, $lotId]) {
            if (! $lotId) {
                continue;
            }
            $lot = $lots->get($lotId);
            $neededFromLot[$lotId] = round(($neededFromLot[$lotId] ?? 0) + $liters, 2);
            if (! $lot) {
                return 'The garage stock of order '.$item->sale_code.' is no longer available.';
            }
            if ($neededFromLot[$lotId] > $lot->in_garage) {
                return $lot->tank_name.' ('.$lot->purchase_code.') has only '.number_format($lot->in_garage, 2).' L left for the '.number_format($liters, 2).' L of order '.$item->sale_code.'.';
            }
        }
        $needed = [];
        foreach ($portions as [$portion, $purchaseItemId, , $liters]) {
            $line = $lines->get($purchaseItemId);
            $needed[$purchaseItemId] = round(($needed[$purchaseItemId] ?? 0) + $liters, 2);
            if (! $line) {
                return 'The '.($portion === 'f1' ? 'F1 ' : '').'purchase of order '.$item->sale_code.' is no longer available.';
            }
            if ($needed[$purchaseItemId] > $line->on_hand) {
                return $line->purchase_code.' has only '.number_format($line->on_hand, 2).' L of '.$line->fuel_name.' left for the '.number_format($liters, 2).' L of order '.$item->sale_code.'.';
            }
        }

        foreach ($portions as [$portion, $purchaseItemId, $fuelTypeId, $liters, $lotId]) {
            $line = $lines->get($purchaseItemId);
            $this->insertRelease($item, $portion, $line, $fuelTypeId, $liters, $stockOutAt, $createdBy, $allocation, $remarks, $lotId ? $lots->get($lotId) : null);
        }

        // Keep the purchases a line recorded without them was deducted from.
        $link = array_filter([
            'purchase_item_id' => $item->purchase_item_id ? null : $mainPurchaseId,
            'f1_purchase_item_id' => $item->f1_purchase_item_id || $f1Due <= 0 ? null : $f1PurchaseId,
        ]);
        if ($link !== []) {
            DB::table('sale_items')->where('id', $item->id)->update($link + ['updated_at' => now()]);
        }

        return null;
    }

    /** The oldest purchase of a fuel whose stock (ordered - delivered) still covers $liters. */
    private function oldestPurchaseWithStock(int $fuelTypeId, float $liters): ?int
    {
        $line = $this->purchaseStock->lines()
            ->where('fuel_type_id', $fuelTypeId)
            ->first(fn (object $line): bool => $line->on_hand >= $liters);

        return $line ? (int) $line->id : null;
    }

    private function insertRelease(object $item, string $portion, object $line, int $fuelTypeId, float $liters, string $stockOutAt, int $createdBy, ?object $allocation, ?string $remarks, ?object $lot = null): void
    {
        DB::table('stock_outs')
            ->where('sale_item_id', $item->id)
            ->where('status', 'prepared')
            ->update(['status' => 'cancelled', 'updated_at' => now()]);

        DB::table('sale_items')->where('id', $item->id)->update([
            'fulfilled_quantity_liters' => DB::raw('fulfilled_quantity_liters + '.$liters),
            'updated_at' => now(),
        ]);

        $this->idempotencyService->retryOnCollision('stock_out_code', function () use ($item, $portion, $line, $fuelTypeId, $liters, $stockOutAt, $createdBy, $allocation, $lot): void {
            DB::table('stock_outs')->insert([
                'stock_out_code' => $this->nextCode(),
                'sale_id' => $item->sale_id,
                'sale_item_id' => $item->id,
                'customer_id' => $item->customer_id,
                'fuel_type_id' => $fuelTypeId,
                'portion' => $portion,
                // Part 13: a garage lot's liters leave the garage (its tank and the lift row that unloaded it).
                'source_type' => $lot ? 'garage' : 'depot',
                'depot_id' => $line->depot_id,
                'storage_location_id' => $lot?->storage_location_id,
                // The lift row that carried the order (Depot to Client), when there is one. A garage release has none
                // (the stock-out shape rule): its lot already names the lift row that unloaded it.
                'haul_allocation_id' => $lot ? null : $allocation?->id,
                'garage_stock_id' => $lot?->id,
                'purchase_item_id' => $line->id,
                'quantity_liters' => $liters,
                'unit_cost' => $lot ? $lot->unit_cost : $line->unit_cost,
                'stock_out_at' => $stockOutAt,
                'status' => 'released',
                'created_by' => $createdBy,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    private function nextCode(): string
    {
        do {
            $code = 'STO-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        } while (DB::table('stock_outs')->where('stock_out_code', $code)->exists());

        return $code;
    }
}
