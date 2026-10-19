<?php

namespace App\Services;

use App\Support\F1Split;
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
 * - reserved = undelivered sale lines that name this lot, plus its FIFO share of undelivered F1 portions
 *   (Part 9: the F1 part of an Unleaded/Premium line is released from F1 tanks, oldest lot first);
 * - available = on hand − reserved (what Record Sales may still promise).
 *
 * A line's lot only covers releases of the line's own fuel; its F1 releases are pooled F1 releases.
 */
class StockLotService
{
    /** The lot a release is charged to: the sale line's lot, but only for releases of the line's own fuel. */
    private const RELEASE_LOT = 'CASE WHEN stock_outs.fuel_type_id = sale_items.fuel_type_id THEN sale_items.stock_in_movement_id END';

    /**
     * Lots with their stock figures, optionally limited to some lot ids (every lot of their fuel is still
     * computed, so FIFO shares are right). With $lock the incoming movements are row-locked.
     *
     * @param  array<int, int>|null  $onlyIds
     * @return Collection<int, \stdClass>
     */
    public function lots(?array $onlyIds = null, bool $lock = false): Collection
    {
        $fuelIds = null;
        if ($onlyIds !== null) {
            // Every lot of the same fuel is computed: FIFO shares run across all of that fuel's lots.
            $fuelIds = DB::table('inventory_movements')->whereIn('id', $onlyIds)->distinct()->pluck('fuel_type_id')->map(fn (mixed $id): int => (int) $id)->all();
            if ($fuelIds === []) {
                return collect();
            }
        }

        $lots = $this->compute($fuelIds, $lock);

        return $onlyIds === null ? $lots : $lots->whereIn('id', $onlyIds)->values();
    }

    /**
     * Liters of a fuel that Record Sales may still promise, across all its lots. With $lock the incoming
     * movements of that fuel are row-locked (used when an F1 portion is reserved).
     */
    public function availableForFuel(int $fuelTypeId, bool $lock = false): float
    {
        return round((float) $this->compute([$fuelTypeId], $lock)->sum('available'), 2);
    }

    /**
     * @param  array<int, int>|null  $fuelIds  null = every fuel
     * @return Collection<int, \stdClass>
     */
    private function compute(?array $fuelIds, bool $lock): Collection
    {
        $scope = function (Builder $query) use ($fuelIds): void {
            if ($fuelIds !== null) {
                $query->whereIn('inventory_movements.fuel_type_id', $fuelIds);
            }
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
            ->groupByRaw('inventory_movements.storage_location_id, inventory_movements.fuel_type_id, '.self::RELEASE_LOT)
            ->selectRaw('inventory_movements.storage_location_id, inventory_movements.fuel_type_id, '.self::RELEASE_LOT.' as lot_id, SUM(inventory_movements.quantity_liters) as liters')
            ->get();

        $open = fn (): Builder => DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereNull('sales.deleted_at')
            ->where('sales.status', '!=', 'cancelled')
            ->whereNotIn('sales.delivery_status', ['delivered', 'cancelled']);

        // The lot covers the line's own fuel only (ordered liters less the F1 portion).
        $reserved = $open()
            ->whereNotNull('sale_items.stock_in_movement_id')
            ->groupBy('sale_items.stock_in_movement_id')
            ->selectRaw('sale_items.stock_in_movement_id as lot_id, SUM(CASE WHEN sale_items.quantity_liters - sale_items.f1_quantity_liters - sale_items.fulfilled_quantity_liters > 0 THEN sale_items.quantity_liters - sale_items.f1_quantity_liters - sale_items.fulfilled_quantity_liters ELSE 0 END) as liters')
            ->pluck('liters', 'lot_id');

        $f1FuelId = F1Split::f1FuelId();
        // Net of F1 already released: a Depot to Client order's F1 leaves the garage when its lift completes,
        // before the order is delivered.
        $f1Reserved = 0.0;
        if ($f1FuelId !== null && ($fuelIds === null || in_array($f1FuelId, $fuelIds, true))) {
            $f1Released = DB::table('stock_outs')
                ->where('fuel_type_id', $f1FuelId)
                ->where('status', 'released')
                ->whereNotNull('sale_item_id')
                ->groupBy('sale_item_id')
                ->selectRaw('sale_item_id, SUM(quantity_liters) as liters');
            $f1Reserved = round((float) $open()
                ->leftJoinSub($f1Released, 'f1_released', 'f1_released.sale_item_id', '=', 'sale_items.id')
                ->where('sale_items.f1_quantity_liters', '>', 0)
                ->sum(DB::raw('CASE WHEN sale_items.f1_quantity_liters - COALESCE(f1_released.liters, 0) > 0 THEN sale_items.f1_quantity_liters - COALESCE(f1_released.liters, 0) ELSE 0 END')), 2);
        }

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

        // Undelivered F1 portions are pooled: they hold back F1 stock oldest lot first.
        if ($f1Reserved > 0) {
            foreach ($lots->where('fuel_type_id', $f1FuelId)->sortBy([['movement_date', 'asc'], ['id', 'asc']]) as $lot) {
                $take = min($lot->available, $f1Reserved);
                $lot->reserved = round($lot->reserved + $take, 2);
                $lot->available = round($lot->available - $take, 2);
                $lot->label = $lot->source_code.' · '.$lot->fuel_name.' · '.number_format($lot->available, 0).' L in stock · ₱'.number_format((float) $lot->unit_cost, 2).'/L';
                $f1Reserved = round($f1Reserved - $take, 2);
                if ($f1Reserved <= 0) {
                    break;
                }
            }
        }

        return $lots;
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
