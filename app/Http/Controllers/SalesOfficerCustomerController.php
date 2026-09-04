<?php

namespace App\Http\Controllers;

use App\Services\DashboardSummaryService;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SalesOfficerCustomerController extends Controller
{
    private const PAYMENT_STATUSES = ['clear', 'pending', 'partial', 'unpaid'];
    private const ACCOUNT_STATUSES = ['active', 'inactive'];
    private const SALE_STATUSES = ['draft', 'confirmed', 'partially_paid', 'paid', 'unpaid', 'cancelled'];
    private const PAYMENT_METHODS = ['cash_on_delivery', 'cheque', 'advance_payment', 'bank_transfer'];
    private const PAYMENT_TERMS = ['cod', 'installment', 'advance'];

    public function index(Request $request, DashboardSummaryService $dashboardSummary, string $state = 'receivables'): View
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $search = trim((string) ($data['search'] ?? ''));
        $normalizedSearch = $search === '' ? null : $search;

        return view('sales-officer.sales', [
            'activeTab' => $state === 'customers' ? 'customers' : 'receivables',
            'search' => $normalizedSearch,
            'summaryCards' => $dashboardSummary->salesCards(),
            'sales' => $this->salesRows($normalizedSearch),
            'customers' => $this->customerRows($normalizedSearch),
            'customerOptions' => $this->customerOptions($state === 'customers' ? $normalizedSearch : null),
            'fuelTypes' => $this->fuelTypeOptions(),
            'paymentStatuses' => self::PAYMENT_STATUSES,
            'accountStatuses' => self::ACCOUNT_STATUSES,
            'saleStatuses' => self::SALE_STATUSES,
            'editableSaleStatuses' => array_values(array_diff(self::SALE_STATUSES, ['paid'])),
            'paymentMethods' => self::PAYMENT_METHODS,
            'paymentTerms' => self::PAYMENT_TERMS,
            'saleIdempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function storePayment(Request $request, int $sale): RedirectResponse
    {
        $data = $this->validatedPaymentData($request);
        $token = (string) $data['idempotency_key'];
        $sessionKey = 'payments.created.'.$token;

        if ($request->session()->has($sessionKey)) {
            return redirect()
                ->route('sales-officer.sales')
                ->with('status', 'Payment record was already submitted.');
        }

        $result = DB::transaction(function () use ($request, $sale, $data): array {
            $saleRow = DB::table('sales')
                ->join('customers', 'customers.id', '=', 'sales.customer_id')
                ->leftJoin('receivables', 'receivables.sale_id', '=', 'sales.id')
                ->where('sales.id', $sale)
                ->whereNull('sales.deleted_at')
                ->whereNotIn('sales.status', ['draft', 'cancelled'])
                ->lockForUpdate()
                ->first([
                    'sales.id',
                    'sales.sale_code',
                    'sales.customer_id',
                    'sales.status',
                    'receivables.due_date',
                    'customers.status as customer_status',
                ]);

            if (! $saleRow || $saleRow->customer_status !== 'active') {
                return ['error' => 'The selected sale is not eligible for payment.'];
            }

            DB::table('payments')
                ->where('sale_id', $saleRow->id)
                ->lockForUpdate()
                ->get(['id']);

            $saleTotal = $this->saleTotalForUpdate((int) $saleRow->id);
            $previousPaid = $this->paidTotalForSale((int) $saleRow->id);
            $amount = round((float) $data['amount'], 2);
            $remaining = round($saleTotal - $previousPaid, 2);

            if ($saleTotal <= 0) {
                return ['error' => 'The selected sale has no billable items.'];
            }

            if ($remaining <= 0) {
                return ['error' => 'The selected sale is already fully paid.'];
            }

            if ($amount > $remaining) {
                return ['error' => 'Payment amount cannot exceed the remaining balance.'];
            }

            $paymentSchedule = null;

            if (! empty($data['payment_schedule_id'])) {
                $paymentSchedule = $this->paymentScheduleForUpdate((int) $data['payment_schedule_id'], (int) $saleRow->id);

                if (! $paymentSchedule) {
                    return ['error' => 'The selected installment schedule does not belong to this sale.'];
                }

                $schedulePaid = $this->paidTotalForSchedule((int) $paymentSchedule->id);
                $scheduleRemaining = round((float) $paymentSchedule->amount_due - $schedulePaid, 2);

                if ($scheduleRemaining <= 0) {
                    return ['error' => 'The selected installment is already fully paid.'];
                }

                if ($amount > $scheduleRemaining) {
                    return ['error' => 'Payment amount cannot exceed the selected installment balance.'];
                }
            }

            $referenceNumber = isset($data['reference_number']) && trim((string) $data['reference_number']) !== ''
                ? trim((string) $data['reference_number'])
                : null;

            if ($this->duplicatePaymentExists(
                (int) $saleRow->id,
                $paymentSchedule?->id ? (int) $paymentSchedule->id : null,
                (string) $data['payment_date'],
                $amount,
                (string) $data['method'],
                $referenceNumber
            )) {
                return ['error' => 'This payment has already been recorded.'];
            }

            $paymentCode = $this->nextCode('payments', 'payment_code', 'PAY');

            DB::table('payments')->insert([
                'payment_code' => $paymentCode,
                'sale_id' => $saleRow->id,
                'payment_schedule_id' => $paymentSchedule?->id,
                'payment_date' => $data['payment_date'],
                'amount' => $amount,
                'method' => $data['method'],
                'reference_number' => $referenceNumber,
                'remarks' => $data['remarks'] ?? null,
                'received_by' => $request->user()->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $totalPaid = round($previousPaid + $amount, 2);
            $newStatus = $this->salePaymentStatus($saleTotal, $totalPaid);
            $newReceivableStatus = $this->receivableStatusForSale($saleTotal, $totalPaid, $saleRow->due_date);

            DB::table('sales')
                ->where('id', $saleRow->id)
                ->update([
                    'status' => $newStatus,
                    'updated_at' => now(),
                ]);

            DB::table('receivables')
                ->updateOrInsert(
                    ['sale_id' => $saleRow->id],
                    [
                        'status' => $newReceivableStatus,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );

            $this->updatePaymentScheduleStatuses((int) $saleRow->id);

            return ['payment_code' => $paymentCode];
        });

        if (isset($result['error'])) {
            return back()
                ->withInput()
                ->withErrors(['payment' => $result['error']]);
        }

        $request->session()->put($sessionKey, $result['payment_code']);

        return redirect()
            ->route('sales-officer.sales')
            ->with('status', 'Payment record '.$result['payment_code'].' recorded successfully.');
    }

    public function storeSale(Request $request): RedirectResponse
    {
        $data = $this->validatedSaleData($request);
        $token = (string) $data['idempotency_key'];
        $sessionKey = 'sales.created.'.$token;

        if ($request->session()->has($sessionKey)) {
            return redirect()
                ->route('sales-officer.sales')
                ->with('status', 'Sale record was already submitted.');
        }

        try {
            $saleCode = DB::transaction(function () use ($request, $data): string {
                $saleCode = $this->saleCode($data['sale_code'] ?? null);
                $items = $this->normalizedSaleItems($data);

                $saleId = DB::table('sales')->insertGetId([
                    'sale_code' => $saleCode,
                    'customer_id' => $data['customer_id'],
                    'sale_date' => $data['sale_date'],
                    'payment_method' => $data['payment_method'],
                    'payment_terms' => $data['payment_terms'] ?? $this->defaultPaymentTerms($data['payment_method']),
                    'status' => $data['status'] ?? 'confirmed',
                    'created_by' => $request->user()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $now = now();
                foreach ($items as $item) {
                    DB::table('sale_items')->insert([
                        'sale_id' => $saleId,
                        'fuel_type_id' => $item['fuel_type_id'],
                        'quantity_liters' => $item['quantity_liters'],
                        'unit_price' => $item['unit_price'],
                        'line_total' => $this->lineTotal($item['quantity_liters'], $item['unit_price']),
                        'fulfilled_quantity_liters' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }

                DB::table('receivables')->insert([
                    'sale_id' => $saleId,
                    'due_date' => $data['due_date'] ?? null,
                    'status' => 'pending',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return $saleCode;
            });
        } catch (QueryException) {
            return back()
                ->withInput()
                ->withErrors(['sale' => 'Sale record could not be saved. Please review the details and try again.']);
        }

        $request->session()->put($sessionKey, $saleCode);

        return redirect()
            ->route('sales-officer.sales')
            ->with('status', 'Sale record '.$saleCode.' created successfully.');
    }

    public function updateSale(Request $request, int $sale): RedirectResponse
    {
        $data = $this->validatedSaleData($request, $sale);
        $row = $this->saleForUpdate($sale);

        abort_unless($row, 404);

        if ($this->hasSaleDependentActivity($sale) && $this->changesProtectedSaleFields($row, $data)) {
            return back()
                ->withInput()
                ->withErrors(['sale' => 'This sale already has payment, stock-out, delivery, or haul activity, so customer, fuel, quantity, price, and date cannot be changed.']);
        }

        DB::transaction(function () use ($sale, $data): void {
            $items = $this->normalizedSaleItems($data);
            $hasDependencies = $this->hasSaleDependentActivity($sale);

            DB::table('sales')
                ->where('id', $sale)
                ->update([
                    'customer_id' => $data['customer_id'],
                    'sale_date' => $data['sale_date'],
                    'payment_method' => $data['payment_method'],
                    'payment_terms' => $data['payment_terms'] ?? $this->defaultPaymentTerms($data['payment_method']),
                    'status' => $data['status'] ?? 'confirmed',
                    'updated_at' => now(),
                ]);

            if (! $hasDependencies) {
                DB::table('sale_items')->where('sale_id', $sale)->delete();

                $now = now();
                foreach ($items as $item) {
                    DB::table('sale_items')->insert([
                        'sale_id' => $sale,
                        'fuel_type_id' => $item['fuel_type_id'],
                        'quantity_liters' => $item['quantity_liters'],
                        'unit_price' => $item['unit_price'],
                        'line_total' => $this->lineTotal($item['quantity_liters'], $item['unit_price']),
                        'fulfilled_quantity_liters' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
            }

            DB::table('receivables')
                ->updateOrInsert(
                    ['sale_id' => $sale],
                    [
                        'due_date' => $data['due_date'] ?? null,
                        'status' => ($data['status'] ?? 'confirmed') === 'cancelled' ? 'unpaid' : 'pending',
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
        });

        return redirect()
            ->route('sales-officer.sales')
            ->with('status', 'Sale record updated successfully.');
    }

    public function cancelSale(int $sale): RedirectResponse
    {
        abort_unless(DB::table('sales')->where('id', $sale)->whereNull('deleted_at')->exists(), 404);

        if ($this->hasSaleDependentActivity($sale)) {
            return back()
                ->withErrors(['sale' => 'This sale already has dependent activity and cannot be cancelled from Sales.']);
        }

        DB::table('sales')
            ->where('id', $sale)
            ->update([
                'status' => 'cancelled',
                'updated_at' => now(),
            ]);

        return redirect()
            ->route('sales-officer.sales')
            ->with('status', 'Sale record cancelled successfully.');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validatedCustomerData($request);

        try {
            DB::table('customers')->insert([
                'customer_code' => $this->nextCustomerCode(),
                'name' => $data['name'],
                'company_name' => $data['company_name'],
                'location' => $data['location'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'payment_status' => $data['payment_status'],
                'status' => $data['status'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (QueryException) {
            return back()
                ->withInput()
                ->withErrors(['company_name' => 'Customer record could not be saved. Please review the customer details and try again.']);
        }

        return redirect()
            ->route('sales-officer.sales.customers')
            ->with('status', 'Customer record created successfully.');
    }

    public function update(Request $request, int $customer): RedirectResponse
    {
        abort_unless(DB::table('customers')->where('id', $customer)->exists(), 404);

        $data = $this->validatedCustomerData($request, $customer);

        try {
            DB::table('customers')
                ->where('id', $customer)
                ->update([
                    'name' => $data['name'],
                    'company_name' => $data['company_name'],
                    'location' => $data['location'] ?? null,
                    'email' => $data['email'] ?? null,
                    'phone' => $data['phone'] ?? null,
                    'payment_status' => $data['payment_status'],
                    'status' => $data['status'],
                    'updated_at' => now(),
                ]);
        } catch (QueryException) {
            return back()
                ->withInput()
                ->withErrors(['company_name' => 'Customer record could not be updated. Please review the customer details and try again.']);
        }

        return redirect()
            ->route('sales-officer.sales.customers')
            ->with('status', 'Customer record updated successfully.');
    }

    public function deactivate(int $customer): RedirectResponse
    {
        abort_unless(DB::table('customers')->where('id', $customer)->exists(), 404);

        try {
            DB::table('customers')
                ->where('id', $customer)
                ->update([
                    'status' => 'inactive',
                    'updated_at' => now(),
                ]);
        } catch (QueryException) {
            return back()
                ->withErrors(['customer' => 'Customer record could not be deactivated. Please try again.']);
        }

        return redirect()
            ->route('sales-officer.sales.customers')
            ->with('status', 'Customer record deactivated successfully.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedCustomerData(Request $request, ?int $customerId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company_name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('customers', 'company_name')->ignore($customerId),
            ],
            'location' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\\-\\s]+$/'],
            'payment_status' => ['required', Rule::in(self::PAYMENT_STATUSES)],
            'status' => ['required', Rule::in(self::ACCOUNT_STATUSES)],
        ]);
    }

    private function customerRows(?string $search)
    {
        $rows = DB::table('customers')
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
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'customer_code',
                'name',
                'company_name',
                'location',
                'email',
                'phone',
                'payment_status',
                'status',
                'created_at',
                'updated_at',
            ]);

        $customerIds = $rows
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $transactionSummaries = $this->transactionSummaries($customerIds);
        $outstandingTotals = $this->customerOutstandingTotals($customerIds);

        return $rows
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'modal_id' => 'so-customer-edit-'.$row->id,
                'customer_code' => $row->customer_code,
                'name' => $row->name,
                'company_name' => $row->company_name,
                'location' => $row->location,
                'email' => $row->email,
                'phone' => $row->phone,
                'payment_status' => $row->payment_status,
                'status' => $row->status,
                'class' => $row->status === 'inactive' ? 'row-danger' : $this->rowClass($row->payment_status),
                'cells' => [
                    $row->customer_code,
                    $row->name,
                    $row->company_name,
                    $row->location ?: 'N/A',
                    $row->email ?: 'N/A',
                    $row->phone ?: 'N/A',
                    $this->label($row->payment_status),
                ],
                'details' => [
                    'Customer Name' => $row->name,
                    'Company Name' => $row->company_name,
                    'Location' => $row->location ?: 'N/A',
                    'Email' => $row->email ?: 'N/A',
                    'Contact Number' => $row->phone ?: 'N/A',
                    'Payment Status' => $this->label($row->payment_status),
                    'Account Status' => $this->label($row->status),
                    'Date Added' => $this->formatDateTime($row->created_at),
                    'Last Updated' => $this->formatDateTime($row->updated_at),
                    'Transactions' => $transactionSummaries[(int) $row->id] ?? 'No transaction records found',
                    'Outstanding Receivables' => 'PHP '.$this->formatNumber($outstandingTotals[(int) $row->id] ?? 0),
                ],
            ]);
    }

    private function customerOptions(?string $search)
    {
        return DB::table('customers')
            ->where('status', 'active')
            ->when($search, fn (Builder $query): Builder => $this->search($query, $search, [
                'customer_code',
                'name',
                'company_name',
                'location',
                'email',
                'phone',
            ]))
            ->orderBy('company_name')
            ->orderBy('name')
            ->get(['id', 'name', 'company_name']);
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

        $rows = DB::table('sales')
            ->joinSub($items, 'items_total', 'items_total.sale_id', '=', 'sales.id')
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->leftJoin('receivables', 'receivables.sale_id', '=', 'sales.id')
            ->leftJoinSub($payments, 'payments_total', 'payments_total.sale_id', '=', 'sales.id')
            ->whereNull('sales.deleted_at')
            ->when($search, fn (Builder $query): Builder => $this->search($query, $search, [
                'sales.sale_code',
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
            ]);

        $saleIds = $rows
            ->pluck('sale_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $itemsBySale = $this->itemsForSales($saleIds);
        $latestPaymentDates = $this->latestPaymentDatesForSales($saleIds);
        $dependencyMap = $this->saleDependencyMap($saleIds);
        $paymentsBySale = $this->paymentsForSales($saleIds);
        $schedulesBySale = $this->paymentSchedulesForSales($saleIds);

        return $rows
            ->map(function (object $row) use ($itemsBySale, $latestPaymentDates, $dependencyMap, $paymentsBySale, $schedulesBySale): array {
                $saleId = (int) $row->sale_id;
                $paid = (float) ($row->total_paid ?? 0);
                $saleTotal = (float) $row->sale_total;
                $balance = max(0, $saleTotal - $paid);
                $receivableStatus = $this->receivableStatusForSale($saleTotal, $paid, $row->due_date);
                $status = $this->receivableStatusLabel($receivableStatus);
                $saleItems = $itemsBySale[$saleId] ?? [];
                $latestPaymentDate = $latestPaymentDates[$saleId] ?? null;

                return [
                    'id' => $saleId,
                    'modal_id' => 'so-sales-edit-'.$row->sale_id,
                    'payment_id' => 'so-payment-history-'.$row->sale_id,
                    'payment_token' => (string) Str::uuid(),
                    'sale_code' => $row->sale_code,
                    'sale_date' => $row->sale_date,
                    'customer_id' => (int) $row->customer_id,
                    'payment_method' => $row->payment_method,
                    'payment_terms' => $row->payment_terms,
                    'status' => $row->status,
                    'receivable_status' => $receivableStatus,
                    'due_date' => $row->due_date,
                    'latest_payment_date' => $latestPaymentDate,
                    'has_dependencies' => $dependencyMap[$saleId] ?? false,
                    'items' => $saleItems,
                    'class' => $this->rowClass($receivableStatus === 'overdue' ? 'overdue' : $status),
                    'cells' => [
                        $row->sale_code,
                        $this->formatDate($row->sale_date),
                        $row->customer_name,
                        $row->company_name,
                        $this->fuelSummary((string) $row->first_fuel_name, (int) $row->item_count),
                        $this->formatNumber($row->total_quantity_liters),
                        $this->itemPriceSummary($saleItems),
                        $this->formatNumber($saleTotal),
                        $this->formatNumber($paid),
                        $this->formatNumber($balance),
                        $row->due_date ? $this->formatDate($row->due_date) : 'N/A',
                        $latestPaymentDate ? $this->formatDate($latestPaymentDate) : 'N/A',
                        $status,
                    ],
                    'details' => [
                        'Transaction Date' => $this->formatDate($row->sale_date),
                        'Customer Name' => $row->customer_name,
                        'Company Name' => $row->company_name,
                        'Fuel Type' => $this->fuelSummary((string) $row->first_fuel_name, (int) $row->item_count),
                        'Quantity Ordered' => $this->formatLiters($row->total_quantity_liters),
                        'Quantity Fulfilled' => $this->formatLiters(collect($saleItems)->sum('fulfilled_quantity_liters')),
                        'Total' => $this->formatNumber($saleTotal),
                        'Total Paid' => $this->formatNumber($paid),
                        'Balance' => $this->formatNumber($balance),
                        'Due Date' => $row->due_date ? $this->formatDate($row->due_date) : 'N/A',
                        'Latest Payment Date' => $latestPaymentDate ? $this->formatDate($latestPaymentDate) : 'N/A',
                        'Receivable Status' => $status,
                        'Payment Terms' => $this->label($row->payment_terms),
                        'Payment Method' => $this->paymentMethodLabel($row->payment_method),
                    ],
                    'payments' => $paymentsBySale[$saleId] ?? [],
                    'payment_schedules' => $schedulesBySale[$saleId] ?? [],
                    'sale_total' => $this->formatNumber($saleTotal),
                    'total_paid' => $this->formatNumber($paid),
                    'balance' => $this->formatNumber($balance),
                ];
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedSaleData(Request $request, ?int $saleId = null): array
    {
        return $request->validate([
            'idempotency_key' => [$saleId ? 'nullable' : 'required', 'uuid'],
            'sale_code' => ['nullable', 'string', 'max:30', Rule::unique('sales', 'sale_code')->ignore($saleId)->whereNull('deleted_at')],
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where(fn (Builder $query): Builder => $query->where('status', 'active'))],
            'sale_date' => ['required', 'date'],
            'fuel_type_id' => ['required_without:items', 'integer', Rule::exists('fuel_types', 'id')->where(fn (Builder $query): Builder => $query->where('status', 'active'))],
            'quantity_liters' => ['required_without:items', 'numeric', 'gt:0', 'max:999999999999.99'],
            'unit_price' => ['required_without:items', 'numeric', 'gt:0', 'max:9999999999.99'],
            'items' => ['nullable', 'array', 'min:1'],
            'items.*.fuel_type_id' => ['required_with:items', 'integer', Rule::exists('fuel_types', 'id')->where(fn (Builder $query): Builder => $query->where('status', 'active'))],
            'items.*.quantity_liters' => ['required_with:items', 'numeric', 'gt:0', 'max:999999999999.99'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'gt:0', 'max:9999999999.99'],
            'payment_method' => ['required', Rule::in(self::PAYMENT_METHODS)],
            'payment_terms' => ['nullable', Rule::in(self::PAYMENT_TERMS)],
            'status' => ['nullable', Rule::in(array_diff(self::SALE_STATUSES, ['paid']))],
            'due_date' => ['nullable', 'date', 'after_or_equal:sale_date'],
            'created_by' => ['prohibited'],
            'line_total' => ['prohibited'],
            'total' => ['prohibited'],
            'total_paid' => ['prohibited'],
            'balance' => ['prohibited'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPaymentData(Request $request): array
    {
        return $request->validate([
            'idempotency_key' => ['required', 'uuid'],
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999.99'],
            'method' => ['required', Rule::in(self::PAYMENT_METHODS)],
            'payment_schedule_id' => ['nullable', 'integer', Rule::exists('payment_schedules', 'id')],
            'reference_number' => ['nullable', 'string', 'max:100', 'required_if:method,cheque,bank_transfer'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'sale_id' => ['prohibited'],
            'customer_id' => ['prohibited'],
            'sale_total' => ['prohibited'],
            'total_paid' => ['prohibited'],
            'remaining_balance' => ['prohibited'],
            'balance' => ['prohibited'],
            'status' => ['prohibited'],
            'received_by' => ['prohibited'],
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array{fuel_type_id: int, quantity_liters: mixed, unit_price: mixed}>
     */
    private function normalizedSaleItems(array $data): array
    {
        if (! empty($data['items'])) {
            return collect($data['items'])
                ->map(fn (array $item): array => [
                    'fuel_type_id' => (int) $item['fuel_type_id'],
                    'quantity_liters' => $item['quantity_liters'],
                    'unit_price' => $item['unit_price'],
                ])
                ->values()
                ->all();
        }

        return [[
            'fuel_type_id' => (int) $data['fuel_type_id'],
            'quantity_liters' => $data['quantity_liters'],
            'unit_price' => $data['unit_price'],
        ]];
    }

    private function saleForUpdate(int $sale): ?object
    {
        return DB::table('sales')
            ->where('sales.id', $sale)
            ->whereNull('sales.deleted_at')
            ->first([
                'sales.id',
                'sales.customer_id',
                'sales.sale_date',
                'sales.payment_method',
                'sales.payment_terms',
                'sales.status',
            ]);
    }

    private function hasSaleDependentActivity(int $saleId): bool
    {
        return DB::table('payments')->where('sale_id', $saleId)->exists()
            || DB::table('stock_outs')->where('sale_id', $saleId)->exists()
            || DB::table('deliveries')->where('sale_id', $saleId)->exists()
            || DB::table('haul_allocations')->where('sale_id', $saleId)->exists()
            || DB::table('sale_items')->where('sale_id', $saleId)->where('fulfilled_quantity_liters', '>', 0)->exists();
    }

    /**
     * @param Collection<int, int> $saleIds
     * @return array<int, bool>
     */
    private function saleDependencyMap(Collection $saleIds): array
    {
        $ids = $saleIds
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $dependentIds = collect()
            ->merge(DB::table('payments')->whereIn('sale_id', $ids->all())->pluck('sale_id'))
            ->merge(DB::table('stock_outs')->whereIn('sale_id', $ids->all())->pluck('sale_id'))
            ->merge(DB::table('deliveries')->whereIn('sale_id', $ids->all())->pluck('sale_id'))
            ->merge(DB::table('haul_allocations')->whereIn('sale_id', $ids->all())->pluck('sale_id'))
            ->merge(DB::table('sale_items')->whereIn('sale_id', $ids->all())->where('fulfilled_quantity_liters', '>', 0)->pluck('sale_id'))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->all();

        $dependentSet = array_fill_keys($dependentIds, true);

        return $ids
            ->mapWithKeys(fn (int $id): array => [$id => isset($dependentSet[$id])])
            ->all();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function changesProtectedSaleFields(object $row, array $data): bool
    {
        $existingItems = DB::table('sale_items')
            ->where('sale_id', $row->id)
            ->orderBy('id')
            ->get(['fuel_type_id', 'quantity_liters', 'unit_price'])
            ->map(fn (object $item): array => [
                'fuel_type_id' => (int) $item->fuel_type_id,
                'quantity_liters' => (string) $item->quantity_liters,
                'unit_price' => (string) $item->unit_price,
            ])
            ->all();

        $submittedItems = collect($this->normalizedSaleItems($data))
            ->map(fn (array $item): array => [
                'fuel_type_id' => (int) $item['fuel_type_id'],
                'quantity_liters' => number_format((float) $item['quantity_liters'], 2, '.', ''),
                'unit_price' => number_format((float) $item['unit_price'], 2, '.', ''),
            ])
            ->all();

        return (int) $row->customer_id !== (int) $data['customer_id']
            || (string) $row->sale_date !== (string) $data['sale_date']
            || $existingItems !== $submittedItems;
    }

    private function saleCode(?string $requested): string
    {
        $requested = trim((string) $requested);

        return $requested !== '' ? $requested : $this->nextCode('sales', 'sale_code', 'SLS');
    }

    private function saleTotalForUpdate(int $saleId): float
    {
        DB::table('sale_items')
            ->where('sale_id', $saleId)
            ->lockForUpdate()
            ->get(['id']);

        return round((float) DB::table('sale_items')
            ->where('sale_id', $saleId)
            ->sum('line_total'), 2);
    }

    private function paidTotalForSale(int $saleId): float
    {
        return round((float) DB::table('payments')
            ->where('sale_id', $saleId)
            ->sum('amount'), 2);
    }

    private function paymentScheduleForUpdate(int $paymentScheduleId, int $saleId): ?object
    {
        return DB::table('payment_schedules')
            ->where('id', $paymentScheduleId)
            ->where('sale_id', $saleId)
            ->lockForUpdate()
            ->first([
                'id',
                'sale_id',
                'due_date',
                'amount_due',
                'status',
            ]);
    }

    private function paidTotalForSchedule(int $paymentScheduleId): float
    {
        return round((float) DB::table('payments')
            ->where('payment_schedule_id', $paymentScheduleId)
            ->sum('amount'), 2);
    }

    private function duplicatePaymentExists(
        int $saleId,
        ?int $paymentScheduleId,
        string $paymentDate,
        float $amount,
        string $method,
        ?string $referenceNumber
    ): bool {
        $query = DB::table('payments')
            ->where('sale_id', $saleId)
            ->where('payment_date', $paymentDate)
            ->where('amount', $amount)
            ->where('method', $method);

        if ($paymentScheduleId) {
            $query->where('payment_schedule_id', $paymentScheduleId);
        } else {
            $query->whereNull('payment_schedule_id');
        }

        if ($referenceNumber === null) {
            $query->whereNull('reference_number');
        } else {
            $query->where('reference_number', $referenceNumber);
        }

        return $query->exists();
    }

    private function updatePaymentScheduleStatuses(int $saleId): void
    {
        $schedules = DB::table('payment_schedules')
            ->where('sale_id', $saleId)
            ->lockForUpdate()
            ->get(['id', 'due_date', 'amount_due']);

        $scheduleIds = $schedules
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->values();

        $paidBySchedule = $scheduleIds->isEmpty()
            ? collect()
            : DB::table('payments')
                ->whereIn('payment_schedule_id', $scheduleIds->all())
                ->selectRaw('payment_schedule_id, COALESCE(SUM(amount), 0) as paid')
                ->groupBy('payment_schedule_id')
                ->pluck('paid', 'payment_schedule_id');

        foreach ($schedules as $schedule) {
            $paid = round((float) ($paidBySchedule[(int) $schedule->id] ?? 0), 2);
            $status = $this->paymentScheduleStatus((float) $schedule->amount_due, $paid, (string) $schedule->due_date);

            DB::table('payment_schedules')
                ->where('id', $schedule->id)
                ->update([
                    'status' => $status,
                    'updated_at' => now(),
                ]);
        }
    }

    private function paymentScheduleStatus(float $amountDue, float $paid, string $dueDate): string
    {
        if (round($paid, 2) >= round($amountDue, 2)) {
            return 'paid';
        }

        if ($paid > 0) {
            return 'partial';
        }

        return strtotime($dueDate) < strtotime(now()->toDateString()) ? 'overdue' : 'pending';
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
        return $this->latestPaymentDatesForSales(collect([$saleId]))[$saleId] ?? null;
    }

    /**
     * @param Collection<int, int> $saleIds
     * @return array<int, string|null>
     */
    private function latestPaymentDatesForSales(Collection $saleIds): array
    {
        $ids = $saleIds
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return DB::table('payments')
            ->whereIn('sale_id', $ids->all())
            ->selectRaw('sale_id, MAX(payment_date) as latest_payment_date')
            ->groupBy('sale_id')
            ->pluck('latest_payment_date', 'sale_id')
            ->mapWithKeys(fn (mixed $date, mixed $saleId): array => [(int) $saleId => $date ? (string) $date : null])
            ->all();
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

    private function lineTotal(mixed $quantity, mixed $unitPrice): string
    {
        $quantityHundredths = $this->decimalToInt($quantity, 2);
        $unitPriceCents = $this->decimalToInt($unitPrice, 2);
        $lineTotalCents = intdiv(($quantityHundredths * $unitPriceCents) + 50, 100);

        return number_format($lineTotalCents / 100, 2, '.', '');
    }

    private function decimalToInt(mixed $value, int $scale): int
    {
        $normalized = number_format((float) $value, $scale, '.', '');
        [$whole, $decimal] = array_pad(explode('.', $normalized, 2), 2, '');

        return ((int) $whole * (10 ** $scale)) + (int) str_pad(substr($decimal, 0, $scale), $scale, '0');
    }

    private function defaultPaymentTerms(string $paymentMethod): string
    {
        return $paymentMethod === 'advance_payment' ? 'advance' : 'cod';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function itemsForSale(int $saleId): array
    {
        return $this->itemsForSales(collect([$saleId]))[$saleId] ?? [];
    }

    /**
     * @param Collection<int, int> $saleIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function itemsForSales(Collection $saleIds): array
    {
        $ids = $saleIds
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $items = DB::table('sale_items')
            ->join('fuel_types', 'fuel_types.id', '=', 'sale_items.fuel_type_id')
            ->whereIn('sale_items.sale_id', $ids->all())
            ->orderBy('sale_items.sale_id')
            ->orderBy('sale_items.id')
            ->get([
                'sale_items.sale_id',
                'sale_items.fuel_type_id',
                'sale_items.quantity_liters',
                'sale_items.unit_price',
                'sale_items.line_total',
                'sale_items.fulfilled_quantity_liters',
                'fuel_types.name as fuel_name',
            ])
            ->groupBy(fn (object $item): int => (int) $item->sale_id);

        return $ids
            ->mapWithKeys(fn (int $saleId): array => [
                $saleId => ($items[$saleId] ?? collect())
                    ->map(fn (object $item): array => [
                        'fuel_type_id' => (int) $item->fuel_type_id,
                        'fuel_name' => $item->fuel_name,
                        'quantity_liters' => $item->quantity_liters,
                        'unit_price' => $item->unit_price,
                        'line_total' => $item->line_total,
                        'fulfilled_quantity_liters' => $item->fulfilled_quantity_liters,
                    ])
                    ->all(),
            ])
            ->all();
    }

    private function fuelTypeOptions()
    {
        $balances = DB::table('inventory_movements')
            ->whereIn('direction', ['in', 'out'])
            ->whereNotExists($this->cancelledStockOutExists())
            ->whereNotExists($this->cancelledHaulAllocationExists())
            ->selectRaw("fuel_type_id, COALESCE(SUM(CASE WHEN direction = 'in' THEN quantity_liters ELSE -quantity_liters END), 0) as available_liters")
            ->groupBy('fuel_type_id');

        return DB::table('fuel_types')
            ->leftJoinSub($balances, 'balances', 'balances.fuel_type_id', '=', 'fuel_types.id')
            ->where('fuel_types.status', 'active')
            ->orderBy('fuel_types.name')
            ->get([
                'fuel_types.id',
                'fuel_types.name',
                DB::raw('COALESCE(balances.available_liters, 0) as available_liters'),
            ]);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function paymentsForSale(int $saleId): array
    {
        return $this->paymentsForSales(collect([$saleId]))[$saleId] ?? [];
    }

    /**
     * @param Collection<int, int> $saleIds
     * @return array<int, array<int, array<string, string>>>
     */
    private function paymentsForSales(Collection $saleIds): array
    {
        $ids = $saleIds
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $payments = DB::table('payments')
            ->leftJoin('users', 'users.id', '=', 'payments.received_by')
            ->leftJoin('payment_schedules', 'payment_schedules.id', '=', 'payments.payment_schedule_id')
            ->whereIn('payments.sale_id', $ids->all())
            ->orderBy('payments.sale_id')
            ->orderBy('payment_date')
            ->orderBy('payments.id')
            ->get([
                'payments.sale_id',
                'payments.id',
                'payments.payment_code',
                'payments.payment_date',
                'payments.amount',
                'payments.method',
                'payments.reference_number',
                'payments.remarks',
                'payment_schedules.due_date as schedule_due_date',
                'users.name as received_by_name',
            ])
            ->groupBy(fn (object $row): int => (int) $row->sale_id);

        return $ids
            ->mapWithKeys(fn (int $saleId): array => [
                $saleId => ($payments[$saleId] ?? collect())
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
                        'remarks' => $row->remarks ?: 'N/A',
                        'status' => 'Recorded',
                    ])
                    ->all(),
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function paymentSchedulesForSale(int $saleId): array
    {
        return $this->paymentSchedulesForSales(collect([$saleId]))[$saleId] ?? [];
    }

    /**
     * @param Collection<int, int> $saleIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function paymentSchedulesForSales(Collection $saleIds): array
    {
        $ids = $saleIds
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $schedulePayments = DB::table('payments')
            ->selectRaw('payment_schedule_id, COALESCE(SUM(amount), 0) as paid')
            ->whereNotNull('payment_schedule_id')
            ->groupBy('payment_schedule_id');

        $schedules = DB::table('payment_schedules')
            ->leftJoinSub($schedulePayments, 'schedule_payments', 'schedule_payments.payment_schedule_id', '=', 'payment_schedules.id')
            ->whereIn('payment_schedules.sale_id', $ids->all())
            ->orderBy('payment_schedules.sale_id')
            ->orderBy('payment_schedules.due_date')
            ->orderBy('payment_schedules.id')
            ->get([
                'payment_schedules.sale_id',
                'payment_schedules.id',
                'payment_schedules.due_date',
                'payment_schedules.amount_due',
                'payment_schedules.status',
                DB::raw('COALESCE(schedule_payments.paid, 0) as paid'),
            ])
            ->groupBy(fn (object $row): int => (int) $row->sale_id);

        return $ids
            ->mapWithKeys(fn (int $saleId): array => [
                $saleId => ($schedules[$saleId] ?? collect())
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
                            'is_payable' => $remaining > 0,
                            'label' => 'Installment #'.($index + 1).' / Due '.$this->formatDate($row->due_date).' / Remaining '.$this->formatNumber($remaining),
                        ];
                    })
                    ->all(),
            ])
            ->all();
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

    private function transactionSummary(int $customerId): string
    {
        return $this->transactionSummaries(collect([$customerId]))[$customerId] ?? 'No transaction records found';
    }

    /**
     * @param Collection<int, int> $customerIds
     * @return array<int, string>
     */
    private function transactionSummaries(Collection $customerIds): array
    {
        $ids = $customerIds
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $salesCounts = DB::table('sales')
            ->whereIn('customer_id', $ids->all())
            ->whereNull('deleted_at')
            ->selectRaw('customer_id, COUNT(*) as sales_count')
            ->groupBy('customer_id')
            ->pluck('sales_count', 'customer_id');

        $deliveryCounts = DB::table('deliveries')
            ->whereIn('customer_id', $ids->all())
            ->selectRaw('customer_id, COUNT(*) as delivery_count')
            ->groupBy('customer_id')
            ->pluck('delivery_count', 'customer_id');

        return $ids
            ->mapWithKeys(function (int $customerId) use ($salesCounts, $deliveryCounts): array {
                $sales = (int) ($salesCounts[$customerId] ?? 0);
                $deliveries = (int) ($deliveryCounts[$customerId] ?? 0);

                return [
                    $customerId => $sales === 0 && $deliveries === 0
                        ? 'No transaction records found'
                        : $sales.' sales / '.$deliveries.' deliveries',
                ];
            })
            ->all();
    }

    private function customerOutstandingTotal(int $customerId): float
    {
        return $this->customerOutstandingTotals(collect([$customerId]))[$customerId] ?? 0.0;
    }

    /**
     * @param Collection<int, int> $customerIds
     * @return array<int, float>
     */
    private function customerOutstandingTotals(Collection $customerIds): array
    {
        $ids = $customerIds
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $payments = DB::table('payments')
            ->selectRaw('sale_id, COALESCE(SUM(amount), 0) as total_paid')
            ->groupBy('sale_id');

        $items = DB::table('sale_items')
            ->selectRaw('sale_id, COALESCE(SUM(line_total), 0) as sale_total')
            ->groupBy('sale_id');

        $totals = DB::table('sales')
            ->joinSub($items, 'items_total', 'items_total.sale_id', '=', 'sales.id')
            ->leftJoinSub($payments, 'payments_total', 'payments_total.sale_id', '=', 'sales.id')
            ->whereIn('sales.customer_id', $ids->all())
            ->whereNull('sales.deleted_at')
            ->where('sales.status', '!=', 'cancelled')
            ->selectRaw('sales.customer_id, COALESCE(SUM(CASE WHEN items_total.sale_total > COALESCE(payments_total.total_paid, 0) THEN items_total.sale_total - COALESCE(payments_total.total_paid, 0) ELSE 0 END), 0) as outstanding')
            ->groupBy('sales.customer_id')
            ->pluck('outstanding', 'customer_id');

        return $ids
            ->mapWithKeys(fn (int $customerId): array => [$customerId => round((float) ($totals[$customerId] ?? 0), 2)])
            ->all();
    }

    private function nextCustomerCode(): string
    {
        $nextId = ((int) DB::table('customers')->max('id')) + 1;

        do {
            $code = 'CSM-'.str_pad((string) $nextId, 6, '0', STR_PAD_LEFT);
            $nextId++;
        } while (DB::table('customers')->where('customer_code', $code)->exists());

        return $code;
    }

    private function rowClass(?string $status): string
    {
        return match (strtolower(str_replace(' ', '_', (string) $status))) {
            'unpaid' => 'row-danger',
            'pending', 'partial', 'partially_paid' => 'row-warning',
            'paid', 'clear', 'settled' => 'row-success',
            default => '',
        };
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

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function itemPriceSummary(array $items): string
    {
        $prices = collect($items)
            ->pluck('unit_price')
            ->map(fn (mixed $price): string => $this->formatNumber($price))
            ->unique()
            ->values();

        return $prices->count() === 1 ? $prices->first() : 'Mixed';
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

    private function formatLiters(mixed $value): string
    {
        return $this->formatNumber($value).' L';
    }

    private function cancelledStockOutExists(): \Closure
    {
        return function (Builder $query): void {
            $query->selectRaw('1')
                ->from('stock_outs')
                ->whereColumn('stock_outs.id', 'inventory_movements.reference_id')
                ->where('inventory_movements.reference_type', 'stock_out')
                ->where('stock_outs.status', 'cancelled');
        };
    }

    private function cancelledHaulAllocationExists(): \Closure
    {
        return function (Builder $query): void {
            $query->selectRaw('1')
                ->from('haul_allocations')
                ->whereColumn('haul_allocations.id', 'inventory_movements.reference_id')
                ->where('inventory_movements.reference_type', 'haul_allocation')
                ->where('haul_allocations.status', 'cancelled');
        };
    }
}
