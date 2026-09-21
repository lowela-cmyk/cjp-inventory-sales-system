<?php

namespace App\Http\Controllers;

use App\Services\InventoryLedgerService;
use App\Services\PurchaseService;
use App\Services\StockOutFinancialService;
use App\Services\TruckAvailabilityService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AdminMonitoringController extends Controller
{
    public function __construct(
        protected ?PurchaseService $purchaseService = null,
        protected ?TruckAvailabilityService $truckAvailability = null,
        protected ?StockOutFinancialService $stockOutFinancials = null,
    ) {
        $this->purchaseService = $this->purchaseService ?? app(PurchaseService::class);
        $this->truckAvailability = $this->truckAvailability ?? app(TruckAvailabilityService::class);
        $this->stockOutFinancials = $this->stockOutFinancials ?? app(StockOutFinancialService::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function inventory(Request $request): View
    {
        $search = $this->validatedSearch($request);

        $rawPurchaseRows = DB::table('purchase_items')
            ->leftJoinSub(
                DB::table('hauls')
                    ->whereNotNull('withdrawal_receipt_path')
                    ->selectRaw('purchase_item_id, COUNT(*) as withdrawal_count, MAX(withdrawal_receipt_uploaded_at) as latest_withdrawal_at')
                    ->groupBy('purchase_item_id'),
                'withdrawal_totals',
                'withdrawal_totals.purchase_item_id',
                '=',
                'purchase_items.id'
            )
            ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->join('depots', 'depots.id', '=', 'purchases.depot_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'purchase_items.fuel_type_id')
            ->whereNull('purchases.deleted_at')
            ->when($search, fn (Builder $query): Builder => $this->search($query, $search, [
                'purchases.purchase_code',
                'depots.name',
                'fuel_types.name',
                'purchases.payment_status',
                'purchases.status',
            ]))
            ->orderByDesc('purchases.purchase_date')
            ->orderByDesc('purchase_items.id')
            ->get([
                'purchase_items.id',
                'purchases.id as purchase_id',
                'purchases.purchase_code',
                'purchases.purchase_date',
                'purchases.receipt_reference',
                'purchases.payment_status',
                'purchases.status as purchase_status',
                'depots.name as depot_name',
                'fuel_types.name as fuel_name',
                'purchase_items.quantity_ordered_liters',
                'purchase_items.quantity_hauled_liters',
                'purchase_items.unit_cost',
                'purchase_items.line_total',
                'purchase_items.status as item_status',
                DB::raw('COALESCE(withdrawal_totals.withdrawal_count, 0) as withdrawal_count'),
                'withdrawal_totals.latest_withdrawal_at',
            ]);

        $withdrawalsMap = $this->purchaseService->batchWithdrawalsForPurchaseItems($rawPurchaseRows);

        $purchaseRows = $rawPurchaseRows->map(fn (object $row): array => [
            'id' => 'purchase-detail-'.$row->id,
            'receipt_url' => null,
            'withdrawals' => $withdrawalsMap[(int) $row->id] ?? [],
            'cells' => [
                $row->purchase_code,
                $this->formatDate($row->purchase_date),
                $row->fuel_name,
                $row->depot_name,
                $this->formatNumber($row->quantity_ordered_liters),
                $this->formatNumber($row->unit_cost),
                $this->formatNumber($row->line_total),
                $this->withdrawalStatus((int) $row->withdrawal_count),
                $this->label($row->payment_status),
            ],
            'status' => $this->label($row->payment_status),
            'class' => $this->rowClass($row->payment_status),
            'details' => [
                'Date' => $this->formatDate($row->purchase_date),
                'Fuel' => $row->fuel_name,
                'Depot' => $row->depot_name,
                'QTY Ordered (L)' => $this->formatLiters($row->quantity_ordered_liters),
                'QTY Lifted (L)' => $this->formatLiters($row->quantity_hauled_liters),
                'Cost / Liter' => $this->formatNumber($row->unit_cost),
                'Total Cost' => $this->formatNumber($row->line_total),
                'Withdrawal Receipts' => $this->withdrawalStatus((int) $row->withdrawal_count),
                'Latest Withdrawal Upload' => $this->formatDateTime($row->latest_withdrawal_at),
                'Purchase Status' => $this->label($row->purchase_status),
                'Item Status' => $this->label($row->item_status),
                'Payment Status' => $this->label($row->payment_status),
            ],
        ]);

        $stockInRows = DB::table('inventory_movements')
            ->join('storage_locations', 'storage_locations.id', '=', 'inventory_movements.storage_location_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'inventory_movements.fuel_type_id')
            ->where('inventory_movements.direction', 'in')
            ->when($search, fn (Builder $query): Builder => $this->search($query, $search, [
                'inventory_movements.movement_code',
                'storage_locations.name',
                'fuel_types.name',
                'inventory_movements.movement_type',
                'inventory_movements.remarks',
            ]))
            ->orderByDesc('inventory_movements.movement_date')
            ->orderByDesc('inventory_movements.id')
            ->get([
                'inventory_movements.id',
                'inventory_movements.movement_code',
                'inventory_movements.movement_date',
                'inventory_movements.movement_type',
                'inventory_movements.quantity_liters',
                'inventory_movements.unit_cost',
                'inventory_movements.reference_type',
                'inventory_movements.reference_id',
                'inventory_movements.remarks',
                'storage_locations.name as location_name',
                'fuel_types.name as fuel_name',
            ])
            ->map(fn (object $row): array => [
                'id' => 'stock-detail-'.$row->id,
                'cells' => [
                    $row->movement_code,
                    $this->formatDateTime($row->movement_date),
                    $row->fuel_name,
                    $row->location_name,
                    $this->formatNumber($row->quantity_liters),
                    $this->formatNumber($row->unit_cost),
                    $this->formatNumber(((float) $row->quantity_liters) * ((float) ($row->unit_cost ?? 0))),
                    $this->formatNumber($row->quantity_liters),
                    $this->stockStatus((float) $row->quantity_liters),
                ],
                'status' => $this->stockStatus((float) $row->quantity_liters),
                'class' => $this->rowClass($this->stockStatus((float) $row->quantity_liters)),
                'details' => [
                    'Movement Type' => $this->label($row->movement_type),
                    'Fuel' => $row->fuel_name,
                    'Location' => $row->location_name,
                    'Quantity' => $this->formatLiters($row->quantity_liters),
                    'Unit Cost' => $this->formatNumber($row->unit_cost),
                    'Reference' => $row->reference_type.' #'.$row->reference_id,
                    'Remarks' => $row->remarks ?: 'N/A',
                ],
            ]);

        $stockOutRows = $this->stockOutRows($search);

        return view('admin.inventory', [
            'search' => $search,
            'purchases' => $purchaseRows,
            'stockIn' => $stockInRows,
            'stockOut' => $stockOutRows,
        ]);
    }

    public function ledger(Request $request, InventoryLedgerService $ledgerService): View
    {
        $search = $this->validatedSearch($request);
        $rows = $ledgerService->rows($search);

        return view('admin.ledger', [
            'search' => $search,
            'ledger' => $rows['ledger'],
            'transactions' => $rows['transactions'],
            'latestBalances' => $rows['latestBalances'],
        ]);
    }

    public function fuelLifting(Request $request): View
    {
        $search = $this->validatedSearch($request);
        $rows = $this->haulRows($search);

        return view('admin.fuel-lifting', [
            'search' => $search,
            'scheduled' => $rows->whereIn('raw_status', ['scheduled', 'in_transit'])->values(),
            'hauled' => $rows->whereIn('raw_status', ['lifted', 'completed'])->values(),
            'liftingStatusIdempotencyKey' => (string) Str::uuid(),
            'truckAssignmentIdempotencyKey' => (string) Str::uuid(),
            'trucks' => $this->haulTruckOptions(),
        ]);
    }

    public function sales(Request $request): View
    {
        $search = $this->validatedSearch($request);

        $sales = $this->salesRows($search);
        $rawCustomers = DB::table('customers')
            ->when($search, fn (Builder $query): Builder => $this->search($query, $search, [
                'customer_code',
                'name',
                'company_name',
                'location',
                'email',
                'phone',
                'payment_status',
                'status',
            ]))
            ->orderBy('id')
            ->get();

        $customerIds = $rawCustomers->pluck('id')->map(fn (mixed $id): int => (int) $id)->filter()->values();

        $itemsSub = DB::table('sale_items')
            ->selectRaw('sale_id, COALESCE(SUM(line_total), 0) as sale_total')
            ->groupBy('sale_id');
        $paymentsSub = DB::table('payments')
            ->selectRaw('sale_id, COALESCE(SUM(amount), 0) as total_paid')
            ->groupBy('sale_id');

        $outstandingTotals = $customerIds->isEmpty()
            ? []
            : DB::table('sales')
                ->joinSub($itemsSub, 'items_total', 'items_total.sale_id', '=', 'sales.id')
                ->leftJoinSub($paymentsSub, 'payments_total', 'payments_total.sale_id', '=', 'sales.id')
                ->whereIn('sales.customer_id', $customerIds->all())
                ->whereNull('sales.deleted_at')
                ->where('sales.status', '!=', 'cancelled')
                ->selectRaw('sales.customer_id, COALESCE(SUM(CASE WHEN items_total.sale_total > COALESCE(payments_total.total_paid, 0) THEN items_total.sale_total - COALESCE(payments_total.total_paid, 0) ELSE 0 END), 0) as outstanding')
                ->groupBy('sales.customer_id')
                ->pluck('outstanding', 'customer_id')
                ->all();

        $overdueCustomerIds = $customerIds->isEmpty()
            ? []
            : DB::table('receivables')
                ->join('sales', 'sales.id', '=', 'receivables.sale_id')
                ->whereIn('sales.customer_id', $customerIds->all())
                ->whereNull('sales.deleted_at')
                ->where('sales.status', '!=', 'cancelled')
                ->where('receivables.status', 'overdue')
                ->pluck('sales.customer_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->flip()
                ->all();

        $hasSalesCustomerIds = $customerIds->isEmpty()
            ? []
            : DB::table('sales')
                ->whereIn('customer_id', $customerIds->all())
                ->whereNull('deleted_at')
                ->pluck('customer_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->unique()
                ->flip()
                ->all();

        $customers = $rawCustomers->map(function (object $row) use ($outstandingTotals, $overdueCustomerIds, $hasSalesCustomerIds): array {
            $cid = (int) $row->id;
            $outstanding = (float) ($outstandingTotals[$cid] ?? 0);
            $hasOverdue = isset($overdueCustomerIds[$cid]);
            $hasSales = isset($hasSalesCustomerIds[$cid]);
            $derivedPaymentStatus = $hasSales
                ? ($hasOverdue ? 'overdue' : ($outstanding > 0 ? 'pending' : 'clear'))
                : ($row->payment_status ?: 'clear');

            return [
                'id' => 'customer-detail-'.$row->id,
                'cells' => [
                    $row->customer_code,
                    $row->name,
                    $row->company_name,
                    $row->location ?: 'N/A',
                    $row->email ?: 'N/A',
                    $row->phone ?: 'N/A',
                ],
                'details' => [
                    'Customer Name' => $row->name,
                    'Company Name' => $row->company_name,
                    'Location' => $row->location ?: 'N/A',
                    'Email' => $row->email ?: 'N/A',
                    'Contact Number' => $row->phone ?: 'N/A',
                    'Payment Status' => $this->label($derivedPaymentStatus),
                    'Account Status' => $this->label($row->status),
                ],
            ];
        });

        return view('admin.sales', [
            'search' => $search,
            'sales' => $sales,
            'customers' => $customers,
            'customerOptions' => DB::table('customers')
                ->where('status', 'active')
                ->orderBy('company_name')
                ->orderBy('name')
                ->get(['id', 'name', 'company_name']),
            'fuelTypes' => DB::table('fuel_types')
                ->where('status', 'active')
                ->whereIn('code', array_keys(config('fuels.approved', ['F1' => true, 'UNL' => true, 'DSL' => true, 'PREM' => true])))
                ->orderBy('name')
                ->get(['id', 'name']),
            'paymentMethods' => ['cash_on_delivery', 'cheque', 'advance_payment', 'bank_transfer'],
            'paymentTerms' => ['cod', 'installment', 'advance'],
            'editableSaleStatuses' => ['draft', 'confirmed', 'partially_paid', 'unpaid'],
            'saleIdempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function alerts(Request $request): View
    {
        $search = $this->validatedSearch($request);
        $alerts = $this->alertRows($search, null, (int) $request->user()->id, (string) $request->user()->role);

        return view('admin.alerts', compact('search', 'alerts'));
    }

    public function inventoryOfficerAlerts(Request $request): View
    {
        $search = $this->validatedSearch($request);
        $alerts = $this->alertRows($search, ['inventory', 'purchase', 'haul', 'discrepancy'], (int) $request->user()->id, (string) $request->user()->role);

        return view('inventory-officer.alerts', compact('search', 'alerts'));
    }

    public function salesOfficerAlerts(Request $request): View
    {
        $search = $this->validatedSearch($request);
        $alerts = $this->alertRows($search, ['payment', 'receivable'], (int) $request->user()->id, (string) $request->user()->role);

        return view('sales-officer.alerts', compact('search', 'alerts'));
    }

    public function dispatchAlerts(Request $request): View
    {
        $search = $this->validatedSearch($request);
        $alerts = $this->alertRows($search, ['haul', 'delivery'], (int) $request->user()->id, (string) $request->user()->role);

        return view('dispatch.alerts', compact('search', 'alerts'));
    }

    private function stockOutRows(?string $search)
    {
        return $this->stockOutFinancials->query($search)
            ->orderByDesc('stock_outs.stock_out_at')
            ->orderByDesc('stock_outs.id')
            ->get()
            ->map(function (object $row): array {
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
    }

    /**
     * @param  array<int, string>|null  $types
     */
    public function markAlertRead(Request $request, int $alert): RedirectResponse
    {
        $allowedTypes = match ($request->user()->role) {
            'inventory_officer' => ['inventory', 'purchase', 'haul', 'discrepancy'],
            'dispatch_officer' => ['haul', 'delivery'],
            'sales_officer' => ['payment', 'receivable'],
            'admin' => null,
            default => [],
        };
        $query = DB::table('alerts')->where('id', $alert);
        if (is_array($allowedTypes)) {
            $query->whereIn('type', $allowedTypes);
        }
        abort_unless($query->exists(), 404);

        DB::table('alert_reads')->upsert([[
            'alert_id' => $alert,
            'user_id' => $request->user()->id,
            'read_at' => now(),
        ]], ['alert_id', 'user_id'], ['read_at']);

        return back()->with('status', 'Alert marked as read.');
    }

    private function alertRows(?string $search, ?array $types = null, ?int $userId = null, ?string $role = null)
    {
        return DB::table('alerts')
            ->leftJoin('alert_reads', function ($join) use ($userId): void {
                $join->on('alert_reads.alert_id', '=', 'alerts.id')
                    ->where('alert_reads.user_id', '=', $userId ?? 0);
            })
            ->when($types, fn (Builder $query, array $types): Builder => $query->whereIn('type', $types))
            ->when($search, fn (Builder $query): Builder => $this->search($query, $search, [
                'alerts.alert_code',
                'alerts.type',
                'alerts.severity',
                'alerts.title',
                'alerts.message',
                'alerts.reference_type',
                'alerts.status',
            ]))
            ->orderByDesc('alerts.created_at')
            ->orderByDesc('alerts.id')
            ->get(['alerts.*', 'alert_reads.read_at'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'class' => $row->severity === 'critical' ? 'alert-critical' : 'alert-warning',
                'type' => $row->severity === 'critical' ? 'critical' : 'warning',
                'title' => $row->alert_code.' - '.$row->title,
                'message' => $row->message,
                'time' => $this->formatDateTime($row->created_at),
                'meta' => trim($this->label($row->type).' / '.($row->reference_type ?: '').($row->reference_id ? ' #'.$row->reference_id : '')),
                'status' => $this->label($row->status),
                'read' => $row->read_at !== null,
                'read_state' => $row->read_at ? 'Read' : 'Unread',
                'action_url' => $this->alertActionUrl($row, $role),
            ]);
    }

    private function alertActionUrl(object $alert, ?string $role): ?string
    {
        return match ($role) {
            'admin' => $alert->reference_type === 'haul' ? route('admin.fuel-lifting') : route('admin.inventory'),
            'inventory_officer' => route('inventory-officer.inventory'),
            'dispatch_officer' => route('dispatch.fuel-lifting'),
            'sales_officer' => route('sales-officer.sales'),
            default => $alert->action_url,
        };
    }

    private function salesRows(?string $search)
    {
        $payments = DB::table('payments')
            ->selectRaw('sale_id, COALESCE(SUM(amount), 0) as total_paid')
            ->groupBy('sale_id');

        $items = DB::table('sale_items')
            ->join('fuel_types', 'fuel_types.id', '=', 'sale_items.fuel_type_id')
            ->selectRaw('sale_items.sale_id, COUNT(*) as item_count, SUM(sale_items.quantity_liters) as total_quantity_liters, SUM(sale_items.line_total) as sale_total, MIN(fuel_types.name) as first_fuel_name')
            ->groupBy('sale_items.sale_id');

        return DB::table('sales')
            ->joinSub($items, 'items_total', 'items_total.sale_id', '=', 'sales.id')
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->leftJoin('receivables', 'receivables.sale_id', '=', 'sales.id')
            ->leftJoinSub($payments, 'payments_total', 'payments_total.sale_id', '=', 'sales.id')
            ->whereNull('sales.deleted_at')
            ->when($search, fn (Builder $query): Builder => $this->search($query, $search, [
                'sales.sale_code',
                'sales.sales_order_number',
                'customers.name',
                'customers.company_name',
                'items_total.first_fuel_name',
                'sales.status',
                'sales.payment_method',
                'receivables.status',
            ]))
            ->orderByDesc('sales.sale_date')
            ->orderByDesc('sales.id')
            ->get([
                'sales.id as sale_id',
                'sales.sale_code',
                'sales.sales_order_number',
                'sales.sale_date',
                'sales.customer_id',
                'sales.payment_method',
                'sales.payment_terms',
                'sales.status',
                'customers.name as customer_name',
                'customers.company_name',
                'items_total.item_count',
                'items_total.total_quantity_liters',
                'items_total.sale_total',
                'items_total.first_fuel_name',
                'receivables.due_date',
                'receivables.status as receivable_status',
                'payments_total.total_paid',
            ])
            ->map(function (object $row): array {
                $paid = (float) ($row->total_paid ?? 0);
                $saleTotal = (float) $row->sale_total;
                $balance = max(0, $saleTotal - $paid);
                $receivableStatus = $this->receivableStatusForSale($saleTotal, $paid, $row->due_date);
                $status = $this->receivableStatusLabel($receivableStatus);
                $latestPaymentDate = $this->latestPaymentDateForSale((int) $row->sale_id);

                return [
                    'id' => 'sales-detail-'.$row->sale_id,
                    'sale_id' => (int) $row->sale_id,
                    'payment_id' => 'payment-history-'.$row->sale_id,
                    'sale_code' => $row->sale_code,
                    'sales_order_number' => $row->sales_order_number,
                    'sale_date' => $row->sale_date,
                    'customer_id' => (int) $row->customer_id,
                    'payment_method' => $row->payment_method,
                    'payment_terms' => $row->payment_terms,
                    'raw_status' => $row->status,
                    'due_date' => $row->due_date,
                    'items' => $this->itemsForSale((int) $row->sale_id),
                    'cells' => [
                        $row->sale_code,
                        $row->sales_order_number ?: 'N/A',
                        $this->formatDate($row->sale_date),
                        $row->customer_name,
                        $row->company_name,
                        $this->fuelSummary((string) $row->first_fuel_name, (int) $row->item_count),
                        $this->formatNumber($row->total_quantity_liters),
                        $this->itemPriceSummary((int) $row->sale_id),
                        $this->formatNumber($saleTotal),
                        $this->formatNumber($paid),
                        $this->formatNumber($balance),
                        $row->due_date ? $this->formatDate($row->due_date) : 'N/A',
                        $latestPaymentDate ? $this->formatDate($latestPaymentDate) : 'N/A',
                        $status,
                    ],
                    'status' => $status,
                    'class' => $this->rowClass($status),
                    'details' => [
                        'Sales Order Number' => $row->sales_order_number ?: 'N/A',
                        'Transaction Date' => $this->formatDate($row->sale_date),
                        'Customer Name' => $row->customer_name,
                        'Company Name' => $row->company_name,
                        'Fuel Type' => $this->fuelSummary((string) $row->first_fuel_name, (int) $row->item_count),
                        'Quantity Ordered' => $this->formatLiters($row->total_quantity_liters),
                        'Quantity Fulfilled' => $this->formatLiters($this->fulfilledQuantityForSale((int) $row->sale_id)),
                        'Price / Unit' => $this->itemPriceSummary((int) $row->sale_id),
                        'Total Price' => $this->formatNumber($saleTotal),
                        'Total Paid' => $this->formatNumber($paid),
                        'Balance' => $this->formatNumber($balance),
                        'Due Date' => $row->due_date ? $this->formatDate($row->due_date) : 'N/A',
                        'Latest Payment Date' => $latestPaymentDate ? $this->formatDate($latestPaymentDate) : 'N/A',
                        'Receivable Status' => $status,
                        'Payment Terms' => $this->label($row->payment_terms),
                        'Payment Method' => $this->paymentMethodLabel($row->payment_method),
                    ],
                    'payments' => $this->paymentsForSale((int) $row->sale_id),
                    'payment_schedules' => $this->paymentSchedulesForSale((int) $row->sale_id),
                    'sale_total' => $this->formatNumber($saleTotal),
                    'total_paid' => $this->formatNumber($paid),
                    'balance' => $this->formatNumber($balance),
                ];
            });
    }

    private function haulRows(?string $search)
    {
        return DB::table('hauls')
            ->join('purchases', 'purchases.id', '=', 'hauls.purchase_id')
            ->join('depots', 'depots.id', '=', 'hauls.depot_id')
            ->join('fuel_types', 'fuel_types.id', '=', 'hauls.fuel_type_id')
            ->join('trucks', 'trucks.id', '=', 'hauls.truck_id')
            ->join('users as drivers', 'drivers.id', '=', 'hauls.driver_user_id')
            ->whereNull('purchases.deleted_at')
            ->when($search, fn (Builder $query): Builder => $this->search($query, $search, [
                'hauls.haul_code',
                'purchases.purchase_code',
                'hauls.dr_number',
                'hauls.source_location',
                'depots.name',
                'fuel_types.name',
                'trucks.truck_code',
                'drivers.name',
                'drivers.phone',
                'hauls.status',
            ]))
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
                'hauls.truck_id',
                'hauls.status',
                'purchases.purchase_code',
                'depots.name as depot_name',
                'fuel_types.name as fuel_name',
                'trucks.truck_code',
                'trucks.capacity_liters',
                'drivers.name as driver_name',
                'drivers.phone as driver_phone',
            ])
            ->map(fn (object $row): array => [
                'id' => 'lift-detail-'.$row->id,
                'haul_id' => (int) $row->id,
                'truck_id' => (int) $row->truck_id,
                'raw_status' => $row->status,
                'cells' => [
                    $row->haul_code,
                    $row->purchase_code,
                    $row->dr_number ?: 'N/A',
                    $this->formatDateTime($row->hauled_at ?: $row->scheduled_at),
                    $row->source_location ?: $row->depot_name,
                    $row->driver_name,
                    $row->driver_phone ?: 'N/A',
                    $row->truck_code,
                    $this->formatNumber($row->capacity_liters),
                    $this->formatLiters($row->quantity_liters),
                    $this->label($row->status),
                ],
                'status' => $this->label($row->status),
                'class' => $this->rowClass($row->status),
                'details' => [
                    'Purchase ID' => $row->purchase_code,
                    'Fuel Type' => $row->fuel_name,
                    'DR Number' => $row->dr_number ?: 'N/A',
                    'Scheduled Date' => $this->formatDateTime($row->scheduled_at),
                    'Hauled Date' => $row->hauled_at ? $this->formatDateTime($row->hauled_at) : 'N/A',
                    'Location' => $row->source_location ?: $row->depot_name,
                    'Driver' => $row->driver_name,
                    "Driver's Contact" => $row->driver_phone ?: 'N/A',
                    'Truck ID' => $row->truck_code,
                    'Capacity' => $this->formatNumber($row->capacity_liters),
                    'Quantity Lift' => $this->formatLiters($row->quantity_liters),
                ],
                'allowed_statuses' => DispatchLiftingStatusController::STATUS_TRANSITIONS[$row->status] ?? [],
                'can_assign_truck' => $row->status === 'scheduled',
            ]);
    }

    private function haulTruckOptions()
    {
        return $this->truckAvailability->assignableTrucks();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function haulsForPurchaseItem(int $purchaseItemId): array
    {
        return DB::table('hauls')
            ->where('purchase_item_id', $purchaseItemId)
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (object $row): array => [
                'label' => $row->haul_code.' / DR '.($row->dr_number ?: 'N/A').' / '.$this->formatDateTime($row->hauled_at ?: $row->scheduled_at),
                'quantity' => $this->formatLiters($row->quantity_liters),
                'status' => $this->label($row->status),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function paymentsForSale(int $saleId): array
    {
        return DB::table('payments')
            ->leftJoin('users', 'users.id', '=', 'payments.received_by')
            ->leftJoin('payment_schedules', 'payment_schedules.id', '=', 'payments.payment_schedule_id')
            ->where('payments.sale_id', $saleId)
            ->orderBy('payment_date')
            ->orderBy('payments.id')
            ->get([
                'payments.id',
                'payments.payment_code',
                'payments.payment_date',
                'payments.amount',
                'payments.method',
                'payments.reference_number',
                'payment_schedules.due_date as schedule_due_date',
                'users.name as received_by_name',
            ])
            ->values()
            ->map(fn (object $row, int $index): array => [
                'sequence' => 'Installment #'.($index + 1),
                'code' => $row->payment_code,
                'date' => $this->formatDate($row->payment_date),
                'amount' => $this->formatNumber($row->amount),
                'method' => $this->paymentMethodLabel($row->method),
                'reference' => $row->reference_number ?: 'N/A',
                'schedule' => $row->schedule_due_date ? 'Due '.$this->formatDate($row->schedule_due_date) : 'Unscheduled',
                'recorded_by' => $row->received_by_name ?: 'N/A',
                'status' => 'Recorded',
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function paymentSchedulesForSale(int $saleId): array
    {
        $schedulePayments = DB::table('payments')
            ->selectRaw('payment_schedule_id, COALESCE(SUM(amount), 0) as paid')
            ->whereNotNull('payment_schedule_id')
            ->groupBy('payment_schedule_id');

        return DB::table('payment_schedules')
            ->leftJoinSub($schedulePayments, 'schedule_payments', 'schedule_payments.payment_schedule_id', '=', 'payment_schedules.id')
            ->where('payment_schedules.sale_id', $saleId)
            ->orderBy('payment_schedules.due_date')
            ->orderBy('payment_schedules.id')
            ->get([
                'payment_schedules.id',
                'payment_schedules.due_date',
                'payment_schedules.amount_due',
                'payment_schedules.status',
                DB::raw('COALESCE(schedule_payments.paid, 0) as paid'),
            ])
            ->values()
            ->map(function (object $row, int $index): array {
                $remaining = max(0, round((float) $row->amount_due - (float) $row->paid, 2));

                return [
                    'id' => (int) $row->id,
                    'sequence' => 'Installment #'.($index + 1),
                    'due_date' => $this->formatDate($row->due_date),
                    'amount_due' => $this->formatNumber($row->amount_due),
                    'paid' => $this->formatNumber($row->paid),
                    'remaining' => $this->formatNumber($remaining),
                    'status' => $this->label($row->status),
                ];
            })
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function itemsForSale(int $saleId): array
    {
        return DB::table('sale_items')
            ->join('fuel_types', 'fuel_types.id', '=', 'sale_items.fuel_type_id')
            ->where('sale_items.sale_id', $saleId)
            ->orderBy('sale_items.id')
            ->get([
                'sale_items.fuel_type_id',
                'sale_items.quantity_liters',
                'sale_items.unit_price',
                'sale_items.line_total',
                'sale_items.fulfilled_quantity_liters',
                'fuel_types.name as fuel_name',
            ])
            ->map(fn (object $item): array => [
                'fuel_type_id' => (int) $item->fuel_type_id,
                'fuel_name' => $item->fuel_name,
                'quantity_liters' => $item->quantity_liters,
                'unit_price' => $item->unit_price,
                'line_total' => $item->line_total,
                'fulfilled_quantity_liters' => $item->fulfilled_quantity_liters,
            ])
            ->all();
    }

    private function validatedSearch(Request $request): ?string
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $search = trim((string) ($data['search'] ?? ''));

        return $search === '' ? null : $search;
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
        return ucwords(str_replace('_', ' ', (string) $value));
    }

    private function receivableStatusLabel(string $status): string
    {
        return match ($status) {
            'clear' => 'Settled',
            'partial' => 'Partially Paid',
            default => $this->label($status),
        };
    }

    private function paymentMethodLabel(?string $value): string
    {
        return match ($value) {
            'cash_on_delivery' => 'COD',
            'bank_transfer' => 'Banking',
            'advance_payment' => 'Advance Payment',
            'cheque' => 'Cheque',
            default => $value ? $this->label($value) : 'N/A',
        };
    }

    private function fuelSummary(string $firstFuel, int $itemCount): string
    {
        if ($itemCount <= 1) {
            return $firstFuel;
        }

        return trim($firstFuel).' + '.($itemCount - 1);
    }

    private function itemPriceSummary(int $saleId): string
    {
        $prices = DB::table('sale_items')
            ->where('sale_id', $saleId)
            ->distinct()
            ->orderBy('unit_price')
            ->pluck('unit_price')
            ->map(fn (mixed $price): string => $this->formatNumber($price));

        return $prices->count() === 1 ? $prices->first() : 'Mixed';
    }

    private function fulfilledQuantityForSale(int $saleId): float
    {
        return (float) DB::table('sale_items')
            ->where('sale_id', $saleId)
            ->sum('fulfilled_quantity_liters');
    }

    private function salePaymentStatus(float $saleTotal, float $totalPaid): string
    {
        if ($totalPaid <= 0) {
            return 'unpaid';
        }

        return round($totalPaid, 2) >= round($saleTotal, 2) ? 'paid' : 'partially_paid';
    }

    private function receivableStatusForSale(float $saleTotal, float $totalPaid, mixed $dueDate = null): string
    {
        if ($totalPaid <= 0) {
            return $this->isOverdue($saleTotal, $totalPaid, $dueDate) ? 'overdue' : 'unpaid';
        }

        if (round($totalPaid, 2) >= round($saleTotal, 2)) {
            return 'clear';
        }

        return $this->isOverdue($saleTotal, $totalPaid, $dueDate) ? 'overdue' : 'partial';
    }

    private function isOverdue(float $saleTotal, float $totalPaid, mixed $dueDate): bool
    {
        return $dueDate
            && round($totalPaid, 2) < round($saleTotal, 2)
            && strtotime((string) $dueDate) < strtotime(now()->toDateString());
    }

    private function latestPaymentDateForSale(int $saleId): ?string
    {
        $date = DB::table('payments')
            ->where('sale_id', $saleId)
            ->max('payment_date');

        return $date ? (string) $date : null;
    }

    private function rowClass(?string $status): string
    {
        return match (strtolower(str_replace(' ', '_', (string) $status))) {
            'unpaid', 'overdue', 'cancelled', 'critical', 'depleted' => 'row-danger',
            'partial', 'partially_paid', 'partially_hauled', 'pending', 'scheduled', 'in_transit', 'low_stock', 'incomplete' => 'row-warning',
            'paid', 'clear', 'settled', 'hauled', 'lifted', 'completed', 'delivered', 'available', 'released' => 'row-success',
            default => '',
        };
    }

    private function stockStatus(float $liters): string
    {
        return match (true) {
            $liters <= 0 => 'Depleted',
            $liters < 15000 => 'Low Stock',
            default => 'Available',
        };
    }

    /**
     * @return array<int, array<string, string|null>>
     */
    private function withdrawalsForPurchaseItem(int $purchaseItemId): array
    {
        return DB::table('hauls')
            ->where('purchase_item_id', $purchaseItemId)
            ->whereNotNull('withdrawal_receipt_path')
            ->orderByDesc('withdrawal_receipt_uploaded_at')
            ->orderByDesc('id')
            ->get(['id', 'haul_code', 'withdrawal_receipt_notes', 'withdrawal_receipt_uploaded_at'])
            ->map(fn (object $row): array => [
                'haul_code' => $row->haul_code,
                'uploaded_at' => $this->formatDateTime($row->withdrawal_receipt_uploaded_at),
                'notes' => $row->withdrawal_receipt_notes ?: null,
                'url' => route('withdrawal-receipts.show', $row->id),
            ])
            ->all();
    }

    private function withdrawalStatus(int $count): string
    {
        return $count > 0 ? $count.' Uploaded' : 'No Withdrawal';
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
