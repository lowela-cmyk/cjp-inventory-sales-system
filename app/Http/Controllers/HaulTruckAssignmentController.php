<?php

namespace App\Http\Controllers;

use App\Services\TruckAvailabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HaulTruckAssignmentController extends Controller
{
    private const ASSIGNABLE_HAUL_STATUSES = ['scheduled'];

    public function __construct(private readonly TruckAvailabilityService $truckAvailability) {}

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

            $truck = $this->truckAvailability->lockAssignableTruck(
                (int) $data['truck_id'],
                $row->lifting_schedule_id ? null : (int) $row->id,
                $row->lifting_schedule_id ? (int) $row->lifting_schedule_id : null
            );

            if (! $truck) {
                return 'The selected truck is unavailable or already assigned to an active lift.';
            }

            $itemQuantity = round((float) $row->quantity_liters, 2);
            $truckLoad = $row->lifting_schedule_id
                ? round((float) DB::table('hauls')->where('lifting_schedule_id', $row->lifting_schedule_id)->sum('quantity_liters'), 2)
                : $itemQuantity;

            if ($itemQuantity <= 0 || $itemQuantity > round((float) $row->quantity_ordered_liters, 2)) {
                return 'Lift quantity must be positive and cannot exceed the authorized purchase item quantity.';
            }

            if ($truckLoad > round((float) $truck->capacity_liters, 2)) {
                return 'Lift quantity cannot exceed the selected truck capacity.';
            }

            $oldTruckIds = $row->lifting_schedule_id
                ? DB::table('hauls')->where('lifting_schedule_id', $row->lifting_schedule_id)->pluck('truck_id')->unique()
                : collect([(int) $row->truck_id]);

            $haulQuery = DB::table('hauls');
            $row->lifting_schedule_id
                ? $haulQuery->where('lifting_schedule_id', $row->lifting_schedule_id)
                : $haulQuery->where('id', $row->id);
            $haulQuery->update([
                    'truck_id' => $truck->id,
                    'updated_at' => now(),
                ]);

            if ($row->lifting_schedule_id) {
                DB::table('lifting_schedules')->where('id', $row->lifting_schedule_id)->update([
                    'truck_id' => $truck->id,
                    'updated_at' => now(),
                ]);
            }

            foreach ($oldTruckIds->push((int) $truck->id)->unique() as $truckId) {
                $this->truckAvailability->synchronizeStatus((int) $truckId);
            }

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
                'hauls.lifting_schedule_id',
                'hauls.truck_id',
                'hauls.scheduled_at',
                'hauls.quantity_liters',
                'hauls.status',
                'purchase_items.quantity_ordered_liters',
            ]);
    }

}
