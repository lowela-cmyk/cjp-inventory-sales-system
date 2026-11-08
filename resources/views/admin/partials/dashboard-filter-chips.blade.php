{{-- Active dashboard filters as small chips (replaces the old "Fuel Type: ALL" / "Depot: ALL" cards). Nothing shows
     when no filter is set. --}}
@if (! empty($chips))
    <ul class="filter-chips" aria-label="Active filters">
        @foreach ($chips as $label => $value)
            <li class="filter-chip"><span>{{ $label }}:</span> {{ $value }}</li>
        @endforeach
    </ul>
@endif
