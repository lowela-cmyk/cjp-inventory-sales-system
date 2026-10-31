{{--
    Schedule Lift form (Part 2 revisions mockup), shared by Schedule Lift and Edit Lift (Part 16 #4): Lift Details (truck,
    driver, date, purchases with Amount to Lift and ATL, DR number), then the Depot to Client deliveries.
    Expects: $formId, $action, $method (POST|PATCH), $prefix (unique id prefix), $formContext, $idempotencyKey (or null),
    $values (truck_id, driver_user_id, scheduled_at, dr_number), $lines, $deliveries, $trucks, $drivers, $purchaseItems,
    $directSaleOptions, $showErrors (only the form that was submitted shows its errors).
--}}
@php
    $fieldError = fn (string $key): ?string => $showErrors ? $errors->first($key) : null;
@endphp
<form id="{{ $formId }}" class="lift-form" method="POST" action="{{ $action }}" data-prevent-double-submit data-lifting-schedule-form>
    @csrf
    @if ($method !== 'POST')
        @method($method)
    @endif
    @if ($idempotencyKey)
        <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
    @endif
    <input type="hidden" name="form_context" value="{{ $formContext }}">

    @if ($fieldError('lift'))
        <p class="form-alert" role="alert">{{ $fieldError('lift') }}</p>
    @endif

    <h3 class="lift-section-title">Lift Details</h3>
    <section class="lift-form-section lift-header-card" aria-label="Lift details">
        <div class="lift-fields lift-fields-3">
            <div class="lift-field">
                <label for="{{ $prefix }}_truck_id">Truck ID</label>
                <select id="{{ $prefix }}_truck_id" name="truck_id" required @if ($fieldError('truck_id')) aria-invalid="true" @endif>
                    <option value="">Select Truck</option>
                    @foreach ($trucks as $truck)
                        <option value="{{ $truck->id }}" data-capacity="{{ $truck->capacity_liters }}" @selected((string) $values['truck_id'] === (string) $truck->id)>{{ \App\Support\TruckLabel::make($truck->name, $truck->truck_code) }} – {{ number_format((float) $truck->capacity_liters, 2) }} L – {{ ($truck->is_current ?? false) ? 'Current' : 'Available' }}</option>
                    @endforeach
                </select>
                @if ($fieldError('truck_id'))<p class="field-error">{{ $fieldError('truck_id') }}</p>@endif
            </div>
            <div class="lift-field">
                <label for="{{ $prefix }}_driver_user_id">Driver</label>
                <select id="{{ $prefix }}_driver_user_id" name="driver_user_id" required @if ($fieldError('driver_user_id')) aria-invalid="true" @endif>
                    <option value="">{{ $drivers->isEmpty() ? 'No active drivers available' : 'Select Driver' }}</option>
                    @foreach ($drivers as $driver)
                        <option value="{{ $driver->id }}" @selected((string) $values['driver_user_id'] === (string) $driver->id)>{{ $driver->name }}{{ $driver->driver_code ? ' / '.$driver->driver_code : '' }}</option>
                    @endforeach
                </select>
                @if ($fieldError('driver_user_id'))<p class="field-error">{{ $fieldError('driver_user_id') }}</p>@endif
            </div>
            <div class="lift-field">
                <label for="{{ $prefix }}_scheduled_at">Lift Date</label>
                <input id="{{ $prefix }}_scheduled_at" name="scheduled_at" type="datetime-local" value="{{ $values['scheduled_at'] }}" required @if ($fieldError('scheduled_at')) aria-invalid="true" @endif>
                @if ($fieldError('scheduled_at'))<p class="field-error">{{ $fieldError('scheduled_at') }}</p>@endif
            </div>
        </div>

        <div class="lift-lines" data-lifting-items>
            @foreach ($lines as $index => $line)
                <div class="lift-line" data-lifting-item>
                    <div class="lift-fields lift-fields-3">
                        <div class="lift-field">
                            <label for="{{ $prefix }}_purchase_{{ $index }}">Purchase ID</label>
                            <select id="{{ $prefix }}_purchase_{{ $index }}" name="items[{{ $index }}][purchase_item_id]" data-lifting-purchase required>
                                <option value="">{{ $purchaseItems->isEmpty() ? 'No purchases in the depot' : 'Select Purchase ID' }}</option>
                                @foreach ($purchaseItems as $purchaseItem)
                                    <option value="{{ $purchaseItem->id }}" data-depot-id="{{ $purchaseItem->depot_id }}" data-pickup="{{ $purchaseItem->pickup_label }}" data-fuel="{{ $purchaseItem->fuel_name }}" data-fuel-type-id="{{ $purchaseItem->fuel_type_id }}" @if ((int) $purchaseItem->fuel_type_id === \App\Support\F1Split::f1FuelId()) data-f1 @endif data-remaining="{{ $purchaseItem->remaining_liters }}" data-remaining-label="{{ $purchaseItem->remaining_label }}" @selected((string) ($line['purchase_item_id'] ?? '') === (string) $purchaseItem->id)>{{ $purchaseItem->label }}</option>
                                @endforeach
                            </select>
                            @if ($fieldError("items.$index.purchase_item_id"))<p class="field-error">{{ $fieldError("items.$index.purchase_item_id") }}</p>@endif
                        </div>
                        <div class="lift-field">
                            <span class="lift-field-label">Fuel / Remaining in Depot</span>
                            <p class="lift-line-summary-text" data-purchase-summary>Select Purchase ID</p>
                        </div>
                        <div class="lift-field">
                            <label for="{{ $prefix }}_quantity_{{ $index }}">Amount to Lift</label>
                            <div class="input-suffix">
                                <input id="{{ $prefix }}_quantity_{{ $index }}" name="items[{{ $index }}][quantity_liters]" data-lifting-quantity type="number" min="0.01" step="0.01" inputmode="decimal" placeholder="Enter Amount" value="{{ $line['quantity_liters'] ?? '' }}" required>
                                <span aria-hidden="true">L</span>
                            </div>
                            @if ($fieldError("items.$index.quantity_liters"))<p class="field-error">{{ $fieldError("items.$index.quantity_liters") }}</p>@endif
                        </div>
                        <div class="lift-field lift-span-2">
                            <label for="{{ $prefix }}_atl_{{ $index }}">Authority to Load</label>
                            <input id="{{ $prefix }}_atl_{{ $index }}" name="items[{{ $index }}][atl_number]" type="text" maxlength="100" placeholder="Enter ATL" value="{{ $line['atl_number'] ?? '' }}" @if ($fieldError("items.$index.atl_number")) aria-invalid="true" @endif>
                            @if ($fieldError("items.$index.atl_number"))<p class="field-error">{{ $fieldError("items.$index.atl_number") }}</p>@endif
                        </div>
                        <div class="lift-purchase-buttons">
                            <button class="btn btn-secondary" type="button" data-lifting-item-add>+ Add Purchase</button>
                            <button class="btn btn-danger" type="button" data-lifting-item-remove disabled>Remove Purchase</button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Part 15: shown when changing the first purchase's fuel cleared the other purchase rows. --}}
        <p class="lifting-empty-note" role="status" data-lifting-fuel-note hidden></p>

        {{-- Kept at the client's request (not on the mockup). --}}
        <div class="lift-fields lift-fields-3">
            <div class="lift-field">
                <label for="{{ $prefix }}_dr_number">DR Number</label>
                <input id="{{ $prefix }}_dr_number" name="dr_number" type="text" value="{{ $values['dr_number'] }}" maxlength="100" placeholder="Enter DR Number" @if ($fieldError('dr_number')) aria-invalid="true" @endif>
                @if ($fieldError('dr_number'))<p class="field-error">{{ $fieldError('dr_number') }}</p>@endif
            </div>
        </div>
        @if ($purchaseItems->isEmpty())
            <p class="lifting-empty-note" role="note">No purchases have fuel remaining in the depot. Record a new purchase in Inventory, or cancel an unneeded lift to free its quantity.</p>
        @endif
        @if ($fieldError('items'))<p class="field-error">{{ $fieldError('items') }}</p>@endif
    </section>

    {{-- Depot to Client orders this lift delivers. Fuel Remaining in Truck = Amount to Lift - deliveries. --}}
    <div class="lift-deliveries" data-lift-deliveries>
        <h3 class="lift-section-title" data-deliveries-title @if (empty($deliveries)) hidden @endif>Delivery Details</h3>
        <div class="lift-delivery-rows" data-delivery-rows>
            @foreach ($deliveries as $deliveryIndex => $delivery)
                @include('dispatch.partials.lift-delivery-row', ['deliveryIndex' => $deliveryIndex, 'selectedSale' => $delivery['sale_id'] ?? null])
            @endforeach
        </div>
        <template data-delivery-template>
            @include('dispatch.partials.lift-delivery-row', ['deliveryIndex' => '__INDEX__', 'selectedSale' => null])
        </template>
        <div class="lift-delivery-footer">
            <p class="lift-truck-remaining" data-truck-remaining-line aria-live="polite">Fuel Remaining in Truck: <output data-truck-remaining>0.00 L</output></p>
            <button class="btn lift-add-row" type="button" data-delivery-add @disabled($directSaleOptions->isEmpty())>{{ $directSaleOptions->isEmpty() ? 'No open Depot to Client orders' : '+ Add Depot to Client Delivery' }}</button>
        </div>
        @if ($fieldError('deliveries'))<p class="field-error" role="alert">{{ $fieldError('deliveries') }}</p>@endif
        <p class="field-error" data-deliveries-warning role="alert"></p>
    </div>
    <dl class="lift-info">
        <div class="lift-info-row"><dt>Pickup Depot</dt><dd data-pickup-depot>Select a purchase to derive the official depot and address.</dd></div>
        <div class="lift-info-row"><dt>Truck Capacity</dt><dd data-truck-capacity>0.00 L</dd></div>
    </dl>
    <p class="field-error lift-totals-warning" data-lifting-warning role="alert"></p>
</form>
