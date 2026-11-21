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
 * - available (Part 13): liters in the depot a new Standard or Depot to Client order may take = remaining_in_depot -
 *   depot promises (open orders that will take the purchase's fuel from the depot: not a garage lot, and for a Depot
 *   to Client order's own fuel, not already on a lift). Garage to Client orders sell from garage lots instead.
 * - on_truck (Part 13): liters on lifts that are neither delivered nor unloaded to the garage (scheduled lifts
 *   included); in_garage: liters of the purchase's garage lots. Purchased = remaining_in_depot + on_truck + in_garage
 *   + released (delivered).
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
        [$reserved, $reservedDepot] = $this->reservedByPurchase($ids);
        [$lifted, $physicallyLifted] = $this->liftedByPurchase($ids);
        $directFromDepot = $this->releasedWithoutOwnLift($ids);
        $offLifts = $this->leftLiftsByPurchase($ids);
        $inGarage = app(GarageStockService::class)->inGarageByPurchase($ids);
        $truckFree = $this->freeOnOpenLifts($ids);
        $unlifted = $this->unliftedOrders($ids);

        return $lines->mapWithKeys(function (object $line) use ($released, $reserved, $reservedDepot, $lifted, $physicallyLifted, $directFromDepot, $offLifts, $inGarage, $truckFree, $unlifted): array {
            $id = (int) $line->id;
            $ordered = round((float) $line->quantity_ordered_liters, 2);
            $line->ordered = $ordered;
            $line->released = round((float) ($released[$id] ?? 0), 2);
            $line->reserved = round((float) ($reserved[$id] ?? 0), 2);
            $line->on_hand = round(max(0, $ordered - $line->released), 2);
            $line->lifted = round((float) ($lifted[$id] ?? 0), 2);
            $line->remaining_in_depot = round(max(0, $ordered - $line->lifted - (float) ($directFromDepot[$id] ?? 0)), 2);
            $line->reserved_depot = round((float) ($reservedDepot[$id] ?? 0), 2);
            $line->available = round(max(0, $line->remaining_in_depot - $line->reserved_depot), 2);
            $line->in_garage = round((float) ($inGarage[$id] ?? 0), 2);
            $line->on_truck = round(max(0, $line->lifted - (float) ($offLifts[$id] ?? 0)), 2);
            // Do not infer missing historical allocations. Expose the inconsistency and hold future withdrawals.
            $line->conservation_difference_liters = round($line->remaining_in_depot + $line->on_truck + $line->in_garage + $line->released - $ordered, 2);
            $line->requires_reconciliation = abs($line->conservation_difference_liters) > .005;
            if ($line->requires_reconciliation) $line->available = 0.0;
            // Part 26: lifted fuel no order or garage unload has claimed yet stays sellable as Depot to Client under its
            // purchase until it is delivered or unloaded into a garage tank (the order then rides that truck through
            // Fuel Lifting > Deliver from Truck, or a later lift of the purchase). Each open order of the purchase not on
            // a lift yet is placed on one source (the depot, or one truck's unclaimed liters, best fit); a new order may
            // take at most the biggest single source left, so nothing is sold twice and every order can still be
            // delivered whole.
            $lineFree = $truckFree[$id] ?? [];
            $line->truck_free = round((float) array_sum($lineFree), 2);
            $placed = self::place($line->remaining_in_depot, $lineFree, $unlifted[$id] ?? [], false);
            $line->reserved_for_lift = round(max(0, $line->remaining_in_depot - $placed['depot']), 2);
            $line->depot_to_client_pool = round(max(0, $placed['depot']) + array_sum($placed['lines']), 2);
            $line->depot_to_client_available = $line->requires_reconciliation || $placed['depot'] < -0.005 ? 0.0
                : round(max($placed['depot'], $placed['lines'] === [] ? 0.0 : max($placed['lines'])), 2);
            $line->not_lifted = round(max(0, $ordered - (float) ($physicallyLifted[$id] ?? 0) - (float) ($directFromDepot[$id] ?? 0)), 2);
            $line->label = $line->purchase_code.' · '.mb_strtoupper((string) $line->fuel_name);
            // Record Sales dropdowns: "PUR-002 – PREMIUM – 60,000 L in stock – ₱55.00/L".
            $line->option_label = implode(' – ', [
                $line->purchase_code,
                mb_strtoupper((string) $line->fuel_name),
                number_format($line->available, fmod($line->available, 1.0) === 0.0 ? 0 : 2).' L in depot',
                '₱'.number_format((float) $line->unit_cost, 2).'/L',
            ]);
            // Part 26: the same purchase as a Depot to Client source ("PUR-002 – DIESEL – 60,000 L available (lifted fuel on trucks included) – ₱55.00/L").
            $liters = fn (float $value): string => number_format($value, fmod($value, 1.0) === 0.0 ? 0 : 2).' L';
            $line->depot_to_client_label = implode(' – ', [
                $line->purchase_code,
                mb_strtoupper((string) $line->fuel_name),
                $liters($line->depot_to_client_available).' available'.($line->truck_free > 0 ? ' (lifted fuel on trucks included)' : ' in depot'),
                '₱'.number_format((float) $line->unit_cost, 2).'/L',
            ]);

            return [$id => $line];
        });
    }

    /**
     * Liters taken from each purchase line, without the lift figures: delivered (released) and promised to open orders
     * not delivered yet (reserved).
     *
     * @param  array<int, int>  $purchaseItemIds
     * @return array{released: array<int, float>, reserved: array<int, float>}
     */
    public function usage(array $purchaseItemIds): array
    {
        return [
            'released' => $this->releasedByPurchase($purchaseItemIds),
            'reserved' => $purchaseItemIds === [] ? [] : $this->reservedByPurchase($purchaseItemIds)[0],
        ];
    }

    /**
     * Part 25: liters a lift may take from each purchase = remaining in the depot - what open orders will still take
     * from the depot once the lift is saved. A sale reserves its purchase's liters the moment it is recorded (lifted or
     * not), so a lift to the garage or a truck cannot take them; the orders the lift itself carries ($carriedSaleIds) move
     * their liters onto the lift and are left out. Editing a lift ($exceptHaulIds), its current orders count as off it
     * (an order removed from it goes back to the depot reservations) and its own liters are given back by the caller.
     *
     * @param  array<int, int>  $purchaseItemIds
     * @param  array<int, int>  $carriedSaleIds
     * @param  array<int, int>  $exceptHaulIds
     * @return array<int, array{remaining_in_depot: float, reserved_for_sales: float}> keyed by purchase_item_id
     */
    public function liftableLiters(array $purchaseItemIds, array $carriedSaleIds = [], array $exceptHaulIds = [], bool $lock = false): array
    {
        $lines = $this->lines($purchaseItemIds, $lock);
        $ids = $lines->keys()->map(fn (mixed $id): int => (int) $id)->all();
        $exceptHaulIds = array_values(array_unique(array_map('intval', $exceptHaulIds)));
        $carriedSaleIds = array_values(array_unique(array_map('intval', $carriedSaleIds)));
        // Part 26: an open order not on a lift may also be delivered whole from the unclaimed fuel already on one truck
        // of its purchase (Deliver from Truck); only the orders no truck can take keep depot liters from a new lift.
        $truckFree = $this->freeOnOpenLifts($ids, $exceptHaulIds);
        $unlifted = $this->unliftedOrders($ids, $carriedSaleIds, $exceptHaulIds);

        return $lines->mapWithKeys(function (object $line, int $id) use ($truckFree, $unlifted): array {
            $placed = self::place((float) $line->remaining_in_depot, $truckFree[$id] ?? [], $unlifted[$id] ?? [], false);

            return [$id => [
                'remaining_in_depot' => (float) $line->remaining_in_depot,
                'reserved_for_sales' => round(max(0, (float) $line->remaining_in_depot - $placed['depot']), 2),
            ]];
        })->all();
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
            ->filter(fn (object $line): bool => $line->available > 0 || $line->depot_to_client_available > 0 || in_array((int) $line->id, $keep, true))
            ->values();
    }

    /** @return array<int, int> fuel ids of the F1 fuel, for F1 purchase dropdowns */
    public static function f1FuelIds(): array
    {
        $id = F1Split::f1FuelId();

        return $id === null ? [] : [$id];
    }

    /** SQL equivalent of the existing main/F1/tank promises, used before Stock-In COUNT/LIMIT. */
    public function reservationTotalsQuery(): Builder
    {
        $released = DB::table('stock_outs')->where('status', 'released')->whereNotNull('sale_item_id')
            ->groupBy('sale_item_id')->selectRaw("sale_item_id, SUM(CASE WHEN stock_outs.portion = 'f1' THEN 0 ELSE stock_outs.quantity_liters END) as main, SUM(CASE WHEN stock_outs.portion = 'f1' THEN stock_outs.quantity_liters ELSE 0 END) as f1");
        $portion = function (string $column, string $quantity, string $part) use ($released): Builder {
            $due = "sale_items.$quantity - COALESCE(released.$part, 0)";
            return DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->leftJoinSub($released, 'released', 'released.sale_item_id', '=', 'sale_items.id')
                ->whereNull('sales.deleted_at')->whereIn('sales.status', self::OPEN_SALE_STATUSES)
                ->whereNotIn('sales.delivery_status', ['delivered', 'cancelled'])->whereNotNull('sale_items.'.$column)
                ->groupBy('sale_items.'.$column)
                ->selectRaw("sale_items.$column as purchase_item_id, SUM(CASE WHEN $due > 0 THEN $due ELSE 0 END) as liters");
        };
        $union = $portion('purchase_item_id', 'main_quantity_liters', 'main')
            ->unionAll($portion('f1_purchase_item_id', 'f1_quantity_liters', 'f1'));
        // Tank reservations retain the established proportional, per-purchase rounding. Only tank lots are read.
        $tankIds = DB::table('garage_stocks')->distinct()->pluck('purchase_item_id')->map(fn ($id) => (int) $id)->all();
        foreach (app(GarageStockService::class)->tankReservedByPurchase($tankIds) as $id => $liters) {
            $union->unionAll(DB::query()->selectRaw('? as purchase_item_id, ? as liters', [(int) $id, $liters]));
        }

        return DB::query()->fromSub($union, 'promises')->groupBy('purchase_item_id')->selectRaw('purchase_item_id, SUM(liters) as liters');
    }

    /**
     * Sum of a query's purchase stock by fuel: on hand per fuel id (for the dashboard and summary cards).
     *
     * @param  array<int, int>|null  $purchaseItemIds
     * @return array<int, float> fuel_type_id => liters on hand
     */
    public function onHandByFuel(?array $purchaseItemIds = null): array
    {
        $released = DB::query()->fromSub(self::releasesByPurchaseQuery(), 'release_rows')->groupBy('purchase_item_id')->selectRaw('purchase_item_id, SUM(liters) as liters');
        return DB::table('purchase_items')->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->leftJoinSub($released, 'released', 'released.purchase_item_id', '=', 'purchase_items.id')
            ->whereNull('purchases.deleted_at')->where('purchases.status', '!=', 'cancelled')
            ->when($purchaseItemIds !== null, fn (Builder $q) => $q->whereIn('purchase_items.id', $purchaseItemIds ?: [0]))
            ->groupBy('purchase_items.fuel_type_id')->selectRaw('purchase_items.fuel_type_id, ROUND(SUM(CASE WHEN purchase_items.quantity_ordered_liters > COALESCE(released.liters, 0) THEN purchase_items.quantity_ordered_liters - COALESCE(released.liters, 0) ELSE 0 END), 2) as liters')
            ->pluck('liters', 'fuel_type_id')->map(fn ($liters) => (float) $liters)->all();
    }

    /**
     * Part 18 add-on: released liters per purchase, one row per release (a stock-out naming its purchase) or per draw
     * of a tank release (whose stock-out names the tank, not a purchase). Columns: purchase_item_id, liters,
     * stock_out_id, stock_out_code, stock_out_at, sale_id, sale_item_id, portion, depot_id, fuel_type_id.
     */
    public static function releasesByPurchaseQuery(): Builder
    {
        $columns = ['stock_outs.id as stock_out_id', 'stock_outs.stock_out_code', 'stock_outs.stock_out_at', 'stock_outs.sale_id', 'stock_outs.sale_item_id', 'stock_outs.portion', 'stock_outs.depot_id', 'stock_outs.fuel_type_id'];
        $direct = DB::table('stock_outs')
            ->where('stock_outs.status', 'released')
            ->whereNotNull('stock_outs.purchase_item_id')
            ->select(array_merge(['stock_outs.purchase_item_id', 'stock_outs.quantity_liters as liters'], $columns));
        $drawn = DB::table('stock_out_draws')
            ->join('stock_outs', 'stock_outs.id', '=', 'stock_out_draws.stock_out_id')
            ->where('stock_outs.status', 'released')
            ->select(array_merge(['stock_out_draws.purchase_item_id', 'stock_out_draws.liters'], $columns));

        return DB::query()->fromSub($direct->unionAll($drawn), 'purchase_releases');
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

        return self::releasesByPurchaseQuery()
            ->whereIn('purchase_item_id', $ids)
            ->groupBy('purchase_item_id')
            ->selectRaw('purchase_item_id, COALESCE(SUM(liters), 0) as liters')
            ->pluck('liters', 'purchase_item_id')
            ->map(fn (mixed $liters): float => (float) $liters)
            ->all();
    }

    /**
     * Undelivered liters of open sale lines, per purchase: their own fuel and their F1 portion. Returns [all promises,
     * promises taken from the depot].
     *
     * @param  array<int, int>  $ids
     * @return array{0: array<int, float>, 1: array<int, float>}
     */
    private function reservedByPurchase(array $ids, array $exceptSaleIds = [], array $exceptHaulIds = []): array
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
            ->when($exceptSaleIds !== [], fn (Builder $query): Builder => $query->whereNotIn('sales.id', $exceptSaleIds))
            ->groupBy("sale_items.$column");

        // Depot promises leave out what is not taken from the depot: garage lots (Garage to Client) and the own fuel of
        // a Depot to Client order already on a lift (its liters are in the lift).
        $onLift = DB::table('haul_allocations')
            ->join('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
            ->whereNull('hauls.deleted_at')
            ->where('haul_allocations.destination_type', 'customer')
            ->where('haul_allocations.status', '!=', 'cancelled')
            ->whereNotNull('haul_allocations.sale_id')
            ->when($exceptHaulIds !== [], fn (Builder $query): Builder => $query->whereNotIn('haul_allocations.haul_id', $exceptHaulIds))
            ->select('haul_allocations.sale_id');
        $mainLiters = 'COALESCE(SUM(CASE WHEN sale_items.main_quantity_liters - COALESCE(released.main_released, 0) > 0 THEN sale_items.main_quantity_liters - COALESCE(released.main_released, 0) ELSE 0 END), 0)';
        $f1Liters = 'COALESCE(SUM(CASE WHEN sale_items.f1_quantity_liters - COALESCE(released.f1_released, 0) > 0 THEN sale_items.f1_quantity_liters - COALESCE(released.f1_released, 0) ELSE 0 END), 0)';

        $main = $open('purchase_item_id')->selectRaw("sale_items.purchase_item_id as purchase_item_id, {$mainLiters} as liters")->pluck('liters', 'purchase_item_id');
        $f1 = $open('f1_purchase_item_id')->selectRaw("sale_items.f1_purchase_item_id as purchase_item_id, {$f1Liters} as liters")->pluck('liters', 'purchase_item_id');
        $mainDepot = $open('purchase_item_id')->whereNull('sale_items.garage_stock_id')->whereNotIn('sales.id', $onLift)
            ->selectRaw("sale_items.purchase_item_id as purchase_item_id, {$mainLiters} as liters")->pluck('liters', 'purchase_item_id');
        $f1Depot = $open('f1_purchase_item_id')->whereNull('sale_items.f1_garage_stock_id')
            ->selectRaw("sale_items.f1_purchase_item_id as purchase_item_id, {$f1Liters} as liters")->pluck('liters', 'purchase_item_id');

        $sum = function (array $sets): array {
            $total = [];
            foreach ($sets as $totals) {
                foreach ($totals as $id => $liters) {
                    $total[(int) $id] = ($total[(int) $id] ?? 0) + (float) $liters;
                }
            }

            return $total;
        };

        // Part 18 add-on: open orders of a garage tank are promised from its purchases as a release would draw them.
        $tanks = app(GarageStockService::class)->tankReservedByPurchase($ids);

        return [$sum([$main, $f1, $tanks]), $sum([$mainDepot, $f1Depot])];
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
            ->whereNull('deleted_at')
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
     * Liters that left the lifts of each purchase: releases through a delivery row of a lift of the same purchase
     * (garage sales excluded; they leave the garage, not a lift) and the lifts' garage rows (unloads to the garage, and
     * the retired pre-October 22 garage rows).
     *
     * @param  array<int, int>  $ids
     * @return array<int, float>
     */
    private function leftLiftsByPurchase(array $ids): array
    {
        $released = DB::table('stock_outs')
            ->join('haul_allocations', 'haul_allocations.id', '=', 'stock_outs.haul_allocation_id')
            ->join('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
            ->where('stock_outs.status', 'released')
            ->whereNull('stock_outs.garage_stock_id')
            ->whereColumn('hauls.purchase_item_id', 'stock_outs.purchase_item_id')
            ->whereIn('stock_outs.purchase_item_id', $ids ?: [0])
            ->groupBy('stock_outs.purchase_item_id')
            ->selectRaw('stock_outs.purchase_item_id, COALESCE(SUM(stock_outs.quantity_liters), 0) as liters')
            ->pluck('liters', 'purchase_item_id');
        $toGarage = DB::table('haul_allocations')
            ->join('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
            ->whereNull('hauls.deleted_at')
            ->where('haul_allocations.destination_type', 'garage')
            ->where('haul_allocations.status', '!=', 'cancelled')
            ->whereIn('hauls.purchase_item_id', $ids ?: [0])
            ->groupBy('hauls.purchase_item_id')
            ->selectRaw('hauls.purchase_item_id, COALESCE(SUM(haul_allocations.quantity_liters), 0) as liters')
            ->pluck('liters', 'purchase_item_id');

        $left = [];
        foreach ([$released, $toGarage] as $totals) {
            foreach ($totals as $id => $liters) {
                $left[(int) $id] = ($left[(int) $id] ?? 0) + (float) $liters;
            }
        }

        return $left;
    }

    /**
     * Part 26: fuel on open lifts (Scheduled, In Transit or Lifted; never Completed or Cancelled) that no delivery row
     * or garage unload has claimed, per purchase and lift line.
     *
     * @param  array<int, int>  $ids
     * @param  array<int, int>  $exceptHaulIds  a lift being edited: its liters are given back by the caller
     * @return array<int, array<int, float>> purchase_item_id => [haul_id => liters]
     */
    public function freeOnOpenLifts(array $ids, array $exceptHaulIds = []): array
    {
        if ($ids === []) {
            return [];
        }
        $claimed = DB::table('haul_allocations')->where('status', '!=', 'cancelled')->groupBy('haul_id')
            ->selectRaw('haul_id, COALESCE(SUM(quantity_liters), 0) as liters');
        $free = [];
        DB::table('hauls')
            ->leftJoinSub($claimed, 'claimed', 'claimed.haul_id', '=', 'hauls.id')
            ->whereNull('hauls.deleted_at')
            ->whereIn('hauls.status', ['scheduled', 'in_transit', 'lifted'])
            ->whereIn('hauls.purchase_item_id', $ids)
            ->when($exceptHaulIds !== [], fn (Builder $query): Builder => $query->whereNotIn('hauls.id', $exceptHaulIds))
            ->orderBy('hauls.id')
            ->get(['hauls.id', 'hauls.purchase_item_id', 'hauls.quantity_liters', 'claimed.liters as claimed'])
            ->each(function (object $haul) use (&$free): void {
                $liters = round(max(0, (float) $haul->quantity_liters - (float) ($haul->claimed ?? 0)), 2);
                if ($liters > 0) {
                    $free[(int) $haul->purchase_item_id][(int) $haul->id] = $liters;
                }
            });

        return $free;
    }

    /**
     * Part 26: liters each lift line may unload into the garage = its unclaimed liters less what the open orders of
     * its purchase not on a lift yet need from it (they are placed on the depot first, then on the trucks).
     *
     * @param  array<int, int>  $purchaseItemIds
     * @return array<int, float> haul_id => liters
     */
    public function unloadableByHaul(array $purchaseItemIds, bool $lock = false): array
    {
        $lines = $this->lines($purchaseItemIds, $lock);
        $ids = $lines->keys()->map(fn (mixed $id): int => (int) $id)->all();
        $truckFree = $this->freeOnOpenLifts($ids);
        $unlifted = $this->unliftedOrders($ids);
        $unloadable = [];
        foreach ($lines as $id => $line) {
            foreach (self::place((float) $line->remaining_in_depot, $truckFree[$id] ?? [], $unlifted[$id] ?? [], true)['lines'] as $haulId => $liters) {
                $unloadable[(int) $haulId] = round(max(0, $liters), 2);
            }
        }

        return $unloadable;
    }

    /**
     * Part 26: undelivered liters each open order still takes from a purchase outside a lift (garage lots excluded),
     * per order: its own fuel while it is not on a lift, and its F1 portion (always from the depot). The totals match
     * the depot promises of reservedByPurchase(). "flexible" = the order may also be delivered from a truck already carrying the purchase (its own fuel,
     * from this purchase only); an F1 portion or an order supplied from several purchases needs a lift from the depot.
     *
     * @param  array<int, int>  $ids
     * @param  array<int, int>  $exceptSaleIds  orders a lift being saved carries
     * @param  array<int, int>  $exceptHaulIds  a lift being edited: its orders count as off it
     * @return array<int, list<array{liters: float, flexible: bool}>> purchase_item_id => orders
     */
    private function unliftedOrders(array $ids, array $exceptSaleIds = [], array $exceptHaulIds = []): array
    {
        if ($ids === []) {
            return [];
        }
        $releasedByLine = DB::table('stock_outs')->where('status', 'released')->whereNotNull('sale_item_id')->groupBy('sale_item_id')
            ->selectRaw("sale_item_id, COALESCE(SUM(CASE WHEN stock_outs.portion = 'f1' THEN quantity_liters ELSE 0 END), 0) as f1_released, COALESCE(SUM(CASE WHEN stock_outs.portion = 'f1' THEN 0 ELSE quantity_liters END), 0) as main_released");
        $onLift = DB::table('haul_allocations')->join('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')->whereNull('hauls.deleted_at')
            ->where('haul_allocations.destination_type', 'customer')->where('haul_allocations.status', '!=', 'cancelled')->whereNotNull('haul_allocations.sale_id')
            ->when($exceptHaulIds !== [], fn (Builder $query): Builder => $query->whereNotIn('haul_allocations.haul_id', $exceptHaulIds))
            ->distinct()->select('haul_allocations.sale_id');
        $purchasesPerSale = DB::table('sale_items')->whereNotNull('purchase_item_id')->groupBy('sale_id')
            ->selectRaw('sale_id, COUNT(DISTINCT purchase_item_id) as purchases');
        $rows = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoinSub($releasedByLine, 'released', 'released.sale_item_id', '=', 'sale_items.id')
            ->leftJoinSub($purchasesPerSale, 'per_sale', 'per_sale.sale_id', '=', 'sales.id')
            ->leftJoinSub($onLift, 'on_lift', 'on_lift.sale_id', '=', 'sales.id')
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', self::OPEN_SALE_STATUSES)
            ->whereNotIn('sales.delivery_status', ['delivered', 'cancelled'])
            ->when($exceptSaleIds !== [], fn (Builder $query): Builder => $query->whereNotIn('sales.id', $exceptSaleIds))
            ->where(fn (Builder $query): Builder => $query->whereIn('sale_items.purchase_item_id', $ids)->orWhereIn('sale_items.f1_purchase_item_id', $ids))
            ->get(['sales.id as sale_id', 'sales.delivery_type', 'per_sale.purchases', 'on_lift.sale_id as on_lift_sale_id', 'sale_items.purchase_item_id', 'sale_items.f1_purchase_item_id', 'sale_items.garage_stock_id', 'sale_items.f1_garage_stock_id',
                'sale_items.main_quantity_liters', 'sale_items.f1_quantity_liters', 'released.main_released', 'released.f1_released']);

        $orders = [];
        foreach ($rows as $row) {
            // The order's own fuel rides its lift once it is on one; an F1 portion is always taken from the depot.
            $portions = [
                [$row->garage_stock_id || $row->on_lift_sale_id ? null : $row->purchase_item_id, (float) $row->main_quantity_liters - (float) ($row->main_released ?? 0), in_array($row->delivery_type, ['depot_to_client', 'standard'], true) && (int) ($row->purchases ?? 0) <= 1],
                [$row->f1_garage_stock_id ? null : $row->f1_purchase_item_id, (float) $row->f1_quantity_liters - (float) ($row->f1_released ?? 0), false],
            ];
            foreach ($portions as [$purchaseItemId, $liters, $flexible]) {
                if (! $purchaseItemId || $liters <= 0 || ! in_array((int) $purchaseItemId, $ids, true)) {
                    continue;
                }
                $key = (int) $purchaseItemId.':'.(int) $row->sale_id.':'.($flexible ? 'f' : 'd');
                $orders[(int) $purchaseItemId][$key] = ['liters' => round(($orders[(int) $purchaseItemId][$key]['liters'] ?? 0) + $liters, 2), 'flexible' => $flexible];
            }
        }

        return array_map('array_values', $orders);
    }

    /**
     * Part 26: puts each open order (biggest first) on one source: the depot or the lift line it fits best. Orders
     * that must come from the depot always go there; the others go to the depot first ($preferDepot, e.g. before a
     * garage unload) or to a truck first (before a new sale or lift). An order that fits nowhere is counted against
     * the depot (it can then go negative: the purchase is over-promised).
     *
     * @param  array<int, float>  $lines  haul_id => unclaimed liters
     * @param  list<array{liters: float, flexible: bool}>  $orders
     * @return array{depot: float, lines: array<int, float>}
     */
    private static function place(float $depot, array $lines, array $orders, bool $preferDepot): array
    {
        usort($orders, fn (array $a, array $b): int => $b['liters'] <=> $a['liters']);
        $bestLine = function (float $liters) use (&$lines): ?int {
            $best = null;
            foreach ($lines as $haulId => $free) {
                if ($free + 0.005 >= $liters && ($best === null || $free < $lines[$best])) {
                    $best = $haulId;
                }
            }

            return $best;
        };
        foreach ($orders as $order) {
            $liters = (float) $order['liters'];
            $line = $order['flexible'] && ! ($preferDepot && $depot + 0.005 >= $liters) ? $bestLine($liters) : null;
            if ($line !== null) {
                $lines[$line] = round($lines[$line] - $liters, 2);
            } else {
                $depot = round($depot - $liters, 2);
            }
        }

        return ['depot' => $depot, 'lines' => $lines];
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
