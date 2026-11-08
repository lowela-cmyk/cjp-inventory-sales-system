@props(['role'])

{{-- Sidebar links for one layout, from config/navigation.php (the bottom nav reads the same list). --}}
@php($nav = config('navigation.'.$role))
<nav class="{{ trim('side-nav '.($nav['nav_class'] ?? '')) }}" aria-label="{{ $nav['label'] }}">
    @foreach ($nav['items'] as $link)
        <x-sidebar-link :route="$link['route']" :label="$link['label']" :module="$link['module'] ?? null" />
    @endforeach
</nav>
