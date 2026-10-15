@php
    // Filter inputs for one inventory tab toolbar. Expects: $tab, $activeTab, $filters, $filterOptions,
    // and optional $fields (subset of status/date/depot/fuel). Values only persist on the tab that applied them.
    $fields = $fields ?? ['status', 'date', 'depot', 'fuel'];
    $current = $tab === $activeTab ? $filters : [];
    $statuses = $filterOptions['statuses'][$tab] ?? [];
@endphp

@if (in_array('status', $fields, true) && $statuses !== [])
    <x-filter.select name="status" label="Status" :selected="$current['status'] ?? null"
        :options="collect($statuses)->mapWithKeys(fn (string $status): array => [$status => ucwords(str_replace('_', ' ', $status))])" />
@endif
@if (in_array('date', $fields, true))
    <x-filter.date name="date" :value="$current['date'] ?? null" />
@endif
@if (in_array('depot', $fields, true))
    <x-filter.select name="depot_id" label="Depot" :selected="$current['depot_id'] ?? null" :options="$filterOptions['depots']->pluck('name', 'id')" />
@endif
@if (in_array('fuel', $fields, true))
    <x-filter.select name="fuel_type_id" label="Fuel Type" :selected="$current['fuel_type_id'] ?? null" :options="$filterOptions['fuelTypes']->pluck('name', 'id')" />
@endif
