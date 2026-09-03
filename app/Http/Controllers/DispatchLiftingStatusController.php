<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DispatchLiftingStatusController extends Controller
{
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

        $result = DB::transaction(function () use ($haul, $data): ?string {
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
                'updated_at' => now(),
            ];

            if ($nextStatus === 'completed') {
                $updates['hauled_at'] = $row->hauled_at ?: now();
            }

            DB::table('hauls')
                ->where('id', $row->id)
                ->update($updates);

            if ($nextStatus === 'completed') {
                $this->syncPurchaseProgress((int) $row->purchase_id, (int) $row->purchase_item_id);
            }

            return null;
        });

        if ($result) {
            return back()->withErrors(['lifting' => $result])->withInput();
        }

        $request->session()->put($sessionKey, true);

        return redirect()
            ->route($this->redirectRoute($request))
            ->with('status', 'Lifting status updated successfully.');
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
            ->whereNull('purchases.deleted_at')
            ->whereColumn('hauls.purchase_id', 'purchase_items.purchase_id')
            ->whereColumn('hauls.depot_id', 'purchases.depot_id')
            ->whereColumn('hauls.fuel_type_id', 'purchase_items.fuel_type_id')
            ->lockForUpdate()
            ->first([
                'hauls.id',
                'hauls.purchase_id',
                'hauls.purchase_item_id',
                'hauls.depot_id',
                'hauls.fuel_type_id',
                'hauls.truck_id',
                'hauls.driver_user_id',
                'hauls.scheduled_at',
                'hauls.hauled_at',
                'hauls.quantity_liters',
                'hauls.status',
                'purchases.status as purchase_status',
                'purchase_items.quantity_ordered_liters',
                'trucks.truck_type',
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

        if (! in_array($haul->truck_type, ['hauling', 'mixed'], true) || in_array($haul->truck_status, ['maintenance', 'inactive'], true)) {
            return 'A valid assigned hauling truck is required before updating this lift status.';
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

        if ($quantity <= 0 || $quantity > round((float) $haul->quantity_ordered_liters, 2)) {
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
