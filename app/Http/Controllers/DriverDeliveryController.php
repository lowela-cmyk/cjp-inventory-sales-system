<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DriverDeliveryController extends Controller
{
    private const TASK_STATUSES = ['scheduled', 'in_transit', 'lifted', 'completed', 'cancelled'];

    public function index(Request $request, string $state = 'schedule'): View
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'task_status' => ['nullable', Rule::in(self::TASK_STATUSES)],
            'source_type' => ['nullable', Rule::in(['depot', 'garage'])],
            'destination_type' => ['nullable', Rule::in(['garage', 'customer'])],
            'fuel_type_id' => ['nullable', 'integer', Rule::exists('fuel_types', 'id')],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $search = trim((string) ($data['search'] ?? ''));
        $filters = [
            'task_status' => $data['task_status'] ?? null,
            'source_type' => $data['source_type'] ?? null,
            'destination_type' => $data['destination_type'] ?? null,
            'fuel_type_id' => $data['fuel_type_id'] ?? null,
            'date_from' => $data['date_from'] ?? null,
            'date_to' => $data['date_to'] ?? null,
        ];
        $driverId = (int) $request->user()->id;
        $rows = $this->rows($driverId, $search === '' ? null : $search, $filters);

        return view('driver.fuel-lifting', [
            'activeTab' => in_array($state, ['hauled', 'no-hauled'], true) ? 'hauled' : 'schedule',
            'driverName' => $request->user()->name,
            'search' => $search === '' ? null : $search,
            'filters' => $filters,
            'filterOptions' => $this->filterOptions($driverId),
            'driverProfile' => $this->driverProfile($driverId),
            'summaryCards' => $this->summaryCards($driverId),
            'currentAssignment' => $rows->where('group', 'schedule')->sortBy('sort_at')->first(),
            'scheduleRows' => $rows->where('group', 'schedule')->values(),
            'hauledRows' => $rows->where('group', 'hauled')->values(),
            'liftingStatusIdempotencyKey' => (string) Str::uuid(),
        ]);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function rows(int $driverId, ?string $search, array $filters)
    {
        return $this->haulRows($driverId, $search, $filters)
            ->sortByDesc('sort_at')
            ->sortByDesc('id')
            ->values();
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function haulRows(int $driverId, ?string $search, array $filters)
    {
        $allocations = DB::table('haul_allocations')
            ->leftJoin('storage_locations', 'storage_locations.id', '=', 'haul_allocations.storage_location_id')
            ->leftJoin('customers', 'customers.id', '=', 'haul_allocations.customer_id')
            ->leftJoin('sales', 'sales.id', '=', 'haul_allocations.sale_id')
            ->selectRaw("
                haul_allocations.haul_id,
                GROUP_CONCAT(COALESCE(storage_locations.name, customers.company_name, customers.name, 'Unassigned')) as destination_names,
                GROUP_CONCAT(DISTINCT sales.sale_code) as sale_codes,
                GROUP_CONCAT(DISTINCT haul_allocations.destination_type) as destination_types,
                GROUP_CONCAT(DISTINCT haul_allocations.status) as allocation_statuses
            ")
            ->groupBy('haul_allocations.haul_id');

        return DB::table('hauls')
            ->join('purchases', 'purchases.id', '=', 'hauls.purchase_id')
            ->join('depots', 'depots.id', '=', 'hauls.depot_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'hauls.fuel_type_id')
            ->join('trucks', 'trucks.id', '=', 'hauls.truck_id')
            ->leftJoinSub($allocations, 'allocations', 'allocations.haul_id', '=', 'hauls.id')
            ->where('hauls.driver_user_id', $driverId)
            ->whereNull('purchases.deleted_at')
            ->when($search, fn (Builder $query): Builder => $this->search($query, $search, [
                'hauls.haul_code',
                'purchases.purchase_code',
                'fuel_types.name',
                'depots.name',
                'trucks.truck_code',
                'trucks.plate_number',
                'hauls.status',
                'allocations.destination_names',
                'allocations.sale_codes',
            ]))
            ->when($filters['task_status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('hauls.status', $status))
            ->when($filters['source_type'] ?? null, function (Builder $query, string $sourceType): Builder {
                return $sourceType === 'depot'
                    ? $query
                    : $query->whereRaw('1 = 0');
            })
            ->when($filters['destination_type'] ?? null, fn (Builder $query, string $destinationType): Builder => $query->whereExists(function ($subQuery) use ($destinationType): void {
                $subQuery->selectRaw('1')
                    ->from('haul_allocations')
                    ->whereColumn('haul_allocations.haul_id', 'hauls.id')
                    ->where('haul_allocations.destination_type', $destinationType);
            }))
            ->when($filters['fuel_type_id'] ?? null, fn (Builder $query, mixed $fuelTypeId): Builder => $query->where('hauls.fuel_type_id', (int) $fuelTypeId))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date): Builder => $query->where('hauls.scheduled_at', '>=', CarbonImmutable::parse($date)->startOfDay()->toDateTimeString()))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date): Builder => $query->where('hauls.scheduled_at', '<=', CarbonImmutable::parse($date)->endOfDay()->toDateTimeString()))
            ->orderByDesc('hauls.scheduled_at')
            ->orderByDesc('hauls.id')
            ->get([
                'hauls.id',
                'hauls.haul_code',
                'hauls.scheduled_at',
                'hauls.hauled_at',
                'hauls.source_location',
                'hauls.quantity_liters',
                'hauls.status',
                'purchases.purchase_code',
                'depots.name as depot_name',
                'fuel_types.name as fuel_name',
                'trucks.truck_code',
                'trucks.plate_number',
                'trucks.capacity_liters',
                'allocations.destination_names',
                'allocations.sale_codes',
                'allocations.destination_types',
                'allocations.allocation_statuses',
            ])
            ->map(function (object $row): array {
                $truck = $row->truck_code
                    ? trim($row->truck_code.($row->plate_number ? ' / '.$row->plate_number : ''))
                    : 'N/A';
                $destination = $row->destination_names ?: 'N/A';

                return [
                    'id' => 'driver-haul-'.$row->id,
                    'record_id' => (int) $row->id,
                    'kind' => 'Lift',
                    'raw_status' => $row->status,
                    'allowed_driver_statuses' => DriverLiftingStatusController::STATUS_TRANSITIONS[$row->status] ?? [],
                    'group' => in_array($row->status, ['lifted', 'completed', 'cancelled'], true) ? 'hauled' : 'schedule',
                    'sort_at' => (string) ($row->hauled_at ?: $row->scheduled_at ?: $row->id),
                    'cells' => [
                        $row->haul_code,
                        $row->sale_codes ?: $row->purchase_code,
                        $row->source_location ?: $row->depot_name,
                        $this->formatDateTime($row->hauled_at ?: $row->scheduled_at),
                        $destination,
                        $truck,
                        $this->formatNumber($row->capacity_liters),
                        $this->formatNumber($row->quantity_liters),
                        $this->label($row->status),
                    ],
                    'details' => [
                        'Assignment Type' => 'Lift',
                        'Lift Reference' => $row->haul_code,
                        'Purchase Reference' => $row->purchase_code,
                        'Sale Reference' => $row->sale_codes ?: 'N/A',
                        'Source' => 'Depot',
                        'Source Name' => $row->depot_name,
                        'Source Reference' => $row->source_location ?: $row->depot_name,
                        'Destination' => $destination,
                        'Destination Type' => $row->destination_types ? $this->label(str_replace(',', ', ', $row->destination_types)) : 'N/A',
                        'Fuel Type' => $row->fuel_name,
                        'Truck-ID' => $truck,
                        'Capacity' => $this->formatLiters($row->capacity_liters),
                        'Quantity to Lift' => $this->formatLiters($row->quantity_liters),
                        'Scheduled Date' => $this->formatDateTime($row->scheduled_at),
                        'Completed Date' => $row->hauled_at ? $this->formatDateTime($row->hauled_at) : 'N/A',
                        'Allocation Status' => $row->allocation_statuses ? $this->label(str_replace(',', ', ', $row->allocation_statuses)) : 'N/A',
                        'Status' => $this->label($row->status),
                    ],
                ];
            });
    }

    private function filterOptions(int $driverId): array
    {
        $fuelIds = DB::table('hauls')
            ->where('driver_user_id', $driverId)
            ->pluck('fuel_type_id')
            ->unique()
            ->values();

        return [
            'statuses' => self::TASK_STATUSES,
            'fuelTypes' => DB::table('fuel_types')
                ->when(
                    $fuelIds->isNotEmpty(),
                    fn (Builder $query): Builder => $query->whereIn('id', $fuelIds),
                    fn (Builder $query): Builder => $query->whereRaw('1 = 0')
                )
                ->orderBy('name')
                ->get(['id', 'name']),
        ];
    }

    private function driverProfile(int $driverId): array
    {
        $row = DB::table('users')
            ->leftJoin('driver_profiles', 'driver_profiles.user_id', '=', 'users.id')
            ->where('users.id', $driverId)
            ->where('users.role', 'driver')
            ->first([
                'users.name',
                'users.email',
                'users.phone',
                'users.status as user_status',
                'driver_profiles.driver_code',
                'driver_profiles.license_number',
                'driver_profiles.emergency_contact',
                'driver_profiles.status as profile_status',
            ]);

        return [
            'Name' => $row->name ?? 'N/A',
            'Driver ID' => $row->driver_code ?? 'N/A',
            'Contact No.' => $row->phone ?? 'N/A',
            'Email' => $row->email ?? 'N/A',
            'License No.' => $row->license_number ?? 'N/A',
            'Emergency Contact' => $row->emergency_contact ?? 'N/A',
            'Account Status' => $this->label($row->user_status ?? null),
            'Profile Status' => $this->label($row->profile_status ?? null),
        ];
    }

    private function summaryCards(int $driverId): array
    {
        $hauls = DB::table('hauls')
            ->where('driver_user_id', $driverId)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
                SUM(CASE WHEN status IN ('in_transit', 'lifted') THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
            ")
            ->first();

        return [
            ['label' => 'Assigned Lifts', 'value' => number_format((int) ($hauls->total ?? 0))],
            ['label' => 'Scheduled', 'value' => number_format((int) ($hauls->scheduled ?? 0))],
            ['label' => 'Active', 'value' => number_format((int) ($hauls->active ?? 0))],
            ['label' => 'Completed', 'value' => number_format((int) ($hauls->completed ?? 0))],
        ];
    }

    /**
     * @param array<int, string> $columns
     */
    private function search(Builder $query, string $term, array $columns): Builder
    {
        return $query->where(function (Builder $query) use ($term, $columns): void {
            foreach ($columns as $column) {
                $query->orWhere($column, 'like', '%'.$term.'%');
            }
        });
    }

    private function label(?string $value): string
    {
        return $value ? ucwords(str_replace('_', ' ', $value)) : 'N/A';
    }

    private function formatDateTime(mixed $date): string
    {
        return $date ? CarbonImmutable::parse($date)->format('M d, Y h:i A') : 'N/A';
    }

    private function formatLiters(mixed $value): string
    {
        return $this->formatNumber($value).' L';
    }

    private function formatNumber(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2);
    }
}
