@props([
    'title' => 'Inventory Management',
    'subtitle' => 'INVENTORY AND SALES MANAGEMENT SYSTEM',
])

@php($user = auth()->user())

<header class="admin-header">
    <div>
        <h1>{{ $title }}</h1>
        <p>{{ $subtitle }}</p>
    </div>
    <div class="header-profile">
        <div class="profile-mark" aria-hidden="true"></div>
        <div>
            <div class="profile-name">{{ $user?->name ?? 'Account' }}</div>
            <div class="profile-role">{{ $user?->role_label ?? 'User' }}</div>
        </div>
    </div>
</header>
