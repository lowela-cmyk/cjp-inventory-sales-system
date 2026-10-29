<?php

namespace App\Http\Controllers;

use App\Services\GarageStockService;
use App\Services\LiftAllocationService;
use App\Services\SaleConfirmationService;
use App\Services\TruckAvailabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Part 12 (October 26, 2026): fuel left on a truck after its lift (Admin and Dispatch). While liters remain the truck
 * is Loaded. Two ways to empty it:
 * - Deliver from Truck: an open customer order (Depot to Client or Standard) supplied from a purchase the lift carries,
 *   up to the liters left, gets a delivery row on the lift and is delivered at once; it deducts from its purchase
 *   exactly once, through that row.
 * - Unload to Garage (Part 13, replaces Return to Depot): the liters left go into the fuel's garage tanks as lots.
 *   Garage fuel never returns to the depot. Lines returned to the depot before Part 13 keep their returned_liters.
 * The truck becomes Available when nothing is left.
 */
class LiftLeftoverController extends Controller
{
    public function __construct(
        private readonly LiftAllocationService $liftAllocations,
        private readonly SaleConfirmationService $saleConfirmation,
        private readonly GarageStockService $garageStock,
        private readonly TruckAvailabilityService $truckAvailability
    ) {}

    public function deliver(Request $request, int $haul): RedirectResponse
    {
        $data = $request->validate(['sale_id' => ['required', 'integer']], [], ['sale_id' => 'order']);
        $userId = (int) $request->user()->id;

        $message = DB::transaction(function () use ($haul, $data, $userId): string {
            $lines = $this->linesWithFuel($haul);
            $sale = $this->liftAllocations->truckOrderOptions($lines, true)->firstWhere('sale_id', (int) $data['sale_id']);
            if (! $sale) {
                throw ValidationException::withMessages(['sale_id' => 'Select an open order of this lift\'s fuel that fits in the '
                    .number_format((float) collect($lines)->sum('leftover'), 2).' L left on the truck.']);
            }

            $line = collect($lines)->firstWhere('haul_id', $sale->haul_id);
            $this->liftAllocations->createForHaul((int) $sale->haul_id, (int) $line['fuel_type_id'], [[
                'sale_id' => (int) $sale->sale_id,
                'customer_id' => (int) $sale->customer_id,
                'quantity_liters' => (float) $sale->open_liters,
            ]]);
            if ($sale->delivery_type !== 'depot_to_client') {
                // A Standard order delivered from this truck: its delivery details are the lift's truck and driver.
                DB::table('sales')->where('id', $sale->sale_id)->update([
                    'delivery_truck_id' => $line['truck_id'],
                    'delivery_driver_user_id' => $line['driver_user_id'],
                    'delivery_date' => now(),
                    'updated_at' => now(),
                ]);
            }
            $this->saleConfirmation->updateDeliveryStatus((int) $sale->sale_id, 'delivered', $userId);
            $this->truckAvailability->synchronizeStatus((int) $line['truck_id']);

            $left = $this->truckAvailability->leftoverOnTruck((int) $line['truck_id']);

            return 'Order '.$sale->sale_code.' delivered from the truck. '
                .($left > 0 ? number_format($left, 2).' L left on the truck.' : 'The truck is empty and Available again.');
        });

        return redirect()->back(302, [], route($this->redirectRoute($request)))->with('status', $message);
    }

