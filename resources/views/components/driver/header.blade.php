@props([
    'title' => 'Fuel Lifting Operations',
    'subtitle' => 'INVENTORY AND SALES MANAGEMENT SYSTEM',
    'driverName' => 'Account',
])

@php($user = auth()->user())

<header class="admin-header">
    <div>
        <h1>{{ $title }}</h1>
        <p>{{ $subtitle }}</p>
    </div>
    {{-- Drivers have no Alerts module, so no notification bell (Part 8 follow-up). --}}
    <div class="header-profile">
        <div>
            <div class="profile-name">{{ $user?->name ?? $driverName }}</div>
            <div class="profile-role">{{ $user?->role_label ?? 'Driver' }}</div>
        </div>
    </div>
</header>
