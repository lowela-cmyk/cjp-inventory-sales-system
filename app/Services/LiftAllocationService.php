<?php

namespace App\Services;

use App\Support\F1Split;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Allocation of each lift, entered when the lift is scheduled (audit C01). One haul_allocations row per lift row:
 * - Depot to Garage: liters going into a chosen garage tank. Completing the lift posts the stock-in.
 * - Depot to Client (restored in Part 9): liters released straight from the depot to a Depot to Client sale.
 *   Completing the lift marks it delivered and releases it against the sale (see settle()).
 * The lift rows of a purchase must add up exactly to its Amount to Lift.
 * A Depot to Client order with an F1 portion (Part 9) loads that F1 at the garage onto the same truck: the first
 * lift row for the order carries the F1 liters and the garage F1 tank, and completing the lift deducts them.
 * A lift scheduled without any rows or tank (older clients) gets its full quantity on the fuel's default tank
 * with receive_on_completion = false, so it waits under Pending Garage Receipts for the Inventory Officer to
 * confirm the tank ("Assign Tank").
 */
class LiftAllocationService
{
    /** Sale statuses that can still take a direct depot delivery. */
    private const OPEN_SALE_STATUSES = ['confirmed', 'partially_paid', 'paid', 'unpaid'];

    public function __construct(
        private readonly StockInService $stockIn,
        private readonly SaleConfirmationService $saleConfirmation,
        private readonly GarageTankService $garageTanks,
        private readonly StockOutReleaseService $stockOutRelease
    ) {}

    /**
     * Active garage tanks with their stock and free capacity (free is null when the capacity is not set).
     *
     * @return Collection<int, \stdClass>
     */
    public function tankOptions(): Collection
    {
        $balances = DB::table('inventory_movements')
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('stock_outs')
                ->whereColumn('stock_outs.id', 'inventory_movements.reference_id')
                ->where('inventory_movements.reference_type', 'stock_out')
                ->where('stock_outs.status', 'cancelled'))
            ->selectRaw("storage_location_id, COALESCE(SUM(CASE WHEN direction = 'in' THEN quantity_liters ELSE -quantity_liters END), 0) as balance")
            ->groupBy('storage_location_id')
            ->pluck('balance', 'storage_location_id');

        $incoming = $this->incomingByTank();

