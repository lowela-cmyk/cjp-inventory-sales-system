<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Stock lots (Part 8 #8): every incoming inventory movement is a lot. That is a stock-in from a
 * completed lift (one purchase in one garage tank), or an opening balance. A sale line can name its lot
 * (sale_items.stock_in_movement_id); delivering it deducts from that lot, at that lot's cost.
 *
 * A lot's stock is worked out from the ledger, so it always agrees with the tank balances:
 * - on hand = received − releases of sale lines that name this lot − its FIFO share of older releases
 *   that named no lot (the pooled garage releases made before lots existed are charged oldest-first);
 * - reserved = undelivered sale lines that name this lot;
 * - available = on hand − reserved (what Record Sales may still promise).
 */
class StockLotService
{
    /**
     * Lots with their stock figures, optionally limited to some lot ids (their tank groups are still
     * fully computed, so FIFO shares are right). With $lock the incoming movements are row-locked.
     *
     * @param  array<int, int>|null  $onlyIds
     * @return Collection<int, \stdClass>
     */
    public function lots(?array $onlyIds = null, bool $lock = false): Collection
    {
        $groups = null;
        if ($onlyIds !== null) {
            $groups = DB::table('inventory_movements')->whereIn('id', $onlyIds)->get(['storage_location_id', 'fuel_type_id']);
            if ($groups->isEmpty()) {
                return collect();
            }
        }

        $scope = function (Builder $query) use ($groups): void {
            if ($groups === null) {
                return;
            }
            $query->where(function (Builder $query) use ($groups): void {
                foreach ($groups->unique(fn (object $group): string => $group->storage_location_id.'|'.$group->fuel_type_id) as $group) {
                    $query->orWhere(fn (Builder $query) => $query->where('inventory_movements.storage_location_id', $group->storage_location_id)
                        ->where('inventory_movements.fuel_type_id', $group->fuel_type_id));
                }
            });
        };

        $incoming = DB::table('inventory_movements')
            ->join('fuel_types', 'fuel_types.id', '=', 'inventory_movements.fuel_type_id')
            ->leftJoin('storage_locations', 'storage_locations.id', '=', 'inventory_movements.storage_location_id')
            ->leftJoin('haul_allocations', function ($join): void {
                $join->on('haul_allocations.id', '=', 'inventory_movements.reference_id')
                    ->where('inventory_movements.reference_type', '=', StockInService::STOCK_IN_REFERENCE_TYPE);
            })
            ->leftJoin('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
            ->leftJoin('purchases', 'purchases.id', '=', 'hauls.purchase_id')
            ->where('inventory_movements.direction', 'in')
            ->whereNotExists($this->cancelledHaulAllocation())
            ->where($scope)
            ->orderBy('inventory_movements.movement_date')
            ->orderBy('inventory_movements.id')
            ->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())
            ->get([
                'inventory_movements.id',
                'inventory_movements.storage_location_id',
                'inventory_movements.fuel_type_id',
                'inventory_movements.quantity_liters',
                'inventory_movements.unit_cost',
                'inventory_movements.movement_date',
                'inventory_movements.movement_type',
                'fuel_types.name as fuel_name',
                'storage_locations.name as tank_name',
                'purchases.purchase_code',
            ]);

        // Released stock leaving each tank, with the lot its sale line named (null = pooled, pre-lot release).
        $outgoing = DB::table('inventory_movements')
            ->leftJoin('stock_outs', function ($join): void {
                $join->on('stock_outs.id', '=', 'inventory_movements.reference_id')
                    ->where('inventory_movements.reference_type', '=', 'stock_out');
            })
            ->leftJoin('sale_items', 'sale_items.id', '=', 'stock_outs.sale_item_id')
            ->where('inventory_movements.direction', 'out')
            ->where(fn (Builder $query) => $query->whereNull('stock_outs.id')->orWhere('stock_outs.status', '!=', 'cancelled'))
            ->where($scope)
            ->groupBy('inventory_movements.storage_location_id', 'inventory_movements.fuel_type_id', 'sale_items.stock_in_movement_id')
            ->selectRaw('inventory_movements.storage_location_id, inventory_movements.fuel_type_id, sale_items.stock_in_movement_id as lot_id, SUM(inventory_movements.quantity_liters) as liters')
            ->get();

        $reserved = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereNotNull('sale_items.stock_in_movement_id')
            ->whereNull('sales.deleted_at')
            ->where('sales.status', '!=', 'cancelled')
            ->whereNotIn('sales.delivery_status', ['delivered', 'cancelled'])
            ->groupBy('sale_items.stock_in_movement_id')
            ->selectRaw('sale_items.stock_in_movement_id as lot_id, SUM(sale_items.quantity_liters - sale_items.fulfilled_quantity_liters) as liters')
            ->pluck('liters', 'lot_id');

        $lots = collect();
        foreach ($incoming->groupBy(fn (object $lot): string => $lot->storage_location_id.'|'.$lot->fuel_type_id) as $key => $group) {
            [$tankId, $fuelTypeId] = array_map('intval', explode('|', $key));
            $groupOut = $outgoing->filter(fn (object $row): bool => (int) $row->storage_location_id === $tankId && (int) $row->fuel_type_id === $fuelTypeId);
            $linked = $groupOut->whereNotNull('lot_id')->mapWithKeys(fn (object $row): array => [(int) $row->lot_id => (float) $row->liters]);
            $pooled = round((float) $groupOut->whereNull('lot_id')->sum('liters'), 2);

            foreach ($group as $lot) {
                $onHand = round(max(0, (float) $lot->quantity_liters - (float) ($linked[(int) $lot->id] ?? 0)), 2);
                $fromPool = min($onHand, $pooled);
                $pooled = round($pooled - $fromPool, 2);
                $lot->on_hand = round($onHand - $fromPool, 2);
                $lot->reserved = round(max(0, (float) ($reserved[(int) $lot->id] ?? 0)), 2);
                $lot->available = round(max(0, $lot->on_hand - $lot->reserved), 2);
                $lot->released = round((float) $lot->quantity_liters - $lot->on_hand, 2);
                $lot->source_code = $lot->purchase_code ?: ($lot->movement_type === 'beginning' ? 'Opening stock' : 'Stock-in #'.$lot->id);
                $lot->label = $lot->source_code.' · '.$lot->fuel_name.' · '.number_format($lot->available, 0).' L in stock · ₱'.number_format((float) $lot->unit_cost, 2).'/L';
                $lots->push($lot);
            }
        }

        return $onlyIds === null ? $lots : $lots->whereIn('id', $onlyIds)->values();
    }

    /** One lot with its figures, row-locked for a sale or a release. */
    public function lockedLot(int $lotId): ?\stdClass
    {
        return $this->lots([$lotId], true)->first();
    }

    /**
     * Lots Record Sales can offer: stock available, grouped by fuel then oldest first.
     * $keep adds lots already chosen on the sale being edited even when nothing more is available.
     *
     * @param  array<int, int>  $keep
     * @return Collection<int, \stdClass>
     */
    public function saleOptions(array $keep = []): Collection
    {
        return $this->lots()
            ->filter(fn (object $lot): bool => $lot->available > 0 || in_array((int) $lot->id, $keep, true))
            ->sortBy([['fuel_name', 'asc'], ['movement_date', 'asc'], ['id', 'asc']])
            ->values();
    }

    private function cancelledHaulAllocation(): \Closure
    {
        return function (Builder $query): void {
            $query->selectRaw('1')
                ->from('haul_allocations as cancelled_allocations')
                ->whereColumn('cancelled_allocations.id', 'inventory_movements.reference_id')
                ->where('inventory_movements.reference_type', StockInService::STOCK_IN_REFERENCE_TYPE)
                ->where('cancelled_allocations.status', 'cancelled');
        };
    }
}
