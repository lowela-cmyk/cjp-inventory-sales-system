@props(['value' => null, 'label' => 'Search', 'placeholder' => 'Search...'])

<input type="search" name="search" placeholder="{{ $placeholder }}" aria-label="{{ $label }}" value="{{ $value }}" {{ $attributes->class(['filter-search']) }}>
