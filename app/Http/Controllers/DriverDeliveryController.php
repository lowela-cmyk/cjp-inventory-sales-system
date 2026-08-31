<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DriverDeliveryController extends Controller
{
    public function index(Request $request, string $state = 'schedule'): View
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $search = trim((string) ($data['search'] ?? ''));
        $driverId = (int) $request->user()->id;
        $rows = $this->rows($driverId, $search === '' ? null : $search);

        return view('driver.fuel-lifting', [
            'activeTab' => in_array($state, ['hauled', 'no-hauled'], true) ? 'hauled' : 'schedule',
            'driverName' => $request->user()->name,
            'search' => $search === '' ? null : $search,
            'driverProfile' => $this->driverProfile($driverId),
            'summaryCards' => $this->summaryCards($driverId),
            'currentAssignment' => $rows->where('group', 'schedule')->sortBy('sort_at')->first(),
            'scheduleRows' => $rows->where('group', 'schedule')->values(),
            'hauledRows' => $rows->where('group', 'hauled')->values(),
        ]);
    }

    private function rows(int $driverId, ?string $search)
    {
        return $this->deliveryRows($driverId, $search)
            ->merge($this->haulRows($driverId, $search))
            ->sortByDesc('sort_at')
            ->sortByDesc('id')
            ->values();
    }

    private function deliveryRows(int $driverId, ?string $search)
    {
        return DB::table('deliveries')
            ->join('customers', 'customers.id', '=', 'deliveries.customer_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'deliveries.fuel_type_id')
            ->leftJoin('sales', 'sales.id', '=', 'deliveries.sale_id')
            ->leftJoin('trucks', 'trucks.id', '=', 'deliveries.truck_id')
            ->leftJoin('stock_outs', 'stock_outs.delivery_id', '=', 'deliveries.id')
            ->leftJoin('haul_allocations', 'haul_allocations.id', '=', 'deliveries.haul_allocation_id')
            ->leftJoin('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
            ->leftJoin('depots', 'depots.id', '=', 'deliveries.depot_id')
            ->leftJoin('storage_locations', 'storage_locations.id', '=', 'deliveries.storage_location_id')
            ->where('deliveries.driver_user_id', $driverId)
            ->when($search, fn (Builder $query): Builder => $this->search($query, $search, [
                'deliveries.delivery_code',
                'sales.sale_code',
                'hauls.haul_code',
                'customers.company_name',
                'customers.location',
                'fuel_types.name',
                'depots.name',
                'storage_locations.name',
                'trucks.truck_code',
                'trucks.plate_number',
                'deliveries.status',
            ]))
            ->orderByDesc('deliveries.scheduled_at')
            ->orderByDesc('deliveries.id')
            ->get([
                'deliveries.id',
                'deliveries.delivery_code',
                'deliveries.scheduled_at',
                'deliveries.delivered_at',
                'deliveries.scheduled_quantity_liters',
                'deliveries.actual_quantity_liters',
                'deliveries.status',
                'deliveries.source_type',
                'sales.sale_code',
                'customers.company_name',
                'customers.location',
                'fuel_types.name as fuel_name',
                'trucks.truck_code',
                'trucks.plate_number',
                'trucks.capacity_liters',
                'stock_outs.stock_out_code',
                'haul_allocations.id as allocation_id',
                'haul_allocations.status as allocation_status',
                'hauls.haul_code',
                'hauls.status as lifting_status',
                'hauls.scheduled_at as lifting_scheduled_at',
                'hauls.hauled_at',
                'depots.name as depot_name',
                'storage_locations.name as garage_name',
            ])
            ->map(function (object $row): array {
                $quantity = (float) ($row->actual_quantity_liters ?? $row->scheduled_quantity_liters ?? 0);
                $sourceRef = $row->source_type === 'garage'
                    ? ($row->stock_out_code ?: 'Garage Release')
                    : ($row->haul_code ?: 'Allocation #'.($row->allocation_id ?: 'N/A'));
                $sourceName = $row->source_type === 'garage'
                    ? ($row->garage_name ?: 'Garage')
                    : ($row->depot_name ?: 'Depot');
                $truck = $row->truck_code
                    ? trim($row->truck_code.($row->plate_number ? ' / '.$row->plate_number : ''))
                    : 'N/A';

                return [
                    'id' => 'driver-delivery-'.$row->id,
                    'record_id' => (int) $row->id,
                    'kind' => 'Delivery',
                    'raw_status' => $row->status,
                    'group' => in_array($row->status, ['delivered', 'cancelled'], true) ? 'hauled' : 'schedule',
                    'sort_at' => (string) ($row->delivered_at ?: $row->scheduled_at ?: $row->id),
                    'cells' => [
                        $row->delivery_code,
                        $row->sale_code ?: 'N/A',
                        $sourceRef,
                        $this->formatDateTime($row->delivered_at ?: $row->scheduled_at),
                        $row->location ?: $row->company_name,
                        $row->truck_code ?: 'N/A',
                        $this->formatNumber($row->capacity_liters),
                        $this->formatNumber($quantity),
                        $this->label($row->status),
                    ],
                    'details' => [
                        'Delivery Reference' => $row->delivery_code,
                        'Lift Reference' => $row->haul_code ?: 'N/A',
                        'Sale Reference' => $row->sale_code ?: 'N/A',
                        'Source' => $this->label($row->source_type),
                        'Source Name' => $sourceName,
                        'Source Reference' => $sourceRef,
                        'Customer' => $row->company_name,
                        'Location' => $row->location ?: 'N/A',
                        'Fuel Type' => $row->fuel_name,
                        'Truck-ID' => $truck,
                        'Capacity' => $this->formatLiters($row->capacity_liters),
                        'Quantity to Lift' => $this->formatLiters($row->scheduled_quantity_liters),
                        'Actual Quantity' => $row->actual_quantity_liters ? $this->formatLiters($row->actual_quantity_liters) : 'N/A',
                        'Scheduled Date' => $this->formatDateTime($row->scheduled_at),
                        'Delivered Date' => $row->delivered_at ? $this->formatDateTime($row->delivered_at) : 'N/A',
                        'Lifting Scheduled Date' => $row->lifting_scheduled_at ? $this->formatDateTime($row->lifting_scheduled_at) : 'N/A',
                        'Lifting Completed Date' => $row->hauled_at ? $this->formatDateTime($row->hauled_at) : 'N/A',
                        'Lifting Status' => $this->label($row->lifting_status),
                        'Allocation Status' => $this->label($row->allocation_status),
                        'Status' => $this->label($row->status),
                    ],
                ];
            });
    }

    private function haulRows(int $driverId, ?string $search)
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
                    'group' => in_array($row->status, ['completed', 'cancelled'], true) ? 'hauled' : 'schedule',
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
        $deliveries = DB::table('deliveries')
            ->where('driver_user_id', $driverId)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
                SUM(CASE WHEN status IN ('in_transit', 'incomplete') THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as completed
            ")
            ->first();
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
            ['label' => 'Assigned', 'value' => number_format((int) ($deliveries->total ?? 0) + (int) ($hauls->total ?? 0))],
            ['label' => 'Scheduled', 'value' => number_format((int) ($deliveries->scheduled ?? 0) + (int) ($hauls->scheduled ?? 0))],
            ['label' => 'Active', 'value' => number_format((int) ($deliveries->active ?? 0) + (int) ($hauls->active ?? 0))],
            ['label' => 'Completed', 'value' => number_format((int) ($deliveries->completed ?? 0) + (int) ($hauls->completed ?? 0))],
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
