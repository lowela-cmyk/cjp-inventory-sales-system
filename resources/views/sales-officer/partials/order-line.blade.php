{{--
    Part 2 Button Revisions (mockup): one order line.
    Fuel Type (a purchase, "PUR-022 · PREMIUM") | Fuel Price
    F1 (an F1 purchase) | F1 Price | F1 %            (Unleaded / Premium only)
    Ordered Amount | F1 Percentage | Premium Amount | F1 Amount | Total Amount
    Premium Total = main liters x Fuel Price, F1 Total = F1 liters x F1 Price, Line Total = Premium Total + F1 Total.
    $item: purchase_item_id, unit_price, f1_purchase_item_id, f1_unit_price, f1_percentage (and fuel_type_id, fuel_name
    for a line recorded before purchases were chosen).
--}}
@php
    $formAttr = $formId ? 'form="'.$formId.'"' : '';
    $name = fn (string $field): string => "items[$index][$field]";
    $id = fn (string $field): string => $idPrefix.'_'.$field.'_'.$index;
    $err = fn (string $field): ?string => $lineError ? $lineError($field) : null;
    $selectedPurchase = (string) ($item['purchase_item_id'] ?? '');
    $selectedF1 = (string) ($item['f1_purchase_item_id'] ?? '');
@endphp
<div class="sale-pair">
    <div class="form-row">
        <label for="{{ $id('purchase_item_id') }}">Fuel Type</label>
        <select {!! $formAttr !!} id="{{ $id('purchase_item_id') }}" name="{{ $name('purchase_item_id') }}" data-sale-purchase @if (! ($item['legacy'] ?? false)) required @endif @if ($err('purchase_item_id')) aria-invalid="true" @endif>
            <option value="">{{ ($item['legacy'] ?? false) ? ($item['fuel_name'] ?? 'Fuel').' (no purchase recorded)' : ($purchaseOptions->isEmpty() ? 'No purchases with stock' : 'Select Fuel Type') }}</option>
            @foreach ($purchaseOptions as $purchase)
                <option value="{{ $purchase->id }}" data-fuel-code="{{ $purchase->fuel_code }}" data-fuel-name="{{ $purchase->fuel_name }}" data-available="{{ $purchase->available }}" @selected($selectedPurchase === (string) $purchase->id)>{{ $purchase->option_label }}</option>
            @endforeach
        </select>
        @if ($item['legacy'] ?? false)
            <input {!! $formAttr !!} type="hidden" name="{{ $name('fuel_type_id') }}" value="{{ $item['fuel_type_id'] }}" data-legacy-fuel data-fuel-code="{{ $item['fuel_code'] ?? '' }}" data-fuel-name="{{ $item['fuel_name'] ?? '' }}">
        @endif
        @if ($err('purchase_item_id'))<p class="field-error form-row-message">{{ $err('purchase_item_id') }}</p>@endif
    </div>
    <div class="form-row">
        <label for="{{ $id('unit_price') }}">Fuel Price</label>
        <input {!! $formAttr !!} id="{{ $id('unit_price') }}" name="{{ $name('unit_price') }}" type="number" min="0.01" step="0.01" placeholder="Enter Fuel Price" value="{{ $item['unit_price'] ?? '' }}" data-sale-price required>
    </div>
</div>
<div class="sale-f1-row" data-f1-row hidden>
    <div class="form-row">
        <label for="{{ $id('f1_purchase_item_id') }}">F1</label>
        <select {!! $formAttr !!} id="{{ $id('f1_purchase_item_id') }}" name="{{ $name('f1_purchase_item_id') }}" data-sale-f1-purchase @if ($err('f1_purchase_item_id')) aria-invalid="true" @endif>
            <option value="">{{ $f1PurchaseOptions->isEmpty() ? 'No F1 purchases with stock' : 'Select F1' }}</option>
            @foreach ($f1PurchaseOptions as $purchase)
                <option value="{{ $purchase->id }}" data-available="{{ $purchase->available }}" @selected($selectedF1 === (string) $purchase->id)>{{ $purchase->option_label }}</option>
            @endforeach
        </select>
        @if ($err('f1_purchase_item_id'))<p class="field-error form-row-message">{{ $err('f1_purchase_item_id') }}</p>@endif
    </div>
    <div class="form-row">
        <label for="{{ $id('f1_unit_price') }}">F1 Price</label>
        <input {!! $formAttr !!} id="{{ $id('f1_unit_price') }}" name="{{ $name('f1_unit_price') }}" type="number" min="0.01" step="0.01" placeholder="Enter F1 Price" value="{{ ($item['f1_unit_price'] ?? 0) > 0 ? $item['f1_unit_price'] : '' }}" data-sale-f1-price @if ($err('f1_unit_price')) aria-invalid="true" @endif>
        @if ($err('f1_unit_price'))<p class="field-error form-row-message">{{ $err('f1_unit_price') }}</p>@endif
    </div>
    <div class="form-row">
        <label for="{{ $id('f1_percentage') }}">F1 %</label>
        <select {!! $formAttr !!} id="{{ $id('f1_percentage') }}" name="{{ $name('f1_percentage') }}" data-f1-percentage @if ($err('f1_percentage')) aria-invalid="true" @endif>
            <option value="0" @selected((float) ($item['f1_percentage'] ?? 0) === 0.0)>Select F1 %</option>
            @foreach ($f1Percentages as $percentage)
                <option value="{{ $percentage }}" @selected((float) ($item['f1_percentage'] ?? 0) === (float) $percentage)>{{ $percentage }}%</option>
            @endforeach
        </select>
        @if ($err('f1_percentage'))<p class="field-error form-row-message">{{ $err('f1_percentage') }}</p>@endif
    </div>
</div>
<div class="f1-breakdown" data-f1-breakdown>
    <div class="f1-breakdown-cell"><span>Ordered Amount</span><output data-f1-ordered>0.00</output></div>
    <div class="f1-breakdown-cell"><span>F1 Percentage</span><output data-f1-percent>0%</output></div>
    <div class="f1-breakdown-cell"><span data-f1-main-label>Fuel Amount</span><output data-f1-main>0.00</output></div>
    <div class="f1-breakdown-cell"><span>F1 Amount</span><output data-f1-amount>0.00</output></div>
    <div class="f1-breakdown-cell"><span>Total Amount</span><output data-f1-total>0.00</output></div>
</div>
<div class="sale-line-totals">
    <div class="sale-line-total-row"><span data-main-total-label>Fuel Total</span><output data-sales-main-total>0.00</output></div>
    <div class="sale-line-total-row"><span>F1 Total</span><output data-sales-f1-total>0.00</output></div>
    <div class="sale-line-total-row sale-line-total"><span>Line Total</span><output data-sales-line-total>0.00</output></div>
</div>
@if ($err('quantity_liters'))<p class="field-error form-row-message">{{ $err('quantity_liters') }}</p>@endif
