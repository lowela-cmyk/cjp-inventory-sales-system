<?php

namespace App\Http\Controllers;

use App\Rules\ApprovedFuelType;
use App\Services\DashboardSummaryService;
use App\Services\GarageTankService;
use App\Services\IdempotencyService;
use App\Services\PurchaseService;
use App\Services\StockInService;
use App\Services\StockOutFinancialService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class InventoryOfficerPurchaseController extends Controller
{
    public function __construct(
        private readonly IdempotencyService $idempotencyService,
        private readonly PurchaseService $purchaseService,
        private readonly StockInService $stockInService,
        private readonly StockOutFinancialService $stockOutFinancials
    ) {}

    private const PURCHASE_STATUSES = ['draft', 'ordered', 'partially_hauled', 'hauled', 'cancelled'];

    private const PAYMENT_STATUSES = ['paid', 'partial', 'unpaid'];

    private const STOCK_IN_REFERENCE_TYPE = 'haul_allocation';

    private const STOCK_OUT_REFERENCE_TYPE = 'stock_out';

    /** Status filter options per inventory tab (Stock-In movements are always posted, so it has none). */
    private const TAB_FILTER_STATUSES = [
        'purchases' => ['pending', 'scheduled', 'in_transit', 'partially_lifted', 'completed', 'cancelled'],
        'stock-in' => [],
        'stock-out' => ['prepared', 'released', 'cancelled'],
        'depots' => ['active', 'inactive'],
    ];

    public function index(Request $request, DashboardSummaryService $dashboardSummary, GarageTankService $garageTanks, string $state = 'purchases'): View
    {
        $garageTanks->ensureForActiveFuelTypes();

        $activeTab = in_array($state, ['purchases', 'stock-in', 'stock-out', 'depots'], true) ? $state : 'purchases';
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(self::TAB_FILTER_STATUSES[$activeTab])],
            'date' => ['nullable', 'date'],
            'depot_id' => ['nullable', 'integer', Rule::exists('depots', 'id')],
            'fuel_type_id' => ['nullable', 'integer', Rule::exists('fuel_types', 'id')],
        ]);

        $search = trim((string) ($data['search'] ?? ''));
        // Filters belong to the tab whose toolbar submitted them; the other tabs stay unfiltered.
        $filters = [
            'status' => $data['status'] ?? null,
            'date' => $data['date'] ?? null,
            'depot_id' => $data['depot_id'] ?? null,
            'fuel_type_id' => $data['fuel_type_id'] ?? null,
        ];
        $filtersFor = fn (string $tab): array => $tab === $activeTab ? $filters : [];
        $purchases = $this->purchaseRows($search === '' ? null : $search, $filtersFor('purchases'));
        $stockIn = $this->stockInRows($search === '' ? null : $search, $filtersFor('stock-in'));
        $stockOut = $this->stockOutRows($search === '' ? null : $search, $filtersFor('stock-out'));
        $purchaseFuelTypeIds = $purchases->pluck('fuel_type_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $purchaseDepotIds = $purchases->pluck('depot_id')->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();

        return view('inventory-officer.inventory', [
            'activeTab' => $activeTab,
            'search' => $search === '' ? null : $search,
            'summaryCards' => $dashboardSummary->inventoryCards(),
            'purchases' => $purchases,
            'stockIn' => $stockIn,
            'stockOut' => $stockOut,
            'depots' => DB::table('depots')
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name']),
            'depotRows' => $this->depotRows($search === '' ? null : $search, $filtersFor('depots')),
            'filters' => $filters,
            'filterOptions' => [
                'statuses' => self::TAB_FILTER_STATUSES,
                'depots' => DB::table('depots')->orderBy('name')->get(['id', 'name']),
                'fuelTypes' => DB::table('fuel_types')
                    ->where('status', 'active')
                    ->whereIn('code', array_keys(config('fuels.approved', ['F1' => true, 'UNL' => true, 'DSL' => true, 'PREM' => true])))
                    ->orderBy('name')
                    ->get(['id', 'name']),
            ],
            'fuelTypes' => DB::table('fuel_types')
                ->where('status', 'active')
                ->whereIn('code', array_keys(config('fuels.approved', ['F1' => true, 'UNL' => true, 'DSL' => true, 'PREM' => true])))
                ->when($search !== '', fn (Builder $query): Builder => $query->whereIn('id', $purchaseFuelTypeIds ?: [0]))
                ->orderBy('name')
                ->get(['id', 'name']),
            'garages' => DB::table('storage_locations')
                ->where('type', 'garage')
                ->where('status', 'active')
                ->when($search !== '', function (Builder $query) use ($purchaseFuelTypeIds): Builder {
                    return $query->where(function (Builder $query) use ($purchaseFuelTypeIds): void {
                        $query->whereNull('fuel_type_id')
                            ->orWhereIn('fuel_type_id', $purchaseFuelTypeIds ?: [0]);
                    });
                })
                ->orderBy('fuel_type_id')
                ->orderBy('tank_number')
                ->orderBy('name')
                ->get(['id', 'name', 'fuel_type_id', 'tank_number']),
            'garageAllocations' => $this->garageAllocationOptions(),
            'paymentStatuses' => self::PAYMENT_STATUSES,
            'purchaseIdempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedPurchaseData($request);
        $idempotencyKey = (string) ($data['idempotency_key'] ?? Str::uuid());
        unset($data['idempotency_key']);

        try {
            $result = $this->idempotencyService->run(
                $idempotencyKey,
                'purchases.store',
                (int) $request->user()->id,
                function () use ($data, $request): array {
                    $purchaseId = $this->purchaseService->createPurchase($data, (int) $request->user()->id);

                    return ['reference_id' => $purchaseId, 'response_reference' => 'purchase'];
                }
            );
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route($this->inventoryRouteName($request))
            ->with('status', $result['duplicate'] ? 'Purchase request was already submitted.' : 'Purchase record created successfully.');
    }

    public function update(Request $request, int $purchaseItem): RedirectResponse
    {
        $data = $this->validatedPurchaseData($request);

        try {
            $this->purchaseService->updatePurchase($purchaseItem, $data, (int) $request->user()->id);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route($this->inventoryRouteName($request))
            ->with('status', 'Purchase record updated successfully.');
    }

    public function withdrawalReceipt(int $haul)
    {
        $row = DB::table('hauls')
            ->join('purchases', 'purchases.id', '=', 'hauls.purchase_id')
            ->where('hauls.id', $haul)
            ->whereNull('purchases.deleted_at')
            ->first(['hauls.haul_code', 'hauls.withdrawal_receipt_path']);

        abort_unless($row && $this->isStoredWithdrawalReceipt($row->withdrawal_receipt_path), 404);

        $extension = pathinfo((string) $row->withdrawal_receipt_path, PATHINFO_EXTENSION);

        return response(Storage::disk('local')->get($row->withdrawal_receipt_path), 200, [
            'Content-Type' => match (strtolower($extension)) {
                'jpg', 'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                default => 'image/png',
            },
            'Content-Disposition' => 'inline; filename="'.Str::slug($row->haul_code).'-withdrawal.'.$extension.'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function cancel(Request $request, int $purchaseItem): RedirectResponse
    {
        try {
            $this->purchaseService->cancelPurchase($purchaseItem, (int) $request->user()->id);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        return redirect()
            ->route($this->inventoryRouteName($request))
            ->with('status', 'Purchase record cancelled successfully.');
    }

    public function storeDepot(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'depot_code' => ['required', 'string', 'max:30', Rule::unique('depots', 'depot_code')],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        DB::table('depots')->insert([
            'depot_code' => Str::upper($data['depot_code']),
            'name' => $data['name'],
            'address' => $data['address'] ?? null,
            'contact_person' => $data['contact_person'] ?? null,
            'phone' => $data['phone'] ?? null,
            'status' => $data['status'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()
            ->route($this->inventoryRouteName($request))
            ->with('status', 'Depot created successfully.');
    }

    public function storeStockIn(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['nullable', 'uuid'],
            'haul_allocation_id' => ['required', 'integer', Rule::exists('haul_allocations', 'id')],
            'storage_location_id' => ['required', 'integer', Rule::exists('storage_locations', 'id')->where(fn (Builder $query): Builder => $query->where('type', 'garage')->where('status', 'active'))],
            'quantity_liters' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'movement_date' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);

        $data['idempotency_key'] ??= (string) Str::uuid();
        $result = $this->stockInService->recordStockIn($data, (int) $request->user()->id);

        if ($result) {
            return back()->withErrors(['stock_in' => $result])->withInput();
        }

        return redirect()
            ->route($this->inventoryRouteName($request, 'stock-in'))
            ->with('status', 'Stock-In recorded successfully.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPurchaseData(Request $request): array
    {
        return $request->validate([
            'purchase_date' => ['required', 'date'],
            'idempotency_key' => ['nullable', 'uuid'],
            'depot_id' => ['required', 'integer', Rule::exists('depots', 'id')->where(fn (Builder $query): Builder => $query->where('status', 'active'))],
            'fuel_type_id' => [
                'required',
                'integer',
                ApprovedFuelType::rule(),
            ],
            'quantity_ordered_liters' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'unit_cost' => ['required', 'numeric', 'gte:0', 'max:10000'],
            'receipt_reference' => ['prohibited'],
            'receipt_file' => ['prohibited'],
            'receipt_status' => ['prohibited'],
            'payment_status' => ['required', Rule::in(self::PAYMENT_STATUSES)],
            'status' => ['nullable', Rule::in(self::PURCHASE_STATUSES)],
        ]);
    }

    private function purchaseRows(?string $search, array $filters = []): LengthAwarePaginator
    {
        return $this->purchaseService->purchaseRows($search, filters: $filters);
    }

    private function depotRows(?string $search, array $filters = []): LengthAwarePaginator
    {
        $query = DB::table('depots')
            ->when($search, fn (Builder $query): Builder => $query->where(function (Builder $query) use ($search): void {
                $query->where('depot_code', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%')
                    ->orWhere('address', 'like', '%'.$search.'%')
                    ->orWhere('contact_person', 'like', '%'.$search.'%')
                    ->orWhere('phone', 'like', '%'.$search.'%')
                    ->orWhere('status', 'like', '%'.$search.'%');
            }))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->orderBy('name')
            ->select(['depot_code', 'name', 'address', 'contact_person', 'phone', 'status']);

        $paginator = $query->paginate(25, ['*'], 'depots_page')->withQueryString();
        $transformed = collect($paginator->items())->map(fn (object $row): array => [
            $row->depot_code,
            $row->name,
            $row->address ?: 'N/A',
            $row->contact_person ?: 'N/A',
            $row->phone ?: 'N/A',
            $row->status,
        ]);
        $paginator->setCollection($transformed);

        return $paginator;
    }

    private function stockInRows(?string $search, array $filters = []): LengthAwarePaginator
    {
        return $this->stockInService->stockInRows($search, filters: $filters);
    }

    private function stockOutRows(?string $search, array $filters = []): LengthAwarePaginator
    {
        $query = $this->stockOutFinancials->query($search)
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('stock_outs.status', $status))
            ->when($filters['date'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('stock_outs.stock_out_at', $date))
            // Garage releases come from pooled tank stock, so only direct depot releases carry a depot.
            ->when($filters['depot_id'] ?? null, fn (Builder $query, mixed $depotId): Builder => $query->where('source_depot.id', (int) $depotId))
            ->when($filters['fuel_type_id'] ?? null, fn (Builder $query, mixed $fuelTypeId): Builder => $query->where('stock_outs.fuel_type_id', (int) $fuelTypeId))
            ->orderByDesc('stock_outs.stock_out_at')
            ->orderByDesc('stock_outs.id');

        $paginator = $query->paginate(25, ['*'], 'stock_out_page')->withQueryString();
        $transformed = collect($paginator->items())->map(function (object $row): array {
            $amounts = $this->stockOutFinancials->calculate($row);
            // A voided release earns nothing (any payment is a refund due), so it has no margin or profit.
            if ($row->status === 'cancelled') {
                $amounts['margin'] = null;
                $amounts['profit'] = null;
            }

            return [
                'cells' => [
                    $row->sale_code ?: $row->stock_out_code,
                    $this->formatDateTime($row->stock_out_at),
                    $row->customer_name,
                    $row->company_name,
                    $row->fuel_name,
                    $this->formatNumber($amounts['quantity']),
                    $this->formatFinancial($amounts['unit_cost']),
                    $this->formatFinancial($amounts['total_cost']),
                    $this->formatNumber($amounts['unit_price']),
                    $this->formatNumber($amounts['total_price']),
                    $this->formatNumber($amounts['total_paid']),
                    $this->stockOutSourceLabel($row),
                    $this->label($row->status),
                    $row->status === 'cancelled' ? '—' : $this->formatFinancial($amounts['margin']),
                    $row->status === 'cancelled' ? '—' : $this->formatFinancial($amounts['profit']),
                ],
                'class' => $this->rowClass($row->status),
                'margin' => $amounts['margin'],
                'profit' => $amounts['profit'],
            ];
        });
        $paginator->setCollection($transformed);

        return $paginator;
    }

    private function purchaseItemForUpdate(int $purchaseItem): ?object
    {
        return DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where('purchase_items.id', $purchaseItem)
            ->whereNull('purchases.deleted_at')
            ->first([
                'purchase_items.id',
                'purchase_items.purchase_id',
                'purchase_items.fuel_type_id',
                'purchase_items.quantity_ordered_liters',
                'purchase_items.quantity_hauled_liters',
                'purchase_items.unit_cost',
                'purchases.depot_id',
                'purchases.purchase_date',
            ]);
    }

    private function garageAllocationForUpdate(int $allocationId): ?object
    {
        return DB::table('haul_allocations')
            ->join('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
            ->join('purchase_items', 'purchase_items.id', '=', 'hauls.purchase_item_id')
            ->join('purchases', 'purchases.id', '=', 'hauls.purchase_id')
            ->leftJoin('storage_locations', 'storage_locations.id', '=', 'haul_allocations.storage_location_id')
            ->join('depots', 'depots.id', '=', 'hauls.depot_id')
            ->where('haul_allocations.id', $allocationId)
            ->where('haul_allocations.destination_type', 'garage')
            ->whereNotNull('haul_allocations.storage_location_id')
            ->where('haul_allocations.status', '!=', 'cancelled')
            ->where('storage_locations.type', 'garage')
            ->where('storage_locations.status', 'active')
            ->where('hauls.status', 'completed')
            ->whereNull('purchases.deleted_at')
            ->whereColumn('hauls.purchase_id', 'purchase_items.purchase_id')
            ->whereColumn('hauls.depot_id', 'purchases.depot_id')
            ->whereColumn('hauls.fuel_type_id', 'purchase_items.fuel_type_id')
            ->whereColumn('haul_allocations.fuel_type_id', 'hauls.fuel_type_id')
            ->where(function (Builder $query): void {
                $query->whereNull('storage_locations.fuel_type_id')
                    ->orWhereColumn('storage_locations.fuel_type_id', 'haul_allocations.fuel_type_id');
            })
            ->lockForUpdate()
            ->first([
                'haul_allocations.id',
                'haul_allocations.haul_id',
                'haul_allocations.storage_location_id',
                'haul_allocations.fuel_type_id',
                'haul_allocations.quantity_liters',
                'haul_allocations.status',
                'hauls.quantity_liters as haul_quantity_liters',
                'purchase_items.unit_cost',
            ]);
    }

    private function remainingReceivableForAllocation(object $allocation): float
    {
        $received = (float) DB::table('inventory_movements')
            ->where('reference_type', self::STOCK_IN_REFERENCE_TYPE)
            ->where('reference_id', $allocation->id)
            ->where('direction', 'in')
            ->where('movement_type', 'stock_in')
            ->sum('quantity_liters');

        return round(max(0, (float) $allocation->quantity_liters - $received), 2);
    }

    private function duplicateStockInExists(object $allocation, int $garageId, float $quantity, string $movementDate): bool
    {
        return DB::table('inventory_movements')
            ->where('reference_type', self::STOCK_IN_REFERENCE_TYPE)
            ->where('reference_id', $allocation->id)
            ->where('storage_location_id', $garageId)
            ->where('fuel_type_id', $allocation->fuel_type_id)
            ->where('direction', 'in')
            ->where('movement_type', 'stock_in')
            ->where('quantity_liters', $quantity)
            ->where('movement_date', $movementDate)
            ->lockForUpdate()
            ->first(['id']) !== null;
    }

    /**
     * @param  Collection<int, object>  $movements
     * @return array<string, string>
     */
    private function stockInReferenceLabels(Collection $movements): array
    {
        $allocationIds = $movements
            ->filter(fn (object $row): bool => $row->reference_type === self::STOCK_IN_REFERENCE_TYPE)
            ->pluck('reference_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($allocationIds->isEmpty()) {
            return [];
        }

        return DB::table('haul_allocations')
            ->join('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
            ->join('purchases', 'purchases.id', '=', 'hauls.purchase_id')
            ->leftJoin('depots', 'depots.id', '=', 'hauls.depot_id')
            ->whereIn('haul_allocations.id', $allocationIds->all())
            ->get([
                'haul_allocations.id',
                'purchases.purchase_code',
                'hauls.haul_code',
                'depots.name as depot_name',
            ])
            ->mapWithKeys(fn (object $row): array => [
                $this->referenceKey(self::STOCK_IN_REFERENCE_TYPE, (int) $row->id) => $row->purchase_code.' / '.$row->haul_code.' / '.($row->depot_name ?: 'Depot'),
            ])
            ->all();
    }

    private function inventoryLinkStatus(float $garageAllocated, float $received, float $directAllocated): string
    {
        if ($garageAllocated <= 0 && $directAllocated <= 0) {
            return 'not_allocated';
        }

        if ($garageAllocated <= 0 && $directAllocated > 0) {
            return 'direct_to_client';
        }

        if ($received <= 0) {
            return 'awaiting_garage_receipt';
        }

        if (round($received, 2) < round($garageAllocated, 2)) {
            return 'partially_received';
        }

        return $directAllocated > 0 ? 'garage_received_with_direct' : 'garage_received';
    }

    private function garageAllocationOptions()
    {
        $received = DB::table('inventory_movements')
            ->where('reference_type', self::STOCK_IN_REFERENCE_TYPE)
            ->where('direction', 'in')
            ->where('movement_type', 'stock_in')
            ->selectRaw('reference_id, COALESCE(SUM(quantity_liters), 0) as received_liters')
            ->groupBy('reference_id');

        return DB::table('haul_allocations')
            ->join('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
            ->join('purchases', 'purchases.id', '=', 'hauls.purchase_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'haul_allocations.fuel_type_id')
            ->leftJoin('storage_locations', 'storage_locations.id', '=', 'haul_allocations.storage_location_id')
            ->join('depots', 'depots.id', '=', 'hauls.depot_id')
            ->leftJoinSub($received, 'received', 'received.reference_id', '=', 'haul_allocations.id')
            ->where('haul_allocations.destination_type', 'garage')
            ->where('haul_allocations.status', '!=', 'cancelled')
            ->whereIn('hauls.status', ['lifted', 'completed'])
            ->whereNull('purchases.deleted_at')
            ->selectRaw('haul_allocations.id, haul_allocations.storage_location_id, hauls.purchase_item_id, hauls.haul_code, hauls.scheduled_at, purchases.purchase_code, fuel_types.id as fuel_type_id, fuel_types.name as fuel_name, depots.name as depot_name, storage_locations.name as garage_name, haul_allocations.quantity_liters, COALESCE(received.received_liters, 0) as received_liters')
            ->orderByDesc('hauls.scheduled_at')
            ->get()
            ->filter(fn (object $row): bool => ((float) $row->quantity_liters - (float) $row->received_liters) > 0)
            ->values()
            ->map(function (object $row): object {
                $row->remaining_liters = round((float) $row->quantity_liters - (float) $row->received_liters, 2);
                $row->receipt_status = (float) $row->received_liters > 0 ? 'Partially Received' : 'Pending Receipt';
                $row->label = $row->haul_code.' / '.$row->purchase_code.' / '.$row->fuel_name.' / '.($row->garage_name ?: 'Tank assignment required').' / '.$this->formatLiters($row->remaining_liters);

                return $row;
            });
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

    private function lineTotal(mixed $quantity, mixed $unitCost): float
    {
        return round(((float) $quantity) * ((float) $unitCost), 2);
    }

    private function isStoredWithdrawalReceipt(?string $path): bool
    {
        return is_string($path)
            && str_starts_with($path, 'withdrawal-receipts/')
            && ! str_contains($path, '..')
            && Storage::disk('local')->exists($path);
    }

    private function withdrawalStatus(int $count): string
    {
        return $count > 0 ? $count.' Uploaded' : 'No Withdrawal';
    }

    private function itemStatus(float $hauled, float $ordered): string
    {
        return match (true) {
            $hauled <= 0 => 'unlifted',
            $hauled >= $ordered => 'lifted',
            default => 'partial',
        };
    }

    private function stockOutSourceLabel(object $row): string
    {
        if ($row->source_type === 'depot') {
            return 'Depot'.($row->depot_name ? ' — '.$row->depot_name : '');
        }

        $lot = $row->lot_id ? ' · '.($row->lot_code ?: 'Opening stock') : '';

        return 'Garage'.($row->garage_name ? ' — '.$row->garage_name : '').$lot;
    }

    private function rowClass(?string $status): string
    {
        return match ($status) {
            'unpaid', 'cancelled' => 'row-danger',
            'partial' => 'row-warning',
            'paid' => 'row-success',
            default => '',
        };
    }

    private function label(?string $value): string
    {
        return ucwords(str_replace('_', ' ', (string) $value));
    }

    private function referenceKey(?string $type, int $id): string
    {
        return ((string) $type).':'.$id;
    }

    private function inventoryRouteName(Request $request, ?string $state = null): string
    {
        $prefix = str_starts_with((string) $request->route()?->getName(), 'admin.')
            ? 'admin.inventory'
            : 'inventory-officer.inventory';

        return $state ? $prefix.'.'.$state : $prefix;
    }

    private function formatDate(mixed $date): string
    {
        return $date ? date('n/j/Y', strtotime((string) $date)) : 'N/A';
    }

    private function formatDateTime(mixed $date): string
    {
        return $date ? date('n/j/Y h:i A', strtotime((string) $date)) : 'N/A';
    }

    private function formatNumber(mixed $value): string
    {
        return number_format((float) ($value ?? 0), 2);
    }

    private function formatFinancial(float|int|null $value): string
    {
        return $value === null ? 'Unavailable' : $this->formatNumber($value);
    }

    private function formatLiters(mixed $value): string
    {
        return $this->formatNumber($value).' L';
    }
}
