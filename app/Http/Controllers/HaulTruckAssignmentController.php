<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HaulTruckAssignmentController extends Controller
{
    private const ASSIGNABLE_HAUL_STATUSES = ['scheduled'];
    private const ACTIVE_HAUL_STATUSES = ['scheduled', 'in_transit', 'lifted'];
    private const ACTIVE_DELIVERY_STATUSES = ['scheduled', 'in_transit', 'incomplete'];

    public function update(Request $request, int $haul): RedirectResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'truck_id' => ['required', 'integer'],
        ]);
        $sessionKey = 'hauls.truck.'.$haul.'.'.((string) $data['idempotency_key']);

        if ($request->session()->has($sessionKey)) {
            return redirect()
                ->route($this->redirectRoute($request))
                ->with('status', 'Truck assignment was already submitted.');
        }

        $result = DB::transaction(function () use ($haul, $data): ?string {
            $row = $this->haulForUpdate($haul);

            if (! $row) {
                return 'The selected lift transaction does not exist or is missing required records.';
            }

            if (! in_array($row->status, self::ASSIGNABLE_HAUL_STATUSES, true)) {
                return 'This lift status does not allow truck assignment changes.';
            }

            $truck = $this->truckForAssignment((int) $data['truck_id'], (int) $row->truck_id === (int) $data['truck_id']);

            if (! $truck) {
                return 'The selected truck is not eligible for lift assignment.';
            }

            $quantity = round((float) $row->quantity_liters, 2);

            if ($quantity <= 0 || $quantity > round((float) $row->quantity_ordered_liters, 2)) {
                return 'Lift quantity must be positive and cannot exceed the authorized purchase item quantity.';
            }

            if ($quantity > round((float) $truck->capacity_liters, 2)) {
                return 'Lift quantity cannot exceed the selected truck capacity.';
            }

            if (! $this->truckIsAvailable((int) $truck->id, CarbonImmutable::parse($row->scheduled_at), (int) $row->id)) {
                return 'The selected truck already has an active trip at this schedule.';
            }

            DB::table('hauls')
                ->where('id', $row->id)
                ->update([
                    'truck_id' => $truck->id,
                    'updated_at' => now(),
                ]);

            return null;
        });

        if ($result) {
            return back()->withErrors(['truck' => $result])->withInput();
        }

        $request->session()->put($sessionKey, true);

        return redirect()
            ->route($this->redirectRoute($request))
            ->with('status', 'Truck assignment updated successfully.');
    }

    private function redirectRoute(Request $request): string
    {
        return str_starts_with((string) $request->route()?->getName(), 'admin.')
            ? 'admin.fuel-lifting'
            : 'dispatch.fuel-lifting';
    }

    private function haulForUpdate(int $haul): ?object
    {
        return DB::table('hauls')
            ->join('purchases', 'purchases.id', '=', 'hauls.purchase_id')
            ->join('purchase_items', 'purchase_items.id', '=', 'hauls.purchase_item_id')
            ->join('depots', 'depots.id', '=', 'hauls.depot_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'hauls.fuel_type_id')
            ->where('hauls.id', $haul)
            ->whereNull('purchases.deleted_at')
            ->where('purchases.status', '!=', 'cancelled')
            ->where('depots.status', 'active')
            ->where('fuel_types.status', 'active')
            ->whereColumn('hauls.purchase_id', 'purchase_items.purchase_id')
            ->whereColumn('hauls.depot_id', 'purchases.depot_id')
            ->whereColumn('hauls.fuel_type_id', 'purchase_items.fuel_type_id')
            ->lockForUpdate()
            ->first([
                'hauls.id',
                'hauls.truck_id',
                'hauls.scheduled_at',
                'hauls.quantity_liters',
                'hauls.status',
                'purchase_items.quantity_ordered_liters',
            ]);
    }

    private function truckForAssignment(int $truckId, bool $allowCurrent): ?object
    {
        return DB::table('trucks')
            ->where('id', $truckId)
            ->whereIn('truck_type', ['hauling', 'mixed'])
            ->where(function (Builder $query) use ($allowCurrent): void {
                $query->where('status', 'available');

                if ($allowCurrent) {
                    $query->orWhere('status', 'assigned');
                }
            })
            ->lockForUpdate()
            ->first(['id', 'capacity_liters']);
    }

    private function truckIsAvailable(int $truckId, CarbonImmutable $scheduledAt, int $exceptHaulId): bool
    {
        $hasHaulConflict = DB::table('hauls')
            ->where('truck_id', $truckId)
            ->where('id', '!=', $exceptHaulId)
            ->whereIn('status', self::ACTIVE_HAUL_STATUSES)
            ->where('scheduled_at', $scheduledAt->toDateTimeString())
            ->lockForUpdate()
            ->exists();

        if ($hasHaulConflict) {
            return false;
        }

        return ! DB::table('deliveries')
            ->where('truck_id', $truckId)
            ->whereIn('status', self::ACTIVE_DELIVERY_STATUSES)
            ->where('scheduled_at', $scheduledAt->toDateTimeString())
            ->lockForUpdate()
            ->exists();
    }
}
