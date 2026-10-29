<?php

namespace App\Services;

use App\Support\FilterDates;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Part 13 (October 28, 2026): garage stock. Every fuel has two garage tanks (Tank 1 and Tank 2, 24,000 L each, the
 * storage_locations garage tanks). Fuel left on a truck is unloaded into them as lots (garage_stocks): each lot keeps
 * its purchase line, fuel, cost per liter, lift, truck and tank. Garage fuel never goes back to the depot; it leaves
 * only through Garage to Client sales, whose released stock-outs name the lot.
 *
 * - Lot "in garage" = liters unloaded - liters released from it.
 * - Lot "available" = in garage - liters promised to open Garage to Client orders not delivered yet.
 * - Tank contents = the sum of its lots in garage; free space = capacity - contents (never below 0).
 */
class GarageStockService
{
    /** Fuels the garage keeps (two tanks each). */
    public const FUEL_CODES = ['DSL', 'PREM', 'UNL', 'F1'];

    private const OPEN_SALE_STATUSES = ['draft', 'confirmed', 'partially_paid', 'paid', 'unpaid'];

    /**
     * The garage tanks with their contents, grouped in fuel order (each fuel's Tank 1 then Tank 2).
     *
     * @param  array<int, int>|null  $fuelTypeIds
     * @return Collection<int, \stdClass> keyed by tank id
     */
    public function tanks(?array $fuelTypeIds = null, bool $lock = false): Collection
    {
        $tanks = DB::table('storage_locations')
            ->join('fuel_types', 'fuel_types.id', '=', 'storage_locations.fuel_type_id')
            ->where('storage_locations.type', 'garage')
            ->where('storage_locations.status', 'active')
            ->whereIn('fuel_types.code', self::FUEL_CODES)
            ->when($fuelTypeIds !== null, fn (Builder $query): Builder => $query->whereIn('storage_locations.fuel_type_id', $fuelTypeIds ?: [0]))
            ->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())
            ->orderBy('storage_locations.tank_number')
            ->get(['storage_locations.id', 'storage_locations.name', 'storage_locations.tank_number', 'storage_locations.capacity_liters', 'storage_locations.fuel_type_id', 'fuel_types.name as fuel_name', 'fuel_types.code as fuel_code']);
        $contents = $this->inGarageByTank($tanks->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());

