{{-- Part 20 #2: Update Delivery from the Receivables row. Posts to the existing delivery-status route, which runs the
     Pending -> Out for Delivery -> Delivered flow (SaleConfirmationService); Delivered deducts stock (Stock Out).
     Expects $row, $salesRoutePrefix, $deliveredConfirm. --}}
@php
    $update = $row['delivery_update'];
    $label = fn (string $status): string => ucwords(str_replace('_', ' ', $status));
    $isThisSale = (int) old('delivery_update_sale_id') === (int) $row['id'];
@endphp
<x-admin.modal id="{{ $row['modal_id'] }}-delivery" title="Update Delivery" :show-brand="false">
    @if ($isThisSale && $errors->has('delivery_status'))
        <span hidden data-open-modal-on-load="{{ $row['modal_id'] }}-delivery"></span>
        <p class="form-alert" role="alert">{{ $errors->first('delivery_status') }}</p>
    @endif
    <form class="delivery-update-form" method="POST" action="{{ route($salesRoutePrefix.'.sales.delivery-status.update', $row['id']) }}"
        data-confirm="status" data-confirm-title="Update delivery status?" data-confirm-item="{{ $row['sale_code'] }}"
        data-confirm-from="{{ $label($update['current']) }}" data-confirm-to-field="delivery_status"
        data-confirm-when="delivery_status=delivered" data-confirm-message="{{ $deliveredConfirm }}">
        @csrf
        @method('PATCH')
        <input type="hidden" name="delivery_update_sale_id" value="{{ $row['id'] }}">
        {{-- One key per page load: a double click or a resent form saves once. --}}
        <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
        <dl class="delivery-update-summary">
            <div><dt>Order-ID</dt><dd>{{ $row['sale_code'] }}</dd></div>
            <div><dt>Delivery Type</dt><dd>{{ $row['delivery_type_label'] }}</dd></div>
            <div><dt>Current Status</dt><dd><x-admin.status-badge :status="$update['current']" /></dd></div>
            <div><dt>Last Updated</dt><dd>{{ $update['last_update'] ?? 'Not updated yet' }}</dd></div>
        </dl>
        <div class="form-row">
            <label for="delivery_update_status_{{ $row['id'] }}">New Delivery Status</label>
            <select id="delivery_update_status_{{ $row['id'] }}" name="delivery_status" required>
                @foreach ($update['options'] as $status)
                    <option value="{{ $status }}">{{ $label($status) }}</option>
                @endforeach
            </select>
        </div>
        @if ($update['note'])
            <p class="sale-locked-note">{{ $update['note'] }}</p>
        @endif
        <p class="table-help">Delivered deducts the order from stock (Stock Out) and cannot be undone. Payments and balances do not change.</p>
        <div class="modal-actions">
            <button class="btn btn-pill btn-secondary" type="button" data-modal-close>Close</button>
            <button class="btn btn-pill btn-primary" type="submit">Update Delivery</button>
        </div>
    </form>
</x-admin.modal>
