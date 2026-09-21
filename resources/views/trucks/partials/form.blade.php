@php
    $manualStatus = $truck && in_array($truck->status, ['maintenance', 'inactive'], true) ? $truck->status : 'available';
@endphp
<div class="modal-card">
    <div class="form-grid fleet-form-grid">
        <div class="form-row"><label>Truck ID / Code</label><input name="truck_code" type="text" value="{{ old('truck_code', $truck->truck_code ?? '') }}" maxlength="30" required></div>
        <div class="form-row"><label>Plate Number</label><input name="plate_number" type="text" value="{{ old('plate_number', $truck->plate_number ?? '') }}" maxlength="50" required></div>
        <div class="form-row"><label>Truck Name</label><input name="name" type="text" value="{{ old('name', $truck->name ?? '') }}" maxlength="100" required></div>
        <div class="form-row"><label>Capacity (liters)</label><input name="capacity_liters" type="number" value="{{ old('capacity_liters', $truck->capacity_liters ?? '') }}" min="0.01" max="1000000" step="0.01" required></div>
        <div class="form-row"><label>Truck Type</label><select name="truck_type" required>@foreach ($truckTypes as $type)<option value="{{ $type }}" @selected(old('truck_type', $truck->truck_type ?? 'hauling') === $type)>{{ ucfirst($type) }}</option>@endforeach</select></div>
        <div class="form-row"><label>Operational Status</label><select name="status" required>@foreach ($managedStatuses as $status)<option value="{{ $status }}" @selected(old('status', $manualStatus) === $status)>{{ ucfirst($status) }}</option>@endforeach</select><small>Assigned and In Use are set automatically from active lifts.</small></div>
        <div class="form-row form-row-full"><label>Description</label><textarea name="description" rows="3" maxlength="255">{{ old('description', $truck->description ?? '') }}</textarea></div>
    </div>
</div>
