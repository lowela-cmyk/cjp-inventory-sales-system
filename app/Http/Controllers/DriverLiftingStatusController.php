<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DriverLiftingStatusController extends Controller
{
    public const STATUS_TRANSITIONS = [
        'scheduled' => ['in_transit'],
        'in_transit' => ['lifted'],
        'lifted' => [],
        'completed' => [],
        'cancelled' => [],
    ];

    public function updateStatus(Request $request, int $haul): RedirectResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'lifting_status' => ['required', Rule::in(DispatchLiftingStatusController::LIFTING_STATUSES)],
        ]);
        $driverId = (int) $request->user()->id;
        $sessionKey = 'driver.hauls.status.'.$driverId.'.'.$haul.'.'.((string) $data['idempotency_key']);

        if ($request->session()->has($sessionKey)) {
            return redirect()
                ->route('driver.fuel-lifting')
                ->with('status', 'Lifting status update was already submitted.');
        }

        $result = DB::transaction(function () use ($haul, $driverId, $data): ?string {
            $row = $this->haulForDriverUpdate($haul, $driverId);

            if (! $row) {
                return 'The selected lifting task is not assigned to your driver account.';
            }

            $nextStatus = (string) $data['lifting_status'];
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

            if ($nextStatus === 'lifted') {
                $updates['hauled_at'] = $row->hauled_at ?: now();
            }

            DB::table('hauls')
                ->where('id', $row->id)
                ->where('driver_user_id', $driverId)
                ->where('status', $row->status)
                ->update($updates);

            return null;
        });

        if ($result) {
            return back()->withErrors(['lifting' => $result])->withInput();
        }

        $request->session()->put($sessionKey, true);

        return redirect()
            ->route('driver.fuel-lifting')
            ->with('status', 'Lifting status updated successfully.');
    }

    private function haulForDriverUpdate(int $haul, int $driverId): ?object
    {
        return DB::table('hauls')
            ->join('purchases', 'purchases.id', '=', 'hauls.purchase_id')
            ->join('purchase_items', 'purchase_items.id', '=', 'hauls.purchase_item_id')
            ->join('depots', 'depots.id', '=', 'hauls.depot_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'hauls.fuel_type_id')
            ->join('trucks', 'trucks.id', '=', 'hauls.truck_id')
            ->join('users as drivers', 'drivers.id', '=', 'hauls.driver_user_id')
            ->leftJoin('driver_profiles', 'driver_profiles.user_id', '=', 'drivers.id')
            ->where('hauls.id', $haul)
            ->where('hauls.driver_user_id', $driverId)
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
                'driver_profiles.status as profile_status',
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

        if ($haul->driver_role !== 'driver' || $haul->driver_status !== 'active' || $haul->profile_status === 'inactive') {
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

        if (in_array($nextStatus, ['in_transit', 'lifted'], true) && ! $haul->scheduled_at) {
            return 'A scheduled lifting date is required before progressing this lift.';
        }

        return $this->validateAllocations((int) $haul->id, (int) $haul->fuel_type_id, $quantity);
    }

    private function validateAllocations(int $haulId, int $fuelTypeId, float $haulQuantity): ?string
    {
        $allocations = DB::table('haul_allocations')
            ->where('haul_id', $haulId)
            ->where('status', '<>', 'cancelled')
            ->get([
                'fuel_type_id',
                'destination_type',
                'storage_location_id',
                'customer_id',
                'quantity_liters',
            ]);

        if ($allocations->isEmpty()) {
            return null;
        }

        $allocatedQuantity = 0.0;

        foreach ($allocations as $allocation) {
            if ((int) $allocation->fuel_type_id !== $fuelTypeId) {
                return 'Lift allocation fuel type must match the lifting task fuel type.';
            }

            if ($allocation->destination_type === 'garage' && ! $allocation->storage_location_id) {
                return 'Garage-bound lifting tasks require a valid garage destination.';
            }

            if ($allocation->destination_type === 'customer' && ! $allocation->customer_id) {
                return 'Client-bound lifting tasks require a valid customer destination.';
            }

            if (! in_array($allocation->destination_type, ['garage', 'customer'], true)) {
                return 'Lift allocation destination must be Garage or Client.';
            }

            $allocatedQuantity += (float) $allocation->quantity_liters;
        }

        if (round($allocatedQuantity, 2) > round($haulQuantity, 2)) {
            return 'Lift allocation quantity cannot exceed the assigned lifting quantity.';
        }

        return null;
    }
}
