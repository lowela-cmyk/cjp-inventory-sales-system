@php
    // Filter inputs for one inventory tab toolbar. Expects: $tab, $activeTab, $filters, $filterOptions,
    // and optional $fields (subset of status/date/depot/fuel). Values only persist on the tab that applied them.
    $fields = $fields ?? ['status', 'date', 'depot', 'fuel'];
    $current = $tab === $activeTab ? $filters : [];
    $statuses = $filterOptions['statuses'][$tab] ?? [];
@endphp

@if (in_array('status', $fields, true) && $statuses !== [])
    <select name="status" aria-label="Filter by status">
        <option value="">Status (All)</option>
        @foreach ($statuses as $status)
            <option value="{{ $status }}" @selected(($current['status'] ?? '') === $status)>{{ ucwords(str_replace('_', ' ', $status)) }}</option>
        @endforeach
    </select>
@endif
@if (in_array('date', $fields, true))
    <input type="date" name="date" aria-label="Filter by date" title="Date" value="{{ $current['date'] ?? '' }}">
@endif
@if (in_array('depot', $fields, true))
    <select name="depot_id" aria-label="Filter by depot">
        <option value="">Depot (All)</option>
        @foreach ($filterOptions['depots'] as $depot)
            <option value="{{ $depot->id }}" @selected((string) ($current['depot_id'] ?? '') === (string) $depot->id)>{{ $depot->name }}</option>
        @endforeach
    </select>
@endif
@if (in_array('fuel', $fields, true))
    <select name="fuel_type_id" aria-label="Filter by fuel type">
        <option value="">Fuel Type (All)</option>
        @foreach ($filterOptions['fuelTypes'] as $fuelType)
            <option value="{{ $fuelType->id }}" @selected((string) ($current['fuel_type_id'] ?? '') === (string) $fuelType->id)>{{ $fuelType->name }}</option>
        @endforeach
    </select>
@endif
