@props([
    'title' => 'Admin',
    'subtitle' => 'INVENTORY AND SALES MANAGEMENT SYSTEM',
    'name' => null,
    'role' => null,
    'bell' => true,
])

{{-- Shared page header for every role. Desktop: title + subtitle on the left, bell + name / role on the right.
     Below 1024px it is one compact row (the hamburger sits on its left); the subtitle is hidden and the name / role
     move into a dropdown opened from the avatar (see responsive.js). --}}
@php
    $user = auth()->user();
    $displayName = $user?->name ?? $name ?? 'Account';
    $displayRole = $user?->role_label ?? $role ?? 'User';
    $initials = collect(preg_split('/\s+/', trim($displayName)) ?: [])
        ->filter()
        ->take(2)
        ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
        ->implode('') ?: '?';
@endphp

<header class="admin-header">
    <div class="header-titles">
        <h1>{{ $title }}</h1>
        <p class="header-subtitle">{{ $subtitle }}</p>
    </div>
    <div class="header-profile">
        @if ($bell)
            <x-header.bell />
        @endif
        <div class="header-account" data-account-menu>
            <button class="header-avatar" type="button" aria-haspopup="true" aria-expanded="false" aria-label="Account: {{ $displayName }}" data-account-toggle>{{ $initials }}</button>
            <div class="header-account-text" data-account-panel>
                <div class="profile-name">{{ $displayName }}</div>
                <div class="profile-role">{{ $displayRole }}</div>
                {{-- Sign Out from the avatar menu where the sidebar is replaced by the bottom nav (responsive.css). --}}
                <form class="header-signout" method="POST" action="{{ route('logout') }}" data-confirm="simple" data-confirm-title="Sign out?" data-confirm-ok="Sign Out" data-confirm-message="Are you sure you want to sign out of CJP Southern Star?">
                    @csrf
                    <button class="btn btn-secondary" type="submit"><x-nav.icon name="signout" width="18" height="18" /> Sign Out</button>
                </form>
            </div>
        </div>
    </div>
</header>
