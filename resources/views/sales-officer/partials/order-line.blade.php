{{--
    Part 2 Button Revisions (mockup): one order line.
    Fuel Type (a purchase, "PUR-022 · PREMIUM") | Fuel Price
    F1 (an F1 purchase) | F1 Price | F1 %            (Unleaded / Premium only)
    Ordered Amount | Fuel Amount | F1 Amount | Total Amount
    Premium Total = main liters x Fuel Price, F1 Total = F1 liters x F1 Price, Line Total = Premium Total + F1 Total.
    $item: purchase_item_id, unit_price, f1_purchase_item_id, f1_price/f1_unit_price, f1_percentage (and fuel_type_id, fuel_name
    for a line recorded before purchases were chosen).
--}}
@php
    $showF1 = $showF1 ?? true;
    // Record Sales and existing sales keep the F1 portion functional, but the percentage is not edited here.
    $showF1Percentage = $showF1Percentage ?? true;
    $formAttr = $formId ? 'form="'.$formId.'"' : '';
    $name = fn (string $field): string => "items[$index][$field]";
    $id = fn (string $field): string => $idPrefix.'_'.$field.'_'.$index;
    $err = fn (string $field): ?string => $lineError ? $lineError($field) : null;
    // Part 13: a Garage to Client line sells from the garage; $lineDeliveryType (edit modal) keeps only the options of
    // the sale's type, Record Sales shows them by the Delivery Type picked (app.js). Part 18 add-on: fuel in a tank is
    // mixed, so the garage options are TANKS ("tank:<id>", at the tank's average cost); a lot ("garage:<lot>") is
    // listed only on the older order that already sells from it.
    $lineDeliveryType = $lineDeliveryType ?? null;
    $garageTankOptions = $garageTankOptions ?? collect();
    $f1GarageTankOptions = $f1GarageTankOptions ?? collect();
    $garageOptions = $garageOptions ?? collect();
    $f1GarageOptions = $f1GarageOptions ?? collect();
    $selectedPurchase = match (true) {
        (bool) ($item['garage_tank_id'] ?? null) => 'tank:'.$item['garage_tank_id'],
        (bool) ($item['garage_stock_id'] ?? null) => 'garage:'.$item['garage_stock_id'],
        default => (string) ($item['purchase_item_id'] ?? ''),
    };
    $selectedF1 = match (true) {
        (bool) ($item['f1_garage_tank_id'] ?? null) => 'tank:'.$item['f1_garage_tank_id'],
        (bool) ($item['f1_garage_stock_id'] ?? null) => 'garage:'.$item['f1_garage_stock_id'],
        default => (string) ($item['f1_purchase_item_id'] ?? ''),
    };
    // A purchase with no liters left in the depot is not offered (it stays on the order that already uses it).
    $purchaseOptions = $purchaseOptions->filter(fn (object $purchase): bool => $purchase->available > 0 || $selectedPurchase === (string) $purchase->id);
    $f1PurchaseOptions = $f1PurchaseOptions->filter(fn (object $purchase): bool => $purchase->available > 0 || $selectedF1 === (string) $purchase->id);
    $garageTankOptions = $garageTankOptions->filter(fn (object $tank): bool => $tank->available > 0 || $selectedPurchase === 'tank:'.$tank->id);
    $f1GarageTankOptions = $f1GarageTankOptions->filter(fn (object $tank): bool => $tank->available > 0 || $selectedF1 === 'tank:'.$tank->id);
    $garageOptions = $garageOptions->filter(fn (object $lot): bool => $selectedPurchase === 'garage:'.$lot->id);
    $f1GarageOptions = $f1GarageOptions->filter(fn (object $lot): bool => $selectedF1 === 'garage:'.$lot->id);
    // Part 25 #4: what each purchase's liters are doing, tracked separately (shown under Fuel Type once chosen).
    $breakdownLiters = fn (mixed $value): string => number_format((float) $value, fmod((float) $value, 1.0) === 0.0 ? 0 : 2).' L';
    $purchaseBreakdown = fn (object $purchase): string => $purchase->purchase_code.' · Purchased '.$breakdownLiters($purchase->ordered ?? 0)
        .' · On lifts '.$breakdownLiters($purchase->lifted ?? 0).' · Reserved '.$breakdownLiters($purchase->reserved ?? 0)
        .' · Delivered '.$breakdownLiters($purchase->released ?? 0).' · Available '.$breakdownLiters($purchase->available ?? 0);
    $showDepot = $lineDeliveryType !== 'garage_to_client';
    $showGarage = in_array($lineDeliveryType, [null, 'garage_to_client'], true);
