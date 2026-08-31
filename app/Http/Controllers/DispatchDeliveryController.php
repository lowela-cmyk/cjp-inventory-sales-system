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
    private const ACTIVE_DELIVERY_STATUSES = ['scheduled', 'in_transit', 'incomplete'];
    private const ACTIVE_HAUL_STATUSES = ['scheduled', 'in_transit', 'lifted'];
    private const ASSIGNABLE_DELIVERY_STATUSES = ['scheduled', 'incomplete'];
    private const DELIVERY_STATUSES = ['scheduled', 'in_transit', 'delivered', 'cancelled', 'incomplete'];
    private const ELIGIBLE_DIRECT_HAUL_STATUSES = ['completed'];
    private const ELIGIBLE_SALE_STATUSES = ['confirmed', 'partially_paid', 'paid', 'unpaid'];
    private const STATUS_TRANSITIONS = [
        'scheduled' => ['in_transit', 'cancelled'],
        'in_transit' => ['delivered', 'incomplete', 'cancelled'],
        'incomplete' => ['in_transit', 'cancelled'],
        'delivered' => [],
        'cancelled' => [],
    ];

    public function index(Request $request, string $state = 'schedule'): View
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(self::DELIVERY_STATUSES)],
            'source_type' => ['nullable', Rule::in(['garage', 'depot'])],
            'fuel_type_id' => ['nullable', 'integer', Rule::exists('fuel_types', 'id')],
            'driver_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn (Builder $query): Builder => $query->where('role', 'driver'))],
            'truck_id' => ['nullable', 'integer', Rule::exists('trucks', 'id')],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $search = trim((string) ($data['search'] ?? ''));
        $filters = [
            'status' => $data['status'] ?? null,
            'source_type' => $data['source_type'] ?? null,
            'fuel_type_id' => $data['fuel_type_id'] ?? null,
            'driver_user_id' => $data['driver_user_id'] ?? null,
            'truck_id' => $data['truck_id'] ?? null,
            'date_from' => $data['date_from'] ?? null,
            'date_to' => $data['date_to'] ?? null,
        ];
        $rows = $this->deliveryRows($search === '' ? null : $search, $filters);

        return view('dispatch.fuel-lifting', [
            'activeTab' => $state === 'hauled' ? 'hauled' : 'schedule',
            'search' => $search === '' ? null : $search,
            'scheduledRows' => $rows->whereIn('raw_status', self::ACTIVE_DELIVERY_STATUSES)->values(),
            'deliveredRows' => $rows->whereIn('raw_status', ['delivered', 'cancelled'])->values(),
            'filters' => $filters,
            'filterOptions' => $this->deliveryFilterOptions(),
            'deliverySummary' => $this->deliverySummary($filters, $search === '' ? null : $search),
            'garageStockOuts' => $this->garageStockOutOptions(),
            'directAllocations' => $this->directAllocationOptions(),
            'drivers' => $this->driverOptions(),
            'trucks' => $this->truckOptions(),
            'idempotencyKey' => (string) Str::uuid(),
            'assignmentIdempotencyKey' => (string) Str::uuid(),
            'statusIdempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'source_type' => ['required', Rule::in(['garage', 'depot'])],
            'stock_out_id' => ['required_if:source_type,garage', 'nullable', 'integer', Rule::exists('stock_outs', 'id')],
            'haul_allocation_id' => ['required_if:source_type,depot', 'nullable', 'integer', Rule::exists('haul_allocations', 'id')],
            'driver_user_id' => ['required', 'integer', Rule::exists('users', 'id')->where(fn (Builder $query): Builder => $query->where('role', 'driver')->where('status', 'active'))],
            'truck_id' => ['required', 'integer', Rule::exists('trucks', 'id')->where(fn (Builder $query): Builder => $query->whereIn('truck_type', ['delivery', 'mixed'])->where('status', 'available'))],
            'scheduled_at' => ['required', 'date'],
            'quantity_liters' => ['required', 'numeric', 'gt:0', 'max:999999999999.99'],
        ]);
        $sessionKey = 'deliveries.scheduled.'.((string) $data['idempotency_key']);

        if ($request->session()->has($sessionKey)) {
            return redirect()
                ->route($this->redirectRoute($request))
                ->with('status', 'Delivery schedule was already submitted.');
        }

        $result = DB::transaction(function () use ($data): ?string {
            $scheduledAt = CarbonImmutable::parse($data['scheduled_at']);

            if ($scheduledAt->lt(now()->startOfMinute())) {
                return 'Delivery schedule cannot be in the past.';
            }

            $quantity = round((float) $data['quantity_liters'], 2);
            $truck = $this->truckForUpdate((int) $data['truck_id']);

            if (! $truck) {
                return 'The selected truck is not available for delivery scheduling.';
            }

            if ($quantity > round((float) $truck->capacity_liters, 2)) {
                return 'Delivery quantity cannot exceed the selected truck capacity.';
            }

            if (! $this->driverIsAvailable((int) $data['driver_user_id'], $scheduledAt)) {
                return 'The selected driver already has an active delivery at this schedule.';
            }

            if (! $this->truckIsAvailable((int) $data['truck_id'], $scheduledAt)) {
                return 'The selected truck already has an active delivery at this schedule.';
            }

            if ($data['source_type'] === 'garage') {
                return $this->scheduleGarageDelivery($data, $scheduledAt, $quantity);
            }

            return $this->scheduleDepotDelivery($data, $scheduledAt, $quantity);
        });

        if ($result) {
            return back()->withErrors(['delivery' => $result])->withInput();
        }

        $request->session()->put($sessionKey, true);

        return redirect()
            ->route($this->redirectRoute($request))
            ->with('status', 'Delivery scheduled successfully.');
    }

    public function updateStatus(Request $request, int $delivery): RedirectResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'status' => ['required', Rule::in(self::DELIVERY_STATUSES)],
        ]);
        $sessionKey = 'deliveries.status.'.$delivery.'.'.((string) $data['idempotency_key']);

        if ($request->session()->has($sessionKey)) {
            return redirect()
                ->route($this->redirectRoute($request))
                ->with('status', 'Delivery status update was already submitted.');
        }

        $result = DB::transaction(function () use ($delivery, $data): ?string {
            $row = $this->deliveryForUpdate($delivery);

            if (! $row) {
                return 'The selected delivery does not exist or is missing required linked records.';
            }

            $nextStatus = (string) $data['status'];
            $allowed = self::STATUS_TRANSITIONS[$row->status] ?? [];

            if (! in_array($nextStatus, $allowed, true)) {
                return 'The selected status transition is not allowed for this delivery.';
            }

            if ($nextStatus === 'in_transit') {
                $assignmentError = $this->validateDispatchAssignment($row);

                if ($assignmentError) {
                    return $assignmentError;
                }
            }

            $updates = [
                'status' => $nextStatus,
                'updated_at' => now(),
            ];

            if ($nextStatus === 'delivered') {
                $updates['delivered_at'] = now();
                $updates['actual_quantity_liters'] = round((float) ($row->actual_quantity_liters ?? $row->scheduled_quantity_liters), 2);
            }

            DB::table('deliveries')
                ->where('id', $row->id)
                ->update($updates);

            if ($nextStatus === 'delivered' && $row->source_type === 'depot') {
                $this->markDirectAllocationDeliveredWhenComplete($row);
            }

            return null;
        });

        if ($result) {
            return back()->withErrors(['delivery' => $result])->withInput();
        }

        $request->session()->put($sessionKey, true);

        return redirect()
            ->route($this->redirectRoute($request))
            ->with('status', 'Delivery status updated successfully.');
    }

    public function updateAssignment(Request $request, int $delivery): RedirectResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'driver_user_id' => ['required', 'integer'],
            'truck_id' => ['required', 'integer'],
        ]);
        $sessionKey = 'deliveries.assignment.'.$delivery.'.'.((string) $data['idempotency_key']);

        if ($request->session()->has($sessionKey)) {
            return redirect()
                ->route($this->redirectRoute($request))
                ->with('status', 'Delivery assignment was already submitted.');
        }

        $result = DB::transaction(function () use ($delivery, $data): ?string {
            $row = $this->deliveryForUpdate($delivery);

            if (! $row) {
                return 'The selected delivery does not exist or is missing required linked records.';
            }

            if (! in_array($row->status, self::ASSIGNABLE_DELIVERY_STATUSES, true)) {
                return 'This delivery status does not allow driver or truck assignment changes.';
            }

            $driver = $this->driverForAssignment((int) $data['driver_user_id']);

            if (! $driver) {
                return 'The selected driver is not eligible for dispatch assignment.';
            }

            if (! $row->truck_id) {
                return 'A valid assigned truck is required before assigning a driver.';
            }

            if ((int) $data['truck_id'] !== (int) $row->truck_id) {
                return 'The submitted truck does not match this delivery assignment.';
            }

            $truck = $this->truckForAssignment((int) $row->truck_id, true);

            if (! $truck) {
                return 'The selected truck is not eligible for dispatch assignment.';
            }

            $quantity = round((float) ($row->actual_quantity_liters ?? $row->scheduled_quantity_liters ?? 0), 2);

            if ($quantity <= 0) {
                return 'Delivery quantity is required before assigning dispatch resources.';
            }

            if ($quantity > round((float) $truck->capacity_liters, 2)) {
                return 'Delivery quantity cannot exceed the selected truck capacity.';
            }

            $scheduledAt = $row->scheduled_at ? CarbonImmutable::parse($row->scheduled_at) : null;

            if (! $scheduledAt) {
                return 'Delivery schedule is required before assigning dispatch resources.';
            }

            if (! $this->driverIsAvailable((int) $driver->id, $scheduledAt, $row->id)) {
                return 'The selected driver already has an active delivery at this schedule.';
            }

            if (! $this->truckIsAvailable((int) $truck->id, $scheduledAt, $row->id)) {
                return 'The selected truck already has an active delivery at this schedule.';
            }

            DB::table('deliveries')
                ->where('id', $row->id)
                ->update([
                    'driver_user_id' => $driver->id,
                    'updated_at' => now(),
                ]);

            return null;
        });

        if ($result) {
            return back()->withErrors(['delivery' => $result])->withInput();
        }

        $request->session()->put($sessionKey, true);

        return redirect()
            ->route($this->redirectRoute($request))
            ->with('status', 'Delivery assignment updated successfully.');
    }

    private function redirectRoute(Request $request): string
    {
        return str_starts_with((string) $request->route()?->getName(), 'admin.')
            ? 'admin.fuel-lifting'
            : 'dispatch.fuel-lifting';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function scheduleGarageDelivery(array $data, CarbonImmutable $scheduledAt, float $quantity): ?string
    {
        $stockOut = DB::table('stock_outs')
            ->join('sales', 'sales.id', '=', 'stock_outs.sale_id')
            ->where('stock_outs.id', (int) $data['stock_out_id'])
            ->where('stock_outs.status', 'released')
            ->whereNull('stock_outs.delivery_id')
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', self::ELIGIBLE_SALE_STATUSES)
            ->lockForUpdate()
            ->first([
                'stock_outs.id',
                'stock_outs.sale_id',
                'stock_outs.sale_item_id',
                'stock_outs.customer_id',
                'stock_outs.fuel_type_id',
                'stock_outs.storage_location_id',
                'stock_outs.quantity_liters',
            ]);

        if (! $stockOut) {
            return 'The selected garage stock-out is not eligible for delivery scheduling.';
        }

        if ($quantity !== round((float) $stockOut->quantity_liters, 2)) {
            return 'Garage delivery quantity must match the released stock-out quantity.';
        }

        $deliveryId = DB::table('deliveries')->insertGetId([
            'delivery_code' => $this->nextCode('deliveries', 'delivery_code', 'DLV'),
            'sale_id' => $stockOut->sale_id,
            'sale_item_id' => $stockOut->sale_item_id,
            'customer_id' => $stockOut->customer_id,
            'fuel_type_id' => $stockOut->fuel_type_id,
            'source_type' => 'garage',
            'storage_location_id' => $stockOut->storage_location_id,
            'truck_id' => (int) $data['truck_id'],
            'driver_user_id' => (int) $data['driver_user_id'],
            'scheduled_at' => $scheduledAt->toDateTimeString(),
            'scheduled_quantity_liters' => $quantity,
            'status' => 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('stock_outs')
            ->where('id', $stockOut->id)
            ->whereNull('delivery_id')
            ->update([
                'delivery_id' => $deliveryId,
                'updated_at' => now(),
            ]);

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function scheduleDepotDelivery(array $data, CarbonImmutable $scheduledAt, float $quantity): ?string
    {
        $allocation = DB::table('haul_allocations')
            ->join('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
            ->join('sales', 'sales.id', '=', 'haul_allocations.sale_id')
            ->join('purchase_items', 'purchase_items.id', '=', 'hauls.purchase_item_id')
            ->join('purchases', 'purchases.id', '=', 'hauls.purchase_id')
            ->where('haul_allocations.id', (int) $data['haul_allocation_id'])
            ->where('haul_allocations.destination_type', 'customer')
            ->whereNotNull('haul_allocations.sale_id')
            ->whereNotNull('haul_allocations.customer_id')
            ->where('haul_allocations.status', '!=', 'cancelled')
            ->whereIn('hauls.status', self::ELIGIBLE_DIRECT_HAUL_STATUSES)
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', self::ELIGIBLE_SALE_STATUSES)
            ->whereNull('purchases.deleted_at')
            ->whereColumn('hauls.purchase_id', 'purchase_items.purchase_id')
            ->whereColumn('hauls.depot_id', 'purchases.depot_id')
            ->whereColumn('hauls.fuel_type_id', 'purchase_items.fuel_type_id')
            ->whereColumn('haul_allocations.fuel_type_id', 'hauls.fuel_type_id')
            ->lockForUpdate()
            ->first([
                'haul_allocations.id',
                'haul_allocations.haul_id',
                'haul_allocations.sale_id',
                'haul_allocations.customer_id',
                'haul_allocations.fuel_type_id',
                'haul_allocations.quantity_liters',
                'hauls.depot_id',
                'hauls.quantity_liters as haul_quantity_liters',
            ]);

        if (! $allocation) {
            return 'The selected direct depot allocation is not eligible for delivery scheduling.';
        }

        if (! $this->haulAllocationsAreWithinQuantity((int) $allocation->haul_id, (float) $allocation->haul_quantity_liters)) {
            return 'The selected haul has invalid allocation quantities.';
        }

        $scheduled = (float) DB::table('deliveries')
            ->where('haul_allocation_id', $allocation->id)
            ->where('status', '!=', 'cancelled')
            ->lockForUpdate()
            ->selectRaw('COALESCE(SUM(COALESCE(actual_quantity_liters, scheduled_quantity_liters, 0)), 0) as scheduled_liters')
            ->value('scheduled_liters');
        $remaining = round((float) $allocation->quantity_liters - $scheduled, 2);

        if ($quantity > $remaining) {
            return 'Delivery quantity cannot exceed the remaining direct depot allocation.';
        }

        DB::table('deliveries')->insert([
            'delivery_code' => $this->nextCode('deliveries', 'delivery_code', 'DLV'),
            'sale_id' => $allocation->sale_id,
            'customer_id' => $allocation->customer_id,
            'fuel_type_id' => $allocation->fuel_type_id,
            'source_type' => 'depot',
            'depot_id' => $allocation->depot_id,
            'haul_allocation_id' => $allocation->id,
            'truck_id' => (int) $data['truck_id'],
            'driver_user_id' => (int) $data['driver_user_id'],
            'scheduled_at' => $scheduledAt->toDateTimeString(),
            'scheduled_quantity_liters' => $quantity,
            'status' => 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return null;
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function deliveryRows(?string $search, array $filters)
    {
        return DB::table('deliveries')
            ->join('customers', 'customers.id', '=', 'deliveries.customer_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'deliveries.fuel_type_id')
            ->leftJoin('sales', 'sales.id', '=', 'deliveries.sale_id')
            ->leftJoin('users as drivers', 'drivers.id', '=', 'deliveries.driver_user_id')
            ->leftJoin('trucks', 'trucks.id', '=', 'deliveries.truck_id')
            ->leftJoin('stock_outs', 'stock_outs.delivery_id', '=', 'deliveries.id')
            ->leftJoin('haul_allocations', 'haul_allocations.id', '=', 'deliveries.haul_allocation_id')
            ->leftJoin('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
            ->leftJoin('depots', 'depots.id', '=', 'deliveries.depot_id')
            ->leftJoin('storage_locations', 'storage_locations.id', '=', 'deliveries.storage_location_id')
            ->when($search, fn (Builder $query): Builder => $this->search($query, $search, [
                'deliveries.delivery_code',
                'sales.sale_code',
                'stock_outs.stock_out_code',
                'hauls.haul_code',
                'customers.name',
                'customers.company_name',
                'customers.location',
                'fuel_types.name',
                'deliveries.source_type',
                'depots.name',
                'storage_locations.name',
                'drivers.name',
                'trucks.truck_code',
                'trucks.plate_number',
                'deliveries.status',
                'hauls.status',
            ]))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('deliveries.status', $status))
            ->when($filters['source_type'] ?? null, fn (Builder $query, string $sourceType): Builder => $query->where('deliveries.source_type', $sourceType))
            ->when($filters['fuel_type_id'] ?? null, fn (Builder $query, mixed $fuelTypeId): Builder => $query->where('deliveries.fuel_type_id', (int) $fuelTypeId))
            ->when($filters['driver_user_id'] ?? null, fn (Builder $query, mixed $driverUserId): Builder => $query->where('deliveries.driver_user_id', (int) $driverUserId))
            ->when($filters['truck_id'] ?? null, fn (Builder $query, mixed $truckId): Builder => $query->where('deliveries.truck_id', (int) $truckId))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date): Builder => $query->where('deliveries.scheduled_at', '>=', CarbonImmutable::parse($date)->startOfDay()->toDateTimeString()))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date): Builder => $query->where('deliveries.scheduled_at', '<=', CarbonImmutable::parse($date)->endOfDay()->toDateTimeString()))
            ->orderByDesc('deliveries.scheduled_at')
            ->orderByDesc('deliveries.id')
            ->get([
                'deliveries.id',
                'deliveries.delivery_code',
                'deliveries.source_type',
                'deliveries.scheduled_at',
                'deliveries.delivered_at',
                'deliveries.scheduled_quantity_liters',
                'deliveries.actual_quantity_liters',
                'deliveries.status',
                'deliveries.driver_user_id',
                'deliveries.truck_id',
                'sales.sale_code',
                'customers.name as customer_name',
                'customers.company_name',
                'customers.location',
                'fuel_types.name as fuel_name',
                'drivers.name as driver_name',
                'drivers.phone as driver_phone',
                'trucks.truck_code',
                'trucks.plate_number',
                'trucks.capacity_liters',
                'stock_outs.stock_out_code',
                'haul_allocations.id as allocation_id',
                'haul_allocations.destination_type as allocation_destination_type',
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
                $source = $row->source_type === 'garage'
                    ? ($row->garage_name ?: 'Garage')
                    : ($row->depot_name ?: 'Depot');
                $truck = $row->truck_code
                    ? trim($row->truck_code.($row->plate_number ? ' / '.$row->plate_number : ''))
                    : 'N/A';

                return [
                    'id' => 'dispatch-delivery-'.$row->id,
                    'delivery_id' => (int) $row->id,
                    'driver_user_id' => $row->driver_user_id ? (int) $row->driver_user_id : null,
                    'truck_id' => $row->truck_id ? (int) $row->truck_id : null,
                    'truck_label' => $row->truck_code ? $row->truck_code.' / '.$this->formatLiters($row->capacity_liters) : 'No truck assigned',
                    'raw_status' => $row->status,
                    'status' => $this->label($row->status),
                    'cells' => [
                        $row->delivery_code,
                        $row->sale_code ?: 'N/A',
                        $sourceRef,
                        $this->formatDateTime($row->delivered_at ?: $row->scheduled_at),
                        $row->location ?: $row->company_name,
                        $row->driver_name ?: 'N/A',
                        $row->driver_phone ?: 'N/A',
                        $row->truck_code ?: 'N/A',
                        $this->formatNumber($row->capacity_liters),
                        $this->formatNumber($quantity),
                    ],
                    'details' => [
                        'Delivery Reference' => $row->delivery_code,
                        'Lift Reference' => $row->haul_code ?: 'N/A',
                        'Sale Reference' => $row->sale_code ?: 'N/A',
                        'Customer' => $row->customer_name,
                        'Company' => $row->company_name,
                        'Fuel Type' => $row->fuel_name,
                        'Source' => $this->label($row->source_type),
                        'Source Name' => $source,
                        'Source Reference' => $sourceRef,
                        'Destination' => $row->location ?: $row->company_name,
                        'Driver' => $row->driver_name ?: 'N/A',
                        'Driver Contact' => $row->driver_phone ?: 'N/A',
                        'Truck' => $truck,
                        'Truck Capacity' => $this->formatLiters($row->capacity_liters),
                        'Scheduled Quantity' => $this->formatLiters($row->scheduled_quantity_liters),
                        'Actual Quantity' => $row->actual_quantity_liters ? $this->formatLiters($row->actual_quantity_liters) : 'N/A',
                        'Scheduled Date' => $this->formatDateTime($row->scheduled_at),
                        'Delivered Date' => $row->delivered_at ? $this->formatDateTime($row->delivered_at) : 'N/A',
                        'Lifting Scheduled Date' => $row->lifting_scheduled_at ? $this->formatDateTime($row->lifting_scheduled_at) : 'N/A',
                        'Lifting Completed Date' => $row->hauled_at ? $this->formatDateTime($row->hauled_at) : 'N/A',
                        'Lifting Status' => $this->label($row->lifting_status),
                        'Allocation Status' => $this->label($row->allocation_status),
                        'Status' => $this->label($row->status),
                    ],
                    'allowed_statuses' => self::STATUS_TRANSITIONS[$row->status] ?? [],
                ];
            });
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function deliverySummary(array $filters, ?string $search): array
    {
        $rows = $this->deliveryBaseQuery($filters, $search)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN deliveries.status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
                SUM(CASE WHEN deliveries.status = 'in_transit' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN deliveries.status = 'delivered' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN deliveries.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled
            ")
            ->first();

        return [
            ['label' => 'Total Deliveries', 'value' => number_format((int) ($rows->total ?? 0))],
            ['label' => 'Scheduled', 'value' => number_format((int) ($rows->scheduled ?? 0))],
            ['label' => 'Active', 'value' => number_format((int) ($rows->active ?? 0))],
            ['label' => 'Completed', 'value' => number_format((int) ($rows->completed ?? 0))],
            ['label' => 'Cancelled', 'value' => number_format((int) ($rows->cancelled ?? 0))],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function deliveryBaseQuery(array $filters, ?string $search): Builder
    {
        return DB::table('deliveries')
            ->join('customers', 'customers.id', '=', 'deliveries.customer_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'deliveries.fuel_type_id')
            ->leftJoin('sales', 'sales.id', '=', 'deliveries.sale_id')
            ->leftJoin('users as drivers', 'drivers.id', '=', 'deliveries.driver_user_id')
            ->leftJoin('trucks', 'trucks.id', '=', 'deliveries.truck_id')
            ->leftJoin('stock_outs', 'stock_outs.delivery_id', '=', 'deliveries.id')
            ->leftJoin('haul_allocations', 'haul_allocations.id', '=', 'deliveries.haul_allocation_id')
            ->leftJoin('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
            ->leftJoin('depots', 'depots.id', '=', 'deliveries.depot_id')
            ->leftJoin('storage_locations', 'storage_locations.id', '=', 'deliveries.storage_location_id')
            ->when($search, fn (Builder $query): Builder => $this->search($query, $search, [
                'deliveries.delivery_code',
                'sales.sale_code',
                'stock_outs.stock_out_code',
                'hauls.haul_code',
                'customers.name',
                'customers.company_name',
                'customers.location',
                'fuel_types.name',
                'deliveries.source_type',
                'depots.name',
                'storage_locations.name',
                'drivers.name',
                'trucks.truck_code',
                'trucks.plate_number',
                'deliveries.status',
                'hauls.status',
            ]))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('deliveries.status', $status))
            ->when($filters['source_type'] ?? null, fn (Builder $query, string $sourceType): Builder => $query->where('deliveries.source_type', $sourceType))
            ->when($filters['fuel_type_id'] ?? null, fn (Builder $query, mixed $fuelTypeId): Builder => $query->where('deliveries.fuel_type_id', (int) $fuelTypeId))
            ->when($filters['driver_user_id'] ?? null, fn (Builder $query, mixed $driverUserId): Builder => $query->where('deliveries.driver_user_id', (int) $driverUserId))
            ->when($filters['truck_id'] ?? null, fn (Builder $query, mixed $truckId): Builder => $query->where('deliveries.truck_id', (int) $truckId))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date): Builder => $query->where('deliveries.scheduled_at', '>=', CarbonImmutable::parse($date)->startOfDay()->toDateTimeString()))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date): Builder => $query->where('deliveries.scheduled_at', '<=', CarbonImmutable::parse($date)->endOfDay()->toDateTimeString()));
    }

    private function deliveryForUpdate(int $delivery): ?object
    {
        return DB::table('deliveries')
            ->leftJoin('stock_outs', 'stock_outs.delivery_id', '=', 'deliveries.id')
            ->leftJoin('haul_allocations', 'haul_allocations.id', '=', 'deliveries.haul_allocation_id')
            ->where('deliveries.id', $delivery)
            ->where(function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->where('deliveries.source_type', 'garage')
                        ->whereNotNull('stock_outs.id');
                })->orWhere(function (Builder $query): void {
                    $query->where('deliveries.source_type', 'depot')
                        ->whereNotNull('haul_allocations.id');
                });
            })
            ->lockForUpdate()
            ->first([
                'deliveries.id',
                'deliveries.source_type',
                'deliveries.haul_allocation_id',
                'deliveries.scheduled_quantity_liters',
                'deliveries.actual_quantity_liters',
                'deliveries.status',
                'deliveries.driver_user_id',
                'deliveries.truck_id',
                'deliveries.scheduled_at',
                'haul_allocations.quantity_liters as allocation_quantity_liters',
            ]);
    }

    private function validateDispatchAssignment(object $delivery): ?string
    {
        if (! $delivery->driver_user_id || ! $this->driverForAssignment((int) $delivery->driver_user_id)) {
            return 'A valid assigned driver is required before dispatching this delivery.';
        }

        if (! $delivery->truck_id) {
            return 'A valid assigned truck is required before dispatching this delivery.';
        }

        $truck = $this->truckForAssignment((int) $delivery->truck_id, true);

        if (! $truck) {
            return 'A valid assigned truck is required before dispatching this delivery.';
        }

        $quantity = round((float) ($delivery->actual_quantity_liters ?? $delivery->scheduled_quantity_liters ?? 0), 2);

        if ($quantity <= 0) {
            return 'Delivery quantity is required before dispatching this delivery.';
        }

        if ($quantity > round((float) $truck->capacity_liters, 2)) {
            return 'Delivery quantity cannot exceed the assigned truck capacity.';
        }

        return null;
    }

    private function markDirectAllocationDeliveredWhenComplete(object $delivery): void
    {
        if (! $delivery->haul_allocation_id) {
            return;
        }

        DB::table('haul_allocations')
            ->where('id', $delivery->haul_allocation_id)
            ->lockForUpdate()
            ->get(['id']);

        $delivered = (float) DB::table('deliveries')
            ->where('haul_allocation_id', $delivery->haul_allocation_id)
            ->where('status', 'delivered')
            ->selectRaw('COALESCE(SUM(COALESCE(actual_quantity_liters, scheduled_quantity_liters, 0)), 0) as delivered_liters')
            ->value('delivered_liters');

        if (round($delivered, 2) >= round((float) $delivery->allocation_quantity_liters, 2)) {
            DB::table('haul_allocations')
                ->where('id', $delivery->haul_allocation_id)
                ->update([
                    'status' => 'delivered',
                    'updated_at' => now(),
                ]);
        }
    }

    private function garageStockOutOptions()
    {
        return DB::table('stock_outs')
            ->join('sales', 'sales.id', '=', 'stock_outs.sale_id')
            ->join('customers', 'customers.id', '=', 'stock_outs.customer_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'stock_outs.fuel_type_id')
            ->join('storage_locations', 'storage_locations.id', '=', 'stock_outs.storage_location_id')
            ->where('stock_outs.status', 'released')
            ->whereNull('stock_outs.delivery_id')
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', self::ELIGIBLE_SALE_STATUSES)
            ->orderByDesc('stock_outs.stock_out_at')
            ->get([
                'stock_outs.id',
                'stock_outs.stock_out_code',
                'stock_outs.quantity_liters',
                'sales.sale_code',
                'customers.company_name',
                'fuel_types.name as fuel_name',
                'storage_locations.name as garage_name',
            ])
            ->map(function (object $row): object {
                $row->label = $row->stock_out_code.' / '.$row->sale_code.' / '.$row->company_name.' / '.$row->fuel_name.' / '.$row->garage_name.' / '.$this->formatLiters($row->quantity_liters);

                return $row;
            });
    }

    private function deliveryFilterOptions(): array
    {
        return [
            'statuses' => self::DELIVERY_STATUSES,
            'fuelTypes' => DB::table('fuel_types')
                ->orderBy('name')
                ->get(['id', 'name']),
            'drivers' => DB::table('users')
                ->where('role', 'driver')
                ->orderBy('name')
                ->get(['id', 'name']),
            'trucks' => DB::table('trucks')
                ->orderBy('truck_code')
                ->get(['id', 'truck_code', 'plate_number']),
        ];
    }

    private function directAllocationOptions()
    {
        $scheduled = DB::table('deliveries')
            ->where('source_type', 'depot')
            ->where('status', '!=', 'cancelled')
            ->selectRaw('haul_allocation_id, COALESCE(SUM(COALESCE(actual_quantity_liters, scheduled_quantity_liters, 0)), 0) as scheduled_liters')
            ->groupBy('haul_allocation_id');

        return DB::table('haul_allocations')
            ->join('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
            ->join('sales', 'sales.id', '=', 'haul_allocations.sale_id')
            ->join('customers', 'customers.id', '=', 'haul_allocations.customer_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'haul_allocations.fuel_type_id')
            ->leftJoinSub($scheduled, 'scheduled', 'scheduled.haul_allocation_id', '=', 'haul_allocations.id')
            ->where('haul_allocations.destination_type', 'customer')
            ->whereNotNull('haul_allocations.sale_id')
            ->where('haul_allocations.status', '!=', 'cancelled')
            ->whereIn('hauls.status', self::ELIGIBLE_DIRECT_HAUL_STATUSES)
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', self::ELIGIBLE_SALE_STATUSES)
            ->orderByDesc('hauls.scheduled_at')
            ->get([
                'haul_allocations.id',
                'haul_allocations.quantity_liters',
                'sales.sale_code',
                'customers.company_name',
                'fuel_types.name as fuel_name',
                DB::raw('COALESCE(scheduled.scheduled_liters, 0) as scheduled_liters'),
            ])
            ->filter(fn (object $row): bool => ((float) $row->quantity_liters - (float) $row->scheduled_liters) > 0)
            ->values()
            ->map(function (object $row): object {
                $row->remaining_liters = round((float) $row->quantity_liters - (float) $row->scheduled_liters, 2);
                $row->label = 'Allocation #'.$row->id.' / '.$row->sale_code.' / '.$row->company_name.' / '.$row->fuel_name.' / remaining '.$this->formatLiters($row->remaining_liters);

                return $row;
            });
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

    private function truckOptions()
    {
        return DB::table('trucks')
            ->whereIn('truck_type', ['delivery', 'mixed'])
            ->where('status', 'available')
            ->orderBy('truck_code')
            ->get(['id', 'truck_code', 'plate_number', 'capacity_liters']);
    }

    private function driverForAssignment(int $driverId): ?object
    {
        return DB::table('users')
            ->leftJoin('driver_profiles', 'driver_profiles.user_id', '=', 'users.id')
            ->where('users.id', $driverId)
            ->where('users.role', 'driver')
            ->where('users.status', 'active')
            ->where(function (Builder $query): void {
                $query->whereNull('driver_profiles.status')
                    ->orWhere('driver_profiles.status', '!=', 'inactive');
            })
            ->lockForUpdate()
            ->first(['users.id']);
    }

    private function truckForAssignment(int $truckId, bool $allowCurrentlyAssigned = false): ?object
    {
        return DB::table('trucks')
            ->where('id', $truckId)
            ->whereIn('truck_type', ['delivery', 'mixed'])
            ->where(function (Builder $query) use ($allowCurrentlyAssigned): void {
                $query->where('status', 'available');

                if ($allowCurrentlyAssigned) {
                    $query->orWhere('status', 'assigned');
                }
            })
            ->lockForUpdate()
            ->first(['id', 'capacity_liters']);
    }

    private function truckForUpdate(int $truckId): ?object
    {
        return DB::table('trucks')
            ->where('id', $truckId)
            ->whereIn('truck_type', ['delivery', 'mixed'])
            ->where('status', 'available')
            ->lockForUpdate()
            ->first(['id', 'capacity_liters']);
    }

    private function driverIsAvailable(int $driverId, CarbonImmutable $scheduledAt, ?int $exceptDeliveryId = null): bool
    {
        $hasDeliveryConflict = DB::table('deliveries')
            ->where('driver_user_id', $driverId)
            ->whereIn('status', self::ACTIVE_DELIVERY_STATUSES)
            ->where('scheduled_at', $scheduledAt->toDateTimeString())
            ->when($exceptDeliveryId, fn (Builder $query): Builder => $query->where('id', '!=', $exceptDeliveryId))
            ->lockForUpdate()
            ->exists();

        if ($hasDeliveryConflict) {
            return false;
        }

        return ! DB::table('hauls')
            ->where('driver_user_id', $driverId)
            ->whereIn('status', self::ACTIVE_HAUL_STATUSES)
            ->where('scheduled_at', $scheduledAt->toDateTimeString())
            ->lockForUpdate()
            ->exists();
    }

    private function truckIsAvailable(int $truckId, CarbonImmutable $scheduledAt, ?int $exceptDeliveryId = null): bool
    {
        $hasDeliveryConflict = DB::table('deliveries')
            ->where('truck_id', $truckId)
            ->whereIn('status', self::ACTIVE_DELIVERY_STATUSES)
            ->where('scheduled_at', $scheduledAt->toDateTimeString())
            ->when($exceptDeliveryId, fn (Builder $query): Builder => $query->where('id', '!=', $exceptDeliveryId))
            ->lockForUpdate()
            ->exists();

        if ($hasDeliveryConflict) {
            return false;
        }

        return ! DB::table('hauls')
            ->where('truck_id', $truckId)
            ->whereIn('status', self::ACTIVE_HAUL_STATUSES)
            ->where('scheduled_at', $scheduledAt->toDateTimeString())
            ->lockForUpdate()
            ->exists();
    }

    private function haulAllocationsAreWithinQuantity(int $haulId, float $haulQuantity): bool
    {
        DB::table('haul_allocations')
            ->where('haul_id', $haulId)
            ->lockForUpdate()
            ->get(['id']);

        $allocated = (float) DB::table('haul_allocations')
            ->where('haul_id', $haulId)
            ->where('status', '!=', 'cancelled')
            ->sum('quantity_liters');

        return round($allocated, 2) <= round($haulQuantity, 2);
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

    private function nextCode(string $table, string $column, string $prefix): string
    {
        $nextId = ((int) DB::table($table)->max('id')) + 1;

        do {
            $code = $prefix.'-'.str_pad((string) $nextId, 6, '0', STR_PAD_LEFT);
            $nextId++;
        } while (DB::table($table)->where($column, $code)->exists());

        return $code;
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
