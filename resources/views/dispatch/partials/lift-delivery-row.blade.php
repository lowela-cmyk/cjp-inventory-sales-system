{{-- Part 2 revisions: one Depot to Client order delivered by the lift. Ordered Quantity fills from the order.
     Inside lift-form: $prefix keeps ids unique per form, $fieldError shows only the submitted form's errors. --}}
@php
    $deliveryError = isset($fieldError) ? $fieldError("deliveries.$deliveryIndex.sale_id") : null;
@endphp
<div class="lift-row lift-delivery-row" data-delivery-row>
    <div class="lift-field">
        <label for="{{ $prefix ?? 'dispatch' }}_delivery_sale_{{ $deliveryIndex }}">Order ID / Sales Order</label>
        <select id="{{ $prefix ?? 'dispatch' }}_delivery_sale_{{ $deliveryIndex }}" name="deliveries[{{ $deliveryIndex }}][sale_id]" data-delivery-sale required @if ($deliveryError) aria-invalid="true" @endif>
            <option value="">Select Order ID</option>
            @foreach ($directSaleOptions as $directSale)
                <option value="{{ $directSale->sale_id }}" data-purchase-item-id="{{ $directSale->purchase_item_id }}" data-fuel-type-id="{{ $directSale->fuel_type_id }}" data-liters="{{ $directSale->open_liters }}" data-ordered="{{ $directSale->ordered_label }}" @selected((string) $selectedSale === (string) $directSale->sale_id)>{{ $directSale->label }}</option>
            @endforeach
        </select>
        @if ($deliveryError)<p class="field-error">{{ $deliveryError }}</p>@endif
    </div>
    <div class="lift-field">
        <span class="lift-field-label">Ordered Quantity</span>
        <p class="lift-line-summary-text" data-delivery-ordered>Select Order ID</p>
    </div>
    <div class="lift-row-remove-cell">
        <button class="btn btn-danger lift-row-remove" type="button" data-delivery-remove>Remove Lift</button>
    </div>
</div>
