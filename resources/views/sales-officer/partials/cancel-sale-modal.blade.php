{{-- Void a sale with a required reason (System Analysis 4.13). Expects: $row, $salesRoutePrefix. --}}
@php
    $isCancelForm = (int) old('cancel_sale_id') === (int) $row['id'];
    $cancelOld = fn (string $key): mixed => $isCancelForm ? old($key) : null;
@endphp
<x-admin.modal id="so-sale-cancel-{{ $row['id'] }}" title="Cancel Sale">
    @if ($isCancelForm && $errors->hasAny(['cancel_reason', 'cancel_note']))
        <span hidden data-open-modal-on-load="so-sale-cancel-{{ $row['id'] }}"></span>
    @endif
    <form id="sale-cancel-form-{{ $row['id'] }}" method="POST" action="{{ route($salesRoutePrefix.'.sales.cancel', $row['id']) }}" data-prevent-double-submit>
        @csrf
        @method('PATCH')
        <input type="hidden" name="cancel_sale_id" value="{{ $row['id'] }}">
        <div class="modal-card">
            <p class="detail-id">{{ $row['sale_code'] }}</p>
            <p class="form-hint">
                Payments and transaction history are kept. Any released stock goes back to inventory.
                @if ((float) str_replace(',', '', (string) $row['total_paid']) > 0)
                    The ₱{{ $row['total_paid'] }} already paid will show as a refund due to the customer.
                @endif
            </p>
            <div class="form-grid">
                <div class="form-row">
                    <label for="cancel_reason_{{ $row['id'] }}">Reason</label>
                    <select id="cancel_reason_{{ $row['id'] }}" name="cancel_reason" required @if ($isCancelForm && $errors->has('cancel_reason')) aria-invalid="true" @endif>
                        <option value="" disabled @selected(! $cancelOld('cancel_reason'))>Select reason</option>
                        @foreach (\App\Http\Controllers\SalesOfficerCustomerController::CANCEL_REASONS as $value => $label)
                            <option value="{{ $value }}" @selected($cancelOld('cancel_reason') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @if ($isCancelForm && $errors->has('cancel_reason'))
                        <p class="field-error form-row-message">{{ $errors->first('cancel_reason') }}</p>
                    @endif
                </div>
                <div class="form-row">
                    <label for="cancel_note_{{ $row['id'] }}">Note</label>
                    <textarea id="cancel_note_{{ $row['id'] }}" name="cancel_note" rows="3" maxlength="400" placeholder="Optional (required for Other)" @if ($isCancelForm && $errors->has('cancel_note')) aria-invalid="true" @endif>{{ $cancelOld('cancel_note') }}</textarea>
                    @if ($isCancelForm && $errors->has('cancel_note'))
                        <p class="field-error form-row-message">{{ $errors->first('cancel_note') }}</p>
                    @endif
                </div>
            </div>
        </div>
    </form>
    <x-slot:footer>
        <button class="btn btn-pill btn-danger" type="submit" form="sale-cancel-form-{{ $row['id'] }}">Cancel Sale</button>
        <button class="btn btn-pill btn-secondary" type="button" data-modal-swap="{{ $row['modal_id'] }}">Back</button>
    </x-slot:footer>
</x-admin.modal>
