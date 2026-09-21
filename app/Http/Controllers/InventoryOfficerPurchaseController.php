<?php

namespace App\Http\Controllers;

use App\Rules\ApprovedFuelType;
use App\Services\DashboardSummaryService;
use App\Services\GarageTankService;
use App\Services\IdempotencyService;
use App\Services\InventoryCostService;
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
        private readonly InventoryCostService $inventoryCost,
        private readonly StockOutFinancialService $stockOutFinancials
    ) {}

    private const PURCHASE_STATUSES = ['draft', 'ordered', 'partially_hauled', 'hauled', 'cancelled'];

    private const PAYMENT_STATUSES = ['paid', 'partial', 'unpaid'];

    private const STOCK_IN_REFERENCE_TYPE = 'haul_allocation';

    private const STOCK_OUT_REFERENCE_TYPE = 'stock_out';

    private const ELIGIBLE_SALE_STATUSES = ['confirmed', 'partially_paid', 'paid', 'unpaid'];

    public function index(Request $request, DashboardSummaryService $dashboardSummary, GarageTankService $garageTanks, string $state = 'purchases'): View
    {
        $garageTanks->ensureForActiveFuelTypes();

        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $search = trim((string) ($data['search'] ?? ''));
        $activeTab = in_array($state, ['purchases', 'stock-in', 'stock-out', 'depots'], true) ? $state : 'purchases';
        $purchases = $this->purchaseRows($search === '' ? null : $search);
        $stockIn = $this->stockInRows($search === '' ? null : $search);
        $stockOut = $this->stockOutRows($search === '' ? null : $search);
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
            'depotRows' => $this->depotRows($search === '' ? null : $search),
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
            'stockInPurchases' => $activeTab === 'stock-in'
                ? $this->stockInPurchaseRows($search === '' ? null : $search)
                : new LengthAwarePaginator([], 0, 25),
            'stockOutSaleItems' => $this->stockOutSaleItemOptions(),
            'directDeliveryAllocations' => $this->directDepotReleaseAllocationOptions(),
            'paymentStatuses' => self::PAYMENT_STATUSES,
            'purchaseIdempotencyKey' => (string) Str::uuid(),
            'stockOutIdempotencyKey' => (string) Str::uuid(),
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

    public function storeStockOut(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'source_type' => ['required', Rule::in(['garage', 'depot'])],
            'sale_item_id' => ['required', 'integer', Rule::exists('sale_items', 'id')],
            'storage_location_id' => ['required_if:source_type,garage', 'nullable', 'integer', Rule::exists('storage_locations', 'id')->where(fn (Builder $query): Builder => $query->where('type', 'garage')->where('status', 'active'))],
            'haul_allocation_id' => ['required_if:source_type,depot', 'nullable', 'integer', Rule::exists('haul_allocations', 'id')],
            'quantity_liters' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'stock_out_at' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:1000'],
        ]);
        $sessionKey = 'stock_outs.created.'.((string) $data['idempotency_key']);

        if ($request->session()->has($sessionKey)) {
            return redirect()
                ->route($this->inventoryRouteName($request, 'stock-out'))
                ->with('status', 'Stock-Out record was already submitted.');
        }

        try {
            $idempotency = $this->idempotencyService->run(
                (string) $data['idempotency_key'],
                'stock_outs.store',
                (int) $request->user()->id,
                function () use ($request, $data): array {
                    $saleItem = $this->saleItemForStockOut((int) $data['sale_item_id']);

                    if (! $saleItem) {
                        throw new \RuntimeException('The selected sale is not eligible for stock-out.');
                    }

                    $quantity = round((float) $data['quantity_liters'], 2);
                    $remaining = round((float) $saleItem->quantity_liters - (float) $saleItem->fulfilled_quantity_liters, 2);

                    if ($quantity > $remaining) {
                        throw new \RuntimeException('Quantity released cannot exceed the remaining sale quantity.');
                    }

                    if ($data['source_type'] === 'garage') {
                        $error = $this->releaseFromGarage($request, $data, $saleItem, $quantity);
                    } else {
                        $error = $this->releaseDirectFromDepot($request, $data, $saleItem, $quantity);
                    }

                    if ($error) {
                        throw new \RuntimeException($error);
                    }

                    return [
                        'reference_id' => (int) $saleItem->id,
                        'response_reference' => 'stock_out',
                    ];
                }
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['stock_out' => $e->getMessage()])->withInput();
        }

        if ($idempotency['duplicate']) {
            return redirect()
                ->route($this->inventoryRouteName($request, 'stock-out'))
                ->with('status', 'Stock-Out record was already submitted.');
        }

        $request->session()->put($sessionKey, true);

        return redirect()
            ->route($this->inventoryRouteName($request, 'stock-out'))
            ->with('status', 'Stock-Out recorded successfully.');
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

    private function purchaseRows(?string $search): LengthAwarePaginator
    {
        return $this->purchaseService->purchaseRows($search);
    }

    private function depotRows(?string $search): LengthAwarePaginator
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

    private function stockInPurchaseRows(?string $search): LengthAwarePaginator
    {
        $assigned = DB::table('hauls')
            ->where('status', '!=', 'cancelled')
            ->selectRaw('purchase_item_id, COALESCE(SUM(quantity_liters), 0) as scheduled_liters')
            ->groupBy('purchase_item_id');

        $received = DB::table('inventory_movements')
            ->join('haul_allocations', function ($join): void {
                $join->on('haul_allocations.id', '=', 'inventory_movements.reference_id')
                    ->where('inventory_movements.reference_type', self::STOCK_IN_REFERENCE_TYPE);
            })
            ->join('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
            ->where('inventory_movements.direction', 'in')
            ->where('inventory_movements.movement_type', 'stock_in')
            ->where('haul_allocations.status', '!=', 'cancelled')
            ->where('hauls.status', '!=', 'cancelled')
            ->selectRaw('hauls.purchase_item_id, COALESCE(SUM(inventory_movements.quantity_liters), 0) as received_liters')
            ->groupBy('hauls.purchase_item_id');

        return DB::table('purchase_items')
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->join('depots', 'depots.id', '=', 'purchases.depot_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'purchase_items.fuel_type_id')
            ->leftJoinSub($assigned, 'assigned', 'assigned.purchase_item_id', '=', 'purchase_items.id')
            ->leftJoinSub($received, 'received', 'received.purchase_item_id', '=', 'purchase_items.id')
            ->whereNull('purchases.deleted_at')
            ->where('purchases.status', '!=', 'cancelled')
            ->when($search, fn (Builder $query): Builder => $query->where(function (Builder $query) use ($search): void {
                $query->where('purchases.purchase_code', 'like', '%'.$search.'%')
                    ->orWhere('depots.name', 'like', '%'.$search.'%')
                    ->orWhere('fuel_types.name', 'like', '%'.$search.'%');
            }))
            ->orderByDesc('purchases.purchase_date')
            ->orderByDesc('purchase_items.id')
            ->paginate(25, [
                'purchases.purchase_code',
                'purchases.purchase_date',
                'purchases.workflow_status',
                'depots.name as depot_name',
                'fuel_types.name as fuel_name',
                'purchase_items.quantity_ordered_liters',
                DB::raw('COALESCE(assigned.scheduled_liters, 0) as scheduled_liters'),
                DB::raw('COALESCE(received.received_liters, 0) as received_liters'),
            ], 'stock_in_purchases_page')
            ->withQueryString();
    }

    private function stockInRows(?string $search): LengthAwarePaginator
    {
        return $this->stockInService->stockInRows($search);
    }

    private function stockOutRows(?string $search): LengthAwarePaginator
    {
        $query = $this->stockOutFinancials->query($search)
            ->orderByDesc('stock_outs.stock_out_at')
            ->orderByDesc('stock_outs.id');

        $paginator = $query->paginate(25, ['*'], 'stock_out_page')->withQueryString();
        $transformed = collect($paginator->items())->map(function (object $row): array {
            $amounts = $this->stockOutFinancials->calculate($row);

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
                    $row->source_type === 'depot' ? 'Depot' : 'Garage',
                    $this->formatFinancial($amounts['profit']),
                ],
                'class' => $this->rowClass($row->status),
                'profit' => $amounts['profit'],
            ];
        });
        $paginator->setCollection($transformed);

        return $paginator;
    }

    private function saleItemForStockOut(int $saleItemId): ?object
    {
        return DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->where('sale_items.id', $saleItemId)
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', self::ELIGIBLE_SALE_STATUSES)
            ->lockForUpdate()
            ->first([
                'sale_items.id',
                'sale_items.sale_id',
                'sale_items.fuel_type_id',
                'sale_items.quantity_liters',
                'sale_items.fulfilled_quantity_liters',
                'sale_items.unit_price',
                'sales.sale_code',
                'sales.customer_id',
                'customers.name as customer_name',
                'customers.company_name',
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function releaseFromGarage(Request $request, array $data, object $saleItem, float $quantity): ?string
    {
        $garageId = (int) $data['storage_location_id'];
        $garage = DB::table('storage_locations')
            ->where('id', $garageId)
            ->where('type', 'garage')
            ->where('status', 'active')
            ->lockForUpdate()
            ->first(['id', 'fuel_type_id', 'tank_number']);

        if (! $garage) {
            return 'The selected garage tank is invalid.';
        }

        if ($garage->fuel_type_id && (int) $garage->fuel_type_id !== (int) $saleItem->fuel_type_id) {
            return 'The selected garage tank does not match the sale fuel type.';
        }

        $available = $this->availableGarageStockForUpdate($garageId, (int) $saleItem->fuel_type_id);

        if ($quantity > $available) {
            return 'Garage inventory is insufficient for this stock-out.';
        }

        $unitCost = $this->inventoryCost->currentUnitCostForUpdate($garageId, (int) $saleItem->fuel_type_id);

        if ($unitCost === null) {
            return 'The selected garage inventory has no verifiable cost basis.';
        }

        if ($this->duplicateGarageStockOutExists($saleItem, $garageId, $quantity, (string) $data['stock_out_at'])) {
            return 'This stock-out release has already been recorded.';
        }

        if (! $this->increaseFulfilledQuantity($saleItem, $quantity)) {
            return 'Quantity released cannot exceed the remaining sale quantity.';
        }

        $prepared = $this->preparedStockOutForSaleItem($saleItem);
        $stockOutId = null;

        if ($prepared && $this->sameQuantity((float) $prepared->quantity_liters, $quantity)) {
            DB::table('stock_outs')
                ->where('id', $prepared->id)
                ->update([
                    'storage_location_id' => $garageId,
                    'source_type' => 'garage',
                    'quantity_liters' => $quantity,
                    'unit_cost' => $unitCost,
                    'stock_out_at' => $data['stock_out_at'],
                    'status' => 'released',
                    'created_by' => $request->user()->id,
                    'updated_at' => now(),
                ]);

            $stockOutId = (int) $prepared->id;
        } else {
            $this->reducePreparedStockOut($prepared, $quantity);

            $stockOutId = $this->idempotencyService->retryOnCollision('stock_out_code', function () use ($saleItem, $garageId, $quantity, $unitCost, $data, $request): int {
                return (int) DB::table('stock_outs')->insertGetId([
                    'stock_out_code' => $this->nextCode('stock_outs', 'stock_out_code', 'STO'),
                    'sale_id' => $saleItem->sale_id,
                    'sale_item_id' => $saleItem->id,
                    'customer_id' => $saleItem->customer_id,
                    'fuel_type_id' => $saleItem->fuel_type_id,
                    'storage_location_id' => $garageId,
                    'source_type' => 'garage',
                    'quantity_liters' => $quantity,
                    'unit_cost' => $unitCost,
                    'stock_out_at' => $data['stock_out_at'],
                    'status' => 'released',
                    'created_by' => $request->user()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        }

        $movementId = $this->idempotencyService->retryOnCollision('movement_code', function () use ($garageId, $saleItem, $quantity, $unitCost, $stockOutId, $data, $request): int {
            return (int) DB::table('inventory_movements')->insertGetId([
                'movement_code' => $this->nextCode('inventory_movements', 'movement_code', 'MOV'),
                'storage_location_id' => $garageId,
                'fuel_type_id' => $saleItem->fuel_type_id,
                'movement_type' => 'stock_out',
                'direction' => 'out',
                'quantity_liters' => $quantity,
                'unit_cost' => $unitCost,
                'reference_type' => self::STOCK_OUT_REFERENCE_TYPE,
                'reference_id' => $stockOutId,
                'movement_date' => $data['stock_out_at'],
                'remarks' => $data['remarks'] ?? null,
                'created_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        DB::table('stock_outs')
            ->where('id', $stockOutId)
            ->whereNull('inventory_movement_id')
            ->update([
                'inventory_movement_id' => $movementId,
                'updated_at' => now(),
            ]);

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function releaseDirectFromDepot(Request $request, array $data, object $saleItem, float $quantity): ?string
    {
        $allocation = DB::table('haul_allocations')
            ->join('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
            ->join('purchase_items', 'purchase_items.id', '=', 'hauls.purchase_item_id')
            ->join('purchases', 'purchases.id', '=', 'hauls.purchase_id')
            ->where('haul_allocations.id', (int) $data['haul_allocation_id'])
            ->where('haul_allocations.destination_type', 'customer')
            ->where('haul_allocations.sale_id', $saleItem->sale_id)
            ->where('haul_allocations.customer_id', $saleItem->customer_id)
            ->where('haul_allocations.fuel_type_id', $saleItem->fuel_type_id)
            ->where('haul_allocations.status', '!=', 'cancelled')
            ->where('hauls.status', 'completed')
            ->whereNull('purchases.deleted_at')
            ->whereColumn('hauls.purchase_id', 'purchase_items.purchase_id')
            ->whereColumn('hauls.depot_id', 'purchases.depot_id')
            ->whereColumn('hauls.fuel_type_id', 'purchase_items.fuel_type_id')
            ->whereColumn('haul_allocations.fuel_type_id', 'hauls.fuel_type_id')
            ->lockForUpdate()
            ->first([
                'haul_allocations.id',
                'haul_allocations.haul_id',
                'haul_allocations.quantity_liters',
                'haul_allocations.status',
                'hauls.quantity_liters as haul_quantity_liters',
                'hauls.depot_id',
                'hauls.truck_id',
                'hauls.driver_user_id',
                'purchase_items.unit_cost',
            ]);

        if (! $allocation) {
            return 'The selected direct depot release source does not match this sale.';
        }

        if (! $this->haulAllocationsAreWithinQuantity((int) $allocation->haul_id, (float) $allocation->haul_quantity_liters)) {
            return 'The selected haul has invalid allocation quantities.';
        }

        $committed = (float) DB::table('stock_outs')
            ->where('haul_allocation_id', $allocation->id)
            ->where('source_type', 'depot')
            ->where('status', '!=', 'cancelled')
            ->lockForUpdate()
            ->selectRaw('COALESCE(SUM(quantity_liters), 0) as committed_liters')
            ->value('committed_liters');

        $allocationRemaining = round((float) $allocation->quantity_liters - $committed, 2);

        if ($quantity > $allocationRemaining) {
            return 'Quantity released cannot exceed the remaining direct depot allocation.';
        }

        if ($this->duplicateDirectDepotReleaseExists($allocation, $saleItem, $quantity, (string) $data['stock_out_at'])) {
            return 'This direct depot release has already been recorded.';
        }

        $saleRemaining = round((float) $saleItem->quantity_liters - (float) $saleItem->fulfilled_quantity_liters, 2);

        if ($quantity > $saleRemaining) {
            return 'Quantity released cannot exceed the remaining sale quantity.';
        }

        if (! $this->increaseFulfilledQuantity($saleItem, $quantity)) {
            return 'Quantity released cannot exceed the remaining sale quantity.';
        }

        $prepared = $this->preparedStockOutForSaleItem($saleItem);

        if ($prepared && $this->sameQuantity((float) $prepared->quantity_liters, $quantity)) {
            DB::table('stock_outs')
                ->where('id', $prepared->id)
                ->update([
                    'source_type' => 'depot',
                    'storage_location_id' => null,
                    'depot_id' => $allocation->depot_id,
                    'haul_allocation_id' => $allocation->id,
                    'quantity_liters' => $quantity,
                    'unit_cost' => $allocation->unit_cost,
                    'stock_out_at' => $data['stock_out_at'],
                    'status' => 'released',
                    'created_by' => $request->user()->id,
                    'updated_at' => now(),
                ]);
        } else {
            $this->reducePreparedStockOut($prepared, $quantity);

            $this->idempotencyService->retryOnCollision('stock_out_code', function () use ($saleItem, $allocation, $quantity, $data, $request): void {
                DB::table('stock_outs')->insert([
                    'stock_out_code' => $this->nextCode('stock_outs', 'stock_out_code', 'STO'),
                    'sale_id' => $saleItem->sale_id,
                    'sale_item_id' => $saleItem->id,
                    'customer_id' => $saleItem->customer_id,
                    'fuel_type_id' => $saleItem->fuel_type_id,
                    'source_type' => 'depot',
                    'storage_location_id' => null,
                    'depot_id' => $allocation->depot_id,
                    'haul_allocation_id' => $allocation->id,
                    'quantity_liters' => $quantity,
                    'unit_cost' => $allocation->unit_cost,
                    'stock_out_at' => $data['stock_out_at'],
                    'status' => 'released',
                    'created_by' => $request->user()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        }

        if (round($allocationRemaining - $quantity, 2) <= 0) {
            DB::table('haul_allocations')
                ->where('id', $allocation->id)
                ->update([
                    'status' => 'delivered',
                    'updated_at' => now(),
                ]);
        }

        return null;
    }

    private function availableGarageStockForUpdate(int $garageId, int $fuelTypeId): float
    {
        DB::table('inventory_movements')
            ->where('storage_location_id', $garageId)
            ->where('fuel_type_id', $fuelTypeId)
            ->whereNotExists($this->cancelledStockOutExists())
            ->whereNotExists($this->cancelledHaulAllocationExists())
            ->lockForUpdate()
            ->get(['id']);

        $balance = DB::table('inventory_movements')
            ->where('storage_location_id', $garageId)
            ->where('fuel_type_id', $fuelTypeId)
            ->whereNotExists($this->cancelledStockOutExists())
            ->whereNotExists($this->cancelledHaulAllocationExists())
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN quantity_liters ELSE -quantity_liters END), 0) as balance")
            ->value('balance');

        return round((float) $balance, 2);
    }

    private function increaseFulfilledQuantity(object $saleItem, float $quantity): bool
    {
        return DB::table('sale_items')
            ->where('id', $saleItem->id)
            ->where('fulfilled_quantity_liters', '<=', DB::raw('quantity_liters - '.$quantity))
            ->update([
                'fulfilled_quantity_liters' => DB::raw('fulfilled_quantity_liters + '.$quantity),
                'updated_at' => now(),
            ]) === 1;
    }

    private function duplicateGarageStockOutExists(object $saleItem, int $garageId, float $quantity, string $stockOutAt): bool
    {
        return DB::table('stock_outs')
            ->where('sale_id', $saleItem->sale_id)
            ->where('sale_item_id', $saleItem->id)
            ->where('customer_id', $saleItem->customer_id)
            ->where('fuel_type_id', $saleItem->fuel_type_id)
            ->where('storage_location_id', $garageId)
            ->where('quantity_liters', $quantity)
            ->where('stock_out_at', $stockOutAt)
            ->where('status', '!=', 'cancelled')
            ->lockForUpdate()
            ->first(['id']) !== null;
    }

    private function preparedStockOutForSaleItem(object $saleItem): ?object
    {
        return DB::table('stock_outs')
            ->where('sale_id', $saleItem->sale_id)
            ->where('sale_item_id', $saleItem->id)
            ->where('customer_id', $saleItem->customer_id)
            ->where('fuel_type_id', $saleItem->fuel_type_id)
            ->where('status', 'prepared')
            ->lockForUpdate()
            ->orderBy('id')
            ->first(['id', 'quantity_liters']);
    }

    private function reducePreparedStockOut(?object $prepared, float $quantity): void
    {
        if (! $prepared) {
            return;
        }

        $remaining = round((float) $prepared->quantity_liters - $quantity, 2);

        DB::table('stock_outs')
            ->where('id', $prepared->id)
            ->update([
                'quantity_liters' => max(0, $remaining),
                'status' => $remaining > 0 ? 'prepared' : 'cancelled',
                'updated_at' => now(),
            ]);
    }

    private function sameQuantity(float $left, float $right): bool
    {
        return abs(round($left, 2) - round($right, 2)) < 0.01;
    }

    private function duplicateDirectDepotReleaseExists(object $allocation, object $saleItem, float $quantity, string $stockOutAt): bool
    {
        return DB::table('stock_outs')
            ->where('sale_id', $saleItem->sale_id)
            ->where('sale_item_id', $saleItem->id)
            ->where('customer_id', $saleItem->customer_id)
            ->where('fuel_type_id', $saleItem->fuel_type_id)
            ->where('source_type', 'depot')
            ->where('haul_allocation_id', $allocation->id)
            ->where('quantity_liters', $quantity)
            ->where('stock_out_at', $stockOutAt)
            ->where('status', '!=', 'cancelled')
            ->lockForUpdate()
            ->first(['id']) !== null;
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

    private function cancelledStockOutExists(): \Closure
    {
        return function (Builder $query): void {
            $query->selectRaw('1')
                ->from('stock_outs')
                ->whereColumn('stock_outs.id', 'inventory_movements.reference_id')
                ->where('inventory_movements.reference_type', self::STOCK_OUT_REFERENCE_TYPE)
                ->where('stock_outs.status', 'cancelled');
        };
    }

    private function cancelledHaulAllocationExists(): \Closure
    {
        return function (Builder $query): void {
            $query->selectRaw('1')
                ->from('haul_allocations')
                ->whereColumn('haul_allocations.id', 'inventory_movements.reference_id')
                ->where('inventory_movements.reference_type', self::STOCK_IN_REFERENCE_TYPE)
                ->where('haul_allocations.status', 'cancelled');
        };
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
            ->where('hauls.status', 'completed')
            ->whereNull('purchases.deleted_at')
            ->selectRaw('haul_allocations.id, haul_allocations.storage_location_id, hauls.haul_code, hauls.scheduled_at, purchases.purchase_code, fuel_types.id as fuel_type_id, fuel_types.name as fuel_name, depots.name as depot_name, storage_locations.name as garage_name, haul_allocations.quantity_liters, COALESCE(received.received_liters, 0) as received_liters')
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

    private function stockOutSaleItemOptions()
    {
        $garageStock = $this->activeInventoryBalancesByFuelQuery();

        return DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'sale_items.fuel_type_id')
            ->leftJoinSub($garageStock, 'garage_stock', 'garage_stock.fuel_type_id', '=', 'sale_items.fuel_type_id')
            ->whereNull('sales.deleted_at')
            ->whereIn('sales.status', self::ELIGIBLE_SALE_STATUSES)
            ->selectRaw('sale_items.id, sale_items.sale_id, sale_items.fuel_type_id, sales.sale_code, customers.name as customer_name, customers.company_name, fuel_types.name as fuel_name, sale_items.quantity_liters, sale_items.fulfilled_quantity_liters, COALESCE(garage_stock.available_liters, 0) as available_liters')
            ->orderByDesc('sales.sale_date')
            ->orderByDesc('sales.id')
            ->get()
            ->filter(fn (object $row): bool => ((float) $row->quantity_liters - (float) $row->fulfilled_quantity_liters) > 0)
            ->values()
            ->map(function (object $row): object {
                $row->remaining_liters = round((float) $row->quantity_liters - (float) $row->fulfilled_quantity_liters, 2);
                $row->label = $row->sale_code.' / '.$row->company_name.' / '.$row->fuel_name.' / remaining '.$this->formatLiters($row->remaining_liters).' / garage '.$this->formatLiters($row->available_liters);

                return $row;
            });
    }

    private function activeInventoryBalancesByFuelQuery(): Builder
    {
        return DB::table('inventory_movements')
            ->whereIn('direction', ['in', 'out'])
            ->whereNotExists($this->cancelledStockOutExists())
            ->whereNotExists($this->cancelledHaulAllocationExists())
            ->selectRaw("fuel_type_id, COALESCE(SUM(CASE WHEN direction = 'in' THEN quantity_liters WHEN direction = 'out' THEN -quantity_liters ELSE 0 END), 0) as available_liters")
            ->groupBy('fuel_type_id');
    }

    private function directDepotReleaseAllocationOptions()
    {
        $delivered = DB::table('stock_outs')
            ->where('source_type', 'depot')
            ->where('status', '!=', 'cancelled')
            ->whereNotNull('haul_allocation_id')
            ->selectRaw('haul_allocation_id, COALESCE(SUM(quantity_liters), 0) as delivered_liters')
            ->groupBy('haul_allocation_id');

        return DB::table('haul_allocations')
            ->join('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
            ->join('sales', 'sales.id', '=', 'haul_allocations.sale_id')
            ->join('customers', 'customers.id', '=', 'haul_allocations.customer_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'haul_allocations.fuel_type_id')
            ->leftJoinSub($delivered, 'delivered', 'delivered.haul_allocation_id', '=', 'haul_allocations.id')
            ->where('haul_allocations.destination_type', 'customer')
            ->whereNotNull('haul_allocations.sale_id')
            ->where('haul_allocations.status', '!=', 'cancelled')
            ->where('hauls.status', '!=', 'cancelled')
            ->whereNull('sales.deleted_at')
            ->selectRaw('haul_allocations.id, haul_allocations.sale_id, sales.sale_code, customers.company_name, fuel_types.name as fuel_name, haul_allocations.quantity_liters, COALESCE(delivered.delivered_liters, 0) as delivered_liters')
            ->orderByDesc('hauls.scheduled_at')
            ->get()
            ->filter(fn (object $row): bool => ((float) $row->quantity_liters - (float) $row->delivered_liters) > 0)
            ->values()
            ->map(function (object $row): object {
                $row->remaining_liters = round((float) $row->quantity_liters - (float) $row->delivered_liters, 2);
                $row->label = $row->sale_code.' / '.$row->company_name.' / '.$row->fuel_name.' / depot remaining '.$this->formatLiters($row->remaining_liters);

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

    private function nextCode(string $table, string $column, string $prefix): string
    {
        do {
            $code = $prefix.'-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        } while (DB::table($table)->where($column, $code)->exists());

        return $code;
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
