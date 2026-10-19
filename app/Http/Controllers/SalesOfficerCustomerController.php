<?php

namespace App\Http\Controllers;

use App\Rules\ApprovedFuelType;
use App\Services\DashboardSummaryService;
use App\Services\IdempotencyService;
use App\Services\SaleConfirmationService;
use App\Services\SaleCorrectionService;
use App\Services\StockLotService;
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
        private readonly StockLotService $stockLots
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

    /** Part 9: how the order reaches the client. Garage to Client is sold from garage stock lots. */
    public const DELIVERY_TYPES = ['garage_to_client' => 'Garage to Client', 'depot_to_client' => 'Depot to Client'];

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
            // Stock Source for Record Sales (Part 8 #8): lots with stock, by fuel then oldest first.
            'stockLotOptions' => $this->stockLots->saleOptions(),
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
            $lineTotal = (float) $this->lineTotal($item['quantity_liters'], $item['unit_price']);
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
                            'created_by' => $request->user()->id,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);

                        $this->assertLotStock($items);
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

        $items = $this->normalizedSaleItems($data);
        foreach ($items as $item) {
            $lineTotal = (float) $this->lineTotal($item['quantity_liters'], $item['unit_price']);
            if ($lineTotal > 999999999999.99) {
                return back()
                    ->withInput()
                    ->withErrors(['quantity_liters' => 'Calculated line total exceeds allowed limit.']);
            }
        }

        // A sale recorded before stock lots keeps its lot-less lines editable; any extra line must name a lot.
        // Depot to Client lines have no lot: they are released through their lift.
        $legacyLines = $data['delivery_type'] === 'depot_to_client'
            ? PHP_INT_MAX
            : DB::table('sale_items')->where('sale_id', $sale)->whereNull('stock_in_movement_id')->count();
        $lotErrors = [];
        foreach ($items as $index => $item) {
            if ($item['stock_lot_id'] === null && --$legacyLines < 0) {
                $lotErrors["items.$index.stock_lot_id"] = 'Select a stock source for this line.';
            }
        }
        if ($lotErrors !== []) {
            return back()->withInput()->withErrors($lotErrors);
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
                    ]);

                if (! $hasDependencies && $this->changesProtectedSaleFields($row, $data)) {
                    DB::table('sale_items')->where('sale_id', $sale)->delete();
                    // Checked after the old lines are gone, so this sale's own reservation is not counted twice.
                    $this->assertLotStock($items);

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
                $deliveryType = self::DELIVERY_TYPES[$row->delivery_type] ?? self::DELIVERY_TYPES['garage_to_client'];
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
                    ] + ($f1Summary !== '' ? ['F1 Split' => $f1Summary] : []) + [
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
        // Part 9: the delivery type is chosen when the sale is recorded and stays fixed (lifts may link to it).
        $deliveryType = $current->delivery_type ?? $request->input('delivery_type');
        $isDepot = $deliveryType === 'depot_to_client';

        $data = $request->validate([
            'idempotency_key' => [$saleId ? 'nullable' : 'required', 'uuid'],
            'delivery_type' => [$saleId ? 'exclude' : 'nullable', Rule::in(array_keys(self::DELIVERY_TYPES))],
            'sales_order_number' => ['nullable', 'string', 'max:60', Rule::unique('sales', 'sales_order_number')->ignore($saleId)->whereNull('deleted_at')],
            'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')->where(fn (Builder $query): Builder => $query->where('status', 'active'))],
            'sale_date' => ['required', 'date'],
            'fuel_type_id' => [
                'required_without:items',
                'integer',
                ApprovedFuelType::rule(),
            ],
            'quantity_liters' => ['required_without:items', 'numeric', 'gt:0', 'max:1000000'],
            'unit_price' => ['required_without:items', 'numeric', 'gt:0', 'max:10000'],
            // A new sale is recorded line by line, each from a stock lot (Part 8 follow-up).
            'items' => [$saleId ? 'nullable' : 'required', 'array', 'min:1'],
            // Part 8 #8: a line is sold from a stock lot (its fuel and cost come from the lot). A line without a
            // lot names its fuel instead (legacy pooled release). Depot to Client lines name their fuel: they are
            // released from the depot through a lift, never from garage stock (Part 9).
            'items.*.stock_lot_id' => $isDepot
                ? ['exclude']
                : [$saleId ? 'nullable' : 'required', 'integer', Rule::exists('inventory_movements', 'id')->where('direction', 'in')],
            'items.*.fuel_type_id' => $isDepot
                ? ['required_with:items', 'integer', ApprovedFuelType::rule()]
                : [
                    'exclude_with:items.*.stock_lot_id',
                    'nullable',
                    'required_without:items.*.stock_lot_id',
                    'integer',
                    ApprovedFuelType::rule(),
                ],
            'items.*.quantity_liters' => ['required_with:items', 'numeric', 'gt:0', 'max:1000000'],
            'items.*.unit_price' => ['required_with:items', 'numeric', 'gt:0', 'max:10000'],
            // Part 9: share of the ordered liters delivered as F1 (Unleaded / Premium lines only).
            'items.*.f1_percentage' => [
                'nullable',
                function (string $attribute, mixed $value, \Closure $fail) use ($request): void {
                    if ($value === null || $value === '' || (float) $value === 0.0) {
                        return;
                    }
                    if (preg_match('/^items\.(\d+)\.f1_percentage$/', $attribute, $matches)) {
                        $index = (int) $matches[1];
                        $item = $request->input("items.$index");
                        if (is_array($item)) {
                            $fuelTypeId = null;
                            if (! empty($item['stock_lot_id'])) {
                                $fuelTypeId = DB::table('inventory_movements')->where('id', $item['stock_lot_id'])->value('fuel_type_id');
                            } elseif (! empty($item['fuel_type_id'])) {
                                $fuelTypeId = $item['fuel_type_id'];
                            }
                            if ($fuelTypeId !== null && ! in_array((int) $fuelTypeId, F1Split::eligibleFuelIds(), true)) {
                                return;
                            }
                        }
                    }
                    if (! in_array((int) $value, F1Split::PERCENTAGES, false)) {
                        $fail('Select an F1 Percentage of 10%, 15% or 17%.');
                    }
                },
            ],
            'f1_percentage' => ['nullable'],
            'amount_paid' => [$saleId ? 'prohibited' : 'nullable', 'numeric', 'gte:0', 'max:999999999999.99'],
            'payment_reference_number' => [$saleId ? 'prohibited' : 'nullable', 'string', 'max:100'],
            'payment_date' => [$saleId ? 'prohibited' : 'nullable', 'date', 'after_or_equal:sale_date'],
            'payment_remarks' => [$saleId ? 'prohibited' : 'nullable', 'string', 'max:1000'],
            'payment_method' => ['required', Rule::in($this->allowedValues(self::PAYMENT_METHODS, self::LEGACY_PAYMENT_METHODS, $current?->payment_method))],
            'payment_terms' => ['nullable', Rule::in($this->allowedValues(self::PAYMENT_TERMS, self::LEGACY_PAYMENT_TERMS, $current?->payment_terms))],
            'status' => ['nullable', Rule::in(array_diff(self::SALE_STATUSES, ['paid']))],
            'due_date' => ['nullable', 'date', 'after_or_equal:sale_date'],
            'created_by' => ['prohibited'],
            'line_total' => ['prohibited'],
            'total' => ['prohibited'],
            'total_paid' => ['prohibited'],
            'balance' => ['prohibited'],
            'delivery_status' => ['prohibited'],
            'delivered_at' => ['prohibited'],
            'delivery_status_updated_by' => ['prohibited'],
        ], [
            'items.required' => $isDepot ? 'Add at least one order line.' : 'Add at least one line with a stock source.',
            'items.*.stock_lot_id.required' => 'Select a stock source for this line.',
            'items.*.f1_percentage.in' => 'Select an F1 Percentage of 10%, 15% or 17%.',
        ]);
        $data['delivery_type'] = $isDepot ? 'depot_to_client' : 'garage_to_client';

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
     * Sale lines as saved, with the F1 split (Part 9): f1_percentage only counts on an Unleaded or Premium line;
     * on any other fuel it is treated as 0%.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array{fuel_type_id: int, stock_lot_id: ?int, quantity_liters: mixed, unit_price: mixed, f1_percentage: float, main_quantity_liters: float, f1_quantity_liters: float}>
     */
    private function normalizedSaleItems(array $data): array
    {
        if (! empty($data['items'])) {
            $lotFuel = DB::table('inventory_movements')
                ->whereIn('id', collect($data['items'])->pluck('stock_lot_id')->filter()->all())
                ->pluck('fuel_type_id', 'id');

            // Validated data can rebuild items in rule order (a line with a lot before one without), so restore
            // the submitted order: line N is still items.N for its error messages and for the saved order.
            $items = collect($data['items'])
                ->sortKeys()
                ->map(function (array $item) use ($lotFuel): array {
                    $lotId = ($item['stock_lot_id'] ?? null) ? (int) $item['stock_lot_id'] : null;

                    return [
                        // The lot decides the fuel, whatever fuel the client sent.
                        'fuel_type_id' => $lotId ? (int) $lotFuel[$lotId] : (int) $item['fuel_type_id'],
                        'stock_lot_id' => $lotId,
                        'quantity_liters' => $item['quantity_liters'],
                        'unit_price' => $item['unit_price'],
                        'f1_percentage' => (float) ($item['f1_percentage'] ?? 0),
                    ];
                })
                ->values()
                ->all();
        } else {
            $items = [[
                'fuel_type_id' => (int) $data['fuel_type_id'],
                'stock_lot_id' => null,
                'quantity_liters' => $data['quantity_liters'],
                'unit_price' => $data['unit_price'],
                'f1_percentage' => (float) ($data['f1_percentage'] ?? 0.0),
            ]];
        }

        // Depot to Client orders split the same way: the main fuel is lifted from the depot, the F1 is loaded at the garage.
        $eligible = F1Split::eligibleFuelIds();
        foreach ($items as $index => $item) {
            $percentage = in_array($item['fuel_type_id'], $eligible, true) ? $item['f1_percentage'] : 0.0;
            [$main, $f1] = F1Split::split((float) $item['quantity_liters'], $percentage);
            $items[$index] += ['main_quantity_liters' => $main, 'f1_quantity_liters' => $f1];
            $items[$index]['f1_percentage'] = $percentage;
        }

        return $items;
    }

    /**
     * @param  array{fuel_type_id: int, stock_lot_id: ?int, quantity_liters: mixed, unit_price: mixed, f1_percentage: float, main_quantity_liters: float, f1_quantity_liters: float}  $item
     * @return array<string, mixed>
     */
    private function saleItemRow(int $saleId, array $item, mixed $now): array
    {
        return [
            'sale_id' => $saleId,
            'fuel_type_id' => $item['fuel_type_id'],
            'stock_in_movement_id' => $item['stock_lot_id'],
            'quantity_liters' => $item['quantity_liters'],
            'unit_price' => $item['unit_price'],
            // The F1 split moves stock only: the line is billed as ordered liters × price.
            'line_total' => $this->lineTotal($item['quantity_liters'], $item['unit_price']),
            'f1_percentage' => $item['f1_percentage'],
            'main_quantity_liters' => $item['main_quantity_liters'],
            'f1_quantity_liters' => $item['f1_quantity_liters'],
            'fulfilled_quantity_liters' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Part 8 #8: lines sold from a lot may not exceed what that lot still has available (stock on hand
     * minus other undelivered sales). Lots are row-locked so two sales can't promise the same liters.
     *
     * @param  array<int, array{fuel_type_id: int, stock_lot_id: ?int, quantity_liters: mixed, unit_price: mixed}>  $items
     *
     * @throws ValidationException
     */
    private function assertLotStock(array $items): void
    {
        $lotIds = collect($items)->pluck('stock_lot_id')->filter()->unique()->values()->all();
        $f1Total = round((float) collect($items)->sum('f1_quantity_liters'), 2);
        if ($lotIds === [] && $f1Total <= 0) {
            return;
        }

        $lots = $lotIds === [] ? collect() : $this->stockLots->lots($lotIds, true)->keyBy('id');
        $requested = [];
        $errors = [];

        foreach ($items as $index => $item) {
            if (! $item['stock_lot_id']) {
                continue;
            }

            // The lot supplies the line's own fuel; an F1 portion comes from the F1 tanks (checked below).
            $lot = $lots->get($item['stock_lot_id']);
            $requested[$item['stock_lot_id']] = round(($requested[$item['stock_lot_id']] ?? 0) + $item['main_quantity_liters'], 2);
            if (! $lot) {
                $errors["items.$index.stock_lot_id"] = 'The selected stock source is no longer available.';
            } elseif ($requested[$item['stock_lot_id']] > $lot->available) {
                $errors["items.$index.quantity_liters"] = 'Only '.number_format($lot->available, 2).' L of '.$lot->source_code.' ('.$lot->fuel_name.') is available.';
            }
        }

        // Part 9: F1 portions are released from the F1 tanks (oldest lot first). They may only promise the F1
        // stock that other sales have not, less what this sale's own F1 lines take from F1 lots.
        $f1FuelId = F1Split::f1FuelId();
        if ($f1Total > 0 && $f1FuelId !== null) {
            $f1Available = round($this->stockLots->availableForFuel($f1FuelId, true)
                - (float) $lots->where('fuel_type_id', $f1FuelId)->keys()->sum(fn (int $lotId): float => $requested[$lotId] ?? 0), 2);
            $f1Requested = 0.0;
            foreach ($items as $index => $item) {
                if ($item['f1_quantity_liters'] <= 0) {
                    continue;
                }
                $f1Requested = round($f1Requested + $item['f1_quantity_liters'], 2);
                if ($f1Requested > $f1Available) {
                    $errors["items.$index.f1_percentage"] = 'Not enough F1 stock for the F1 portion: '.number_format($item['f1_quantity_liters'], 2).' L needed, '.number_format(max(0, $f1Available - $f1Requested + $item['f1_quantity_liters']), 2).' L of F1 available in the garage tanks.';
                }
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
        $existingItems = DB::table('sale_items')
            ->where('sale_id', $row->id)
            ->orderBy('id')
            ->get(['fuel_type_id', 'stock_in_movement_id', 'quantity_liters', 'unit_price', 'f1_percentage'])
            ->map(fn (object $item): array => [
                'fuel_type_id' => (int) $item->fuel_type_id,
                'stock_lot_id' => $item->stock_in_movement_id ? (int) $item->stock_in_movement_id : null,
                'quantity_liters' => number_format((float) $item->quantity_liters, 2, '.', ''),
                'unit_price' => number_format((float) $item->unit_price, 2, '.', ''),
                'f1_percentage' => number_format((float) $item->f1_percentage, 2, '.', ''),
            ])
            ->all();

        $submittedItems = collect($this->normalizedSaleItems($data))
            ->map(fn (array $item): array => [
                'fuel_type_id' => (int) $item['fuel_type_id'],
                'stock_lot_id' => $item['stock_lot_id'],
                'quantity_liters' => number_format((float) $item['quantity_liters'], 2, '.', ''),
                'unit_price' => number_format((float) $item['unit_price'], 2, '.', ''),
                'f1_percentage' => number_format((float) $item['f1_percentage'], 2, '.', ''),
            ])
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
                'sale_items.stock_in_movement_id',
                'sale_items.f1_percentage',
                'sale_items.f1_quantity_liters',
                'fuel_types.name as fuel_name',
                'fuel_types.code as fuel_code',
            ])
            ->groupBy(fn (object $item): int => (int) $item->sale_id);
        $lotIds = $items->flatten(1)->pluck('stock_in_movement_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();
        $lotLabels = $lotIds === [] ? collect() : $this->stockLots->lots($lotIds)->mapWithKeys(fn (object $lot): array => [(int) $lot->id => $lot->source_code.' · '.$lot->fuel_name.' · ₱'.number_format((float) $lot->unit_cost, 2).'/L']);

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
                        'stock_lot_id' => $item->stock_in_movement_id ? (int) $item->stock_in_movement_id : null,
                        'source' => $item->stock_in_movement_id ? ($lotLabels[(int) $item->stock_in_movement_id] ?? '—') : '—',
                        'fuel_code' => $item->fuel_code,
                        'f1_percentage' => (float) $item->f1_percentage,
                        'f1_quantity_liters' => (float) $item->f1_quantity_liters,
                        'main_quantity_liters' => round((float) $item->quantity_liters - (float) $item->f1_quantity_liters, 2),
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
            ->whereIn('fuel_types.code', array_keys(config('fuels.approved', ['F1' => true, 'UNL' => true, 'DSL' => true, 'PREM' => true])))
            ->orderBy('fuel_types.name')
            ->get([
                'fuel_types.id',
                'fuel_types.name',
                'fuel_types.code',
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
