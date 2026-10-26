@props(['label' => 'Date', 'from' => 'date_from', 'to' => 'date_to', 'fromValue' => null, 'toValue' => null])

{{-- Part 12: a Date filter is a From / To pair of date pickers. Each applies on change (see app.js); Clear empties
     both and applies. Inside an auto-filter form it keeps working with the search and the other filters. --}}
<span {{ $attributes->class(['filter-date-range']) }} role="group" aria-label="{{ $label }} range">
    <label class="filter-date-field">
        <span>From</span>
        <input type="date" name="{{ $from }}" value="{{ $fromValue }}" aria-label="{{ $label }} from">
    </label>
    <label class="filter-date-field">
        <span>To</span>
        <input type="date" name="{{ $to }}" value="{{ $toValue }}" aria-label="{{ $label }} to">
    </label>
    <button class="filter-date-clear" type="button" data-date-range-clear @if (! $fromValue && ! $toValue) hidden @endif aria-label="Clear {{ strtolower($label) }} range">Clear</button>
</span>
