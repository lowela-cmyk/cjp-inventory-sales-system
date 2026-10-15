@props(['name', 'label' => 'Date', 'value' => null])

<input type="date" name="{{ $name }}" aria-label="Filter by {{ strtolower($label) }}" title="{{ $label }}" value="{{ $value }}" {{ $attributes->class(['filter-date']) }}>
