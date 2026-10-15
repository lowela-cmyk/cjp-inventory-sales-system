@props(['route', 'label', 'module' => null])

@php
    $user = auth()->user();
    $isActive = request()->routeIs($route) || request()->routeIs($route.'.*');
    // Red dot: the module has records created or updated since this user last opened it.
    $hasUpdates = $module && $user && in_array($module, app(\App\Services\ModuleActivityService::class)->unseenModules($user), true);
@endphp

<a class="side-link {{ $isActive ? 'is-active' : '' }}" href="{{ route($route) }}">
    <span>{{ $label }}</span>
    @if ($hasUpdates)
        <span class="nav-dot" data-nav-dot="{{ $module }}" title="New updates"><span class="visually-hidden">(new updates)</span></span>
    @endif
</a>
