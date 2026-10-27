@php
    // Shared Payment History modal for the admin and sales officer sales pages.
    // Expects: $row, $saleId, $saleCode, $paymentStatus, $viewModalId, $paymentAction, $paymentMethods, $paymentMethodLabels.
    $balanceValue = (float) str_replace(',', '', $row['balance']);
    $paidValue = (float) str_replace(',', '', $row['total_paid']);
    // Payment status of the sale itself (the receivable status, e.g. Settled / Overdue, is shown separately).
    $salePaymentStatus = match (true) {
        $paidValue <= 0 => 'Unpaid',
        $balanceValue <= 0 => 'Paid',
        default => 'Partially Paid',
    };
    $isOldPaymentForm = (int) old('payment_sale_id') === (int) $saleId;
    $showPaymentForm = $isOldPaymentForm && $errors->any();
    $reopenOnLoad = $isOldPaymentForm || (int) session('payment_sale_id') === (int) $saleId;
    $fieldPrefix = 'payment_'.$saleId;
    $selectedMethod = ($isOldPaymentForm ? old('method') : null) ?: (in_array($row['payment_method'], $paymentMethods, true) ? $row['payment_method'] : 'cash_on_delivery');
    // Field errors are shown only in the modal of the sale whose payment form was submitted.
    $paymentError = fn (string $field): ?string => $showPaymentForm ? $errors->first($field) ?: null : null;
@endphp

