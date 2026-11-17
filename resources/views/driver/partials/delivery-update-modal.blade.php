{{-- Part 21 #1 (moved from Sales, Part 20 #2): the assigned driver's Update Delivery. Posts to the driver delivery
     route, which checks the assignment and runs the Pending -> Out for Delivery -> Delivered flow
     (SaleConfirmationService); Delivered deducts stock (Stock Out). Expects $row (DriverSaleDeliveryService::rows).
     Layout (see "Update Delivery modal" in app.css / responsive.css): a summary card, the status dropdown, compact
     notes, and Cancel / Update Delivery in the modal's fixed footer. On phones it is a bottom sheet. --}}
@php
    $update = $row['delivery_update'];
    $label = fn (string $status): string => ucwords(str_replace('_', ' ', $status));
    $isThisSale = (int) old('delivery_update_sale_id') === (int) $row['id'];
    $fieldId = 'delivery_update_status_'.$row['id'];
    $formId = 'delivery-update-form-'.$row['id'];
    $deliveredConfirm = 'This sale will be deducted from stock (Stock Out). Continue?';
@endphp
<x-admin.modal id="{{ $row['modal_id'] }}-delivery" title="Update Delivery" size="delivery" :show-brand="false">
    <form id="{{ $formId }}" class="delivery-update-form" method="POST" action="{{ route('driver.fuel-lifting.deliveries.status', $row['id']) }}"
        data-delivery-update-form
        data-confirm="status" data-confirm-title="Update delivery status?" data-confirm-item="{{ $row['sale_code'] }}"
        data-confirm-from="{{ $label($update['current']) }}" data-confirm-to-field="delivery_status"
        data-confirm-when="delivery_status=delivered" data-confirm-message="{{ $deliveredConfirm }}">
        @csrf
        @method('PATCH')
        <input type="hidden" name="delivery_update_sale_id" value="{{ $row['id'] }}">
        {{-- One key per page load: a double click or a resent form saves once. --}}
        <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">

        @if ($isThisSale && $errors->has('delivery_status'))
            <span hidden data-open-modal-on-load="{{ $row['modal_id'] }}-delivery"></span>
            <p class="form-alert delivery-update-alert" role="alert" id="{{ $fieldId }}_error">{{ $errors->first('delivery_status') }}</p>
        @endif

        <dl class="delivery-update-summary">
            <div class="delivery-update-item"><dt>Order ID</dt><dd class="delivery-update-code">{{ $row['sale_code'] }}</dd></div>
            <div class="delivery-update-item"><dt>Customer</dt><dd>{{ $row['customer'] }}</dd></div>
            <div class="delivery-update-item"><dt>Current Status</dt><dd><x-admin.status-badge :status="$label($update['current'])" /></dd></div>
            <div class="delivery-update-item"><dt>Last Updated</dt><dd @class(['delivery-update-muted' => ! $update['last_update']])>{{ $update['last_update'] ?? 'Not updated yet' }}</dd></div>
        </dl>

        <div class="delivery-update-field">
            <label for="{{ $fieldId }}">New Delivery Status</label>
            <select id="{{ $fieldId }}" name="delivery_status" required data-modal-autofocus
                aria-describedby="{{ $fieldId }}_hint" @if ($isThisSale && $errors->has('delivery_status')) aria-invalid="true" aria-errormessage="{{ $fieldId }}_error" @endif>
                <option value="" disabled selected>Choose the next status</option>
                @foreach ($update['options'] as $status)
                    <option value="{{ $status }}">{{ $label($status) }}</option>
                @endforeach
            </select>
        </div>

        <div class="delivery-update-notes" id="{{ $fieldId }}_hint">
            @if ($update['note'])
                <p class="delivery-update-note is-warning">{{ $update['note'] }}</p>
            @endif
            <p class="delivery-update-note">Mark Delivered only once the fuel is with the customer. It deducts the order from stock and cannot be undone.</p>
        </div>
    </form>

    <x-slot:footer>
        <button class="btn btn-pill btn-secondary" type="button" data-modal-close>Cancel</button>
        <button class="btn btn-pill btn-primary" type="submit" form="{{ $formId }}" data-delivery-update-submit data-loading-label="Updating…">Update Delivery</button>
    </x-slot:footer>
</x-admin.modal>
