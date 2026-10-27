@props(['active' => 'dashboard'])

@php
    $user = auth()->user();
    $links = [
        ['route' => 'admin.dashboard', 'label' => 'Dashboard'],
        ['route' => 'admin.inventory', 'module' => 'inventory', 'label' => 'Inventory'],
        ['route' => 'admin.ledger', 'module' => 'ledger', 'label' => 'Ledger'],
        ['route' => 'admin.fuel-lifting', 'module' => 'fuel-lifting', 'label' => 'Fuel Lifting'],
        ['route' => 'admin.sales', 'module' => 'sales', 'label' => 'Sales'],
        ['route' => 'admin.alerts', 'module' => 'alerts', 'label' => 'Alerts'],
        ['route' => 'admin.account-requests', 'module' => 'account-requests', 'label' => 'Account Requests'],
        ['route' => 'admin.user-management', 'module' => 'user-management', 'label' => 'User Management'],
    ];
@endphp

<aside class="admin-sidebar" data-sidebar>
    <a class="brand-row brand-home" href="{{ route('admin.dashboard') }}" aria-label="Admin dashboard">
        <img class="brand-logo" src="{{ asset('images/cjp-logo.png') }}" alt="CJP Southern Star OPC">
        <div>
            <div class="brand-name">CJP Southern Star OPC</div>
            <div class="brand-subtitle">INVENTORY AND SALES</div>
        </div>
    </a>

    <nav class="side-nav" aria-label="Admin navigation">
        @foreach ($links as $link)
            <x-sidebar-link :route="$link['route']" :label="$link['label']" :module="$link['module'] ?? null" />
        @endforeach
    </nav>

    <div class="sidebar-account">
        <div class="sidebar-user">{{ strtoupper($user?->name ?? 'Account') }}</div>
        <div class="sidebar-role">{{ $user?->role_label ?? 'User' }}</div>
        <form method="POST" action="{{ route('logout') }}" data-confirm-message="Are you sure you want to sign out of CJP Southern Star?">
            @csrf
            <button class="btn btn-secondary btn-block" type="submit">Logout</button>
        </form>
    </div>
</aside>
