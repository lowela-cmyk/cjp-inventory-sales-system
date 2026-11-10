<?php

namespace App\Services;

use App\Support\FilterDates;
use App\Support\TruckLabel;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Part 13 (October 28, 2026): garage stock. Every fuel has two garage tanks (Tank 1 and Tank 2, 24,000 L each, the
 * storage_locations garage tanks). Fuel left on a truck is unloaded into them as lots (garage_stocks): each lot keeps
 * its purchase line, fuel, cost per liter, lift, truck and tank. Garage fuel never goes back to the depot; it leaves
 * only through Garage to Client sales, whose released stock-outs name the lot.
 *
 * - Lot "in garage" = liters unloaded - liters released from it (lot-based releases, and tank releases' draws).
 * - Lot "available" = in garage - liters promised to open Garage to Client orders not delivered yet.
 * - Tank contents = the sum of its lots in garage; free space = capacity - contents (never below 0).
 *
 * Part 18 add-on (November 3, 2026): fuel in a tank is mixed, so new orders sell from a TANK, not a lot.
 * - Tank average cost = sum(lot in garage x lot cost/L) / contents (weighted average of everything in it).
 * - Tank "available" = contents - liters promised to open orders of the tank - liters older open orders still hold
 *   on one of its lots.
 * - A tank release takes its liters from the lots in proportion to what each lot can give (in garage less what older
 *   lot-based orders hold), so the tank's average does not move when fuel leaves it; only unloads change it.
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
        $tankIds = $tanks->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $contents = $this->inGarageByTank($tankIds);
        $reserved = $this->reservedByTank($tankIds);

        return $tanks
            ->sortBy(fn (object $tank): string => array_search($tank->fuel_code, self::FUEL_CODES, true).'-'.$tank->tank_number)
            ->mapWithKeys(function (object $tank) use ($contents, $reserved): array {
                $id = (int) $tank->id;
                $tank->capacity = GarageTankService::capacity($tank->capacity_liters);
                $tank->contents = round((float) ($contents[$id]['liters'] ?? 0), 2);
                $tank->value = round((float) ($contents[$id]['value'] ?? 0), 2);
                $tank->avg_cost = self::averageCost($tank->contents, $tank->value);
                $tank->free = round(max(0, $tank->capacity - $tank->contents), 2);
                $tank->percent = $tank->capacity > 0 ? round($tank->contents / $tank->capacity * 100, 1) : 0.0;
                $tank->reserved = round((float) ($reserved[$id] ?? 0), 2);
                $tank->available = round(max(0, $tank->contents - $tank->reserved), 2);
                // Record Sales / Assign Source dropdowns: "Premium Tank 1 · 12,000 L · avg ₱55.20/L".
                $tank->option_label = implode(' · ', array_filter([
                    $tank->name,
                    number_format($tank->available, fmod($tank->available, 1.0) === 0.0 ? 0 : 2).' L',
                    $tank->avg_cost === null ? null : 'avg ₱'.number_format($tank->avg_cost, 2).'/L',
                ]));

                return [$id => $tank];
            });
    }

    /** Weighted average cost per liter of what a tank holds (null when it is empty). */
    public static function averageCost(float $liters, float $value): ?float
    {
        return $liters > 0 ? round($value / $liters, 2) : null;
    }

    public function tank(int $id, bool $lock = false): ?\stdClass
    {
        $fuelTypeId = DB::table('storage_locations')->where('id', $id)->value('fuel_type_id');

        return $fuelTypeId === null ? null : $this->tanks([(int) $fuelTypeId], $lock)->get($id);
    }

    /**
     * Tanks a new order (or its F1 portion) can sell from: liters available, in fuel order. $keep adds tanks already
     * chosen on a sale being edited.
     *
     * @param  array<int, int>  $keep
     * @return Collection<int, \stdClass>
     */
    public function tankOptions(?int $fuelTypeId = null, array $keep = []): Collection
    {
        return $this->tanks($fuelTypeId === null ? null : [$fuelTypeId])
            ->filter(fn (object $tank): bool => $tank->available > 0 || in_array((int) $tank->id, $keep, true))
            ->values();
    }

    /**
     * The liters a tank release takes from each lot of the tank, in proportion to what each lot can give (its liters
     * in garage less what older lot-based orders still hold on it). Liters are split in hundredths: each lot gets its
     * share rounded down, and the hundredths left go to the lots with the largest remainders, so the draws add up to
     * exactly $liters. Lots must be locked by the caller (lots(..., lock: true)).
     *
     * @param  Collection<int, \stdClass>  $lots  the tank's lots (from lots())
     * @return array<int, array{lot: \stdClass, liters: float}>|null null when the lots cannot give $liters
     */
    public static function proportionalDraws(Collection $lots, float $liters): ?array
    {
        $cents = (int) round($liters * 100);
        $weights = $lots->mapWithKeys(fn (object $lot): array => [(int) $lot->id => (int) round(max(0, (float) $lot->available) * 100)])
            ->filter(fn (int $weight): bool => $weight > 0);
        $total = $weights->sum();
        if ($cents <= 0 || $total < $cents) {
            return null;
        }

        $shares = [];
        $remainders = [];
        foreach ($weights as $id => $weight) {
            $shares[$id] = intdiv($cents * $weight, $total);
            $remainders[$id] = ($cents * $weight) % $total;
        }
        $left = $cents - array_sum($shares);
        // Largest remainder first; ties go to the lot holding more, then the older lot.
        uksort($remainders, fn (int $a, int $b): int => [$remainders[$b], $weights[$b], $a] <=> [$remainders[$a], $weights[$a], $b]);
        foreach (array_keys($remainders) as $id) {
            if ($left <= 0) {
                break;
            }
            $shares[$id]++;
            $left--;
        }

        $draws = [];
        foreach ($lots->sortBy(fn (object $lot): string => $lot->unloaded_at.'-'.str_pad((string) $lot->id, 10, '0', STR_PAD_LEFT)) as $lot) {
            $share = $shares[(int) $lot->id] ?? 0;
            if ($share > 0) {
                $draws[] = ['lot' => $lot, 'liters' => round($share / 100, 2)];
            }
        }

        return $draws;
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
                foreach (['garage_stocks.lot_code', 'purchases.purchase_code', 'fuel_types.name', 'storage_locations.name', 'lifting_schedules.schedule_code', 'hauls.haul_code', 'trucks.truck_code', 'trucks.name'] as $column) {
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
                'purchases.purchase_code', 'lifting_schedules.schedule_code', 'hauls.haul_code', 'trucks.truck_code', 'trucks.name as truck_name',
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
                $lot->lift_label = trim(($lot->schedule_code ?: $lot->haul_code).' / '.($lot->truck_code ? TruckLabel::make($lot->truck_name, $lot->truck_code) : ''), ' /');
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
     * liters, free space and fill), one row per tank (Part 16 #5) and the lots with liters in the garage, filtered.
     *
     * @param  array{fuel_type_id?: ?int, tank_id?: ?int, date_from?: ?string, date_to?: ?string}  $filters
     * @return array{fuels: Collection<int, array{fuel_type_id: int, fuel_name: mixed, tanks: array<int, \stdClass>, total: float, capacity: float}>, tankRows: Collection<int, \stdClass>, lots: Collection<int, \stdClass>, tankOptions: array<int, string>, fuelOptions: array<int, string>}
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

        $lotFilters = [
            'fuel_type_id' => $filters['fuel_type_id'] ?? null,
            'storage_location_id' => $filters['tank_id'] ?? null,
            'date_from' => $filters['date_from'] ?? null,
            'date_to' => $filters['date_to'] ?? null,
        ];
        $search = trim((string) $search) ?: null;

        // Part 16 #5: one row per tank. Search and the Date Unloaded range keep the tanks with a matching lot (search
        // also matches the tank name); Fuel Type and Tank narrow the tanks. Each tank carries all its lots, newest first
        // (emptied ones too, so recent unloads stay visible), and its stock by purchase.
        $history = $this->lots(['with_empty' => true])->values();
        $narrowed = $search !== null || ($filters['date_from'] ?? null) || ($filters['date_to'] ?? null);
        $matchingTanks = $narrowed ? $this->lots($lotFilters + ['with_empty' => true], $search)->pluck('storage_location_id')->map(fn (mixed $id): int => (int) $id)->unique()->all() : null;
        $tankRows = $tanks
            ->when($filters['fuel_type_id'] ?? null, fn (Collection $tanks, mixed $id): Collection => $tanks->where('fuel_type_id', (int) $id))
            ->when($filters['tank_id'] ?? null, fn (Collection $tanks, mixed $id): Collection => $tanks->where('id', (int) $id))
            ->filter(fn (object $tank): bool => $matchingTanks === null || in_array((int) $tank->id, $matchingTanks, true)
                || ($search !== null && ! ($filters['date_from'] ?? null) && ! ($filters['date_to'] ?? null) && stripos((string) $tank->name, $search) !== false))
            ->map(function (object $tank) use ($history): object {
                $row = clone $tank;
                $row->lots = $history->where('storage_location_id', $tank->id)->values();
                $row->purchases = $row->lots->filter(fn (object $lot): bool => $lot->in_garage > 0)
                    ->groupBy('purchase_code')
                    ->map(fn (Collection $lots, string $code): array => [
                        'purchase_code' => $code,
                        'liters' => round((float) $lots->sum('in_garage'), 2),
                        'percent' => $tank->contents > 0 ? round((float) $lots->sum('in_garage') / $tank->contents * 100, 1) : 0.0,
                        // Part 18 add-on: the purchase's cost/L (its lots' liters weighted, the same for every lot of it).
                        'unit_cost' => self::averageCost((float) $lots->sum('in_garage'), (float) $lots->sum(fn (object $lot): float => $lot->in_garage * $lot->unit_cost)),
                    ])
                    ->sortByDesc('liters')
                    ->values();

                return $row;
            })
            ->values();

        return [
            'fuels' => $fuels,
            'tankRows' => $tankRows,
            'lots' => $this->lots($lotFilters, $search)->values(),
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
     * on the sale being edited. Part 18 add-on: new orders sell from tanks (tankOptions()); lots stay selectable only
     * on the older orders that already use one.
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
     * Part 18 add-on: liters a tank release (stock-out) drew from each lot, with the lot's purchase and cost.
     *
     * @param  array<int, int>  $stockOutIds
     * @return Collection<int, Collection<int, \stdClass>> stock_out_id => draws, oldest lot first
     */
    public function drawsByStockOut(array $stockOutIds): Collection
    {
        if ($stockOutIds === []) {
            return collect();
        }

        return DB::table('stock_out_draws')
            ->join('garage_stocks', 'garage_stocks.id', '=', 'stock_out_draws.garage_stock_id')
            ->join('purchase_items', 'purchase_items.id', '=', 'stock_out_draws.purchase_item_id')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->whereIn('stock_out_draws.stock_out_id', $stockOutIds)
            ->orderBy('garage_stocks.unloaded_at')
            ->orderBy('stock_out_draws.id')
            ->get(['stock_out_draws.stock_out_id', 'stock_out_draws.garage_stock_id', 'stock_out_draws.purchase_item_id', 'stock_out_draws.liters', 'stock_out_draws.unit_cost', 'garage_stocks.lot_code', 'purchases.purchase_code'])
            ->groupBy(fn (object $draw): int => (int) $draw->stock_out_id);
    }

    /** "PUR-001 2,000.00 L @ ₱54.00/L · PUR-003 1,000.00 L @ ₱57.60/L" (one entry per purchase). */
    public static function drawsLabel(Collection $draws): string
    {
        return $draws->groupBy('purchase_code')
            ->map(fn (Collection $rows, string $code): string => $code.' '.number_format((float) $rows->sum('liters'), 2).' L @ ₱'.number_format((float) $rows->first()->unit_cost, 2).'/L')
            ->implode(' · ');
    }

    /**
     * Part 18 add-on: liters promised to open orders of each tank, split over the purchases in the tank as a tank
     * release would take them (in proportion to what each lot can give), so each purchase's Reserved and Remaining
     * count them before they are delivered.
     *
     * @param  array<int, int>  $purchaseItemIds
     * @return array<int, float>
     */
    public function tankReservedByPurchase(array $purchaseItemIds): array
    {
        if ($purchaseItemIds === []) {
            return [];
        }
        $tankIds = DB::table('garage_stocks')->whereIn('purchase_item_id', $purchaseItemIds)->distinct()->pluck('storage_location_id')->map(fn (mixed $id): int => (int) $id)->all();
        $promised = array_filter($this->tankLineReservations($tankIds), fn (float $liters): bool => $liters > 0);
        if ($promised === []) {
            return [];
        }

        $reserved = [];
        $lots = $this->lots(['with_empty' => true])->whereIn('storage_location_id', array_keys($promised));
        foreach ($lots->groupBy('storage_location_id') as $tankId => $tankLots) {
            $total = (float) $tankLots->sum('available');
            foreach ($tankLots as $lot) {
                if ($total <= 0 || $lot->available <= 0 || ! in_array((int) $lot->purchase_item_id, $purchaseItemIds, true)) {
                    continue;
                }
                $id = (int) $lot->purchase_item_id;
                $reserved[$id] = round(($reserved[$id] ?? 0) + min($promised[(int) $tankId], $total) * $lot->available / $total, 2);
            }
        }

        return $reserved;
    }

    /**
     * @param  array<int, int>  $tankIds
     * @return array<int, array{liters: float, value: float}> tank id => liters in garage and their cost
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
            ->selectRaw('garage_stocks.storage_location_id, COALESCE(SUM(garage_stocks.liters - COALESCE(released.liters, 0)), 0) as liters, COALESCE(SUM((garage_stocks.liters - COALESCE(released.liters, 0)) * garage_stocks.unit_cost), 0) as value')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->storage_location_id => ['liters' => (float) $row->liters, 'value' => (float) $row->value]])
            ->all();
    }

    /** Liters released from each lot: lot-based releases and the draws of tank releases (released stock-outs only). */
    private function releasedQuery(): Builder
    {
        $direct = DB::table('stock_outs')
            ->where('status', 'released')
            ->whereNotNull('garage_stock_id')
            ->select(['garage_stock_id', 'quantity_liters as liters']);
        $drawn = DB::table('stock_out_draws')
            ->join('stock_outs', 'stock_outs.id', '=', 'stock_out_draws.stock_out_id')
            ->where('stock_outs.status', 'released')
            ->select(['stock_out_draws.garage_stock_id', 'stock_out_draws.liters']);

        return DB::query()
            ->fromSub($direct->unionAll($drawn), 'garage_releases')
            ->groupBy('garage_stock_id')
            ->selectRaw('garage_stock_id, COALESCE(SUM(liters), 0) as liters');
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
     * Undelivered liters of open sale lines that sell from a lot (older Garage to Client orders: their own fuel in
     * garage_stock_id, their F1 portion in f1_garage_stock_id), per lot.
     *
     * @param  array<int, int>  $lotIds
     * @return array<int, float>
     */
    private function reservedByLot(array $lotIds, ?int $exceptSaleId = null): array
    {
        return $this->openLineLiters('garage_stock_id', 'f1_garage_stock_id', $lotIds, $exceptSaleId);
    }

    /**
     * Part 18 add-on: liters promised per tank: open orders of the tank plus what older open orders hold on its lots.
     *
     * @param  array<int, int>  $tankIds
     * @return array<int, float>
     */
    private function reservedByTank(array $tankIds): array
    {
        if ($tankIds === []) {
            return [];
        }
        $reserved = $this->tankLineReservations($tankIds);
        $lotTanks = DB::table('garage_stocks')->whereIn('storage_location_id', $tankIds)->pluck('storage_location_id', 'id');
        foreach ($this->reservedByLot($lotTanks->keys()->map(fn (mixed $id): int => (int) $id)->all()) as $lotId => $liters) {
            $tankId = (int) $lotTanks[$lotId];
            $reserved[$tankId] = round(($reserved[$tankId] ?? 0) + $liters, 2);
        }

        return $reserved;
    }

    /**
     * @param  array<int, int>  $tankIds
     * @return array<int, float> tank id => undelivered liters of open lines that sell from the tank
     */
    private function tankLineReservations(array $tankIds): array
    {
        return $this->openLineLiters('garage_tank_id', 'f1_garage_tank_id', $tankIds);
    }

    /**
     * Undelivered liters of open sale lines per source: their own fuel ($mainColumn) and their F1 portion ($f1Column),
     * less what was already released for them.
     *
     * @param  array<int, int>  $ids
     * @return array<int, float>
     */
    private function openLineLiters(string $mainColumn, string $f1Column, array $ids, ?int $exceptSaleId = null): array
    {
        if ($ids === []) {
            return [];
        }

        $releasedByLine = DB::table('stock_outs')
            ->where('status', 'released')
            ->whereNotNull('sale_item_id')
            ->groupBy('sale_item_id')
            // "portion" is a reserved word in MariaDB, so it is always written with its table.
            ->selectRaw("sale_item_id, COALESCE(SUM(CASE WHEN stock_outs.portion = 'f1' THEN quantity_liters ELSE 0 END), 0) as f1_released, COALESCE(SUM(CASE WHEN stock_outs.portion = 'f1' THEN 0 ELSE quantity_liters END), 0) as main_released");

        $open = fn (string $column): Builder => DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->leftJoinSub($releasedByLine, 'released', 'released.sale_item_id', '=', 'sale_items.id')
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', self::OPEN_SALE_STATUSES)
            ->whereNotIn('sales.delivery_status', ['delivered', 'cancelled'])
            ->when($exceptSaleId, fn (Builder $query, int $id): Builder => $query->where('sales.id', '!=', $id))
            ->whereIn("sale_items.$column", $ids)
            ->groupBy("sale_items.$column");

        $reserved = [];
        $main = $open($mainColumn)
            ->selectRaw("sale_items.$mainColumn as source_id, COALESCE(SUM(CASE WHEN sale_items.main_quantity_liters - COALESCE(released.main_released, 0) > 0 THEN sale_items.main_quantity_liters - COALESCE(released.main_released, 0) ELSE 0 END), 0) as liters")
            ->pluck('liters', 'source_id');
        $f1 = $open($f1Column)
            ->selectRaw("sale_items.$f1Column as source_id, COALESCE(SUM(CASE WHEN sale_items.f1_quantity_liters - COALESCE(released.f1_released, 0) > 0 THEN sale_items.f1_quantity_liters - COALESCE(released.f1_released, 0) ELSE 0 END), 0) as liters")
            ->pluck('liters', 'source_id');
        foreach ([$main, $f1] as $totals) {
            foreach ($totals as $sourceId => $liters) {
                $reserved[(int) $sourceId] = round(($reserved[(int) $sourceId] ?? 0) + (float) $liters, 2);
            }
        }

        return $reserved;
    }
}
