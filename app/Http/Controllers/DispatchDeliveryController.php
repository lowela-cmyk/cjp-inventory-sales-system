<?php

namespace App\Http\Controllers;

use App\Rules\ApprovedFuelType;
use App\Services\IdempotencyService;
use App\Services\LiftAllocationService;
use App\Services\PurchaseStockService;
use App\Services\PurchaseWorkflowService;
use App\Services\TruckAvailabilityService;
use App\Support\DepotLabel;
use App\Support\FilterDates;
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
    public function __construct(
        private readonly IdempotencyService $idempotencyService,
        private readonly PurchaseWorkflowService $purchaseWorkflow,
        private readonly TruckAvailabilityService $truckAvailability,
        private readonly LiftAllocationService $liftAllocations,
        private readonly PurchaseStockService $purchaseStock
    ) {}

    private const ACTIVE_HAUL_STATUSES = ['scheduled', 'in_transit', 'lifted'];

    public function index(Request $request, string $state = 'schedule'): View
    {
        $activeTab = in_array($state, ['hauled', 'trucks'], true) ? $state : 'schedule';
        // The Trucks tab filters the fleet (truck availability), the other tabs filter lifts.
        $truckFilters = $activeTab === 'trucks'
            ? $request->validate([
                'search' => ['nullable', 'string', 'max:100'],
                'status' => ['nullable', Rule::in(TruckAvailabilityService::DISPLAY_STATUSES)],
            ])
            : [];
        $data = $activeTab === 'trucks' ? [] : $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['scheduled', 'in_transit', 'lifted', 'completed', 'cancelled'])],
            'fuel_type_id' => [
                'nullable',
                'integer',
                ApprovedFuelType::rule(),
            ],
            'driver_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where(fn (Builder $query): Builder => $query->where('role', 'driver'))],
            'truck_id' => ['nullable', 'integer', Rule::exists('trucks', 'id')],
            'lift_date' => ['nullable', 'date'],
        ]);
        $search = trim((string) ($data['search'] ?? ''));
        $filters = [
            'status' => $data['status'] ?? null,
            'fuel_type_id' => $data['fuel_type_id'] ?? null,
            'driver_user_id' => $data['driver_user_id'] ?? null,
            'truck_id' => $data['truck_id'] ?? null,
            'lift_date' => $data['lift_date'] ?? null,
        ];
        $rows = $this->haulRows($search === '' ? null : $search, $filters);

        $truckSearch = trim((string) ($truckFilters['search'] ?? ''));

        return view('dispatch.fuel-lifting', [
            'activeTab' => $activeTab,
            'search' => $search === '' ? null : $search,
            'truckDirectory' => $this->truckAvailability->directory($truckSearch, $truckFilters['status'] ?? null),
            'truckSearch' => $truckSearch === '' ? null : $truckSearch,
            'truckStatusFilter' => $truckFilters['status'] ?? null,
            'truckTypes' => TruckAvailabilityService::TRUCK_TYPES,
            'nextTruckCode' => $this->truckAvailability->nextTruckCode(),
            'truckStatuses' => TruckAvailabilityService::DISPLAY_STATUSES,
            'managedStatuses' => TruckAvailabilityService::MANAGED_STATUSES,
            'scheduledRows' => $rows->whereIn('raw_status', self::ACTIVE_HAUL_STATUSES)->values(),
            'deliveredRows' => $rows->whereIn('raw_status', ['completed', 'cancelled'])->values(),
            'filters' => $filters,
            'filterOptions' => $this->haulFilterOptions(),
            'summaryCards' => $this->haulSummary($filters, $search === '' ? null : $search),
            'drivers' => $this->driverOptions(),
            'trucks' => $this->haulTruckOptions(),
            'purchaseItems' => $this->availablePurchaseItemOptions(),
            // Part 2 revisions: open Depot to Client orders a lift can deliver (Delivery Details).
            'directSaleOptions' => $this->liftAllocations->directSaleOptions(),
            'createIdempotencyKey' => (string) Str::uuid(),
            'statusIdempotencyKey' => (string) Str::uuid(),
            'truckAssignmentIdempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'items' => ['sometimes', 'array', 'min:1', 'max:20'],
            'items.*.purchase_item_id' => ['required_with:items', 'integer', 'distinct', Rule::exists('purchase_items', 'id')],
            'items.*.quantity_liters' => ['required_with:items', 'numeric', 'gt:0', 'max:1000000'],
            // Authority to Load for this purchase (Part 9).
            'items.*.atl_number' => ['nullable', 'string', 'max:100'],
            // Part 2 revisions: Delivery Details, the open Depot to Client orders this lift delivers.
            'deliveries' => ['sometimes', 'array', 'max:50'],
            'deliveries.*.sale_id' => ['required', 'integer', Rule::exists('sales', 'id')],
            'purchase_item_id' => ['required_without:items', 'integer', Rule::exists('purchase_items', 'id')],
            'driver_user_id' => ['required', 'integer', Rule::exists('users', 'id')->where(fn (Builder $query): Builder => $query->where('role', 'driver'))],
            'truck_id' => ['required', 'integer', Rule::exists('trucks', 'id')],
            'dr_number' => ['nullable', 'string', 'max:100'],
            'scheduled_at' => ['required', 'date'],
            'quantity_liters' => ['required_without:items', 'numeric', 'gt:0', 'max:1000000'],
        ], [], [
            'driver_user_id' => 'driver',
            'truck_id' => 'truck',
            'scheduled_at' => 'lift date',
            'dr_number' => 'DR number',
            'items.*.purchase_item_id' => 'purchase',
            'items.*.quantity_liters' => 'amount to lift',
            'items.*.atl_number' => 'ATL',
            'deliveries.*.sale_id' => 'order',
        ]);
        $items = collect($data['items'] ?? [[
            'purchase_item_id' => $data['purchase_item_id'],
            'quantity_liters' => $data['quantity_liters'],
        ]])->map(fn (array $item): array => [
            'purchase_item_id' => (int) $item['purchase_item_id'],
            'quantity_liters' => round((float) $item['quantity_liters'], 2),
            'atl_number' => trim((string) ($item['atl_number'] ?? '')) ?: null,
        ])->values();
        $fuelTypeByItem = DB::table('purchase_items')->whereIn('id', $items->pluck('purchase_item_id'))->pluck('fuel_type_id', 'id')->map(fn (mixed $id): int => (int) $id)->all();
        // Field-level delivery errors (under the right row) before anything is written.
        $allocations = $this->liftAllocations->resolveDeliveries(array_values($data['deliveries'] ?? []), $items->all(), $fuelTypeByItem);
        $sessionKey = 'hauls.created.'.((string) $data['idempotency_key']);

        if ($request->session()->has($sessionKey)) {
            return redirect()
                ->route($this->liftingRedirectRoute($request))
                ->with('status', 'Lift assignment was already submitted.');
        }

        try {
            $idempotency = $this->idempotencyService->run(
                (string) $data['idempotency_key'],
                'hauls.store',
                (int) $request->user()->id,
                function () use ($data, $items, $allocations, $request): array {
                    $driver = $this->driverForAssignment((int) $data['driver_user_id']);

                    if (! $driver) {
                        throw new \RuntimeException('The selected driver is not eligible for lifting.');
                    }

                    $truck = $this->truckForAssignment((int) $data['truck_id']);

                    if (! $truck) {
                        throw new \RuntimeException('The selected truck is not eligible for lifting.');
                    }

                    $scheduledAt = CarbonImmutable::parse($data['scheduled_at']);
                    $purchaseItems = $items->map(function (array $item, int $index) use ($allocations): object {
                        $purchaseItem = $this->purchaseItemForAssignment($item['purchase_item_id']);
                        if (! $purchaseItem) {
                            throw new \RuntimeException('One or more selected purchases are not eligible for lifting.');
                        }
                        $purchaseItem->scheduled_quantity_liters = $item['quantity_liters'];
                        $purchaseItem->atl_number = $item['atl_number'];
                        $purchaseItem->allocation = $allocations[$index] ?? [];

                        return $purchaseItem;
                    });

                    if ($purchaseItems->pluck('depot_id')->unique()->count() !== 1) {
                        throw new \RuntimeException('All purchases in one lifting schedule must use the same pickup depot.');
                    }

                    $totalQuantity = round((float) $purchaseItems->sum('scheduled_quantity_liters'), 2);
                    if ($totalQuantity <= 0 || $totalQuantity > round((float) $truck->capacity_liters, 2)) {
                        throw new \RuntimeException('The combined lift quantity cannot exceed the selected truck capacity.');
                    }

                    // Part 2 revisions: a lift takes at most the purchase's fuel still in the depot.
                    $stock = $this->purchaseStock->lines($purchaseItems->pluck('id')->map(fn (mixed $id): int => (int) $id)->all(), true);
                    foreach ($purchaseItems as $purchaseItem) {
                        $remaining = (float) ($stock->get((int) $purchaseItem->id)?->remaining_in_depot ?? 0);
                        if ((float) $purchaseItem->scheduled_quantity_liters > $remaining) {
                            throw new \RuntimeException('Lift quantity cannot exceed the '.number_format($remaining, 2).' L of '.$purchaseItem->purchase_code.' remaining in the depot.');
                        }
                    }

                    $scheduleCode = $this->nextCode('lifting_schedules', 'schedule_code', 'LFT');
                    $scheduleId = DB::table('lifting_schedules')->insertGetId([
                        'schedule_code' => $scheduleCode,
                        'depot_id' => $purchaseItems->first()->depot_id,
                        'truck_id' => $truck->id,
                        'driver_user_id' => $driver->id,
                        'dr_number' => $data['dr_number'] ?? null,
                        'scheduled_at' => $scheduledAt->toDateTimeString(),
                        'status' => 'scheduled',
                        'created_by' => $request->user()->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    foreach ($purchaseItems as $index => $purchaseItem) {
                        $haulCode = $scheduleCode.'-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
                        $haulId = DB::table('hauls')->insertGetId([
                            'lifting_schedule_id' => $scheduleId,
                            'haul_code' => $haulCode,
                            'purchase_id' => $purchaseItem->purchase_id,
                            'purchase_item_id' => $purchaseItem->id,
                            'depot_id' => $purchaseItem->depot_id,
                            'fuel_type_id' => $purchaseItem->fuel_type_id,
                            'truck_id' => $truck->id,
                            'driver_user_id' => $driver->id,
                            'dr_number' => $data['dr_number'] ?? null,
                            'atl_number' => $purchaseItem->atl_number,
                            'scheduled_at' => $scheduledAt->toDateTimeString(),
                            'source_location' => null,
                            'quantity_liters' => $purchaseItem->scheduled_quantity_liters,
                            'status' => 'scheduled',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        $this->liftAllocations->createForHaul($haulId, (int) $purchaseItem->fuel_type_id, $purchaseItem->allocation);
                        $this->purchaseWorkflow->synchronize((int) $purchaseItem->purchase_id, (int) $request->user()->id, $haulId);
                    }

                    $this->truckAvailability->synchronizeStatus((int) $truck->id);

                    return ['reference_id' => $scheduleId, 'response_reference' => $scheduleCode];
                }
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['lift' => $e->getMessage()])->withInput();
        }

        if ($idempotency['duplicate']) {
            return redirect()
                ->route($this->liftingRedirectRoute($request))
                ->with('status', 'Lift assignment was already submitted.');
        }

        $request->session()->put($sessionKey, true);

        return redirect()
            ->route($this->liftingRedirectRoute($request))
            ->with('status', 'Lift assignment scheduled successfully.');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function haulRows(?string $search, array $filters)
    {
        $scheduleItems = DB::table('hauls as schedule_hauls')
            ->join('purchases as schedule_purchases', 'schedule_purchases.id', '=', 'schedule_hauls.purchase_id')
            ->join('fuel_types as schedule_fuels', 'schedule_fuels.id', '=', 'schedule_hauls.fuel_type_id')
            ->whereNotNull('schedule_hauls.lifting_schedule_id')
            ->selectRaw('schedule_hauls.lifting_schedule_id, GROUP_CONCAT(DISTINCT schedule_purchases.purchase_code) as purchase_codes, GROUP_CONCAT(DISTINCT schedule_fuels.name) as fuel_names, SUM(schedule_hauls.quantity_liters) as scheduled_quantity_liters')
            ->groupBy('schedule_hauls.lifting_schedule_id');

        $rows = DB::table('hauls')
            ->join('purchases', 'purchases.id', '=', 'hauls.purchase_id')
            ->join('depots', 'depots.id', '=', 'hauls.depot_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'hauls.fuel_type_id')
            ->join('trucks', 'trucks.id', '=', 'hauls.truck_id')
            ->join('users as drivers', 'drivers.id', '=', 'hauls.driver_user_id')
            ->leftJoin('driver_profiles', 'driver_profiles.user_id', '=', 'drivers.id')
            ->leftJoin('lifting_schedules', 'lifting_schedules.id', '=', 'hauls.lifting_schedule_id')
            ->leftJoinSub($scheduleItems, 'schedule_items', 'schedule_items.lifting_schedule_id', '=', 'hauls.lifting_schedule_id')
            ->leftJoin('users as status_updater', 'status_updater.id', '=', 'hauls.status_updated_by')
            ->leftJoin('users as receipt_uploader', 'receipt_uploader.id', '=', 'hauls.withdrawal_receipt_uploaded_by')
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
            ->when($filters['lift_date'] ?? null, fn (Builder $query, string $date): Builder => $query->whereBetween('hauls.scheduled_at', [
                CarbonImmutable::parse($date)->startOfDay()->toDateTimeString(),
                CarbonImmutable::parse($date)->endOfDay()->toDateTimeString(),
            ]))
            ->orderByDesc('hauls.scheduled_at')
            ->orderByDesc('hauls.id')
            ->get([
                'hauls.id',
                'hauls.lifting_schedule_id',
                'hauls.haul_code',
                'hauls.dr_number',
                'hauls.atl_number',
                'hauls.scheduled_at',
                'hauls.hauled_at',
                'hauls.source_location',
                'hauls.quantity_liters',
                'hauls.status',
                'hauls.truck_id',
                'hauls.status_updated_at',
                'hauls.withdrawal_receipt_path',
                'hauls.withdrawal_receipt_uploaded_at',
                'status_updater.name as status_updated_by_name',
                'status_updater.role as status_updated_by_role',
                'receipt_uploader.name as receipt_uploaded_by_name',
                'purchases.purchase_code',
                'depots.name as depot_name',
                'depots.address as depot_address',
                'fuel_types.name as fuel_name',
                'lifting_schedules.schedule_code',
                'schedule_items.purchase_codes',
                'schedule_items.fuel_names',
                'schedule_items.scheduled_quantity_liters',
                'trucks.truck_code',
                'trucks.plate_number',
                'trucks.capacity_liters',
                'drivers.name as driver_name',
                'drivers.phone as driver_phone',
                'driver_profiles.driver_code',
            ]);
        $liftRows = $this->liftRowsByHaul($rows->pluck('id')->all());
        $loads = $this->liftAllocations->loadSummaryByHaul($rows->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());

        return $rows
            ->map(function (object $row) use ($liftRows, $loads): array {
                $truck = trim($row->truck_code.($row->plate_number ? ' / '.$row->plate_number : ''));

                return [
                    'id' => 'dispatch-haul-'.$row->id,
                    'haul_id' => (int) $row->id,
                    'schedule_group' => $row->lifting_schedule_id ? 'schedule-'.$row->lifting_schedule_id : 'haul-'.$row->id,
                    'raw_status' => $row->status,
                    'cells' => [
                        $row->schedule_code ?: $row->haul_code,
                        $row->purchase_codes ?: $row->purchase_code,
                        $row->dr_number ?: 'N/A',
                        $this->formatDateTime($row->hauled_at ?: $row->scheduled_at),
                        DepotLabel::make($row->depot_name, $row->depot_address),
                        $row->driver_name,
                        $row->driver_phone ?: 'N/A',
                        $truck,
                        $this->formatNumber($row->capacity_liters),
                        $this->formatLiters($row->scheduled_quantity_liters ?: $row->quantity_liters),
                    ],
                    'status' => $this->label($row->status),
                    'details' => [
                        'Lift Reference' => $row->schedule_code ?: $row->haul_code,
                        'Purchase IDs' => $row->purchase_codes ?: $row->purchase_code,
                        'DR Number' => $row->dr_number ?: 'N/A',
                        'Fuel Type' => $row->fuel_names ?: $row->fuel_name,
                        'Pickup Depot' => $row->depot_name,
                        'Depot Address' => $row->depot_address ?: 'N/A',
                        'Driver' => $row->driver_name,
                        "Driver's Contact" => $row->driver_phone ?: 'N/A',
                        'Driver Code' => $row->driver_code ?: 'N/A',
                        'Truck-ID' => $truck,
                        'Capacity' => $this->formatLiters($row->capacity_liters),
                        'Quantity Lift' => $this->formatLiters($row->scheduled_quantity_liters ?: $row->quantity_liters),
                        'Scheduled Date' => $this->formatDateTime($row->scheduled_at),
                        'Hauled Date' => $row->hauled_at ? $this->formatDateTime($row->hauled_at) : 'N/A',
                        'Status' => $this->label($row->status),
                        'Last Status Update' => $row->status_updated_by_name
                            ? $row->status_updated_by_name.' ('.$this->label($row->status_updated_by_role).'), '.$this->formatDateTime($row->status_updated_at)
                            : 'N/A',
                    ],
                    'allowed_statuses' => DispatchLiftingStatusController::STATUS_TRANSITIONS[$row->status] ?? [],
                    'truck_id' => (int) $row->truck_id,
                    'can_assign_truck' => $row->status === 'scheduled',
                    'haul' => [
                        'id' => (int) $row->id,
                        'code' => $row->haul_code,
                        'purchase_code' => $row->purchase_code,
                        'fuel_name' => $row->fuel_name,
                        'quantity' => $this->formatLiters($row->quantity_liters),
                        'atl_number' => $row->atl_number ?: 'N/A',
                        'lifts' => $liftRows[(int) $row->id] ?? [],
                        'load' => $loads[(int) $row->id] ?? ['delivered' => 0.0, 'remaining' => round((float) $row->quantity_liters, 2)],
                        'can_upload_withdrawal' => in_array($row->status, ['lifted', 'completed'], true),
                        'withdrawal_uploaded' => (bool) $row->withdrawal_receipt_path,
                        'withdrawal_uploaded_label' => $row->withdrawal_receipt_path
                            ? 'Uploaded '.$this->formatDateTime($row->withdrawal_receipt_uploaded_at).($row->receipt_uploaded_by_name ? ' by '.$row->receipt_uploaded_by_name : '')
                            : 'Not uploaded',
                    ],
                ];
            })
            // One table row per lifting schedule; keep every haul of the schedule for per-lift actions.
            ->groupBy('schedule_group')
            ->map(function ($group): array {
                $row = $group->first();
                $row['hauls'] = $group->pluck('haul')->values()->all();
                // Delivered (to Depot to Client orders) for the whole schedule; the rest stays on the truck.
                $load = collect($row['hauls'])->pluck('load');
                $row['load'] = [
                    'delivered' => $this->formatLiters($load->sum('delivered')),
                    'remaining' => $this->formatLiters($load->sum('remaining')),
                ];
                $row['details'] = array_merge($row['details'], [
                    'Delivered Quantity' => $row['load']['delivered'],
                    'Remaining on Truck' => $row['load']['remaining'],
                ]);
                // Keep Status and Last Status Update at the end of the details.
                foreach (['Status', 'Last Status Update'] as $label) {
                    $value = $row['details'][$label];
                    unset($row['details'][$label]);
                    $row['details'][$label] = $value;
                }

                return $row;
            })
            ->values();
    }

    /**
     * Delivery rows of each haul for the View modal: "Depot to Client" (customer / sales order). Lifts scheduled before
     * October 22, 2026 may also show their retired "Depot to Garage" (tank) rows.
     *
     * @param  array<int, int>  $haulIds
     * @return array<int, array<int, array{type: string, destination: string, quantity: string, status: string}>>
     */
    private function liftRowsByHaul(array $haulIds): array
    {
        if ($haulIds === []) {
            return [];
        }

        return DB::table('haul_allocations')
            ->leftJoin('storage_locations', 'storage_locations.id', '=', 'haul_allocations.storage_location_id')
            ->leftJoin('sales', 'sales.id', '=', 'haul_allocations.sale_id')
            ->leftJoin('customers', 'customers.id', '=', 'haul_allocations.customer_id')
            ->whereIn('haul_allocations.haul_id', $haulIds)
            ->orderBy('haul_allocations.id')
            ->get(['haul_allocations.haul_id', 'haul_allocations.destination_type', 'haul_allocations.quantity_liters', 'haul_allocations.status', 'storage_locations.name as tank_name', 'sales.sale_code', 'sales.sales_order_number', 'customers.name as customer_name', 'customers.company_name'])
            ->groupBy('haul_id')
            ->map(fn ($group): array => $group->map(fn (object $row): array => [
                'type' => $row->destination_type === 'customer' ? 'Depot to Client' : 'Depot to Garage (retired)',
                'destination' => $row->destination_type === 'customer'
                    ? ($row->customer_name ?: $row->company_name ?: 'N/A').' · '.($row->sales_order_number ?: $row->sale_code ?: 'N/A')
                    : ($row->tank_name ?: 'N/A'),
                'quantity' => $this->formatLiters($row->quantity_liters),
                'status' => $this->label($row->status),
            ])->values()->all())
            ->all();
    }

    private function liftingRedirectRoute(Request $request): string
    {
        return str_starts_with((string) $request->route()?->getName(), 'admin.')
            ? 'admin.fuel-lifting'
            : 'dispatch.fuel-lifting';
    }

    private function haulFilterOptions(): array
    {
        return [
            'statuses' => ['scheduled', 'in_transit', 'lifted', 'completed', 'cancelled'],
            'fuelTypes' => DB::table('fuel_types')->where('status', 'active')->whereIn('code', array_keys(config('fuels.approved', ['F1' => true, 'UNL' => true, 'DSL' => true, 'PREM' => true])))->orderBy('name')->get(['id', 'name']),
            'drivers' => $this->driverOptions(),
            'trucks' => $this->haulTruckOptions(),
            'liftDates' => FilterDates::distinct(DB::table('hauls'), 'scheduled_at'),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function haulSummary(array $filters, ?string $search): array
    {
        $rows = $this->haulRows($search, $filters);

        return [
            ['label' => 'Assigned Lifts', 'value' => number_format($rows->count())],
            ['label' => 'Scheduled', 'value' => number_format($rows->where('raw_status', 'scheduled')->count())],
            // "Lifted" is the driver's final step (stock posted, truck free), so it counts as completed.
            ['label' => 'In Progress', 'value' => number_format($rows->where('raw_status', 'in_transit')->count())],
            ['label' => 'Completed', 'value' => number_format($rows->whereIn('raw_status', ['lifted', 'completed'])->count())],
        ];
    }

    private function haulTruckOptions()
    {
        return $this->truckAvailability->assignableTrucks();
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

    /**
     * Purchases a lift can take: "Fuel / Remaining in Depot" (e.g. "PREMIUM - 60,000.00 L"), the liters still at the depot.
     */
    private function availablePurchaseItemOptions()
    {
        return $this->purchaseStock->lines()
            ->filter(fn (object $line): bool => $line->remaining_in_depot > 0)
            ->filter(fn (object $line): bool => DB::table('depots')->where('id', $line->depot_id)->where('status', 'active')->exists())
            ->sortBy('purchase_code')
            ->values()
            ->map(function (object $line): object {
                $line->remaining_liters = $line->remaining_in_depot;
                $line->label = $line->purchase_code.' | '.$line->fuel_name.' | '.$line->depot_name;
                $line->remaining_label = mb_strtoupper((string) $line->fuel_name).' - '.$this->formatLiters($line->remaining_in_depot);
                $line->pickup_label = DepotLabel::make($line->depot_name, $line->depot_address);

                return $line;
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
                'purchases.purchase_code',
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
        return $this->truckAvailability->lockAssignableTruck($truckId);
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
     * @param  array<int, string>  $columns
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
