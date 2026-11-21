{{--
    Part 18 #3: a Garage to Client order's Delivery Details (Truck, Driver, Delivery Date). Required while the order is
    not Delivered; Depot to Client orders take them from their lift (Part 18 #5). Used by Record Sales, Edit Sales and
    the garage-tank Assign Source form. Part 21 #1: no Delivery Status here; the order starts Pending and the driver
    named here updates it.
    $idPrefix, $formId (null: the fields sit inside their form), $value(key): the current / old value,
    $disabled (Record Sales while another type is picked), $showErrors.
--}}
@php
    $formAttr = $formId ? 'form="'.$formId.'"' : '';
    $disabled = $disabled ?? false;
    $includeTruck = $includeTruck ?? true;
    $showErrors = $showErrors ?? false;
    $error = fn (string $field): ?string => $showErrors && $errors->has($field) ? $errors->first($field) : null;
    $orderLiters = $orderLiters ?? null;
    $liters = fn (float $value): string => number_format($value, fmod($value, 1.0) === 0.0 ? 0 : 2);
@endphp
@if ($includeTruck)
<div class="form-row">
    <label for="{{ $idPrefix }}_delivery_truck_id">Truck ID</label>
    <select {!! $formAttr !!} id="{{ $idPrefix }}_delivery_truck_id" name="delivery_truck_id" required @disabled($disabled) @if ($error('delivery_truck_id')) aria-invalid="true" @endif
        data-delivery-truck aria-describedby="{{ $idPrefix }}_delivery_truck_capacity" @if ($orderLiters !== null) data-order-liters="{{ $orderLiters }}" @endif>
        <option value="">Select Truck</option>
        @foreach ($deliveryTrucks as $truck)
            @php
                $capacity = (float) ($truck->capacity_liters ?? 0);
                $plate = trim((string) ($truck->plate_number ?? ''));
                $label = \App\Support\TruckLabel::make($truck->name, $truck->truck_code);
            @endphp
            {{-- Part 25 #1: the maximum capacity only; the server still checks what the truck can take. --}}
            <option value="{{ $truck->id }}" @selected((string) $value('delivery_truck_id') === (string) $truck->id) data-capacity="{{ $capacity }}" data-plate="{{ $plate }}">{{ $label }}{{ $plate !== '' ? ' ('.$plate.')' : '' }} – Max {{ $liters($capacity) }} L</option>
        @endforeach
    </select>
    <p class="form-hint truck-capacity-hint" id="{{ $idPrefix }}_delivery_truck_capacity" data-truck-capacity-hint role="status" aria-live="polite" hidden></p>
    @if ($error('delivery_truck_id'))<p class="field-error form-row-message">{{ $error('delivery_truck_id') }}</p>@endif
</div>
@endif
<div class="form-row">
    <label for="{{ $idPrefix }}_delivery_driver">Driver Name</label>
    <select {!! $formAttr !!} id="{{ $idPrefix }}_delivery_driver" name="delivery_driver_user_id" required @disabled($disabled) @if ($error('delivery_driver_user_id')) aria-invalid="true" @endif>
        <option value="">Select Driver</option>
        @foreach ($deliveryDrivers as $driver)
            <option value="{{ $driver->id }}" @selected((string) $value('delivery_driver_user_id') === (string) $driver->id)>{{ $driver->name }}</option>
        @endforeach
    </select>
    @if ($error('delivery_driver_user_id'))<p class="field-error form-row-message">{{ $error('delivery_driver_user_id') }}</p>@endif
</div>
<div class="form-row">
    <label for="{{ $idPrefix }}_delivery_date">Delivery Date</label>
    <input {!! $formAttr !!} id="{{ $idPrefix }}_delivery_date" name="delivery_date" type="datetime-local" value="{{ $value('delivery_date') }}" required @disabled($disabled) @if ($error('delivery_date')) aria-invalid="true" @endif>
    @if ($error('delivery_date'))<p class="field-error form-row-message">{{ $error('delivery_date') }}</p>@endif
</div>
