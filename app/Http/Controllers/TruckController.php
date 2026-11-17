<?php

namespace App\Http\Controllers;

use App\Services\TruckAvailabilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Truck writes. The fleet list itself is the Trucks tab of Fuel Lifting (DispatchDeliveryController).
 */
class TruckController extends Controller
{
    public function __construct(
        private readonly TruckAvailabilityService $availability
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $this->normalizeIdentifiers($request);
        $data = $request->validate($this->rules(), $this->messages());

        // Part 23 #8: the Truck ID is typed by hand (TRUCK-###); the unique rule and index reject a duplicate.
        DB::table('trucks')->insert([
            'truck_code' => $data['truck_code'],
            'plate_number' => $data['plate_number'],
            'capacity_liters' => round((float) $data['capacity_liters'], 2),
            'status' => $data['status'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->route($this->routeName($request))->with('status', 'Truck added successfully.');
    }

    public function update(Request $request, int $truck): RedirectResponse
    {
        $current = DB::table('trucks')->where('id', $truck)->first(['id', 'truck_code', 'status']);
        abort_unless($current !== null, 404);
        $this->normalizeIdentifiers($request);
        // A legacy code (e.g. the plate) may be kept as it is; a new code must follow TRUCK-###.
        if ($request->input('truck_code') === '' || $request->input('truck_code') === null) {
            $request->merge(['truck_code' => $current->truck_code]);
        }
        $data = $request->validate($this->rules($truck, $request->input('truck_code') === $current->truck_code), $this->messages());

        if ($data['status'] === 'maintenance' && $this->availability->hasActiveAssignment($truck)) {
            return back()->withErrors(['status' => 'A truck with an active lift cannot be moved to maintenance.'])->withInput();
        }

        DB::table('trucks')->where('id', $truck)->update([
            'truck_code' => $data['truck_code'],
            'plate_number' => $data['plate_number'],
            'capacity_liters' => round((float) $data['capacity_liters'], 2),
            'status' => $data['status'],
            'updated_at' => now(),
        ]);
        $this->availability->synchronizeStatus($truck);

        return redirect()->route($this->routeName($request))->with('status', 'Truck updated successfully.');
    }

    /** Part 23 #8: Inactive is retired (use Maintenance); this only brings a legacy inactive truck back. */
    public function toggleStatus(Request $request, int $truck): RedirectResponse
    {
        $row = DB::table('trucks')->where('id', $truck)->first(['id', 'status']);
        abort_unless($row !== null, 404);

        if ($row->status !== 'inactive') {
            return back()->withErrors(['status' => 'Trucks are no longer deactivated. Set the Operational Status to Maintenance instead.']);
        }

        DB::table('trucks')->where('id', $truck)->update(['status' => 'available', 'updated_at' => now()]);

        return redirect()->route($this->routeName($request))->with('status', 'Truck activated successfully.');
    }

    private function rules(?int $truckId = null, bool $keepsLegacyCode = false): array
    {
        return [
            'truck_code' => array_filter([
                'required', 'string', 'max:30',
                $keepsLegacyCode ? null : 'regex:'.TruckAvailabilityService::CODE_PATTERN,
                Rule::unique('trucks', 'truck_code')->ignore($truckId),
            ]),
            'plate_number' => ['required', 'string', 'max:50', Rule::unique('trucks', 'plate_number')->ignore($truckId)],
            'capacity_liters' => ['required', 'numeric', 'gt:0', 'max:'.TruckAvailabilityService::MAX_CAPACITY_LITERS],
            'status' => ['required', Rule::in(TruckAvailabilityService::MANAGED_STATUSES)],
        ];
    }

    private function messages(): array
    {
        return [
            'truck_code.required' => 'Enter the Truck ID (e.g. TRUCK-001).',
            'truck_code.regex' => 'The Truck ID must look like TRUCK-001.',
            'truck_code.unique' => 'This Truck ID is already used by another truck.',
            'capacity_liters.max' => 'A truck holds at most '.number_format(TruckAvailabilityService::MAX_CAPACITY_LITERS).' L.',
        ];
    }

    private function normalizeIdentifiers(Request $request): void
    {
        $request->merge([
            'plate_number' => Str::upper(trim((string) $request->input('plate_number'))),
            'truck_code' => Str::upper(trim((string) $request->input('truck_code'))),
        ]);
    }

    private function routeName(Request $request): string
    {
        return str_starts_with((string) $request->route()?->getName(), 'admin.') ? 'admin.fuel-lifting.trucks' : 'dispatch.fuel-lifting.trucks';
    }
}
