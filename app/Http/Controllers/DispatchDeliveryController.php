<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DispatchDeliveryController extends Controller
{
    private const ACTIVE_HAUL_STATUSES = ['scheduled', 'in_transit', 'lifted'];

    public function index(Request $request, string $state = 'schedule'): View
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['scheduled', 'in_transit', 'lifted', 'completed', 'cancelled'])],
            'fuel_type_id' => ['nullable', 'integer', Rule::exists('fuel_types', 'id')],
            'driver_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn (Builder $query): Builder => $query->where('role', 'driver'))],
            'truck_id' => ['nullable', 'integer', Rule::exists('trucks', 'id')],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $search = trim((string) ($data['search'] ?? ''));
        $filters = [
            'status' => $data['status'] ?? null,
            'fuel_type_id' => $data['fuel_type_id'] ?? null,
            'driver_user_id' => $data['driver_user_id'] ?? null,
            'truck_id' => $data['truck_id'] ?? null,
            'date_from' => $data['date_from'] ?? null,
            'date_to' => $data['date_to'] ?? null,
        ];
        $rows = $this->haulRows($search === '' ? null : $search, $filters);

        return view('dispatch.fuel-lifting', [
            'activeTab' => $state === 'hauled' ? 'hauled' : 'schedule',
            'search' => $search === '' ? null : $search,
            'scheduledRows' => $rows->whereIn('raw_status', self::ACTIVE_HAUL_STATUSES)->values(),
            'deliveredRows' => $rows->whereIn('raw_status', ['completed', 'cancelled'])->values(),
            'filters' => $filters,
            'filterOptions' => $this->haulFilterOptions(),
            'summaryCards' => $this->haulSummary($filters, $search === '' ? null : $search),
            'drivers' => $this->driverOptions(),
            'trucks' => $this->haulTruckOptions(),
            'purchaseItems' => $this->availablePurchaseItemOptions(),
            'createIdempotencyKey' => (string) Str::uuid(),
            'statusIdempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'purchase_item_id' => ['required', 'integer', Rule::exists('purchase_items', 'id')],
            'driver_user_id' => ['required', 'integer', Rule::exists('users', 'id')->where(fn (Builder $query): Builder => $query->where('role', 'driver'))],
            'truck_id' => ['required', 'integer', Rule::exists('trucks', 'id')],
            'dr_number' => ['nullable', 'string', 'max:100'],
            'scheduled_at' => ['required', 'date'],
            'source_location' => ['nullable', 'string', 'max:255'],
            'quantity_liters' => ['required', 'numeric', 'gt:0', 'max:999999999999.99'],
        ]);
        $sessionKey = 'hauls.created.'.((string) $data['idempotency_key']);

        if ($request->session()->has($sessionKey)) {
            return redirect()
                ->route('dispatch.fuel-lifting')
                ->with('status', 'Lift assignment was already submitted.');
        }

        $result = DB::transaction(function () use ($data): ?string {
            $purchaseItem = $this->purchaseItemForAssignment((int) $data['purchase_item_id']);

            if (! $purchaseItem) {
                return 'The selected purchase is not eligible for lifting.';
            }

            $driver = $this->driverForAssignment((int) $data['driver_user_id']);

            if (! $driver) {
                return 'The selected driver is not eligible for lifting.';
            }

            $truck = $this->truckForAssignment((int) $data['truck_id']);

            if (! $truck) {
                return 'The selected truck is not eligible for lifting.';
            }

            $quantity = round((float) $data['quantity_liters'], 2);
            $scheduledAt = CarbonImmutable::parse($data['scheduled_at']);

            if ($quantity <= 0) {
                return 'Lift quantity must be greater than zero.';
            }

            if ($quantity > round((float) $truck->capacity_liters, 2)) {
                return 'Lift quantity cannot exceed the selected truck capacity.';
            }

            $remaining = $this->remainingAssignablePurchaseItemQuantity((int) $purchaseItem->id, (float) $purchaseItem->quantity_ordered_liters);

            if ($quantity > $remaining) {
                return 'Lift quantity cannot exceed the remaining purchase quantity.';
            }

            if (! $this->truckIsAvailable((int) $truck->id, $scheduledAt)) {
                return 'The selected truck already has an active trip at this schedule.';
            }

            if ($this->duplicateHaulExists($purchaseItem, (int) $driver->id, (int) $truck->id, $quantity, $scheduledAt)) {
                return 'This lift assignment has already been scheduled.';
            }

            DB::table('hauls')->insert([
                'haul_code' => $this->nextCode('hauls', 'haul_code', 'LFT'),
                'purchase_id' => $purchaseItem->purchase_id,
                'purchase_item_id' => $purchaseItem->id,
                'depot_id' => $purchaseItem->depot_id,
                'fuel_type_id' => $purchaseItem->fuel_type_id,
                'truck_id' => $truck->id,
                'driver_user_id' => $driver->id,
                'dr_number' => $data['dr_number'] ?? null,
                'scheduled_at' => $scheduledAt->toDateTimeString(),
                'source_location' => $data['source_location'] ?? null,
                'quantity_liters' => $quantity,
                'status' => 'scheduled',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return null;
        });

        if ($result) {
            return back()->withErrors(['lift' => $result])->withInput();
        }

        $request->session()->put($sessionKey, true);

        return redirect()
            ->route('dispatch.fuel-lifting')
            ->with('status', 'Lift assignment scheduled successfully.');
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function haulRows(?string $search, array $filters)
    {
        return DB::table('hauls')
            ->join('purchases', 'purchases.id', '=', 'hauls.purchase_id')
            ->join('depots', 'depots.id', '=', 'hauls.depot_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'hauls.fuel_type_id')
            ->join('trucks', 'trucks.id', '=', 'hauls.truck_id')
            ->join('users as drivers', 'drivers.id', '=', 'hauls.driver_user_id')
            ->leftJoin('driver_profiles', 'driver_profiles.user_id', '=', 'drivers.id')
            ->whereNull('purchases.deleted_at')
            ->when($search, fn (Builder $query): Builder => $this->search($query, $search, [
                'hauls.haul_code',
                'purchases.purchase_code',
                'hauls.dr_number',
                'hauls.source_location',
                'depots.name',
                'fuel_types.name',
                'trucks.truck_code',
                'trucks.plate_number',
                'drivers.name',
                'drivers.phone',
                'hauls.status',
            ]))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('hauls.status', $status))
            ->when($filters['fuel_type_id'] ?? null, fn (Builder $query, mixed $fuelTypeId): Builder => $query->where('hauls.fuel_type_id', (int) $fuelTypeId))
            ->when($filters['driver_user_id'] ?? null, fn (Builder $query, mixed $driverUserId): Builder => $query->where('hauls.driver_user_id', (int) $driverUserId))
            ->when($filters['truck_id'] ?? null, fn (Builder $query, mixed $truckId): Builder => $query->where('hauls.truck_id', (int) $truckId))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date): Builder => $query->where('hauls.scheduled_at', '>=', CarbonImmutable::parse($date)->startOfDay()->toDateTimeString()))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date): Builder => $query->where('hauls.scheduled_at', '<=', CarbonImmutable::parse($date)->endOfDay()->toDateTimeString()))
            ->orderByDesc('hauls.scheduled_at')
            ->orderByDesc('hauls.id')
            ->get([
                'hauls.id',
                'hauls.haul_code',
                'hauls.dr_number',
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
                'drivers.name as driver_name',
                'drivers.phone as driver_phone',
                'driver_profiles.driver_code',
            ])
            ->map(function (object $row): array {
                $truck = trim($row->truck_code.($row->plate_number ? ' / '.$row->plate_number : ''));

                return [
                    'id' => 'dispatch-haul-'.$row->id,
                    'haul_id' => (int) $row->id,
                    'raw_status' => $row->status,
                    'cells' => [
                        $row->haul_code,
                        $row->purchase_code,
                        $row->dr_number ?: 'N/A',
                        $this->formatDateTime($row->hauled_at ?: $row->scheduled_at),
                        $row->source_location ?: $row->depot_name,
                        $row->driver_name,
                        $row->driver_phone ?: 'N/A',
                        $truck,
                        $this->formatNumber($row->capacity_liters),
                        $this->formatLiters($row->quantity_liters),
                    ],
                    'status' => $this->label($row->status),
                    'details' => [
                        'Lift Reference' => $row->haul_code,
                        'Purchase Reference' => $row->purchase_code,
                        'DR Number' => $row->dr_number ?: 'N/A',
                        'Fuel Type' => $row->fuel_name,
                        'Source' => $row->source_location ?: $row->depot_name,
                        'Driver' => $row->driver_name,
                        "Driver's Contact" => $row->driver_phone ?: 'N/A',
                        'Driver Code' => $row->driver_code ?: 'N/A',
                        'Truck-ID' => $truck,
                        'Capacity' => $this->formatLiters($row->capacity_liters),
                        'Quantity Lift' => $this->formatLiters($row->quantity_liters),
                        'Scheduled Date' => $this->formatDateTime($row->scheduled_at),
                        'Hauled Date' => $row->hauled_at ? $this->formatDateTime($row->hauled_at) : 'N/A',
                        'Status' => $this->label($row->status),
                    ],
                    'allowed_statuses' => DispatchLiftingStatusController::STATUS_TRANSITIONS[$row->status] ?? [],
                ];
            });
    }

    private function haulFilterOptions(): array
    {
        return [
            'statuses' => ['scheduled', 'in_transit', 'lifted', 'completed', 'cancelled'],
            'fuelTypes' => DB::table('fuel_types')->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'drivers' => $this->driverOptions(),
            'trucks' => $this->haulTruckOptions(),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function haulSummary(array $filters, ?string $search): array
    {
        $rows = $this->haulRows($search, $filters);

        return [
            ['label' => 'Assigned Lifts', 'value' => number_format($rows->count())],
            ['label' => 'Scheduled', 'value' => number_format($rows->where('raw_status', 'scheduled')->count())],
            ['label' => 'In Progress', 'value' => number_format($rows->whereIn('raw_status', ['in_transit', 'lifted'])->count())],
            ['label' => 'Completed', 'value' => number_format($rows->where('raw_status', 'completed')->count())],
        ];
    }

    private function haulTruckOptions()
    {
        return DB::table('trucks')
            ->whereIn('truck_type', ['hauling', 'mixed'])
            ->orderBy('truck_code')
            ->get(['id', 'truck_code', 'plate_number', 'capacity_liters']);
    }

    private function driverOptions()
    {
        return DB::table('users')
            ->leftJoin('driver_profiles', 'driver_profiles.user_id', '=', 'users.id')
            ->where('users.role', 'driver')
            ->where('users.status', 'active')
            ->where(function (Builder $query): void {
                $query->whereNull('driver_profiles.status')
                    ->orWhere('driver_profiles.status', '!=', 'inactive');
            })
            ->orderBy('users.name')
            ->get(['users.id', 'users.name', 'users.phone', 'driver_profiles.driver_code']);
    }

    private function availablePurchaseItemOptions()
    {
        $assignedLifts = DB::table('hauls')
            ->where('status', '!=', 'cancelled')
            ->selectRaw('purchase_item_id, COALESCE(SUM(quantity_liters), 0) as assigned_liters')
            ->groupBy('purchase_item_id');

        return DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->join('depots', 'depots.id', '=', 'purchases.depot_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'purchase_items.fuel_type_id')
            ->leftJoinSub($assignedLifts, 'assigned_lifts', 'assigned_lifts.purchase_item_id', '=', 'purchase_items.id')
            ->whereNull('purchases.deleted_at')
            ->where('purchases.status', '!=', 'cancelled')
            ->whereRaw('purchase_items.quantity_ordered_liters > COALESCE(assigned_lifts.assigned_liters, 0)')
            ->orderBy('purchases.purchase_code')
            ->get([
                'purchase_items.id',
                'purchases.purchase_code',
                'depots.name as depot_name',
                'fuel_types.name as fuel_name',
                'purchase_items.quantity_ordered_liters',
                DB::raw('COALESCE(assigned_lifts.assigned_liters, 0) as assigned_liters'),
            ])
            ->map(function (object $row): object {
                $row->remaining_liters = round((float) $row->quantity_ordered_liters - (float) $row->assigned_liters, 2);
                $row->label = $row->purchase_code.' / '.$row->fuel_name.' / '.$row->depot_name.' / '.$this->formatLiters($row->remaining_liters).' remaining';

                return $row;
            });
    }

    private function purchaseItemForAssignment(int $purchaseItemId): ?object
    {
        return DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->join('depots', 'depots.id', '=', 'purchases.depot_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'purchase_items.fuel_type_id')
            ->where('purchase_items.id', $purchaseItemId)
            ->whereNull('purchases.deleted_at')
            ->where('purchases.status', '!=', 'cancelled')
            ->where('depots.status', 'active')
            ->where('fuel_types.status', 'active')
            ->lockForUpdate()
            ->first([
                'purchase_items.id',
                'purchase_items.purchase_id',
                'purchase_items.fuel_type_id',
                'purchase_items.quantity_ordered_liters',
                'purchases.depot_id',
            ]);
    }

    private function driverForAssignment(int $driverUserId): ?object
    {
        return DB::table('users')
            ->leftJoin('driver_profiles', 'driver_profiles.user_id', '=', 'users.id')
            ->where('users.id', $driverUserId)
            ->where('users.role', 'driver')
            ->where('users.status', 'active')
            ->where(function (Builder $query): void {
                $query->whereNull('driver_profiles.status')
                    ->orWhere('driver_profiles.status', '!=', 'inactive');
            })
            ->lockForUpdate()
            ->first(['users.id']);
    }

    private function truckForAssignment(int $truckId): ?object
    {
        return DB::table('trucks')
            ->where('id', $truckId)
            ->whereIn('truck_type', ['hauling', 'mixed'])
            ->whereNotIn('status', ['maintenance', 'inactive'])
            ->lockForUpdate()
            ->first(['id', 'capacity_liters']);
    }

    private function remainingAssignablePurchaseItemQuantity(int $purchaseItemId, float $orderedLiters): float
    {
        DB::table('hauls')
            ->where('purchase_item_id', $purchaseItemId)
            ->lockForUpdate()
            ->get(['id']);

        $assignedLiters = (float) DB::table('hauls')
            ->where('purchase_item_id', $purchaseItemId)
            ->where('status', '!=', 'cancelled')
            ->selectRaw('COALESCE(SUM(quantity_liters), 0) as assigned_liters')
            ->value('assigned_liters');

        return round(max(0, $orderedLiters - $assignedLiters), 2);
    }

    private function truckIsAvailable(int $truckId, CarbonImmutable $scheduledAt): bool
    {
        return ! DB::table('hauls')
            ->where('truck_id', $truckId)
            ->whereIn('status', self::ACTIVE_HAUL_STATUSES)
            ->where('scheduled_at', $scheduledAt->toDateTimeString())
            ->lockForUpdate()
            ->exists();
    }

    private function duplicateHaulExists(object $purchaseItem, int $driverId, int $truckId, float $quantity, CarbonImmutable $scheduledAt): bool
    {
        return DB::table('hauls')
            ->where('purchase_item_id', $purchaseItem->id)
            ->where('driver_user_id', $driverId)
            ->where('truck_id', $truckId)
            ->where('quantity_liters', $quantity)
            ->where('scheduled_at', $scheduledAt->toDateTimeString())
            ->where('status', '!=', 'cancelled')
            ->lockForUpdate()
            ->first(['id']) !== null;
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

    private function nextCode(string $table, string $column, string $prefix): string
    {
        do {
            $code = $prefix.'-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        } while (DB::table($table)->where($column, $code)->exists());

        return $code;
    }
}