        return $tanks
            ->sortBy(fn (object $tank): string => array_search($tank->fuel_code, self::FUEL_CODES, true).'-'.$tank->tank_number)
            ->mapWithKeys(function (object $tank) use ($contents): array {
                $tank->capacity = GarageTankService::capacity($tank->capacity_liters);
                $tank->contents = round((float) ($contents[(int) $tank->id] ?? 0), 2);
                $tank->free = round(max(0, $tank->capacity - $tank->contents), 2);
                $tank->percent = $tank->capacity > 0 ? round($tank->contents / $tank->capacity * 100, 1) : 0.0;

                return [(int) $tank->id => $tank];
            });
    }

    /**
     * Garage lots with their liters in garage and available, newest first.
     *
     * @param  array{fuel_type_id?: ?int, storage_location_id?: ?int, date_from?: ?string, date_to?: ?string, with_empty?: bool}  $filters
     * @param  array<int, int>|null  $ids
     * @return Collection<int, \stdClass> keyed by lot id
     */
    public function lots(array $filters = [], ?string $search = null, ?array $ids = null, bool $lock = false, ?int $exceptSaleId = null): Collection
    {
        $lots = DB::table('garage_stocks')
            ->join('storage_locations', 'storage_locations.id', '=', 'garage_stocks.storage_location_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'garage_stocks.fuel_type_id')
            ->join('purchase_items', 'purchase_items.id', '=', 'garage_stocks.purchase_item_id')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->join('hauls', 'hauls.id', '=', 'garage_stocks.haul_id')
            ->leftJoin('lifting_schedules', 'lifting_schedules.id', '=', 'hauls.lifting_schedule_id')
            ->leftJoin('trucks', 'trucks.id', '=', 'garage_stocks.truck_id')
            ->when($ids !== null, fn (Builder $query): Builder => $query->whereIn('garage_stocks.id', $ids ?: [0]))
            ->when($filters['fuel_type_id'] ?? null, fn (Builder $query, mixed $id): Builder => $query->where('garage_stocks.fuel_type_id', (int) $id))
            ->when($filters['storage_location_id'] ?? null, fn (Builder $query, mixed $id): Builder => $query->where('garage_stocks.storage_location_id', (int) $id))
            ->tap(fn (Builder $query): Builder => FilterDates::apply($query, 'garage_stocks.unloaded_at', ['from' => $filters['date_from'] ?? null, 'to' => $filters['date_to'] ?? null]))
            ->when($search, fn (Builder $query, string $term): Builder => $query->where(function (Builder $query) use ($term): void {
                foreach (['garage_stocks.lot_code', 'purchases.purchase_code', 'fuel_types.name', 'storage_locations.name', 'lifting_schedules.schedule_code', 'hauls.haul_code', 'trucks.truck_code', 'trucks.plate_number'] as $column) {
                    $query->orWhere($column, 'like', '%'.$term.'%');
                }
            }))
            ->when($lock, fn (Builder $query): Builder => $query->lockForUpdate())
            ->orderByDesc('garage_stocks.unloaded_at')
            ->orderByDesc('garage_stocks.id')
            ->get([
                'garage_stocks.id', 'garage_stocks.lot_code', 'garage_stocks.storage_location_id', 'garage_stocks.purchase_item_id',
                'garage_stocks.fuel_type_id', 'garage_stocks.haul_id', 'garage_stocks.haul_allocation_id', 'garage_stocks.truck_id',
                'garage_stocks.liters', 'garage_stocks.unit_cost', 'garage_stocks.unloaded_at',
                'storage_locations.name as tank_name', 'storage_locations.tank_number', 'fuel_types.name as fuel_name', 'fuel_types.code as fuel_code',
                'purchases.purchase_code', 'lifting_schedules.schedule_code', 'hauls.haul_code', 'trucks.truck_code', 'trucks.plate_number',
            ]);

        $lotIds = $lots->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $released = $this->releasedByLot($lotIds);
        $reserved = $this->reservedByLot($lotIds, $exceptSaleId);

        return $lots
            ->mapWithKeys(function (object $lot) use ($released, $reserved): array {
                $id = (int) $lot->id;
                $lot->liters = round((float) $lot->liters, 2);
                $lot->unit_cost = round((float) $lot->unit_cost, 2);
                $lot->released = round((float) ($released[$id] ?? 0), 2);
                $lot->in_garage = round(max(0, $lot->liters - $lot->released), 2);
                $lot->reserved = round((float) ($reserved[$id] ?? 0), 2);
                $lot->available = round(max(0, $lot->in_garage - $lot->reserved), 2);
                $lot->lift_label = trim(($lot->schedule_code ?: $lot->haul_code).' / '.$lot->truck_code.($lot->plate_number ? ' · '.$lot->plate_number : ''), ' /');
                // Record Sales dropdown: "PUR-002 · DIESEL · Diesel Tank 2 · 6,000 L · ₱55.00/L".
                $lot->option_label = implode(' · ', [
                    $lot->purchase_code,
                    mb_strtoupper((string) $lot->fuel_name),
                    $lot->tank_name,
                    number_format($lot->available, fmod($lot->available, 1.0) === 0.0 ? 0 : 2).' L',
                    '₱'.number_format($lot->unit_cost, 2).'/L',
                ]);

                return [$id => $lot];
            })
            ->when(! ($filters['with_empty'] ?? false), fn (Collection $lots): Collection => $lots->filter(fn (object $lot): bool => $lot->in_garage > 0));
    }

    /**
     * Garage tab (Inventory and Fuel Lifting): per-fuel tank cards (Tank 1 and Tank 2 side by side, each with its
     * liters, free space and fill) and the lots with liters in the garage, filtered.
     *
     * @param  array{fuel_type_id?: ?int, tank_id?: ?int, date_from?: ?string, date_to?: ?string}  $filters
     * @return array{fuels: Collection<int, array<string, mixed>>, lots: Collection<int, \stdClass>, tankOptions: array<int, string>, fuelOptions: array<int, string>}
     */
    public function tab(array $filters = [], ?string $search = null): array
    {
        $tanks = $this->tanks();
        $fuels = $tanks->groupBy('fuel_type_id')->map(fn (Collection $fuelTanks): array => [
            'fuel_type_id' => (int) $fuelTanks->first()->fuel_type_id,
            'fuel_name' => $fuelTanks->first()->fuel_name,
            'tanks' => $fuelTanks->values()->all(),
            'total' => round((float) $fuelTanks->sum('contents'), 2),
            'capacity' => round((float) $fuelTanks->sum('capacity'), 2),
        ])->values();

        return [
            'fuels' => $fuels,
            'lots' => $this->lots([
                'fuel_type_id' => $filters['fuel_type_id'] ?? null,
                'storage_location_id' => $filters['tank_id'] ?? null,
                'date_from' => $filters['date_from'] ?? null,
                'date_to' => $filters['date_to'] ?? null,
            ], $search)->values(),
            'tankOptions' => $tanks->mapWithKeys(fn (object $tank): array => [(int) $tank->id => $tank->name])->all(),
            'fuelOptions' => $fuels->mapWithKeys(fn (array $fuel): array => [$fuel['fuel_type_id'] => $fuel['fuel_name']])->all(),
        ];
    }

    public function lot(int $id, bool $lock = false, ?int $exceptSaleId = null): ?\stdClass
    {
        return $this->lots(['with_empty' => true], null, [$id], $lock, $exceptSaleId)->get($id);
    }

    /**
     * Lots a Garage to Client order can sell from (liters available), oldest first. $keep adds lots already chosen
     * on the sale being edited.
     *
     * @param  array<int, int>  $keep
     * @return Collection<int, \stdClass>
     */
    public function saleOptions(array $keep = [], ?int $fuelTypeId = null): Collection
    {
        return $this->lots(['with_empty' => true, 'fuel_type_id' => $fuelTypeId])
            ->filter(fn (object $lot): bool => $lot->available > 0 || in_array((int) $lot->id, $keep, true))
            ->sortBy('unloaded_at')
            ->values();
    }

    /**
     * Liters in garage per purchase line (for Purchased = Depot + On Truck + In Garage + Delivered).
     *
     * @param  array<int, int>  $purchaseItemIds
     * @return array<int, float>
     */
    public function inGarageByPurchase(array $purchaseItemIds): array
    {
        if ($purchaseItemIds === []) {
            return [];
        }

        return DB::table('garage_stocks')
            ->leftJoinSub($this->releasedQuery(), 'released', 'released.garage_stock_id', '=', 'garage_stocks.id')
            ->whereIn('garage_stocks.purchase_item_id', $purchaseItemIds)
            ->groupBy('garage_stocks.purchase_item_id')
            ->selectRaw('garage_stocks.purchase_item_id, COALESCE(SUM(garage_stocks.liters - COALESCE(released.liters, 0)), 0) as liters')
            ->pluck('liters', 'purchase_item_id')
            ->map(fn (mixed $liters): float => round((float) $liters, 2))
            ->all();
    }

    /**
     * @param  array<int, int>  $tankIds
     * @return array<int, float> tank id => liters in garage
     */
    private function inGarageByTank(array $tankIds): array
    {
        if ($tankIds === []) {
            return [];
        }

        return DB::table('garage_stocks')
            ->leftJoinSub($this->releasedQuery(), 'released', 'released.garage_stock_id', '=', 'garage_stocks.id')
            ->whereIn('garage_stocks.storage_location_id', $tankIds)
            ->groupBy('garage_stocks.storage_location_id')
            ->selectRaw('garage_stocks.storage_location_id, COALESCE(SUM(garage_stocks.liters - COALESCE(released.liters, 0)), 0) as liters')
            ->pluck('liters', 'storage_location_id')
            ->map(fn (mixed $liters): float => (float) $liters)
            ->all();
    }

    private function releasedQuery(): Builder
    {
        return DB::table('stock_outs')
            ->where('status', 'released')
            ->whereNotNull('garage_stock_id')
            ->groupBy('garage_stock_id')
            ->selectRaw('garage_stock_id, COALESCE(SUM(quantity_liters), 0) as liters');
    }

    /**
     * @param  array<int, int>  $lotIds
     * @return array<int, float>
     */
    private function releasedByLot(array $lotIds): array
    {
        if ($lotIds === []) {
            return [];
        }

        return $this->releasedQuery()->whereIn('garage_stock_id', $lotIds)->pluck('liters', 'garage_stock_id')
            ->map(fn (mixed $liters): float => (float) $liters)->all();
    }

    /**
     * Undelivered liters of open Garage to Client sale lines, per lot: their own fuel (garage_stock_id) and their F1
     * portion (f1_garage_stock_id), less what was already released for them.
     *
     * @param  array<int, int>  $lotIds
     * @return array<int, float>
     */
    private function reservedByLot(array $lotIds, ?int $exceptSaleId = null): array
    {
        if ($lotIds === []) {
            return [];
        }

        $releasedByLine = DB::table('stock_outs')
            ->where('status', 'released')
            ->whereNotNull('sale_item_id')
            ->groupBy('sale_item_id')
            ->selectRaw("sale_item_id, COALESCE(SUM(CASE WHEN portion = 'f1' THEN quantity_liters ELSE 0 END), 0) as f1_released, COALESCE(SUM(CASE WHEN portion = 'f1' THEN 0 ELSE quantity_liters END), 0) as main_released");

        $open = fn (string $column): Builder => DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoinSub($releasedByLine, 'released', 'released.sale_item_id', '=', 'sale_items.id')
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', self::OPEN_SALE_STATUSES)
            ->whereNotIn('sales.delivery_status', ['delivered', 'cancelled'])
            ->when($exceptSaleId, fn (Builder $query, int $id): Builder => $query->where('sales.id', '!=', $id))
            ->whereIn("sale_items.$column", $lotIds)
            ->groupBy("sale_items.$column");

        $reserved = [];
        $main = $open('garage_stock_id')
            ->selectRaw('sale_items.garage_stock_id as lot_id, COALESCE(SUM(CASE WHEN sale_items.main_quantity_liters - COALESCE(released.main_released, 0) > 0 THEN sale_items.main_quantity_liters - COALESCE(released.main_released, 0) ELSE 0 END), 0) as liters')
            ->pluck('liters', 'lot_id');
        $f1 = $open('f1_garage_stock_id')
            ->selectRaw('sale_items.f1_garage_stock_id as lot_id, COALESCE(SUM(CASE WHEN sale_items.f1_quantity_liters - COALESCE(released.f1_released, 0) > 0 THEN sale_items.f1_quantity_liters - COALESCE(released.f1_released, 0) ELSE 0 END), 0) as liters')
            ->pluck('liters', 'lot_id');
        foreach ([$main, $f1] as $totals) {
            foreach ($totals as $lotId => $liters) {
                $reserved[(int) $lotId] = round(($reserved[(int) $lotId] ?? 0) + (float) $liters, 2);
            }
        }

        return $reserved;
    }
}