@endphp
<div class="sale-pair">
    <div class="form-row">
        <label for="{{ $id('purchase_item_id') }}">Fuel Type</label>
        <select {!! $formAttr !!} id="{{ $id('purchase_item_id') }}" name="{{ $name('purchase_item_id') }}" data-sale-purchase @if (! ($item['legacy'] ?? false)) required @endif @if ($err('purchase_item_id') ?? $err('garage_tank_id')) aria-invalid="true" @endif>
            <option value="">{{ ($item['legacy'] ?? false) ? ($item['fuel_name'] ?? 'Fuel').' (no purchase recorded)' : ($purchaseOptions->isEmpty() ? 'No purchases with stock' : 'Select Fuel Type') }}</option>
            @if ($showDepot)
                @foreach ($purchaseOptions as $purchase)
                    <option value="{{ $purchase->id }}" data-source="depot" data-fuel-code="{{ $purchase->fuel_code }}" data-fuel-name="{{ $purchase->fuel_name }}" data-available="{{ $purchase->available }}" data-purchase-code="{{ $purchase->purchase_code }}" data-breakdown="{{ $purchaseBreakdown($purchase) }}" @selected($selectedPurchase === (string) $purchase->id)>{{ $purchase->option_label }}</option>
                @endforeach
            @endif
            @if ($showGarage)
                {{-- Tank selection shows only the tank name and available liters; cost stays internal. --}}
                @foreach ($garageTankOptions as $tank)
                    <option value="tank:{{ $tank->id }}" data-source="garage" data-fuel-code="{{ $tank->fuel_code }}" data-fuel-name="{{ $tank->fuel_name }}" data-available="{{ $tank->available }}" @selected($selectedPurchase === 'tank:'.$tank->id)>{{ $tank->name }} · {{ number_format((float) $tank->available, fmod((float) $tank->available, 1.0) === 0.0 ? 0 : 2) }} L</option>
                @endforeach
                @foreach ($garageOptions as $lot)
                    <option value="garage:{{ $lot->id }}" data-source="garage" data-fuel-code="{{ $lot->fuel_code }}" data-fuel-name="{{ $lot->fuel_name }}" data-available="{{ $lot->available }}" selected>{{ $lot->option_label }}</option>
                @endforeach
            @endif
        </select>
        @if ($item['legacy'] ?? false)
            <input {!! $formAttr !!} type="hidden" name="{{ $name('fuel_type_id') }}" value="{{ $item['fuel_type_id'] }}" data-legacy-fuel data-fuel-code="{{ $item['fuel_code'] ?? '' }}" data-fuel-name="{{ $item['fuel_name'] ?? '' }}">
        @endif
        <p class="form-hint sale-purchase-breakdown" data-purchase-breakdown role="note" hidden></p>
        @if ($err('purchase_item_id') ?? $err('garage_tank_id'))<p class="field-error form-row-message">{{ $err('purchase_item_id') ?? $err('garage_tank_id') }}</p>@endif
    </div>
    <div class="form-row">
        <label for="{{ $id('unit_price') }}">Fuel Price</label>
        <input {!! $formAttr !!} id="{{ $id('unit_price') }}" name="{{ $name('unit_price') }}" type="number" min="0.01" step="0.01" placeholder="Enter Fuel Price" value="{{ $item['unit_price'] ?? '' }}" data-sale-price required>
    </div>
