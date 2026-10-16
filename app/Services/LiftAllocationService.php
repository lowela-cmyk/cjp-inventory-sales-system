<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Garage allocation of each lift, entered when the lift is scheduled (audit C01).
 *
 * The whole Amount to Lift goes into the chosen garage tank; completing the lift posts the stock-in.
 * Part 8 (#5) removed new depot (direct) allocations from Schedule Lift. Lifts that already carry a
 * direct (customer) allocation keep settling as before: completing them releases the liters against
 * their sale (see settle()), and StockOutReleaseService still honours them on delivery.
 * A lift scheduled without a tank (older clients) gets its full quantity on the fuel's default tank with
 * receive_on_completion = false, so it waits under Pending Garage Receipts for the Inventory Officer to
 * confirm the tank ("Assign Tank").
 */
class LiftAllocationService
{
    public function __construct(
        private readonly StockInService $stockIn,
        private readonly SaleConfirmationService $saleConfirmation,
        private readonly GarageTankService $garageTanks
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
     * Normalizes each Schedule Lift line and validates its tank. Field errors are keyed per line
     * (items.N.storage_location_id, items.N.quantity_liters). Any direct-allocation fields an older
     * client still sends are ignored: the whole Amount to Lift goes to the garage tank.
     *
     * @param  array<int, array<string, mixed>>  $lines  each with purchase_item_id, quantity_liters and an optional storage_location_id
     * @param  array<int, int>  $fuelTypeByItem  purchase_item_id => fuel_type_id
     * @return array<int, array{garage_liters: float, storage_location_id: ?int, direct_liters: float, sale_id: ?int, customer_id: ?int, receive_on_completion: bool}>
     *
     * @throws ValidationException
     */
    public function resolveLines(array $lines, array $fuelTypeByItem): array
    {
        $errors = [];
        $resolved = [];
        $tanks = $this->tankOptions()->keyBy('id');
        $requestedByTank = [];

        foreach ($lines as $index => $line) {
            $quantity = round((float) $line['quantity_liters'], 2);
            $fuelTypeId = (int) ($fuelTypeByItem[(int) $line['purchase_item_id']] ?? 0);
            $tankId = ($line['storage_location_id'] ?? null) ? (int) $line['storage_location_id'] : null;

            if ($tankId === null) {
                // Older clients send no tank: the whole lift goes to the fuel's default tank for confirmation.
                $resolved[$index] = [
                    'garage_liters' => $quantity,
                    'storage_location_id' => $this->defaultTankId($fuelTypeId),
                    'direct_liters' => 0.0,
                    'sale_id' => null,
                    'customer_id' => null,
                    'receive_on_completion' => false,
                ];

                continue;
            }

            $tank = $tanks->get($tankId);
            if (! $tank) {
                $errors["items.$index.storage_location_id"] = 'Select the garage tank that receives this lift.';
            } elseif ((int) $tank->fuel_type_id !== $fuelTypeId) {
                $errors["items.$index.storage_location_id"] = 'The tank must hold the same fuel type as the purchase.';
            } else {
                $requestedByTank[$tankId] = round(($requestedByTank[$tankId] ?? 0) + $quantity, 2);
                if ($tank->free_liters !== null && $requestedByTank[$tankId] > $tank->free_liters) {
                    $errors["items.$index.quantity_liters"] = $tank->name.' has only '.number_format($tank->free_liters, 2).' L of free capacity.';
                }
            }

            $resolved[$index] = [
                'garage_liters' => $quantity,
                'storage_location_id' => $tankId,
                'direct_liters' => 0.0,
                'sale_id' => null,
                'customer_id' => null,
                'receive_on_completion' => true,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $resolved;
    }

    /**
     * @param  array{garage_liters: float, storage_location_id: ?int, direct_liters: float, sale_id: ?int, customer_id: ?int, receive_on_completion: bool}  $allocation
     */
    public function createForHaul(int $haulId, int $fuelTypeId, array $allocation): void
    {
        $base = ['haul_id' => $haulId, 'fuel_type_id' => $fuelTypeId, 'allocated_at' => now(), 'status' => 'planned', 'created_at' => now(), 'updated_at' => now()];

        if ($allocation['garage_liters'] > 0 && $allocation['storage_location_id']) {
            DB::table('haul_allocations')->insert($base + [
                'destination_type' => 'garage',
                'storage_location_id' => $allocation['storage_location_id'],
                'quantity_liters' => $allocation['garage_liters'],
                'receive_on_completion' => $allocation['receive_on_completion'],
            ]);
        }

        if ($allocation['direct_liters'] > 0 && $allocation['customer_id']) {
            DB::table('haul_allocations')->insert($base + [
                'destination_type' => 'customer',
                'customer_id' => $allocation['customer_id'],
                'sale_id' => $allocation['sale_id'],
                'quantity_liters' => $allocation['direct_liters'],
                'receive_on_completion' => true,
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

                $this->createForHaul($row['haul_id'], $row['fuel_type_id'], [
                    'garage_liters' => $row['quantity_liters'],
                    'storage_location_id' => $tankId,
                    'direct_liters' => 0.0,
                    'sale_id' => null,
                    'customer_id' => null,
                    'receive_on_completion' => false,
                ]);
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
}
