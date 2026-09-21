<?php

namespace App\Http\Controllers;

use App\Services\TruckAvailabilityService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TruckController extends Controller
{
    private const TRUCK_TYPES = ['hauling', 'delivery', 'mixed'];

    public function __construct(private readonly TruckAvailabilityService $availability) {}

    public function index(Request $request): View
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['available', 'assigned', 'in_use', 'maintenance', 'inactive'])],
        ]);
        $search = trim((string) ($data['search'] ?? ''));

        $activeLifts = DB::table('hauls')
            ->whereIn('status', TruckAvailabilityService::ACTIVE_HAUL_STATUSES)
            ->selectRaw("truck_id, COUNT(*) as active_lift_count, MAX(CASE WHEN status IN ('in_transit', 'lifted') THEN 1 ELSE 0 END) as has_in_use, MIN(scheduled_at) as next_schedule")
            ->groupBy('truck_id');

        $effectiveStatusSql = "CASE WHEN trucks.status IN ('maintenance', 'inactive') THEN trucks.status WHEN COALESCE(active_lifts.has_in_use, 0) > 0 THEN 'in_use' WHEN COALESCE(active_lifts.active_lift_count, 0) > 0 THEN 'assigned' ELSE 'available' END";

        $trucks = DB::table('trucks')
            ->leftJoinSub($activeLifts, 'active_lifts', 'active_lifts.truck_id', '=', 'trucks.id')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('trucks.truck_code', 'like', '%'.$search.'%')
                        ->orWhere('trucks.plate_number', 'like', '%'.$search.'%')
                        ->orWhere('trucks.name', 'like', '%'.$search.'%')
                        ->orWhere('trucks.description', 'like', '%'.$search.'%');
                });
            })
            ->when(! empty($data['status']), fn (Builder $query): Builder => $query->whereRaw($effectiveStatusSql.' = ?', [$data['status']]))
            ->orderByRaw('COALESCE(trucks.plate_number, trucks.truck_code)')
            ->select([
                'trucks.*',
                DB::raw('COALESCE(active_lifts.active_lift_count, 0) as active_lift_count'),
                'active_lifts.has_in_use',
                'active_lifts.next_schedule',
                DB::raw($effectiveStatusSql.' as effective_status'),
            ])
            ->paginate(20)
            ->withQueryString();

        $isAdmin = $request->user()->role === 'admin';

        return view('trucks.index', [
            'layout' => $isAdmin ? 'layouts.admin' : 'layouts.dispatch',
            'routePrefix' => $isAdmin ? 'admin.trucks' : 'dispatch.trucks',
            'trucks' => $trucks,
            'search' => $search,
            'statusFilter' => $data['status'] ?? null,
            'truckTypes' => self::TRUCK_TYPES,
            'managedStatuses' => TruckAvailabilityService::MANAGED_STATUSES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->normalizeIdentifiers($request);
        $data = $request->validate($this->rules());

        DB::table('trucks')->insert([
            'truck_code' => $data['truck_code'],
            'plate_number' => $data['plate_number'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'capacity_liters' => round((float) $data['capacity_liters'], 2),
            'truck_type' => $data['truck_type'],
            'status' => $data['status'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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
            'truck_code' => $data['truck_code'],
            'plate_number' => $data['plate_number'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'capacity_liters' => round((float) $data['capacity_liters'], 2),
            'truck_type' => $data['truck_type'],
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
            'truck_code' => ['required', 'string', 'max:30', Rule::unique('trucks', 'truck_code')->ignore($truckId)],
            'plate_number' => ['required', 'string', 'max:50', Rule::unique('trucks', 'plate_number')->ignore($truckId)],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'capacity_liters' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'truck_type' => ['required', Rule::in(self::TRUCK_TYPES)],
            'status' => ['required', Rule::in(TruckAvailabilityService::MANAGED_STATUSES)],
        ];
    }

    private function normalizeIdentifiers(Request $request): void
    {
        $request->merge([
            'truck_code' => Str::upper(trim((string) $request->input('truck_code'))),
            'plate_number' => Str::upper(trim((string) $request->input('plate_number'))),
        ]);
    }

    private function routeName(Request $request): string
    {
        return str_starts_with((string) $request->route()?->getName(), 'admin.') ? 'admin.trucks' : 'dispatch.trucks';
    }
}