    /**
     * Part 13: Unload to Garage. The fuel left on the truck goes into its fuel's garage tanks (Tank 1 and / or Tank 2),
     * as one lot per tank tied to the purchase line. Each tank takes at most its free space (the tanks are locked
     * while checking); whatever does not fit stays on the truck, which stays Loaded until nothing is left. Garage
     * fuel never goes back to the depot.
     */
    public function unloadToGarage(Request $request, int $haul): RedirectResponse
    {
        $data = $request->validate([
            'unload' => ['required', 'array'],
            'unload.*' => ['array'],
            'unload.*.*' => ['nullable', 'numeric', 'gte:0', 'max:1000000'],
        ], [], ['unload.*.*' => 'liters']);
        $userId = (int) $request->user()->id;

        $message = DB::transaction(function () use ($haul, $data, $userId): string {
            $lines = collect($this->linesWithFuel($haul))->keyBy('haul_id');
            $tanks = $this->garageStock->tanks($lines->pluck('fuel_type_id')->unique()->values()->all(), true);
            $errors = [];
            $plan = [];

            foreach ($data['unload'] as $haulId => $perTank) {
                $line = $lines->get((int) $haulId);
                $total = 0.0;
                foreach ((array) $perTank as $tankId => $liters) {
                    $liters = round((float) $liters, 2);
                    if ($liters <= 0) {
                        continue;
                    }
                    $key = "unload.$haulId.$tankId";
                    $tank = $tanks->get((int) $tankId);
                    if (! $line) {
                        $errors[$key] = 'This lift line has no fuel left on the truck.';
                    } elseif (! $tank || (int) $tank->fuel_type_id !== $line['fuel_type_id']) {
                        $errors[$key] = 'Unload '.$line['fuel_name'].' only into a '.$line['fuel_name'].' tank.';
                    } elseif ($liters > $tank->free) {
                        $errors[$key] = $tank->name.' has only '.number_format($tank->free, 2).' L free (it holds '.number_format($tank->contents, 2).' L of '.number_format($tank->capacity, 2).' L).';
                    } else {
                        $tank->free = round($tank->free - $liters, 2);
                        $tank->contents = round($tank->contents + $liters, 2);
                        $total = round($total + $liters, 2);
                        $plan[] = [$line, $tank, $liters];
                    }
                }
                if ($line && $total > $line['leftover']) {
                    $errors["unload.$haulId"] = 'Only '.number_format($line['leftover'], 2).' L of '.$line['fuel_name'].' are left on the truck.';
                }
            }
            if ($plan === [] && $errors === []) {
                $errors['unload'] = 'Enter the liters to unload into Tank 1 and / or Tank 2.';
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $now = now();
            $unloaded = [];
            foreach ($plan as [$line, $tank, $liters]) {
                $rowId = DB::table('haul_allocations')->insertGetId([
                    'haul_id' => $line['haul_id'],
                    'fuel_type_id' => $line['fuel_type_id'],
                    'destination_type' => 'garage',
                    'storage_location_id' => $tank->id,
                    'quantity_liters' => $liters,
                    'allocated_at' => $now,
                    'status' => 'received',
                    'receive_on_completion' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('garage_stocks')->insert([
                    'lot_code' => $this->nextLotCode(),
                    'storage_location_id' => $tank->id,
                    'purchase_item_id' => $line['purchase_item_id'],
                    'fuel_type_id' => $line['fuel_type_id'],
                    'haul_id' => $line['haul_id'],
                    'haul_allocation_id' => $rowId,
                    'truck_id' => $line['truck_id'],
                    'liters' => $liters,
                    'unit_cost' => $line['unit_cost'],
                    'unloaded_at' => $now,
                    'unloaded_by' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $unloaded[] = number_format($liters, 2).' L into '.$tank->name;
            }

            $truckId = (int) $lines->first()['truck_id'];
            $this->truckAvailability->synchronizeStatus($truckId);
            $left = $this->truckAvailability->leftoverOnTruck($truckId);

            return 'Unloaded '.implode(' and ', $unloaded).'. '
                .($left > 0 ? number_format($left, 2).' L stay on the truck (Loaded).' : 'The truck is empty and Available again.');
        });

        return redirect()->back(302, [], route($this->redirectRoute($request)))->with('status', $message);
    }

    private function nextLotCode(): string
    {
        do {
            $code = 'GRG-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        } while (DB::table('garage_stocks')->where('lot_code', $code)->exists());

        return $code;
    }

    /**
     * The lifted / completed lines of the lift (all lines of its schedule) that still have fuel on the truck, locked.
     *
     * @return array<int, array{haul_id: int, purchase_id: int, purchase_item_id: int, purchase_code: string, fuel_type_id: int, fuel_name: string, unit_cost: float, truck_id: int, driver_user_id: int, leftover: float}>
     */
    private function linesWithFuel(int $haul): array
    {
        $row = DB::table('hauls')->where('id', $haul)->whereNull('deleted_at')->first(['id', 'lifting_schedule_id']);
        abort_if(! $row, 404);

        $hauls = DB::table('hauls')
            ->join('purchases', 'purchases.id', '=', 'hauls.purchase_id')
            ->join('purchase_items', 'purchase_items.id', '=', 'hauls.purchase_item_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'hauls.fuel_type_id')
            ->when($row->lifting_schedule_id, fn ($query) => $query->where('hauls.lifting_schedule_id', $row->lifting_schedule_id), fn ($query) => $query->where('hauls.id', $row->id))
            ->whereNull('hauls.deleted_at')
            ->whereIn('hauls.status', ['lifted', 'completed'])
            ->orderBy('hauls.id')
            ->lockForUpdate()
            ->get(['hauls.id', 'hauls.purchase_id', 'hauls.purchase_item_id', 'hauls.fuel_type_id', 'hauls.truck_id', 'hauls.driver_user_id', 'purchases.purchase_code', 'purchase_items.unit_cost', 'fuel_types.name as fuel_name']);
        $loads = $this->liftAllocations->loadSummaryByHaul($hauls->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());

        $lines = $hauls->map(fn (object $haul): array => [
            'haul_id' => (int) $haul->id,
            'purchase_id' => (int) $haul->purchase_id,
            'purchase_item_id' => (int) $haul->purchase_item_id,
            'purchase_code' => (string) $haul->purchase_code,
            'fuel_type_id' => (int) $haul->fuel_type_id,
            'fuel_name' => (string) $haul->fuel_name,
            'unit_cost' => round((float) $haul->unit_cost, 2),
            'truck_id' => (int) $haul->truck_id,
            'driver_user_id' => (int) $haul->driver_user_id,
            'leftover' => round((float) ($loads[(int) $haul->id]['remaining'] ?? 0), 2),
        ])->filter(fn (array $line): bool => $line['leftover'] > 0)->values()->all();

        if ($lines === []) {
            throw ValidationException::withMessages(['lifting' => 'This lift has no fuel left on its truck.']);
        }

        return $lines;
    }

    private function redirectRoute(Request $request): string
    {
        return str_starts_with((string) $request->route()?->getName(), 'dispatch.')
            ? 'dispatch.fuel-lifting.hauled'
            : 'admin.fuel-lifting.hauled';
    }
}
