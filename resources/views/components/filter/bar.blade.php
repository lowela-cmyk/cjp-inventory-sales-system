@props(['action', 'sortOptions' => []])

{{-- Shared filter toolbar: dropdowns and dates apply on change, search applies after typing stops (see app.js). --}}
<form {{ $attributes->class(['toolbar', 'filter-bar']) }} method="GET" action="{{ $action }}" role="search" data-auto-filter>
    <input type="hidden" name="per_page" value="{{ \App\Support\TablePagination::size() }}">
    {{ $slot }}
    @if ($sortOptions)
        <label class="filter-sort">Sort by <select name="sort" aria-label="Sort by column">@foreach ($sortOptions as $key => $label)<option value="{{ $key }}" @selected(request('sort', array_key_first($sortOptions)) === $key)>{{ $label }}</option>@endforeach</select></label>
        <label class="filter-sort">Order <select name="direction" aria-label="Sort direction"><option value="desc" @selected(request('direction', 'desc') === 'desc')>Descending</option><option value="asc" @selected(request('direction') === 'asc')>Ascending</option></select></label>
    @endif
</form>
