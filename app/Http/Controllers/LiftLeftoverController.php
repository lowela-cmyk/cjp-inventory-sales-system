<?php

namespace App\Http\Controllers;

use App\Services\LiftAllocationService;
use App\Services\PurchaseWorkflowService;
use App\Services\SaleConfirmationService;
use App\Services\TruckAvailabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Part 12 (October 26, 2026): fuel left on a truck after its lift (Admin and Dispatch). While liters remain the truck
 * is Loaded. Two ways to empty it:
 * - Deliver from Truck: an open customer order (Depot to Client or Standard) supplied from a purchase the lift carries,
 *   up to the liters left, gets a delivery row on the lift and is delivered at once; it deducts from its purchase
 *   exactly once, through that row.
 * - Return to Depot: the liters left go back to their purchases (the lift line's quantity goes down by them; the
 *   returned liters, time and user are kept on the line).
 * The truck becomes Available when nothing is left.
 */
class LiftLeftoverController extends Controller
{
    public function __construct(
        private readonly LiftAllocationService $liftAllocations,
        private readonly SaleConfirmationService $saleConfirmation,
        private readonly PurchaseWorkflowService $purchaseWorkflow,
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

    public function returnToDepot(Request $request, int $haul): RedirectResponse
    {
        $userId = (int) $request->user()->id;

        $message = DB::transaction(function () use ($haul, $userId): string {
            $lines = $this->linesWithFuel($haul);
            $returned = 0.0;

            foreach ($lines as $line) {
                DB::table('hauls')->where('id', $line['haul_id'])->update([
                    'quantity_liters' => DB::raw('quantity_liters - '.number_format($line['leftover'], 2, '.', '')),
                    'returned_liters' => DB::raw('returned_liters + '.number_format($line['leftover'], 2, '.', '')),
                    'returned_at' => now(),
                    'returned_by' => $userId,
                    'updated_at' => now(),
                ]);
                $this->syncHauledLiters($line['purchase_item_id']);
                $this->purchaseWorkflow->synchronize($line['purchase_id'], $userId, $line['haul_id']);
                $returned += $line['leftover'];
            }
            $this->truckAvailability->synchronizeStatus((int) $lines[0]['truck_id']);

            return number_format($returned, 2).' L returned to the depot ('.collect($lines)->pluck('purchase_code')->unique()->implode(', ')
                .'). The truck is Available again.';
        });

        return redirect()->back(302, [], route($this->redirectRoute($request)))->with('status', $message);
    }

    /**
     * The lifted / completed lines of the lift (all lines of its schedule) that still have fuel on the truck, locked.
     *
     * @return array<int, array{haul_id: int, purchase_id: int, purchase_item_id: int, purchase_code: string, fuel_type_id: int, truck_id: int, driver_user_id: int, leftover: float}>
     */
    private function linesWithFuel(int $haul): array
    {
        $row = DB::table('hauls')->where('id', $haul)->whereNull('deleted_at')->first(['id', 'lifting_schedule_id']);
        abort_if(! $row, 404);

        $hauls = DB::table('hauls')
            ->join('purchases', 'purchases.id', '=', 'hauls.purchase_id')
            ->when($row->lifting_schedule_id, fn ($query) => $query->where('hauls.lifting_schedule_id', $row->lifting_schedule_id), fn ($query) => $query->where('hauls.id', $row->id))
            ->whereNull('hauls.deleted_at')
            ->whereIn('hauls.status', ['lifted', 'completed'])
            ->orderBy('hauls.id')
            ->lockForUpdate()
            ->get(['hauls.id', 'hauls.purchase_id', 'hauls.purchase_item_id', 'hauls.fuel_type_id', 'hauls.truck_id', 'hauls.driver_user_id', 'purchases.purchase_code']);
        $loads = $this->liftAllocations->loadSummaryByHaul($hauls->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());

        $lines = $hauls->map(fn (object $haul): array => [
            'haul_id' => (int) $haul->id,
            'purchase_id' => (int) $haul->purchase_id,
            'purchase_item_id' => (int) $haul->purchase_item_id,
            'purchase_code' => (string) $haul->purchase_code,
            'fuel_type_id' => (int) $haul->fuel_type_id,
            'truck_id' => (int) $haul->truck_id,
            'driver_user_id' => (int) $haul->driver_user_id,
            'leftover' => round((float) ($loads[(int) $haul->id]['remaining'] ?? 0), 2),
        ])->filter(fn (array $line): bool => $line['leftover'] > 0)->values()->all();

        if ($lines === []) {
            throw ValidationException::withMessages(['lifting' => 'This lift has no fuel left on its truck.']);
        }

        return $lines;
    }

    /** Hauled liters of a purchase line = its completed lifts (a return lowers them), as when a lift is completed. */
    private function syncHauledLiters(int $purchaseItemId): void
    {
        $item = DB::table('purchase_items')->where('id', $purchaseItemId)->lockForUpdate()->first(['id', 'quantity_ordered_liters']);
        $completed = (float) DB::table('hauls')->where('purchase_item_id', $purchaseItemId)->whereNull('deleted_at')->where('status', 'completed')->sum('quantity_liters');
        $hauled = min(round((float) $item->quantity_ordered_liters, 2), round($completed, 2));

        DB::table('purchase_items')->where('id', $purchaseItemId)->update([
            'quantity_hauled_liters' => $hauled,
            'status' => match (true) {
                $hauled <= 0 => 'unlifted',
                $hauled >= round((float) $item->quantity_ordered_liters, 2) => 'lifted',
                default => 'partial',
            },
            'updated_at' => now(),
        ]);
    }

    private function redirectRoute(Request $request): string
    {
        return str_starts_with((string) $request->route()?->getName(), 'dispatch.')
            ? 'dispatch.fuel-lifting.hauled'
            : 'admin.fuel-lifting.hauled';
    }
}