        return DB::table('storage_locations')
            ->where('type', 'garage')
            ->where('status', 'active')
            ->whereNotNull('fuel_type_id')
            ->orderBy('fuel_type_id')
            ->orderBy('tank_number')
            ->get(['id', 'name', 'fuel_type_id', 'tank_number', 'capacity_liters'])
            ->map(function (object $tank) use ($balances, $incoming): object {
                $tank->balance_liters = round((float) ($balances[$tank->id] ?? 0), 2);
                $tank->incoming_liters = round((float) ($incoming[$tank->id] ?? 0), 2);
                $tank->free_liters = $tank->capacity_liters === null
                    ? null
                    : round(max(0, (float) $tank->capacity_liters - $tank->balance_liters - $tank->incoming_liters), 2);
                $tank->label = $tank->name.' | stock '.number_format($tank->balance_liters, 2).' L | '
                    .($tank->free_liters === null ? 'capacity not set' : 'free '.number_format($tank->free_liters, 2).' L');

                return $tank;
            });
    }

    /**
     * Open Depot to Client sale lines a lift row can be released to, with the liters not yet lifted for them.
     * Label: "Customer / Sales Order No." as on the System Analysis mockup.
     *
     * @return Collection<int, \stdClass>
     */
    public function directSaleOptions(): Collection
    {
        return DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'sale_items.fuel_type_id')
            ->where('sales.delivery_type', 'depot_to_client')
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', self::OPEN_SALE_STATUSES)
            ->whereNotIn('sales.delivery_status', ['delivered', 'cancelled'])
            ->orderByDesc('sales.sale_date')
            ->orderByDesc('sales.id')
            ->get(['sales.id as sale_id', 'sales.sale_code', 'sales.sales_order_number', 'sale_items.fuel_type_id', 'fuel_types.name as fuel_name', 'customers.name as customer_name', 'customers.company_name'])
            ->unique(fn (object $row): string => $row->sale_id.'|'.$row->fuel_type_id)
            ->map(function (object $row): object {
                $row->open_liters = $this->openDirectLiters((int) $row->sale_id, (int) $row->fuel_type_id);
                $row->f1_liters = $this->unassignedF1Liters((int) $row->sale_id);
                $row->label = ($row->company_name ?: $row->customer_name).' / '.($row->sales_order_number ?: $row->sale_code)
                    .' | '.$row->fuel_name.' | '.number_format($row->open_liters, 2).' L open'
                    .($row->f1_liters > 0 ? ' + F1 '.number_format($row->f1_liters, 2).' L at garage' : '');

                return $row;
            })
            ->filter(fn (object $row): bool => $row->open_liters > 0)
            ->values();
    }

    /**
     * Normalizes each Schedule Lift line into its lift rows and validates them. A line sends either:
     * - lifts[]: rows of type "garage" (storage_location_id) or "client" (sale_id), each with quantity_liters,
     *   adding up exactly to the line's Amount to Lift (Part 9);
     * - storage_location_id only: the whole Amount to Lift goes to that tank (Part 8 clients);
     * - neither: the whole Amount to Lift waits on the fuel's default tank for the Inventory Officer.
     * Field errors are keyed per row (items.N.lifts.M.storage_location_id, items.N.lifts.M.quantity_liters, ...).
     *
     * @param  array<int, array<string, mixed>>  $lines  each with purchase_item_id, quantity_liters and lifts[] or storage_location_id
     * @param  array<int, int>  $fuelTypeByItem  purchase_item_id => fuel_type_id
     * @return array<int, array<int, array{destination_type: string, storage_location_id: ?int, sale_id: ?int, customer_id: ?int, quantity_liters: float, receive_on_completion: bool, f1_storage_location_id?: ?int, f1_quantity_liters?: float}>>
     *
     * @throws ValidationException
     */
    public function resolveLines(array $lines, array $fuelTypeByItem): array
    {
        $errors = [];
        $resolved = [];
        $tanks = $this->tankOptions()->keyBy('id');
        $requestedByTank = [];
        $requestedBySale = [];
        $f1AssignedBySale = [];
        $f1RequestedByTank = [];

        foreach ($lines as $index => $line) {
            $quantity = round((float) $line['quantity_liters'], 2);
            $fuelTypeId = (int) ($fuelTypeByItem[(int) $line['purchase_item_id']] ?? 0);
            $rows = $line['lifts'] ?? null;

            if (! is_array($rows) || $rows === []) {
                $tankId = ($line['storage_location_id'] ?? null) ? (int) $line['storage_location_id'] : null;
                // Older clients send no tank: the whole lift goes to the fuel's default tank for confirmation.
                $rows = [['type' => 'garage', 'storage_location_id' => $tankId, 'quantity_liters' => $quantity, 'default_tank' => $tankId === null]];
                $prefix = "items.$index";
            } else {
                $prefix = null;
                $sum = round((float) collect($rows)->sum(fn (array $row): float => round((float) ($row['quantity_liters'] ?? 0), 2)), 2);
                if ($sum !== $quantity) {
                    $errors["items.$index.lifts"] = 'The lift rows add up to '.number_format($sum, 2).' L but the Amount to Lift is '.number_format($quantity, 2).' L. They must be equal.';
                }
            }

            $resolved[$index] = [];
            foreach (array_values($rows) as $rowIndex => $row) {
                $key = $prefix ?? "items.$index.lifts.$rowIndex";
                $liters = round((float) ($row['quantity_liters'] ?? 0), 2);

                if (($row['type'] ?? 'garage') === 'client') {
                    $saleId = ($row['sale_id'] ?? null) ? (int) $row['sale_id'] : null;
                    $sale = $saleId ? $this->openDirectSale($saleId) : null;
                    if (! $sale) {
                        $errors["$key.sale_id"] = 'Select an open Depot to Client sales order.';
                    } else {
                        $open = $this->openDirectLiters($saleId, $fuelTypeId);
                        $requestedBySale[$saleId] = round(($requestedBySale[$saleId] ?? 0) + $liters, 2);
                        if ($open <= 0) {
                            $errors["$key.sale_id"] = 'This sales order has no open liters of the purchase\'s fuel type.';
                        } elseif ($requestedBySale[$saleId] > $open) {
                            $errors["$key.quantity_liters"] = 'This sales order has only '.number_format($open, 2).' L left to lift.';
                        }
                    }

                    // The order's F1 portion rides on its first lift row: loaded at the garage onto the same truck.
                    $f1Liters = 0.0;
                    $f1TankId = null;
                    if ($sale && ! isset($f1AssignedBySale[$saleId])) {
                        $f1Liters = $this->unassignedF1Liters($saleId);
                        $f1AssignedBySale[$saleId] = true;
                    }
                    if ($f1Liters > 0) {
                        $f1TankId = ($row['f1_storage_location_id'] ?? null) ? (int) $row['f1_storage_location_id'] : null;
                        $f1Tank = $f1TankId ? $tanks->get($f1TankId) : null;
                        if (! $f1Tank || (int) $f1Tank->fuel_type_id !== F1Split::f1FuelId()) {
                            $errors["$key.f1_storage_location_id"] = 'Select the garage F1 tank to load '.number_format($f1Liters, 2).' L of F1 from.';
                        } else {
                            $f1RequestedByTank[$f1TankId] = round(($f1RequestedByTank[$f1TankId] ?? 0) + $f1Liters, 2);
                            $f1Available = round($f1Tank->balance_liters - $this->plannedF1ByTank($f1TankId), 2);
                            if ($f1RequestedByTank[$f1TankId] > $f1Available) {
                                $errors["$key.f1_storage_location_id"] = $f1Tank->name.' has only '.number_format(max(0, $f1Available), 2).' L of F1 for the '.number_format($f1Liters, 2).' L to load.';
                            }
                        }
                    }

                    $resolved[$index][] = ['destination_type' => 'customer', 'storage_location_id' => null, 'sale_id' => $saleId, 'customer_id' => $sale ? (int) $sale->customer_id : null, 'quantity_liters' => $liters, 'receive_on_completion' => true, 'f1_storage_location_id' => $f1TankId, 'f1_quantity_liters' => $f1Liters];

                    continue;
                }

                if ($row['default_tank'] ?? false) {
                    $resolved[$index][] = ['destination_type' => 'garage', 'storage_location_id' => $this->defaultTankId($fuelTypeId), 'sale_id' => null, 'customer_id' => null, 'quantity_liters' => $liters, 'receive_on_completion' => false];

                    continue;
                }

                $tankId = ($row['storage_location_id'] ?? null) ? (int) $row['storage_location_id'] : null;
                $tank = $tankId ? $tanks->get($tankId) : null;
                if (! $tank) {
                    $errors["$key.storage_location_id"] = 'Select the garage tank that receives this lift.';
                } elseif ((int) $tank->fuel_type_id !== $fuelTypeId) {
                    $errors["$key.storage_location_id"] = 'The tank must hold the same fuel type as the purchase.';
                } else {
                    $requestedByTank[$tankId] = round(($requestedByTank[$tankId] ?? 0) + $liters, 2);
                    if ($tank->free_liters !== null && $requestedByTank[$tankId] > $tank->free_liters) {
                        $errors["$key.quantity_liters"] = $tank->name.' has only '.number_format($tank->free_liters, 2).' L of free capacity.';
                    }
                }

                $resolved[$index][] = ['destination_type' => 'garage', 'storage_location_id' => $tankId, 'sale_id' => null, 'customer_id' => null, 'quantity_liters' => $liters, 'receive_on_completion' => true];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $resolved;
    }

    /**
     * @param  array<int, array{destination_type: string, storage_location_id: ?int, sale_id: ?int, customer_id: ?int, quantity_liters: float, receive_on_completion: bool, f1_storage_location_id?: ?int, f1_quantity_liters?: float}>  $rows
     */
    public function createForHaul(int $haulId, int $fuelTypeId, array $rows): void
    {
        $base = ['haul_id' => $haulId, 'fuel_type_id' => $fuelTypeId, 'allocated_at' => now(), 'status' => 'planned', 'created_at' => now(), 'updated_at' => now()];

        foreach ($rows as $row) {
            if ($row['quantity_liters'] <= 0 || ($row['destination_type'] === 'garage' ? ! $row['storage_location_id'] : ! $row['customer_id'])) {
                continue;
            }

            DB::table('haul_allocations')->insert($base + [
                'destination_type' => $row['destination_type'],
                'storage_location_id' => $row['storage_location_id'],
                'customer_id' => $row['customer_id'],
                'sale_id' => $row['sale_id'],
                'quantity_liters' => $row['quantity_liters'],
                'receive_on_completion' => $row['receive_on_completion'],
                'f1_storage_location_id' => $row['f1_storage_location_id'] ?? null,
                'f1_quantity_liters' => $row['f1_quantity_liters'] ?? 0,
            ]);
        }
    }

    /**
     * Runs when a lift is completed by the driver (lifted) or confirmed (completed). Safe to run twice.
     * Posts the garage portion as stock-in to the chosen tank and releases the direct portion to its sale.
     * Anything that cannot be posted stays visible (Pending Garage Receipts / undelivered sale) and is reported.
     *
     * @return array<int, string> notes for the user
     */
    public function settle(int $haulId, int $actorId): array
    {
        $notes = [];
        $allocations = DB::table('haul_allocations')->where('haul_id', $haulId)->where('status', '!=', 'cancelled')->lockForUpdate()->get();

        foreach ($allocations->where('destination_type', 'garage') as $allocation) {
            if (! $allocation->receive_on_completion || $allocation->status === 'received') {
                continue;
            }

            $remaining = $this->stockIn->remainingReceivableForAllocation($allocation);
            if ($remaining <= 0) {
                continue;
            }

            $error = $this->stockIn->recordStockIn([
                'haul_allocation_id' => $allocation->id,
                'storage_location_id' => $allocation->storage_location_id,
                'quantity_liters' => $remaining,
                'movement_date' => now()->toDateTimeString(),
                'remarks' => 'Automatic stock-in on lift completion',
            ], $actorId);

            if ($error) {
                $notes[] = 'Garage stock-in is waiting under Pending Garage Receipts: '.$error;
            }
        }

        $direct = $allocations->where('destination_type', 'customer');

        // F1 loaded at the garage for a Depot to Client order leaves its F1 tank when the lift is completed.
        foreach ($direct->where('f1_quantity_liters', '>', 0) as $allocation) {
            $error = DB::transaction(fn (): ?string => $this->stockOutRelease->releaseF1ForAllocation($allocation, $actorId));
            if ($error) {
                $notes[] = $error;
            }
        }

        DB::table('haul_allocations')->whereIn('id', $direct->where('status', 'planned')->pluck('id'))->update(['status' => 'delivered', 'updated_at' => now()]);

        foreach ($direct->pluck('sale_id')->filter()->unique() as $saleId) {
            $sale = DB::table('sales')->where('id', $saleId)->first(['delivery_status', 'sale_code']);
            if (! $sale || in_array($sale->delivery_status, ['delivered', 'cancelled'], true)) {
                continue;
            }

            try {
                // Savepoint: a sale that cannot be fully released yet stays undelivered without undoing the lift.
                DB::transaction(fn () => $this->saleConfirmation->updateDeliveryStatus((int) $saleId, 'delivered', $actorId));
            } catch (\RuntimeException $exception) {
                $notes[] = 'Direct release for '.$sale->sale_code.' is recorded on the lift; the sale stays undelivered: '.$exception->getMessage();
            }
        }

        return $notes;
    }

    /** Planned allocations of a cancelled lift no longer reserve tank space or sale liters. */
    public function cancelForHauls(array $haulIds): void
    {
        DB::table('haul_allocations')->whereIn('haul_id', $haulIds)->where('status', 'planned')->update(['status' => 'cancelled', 'updated_at' => now()]);
    }

    /**
     * Audit C01 backfill: lifts (not cancelled) that have no allocation get one garage allocation for their
     * full quantity on the fuel's default tank, unconfirmed, so they show under Pending Garage Receipts.
     * Read-only: returns what would be created.
     *
     * @return Collection<int, array{haul_id: int, haul_code: string, haul_status: string, purchase_code: string, fuel_type_id: int, fuel: string, quantity_liters: float, storage_location_id: ?int, tank: string}>
     */
    public function backfillPlan(): Collection
    {
        $tanks = DB::table('storage_locations')->where('type', 'garage')->where('status', 'active')->whereNotNull('fuel_type_id')
            ->orderBy('tank_number')->orderBy('id')->get(['id', 'name', 'fuel_type_id'])->unique('fuel_type_id')->keyBy('fuel_type_id');

        return DB::table('hauls')
            ->join('purchases', 'purchases.id', '=', 'hauls.purchase_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'hauls.fuel_type_id')
            ->where('hauls.status', '!=', 'cancelled')
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('haul_allocations')
                ->whereColumn('haul_allocations.haul_id', 'hauls.id')
                ->where('haul_allocations.status', '!=', 'cancelled'))
            ->orderBy('hauls.id')
            ->get(['hauls.id', 'hauls.haul_code', 'hauls.status', 'hauls.fuel_type_id', 'hauls.quantity_liters', 'purchases.purchase_code', 'fuel_types.name as fuel_name'])
            ->map(fn (object $haul): array => [
                'haul_id' => (int) $haul->id,
                'haul_code' => (string) $haul->haul_code,
                'haul_status' => (string) $haul->status,
                'purchase_code' => (string) $haul->purchase_code,
                'fuel_type_id' => (int) $haul->fuel_type_id,
                'fuel' => (string) $haul->fuel_name,
                'quantity_liters' => round((float) $haul->quantity_liters, 2),
                'storage_location_id' => isset($tanks[$haul->fuel_type_id]) ? (int) $tanks[$haul->fuel_type_id]->id : null,
                'tank' => isset($tanks[$haul->fuel_type_id]) ? (string) $tanks[$haul->fuel_type_id]->name : '(default tank will be created)',
            ]);
    }

    /**
     * Creates the allocations listed by backfillPlan(). Returns how many were created.
     */
    public function applyBackfill(): int
    {
        return DB::transaction(function (): int {
            $created = 0;
            foreach ($this->backfillPlan() as $row) {
                $tankId = $row['storage_location_id'] ?? $this->defaultTankId($row['fuel_type_id']);
                if (! $tankId) {
                    continue;
                }

                $this->createForHaul($row['haul_id'], $row['fuel_type_id'], [[
                    'destination_type' => 'garage',
                    'storage_location_id' => $tankId,
                    'sale_id' => null,
                    'customer_id' => null,
                    'quantity_liters' => $row['quantity_liters'],
                    'receive_on_completion' => false,
                ]]);
                $created++;
            }

            return $created;
        });
    }

    public function defaultTankId(int $fuelTypeId): ?int
    {
        $find = fn (): mixed => DB::table('storage_locations')
            ->where('type', 'garage')
            ->where('status', 'active')
            ->where('fuel_type_id', $fuelTypeId)
            ->orderBy('tank_number')
            ->orderBy('id')
            ->value('id');

        $id = $find();
        if ($id === null) {
            // Fresh installs only get their standard tanks once Inventory is opened; create them now.
            $this->garageTanks->ensureForActiveFuelTypes();
            $id = $find();
        }

        return $id === null ? null : (int) $id;
    }

    /**
     * Garage liters already promised to each tank by lifts that have not been received yet.
     *
     * @return array<int, float>
     */
    private function incomingByTank(): array
    {
        $received = DB::table('inventory_movements')
            ->where('reference_type', 'haul_allocation')
            ->where('direction', 'in')
            ->where('movement_type', 'stock_in')
            ->selectRaw('reference_id, COALESCE(SUM(quantity_liters), 0) as received_liters')
            ->groupBy('reference_id');

        return DB::table('haul_allocations')
            ->leftJoinSub($received, 'received', 'received.reference_id', '=', 'haul_allocations.id')
            ->where('destination_type', 'garage')
            ->whereNotIn('status', ['cancelled', 'received'])
            ->whereNotNull('storage_location_id')
            ->selectRaw('storage_location_id, SUM(quantity_liters - COALESCE(received.received_liters, 0)) as incoming')
            ->groupBy('storage_location_id')
            ->pluck('incoming', 'storage_location_id')
            ->map(fn (mixed $value): float => (float) $value)
            ->all();
    }

    private function openDirectSale(int $saleId): ?object
    {
        return DB::table('sales')
            ->where('id', $saleId)
            ->where('delivery_type', 'depot_to_client')
            ->whereNull('deleted_at')
            ->whereIn('status', self::OPEN_SALE_STATUSES)
            ->whereNotIn('delivery_status', ['delivered', 'cancelled'])
            ->first(['id', 'customer_id']);
    }

    /**
     * Liters of a fuel on a sale that are neither fulfilled nor already promised to another direct lift.
     */
    private function openDirectLiters(int $saleId, int $fuelTypeId): float
    {
        $item = DB::table('sale_items')
            ->where('sale_id', $saleId)
            ->where('fuel_type_id', $fuelTypeId)
            ->selectRaw('COALESCE(SUM(quantity_liters - f1_quantity_liters), 0) as quantity, COALESCE(SUM(fulfilled_quantity_liters), 0) as fulfilled')
            ->first();

        $released = DB::table('stock_outs')
            ->select('haul_allocation_id', DB::raw('SUM(quantity_liters) as released'))
            ->where('source_type', 'depot')
            ->where('status', '!=', 'cancelled')
            ->groupBy('haul_allocation_id');

        $promised = (float) DB::table('haul_allocations')
            ->leftJoinSub($released, 'released', 'released.haul_allocation_id', '=', 'haul_allocations.id')
            ->where('haul_allocations.destination_type', 'customer')
            ->where('haul_allocations.sale_id', $saleId)
            ->where('haul_allocations.fuel_type_id', $fuelTypeId)
            ->where('haul_allocations.status', '!=', 'cancelled')
            ->selectRaw('COALESCE(SUM(haul_allocations.quantity_liters - COALESCE(released.released, 0)), 0) as promised')
            ->value('promised');

        return round(max(0, (float) $item->quantity - (float) $item->fulfilled - $promised), 2);
    }

    /**
     * F1 liters of a sale not yet put on a lift (Depot to Client orders load their F1 at the garage).
     */
    private function unassignedF1Liters(int $saleId): float
    {
        $f1 = (float) DB::table('sale_items')->where('sale_id', $saleId)->sum('f1_quantity_liters');
        if ($f1 <= 0) {
            return 0.0;
        }

        $assigned = (float) DB::table('haul_allocations')
            ->where('sale_id', $saleId)
            ->where('destination_type', 'customer')
            ->where('status', '!=', 'cancelled')
            ->sum('f1_quantity_liters');

        return round(max(0, $f1 - $assigned), 2);
    }

    /**
     * F1 liters other planned lifts will load from a garage tank but that have not left it yet.
     */
    private function plannedF1ByTank(int $tankId): float
    {
        return round((float) DB::table('haul_allocations')
            ->where('f1_storage_location_id', $tankId)
            ->where('status', 'planned')
            ->sum('f1_quantity_liters'), 2);
    }
}
