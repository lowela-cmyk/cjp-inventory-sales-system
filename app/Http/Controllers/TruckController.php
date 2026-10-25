<?php

namespace App\Http\Controllers;

use App\Services\IdempotencyService;
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
        private readonly TruckAvailabilityService $availability,
        private readonly IdempotencyService $idempotency
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $this->normalizeIdentifiers($request);
        $data = $request->validate($this->rules());

        // The code is generated, never typed (Part 8 #7); a colliding concurrent create retries with the next number.
        $this->idempotency->retryOnCollision('truck_code', fn () => DB::table('trucks')->insert([
            'truck_code' => $this->availability->nextTruckCode(),
            'plate_number' => $data['plate_number'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'capacity_liters' => round((float) $data['capacity_liters'], 2),
            'status' => $data['status'],
            'created_at' => now(),
            'updated_at' => now(),
        ]), 5);

        return redirect()->route($this->routeName($request))->with('status', 'Truck added successfully.');
    }

    public function update(Request $request, int $truck): RedirectResponse
    {
        abort_unless(DB::table('trucks')->where('id', $truck)->exists(), 404);
        $this->normalizeIdentifiers($request);
        $data = $request->validate($this->rules($truck));

        if (in_array($data['status'], ['maintenance', 'inactive'], true) && $this->availability->hasActiveAssignment($truck)) {
            return back()->withErrors(['status' => 'A truck with an active lift cannot be moved to maintenance or inactive.'])->withInput();
        }

        DB::table('trucks')->where('id', $truck)->update([
            'plate_number' => $data['plate_number'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'capacity_liters' => round((float) $data['capacity_liters'], 2),
            'status' => $data['status'],
            'updated_at' => now(),
        ]);
        $this->availability->synchronizeStatus($truck);

        return redirect()->route($this->routeName($request))->with('status', 'Truck updated successfully.');
    }

    public function toggleStatus(Request $request, int $truck): RedirectResponse
    {
        $row = DB::table('trucks')->where('id', $truck)->first(['id', 'status']);
        abort_unless($row, 404);

        if ($row->status !== 'inactive' && $this->availability->hasActiveAssignment($truck)) {
            return back()->withErrors(['status' => 'A truck with an active lift cannot be deactivated.']);
        }

        DB::table('trucks')->where('id', $truck)->update([
            'status' => $row->status === 'inactive' ? 'available' : 'inactive',
            'updated_at' => now(),
        ]);

        return redirect()->route($this->routeName($request))->with('status', $row->status === 'inactive' ? 'Truck activated successfully.' : 'Truck deactivated successfully.');
    }

    private function rules(?int $truckId = null): array
    {
        return [
            'plate_number' => ['required', 'string', 'max:50', Rule::unique('trucks', 'plate_number')->ignore($truckId)],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'capacity_liters' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'status' => ['required', Rule::in(TruckAvailabilityService::MANAGED_STATUSES)],
        ];
    }

    private function normalizeIdentifiers(Request $request): void
    {
        $request->merge([
            'plate_number' => Str::upper(trim((string) $request->input('plate_number'))),
        ]);
    }

    private function routeName(Request $request): string
    {
        return str_starts_with((string) $request->route()?->getName(), 'admin.') ? 'admin.fuel-lifting.trucks' : 'dispatch.fuel-lifting.trucks';
    }
}
