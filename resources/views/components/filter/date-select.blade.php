@props(['name', 'label' => 'Date', 'value' => null, 'dates' => []])

{{-- A Date filter as a dropdown of the days that have records (Important Notes: every filter is a dropdown). --}}
@php
    $options = collect($dates)->mapWithKeys(fn (string $date): array => [$date => date('n/j/Y', strtotime($date))]);
    // A date picked from an older link stays selectable even when it is no longer in the list.
    if ($value && ! $options->has($value)) {
        $options = $options->put($value, date('n/j/Y', strtotime($value)))->sortKeysDesc();
    }
@endphp
<x-filter.select :name="$name" :label="$label" :selected="$value" :options="$options" {{ $attributes }} />
