@props(['name', 'label', 'options' => [], 'selected' => null])

{{-- One filter dropdown. $options maps value => text; the blank option clears the filter. --}}
<select name="{{ $name }}" aria-label="Filter by {{ strtolower($label) }}" {{ $attributes->class(['filter-select']) }}>
    <option value="">{{ $label }} (All)</option>
    @foreach ($options as $value => $text)
        <option value="{{ $value }}" @selected((string) $selected === (string) $value)>{{ $text }}</option>
    @endforeach
</select>
