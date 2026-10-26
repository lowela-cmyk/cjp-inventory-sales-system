<?php

namespace App\Http\Controllers;

use App\Services\LiftAllocationService;
use App\Services\PurchaseWorkflowService;
use App\Services\TruckAvailabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DispatchLiftingStatusController extends Controller
{
    public function __construct(
        private readonly PurchaseWorkflowService $purchaseWorkflow,
        private readonly TruckAvailabilityService $truckAvailability,
        private readonly LiftAllocationService $liftAllocations
    ) {}

    public const LIFTING_STATUSES = ['scheduled', 'in_transit', 'lifted', 'completed', 'cancelled'];

    public const STATUS_TRANSITIONS = [
        'scheduled' => ['in_transit', 'cancelled'],
        'in_transit' => ['lifted', 'cancelled'],
        'lifted' => ['completed'],
        'completed' => [],
        'cancelled' => [],
    ];

    public function updateStatus(Request $request, int $haul): RedirectResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'status' => ['required', Rule::in(self::LIFTING_STATUSES)],
        ]);
        $sessionKey = 'hauls.status.'.$haul.'.'.((string) $data['idempotency_key']);

        if ($request->session()->has($sessionKey)) {
            return redirect()
                ->route($this->redirectRoute($request))
                ->with('status', 'Lifting status update was already submitted.');
        }

        $notes = [];
        $result = DB::transaction(function () use ($haul, $data, $request, &$notes): ?string {
            $row = $this->haulForUpdate($haul);

            if (! $row) {
                return 'The selected lift transaction does not exist or is missing required records.';
            }

            $nextStatus = (string) $data['status'];
            $allowed = self::STATUS_TRANSITIONS[$row->status] ?? [];

            if (! in_array($nextStatus, $allowed, true)) {
                return 'The selected lifting status transition is not allowed.';
            }

            $validationError = $this->validateLiftForStatus($row, $nextStatus);

            if ($validationError) {
                return $validationError;
            }

            $updates = [
                'status' => $nextStatus,
                'status_updated_by' => (int) $request->user()->id,
                'status_updated_at' => now(),
                'updated_at' => now(),
            ];

            if ($nextStatus === 'completed') {
                $updates['hauled_at'] = $row->hauled_at ?: now();
            }

            DB::table('hauls')
                ->where('id', $row->id)
                ->update($updates);

            $purchaseIds = collect([(int) $row->purchase_id]);
            $haulIds = collect([(int) $row->id]);
            if ($row->lifting_schedule_id) {
                $siblings = DB::table('hauls')
                    ->where('lifting_schedule_id', $row->lifting_schedule_id)
                    ->where('id', '!=', $row->id)
                    ->where('status', $row->status)
                    ->lockForUpdate()
                    ->get(['id', 'purchase_id', 'purchase_item_id']);

                DB::table('hauls')
                    ->whereIn('id', $siblings->pluck('id'))
                    ->update($updates);
                DB::table('lifting_schedules')->where('id', $row->lifting_schedule_id)->update([
                    'status' => $nextStatus,
                    'updated_at' => now(),
                ]);
                $purchaseIds = $purchaseIds->merge($siblings->pluck('purchase_id')->map(fn ($id): int => (int) $id));
                $haulIds = $haulIds->merge($siblings->pluck('id')->map(fn ($id): int => (int) $id));

                if ($nextStatus === 'completed') {
                    foreach ($siblings as $sibling) {
                        $this->syncPurchaseProgress((int) $sibling->purchase_id, (int) $sibling->purchase_item_id);
                    }
                }
            }

            if ($nextStatus === 'completed') {
                $this->syncPurchaseProgress((int) $row->purchase_id, (int) $row->purchase_item_id);
            }

            // Finishing a lift posts its allocations (idempotent); cancelling frees the tank/sale reservation.
            if (in_array($nextStatus, ['lifted', 'completed'], true)) {
                foreach ($haulIds as $haulId) {
                    $notes = array_merge($notes, $this->liftAllocations->settle($haulId, (int) $request->user()->id));
                }
            } elseif ($nextStatus === 'cancelled') {
                $this->liftAllocations->cancelForHauls($haulIds->all());
            }

            foreach ($purchaseIds->unique() as $purchaseId) {
                $this->purchaseWorkflow->synchronize((int) $purchaseId, (int) $request->user()->id, (int) $row->id);
            }

            $this->truckAvailability->synchronizeStatus((int) $row->truck_id);

            return null;
        });

        if ($result) {
            return back()->withErrors(['lifting' => $result])->withInput();
        }

        $request->session()->put($sessionKey, true);

        return redirect()
            ->route($this->redirectRoute($request))
            ->with('status', trim('Lifting status updated successfully. '.implode(' ', $notes)));
    }

    /**
     * Part 11: delete a lift that is still only Scheduled (Admin and Dispatch). Undoes what scheduling did: its hauls stop
     * holding the purchases' depot liters, its Depot to Client rows free their orders, the purchases and the truck
     * re-sync. The lift and its hauls are soft-deleted (deleted_at / deleted_by) and drop out of every list and count.
     */
    public function destroy(Request $request, int $haul): RedirectResponse
    {
        $code = DB::transaction(function () use ($haul, $request): string {
            $row = DB::table('hauls')->where('id', $haul)->whereNull('deleted_at')->lockForUpdate()->first(['id', 'lifting_schedule_id', 'haul_code']);
            abort_if(! $row, 404);

            $schedule = $row->lifting_schedule_id
                ? DB::table('lifting_schedules')->where('id', $row->lifting_schedule_id)->lockForUpdate()->first(['id', 'schedule_code', 'status'])
                : null;
            $hauls = DB::table('hauls')
                ->when($schedule, fn ($query) => $query->where('lifting_schedule_id', $schedule->id), fn ($query) => $query->where('id', $row->id))
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->get(['id', 'purchase_id', 'truck_id', 'status', 'hauled_at', 'withdrawal_receipt_path']);
            $haulIds = $hauls->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

            $error = $this->deleteBlocker($schedule, $hauls, $haulIds);
            if ($error) {
                throw ValidationException::withMessages(['lifting' => $error]);
            }

            $deleted = [
                'status' => 'cancelled',
                'deleted_at' => now(),
                'deleted_by' => (int) $request->user()->id,
                'updated_at' => now(),
            ];
            DB::table('hauls')->whereIn('id', $haulIds)->update($deleted + [
                'status_updated_by' => (int) $request->user()->id,
                'status_updated_at' => now(),
            ]);
            if ($schedule) {
                DB::table('lifting_schedules')->where('id', $schedule->id)->update($deleted);
            }

            $this->liftAllocations->cancelForHauls($haulIds);
            foreach ($hauls->pluck('purchase_id')->unique() as $purchaseId) {
                $this->purchaseWorkflow->synchronize((int) $purchaseId, (int) $request->user()->id, (int) $row->id);
            }
            foreach ($hauls->pluck('truck_id')->unique() as $truckId) {
                $this->truckAvailability->synchronizeStatus((int) $truckId);
            }

            return $schedule->schedule_code ?? $row->haul_code;
        });

        return redirect()
            ->route($this->redirectRoute($request))
            ->with('status', 'Lift '.$code.' deleted. Its liters, orders and truck are free again.');
    }

    /**
     * Only a lift that never left Scheduled can be deleted: no receipt, nothing delivered or released from it.
     *
     * @param  \Illuminate\Support\Collection<int, \stdClass>  $hauls
     * @param  array<int, int>  $haulIds
     */
    private function deleteBlocker(?object $schedule, $hauls, array $haulIds): ?string
    {
        if (($schedule && $schedule->status === 'cancelled') || $hauls->contains('status', 'cancelled')) {
            return 'This lift is already cancelled.';
        }

        if (($schedule && $schedule->status !== 'scheduled') || $hauls->contains(fn (object $haul): bool => $haul->status !== 'scheduled')) {
            return 'Only a Scheduled lift can be deleted. This lift is already In Transit or done.';
        }

        if ($hauls->contains(fn (object $haul): bool => $haul->withdrawal_receipt_path || $haul->hauled_at)) {
            return 'This lift has a withdrawal receipt or a hauled date, so it cannot be deleted.';
        }

        $allocationIds = DB::table('haul_allocations')->whereIn('haul_id', $haulIds)->pluck('id');
        $delivered = DB::table('haul_allocations')->whereIn('id', $allocationIds)->whereNotIn('status', ['planned', 'cancelled'])->exists()
            || DB::table('stock_outs')->whereIn('haul_allocation_id', $allocationIds)->exists()
            || DB::table('inventory_movements')->where('reference_type', 'haul_allocation')->whereIn('reference_id', $allocationIds)->exists();

        return $delivered ? 'Fuel on this lift was already delivered or released, so it cannot be deleted.' : null;
    }

    private function redirectRoute(Request $request): string
    {
        return str_starts_with((string) $request->route()?->getName(), 'dispatch.')
            ? 'dispatch.fuel-lifting'
            : 'admin.fuel-lifting';
    }

    private function haulForUpdate(int $haul): ?object
    {
        return DB::table('hauls')
            ->join('purchases', 'purchases.id', '=', 'hauls.purchase_id')
            ->join('purchase_items', 'purchase_items.id', '=', 'hauls.purchase_item_id')
            ->join('depots', 'depots.id', '=', 'hauls.depot_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'hauls.fuel_type_id')
            ->join('trucks', 'trucks.id', '=', 'hauls.truck_id')
            ->join('users as drivers', 'drivers.id', '=', 'hauls.driver_user_id')
            ->where('hauls.id', $haul)
            ->whereNull('hauls.deleted_at')
            ->whereNull('purchases.deleted_at')
            ->whereColumn('hauls.purchase_id', 'purchase_items.purchase_id')
            ->whereColumn('hauls.depot_id', 'purchases.depot_id')
            ->whereColumn('hauls.fuel_type_id', 'purchase_items.fuel_type_id')
            ->lockForUpdate()
            ->first([
                'hauls.id',
                'hauls.lifting_schedule_id',
                'hauls.purchase_id',
                'hauls.purchase_item_id',
                'hauls.depot_id',
                'hauls.fuel_type_id',
                'hauls.truck_id',
                'hauls.driver_user_id',
                'hauls.scheduled_at',
                'hauls.hauled_at',
                'hauls.quantity_liters',
                'hauls.returned_liters',
                'hauls.status',
                'purchases.status as purchase_status',
                'purchase_items.quantity_ordered_liters',
                'trucks.status as truck_status',
                'drivers.role as driver_role',
                'drivers.status as driver_status',
                'depots.status as depot_status',
                'fuel_types.status as fuel_status',
            ]);
    }

    private function validateLiftForStatus(object $haul, string $nextStatus): ?string
    {
        if ($haul->purchase_status === 'cancelled') {
            return 'Cancelled purchases cannot be lifted.';
        }

        // Part 11: any truck type may lift; the truck must be assigned and in service.
        if ($haul->truck_status === null || in_array($haul->truck_status, ['maintenance', 'inactive'], true)) {
            return 'A valid assigned truck is required before updating this lift status.';
        }

        if ($haul->driver_role !== 'driver' || $haul->driver_status !== 'active') {
            return 'A valid assigned driver is required before updating this lift status.';
        }

        if ($haul->depot_status !== 'active') {
            return 'A valid source depot is required before updating this lift status.';
        }

        if ($haul->fuel_status !== 'active') {
            return 'A valid fuel type is required before updating this lift status.';
        }

        $quantity = round((float) $haul->quantity_liters, 2);

        // A line whose leftover was all returned to the depot (Part 12) keeps 0 L and can still be completed.
        if (($quantity <= 0 && (float) $haul->returned_liters <= 0) || $quantity > round((float) $haul->quantity_ordered_liters, 2)) {
            return 'Lift quantity must be positive and cannot exceed the authorized purchase item quantity.';
        }

        if (in_array($nextStatus, ['in_transit', 'lifted', 'completed'], true) && ! $haul->scheduled_at) {
            return 'A scheduled lifting date is required before progressing this lift.';
        }

        if ($nextStatus === 'completed' && $quantity > $this->remainingPurchaseItemLiftQuantity($haul)) {
            return 'Lift quantity cannot exceed the remaining available purchase fuel.';
        }

        return null;
    }

    private function remainingPurchaseItemLiftQuantity(object $haul): float
    {
        DB::table('hauls')
            ->where('purchase_item_id', $haul->purchase_item_id)
            ->lockForUpdate()
            ->get(['id']);

        $committedLiters = (float) DB::table('hauls')
            ->where('purchase_item_id', $haul->purchase_item_id)
            ->where('id', '!=', $haul->id)
            ->whereIn('status', ['lifted', 'completed'])
            ->sum('quantity_liters');

        return round(max(0, (float) $haul->quantity_ordered_liters - $committedLiters), 2);
    }

    private function syncPurchaseProgress(int $purchaseId, int $purchaseItemId): void
    {
        DB::table('purchase_items')
            ->where('purchase_id', $purchaseId)
            ->lockForUpdate()
            ->get(['id']);

        $item = DB::table('purchase_items')
            ->where('id', $purchaseItemId)
            ->where('purchase_id', $purchaseId)
            ->first(['id', 'quantity_ordered_liters']);

        if (! $item) {
            return;
        }

        $completedLiters = (float) DB::table('hauls')
            ->where('purchase_item_id', $purchaseItemId)
            ->where('status', 'completed')
            ->sum('quantity_liters');
        $hauledLiters = min(round((float) $item->quantity_ordered_liters, 2), round($completedLiters, 2));

        DB::table('purchase_items')
            ->where('id', $purchaseItemId)
            ->update([
                'quantity_hauled_liters' => $hauledLiters,
                'status' => $this->itemStatus($hauledLiters, (float) $item->quantity_ordered_liters),
                'updated_at' => now(),
            ]);

        $totals = DB::table('purchase_items')
            ->where('purchase_id', $purchaseId)
            ->selectRaw('COALESCE(SUM(quantity_ordered_liters), 0) as ordered_liters, COALESCE(SUM(quantity_hauled_liters), 0) as hauled_liters')
            ->first();

        DB::table('purchases')
            ->where('id', $purchaseId)
            ->update([
                'status' => $this->purchaseStatus((float) $totals->hauled_liters, (float) $totals->ordered_liters),
                'updated_at' => now(),
            ]);
    }

    private function itemStatus(float $hauled, float $ordered): string
    {
        return match (true) {
            $hauled <= 0 => 'unlifted',
            round($hauled, 2) >= round($ordered, 2) => 'lifted',
            default => 'partial',
        };
    }

    private function purchaseStatus(float $hauled, float $ordered): string
    {
        return match (true) {
            $hauled <= 0 => 'ordered',
            round($hauled, 2) >= round($ordered, 2) => 'hauled',
            default => 'partially_hauled',
        };
    }
}
