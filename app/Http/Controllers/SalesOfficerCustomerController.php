<?php

namespace App\Http\Controllers;

use App\Rules\ApprovedFuelType;
use App\Services\DashboardSummaryService;
use App\Services\IdempotencyService;
use App\Services\SaleConfirmationService;
use App\Services\SaleCorrectionService;
use App\Services\PurchaseStockService;
use App\Support\F1Split;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SalesOfficerCustomerController extends Controller
{
    public function __construct(
        private readonly SaleConfirmationService $saleConfirmationService,
        private readonly IdempotencyService $idempotencyService,
        private readonly SaleCorrectionService $saleCorrectionService,
        private readonly PurchaseStockService $purchaseStock
    ) {}

    public const PAYMENT_STATUSES = ['clear', 'pending', 'partial', 'unpaid'];

    public const ACCOUNT_STATUSES = ['active', 'inactive'];

    private const SALE_STATUSES = ['draft', 'confirmed', 'partially_paid', 'paid', 'unpaid', 'cancelled'];

    private const DELIVERY_STATUSES = ['pending', 'out_for_delivery', 'delivered', 'cancelled'];

    /** Methods offered for new sales and payments (Part 8 #10 removed Advance Payment). */
    private const PAYMENT_METHODS = ['cash_on_delivery', 'cheque', 'bank_transfer'];

    /** Retired methods: existing records keep and display them, but they can't be chosen again. */
    private const LEGACY_PAYMENT_METHODS = ['advance_payment'];

    /** Terms offered for new sales (Part 8 #11 removed COD). */
    private const PAYMENT_TERMS = ['installment', 'advance'];

    /** Retired terms: an existing sale keeps its value when edited. */
    private const LEGACY_PAYMENT_TERMS = ['cod'];

    /** Receivable statuses shown in the Status column (filter value => label). */
    /** Why a sale is being voided (System Analysis 4.13); "other" needs a note. */
    public const CANCEL_REASONS = [
        'wrong_customer' => 'Wrong customer',
        'wrong_quantity_or_price' => 'Wrong quantity or price',
        'wrong_fuel_type' => 'Wrong fuel type',
        'duplicate_entry' => 'Duplicate entry',
        'customer_cancelled' => 'Customer cancelled the order',
        'other' => 'Other',
    ];

    /**
     * How the order reaches the client (Part 2 revisions). Standard orders are delivered by their own truck (Delivery
     * Details) and deducted from their purchases when Delivered; Depot to Client orders ride on a lift and are
     * deducted when the lift is completed.
     */
    public const DELIVERY_TYPES = ['depot_to_client' => 'Depot to Client', 'standard' => 'Standard'];

    /** Delivery statuses a Standard order can be recorded with (Delivered deducts the stock on save). */
    private const NEW_ORDER_DELIVERY_STATUSES = ['pending', 'out_for_delivery', 'delivered'];

    /** Payment Status shown read-only in the sale modals, worked out from recorded payments. */
    private const PAYMENT_STATUS_LABELS = ['unpaid' => 'Unpaid', 'partially_paid' => 'Partially Paid', 'paid' => 'Fully Paid'];

    private const RECEIVABLE_FILTER_STATUSES = ['unpaid' => 'Unpaid', 'partial' => 'Partially Paid', 'overdue' => 'Overdue', 'clear' => 'Settled', 'cancelled' => 'Cancelled'];

    public function index(Request $request, DashboardSummaryService $dashboardSummary, string $state = 'receivables'): View
    {
        $activeTab = $state === 'customers' ? 'customers' : 'receivables';
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in($activeTab === 'customers' ? self::ACCOUNT_STATUSES : array_keys(self::RECEIVABLE_FILTER_STATUSES))],
            'delivery_status' => ['nullable', Rule::in(self::DELIVERY_STATUSES)],
            'delivery_type' => ['nullable', Rule::in(array_keys(self::DELIVERY_TYPES))],
            'date' => ['nullable', 'date'],
            'fuel_type_id' => ['nullable', 'integer', Rule::exists('fuel_types', 'id')],
            'location' => ['nullable', 'string', 'max:255'],
        ]);

        $search = trim((string) ($data['search'] ?? ''));
        $normalizedSearch = $search === '' ? null : $search;
        // Filters belong to the tab whose toolbar submitted them; the other tab stays unfiltered.
        $filters = array_filter([
            'status' => $data['status'] ?? null,
            'delivery_status' => $data['delivery_status'] ?? null,
            'delivery_type' => $data['delivery_type'] ?? null,
            'date' => $data['date'] ?? null,
            'fuel_type_id' => $data['fuel_type_id'] ?? null,
            'location' => $data['location'] ?? null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');

        return view('sales-officer.sales', [
            'activeTab' => $activeTab,
            'search' => $normalizedSearch,
            'filters' => $filters,
            'filterOptions' => [
                'receivableStatuses' => self::RECEIVABLE_FILTER_STATUSES,
                'deliveryStatuses' => collect(self::DELIVERY_STATUSES)->mapWithKeys(fn (string $status): array => [$status => $this->label($status)])->all(),
                'deliveryTypes' => self::DELIVERY_TYPES,
                'accountStatuses' => collect(self::ACCOUNT_STATUSES)->mapWithKeys(fn (string $status): array => [$status => $this->label($status)])->all(),
                'locations' => DB::table('customers')->whereNotNull('location')->where('location', '!=', '')->distinct()->orderBy('location')->pluck('location', 'location'),
            ],
            'summaryCards' => $dashboardSummary->salesCards(),
            'sales' => $this->salesRows($normalizedSearch, filters: $activeTab === 'receivables' ? $filters : []),
            'customers' => $this->customerRows($normalizedSearch, filters: $activeTab === 'customers' ? $filters : []),
            'customerOptions' => $this->customerOptions($state === 'customers' ? $normalizedSearch : null),
            'fuelTypes' => $this->fuelTypeOptions(),
            'deliveryTypes' => self::DELIVERY_TYPES,
            'f1Percentages' => F1Split::PERCENTAGES,
            'f1EligibleCodes' => F1Split::ELIGIBLE_CODES,
            // Part 2 revisions: the purchases an order's fuel and its F1 portion are deducted from.
            'purchaseOptions' => $purchaseOptions = $this->purchaseStock->saleOptions($this->purchaseIdsOnSales()),
            'f1PurchaseOptions' => $purchaseOptions->where('fuel_code', F1Split::F1_CODE)->values(),
            'newOrderDeliveryStatuses' => self::NEW_ORDER_DELIVERY_STATUSES,
            'deliveryTrucks' => DB::table('trucks')->where('status', '!=', 'inactive')->orderBy('truck_code')->get(['id', 'truck_code', 'plate_number']),
            'deliveryDrivers' => DB::table('users')->where('role', 'driver')->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'paymentStatuses' => self::PAYMENT_STATUSES,
            'accountStatuses' => self::ACCOUNT_STATUSES,
            'saleStatuses' => self::SALE_STATUSES,
            'editableSaleStatuses' => array_values(array_diff(self::SALE_STATUSES, ['paid', 'cancelled'])),
            'editableDeliveryStatuses' => array_values(array_diff(self::DELIVERY_STATUSES, ['cancelled'])),
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
                ->route($this->salesRedirectRoute($request))
                ->with('status', 'Payment record was already submitted.');
        }

        try {
            $idempotency = $this->idempotencyService->run(
                $token,
                'payments.store',
                (int) $request->user()->id,
                function () use ($request, $sale, $data): array {
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
                        throw new \RuntimeException('The selected sale is not eligible for payment.');
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
                        throw new \RuntimeException('The selected sale has no billable items.');
                    }

                    if ($remaining <= 0) {
                        throw new \RuntimeException('The selected sale is already fully paid.');
                    }

                    if ($amount > $remaining) {
                        throw ValidationException::withMessages([
                            'amount' => 'Amount paid cannot exceed the remaining balance of PHP '.number_format($remaining, 2).'.',
                        ]);
                    }

                    $paymentSchedule = null;

                    if (! empty($data['payment_schedule_id'])) {
                        $paymentSchedule = $this->paymentScheduleForUpdate((int) $data['payment_schedule_id'], (int) $saleRow->id);

                        if (! $paymentSchedule) {
                            throw new \RuntimeException('The selected installment schedule does not belong to this sale.');
                        }

                        $schedulePaid = $this->paidTotalForSchedule((int) $paymentSchedule->id);
                        $scheduleRemaining = round((float) $paymentSchedule->amount_due - $schedulePaid, 2);

                        if ($scheduleRemaining <= 0) {
                            throw new \RuntimeException('The selected installment is already fully paid.');
                        }

                        if ($amount > $scheduleRemaining) {
                            throw ValidationException::withMessages([
                                'amount' => 'Amount paid cannot exceed the selected installment balance of PHP '.number_format($scheduleRemaining, 2).'.',
                            ]);
                        }
                    }

                    $referenceNumber = isset($data['reference_number']) && trim((string) $data['reference_number']) !== ''
                        ? trim((string) $data['reference_number'])
                        : null;

                    return $this->recordPayment(
                        (int) $saleRow->id,
                        $paymentSchedule?->id ? (int) $paymentSchedule->id : null,
                        (string) $data['payment_date'],
                        $amount,
                        (string) $data['method'],
                        $referenceNumber,
                        $data['remarks'] ?? null,
                        (int) $request->user()->id
                    );
                }
            );
        } catch (\RuntimeException $e) {
            return back()
                ->withInput()
                ->withErrors(['payment' => $e->getMessage()]);
        }

        if ($idempotency['duplicate']) {
            return redirect()
                ->route($this->salesRedirectRoute($request))
                ->with('status', 'Payment record was already submitted.');
        }

        $request->session()->put($sessionKey, $idempotency['response_reference']);

        return redirect()
            ->route($this->salesRedirectRoute($request))
            ->with('payment_sale_id', $sale)
            ->with('status', 'Payment record '.$idempotency['response_reference'].' recorded successfully.');
    }

    public function storeSale(Request $request): RedirectResponse
    {
        $data = $this->validatedSaleData($request);
        $token = (string) $data['idempotency_key'];
        $sessionKey = 'sales.created.'.$token;

        if ($request->session()->has($sessionKey)) {
            return redirect()
                ->route($this->salesRedirectRoute($request))
                ->with('status', 'Sale record was already submitted.');
        }

        $requestedStatus = $data['status'] ?? 'confirmed';
        $items = $this->normalizedSaleItems($data);
        $saleTotal = 0.0;
        foreach ($items as $item) {
            $lineTotal = (float) $item['line_total'];
            if ($lineTotal > 999999999999.99) {
                return back()
                    ->withErrors(['quantity_liters' => 'Calculated line total exceeds allowed limit.'])
                    ->withInput();
            }
            $saleTotal += $lineTotal;
        }

        $initialAmountPaid = round((float) ($data['amount_paid'] ?? 0), 2);
        if ($initialAmountPaid > round($saleTotal, 2)) {
            return back()
                ->withErrors(['amount_paid' => 'Amount paid cannot exceed the sale total.'])
                ->withInput();
        }

        if ($initialAmountPaid > 0 && in_array($requestedStatus, ['draft', 'cancelled'], true)) {
            return back()
                ->withErrors(['amount_paid' => 'Amount paid can only be recorded for a confirmed sale.'])
                ->withInput();
        }

        $initialReference = $this->blankToNull($data['payment_reference_number'] ?? null);
        if ($initialAmountPaid > 0 && $initialReference === null && in_array($data['payment_method'], ['cheque', 'bank_transfer'], true)) {
            return back()
                ->withErrors(['payment_reference_number' => 'Enter the cheque or bank reference number for this payment.'])
                ->withInput();
        }

        try {
            $idempotency = $this->idempotencyService->run(
                $token,
                'sales.store',
                (int) $request->user()->id,
                function () use ($request, $data, $items, $requestedStatus, $initialAmountPaid, $initialReference): array {
                    return $this->idempotencyService->retryOnCollision('sale_code', function () use ($request, $data, $items, $requestedStatus, $initialAmountPaid, $initialReference): array {
                        $saleCode = $this->saleCode();

                        $saleId = DB::table('sales')->insertGetId([
                            'sale_code' => $saleCode,
                            'sales_order_number' => $this->blankToNull($data['sales_order_number'] ?? null),
                            'delivery_type' => $data['delivery_type'],
                            'customer_id' => $data['customer_id'],
                            'sale_date' => $data['sale_date'],
                            'payment_method' => $data['payment_method'],
                            'payment_terms' => $data['payment_terms'] ?? $this->defaultPaymentTerms($data['payment_method']),
                            'status' => $requestedStatus === 'cancelled' ? 'cancelled' : 'draft',
                            'delivery_status' => $requestedStatus === 'cancelled' ? 'cancelled' : 'pending',
                            // Standard orders: Delivery Details (Depot to Client orders take them from their lift).
                            'delivery_truck_id' => $data['delivery_truck_id'] ?? null,
                            'delivery_driver_user_id' => $data['delivery_driver_user_id'] ?? null,
                            'delivery_date' => $data['delivery_date'] ?? null,
                            'created_by' => $request->user()->id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        $this->assertPurchaseStock($items);
                        $now = now();
                        foreach ($items as $item) {
                            DB::table('sale_items')->insertGetId($this->saleItemRow($saleId, $item, $now));
                        }

                        DB::table('receivables')->insert([
                            'sale_id' => $saleId,
                            'due_date' => $data['due_date'] ?? null,
                            'status' => $requestedStatus === 'cancelled' ? 'clear' : 'pending',
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);

                        if (! in_array($requestedStatus, ['draft', 'cancelled'], true)) {
                            $this->saleConfirmationService->confirm((int) $saleId, (int) $request->user()->id);
                        }

                        if ($initialAmountPaid > 0) {
                            $this->recordPayment(
                                (int) $saleId,
                                null,
                                (string) ($data['payment_date'] ?? $data['sale_date']),
                                $initialAmountPaid,
                                (string) $data['payment_method'],
                                $initialReference,
                                $this->blankToNull($data['payment_remarks'] ?? null) ?? 'Initial payment recorded with sale',
                                (int) $request->user()->id
                            );
                        }

                        // A Standard order recorded as Out for Delivery / Delivered moves on now; Delivered deducts its
                        // liters from its purchases on save.
                        $deliveryStatus = $data['delivery_status'] ?? 'pending';
                        if ($deliveryStatus !== 'pending' && ! in_array($requestedStatus, ['draft', 'cancelled'], true)) {
                            $this->saleConfirmationService->updateDeliveryStatus((int) $saleId, $deliveryStatus, (int) $request->user()->id);
                        }

                        return [
                            'reference_id' => $saleId,
                            'response_reference' => $saleCode,
                        ];
                    });
                }
            );
        } catch (\RuntimeException $exception) {
            return back()
                ->withInput()
                ->withErrors(['sale' => $exception->getMessage()]);
        } catch (QueryException) {
            return back()
                ->withInput()
                ->withErrors(['sale' => 'Sale record could not be saved. Please review the details and try again.']);
        }

        if ($idempotency['duplicate']) {
            return redirect()
                ->route($this->salesRedirectRoute($request))
                ->with('status', 'Sale record was already submitted.');
        }

        $request->session()->put($sessionKey, $idempotency['response_reference']);

        return redirect()
            ->route($this->salesRedirectRoute($request))
            ->with('status', 'Sale record '.$idempotency['response_reference'].' created successfully.');
    }

    public function updateSale(Request $request, int $sale): RedirectResponse
    {
        $data = $this->validatedSaleData($request, $sale);
        $row = $this->saleForUpdate($sale);

        abort_unless($row !== null, 404);

        if ($row->status === 'cancelled') {
            return back()->withErrors(['sale' => 'Cancelled sales are read-only and remain available for audit history.']);
        }

        $hasDependencies = $this->hasSaleDependentActivity($sale);
        // Once payments exist the status is derived from them (Unpaid / Partially Paid / Paid), so the
        // edit form cannot change it; this keeps fully paid sales editable (order no., due date, etc.).
        $requestedStatus = $this->paidTotalForSale($sale) > 0
            ? $row->status
            : ($data['status'] ?? $row->status);

        if ($requestedStatus === 'cancelled') {
            return back()
                ->withInput()
                ->withErrors(['sale' => 'Sales cannot be cancelled through edit. Use the cancel sale action instead.']);
        }

        if ($hasDependencies) {
            if ($this->changesProtectedSaleFields($row, $data)) {
                return back()
                    ->withInput()
                    ->withErrors(['sale' => 'This sale already has payment, stock-out, inventory release, or haul activity, so customer, fuel, quantity, price, and date cannot be changed.']);
            }

            if (in_array($requestedStatus, ['draft', 'cancelled'], true)) {
                return back()
                    ->withInput()
                    ->withErrors(['sale' => 'This sale already has dependent activity and cannot be changed to draft or cancelled.']);
            }
        }

        $saleTotalBefore = $this->saleTotalForUpdate($sale);
        $paidTotalBefore = $this->paidTotalForSale($sale);
        if ($saleTotalBefore > 0 && $paidTotalBefore >= $saleTotalBefore && $requestedStatus !== 'paid') {
            return back()
                ->withInput()
                ->withErrors(['sale' => 'A paid sale cannot be changed to a non-paid status.']);
        }

        $items = $this->normalizedSaleItems($data, $sale);
        foreach ($items as $item) {
            if ((float) $item['line_total'] > 999999999999.99) {
                return back()
                    ->withInput()
                    ->withErrors(['quantity_liters' => 'Calculated line total exceeds allowed limit.']);
            }
        }

        try {
            DB::transaction(function () use ($sale, $row, $data, $items, $hasDependencies, $request, $requestedStatus): void {

                DB::table('sales')
                    ->where('id', $sale)
                    ->update([
                        'sales_order_number' => $this->blankToNull($data['sales_order_number'] ?? null),
                        'customer_id' => $data['customer_id'],
                        'sale_date' => $data['sale_date'],
                        'payment_method' => $data['payment_method'],
                        'payment_terms' => $data['payment_terms'] ?? $this->defaultPaymentTerms($data['payment_method']),
                        'updated_at' => now(),
                    ] + ($data['delivery_type'] === 'standard' ? [
                        // Standard orders: Delivery Details stay editable.
                        'delivery_truck_id' => $data['delivery_truck_id'] ?? null,
                        'delivery_driver_user_id' => $data['delivery_driver_user_id'] ?? null,
                        'delivery_date' => $data['delivery_date'] ?? null,
                    ] : []));

                if (! $hasDependencies && $this->changesProtectedSaleFields($row, $data)) {
                    DB::table('sale_items')->where('sale_id', $sale)->delete();
                    // Checked after the old lines are gone, so this sale's own reservation is not counted twice.
                    $this->assertPurchaseStock($items);

                    $now = now();
                    foreach ($items as $item) {
                        DB::table('sale_items')->insert($this->saleItemRow($sale, $item, $now));
                    }
                }

                if (isset($data['due_date'])) {
                    DB::table('receivables')
                        ->where('sale_id', $sale)
                        ->update([
                            'due_date' => $data['due_date'],
                            'updated_at' => now(),
                        ]);
                }

                if ($row->status === 'draft' && $requestedStatus !== 'draft') {
                    $this->saleConfirmationService->confirm($sale, (int) $request->user()->id);
                } else {
                    if ($row->status === 'draft' && $requestedStatus === 'draft') {
                        DB::table('sales')->where('id', $sale)->update(['status' => 'draft', 'updated_at' => now()]);
                    }
                    $this->saleConfirmationService->reconcile($sale);
                }
            });
        } catch (\RuntimeException $exception) {
            return back()
                ->withInput()
                ->withErrors(['sale' => $exception->getMessage()]);
        }

        return redirect()
            ->route($this->salesRedirectRoute($request))
            ->with('status', 'Sale record updated successfully.');
    }

    public function cancelSale(Request $request, int $sale): RedirectResponse
    {
        // An untouched draft is deleted outright; anything else is voided and must say why.
        $reason = null;
        if ($this->saleCorrectionService->correctionMode($sale) === 'cancel') {
            $data = $request->validate([
                'cancel_reason' => ['required', Rule::in(array_keys(self::CANCEL_REASONS))],
                'cancel_note' => ['nullable', 'required_if:cancel_reason,other', 'string', 'max:400'],
            ], [
                'cancel_reason.required' => 'Select why this sale is being cancelled.',
                'cancel_note.required_if' => 'Describe the reason when "Other" is selected.',
            ]);
            $note = trim((string) ($data['cancel_note'] ?? ''));
            $reason = self::CANCEL_REASONS[$data['cancel_reason']].($note !== '' ? ' — '.$note : '');
        }

        try {
            $result = $this->saleCorrectionService->correct(
                $sale,
                (int) $request->user()->id,
                $reason
            );
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === 'Sale record not found.') {
                abort(404);
            }

            return back()->withErrors(['sale' => $exception->getMessage()]);
        }

        return redirect()
            ->route($this->salesRedirectRoute($request))
            ->with('status', $result === 'deleted'
                ? 'Untouched draft sale deleted successfully.'
                : 'Sale cancelled successfully. Related financial and inventory history was preserved and reconciled.');
    }

    public function updateDeliveryStatus(Request $request, int $sale): RedirectResponse
    {
        $data = $request->validate([
            'delivery_status' => ['required', Rule::in(self::DELIVERY_STATUSES)],
        ]);

        try {
            $this->saleConfirmationService->updateDeliveryStatus(
                $sale,
                (string) $data['delivery_status'],
                (int) $request->user()->id
            );
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === 'Sale record not found.') {
                abort(404);
            }

            return back()->withErrors(['delivery_status' => $exception->getMessage()]);
        }

        return redirect()
            ->route($this->salesRedirectRoute($request))
            ->with('status', 'Delivery status updated successfully.');
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
                'bank_name' => $this->blankToNull($data['bank_name'] ?? null),
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

        return redirect()->route($this->customersRedirectRoute(request()))
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
                    'bank_name' => $this->blankToNull($data['bank_name'] ?? null),
                    'payment_status' => $data['payment_status'],
                    'status' => $data['status'],
                    'updated_at' => now(),
                ]);
        } catch (QueryException) {
            return back()
                ->withInput()
                ->withErrors(['company_name' => 'Customer record could not be updated. Please review the customer details and try again.']);
        }

        return redirect()->route($this->customersRedirectRoute(request()))
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

        return redirect()->route($this->customersRedirectRoute(request()))
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
            'bank_name' => ['nullable', 'string', 'max:255'],
            'payment_status' => ['required', Rule::in(self::PAYMENT_STATUSES)],
            'status' => ['required', Rule::in(self::ACCOUNT_STATUSES)],
        ]);
    }

    /**
     * @param  array{status?: string, location?: string}  $filters
     */
    private function customerRows(?string $search, ?int $perPage = 25, array $filters = [])
    {
        $query = DB::table('customers')
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($filters['location'] ?? null, fn (Builder $query, string $location): Builder => $query->where('location', $location))
            ->when($search, fn (Builder $query): Builder => $this->search($query, $search, [
                'customer_code',
                'name',
                'company_name',
                'location',
                'email',
                'phone',
                'bank_name',
                'payment_status',
                'status',
            ]))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $columns = [
            'id',
            'customer_code',
            'name',
            'company_name',
            'location',
            'email',
            'phone',
            'bank_name',
            'payment_status',
            'status',
            'created_at',
            'updated_at',
        ];

        $paginator = null;
        if ($perPage !== null) {
            $paginator = $query->paginate($perPage, $columns, 'customers_page')->withQueryString();
            $rows = $paginator->getCollection();
        } else {
            $rows = $query->get($columns);
        }

        $customerIds = $rows
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $transactionSummaries = $this->transactionSummaries($customerIds);
        $outstandingTotals = $this->customerOutstandingTotals($customerIds);

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

        // Payments recorded on cancelled sales are owed back to the customer.
        $refundDueTotals = $customerIds->isEmpty()
            ? []
            : DB::table('payments')
                ->join('sales', 'sales.id', '=', 'payments.sale_id')
                ->whereIn('sales.customer_id', $customerIds->all())
                ->whereNull('sales.deleted_at')
                ->where('sales.status', 'cancelled')
                ->groupBy('sales.customer_id')
                ->selectRaw('sales.customer_id, SUM(payments.amount) as refund_due')
                ->pluck('refund_due', 'customer_id')
                ->mapWithKeys(fn (mixed $amount, mixed $id): array => [(int) $id => (float) $amount])
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

        $transformed = $rows
            ->map(function (object $row) use ($transactionSummaries, $outstandingTotals, $overdueCustomerIds, $hasSalesCustomerIds, $refundDueTotals): array {
                $cid = (int) $row->id;
                $outstanding = (float) ($outstandingTotals[$cid] ?? 0);
                $hasOverdue = isset($overdueCustomerIds[$cid]);
                $hasSales = isset($hasSalesCustomerIds[$cid]);
                $refundDue = (float) ($refundDueTotals[$cid] ?? 0);
                $derivedPaymentStatus = $hasSales
                    ? ($hasOverdue ? 'overdue' : ($outstanding > 0 ? 'pending' : ($refundDue > 0 ? 'refund_due' : 'clear')))
                    : ($row->payment_status ?: 'clear');

                return [
                    'id' => $cid,
                    'modal_id' => 'so-customer-edit-'.$row->id,
                    'customer_code' => $row->customer_code,
                    'name' => $row->name,
                    'company_name' => $row->company_name,
                    'location' => $row->location,
                    'email' => $row->email,
                    'phone' => $row->phone,
                    'bank_name' => $row->bank_name,
                    'payment_status' => $derivedPaymentStatus,
                    'status' => $row->status,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                    'class' => $row->status === 'inactive' ? 'row-danger' : $this->rowClass($derivedPaymentStatus),
                    'cells' => [
                        $row->customer_code,
                        $row->name,
                        $row->company_name,
                        $row->location ?: 'N/A',
                        $row->email ?: 'N/A',
                        $row->phone ?: 'N/A',
                        $row->bank_name ?: 'N/A',
                        $this->label($derivedPaymentStatus),
                    ],
                    'details' => [
                        'Customer Name' => $row->name,
                        'Company Name' => $row->company_name,
                        'Location' => $row->location ?: 'N/A',
                        'Email' => $row->email ?: 'N/A',
                        'Contact Number' => $row->phone ?: 'N/A',
                        'Bank Name' => $row->bank_name ?: 'N/A',
                        'Payment Status' => $this->label($derivedPaymentStatus),
                        'Account Status' => $this->label($row->status),
                        'Date Added' => $this->formatDateTime($row->created_at),
                        'Last Updated' => $this->formatDateTime($row->updated_at),
                        'Transactions' => $transactionSummaries[$cid] ?? 'No transaction records found',
                        'Outstanding Receivables' => 'PHP '.$this->formatNumber($outstandingTotals[$cid] ?? 0),
                    ],
                ];
            });

        return $paginator ? $paginator->setCollection($transformed) : $transformed;
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

    /**
     * @param  array{status?: string, delivery_status?: string, date?: string, fuel_type_id?: int|string}  $filters
     */
    private function salesRows(?string $search, ?int $perPage = 25, array $filters = [])
    {
        $payments = DB::table('payments')
            ->selectRaw('sale_id, COALESCE(SUM(amount), 0) as total_paid')
            ->groupBy('sale_id');

        $items = DB::table('sale_items')
            ->join('fuel_types', 'fuel_types.id', '=', 'sale_items.fuel_type_id')
            ->selectRaw('sale_items.sale_id, COUNT(*) as item_count, SUM(sale_items.quantity_liters) as total_quantity_liters, SUM(sale_items.line_total) as sale_total, MIN(fuel_types.name) as first_fuel_name')
            ->groupBy('sale_items.sale_id');

        $query = DB::table('sales')
            ->joinSub($items, 'items_total', 'items_total.sale_id', '=', 'sales.id')
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->leftJoin('receivables', 'receivables.sale_id', '=', 'sales.id')
            ->leftJoinSub($payments, 'payments_total', 'payments_total.sale_id', '=', 'sales.id')
            ->leftJoin('trucks as delivery_trucks', 'delivery_trucks.id', '=', 'sales.delivery_truck_id')
            ->leftJoin('users as delivery_drivers', 'delivery_drivers.id', '=', 'sales.delivery_driver_user_id')
            ->whereNull('sales.deleted_at')
            ->when($search, fn (Builder $query): Builder => $this->search($query, $search, [
                'sales.sale_code',
                'sales.sales_order_number',
                'customers.name',
                'customers.company_name',
                'items_total.first_fuel_name',
                'sales.status',
                'sales.delivery_status',
                'sales.payment_method',
                'receivables.status',
            ]))
            ->when($filters['date'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('sales.sale_date', $date))
            ->when($filters['delivery_status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('sales.delivery_status', $status))
            ->when($filters['delivery_type'] ?? null, fn (Builder $query, string $type): Builder => $query->where('sales.delivery_type', $type))
            ->when($filters['fuel_type_id'] ?? null, fn (Builder $query, mixed $fuelTypeId): Builder => $query->whereExists(
                fn (Builder $exists): Builder => $exists->selectRaw('1')->from('sale_items as filter_items')
                    ->whereColumn('filter_items.sale_id', 'sales.id')
                    ->where('filter_items.fuel_type_id', (int) $fuelTypeId)
            ))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $this->whereReceivableStatus($query, $status))
            ->orderByDesc('sales.sale_date')
            ->orderByDesc('sales.id');

        $columns = [
            'sales.id as sale_id',
            'sales.sale_code',
            'sales.sales_order_number',
            'sales.delivery_type',
            'sales.sale_date',
            'sales.customer_id',
            'sales.payment_method',
            'sales.payment_terms',
            'sales.status',
            'sales.delivery_status',
            'sales.cancellation_reason',
            'customers.name as customer_name',
            'customers.company_name',
            'items_total.item_count',
            'items_total.total_quantity_liters',
            'items_total.sale_total',
            'items_total.first_fuel_name',
            'receivables.due_date',
            'receivables.status as receivable_status',
            'payments_total.total_paid',
            'sales.delivery_truck_id',
            'sales.delivery_driver_user_id',
            'sales.delivery_date',
            'delivery_trucks.truck_code as delivery_truck_code',
            'delivery_trucks.plate_number as delivery_truck_plate',
            'delivery_drivers.name as delivery_driver_name',
        ];

        $paginator = null;
        if ($perPage !== null) {
            $paginator = $query->paginate($perPage, $columns, 'sales_page')->withQueryString();
            $rows = $paginator->getCollection();
        } else {
            $rows = $query->get($columns);
        }

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

        $transformed = $rows
            ->map(function (object $row) use ($itemsBySale, $latestPaymentDates, $dependencyMap, $paymentsBySale, $schedulesBySale): array {
                $saleId = (int) $row->sale_id;
                $paid = (float) ($row->total_paid ?? 0);
                $saleTotal = (float) $row->sale_total;
                $isCancelled = $row->status === 'cancelled';
                $balance = $isCancelled ? 0 : max(0, $saleTotal - $paid);
                // Payments on a voided sale are owed back to the customer, so they are not "clear".
                $refundDue = $isCancelled ? round($paid, 2) : 0.0;
                $receivableStatus = $isCancelled ? ($refundDue > 0 ? 'refund_due' : 'clear') : $this->receivableStatusForSale($saleTotal, $paid, $row->due_date);
                $status = match (true) {
                    $refundDue > 0 => 'Refund due: ₱'.number_format($refundDue, 2),
                    $isCancelled => 'Cancelled',
                    default => $this->receivableStatusLabel($receivableStatus),
                };
                $saleItems = $itemsBySale[$saleId] ?? [];
                $latestPaymentDate = $latestPaymentDates[$saleId] ?? null;
                $correctionAction = $this->saleCorrectionService->correctionMode($saleId);
                $deliveryType = self::DELIVERY_TYPES[$row->delivery_type] ?? self::DELIVERY_TYPES['standard'];
                $isStandard = $row->delivery_type !== 'depot_to_client';
                $deliveryTruck = $row->delivery_truck_code ? trim($row->delivery_truck_code.($row->delivery_truck_plate ? ' / '.$row->delivery_truck_plate : '')) : 'N/A';
                // Part 9: Payment Status is read-only and follows the recorded payments.
                $paymentStatus = self::PAYMENT_STATUS_LABELS[$this->salePaymentStatus($saleTotal, $paid)];
                $f1Summary = collect($saleItems)->filter(fn (array $item): bool => $item['f1_quantity_liters'] > 0)
                    ->map(fn (array $item): string => $item['fuel_name'].' '.$this->formatNumber($item['main_quantity_liters']).' L + F1 '.$this->formatNumber($item['f1_quantity_liters']).' L ('.rtrim(rtrim(number_format($item['f1_percentage'], 2), '0'), '.').'%)')
                    ->implode('; ');

                return [
                    'id' => $saleId,
                    'modal_id' => 'so-sales-edit-'.$row->sale_id,
                    'payment_id' => 'so-payment-history-'.$row->sale_id,
                    'payment_token' => (string) Str::uuid(),
                    'sale_code' => $row->sale_code,
                    'sales_order_number' => $row->sales_order_number,
                    'delivery_type' => $row->delivery_type,
                    'delivery_type_label' => $deliveryType,
                    'delivery_truck_id' => $row->delivery_truck_id,
                    'delivery_driver_user_id' => $row->delivery_driver_user_id,
                    'delivery_date' => $row->delivery_date ? date('Y-m-d\\TH:i', strtotime((string) $row->delivery_date)) : null,
                    'payment_status' => $paymentStatus,
                    'sale_date' => $row->sale_date,
                    'customer_id' => (int) $row->customer_id,
                    'payment_method' => $row->payment_method,
                    'payment_terms' => $row->payment_terms,
                    'status' => $row->status,
                    'delivery_status' => $row->delivery_status,
                    'receivable_status' => $receivableStatus,
                    'due_date' => $row->due_date,
                    'latest_payment_date' => $latestPaymentDate,
                    'has_dependencies' => $dependencyMap[$saleId] ?? false,
                    'correction_action' => $correctionAction,
                    'refund_due' => $refundDue,
                    'items' => $saleItems,
                    // Part 8 #9: white by default; red only while money is still owed. Cancelled sales stay white.
                    'class' => ! $isCancelled && $balance > 0 ? 'row-danger' : '',
                    'cells' => [
                        $row->sale_code,
                        $row->sales_order_number ?: 'N/A',
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
                        $this->label($row->delivery_status),
                        $status,
                    ],
                    'details' => [
                        'Delivery Type' => $deliveryType,
                        'Sales Order Number' => $row->sales_order_number ?: 'N/A',
                        'Transaction Date' => $this->formatDate($row->sale_date),
                        'Customer Name' => $row->customer_name,
                        'Company Name' => $row->company_name,
                        'Fuel Type' => $this->fuelSummary((string) $row->first_fuel_name, (int) $row->item_count),
                        'Quantity Ordered' => $this->formatLiters($row->total_quantity_liters),
                        'Purchase ID' => collect($saleItems)->pluck('source')->implode('; ') ?: 'N/A',
                    ] + ($f1Summary !== '' ? ['F1 Split' => $f1Summary] : []) + ($isStandard ? [
                        'Truck ID' => $deliveryTruck,
                        'Driver Name' => $row->delivery_driver_name ?: 'N/A',
                        'Delivery Date' => $row->delivery_date ? $this->formatDateTime($row->delivery_date) : 'N/A',
                    ] : []) + [
                        'Quantity Fulfilled' => $this->formatLiters(collect($saleItems)->sum('fulfilled_quantity_liters')),
                        'Total' => $this->formatNumber($saleTotal),
                        'Total Paid' => $this->formatNumber($paid),
                        'Balance' => $this->formatNumber($balance),
                        'Payment Status' => $paymentStatus,
                        'Due Date' => $row->due_date ? $this->formatDate($row->due_date) : 'N/A',
                        'Latest Payment Date' => $latestPaymentDate ? $this->formatDate($latestPaymentDate) : 'N/A',
                        'Receivable Status' => $status,
                        'Delivery Status' => $this->label($row->delivery_status),
                        'Payment Terms' => $row->payment_terms === 'cod' ? 'COD' : $this->label($row->payment_terms),
                        'Payment Method' => $this->paymentMethodLabel($row->payment_method),
                        'Order Status' => $this->label($row->status),
                    ] + ($isCancelled ? ['Cancellation Reason' => $row->cancellation_reason ?: 'N/A'] : []),
                    'payments' => $paymentsBySale[$saleId] ?? [],
                    'payment_schedules' => $schedulesBySale[$saleId] ?? [],
                    'sale_total' => $this->formatNumber($saleTotal),
                    'total_paid' => $this->formatNumber($paid),
                    'balance' => $this->formatNumber($balance),
                ];
            });

        return $paginator ? $paginator->setCollection($transformed) : $transformed;
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedSaleData(Request $request, ?int $saleId = null): array
    {
        $current = $saleId ? DB::table('sales')->where('id', $saleId)->first(['payment_method', 'payment_terms', 'delivery_type']) : null;
        // The delivery type is chosen when the order is recorded and stays fixed (lifts may link to it).
        $isDepot = ($current->delivery_type ?? $request->input('delivery_type')) === 'depot_to_client';
        $isStandard = ! $isDepot;

        $data = $request->validate([
            'idempotency_key' => [$saleId ? 'nullable' : 'required', 'uuid'],
            // "garage_to_client" is the pre-October 22, 2026 name of Standard.
            'delivery_type' => [$saleId ? 'exclude' : 'nullable', Rule::in([...array_keys(self::DELIVERY_TYPES), 'garage_to_client'])],
            'sales_order_number' => ['nullable', 'string', 'max:60', Rule::unique('sales', 'sales_order_number')->ignore($saleId)->whereNull('deleted_at')],
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where(fn (Builder $query): Builder => $query->where('status', 'active'))],
            'sale_date' => ['required', 'date'],
            // Part 2 revisions: an order line names the purchase its fuel is deducted from ("PUR-022 · PREMIUM").
            'items' => [$saleId ? 'nullable' : 'required', 'array', 'min:1'],
            'items.*.purchase_item_id' => [$saleId ? 'nullable' : 'required', 'integer', Rule::exists('purchase_items', 'id')],
            // Only lines recorded before purchases were chosen send their fuel instead.
            'items.*.fuel_type_id' => ['nullable', 'integer', ApprovedFuelType::rule()],
            'items.*.quantity_liters' => ['required_with:items', 'numeric', 'gt:0', 'max:1000000'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'gt:0', 'max:10000'],
            // F1 portion (Unleaded / Premium lines): its own F1 purchase and F1 price.
            'items.*.f1_percentage' => [
                'nullable',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value !== null && $value !== '' && (float) $value !== 0.0 && ! in_array((float) $value, array_map('floatval', F1Split::PERCENTAGES), true)) {
                        $fail('Select an F1 % of 10%, 15% or 17%.');
                    }
                },
            ],
            'items.*.f1_purchase_item_id' => ['nullable', 'integer', Rule::exists('purchase_items', 'id')],
            'items.*.f1_unit_price' => ['nullable', 'numeric', 'gte:0', 'max:10000'],
            'amount_paid' => [$saleId ? 'prohibited' : 'nullable', 'numeric', 'gte:0', 'max:999999999999.99'],
            'payment_reference_number' => [$saleId ? 'prohibited' : 'nullable', 'string', 'max:100'],
            'payment_date' => [$saleId ? 'prohibited' : 'nullable', 'date', 'after_or_equal:sale_date'],
            'payment_remarks' => [$saleId ? 'prohibited' : 'nullable', 'string', 'max:1000'],
            'payment_method' => ['required', Rule::in($this->allowedValues(self::PAYMENT_METHODS, self::LEGACY_PAYMENT_METHODS, $current?->payment_method))],
            'payment_terms' => ['nullable', Rule::in($this->allowedValues(self::PAYMENT_TERMS, self::LEGACY_PAYMENT_TERMS, $current?->payment_terms))],
            'status' => ['nullable', Rule::in(array_diff(self::SALE_STATUSES, ['paid']))],
            'due_date' => ['nullable', 'date', 'after_or_equal:sale_date'],
            // Standard orders only: Delivery Details. Depot to Client orders are delivered through their lift.
            'delivery_truck_id' => $isStandard ? ['nullable', 'integer', Rule::exists('trucks', 'id')] : ['exclude'],
            'delivery_driver_user_id' => $isStandard ? ['nullable', 'integer', Rule::exists('users', 'id')->where(fn (Builder $query): Builder => $query->where('role', 'driver'))] : ['exclude'],
            'delivery_date' => $isStandard ? ['nullable', 'date'] : ['exclude'],
            'delivery_status' => $isStandard && ! $saleId ? ['nullable', Rule::in(self::NEW_ORDER_DELIVERY_STATUSES)] : ['prohibited'],
            'created_by' => ['prohibited'],
            'line_total' => ['prohibited'],
            'total' => ['prohibited'],
            'total_paid' => ['prohibited'],
            'balance' => ['prohibited'],
            'delivered_at' => ['prohibited'],
            'delivery_status_updated_by' => ['prohibited'],
        ], [
            'items.required' => 'Add the order line.',
            'items.*.purchase_item_id.required' => 'Select the Fuel Type purchase.',
        ], [
            'delivery_truck_id' => 'truck',
            'delivery_driver_user_id' => 'driver',
            'delivery_date' => 'delivery date',
        ]);
        $data['delivery_type'] = $isDepot ? 'depot_to_client' : 'standard';

        return $data;
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
     * Order lines as saved (Part 2 revisions). The fuel comes from the selected purchase. An F1 % only counts on an
     * Unleaded or Premium line (otherwise 0%): f1_percentage of the ordered liters is F1, the rest the line's own fuel.
     * Line Total = main liters x Fuel Price + F1 liters x F1 Price.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function normalizedSaleItems(array $data, ?int $saleId = null): array
    {
        $rows = collect($data['items'] ?? [])->sortKeys()->values()->all();
        $existing = $saleId ? DB::table('sale_items')->where('sale_id', $saleId)->orderBy('id')->pluck('fuel_type_id')->values() : collect();
        $purchaseFuel = DB::table('purchase_items')
            ->whereIn('id', collect($rows)->pluck('purchase_item_id')->filter()->map(fn (mixed $id): int => (int) $id)->all() ?: [0])
            ->pluck('fuel_type_id', 'id');
        $eligible = F1Split::eligibleFuelIds();

        $items = [];
        foreach ($rows as $index => $row) {
            $purchaseId = ($row['purchase_item_id'] ?? null) ? (int) $row['purchase_item_id'] : null;
            $fuelTypeId = $purchaseId ? (int) ($purchaseFuel[$purchaseId] ?? 0) : (int) ($row['fuel_type_id'] ?? ($existing[$index] ?? 0));
            $quantity = round((float) $row['quantity_liters'], 2);
            $price = round((float) $row['unit_price'], 2);
            $percentage = in_array($fuelTypeId, $eligible, true) ? (float) ($row['f1_percentage'] ?? 0) : 0.0;
            [$main, $f1] = F1Split::split($quantity, $percentage);
            $f1PurchaseId = $f1 > 0 && ($row['f1_purchase_item_id'] ?? null) ? (int) $row['f1_purchase_item_id'] : null;
            $f1Price = $f1 > 0 ? round((float) ($row['f1_unit_price'] ?? 0), 2) : 0.0;

            $items[$index] = [
                'purchase_item_id' => $purchaseId,
                'fuel_type_id' => $fuelTypeId,
                'quantity_liters' => $quantity,
                'unit_price' => $price,
                'f1_percentage' => $percentage,
                'f1_purchase_item_id' => $f1PurchaseId,
                'f1_unit_price' => $f1Price,
                'main_quantity_liters' => $main,
                'f1_quantity_liters' => $f1,
                'line_total' => round($main * $price + $f1 * $f1Price, 2),
            ];
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function saleItemRow(int $saleId, array $item, mixed $now): array
    {
        return [
            'sale_id' => $saleId,
            'fuel_type_id' => $item['fuel_type_id'],
            'purchase_item_id' => $item['purchase_item_id'],
            'f1_purchase_item_id' => $item['f1_purchase_item_id'],
            'stock_in_movement_id' => null,
            'quantity_liters' => $item['quantity_liters'],
            'unit_price' => $item['unit_price'],
            'f1_unit_price' => $item['f1_unit_price'],
            'line_total' => $item['line_total'],
            'f1_percentage' => $item['f1_percentage'],
            'main_quantity_liters' => $item['main_quantity_liters'],
            'f1_quantity_liters' => $item['f1_quantity_liters'],
            'fulfilled_quantity_liters' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Each line names a usable purchase of its fuel (and an F1 purchase and F1 Price for an F1 portion), and its
     * purchases have the liters available: stock on hand less what other undelivered orders were promised.
     * Purchases are row-locked so two orders can't promise the same liters.
     *
     * @param  array<int, array<string, mixed>>  $items
     *
     * @throws ValidationException
     */
    private function assertPurchaseStock(array $items): void
    {
        $ids = collect($items)->flatMap(fn (array $item): array => [$item['purchase_item_id'], $item['f1_purchase_item_id']])->filter()->unique()->values()->all();
        $lines = $this->purchaseStock->lines($ids, true);
        $f1FuelId = F1Split::f1FuelId();
        $requested = [];
        $errors = [];

        foreach ($items as $index => $item) {
            $line = $item['purchase_item_id'] ? $lines->get($item['purchase_item_id']) : null;
            if (! $line) {
                $errors["items.$index.purchase_item_id"] = 'Select an available Fuel Type purchase.';
            } else {
                $requested[$line->id] = round(($requested[$line->id] ?? 0) + $item['main_quantity_liters'], 2);
                if ($requested[$line->id] > $line->available) {
                    $errors["items.$index.quantity_liters"] = 'Only '.number_format($line->available, 2).' L of '.$line->purchase_code.' ('.$line->fuel_name.') is available.';
                }
            }

            if ($item['f1_quantity_liters'] <= 0) {
                continue;
            }
            $f1Line = $item['f1_purchase_item_id'] ? $lines->get($item['f1_purchase_item_id']) : null;
            if (! $f1Line || (int) $f1Line->fuel_type_id !== $f1FuelId) {
                $errors["items.$index.f1_purchase_item_id"] = 'Select the F1 purchase for the '.number_format($item['f1_quantity_liters'], 2).' L F1 portion.';
            } else {
                $requested[$f1Line->id] = round(($requested[$f1Line->id] ?? 0) + $item['f1_quantity_liters'], 2);
                if ($requested[$f1Line->id] > $f1Line->available) {
                    $errors["items.$index.f1_purchase_item_id"] = 'Only '.number_format($f1Line->available, 2).' L of F1 is available in '.$f1Line->purchase_code.' for the '.number_format($item['f1_quantity_liters'], 2).' L F1 portion.';
                }
            }
            if ($item['f1_unit_price'] <= 0) {
                $errors["items.$index.f1_unit_price"] = 'Enter the F1 Price.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function saleForUpdate(int $sale): ?object
    {
        return DB::table('sales')
            ->where('sales.id', $sale)
            ->whereNull('sales.deleted_at')
            ->first([
                'sales.id',
                'sales.sales_order_number',
                'sales.delivery_type',
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
            || DB::table('haul_allocations')->where('sale_id', $saleId)->exists()
            || DB::table('sale_items')->where('sale_id', $saleId)->where('fulfilled_quantity_liters', '>', 0)->exists();
    }

    /**
     * @param  Collection<int, int>  $saleIds
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
     * @param  array<string, mixed>  $data
     */
    private function changesProtectedSaleFields(object $row, array $data): bool
    {
        $shape = fn (array $item): array => [
            'purchase_item_id' => $item['purchase_item_id'] ? (int) $item['purchase_item_id'] : null,
            'fuel_type_id' => (int) $item['fuel_type_id'],
            'quantity_liters' => number_format((float) $item['quantity_liters'], 2, '.', ''),
            'unit_price' => number_format((float) $item['unit_price'], 2, '.', ''),
            'f1_percentage' => number_format((float) $item['f1_percentage'], 2, '.', ''),
            'f1_purchase_item_id' => (float) $item['f1_quantity_liters'] > 0 && $item['f1_purchase_item_id'] ? (int) $item['f1_purchase_item_id'] : null,
            'f1_unit_price' => number_format((float) $item['f1_quantity_liters'] > 0 ? (float) $item['f1_unit_price'] : 0, 2, '.', ''),
        ];

        $existingItems = DB::table('sale_items')
            ->where('sale_id', $row->id)
            ->orderBy('id')
            ->get(['purchase_item_id', 'fuel_type_id', 'quantity_liters', 'unit_price', 'f1_percentage', 'f1_purchase_item_id', 'f1_unit_price', 'f1_quantity_liters'])
            ->map(fn (object $item): array => $shape((array) $item))
            ->all();

        $submittedItems = collect($this->normalizedSaleItems($data, (int) $row->id))
            ->map(fn (array $item): array => $shape($item))
            ->all();

        return (int) $row->customer_id !== (int) $data['customer_id']
            || (string) $row->sale_date !== (string) $data['sale_date']
            || $existingItems !== $submittedItems;
    }

    private function saleCode(): string
    {
        return $this->nextCode('sales', 'sale_code', 'SLS');
    }

    private function blankToNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
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

    /**
     * Record an actual customer payment and reconcile the authoritative balances.
     *
     * @return array{reference_id: int, response_reference: string}
     */
    private function recordPayment(
        int $saleId,
        ?int $paymentScheduleId,
        string $paymentDate,
        float $amount,
        string $method,
        ?string $referenceNumber,
        ?string $remarks,
        int $receivedBy
    ): array {
        if ($this->duplicatePaymentExists(
            $saleId,
            $paymentScheduleId,
            $paymentDate,
            $amount,
            $method,
            $referenceNumber
        )) {
            throw new \RuntimeException('This payment has already been recorded.');
        }

        return $this->idempotencyService->retryOnCollision('payment_code', function () use (
            $saleId,
            $paymentScheduleId,
            $paymentDate,
            $amount,
            $method,
            $referenceNumber,
            $remarks,
            $receivedBy
        ): array {
            $paymentCode = $this->nextCode('payments', 'payment_code', 'PAY');

            $paymentId = DB::table('payments')->insertGetId([
                'payment_code' => $paymentCode,
                'sale_id' => $saleId,
                'payment_schedule_id' => $paymentScheduleId,
                'payment_date' => $paymentDate,
                'amount' => $amount,
                'method' => $method,
                'reference_number' => $referenceNumber,
                'remarks' => $remarks,
                'received_by' => $receivedBy,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->saleConfirmationService->reconcile($saleId);
            $this->updatePaymentScheduleStatuses($saleId);

            return [
                'reference_id' => $paymentId,
                'response_reference' => $paymentCode,
            ];
        });
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

    /**
     * SQL mirror of receivableStatusForSale() so the Status filter matches the Status column.
     */
    private function whereReceivableStatus(Builder $query, string $status): Builder
    {
        if ($status === 'cancelled') {
            return $query->where('sales.status', 'cancelled');
        }

        $paid = 'ROUND(COALESCE(payments_total.total_paid, 0), 2)';
        $total = 'ROUND(items_total.sale_total, 2)';
        $overdue = "(receivables.due_date IS NOT NULL AND receivables.due_date < ? AND {$paid} < {$total})";
        $today = now()->toDateString();

        $query->where('sales.status', '!=', 'cancelled');

        return match ($status) {
            'clear' => $query->whereRaw("{$paid} >= {$total}"),
            'overdue' => $query->whereRaw($overdue, [$today]),
            'unpaid' => $query->whereRaw("{$paid} <= 0")->whereRaw("NOT {$overdue}", [$today]),
            default => $query->whereRaw("{$paid} > 0 AND {$paid} < {$total}")->whereRaw("NOT {$overdue}", [$today]),
        };
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
     * @param  Collection<int, int>  $saleIds
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
        do {
            $code = $prefix.'-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
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
        return $paymentMethod === 'advance_payment' ? 'advance' : 'installment';
    }

    /**
     * Offered values, plus a retired one only when the record being edited already uses it.
     *
     * @param  array<int, string>  $offered
     * @param  array<int, string>  $legacy
     * @return array<int, string>
     */
    private function allowedValues(array $offered, array $legacy, ?string $current): array
    {
        return in_array($current, $legacy, true) ? [...$offered, $current] : $offered;
    }

    private function statusAllowedByPayments(string $requested, float $saleTotal, float $paidTotal): string
    {
        if (in_array($requested, ['draft', 'cancelled'], true)) {
            return $requested;
        }

        if ($saleTotal > 0 && $paidTotal >= $saleTotal) {
            return 'paid';
        }

        if ($paidTotal > 0) {
            return 'partially_paid';
        }

        return $requested === 'unpaid' ? 'unpaid' : 'confirmed';
    }

    private function receivableStatus(float $saleTotal, float $paidTotal): string
    {
        if ($saleTotal > 0 && $paidTotal >= $saleTotal) {
            return 'clear';
        }

        return $paidTotal > 0 ? 'partial' : 'pending';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function itemsForSale(int $saleId): array
    {
        return $this->itemsForSales(collect([$saleId]))[$saleId] ?? [];
    }

    /**
     * @param  Collection<int, int>  $saleIds
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
            ->leftJoin('purchase_items as main_items', 'main_items.id', '=', 'sale_items.purchase_item_id')
            ->leftJoin('purchases as main_purchases', 'main_purchases.id', '=', 'main_items.purchase_id')
            ->leftJoin('purchase_items as f1_items', 'f1_items.id', '=', 'sale_items.f1_purchase_item_id')
            ->leftJoin('purchases as f1_purchases', 'f1_purchases.id', '=', 'f1_items.purchase_id')
            ->whereIn('sale_items.sale_id', $ids->all())
            ->orderBy('sale_items.sale_id')
            ->orderBy('sale_items.id')
            ->get([
                'sale_items.sale_id',
                'sale_items.fuel_type_id',
                'sale_items.purchase_item_id',
                'sale_items.f1_purchase_item_id',
                'sale_items.quantity_liters',
                'sale_items.unit_price',
                'sale_items.f1_unit_price',
                'sale_items.line_total',
                'sale_items.fulfilled_quantity_liters',
                'sale_items.f1_percentage',
                'sale_items.f1_quantity_liters',
                'fuel_types.name as fuel_name',
                'fuel_types.code as fuel_code',
                'main_purchases.purchase_code as purchase_code',
                'f1_purchases.purchase_code as f1_purchase_code',
            ])
            ->groupBy(fn (object $item): int => (int) $item->sale_id);

        return $ids
            ->mapWithKeys(fn (int $saleId): array => [
                $saleId => ($items[$saleId] ?? collect())
                    ->map(function (object $item): array {
                        $main = round((float) $item->quantity_liters - (float) $item->f1_quantity_liters, 2);

                        return [
                            'fuel_type_id' => (int) $item->fuel_type_id,
                            'fuel_name' => $item->fuel_name,
                            'fuel_code' => $item->fuel_code,
                            'purchase_item_id' => $item->purchase_item_id ? (int) $item->purchase_item_id : null,
                            'purchase_code' => $item->purchase_code,
                            'f1_purchase_item_id' => $item->f1_purchase_item_id ? (int) $item->f1_purchase_item_id : null,
                            'f1_purchase_code' => $item->f1_purchase_code,
                            'quantity_liters' => $item->quantity_liters,
                            'unit_price' => $item->unit_price,
                            'f1_unit_price' => (float) $item->f1_unit_price,
                            'line_total' => $item->line_total,
                            'fulfilled_quantity_liters' => $item->fulfilled_quantity_liters,
                            // Where the liters are deducted from (Part 2 revisions).
                            'source' => trim(($item->purchase_code ?: '—').((float) $item->f1_quantity_liters > 0 ? ' + F1 '.($item->f1_purchase_code ?: '—') : '')),
                            'f1_percentage' => (float) $item->f1_percentage,
                            'f1_quantity_liters' => (float) $item->f1_quantity_liters,
                            'main_quantity_liters' => $main,
                            'main_total' => round($main * (float) $item->unit_price, 2),
                            'f1_total' => round((float) $item->f1_quantity_liters * (float) $item->f1_unit_price, 2),
                        ];
                    })
                    ->all(),
            ])
            ->all();
    }

    /**
     * Purchases already chosen on recorded sales stay selectable in their Edit forms even when sold out.
     *
     * @return array<int, int>
     */
    private function purchaseIdsOnSales(): array
    {
        return DB::table('sale_items')->whereNotNull('purchase_item_id')->distinct()->pluck('purchase_item_id')
            ->merge(DB::table('sale_items')->whereNotNull('f1_purchase_item_id')->distinct()->pluck('f1_purchase_item_id'))
            ->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
    }

    private function fuelTypeOptions()
    {
        // Part 2 revisions: a fuel's stock is the remaining liters of its purchases.
        $onHand = $this->purchaseStock->onHandByFuel();

        return DB::table('fuel_types')
            ->where('status', 'active')
            ->whereIn('code', array_keys(config('fuels.approved', ['F1' => true, 'UNL' => true, 'DSL' => true, 'PREM' => true])))
            ->orderBy('name')
            ->get(['id', 'name', 'code'])
            ->map(function (object $fuel) use ($onHand): object {
                $fuel->available_liters = round((float) ($onHand[$fuel->id] ?? 0), 2);

                return $fuel;
            });
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function paymentsForSale(int $saleId): array
    {
        return $this->paymentsForSales(collect([$saleId]))[$saleId] ?? [];
    }

    /**
     * @param  Collection<int, int>  $saleIds
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
     * @param  Collection<int, int>  $saleIds
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

    private function transactionSummary(int $customerId): string
    {
        return $this->transactionSummaries(collect([$customerId]))[$customerId] ?? 'No transaction records found';
    }

    /**
     * @param  Collection<int, int>  $customerIds
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

        return $ids
            ->mapWithKeys(function (int $customerId) use ($salesCounts): array {
                $sales = (int) ($salesCounts[$customerId] ?? 0);

                return [
                    $customerId => $sales === 0
                        ? 'No transaction records found'
                        : $sales.' sales',
                ];
            })
            ->all();
    }

    private function salesRedirectRoute(Request $request): string
    {
        return str_starts_with((string) $request->route()?->getName(), 'admin.')
            ? 'admin.sales'
            : 'sales-officer.sales';
    }

    private function customersRedirectRoute(Request $request): string
    {
        return str_starts_with((string) $request->route()?->getName(), 'admin.')
            ? 'admin.sales.customers'
            : 'sales-officer.sales.customers';
    }

    private function customerOutstandingTotal(int $customerId): float
    {
        return $this->customerOutstandingTotals(collect([$customerId]))[$customerId] ?? 0.0;
    }

    /**
     * @param  Collection<int, int>  $customerIds
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
        do {
            $code = 'CSM-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        } while (DB::table('customers')->where('customer_code', $code)->exists());

        return $code;
    }

    private function rowClass(?string $status): string
    {
        return match (strtolower(str_replace(' ', '_', (string) $status))) {
            'unpaid' => 'row-danger',
            'pending', 'partial', 'partially_paid', 'refund_due' => 'row-warning',
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
     * @param  array<int, array<string, mixed>>  $items
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
}
