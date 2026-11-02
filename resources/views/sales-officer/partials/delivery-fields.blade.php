{{--
    Part 18 #3: a Garage to Client order's Delivery Details (Truck, Driver, Delivery Date, and Delivery Status when the
    order is recorded or assigned). Required while the order is not Delivered; Depot to Client orders take them from
    their lift (Part 18 #5). Used by Record Sales, Edit Sales and the garage-lot Assign Source form.
    $idPrefix, $formId (null: the fields sit inside their form), $value(key): the current / old value,
    $withStatus, $deliveryStatus, $disabled (Record Sales while another type is picked), $showErrors.
--}}
@php
    $formAttr = $formId ? 'form="'.$formId.'"' : '';
    $disabled = $disabled ?? false;
    $showErrors = $showErrors ?? false;
    $error = fn (string $field): ?string => $showErrors && $errors->has($field) ? $errors->first($field) : null;
@endphp
<div class="form-row">
    <label for="{{ $idPrefix }}_delivery_truck_id">Truck ID</label>
    <select {!! $formAttr !!} id="{{ $idPrefix }}_delivery_truck_id" name="delivery_truck_id" required @disabled($disabled) @if ($error('delivery_truck_id')) aria-invalid="true" @endif>
        <option value="">Select Truck</option>
        @foreach ($deliveryTrucks as $truck)
            <option value="{{ $truck->id }}" @selected((string) $value('delivery_truck_id') === (string) $truck->id)>{{ \App\Support\TruckLabel::make($truck->name, $truck->truck_code) }}</option>
        @endforeach
    </select>
    @if ($error('delivery_truck_id'))<p class="field-error form-row-message">{{ $error('delivery_truck_id') }}</p>@endif
</div>
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
@if ($withStatus)
    <div class="form-row">
        <label for="{{ $idPrefix }}_delivery_status">Delivery Status</label>
        <select {!! $formAttr !!} id="{{ $idPrefix }}_delivery_status" name="delivery_status" data-default="pending" @disabled($disabled) @if ($error('delivery_status')) aria-invalid="true" @endif>
            @foreach ($newOrderDeliveryStatuses as $deliveryStatusOption)
                <option value="{{ $deliveryStatusOption }}" @selected(($deliveryStatus ?? 'pending') === $deliveryStatusOption)>{{ ucwords(str_replace('_', ' ', $deliveryStatusOption)) }}</option>
            @endforeach
        </select>
        @if ($error('delivery_status'))<p class="field-error form-row-message">{{ $error('delivery_status') }}</p>@endif
    </div>
@endif
