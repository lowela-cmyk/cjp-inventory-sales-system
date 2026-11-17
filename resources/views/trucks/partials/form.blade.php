@php
    $manualStatus = $truck && $truck->status === 'maintenance' ? 'maintenance' : 'available';
    $maxCapacity = \App\Services\TruckAvailabilityService::MAX_CAPACITY_LITERS;
@endphp
<div class="modal-card">
    <div class="form-grid fleet-form-grid">
        {{-- Part 23 #8: the Truck ID is typed by hand (required, unique, TRUCK-###); the next free one is suggested. --}}
        <div class="form-row"><label for="truck_code_{{ $truck->id ?? 'new' }}">Truck ID / Code</label><input id="truck_code_{{ $truck->id ?? 'new' }}" name="truck_code" type="text" value="{{ old('truck_code', $truck->truck_code ?? ($nextTruckCode ?? '')) }}" maxlength="30" placeholder="TRUCK-001" @unless ($truck) pattern="[Tt][Rr][Uu][Cc][Kk]-[0-9]{3,}" @endunless required>@error('truck_code')<p class="field-error form-row-message">{{ $message }}</p>@enderror<small>{{ $truck ? 'Format TRUCK-001. An older code can be kept as it is.' : 'Format TRUCK-001. Suggested: '.($nextTruckCode ?? 'TRUCK-001').'.' }}</small></div>
        <div class="form-row"><label>Plate Number</label><input name="plate_number" type="text" value="{{ old('plate_number', $truck->plate_number ?? '') }}" maxlength="50" required>@error('plate_number')<p class="field-error form-row-message">{{ $message }}</p>@enderror</div>
        <div class="form-row"><label>Capacity (liters)</label><input name="capacity_liters" type="number" value="{{ old('capacity_liters', $truck->capacity_liters ?? '') }}" min="0.01" max="{{ $maxCapacity }}" step="0.01" required>@error('capacity_liters')<p class="field-error form-row-message">{{ $message }}</p>@enderror<small>Maximum {{ number_format($maxCapacity) }} L.</small></div>
        <div class="form-row"><label>Operational Status</label><select name="status" required>@foreach ($managedStatuses as $status)<option value="{{ $status }}" @selected(old('status', $manualStatus) === $status)>{{ ucfirst($status) }}</option>@endforeach</select>@error('status')<p class="field-error form-row-message">{{ $message }}</p>@enderror</div>
    </div>
</div>