<x-admin.modal id="{{ $row['payment_id'] }}" title="Payment History">
    @if ($reopenOnLoad)
        <span hidden data-open-modal-on-load="{{ $row['payment_id'] }}"></span>
    @endif
    <div class="modal-card">
        <p class="detail-id">{{ $saleCode }}</p>
        <div class="detail-grid">
            <div class="detail-row"><div class="detail-label">Sale Total</div><div class="detail-value">PHP {{ $row['sale_total'] }}</div></div>
            <div class="detail-row"><div class="detail-label">Total Paid</div><div class="detail-value">PHP {{ $row['total_paid'] }}</div></div>
            <div class="detail-row"><div class="detail-label">Remaining Balance</div><div class="detail-value">PHP {{ $row['balance'] }}</div></div>
            <div class="detail-row"><div class="detail-label">Payment Status</div><div class="detail-value"><x-admin.status-badge :status="$salePaymentStatus" /></div></div>
            <div class="detail-row"><div class="detail-label">Receivable Status</div><div class="detail-value"><x-admin.status-badge :status="$paymentStatus" /></div></div>
        </div>

        {{-- Part 12: a settled sale (balance 0) offers no payment form at all; the server refuses one too. --}}
        @if ($paymentAction && $balanceValue > 0)
            <div class="payment-form-toggle-row" @if ($showPaymentForm) hidden @endif data-payment-form-toggle-row>
                <button class="btn btn-primary" type="button" data-payment-form-toggle>+ Add New Payment</button>
            </div>
            <form class="payment-entry-form" method="POST" action="{{ $paymentAction }}" @if (! $showPaymentForm) hidden @endif data-payment-form>
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ $isOldPaymentForm ? old('idempotency_key', $row['payment_token']) : $row['payment_token'] }}">
                <input type="hidden" name="payment_sale_id" value="{{ $saleId }}">
                @if ($paymentError('payment'))
                    <p class="form-alert" role="alert">{{ $paymentError('payment') }}</p>
                @endif
                <div class="form-grid">
                    <div class="form-row">
                        <label for="{{ $fieldPrefix }}_amount">Amount Paid</label>
                        <input id="{{ $fieldPrefix }}_amount" name="amount" type="number" min="0.01" step="0.01" max="{{ $balanceValue }}" inputmode="decimal" placeholder="Up to PHP {{ $row['balance'] }}" value="{{ $isOldPaymentForm ? old('amount') : '' }}" required @if ($paymentError('amount')) aria-invalid="true" @endif>
                        @if ($paymentError('amount'))<p class="field-error form-row-message">{{ $paymentError('amount') }}</p>@endif
                    </div>
                    <div class="form-row">
                        <label for="{{ $fieldPrefix }}_method">Payment Method</label>
                        <select id="{{ $fieldPrefix }}_method" name="method" required>
                            @foreach ($paymentMethods as $method)
                                <option value="{{ $method }}" @selected($selectedMethod === $method)>{{ $paymentMethodLabels[$method] ?? ucwords(str_replace('_', ' ', $method)) }}</option>
                            @endforeach
                        </select>
                        @if ($paymentError('method'))<p class="field-error form-row-message">{{ $paymentError('method') }}</p>@endif
                    </div>
                    @if (! empty($row['payment_schedules']))
                        <div class="form-row">
                            <label for="{{ $fieldPrefix }}_schedule">Installment</label>
                            <select id="{{ $fieldPrefix }}_schedule" name="payment_schedule_id">
                                <option value="">Unscheduled Payment</option>
                                @foreach ($row['payment_schedules'] as $schedule)
                                    <option value="{{ $schedule['id'] }}" @selected($isOldPaymentForm && (string) old('payment_schedule_id') === (string) $schedule['id']) @disabled(! $schedule['is_payable'])>{{ $schedule['label'] }}</option>
                                @endforeach
                            </select>
                            @if ($paymentError('payment_schedule_id'))<p class="field-error form-row-message">{{ $paymentError('payment_schedule_id') }}</p>@endif
                        </div>
                    @endif
                    <div class="form-row">
                        <label for="{{ $fieldPrefix }}_reference">Reference Number</label>
                        <input id="{{ $fieldPrefix }}_reference" name="reference_number" type="text" maxlength="100" placeholder="Required for Cheque / Banking payments" value="{{ $isOldPaymentForm ? old('reference_number') : '' }}" @if ($paymentError('reference_number')) aria-invalid="true" @endif>
                        @if ($paymentError('reference_number'))<p class="field-error form-row-message">{{ $paymentError('reference_number') }}</p>@endif
                    </div>
                    <div class="form-row">
                        <label for="{{ $fieldPrefix }}_date">Payment Date</label>
                        <input id="{{ $fieldPrefix }}_date" name="payment_date" type="date" value="{{ $isOldPaymentForm ? old('payment_date', now()->toDateString()) : now()->toDateString() }}" required @if ($paymentError('payment_date')) aria-invalid="true" @endif>
                        @if ($paymentError('payment_date'))<p class="field-error form-row-message">{{ $paymentError('payment_date') }}</p>@endif
                    </div>
                    <div class="form-row">
                        <label for="{{ $fieldPrefix }}_remarks">Remarks</label>
                        <input id="{{ $fieldPrefix }}_remarks" name="remarks" type="text" maxlength="1000" value="{{ $isOldPaymentForm ? old('remarks') : '' }}">
                        @if ($paymentError('remarks'))<p class="field-error form-row-message">{{ $paymentError('remarks') }}</p>@endif
                    </div>
                </div>
                <div class="modal-actions">
                    <button class="btn btn-pill btn-secondary" type="submit" @disabled($balanceValue <= 0)>Record Payment</button>
                    <button class="btn btn-pill btn-danger" type="button" data-payment-form-cancel>Cancel</button>
                </div>
            </form>
        @endif

        @if (! empty($row['payment_schedules']))
            <div class="table-wrap" style="margin-top:18px;min-height:auto">
                <table class="admin-table" style="min-width:640px">
                    <thead><tr><th>Installment</th><th>Due Date</th><th>Amount Due</th><th>Paid</th><th>Remaining</th><th>Status</th></tr></thead>
                    <tbody>
                        @foreach ($row['payment_schedules'] as $schedule)
                            <tr><td>{{ $schedule['sequence'] }}</td><td>{{ $schedule['due_date'] }}</td><td>{{ $schedule['amount_due'] }}</td><td>{{ $schedule['paid'] }}</td><td>{{ $schedule['remaining'] }}</td><td><x-admin.status-badge :status="$schedule['status']" /></td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <div class="table-wrap" style="margin-top:18px;min-height:auto">
            <table class="admin-table" style="min-width:560px">
                <thead><tr><th>Installment</th><th>Payment ID</th><th>Date Recorded</th><th>Amount</th><th>Method</th><th>Reference</th><th>Recorded By</th><th>Status</th></tr></thead>
                <tbody>
                    @forelse ($row['payments'] as $payment)
                        <tr><td>{{ $payment['sequence'] }}</td><td>{{ $payment['code'] }}</td><td>{{ $payment['date'] }}</td><td>{{ $payment['amount'] }}</td><td>{{ $payment['method'] }}</td><td>{{ $payment['reference'] }}</td><td>{{ $payment['recorded_by'] }}</td><td><x-admin.status-badge :status="$payment['status']" /></td></tr>
                    @empty
                        <tr><td class="empty-cell" colspan="8">No payment records found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <x-slot:footer>
        <button class="btn btn-pill btn-secondary" type="button" data-modal-swap="{{ $viewModalId }}">Back</button>
        <button class="btn btn-pill btn-danger" type="button" data-modal-close>Close</button>
    </x-slot:footer>
</x-admin.modal>
