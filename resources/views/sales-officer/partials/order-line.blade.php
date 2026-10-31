{{--
    Part 2 Button Revisions (mockup): one order line.
    Fuel Type (a purchase, "PUR-022 · PREMIUM") | Fuel Price
    F1 (an F1 purchase) | F1 Price | F1 %            (Unleaded / Premium only)
    Ordered Amount | F1 Percentage | Premium Amount | F1 Amount | Total Amount
    Premium Total = main liters x Fuel Price, F1 Total = F1 liters x F1 Price, Line Total = Premium Total + F1 Total.
    $item: purchase_item_id, unit_price, f1_purchase_item_id, f1_price/f1_unit_price, f1_percentage (and fuel_type_id, fuel_name
    for a line recorded before purchases were chosen).
--}}
@php
    $formAttr = $formId ? 'form="'.$formId.'"' : '';
    $name = fn (string $field): string => "items[$index][$field]";
    $id = fn (string $field): string => $idPrefix.'_'.$field.'_'.$index;
    $err = fn (string $field): ?string => $lineError ? $lineError($field) : null;
    // Part 13: a Garage to Client line sells from a garage lot ("garage:<lot>"); $lineDeliveryType (edit modal) keeps
    // only the options of the sale's type, Record Sales shows them by the Delivery Type picked (app.js).
    $lineDeliveryType = $lineDeliveryType ?? null;
    $garageOptions = $garageOptions ?? collect();
    $f1GarageOptions = $f1GarageOptions ?? collect();
    $selectedPurchase = ($item['garage_stock_id'] ?? null) ? 'garage:'.$item['garage_stock_id'] : (string) ($item['purchase_item_id'] ?? '');
    $selectedF1 = ($item['f1_garage_stock_id'] ?? null) ? 'garage:'.$item['f1_garage_stock_id'] : (string) ($item['f1_purchase_item_id'] ?? '');
    // A purchase with no liters left in the depot is not offered (it stays on the order that already uses it).
    $purchaseOptions = $purchaseOptions->filter(fn (object $purchase): bool => $purchase->available > 0 || $selectedPurchase === (string) $purchase->id);
    $f1PurchaseOptions = $f1PurchaseOptions->filter(fn (object $purchase): bool => $purchase->available > 0 || $selectedF1 === (string) $purchase->id);
    $f1GarageOptions = $f1GarageOptions->filter(fn (object $lot): bool => $lot->available > 0 || $selectedF1 === 'garage:'.$lot->id);
    $showDepot = $lineDeliveryType !== 'garage_to_client';
    $showGarage = in_array($lineDeliveryType, [null, 'garage_to_client'], true);
@endphp
<div class="sale-pair">
    <div class="form-row">
        <label for="{{ $id('purchase_item_id') }}">Fuel Type</label>
        <select {!! $formAttr !!} id="{{ $id('purchase_item_id') }}" name="{{ $name('purchase_item_id') }}" data-sale-purchase @if (! ($item['legacy'] ?? false)) required @endif @if ($err('purchase_item_id')) aria-invalid="true" @endif>
            <option value="">{{ ($item['legacy'] ?? false) ? ($item['fuel_name'] ?? 'Fuel').' (no purchase recorded)' : ($purchaseOptions->isEmpty() ? 'No purchases with stock' : 'Select Fuel Type') }}</option>
            @if ($showDepot)
                @foreach ($purchaseOptions as $purchase)
                    <option value="{{ $purchase->id }}" data-source="depot" data-fuel-code="{{ $purchase->fuel_code }}" data-fuel-name="{{ $purchase->fuel_name }}" data-available="{{ $purchase->available }}" @selected($selectedPurchase === (string) $purchase->id)>{{ $purchase->option_label }}</option>
                @endforeach
            @endif
            @if ($showGarage)
                @foreach ($garageOptions as $lot)
                    {{-- A lot whose liters are all promised to open orders is not offered (it stays on its own order). --}}
                    @continue($lot->available <= 0 && $selectedPurchase !== 'garage:'.$lot->id)
                    <option value="garage:{{ $lot->id }}" data-source="garage" data-fuel-code="{{ $lot->fuel_code }}" data-fuel-name="{{ $lot->fuel_name }}" data-available="{{ $lot->available }}" @selected($selectedPurchase === 'garage:'.$lot->id)>{{ $lot->option_label }}</option>
                @endforeach
            @endif
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
{{-- Part 16 #2 (Record Sales): one compact full-width line under Fuel Type, outside the field grid: the selected fuel's
     garage / truck stock, or a one-line summary while no fuel is selected. Hidden for Garage to Client (app.js). --}}
@if (($stockHints['summary'] ?? null) !== null)
    <p class="sale-stock-hint" data-depot-only data-stock-hint role="note">
        <span data-stock-hint-summary>{{ $stockHints['summary'] }}</span>
        @foreach ($stockHints['fuels'] as $fuelCode => $hint)
            <span data-stock-hint-fuel="{{ $fuelCode }}" hidden>{{ $hint }}</span>
        @endforeach
    </p>
@endif
<div class="sale-f1-row" data-f1-row hidden>
    <div class="form-row">
        <label for="{{ $id('f1_purchase_item_id') }}">F1</label>
        <select {!! $formAttr !!} id="{{ $id('f1_purchase_item_id') }}" name="{{ $name('f1_purchase_item_id') }}" data-sale-f1-purchase @if ($err('f1_purchase_item_id')) aria-invalid="true" @endif>
            <option value="">{{ $f1PurchaseOptions->isEmpty() && $f1GarageOptions->isEmpty() ? 'No F1 stock in the depot or the garage' : 'Select F1' }}</option>
            @foreach ($f1PurchaseOptions as $purchase)
                <option value="{{ $purchase->id }}" data-source="depot" data-available="{{ $purchase->available }}" data-unit-price="{{ $purchase->unit_cost }}" @selected($selectedF1 === (string) $purchase->id)>{{ $purchase->option_label }}</option>
            @endforeach
            {{-- Part 16 #1: the F1 portion may come from an F1 garage tank for every delivery type (labelled with its tank). --}}
            @foreach ($f1GarageOptions as $lot)
                <option value="garage:{{ $lot->id }}" data-source="garage" data-garage-f1 data-available="{{ $lot->available }}" data-unit-price="{{ $lot->unit_cost }}" @selected($selectedF1 === 'garage:'.$lot->id)>{{ $lot->option_label }}</option>
            @endforeach
        </select>
        @if ($err('f1_purchase_item_id'))<p class="field-error form-row-message">{{ $err('f1_purchase_item_id') }}</p>@endif
    </div>
    <div class="form-row">
        <label for="{{ $id('f1_unit_price') }}">F1 Price</label>
        <input {!! $formAttr !!} id="{{ $id('f1_unit_price') }}" name="{{ $name('f1_unit_price') }}" type="number" min="0" step="0.01" placeholder="Enter F1 Price" value="{{ ($item['f1_price'] ?? $item['f1_unit_price'] ?? 0) > 0 ? ($item['f1_price'] ?? $item['f1_unit_price']) : '' }}" data-sale-f1-price @if ($err('f1_unit_price')) aria-invalid="true" @endif>
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
