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
    private const ELIGIBLE_SALE_STATUSES = ['confirmed', 'partially_paid', 'paid', 'unpaid'];

    public function index(Request $request, string $state = 'schedule'): View
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $search = trim((string) ($data['search'] ?? ''));
        $rows = $this->deliveryRows($search === '' ? null : $search);

        return view('dispatch.fuel-lifting', [
            'activeTab' => $state === 'hauled' ? 'hauled' : 'schedule',
            'search' => $search === '' ? null : $search,
            'scheduledRows' => $rows->whereIn('raw_status', self::ACTIVE_DELIVERY_STATUSES)->values(),
            'deliveredRows' => $rows->whereIn('raw_status', ['delivered'])->values(),
            'garageStockOuts' => $this->garageStockOutOptions(),
            'directAllocations' => $this->directAllocationOptions(),
            'drivers' => $this->driverOptions(),
            'trucks' => $this->truckOptions(),
            'idempotencyKey' => (string) Str::uuid(),
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
            ->where('hauls.status', '!=', 'cancelled')
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

    private function deliveryRows(?string $search)
    {
        return DB::table('deliveries')
            ->join('customers', 'customers.id', '=', 'deliveries.customer_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'deliveries.fuel_type_id')
            ->leftJoin('sales', 'sales.id', '=', 'deliveries.sale_id')
            ->leftJoin('users as drivers', 'drivers.id', '=', 'deliveries.driver_user_id')
            ->leftJoin('trucks', 'trucks.id', '=', 'deliveries.truck_id')
            ->leftJoin('stock_outs', 'stock_outs.delivery_id', '=', 'deliveries.id')
            ->leftJoin('haul_allocations', 'haul_allocations.id', '=', 'deliveries.haul_allocation_id')
            ->when($search, fn (Builder $query): Builder => $this->search($query, $search, [
                'deliveries.delivery_code',
                'sales.sale_code',
                'stock_outs.stock_out_code',
                'customers.name',
                'customers.company_name',
                'fuel_types.name',
                'deliveries.source_type',
                'drivers.name',
                'trucks.truck_code',
                'deliveries.status',
            ]))
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
                'sales.sale_code',
                'customers.name as customer_name',
                'customers.company_name',
                'customers.location',
                'fuel_types.name as fuel_name',
                'drivers.name as driver_name',
                'drivers.phone as driver_phone',
                'trucks.truck_code',
                'trucks.capacity_liters',
                'stock_outs.stock_out_code',
                'haul_allocations.id as allocation_id',
            ])
            ->map(function (object $row): array {
                $quantity = (float) ($row->actual_quantity_liters ?? $row->scheduled_quantity_liters ?? 0);
                $sourceRef = $row->source_type === 'garage'
                    ? ($row->stock_out_code ?: 'Garage Release')
                    : ('Allocation #'.($row->allocation_id ?: 'N/A'));

                return [
                    'id' => 'dispatch-delivery-'.$row->id,
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
                        'Sale Reference' => $row->sale_code ?: 'N/A',
                        'Customer' => $row->customer_name,
                        'Company' => $row->company_name,
                        'Fuel Type' => $row->fuel_name,
                        'Source' => $this->label($row->source_type),
                        'Source Reference' => $sourceRef,
                        'Destination' => $row->location ?: $row->company_name,
                        'Driver' => $row->driver_name ?: 'N/A',
                        'Driver Contact' => $row->driver_phone ?: 'N/A',
                        'Truck' => $row->truck_code ?: 'N/A',
                        'Truck Capacity' => $this->formatLiters($row->capacity_liters),
                        'Scheduled Quantity' => $this->formatLiters($row->scheduled_quantity_liters),
                        'Actual Quantity' => $row->actual_quantity_liters ? $this->formatLiters($row->actual_quantity_liters) : 'N/A',
                        'Scheduled Date' => $this->formatDateTime($row->scheduled_at),
                        'Delivered Date' => $row->delivered_at ? $this->formatDateTime($row->delivered_at) : 'N/A',
                        'Status' => $this->label($row->status),
                    ],
                ];
            });
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
            ->where('hauls.status', '!=', 'cancelled')
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

    private function truckForUpdate(int $truckId): ?object
    {
        return DB::table('trucks')
            ->where('id', $truckId)
            ->whereIn('truck_type', ['delivery', 'mixed'])
            ->where('status', 'available')
            ->lockForUpdate()
            ->first(['id', 'capacity_liters']);
    }

    private function driverIsAvailable(int $driverId, CarbonImmutable $scheduledAt): bool
    {
        return ! DB::table('deliveries')
            ->where('driver_user_id', $driverId)
            ->whereIn('status', self::ACTIVE_DELIVERY_STATUSES)
            ->where('scheduled_at', $scheduledAt->toDateTimeString())
            ->lockForUpdate()
            ->exists();
    }

    private function truckIsAvailable(int $truckId, CarbonImmutable $scheduledAt): bool
    {
        return ! DB::table('deliveries')
            ->where('truck_id', $truckId)
            ->whereIn('status', self::ACTIVE_DELIVERY_STATUSES)
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
