<?php

namespace App\Services;

use App\Support\F1Split;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Part 2 Button Revisions (October 22, 2026): stock is kept per PURCHASE line (tanks are retired).
 *
 * - released: liters deliveries took from the purchase (released stock-outs that name it).
 * - on_hand ("Remaining"): ordered - released. This is the stock.
 * - reserved: liters promised to sales not delivered yet (their own fuel from purchase_item_id, their F1 portion
 *   from f1_purchase_item_id), less what was already released for them.
 * - available: on_hand - reserved. A new sale may only take available liters.
 * - remaining_in_depot: liters a new lift may take = ordered - lifts not cancelled (scheduled ones included) - liters
 *   delivered straight from the depot without a lift of this purchase (Standard deliveries, or an F1 portion that
 *   rode on another purchase's lift).
 * - not_lifted: liters physically still at the depot = ordered - lifts that were Lifted or Completed - those direct
 *   deliveries (the dashboard's Remaining Balance in Depots).
 */
class PurchaseStockService
{
    /** Sale statuses whose undelivered liters are promised (cancelled sales release their promise). */
    private const OPEN_SALE_STATUSES = ['draft', 'confirmed', 'partially_paid', 'paid', 'unpaid'];

    /**
     * @param  array<int, int>|null  $purchaseItemIds  null = every purchase line
     * @return Collection<int, \stdClass> keyed by purchase_item_id
     */
    public function lines(?array $purchaseItemIds = null, bool $lock = false, bool $includeCancelled = false): Collection
    {
        $lines = DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'purchase_items.fuel_type_id')
            ->join('depots', 'depots.id', '=', 'purchases.depot_id')
            ->whereNull('purchases.deleted_at')
            ->when(! $includeCancelled, fn (Builder $query): Builder => $query->where('purchases.status', '!=', 'cancelled'))
            ->when($purchaseItemIds !== null, fn (Builder $query): Builder => $query->whereIn('purchase_items.id', $purchaseItemIds ?: [0]))
            ->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())
            ->orderBy('purchases.purchase_date')
            ->orderBy('purchase_items.id')
            ->get([
                'purchase_items.id',
                'purchase_items.purchase_id',
                'purchase_items.fuel_type_id',
                'purchase_items.quantity_ordered_liters',
                'purchase_items.unit_cost',
                'purchases.purchase_code',
                'purchases.purchase_date',
                'purchases.depot_id',
                'purchases.status as purchase_status',
                'depots.name as depot_name',
                'depots.address as depot_address',
                'fuel_types.name as fuel_name',
                'fuel_types.code as fuel_code',
            ]);

        $ids = $lines->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $released = $this->releasedByPurchase($ids);
        $reserved = $this->reservedByPurchase($ids);
        [$lifted, $physicallyLifted] = $this->liftedByPurchase($ids);
        $directFromDepot = $this->releasedWithoutOwnLift($ids);

        return $lines->mapWithKeys(function (object $line) use ($released, $reserved, $lifted, $physicallyLifted, $directFromDepot): array {
            $id = (int) $line->id;
            $ordered = round((float) $line->quantity_ordered_liters, 2);
            $line->ordered = $ordered;
            $line->released = round((float) ($released[$id] ?? 0), 2);
            $line->reserved = round((float) ($reserved[$id] ?? 0), 2);
            $line->on_hand = round(max(0, $ordered - $line->released), 2);
            $line->available = round(max(0, $line->on_hand - $line->reserved), 2);
            $line->lifted = round((float) ($lifted[$id] ?? 0), 2);
            $line->remaining_in_depot = round(max(0, $ordered - $line->lifted - (float) ($directFromDepot[$id] ?? 0)), 2);
            $line->not_lifted = round(max(0, $ordered - (float) ($physicallyLifted[$id] ?? 0) - (float) ($directFromDepot[$id] ?? 0)), 2);
            $line->label = $line->purchase_code.' · '.mb_strtoupper((string) $line->fuel_name);

            return [$id => $line];
        });
    }

    public function line(int $purchaseItemId, bool $lock = false): ?\stdClass
    {
        return $this->lines([$purchaseItemId], $lock, true)->get($purchaseItemId);
    }

    /**
     * Purchases Record Sales can sell from: liters available, oldest first. $keep adds purchases already chosen on
     * the sale being edited even when nothing more is available.
     *
     * @param  array<int, int>  $keep
     * @return Collection<int, \stdClass>
     */
    public function saleOptions(array $keep = []): Collection
    {
        return $this->lines()
            ->filter(fn (object $line): bool => $line->available > 0 || in_array((int) $line->id, $keep, true))
            ->values();
    }

    /** @return array<int, int> fuel ids of the F1 fuel, for F1 purchase dropdowns */
    public static function f1FuelIds(): array
    {
        $id = F1Split::f1FuelId();

        return $id === null ? [] : [$id];
    }

    /**
     * Sum of a query's purchase stock by fuel: on hand per fuel id (for the dashboard and summary cards).
     *
     * @param  array<int, int>|null  $purchaseItemIds
     * @return array<int, float> fuel_type_id => liters on hand
     */
    public function onHandByFuel(?array $purchaseItemIds = null): array
    {
        return $this->lines($purchaseItemIds)
            ->groupBy('fuel_type_id')
            ->map(fn (Collection $lines): float => round((float) $lines->sum('on_hand'), 2))
            ->all();
    }

    /**
     * @param  array<int, int>  $ids
     * @return array<int, float>
     */
    private function releasedByPurchase(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('stock_outs')
            ->where('status', 'released')
            ->whereIn('purchase_item_id', $ids)
            ->groupBy('purchase_item_id')
            ->selectRaw('purchase_item_id, COALESCE(SUM(quantity_liters), 0) as liters')
            ->pluck('liters', 'purchase_item_id')
            ->map(fn (mixed $liters): float => (float) $liters)
            ->all();
    }

    /**
     * Undelivered liters of open sale lines, per purchase: their own fuel and their F1 portion.
     *
     * @param  array<int, int>  $ids
     * @return array<int, float>
     */
    private function reservedByPurchase(array $ids): array
    {
        $releasedByLine = DB::table('stock_outs')
            ->where('status', 'released')
            ->whereNotNull('sale_item_id')
            ->groupBy('sale_item_id')
            ->selectRaw("sale_item_id, COALESCE(SUM(CASE WHEN stock_outs.portion = 'f1' THEN quantity_liters ELSE 0 END), 0) as f1_released, COALESCE(SUM(CASE WHEN stock_outs.portion = 'f1' THEN 0 ELSE quantity_liters END), 0) as main_released");

        $open = fn (string $column): Builder => DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoinSub($releasedByLine, 'released', 'released.sale_item_id', '=', 'sale_items.id')
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', self::OPEN_SALE_STATUSES)
            ->whereNotIn('sales.delivery_status', ['delivered', 'cancelled'])
            ->whereIn("sale_items.$column", $ids ?: [0])
            ->groupBy("sale_items.$column");

        $main = $open('purchase_item_id')
            ->selectRaw('sale_items.purchase_item_id as purchase_item_id, COALESCE(SUM(CASE WHEN sale_items.main_quantity_liters - COALESCE(released.main_released, 0) > 0 THEN sale_items.main_quantity_liters - COALESCE(released.main_released, 0) ELSE 0 END), 0) as liters')
            ->pluck('liters', 'purchase_item_id');
        $f1 = $open('f1_purchase_item_id')
            ->selectRaw('sale_items.f1_purchase_item_id as purchase_item_id, COALESCE(SUM(CASE WHEN sale_items.f1_quantity_liters - COALESCE(released.f1_released, 0) > 0 THEN sale_items.f1_quantity_liters - COALESCE(released.f1_released, 0) ELSE 0 END), 0) as liters')
            ->pluck('liters', 'purchase_item_id');

        $reserved = [];
        foreach ([$main, $f1] as $totals) {
            foreach ($totals as $id => $liters) {
                $reserved[(int) $id] = ($reserved[(int) $id] ?? 0) + (float) $liters;
            }
        }

        return $reserved;
    }

    /**
     * Liters on lifts per purchase: [not cancelled (scheduled ones included), Lifted or Completed], in one query.
     *
     * @param  array<int, int>  $ids
     * @return array{0: array<int, float>, 1: array<int, float>}
     */
    private function liftedByPurchase(array $ids): array
    {
        $rows = DB::table('hauls')
            ->where('status', '!=', 'cancelled')
            ->whereIn('purchase_item_id', $ids ?: [0])
            ->groupBy('purchase_item_id')
            ->selectRaw("purchase_item_id, COALESCE(SUM(quantity_liters), 0) as assigned, COALESCE(SUM(CASE WHEN status IN ('lifted', 'completed') THEN quantity_liters ELSE 0 END), 0) as lifted")
            ->get();

        return [
            $rows->mapWithKeys(fn (object $row): array => [(int) $row->purchase_item_id => (float) $row->assigned])->all(),
            $rows->mapWithKeys(fn (object $row): array => [(int) $row->purchase_item_id => (float) $row->lifted])->all(),
        ];
    }

    /**
     * Liters released from a purchase without a lift of that same purchase (they left the depot directly).
     *
     * @param  array<int, int>  $ids
     * @return array<int, float>
     */
    private function releasedWithoutOwnLift(array $ids): array
    {
        return DB::table('stock_outs')
            ->leftJoin('haul_allocations', 'haul_allocations.id', '=', 'stock_outs.haul_allocation_id')
            ->leftJoin('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
            ->where('stock_outs.status', 'released')
            // Old garage releases were lifted first (into a tank), so they never left the depot directly.
            ->where('stock_outs.source_type', 'depot')
            ->whereIn('stock_outs.purchase_item_id', $ids ?: [0])
            ->where(fn (Builder $query): Builder => $query->whereNull('hauls.id')->orWhereColumn('hauls.purchase_item_id', '!=', 'stock_outs.purchase_item_id'))
            ->groupBy('stock_outs.purchase_item_id')
            ->selectRaw('stock_outs.purchase_item_id, COALESCE(SUM(stock_outs.quantity_liters), 0) as liters')
            ->pluck('liters', 'purchase_item_id')
            ->map(fn (mixed $liters): float => (float) $liters)
            ->all();
    }
}