</div>
{{-- Part 23 #9 (Record Sales): one short line under Fuel Type once a fuel is chosen, e.g. "Unleaded: 0 L in depot,
     10,000 L in garage, 30,808 L on trucks". Hidden until then and for Garage to Client (app.js). --}}
@if (($stockHints['fuels'] ?? []) !== [])
    <p class="sale-stock-hint is-empty" data-depot-only data-stock-hint role="note" hidden>
        <span data-stock-hint-summary hidden>{{ $stockHints['summary'] }}</span>
        @foreach ($stockHints['fuels'] as $fuelCode => $hint)
            <span data-stock-hint-fuel="{{ $fuelCode }}" hidden>{{ $hint }}</span>
        @endforeach
    </p>
@endif
@if ($showTruckSelection ?? false)
    @php
        $truckLiters = fn (float $value): string => number_format($value, fmod($value, 1.0) === 0.0 ? 0 : 2);
        $recordDeliveryType = $recordDeliveryType ?? 'standard';
        $truckError = $truckError ?? null;
        $selectedTruckId = $selectedTruckId ?? null;
        // Part 25 #3: Depot to Client has no Truck Selection (its truck comes from the lift); the section is hidden
        // and disabled so it is never sent. Garage to Client requires a truck.
        $truckDisabled = $recordDeliveryType === 'depot_to_client';
        $truckRequired = $recordDeliveryType === 'garage_to_client';
    @endphp
    {{-- Part 25 #1: each truck shows its maximum capacity only. The server still refuses an order the truck cannot
         take (fuel already on it and its open deliveries counted). --}}
    <div class="form-row form-row-full" data-sale-truck-section @if ($truckDisabled) hidden @endif>
        <label for="{{ $idPrefix }}_delivery_truck_id">Truck Selection</label>
        <select {!! $formAttr !!} id="{{ $idPrefix }}_delivery_truck_id" name="delivery_truck_id"
            data-delivery-truck aria-describedby="{{ $idPrefix }}_delivery_truck_capacity"
            @disabled($truckDisabled) @required($truckRequired)
            @if ($truckError) aria-invalid="true" @endif>
            <option value="">Select Truck</option>
            @foreach (($deliveryTrucks ?? collect()) as $truck)
                @php
                    $capacity = (float) ($truck->capacity_liters ?? 0);
                    $plate = trim((string) ($truck->plate_number ?? ''));
                    $label = \App\Support\TruckLabel::make($truck->name, $truck->truck_code);
                @endphp
                <option value="{{ $truck->id }}"
                    @selected((string) $selectedTruckId === (string) $truck->id)
                    data-capacity="{{ $capacity }}"
                    data-plate="{{ $plate }}">{{ $label }}{{ $plate !== '' ? ' ('.$plate.')' : '' }} – Max {{ $truckLiters($capacity) }} L</option>
            @endforeach
        </select>
        <p class="form-hint truck-capacity-hint" id="{{ $idPrefix }}_delivery_truck_capacity" data-truck-capacity-hint role="status" aria-live="polite" hidden></p>
        @if ($truckError)<p class="field-error form-row-message">{{ $truckError }}</p>@endif
    </div>
@endif
@if ($showF1)
<div class="sale-f1-row" data-f1-row data-f1-enabled hidden>
    <div class="form-row">
        <label for="{{ $id('f1_purchase_item_id') }}">F1</label>
        <select {!! $formAttr !!} id="{{ $id('f1_purchase_item_id') }}" name="{{ $name('f1_purchase_item_id') }}" data-sale-f1-purchase @if ($err('f1_purchase_item_id')) aria-invalid="true" @endif>
            <option value="">{{ $f1PurchaseOptions->isEmpty() && $f1GarageTankOptions->isEmpty() && $f1GarageOptions->isEmpty() ? 'No F1 stock in the depot or the garage' : 'Select F1' }}</option>
            @foreach ($f1PurchaseOptions as $purchase)
                <option value="{{ $purchase->id }}" data-source="depot" data-available="{{ $purchase->available }}" data-unit-price="{{ $purchase->unit_cost }}" @selected($selectedF1 === (string) $purchase->id)>{{ $purchase->option_label }}</option>
            @endforeach
            {{-- Part 16 #1: the F1 portion may come from an F1 garage tank for every delivery type (Part 18 add-on: the
                 tank itself, at its average cost). --}}
            @foreach ($f1GarageTankOptions as $tank)
                <option value="tank:{{ $tank->id }}" data-source="garage" data-garage-f1 data-available="{{ $tank->available }}" data-unit-price="{{ $tank->avg_cost }}" @selected($selectedF1 === 'tank:'.$tank->id)>{{ $tank->name }} · {{ number_format((float) $tank->available, fmod((float) $tank->available, 1.0) === 0.0 ? 0 : 2) }} L</option>
            @endforeach
            @foreach ($f1GarageOptions as $lot)
                <option value="garage:{{ $lot->id }}" data-source="garage" data-garage-f1 data-available="{{ $lot->available }}" data-unit-price="{{ $lot->unit_cost }}" selected>{{ $lot->option_label }}</option>
            @endforeach
        </select>
        @if ($err('f1_purchase_item_id'))<p class="field-error form-row-message">{{ $err('f1_purchase_item_id') }}</p>@endif
    </div>
    <div class="form-row">
        <label for="{{ $id('f1_unit_price') }}">F1 Price</label>
        <input {!! $formAttr !!} id="{{ $id('f1_unit_price') }}" name="{{ $name('f1_unit_price') }}" type="number" min="0" step="0.01" placeholder="Enter F1 Price" value="{{ ($item['f1_price'] ?? $item['f1_unit_price'] ?? 0) > 0 ? ($item['f1_price'] ?? $item['f1_unit_price']) : '' }}" data-sale-f1-price @if ($err('f1_unit_price')) aria-invalid="true" @endif>
        @if ($err('f1_unit_price'))<p class="field-error form-row-message">{{ $err('f1_unit_price') }}</p>@endif
    </div>
    @if ($showF1Percentage)
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
    @else
        <input {!! $formAttr !!} type="hidden" name="{{ $name('f1_percentage') }}" value="{{ $item['f1_percentage'] ?? 0 }}" data-f1-percentage>
    @endif
</div>
<div class="f1-breakdown" data-f1-breakdown>
    {{-- Part 17 #8: liters (app.js fills them in); Fuel Total / F1 Total / Line Total below stay in pesos. --}}
    <div class="f1-breakdown-cell"><span>Ordered Amount</span><output data-f1-ordered>0.00 L</output></div>
    <div class="f1-breakdown-cell"><span data-f1-main-label>Fuel Amount</span><output data-f1-main>0.00 L</output></div>
    <div class="f1-breakdown-cell"><span>F1 Amount</span><output data-f1-amount>0.00 L</output></div>
    <div class="f1-breakdown-cell"><span>Total Amount</span><output data-f1-total>0.00 L</output></div>
</div>
@else
<div class="sale-amount-summary" data-sale-amount-summary>
    <div class="f1-breakdown-cell"><span>Ordered Amount</span><output data-sale-ordered>0.00 L</output></div>
    <div class="f1-breakdown-cell"><span>Fuel Amount</span><output data-sale-fuel-amount>0.00 L</output></div>
    <div class="f1-breakdown-cell"><span>Total Amount</span><output data-sale-total-amount>0.00 L</output></div>
</div>
@endif
<div class="sale-line-totals @unless ($showF1)sale-line-totals-no-f1 @endunless">
    <div class="sale-line-total-row"><span data-main-total-label>Fuel Total</span><output data-sales-main-total>0.00</output></div>
    @if ($showF1)
        <div class="sale-line-total-row"><span>F1 Total</span><output data-sales-f1-total>0.00</output></div>
    @endif
    <div class="sale-line-total-row sale-line-total"><span>Line Total</span><output data-sales-line-total>0.00</output></div>
</div>
@if ($err('quantity_liters'))<p class="field-error form-row-message">{{ $err('quantity_liters') }}</p>@endif
