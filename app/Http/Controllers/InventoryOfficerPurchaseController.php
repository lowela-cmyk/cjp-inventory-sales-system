<?php

namespace App\Http\Controllers;

use App\Rules\ApprovedFuelType;
use App\Services\DashboardSummaryService;
use App\Services\IdempotencyService;
use App\Services\PurchaseService;
use App\Services\StockInService;
use App\Services\StockOutFinancialService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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
        private readonly StockOutFinancialService $stockOutFinancials,
        private readonly StockInService $stockIns
    ) {}

    private const PURCHASE_STATUSES = ['draft', 'ordered', 'partially_hauled', 'hauled', 'cancelled'];

    private const PAYMENT_STATUSES = ['paid', 'partial', 'unpaid'];

    private const STOCK_OUT_REFERENCE_TYPE = 'stock_out';

    private const TABS = ['purchases', 'stock-in', 'stock-out', 'depots'];

    /** Status filter options per inventory tab (tanks are retired; Stock-In is one record per purchase). */
    private const TAB_FILTER_STATUSES = [
        'purchases' => ['pending', 'scheduled', 'in_transit', 'partially_lifted', 'completed', 'cancelled'],
        'stock-in' => StockInService::STATUSES,
        'stock-out' => ['released', 'cancelled'],
        'depots' => ['active', 'inactive'],
    ];

    public function index(Request $request, DashboardSummaryService $dashboardSummary, string $state = 'purchases'): View
    {
        $activeTab = in_array($state, self::TABS, true) ? $state : 'purchases';
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(self::TAB_FILTER_STATUSES[$activeTab])],
            'date' => ['nullable', 'date'],
            'depot_id' => ['nullable', 'integer', Rule::exists('depots', 'id')],
            'fuel_type_id' => ['nullable', 'integer', Rule::exists('fuel_types', 'id')],
            'delivery_type' => ['nullable', Rule::in(array_keys(SalesOfficerCustomerController::DELIVERY_TYPES))],
        ]);

        $search = trim((string) ($data['search'] ?? ''));
        // Filters belong to the tab whose toolbar submitted them; the other tabs stay unfiltered.
        $filters = [
            'status' => $data['status'] ?? null,
            'date' => $data['date'] ?? null,
            'depot_id' => $data['depot_id'] ?? null,
            'fuel_type_id' => $data['fuel_type_id'] ?? null,
            'delivery_type' => $data['delivery_type'] ?? null,
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
            // The cards follow the active tab's Depot and Fuel Type filters (and Status and Date on Stock-In).
            'summaryCards' => $dashboardSummary->inventoryCards(array_filter([
                'depot_id' => $filters['depot_id'],
                'fuel_type_id' => $filters['fuel_type_id'],
                'status' => $activeTab === 'stock-in' ? $filters['status'] : null,
                'date' => $activeTab === 'stock-in' ? $filters['date'] : null,
            ])),
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
            'deliveryTypes' => SalesOfficerCustomerController::DELIVERY_TYPES,
            'paymentStatuses' => self::PAYMENT_STATUSES,
            'paymentModes' => PurchaseService::PAYMENT_MODES,
            'banks' => PurchaseService::BANKS,
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

    /**
     * @return array<string, mixed>
     */
    private function validatedPurchaseData(Request $request): array
    {
        $data = $request->validate([
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
            // Part 9: Bank Selection is required for Bank Transfer and Cheque, and cleared for Cash.
            'payment_mode' => ['required', Rule::in(array_keys(PurchaseService::PAYMENT_MODES))],
            'bank_name' => ['exclude_if:payment_mode,cash', 'nullable', 'required_if:payment_mode,bank_transfer,cheque', Rule::in(PurchaseService::BANKS)],
            'status' => ['nullable', Rule::in(self::PURCHASE_STATUSES)],
        ], [
            'payment_mode.required' => 'Select the payment mode.',
            'bank_name.required_if' => 'Select the bank for a Bank Transfer or Cheque payment.',
        ]);
        $data['bank_name'] = $data['bank_name'] ?? null;

        return $data;
    }

    private function purchaseRows(?string $search, array $filters = []): LengthAwarePaginator
    {
        return $this->purchaseService->purchaseRows($search, filters: $filters);
    }

    /**
     * Stock-In tab: one row per purchase line, with what sales took from it and what remains.
     *
     * @param  array<string, mixed>  $filters
     */
    private function stockInRows(?string $search, array $filters = []): LengthAwarePaginator
    {
        $paginator = $this->stockIns->paginate($filters, $search);
        $paginator->setCollection($paginator->getCollection()->map(fn (object $row): array => [
            'id' => (int) $row->id,
            'modal_id' => 'io-stock-in-view-'.$row->id,
            'purchase_code' => $row->purchase_code,
            'date' => $this->formatDate($row->stock_in_date),
            'fuel' => $row->fuel_name,
            'depot' => $row->depot_name,
            'purchased' => $this->formatNumber($row->quantity_liters),
            'unit_cost' => $this->formatNumber($row->unit_cost),
            'total_cost' => $this->formatNumber($row->total_cost),
            'stock_out' => $this->formatNumber($row->stock_out),
            'remaining' => $this->formatNumber($row->remaining),
            'status' => $this->label($row->stock_status),
            'details' => [
                'Purchase ID' => $row->purchase_code,
                'Date' => $this->formatDate($row->stock_in_date),
                'Fuel Type' => $row->fuel_name,
                'Depot' => $row->depot_name,
                'Quantity Purchased' => $this->formatLiters($row->quantity_liters),
                'Cost/Liter' => $this->formatNumber($row->unit_cost),
                'Total Cost' => $this->formatNumber($row->total_cost),
                'Stock Out' => $this->formatLiters($row->stock_out),
                'Delivered' => $this->formatLiters($row->delivered),
                'Awaiting Delivery' => $this->formatLiters($row->reserved),
                'Remaining' => $this->formatLiters($row->remaining),
                'Payment Status' => $this->label($row->payment_status),
                'Recorded By' => $row->created_by_name ?: 'N/A',
                'Recorded At' => $this->formatDateTime($row->created_at),
            ] + ($row->voided_at ? ['Cancelled At' => $this->formatDateTime($row->voided_at)] : []),
            'deductions' => array_map(fn (array $deduction): array => [
                'order' => $deduction['sale_code'].($deduction['sales_order_number'] ? ' / SO '.$deduction['sales_order_number'] : ''),
                'date' => $this->formatDate($deduction['sale_date']),
                'customer' => $deduction['customer_name'],
                'quantity' => $this->formatLiters($deduction['liters']).($deduction['portion'] === 'f1' ? ' (F1 portion)' : ''),
                'delivery_type' => SalesOfficerCustomerController::DELIVERY_TYPES[$deduction['delivery_type']] ?? SalesOfficerCustomerController::DELIVERY_TYPES['standard'],
                'delivery_status' => $this->label($deduction['delivery_status']),
                'stock_out' => $deduction['stock_out_codes'] === []
                    ? 'Not delivered yet'
                    : implode(', ', $deduction['stock_out_codes']).' ('.$this->formatLiters($deduction['delivered']).')',
            ], $row->deductions),
        ]));

        return $paginator;
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

    private function stockOutRows(?string $search, array $filters = []): LengthAwarePaginator
    {
        $query = $this->stockOutFinancials->query($search)
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('stock_outs.status', $status))
            ->when($filters['date'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('stock_outs.stock_out_at', $date))
            // Garage releases come from pooled tank stock, so only direct depot releases carry a depot.
            ->when($filters['depot_id'] ?? null, fn (Builder $query, mixed $depotId): Builder => $query->where('source_depot.id', (int) $depotId))
            ->when($filters['fuel_type_id'] ?? null, fn (Builder $query, mixed $fuelTypeId): Builder => $query->where('stock_outs.fuel_type_id', (int) $fuelTypeId))
            ->when($filters['delivery_type'] ?? null, fn (Builder $query, string $type): Builder => $query->where('sales.delivery_type', $type))
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
                    SalesOfficerCustomerController::DELIVERY_TYPES[$row->delivery_type] ?? SalesOfficerCustomerController::DELIVERY_TYPES['standard'],
                    $row->fuel_name.($row->portion === 'f1' ? ' (F1 portion)' : ''),
                    // The purchase this release was deducted from (the F1 portion names its F1 purchase).
                    $row->release_purchase_code ?: ($row->lot_code ?: 'N/A'),
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
                // Delivery Details: the lift (Depot to Client) or the order's own delivery (Standard).
                'delivery' => [
                    'truck' => $row->delivery_truck_code ? trim($row->delivery_truck_code.($row->delivery_truck_plate ? ' / '.$row->delivery_truck_plate : '')) : 'N/A',
                    'driver' => $row->delivery_driver_name ?: 'N/A',
                    'date' => $row->delivery_date ? $this->formatDateTime($row->delivery_date) : 'N/A',
                    'status' => $this->label($row->delivery_status),
                    'atl' => $row->atl_number ?: 'N/A',
                    'dr' => $row->dr_number ?: 'N/A',
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
            // Part 2 revisions: deducted from a purchase at its depot.
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
