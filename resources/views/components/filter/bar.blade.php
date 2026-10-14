@props(['action'])

{{-- Shared filter toolbar: dropdowns and dates apply on change, search applies after typing stops (see app.js). --}}
<form {{ $attributes->class(['toolbar', 'filter-bar']) }} method="GET" action="{{ $action }}" role="search" data-auto-filter>
    {{ $slot }}
</form>
