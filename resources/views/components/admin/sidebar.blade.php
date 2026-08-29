@props(['active' => 'dashboard'])

@php
    $links = [
        ['route' => 'admin.dashboard', 'label' => 'Dashboard'],
        ['route' => 'admin.inventory', 'label' => 'Inventory'],
        ['route' => 'admin.ledger', 'label' => 'Ledger'],
        ['route' => 'admin.fuel-lifting', 'label' => 'Fuel Lifting'],
        ['route' => 'admin.sales', 'label' => 'Sales'],
        ['route' => 'admin.reports', 'label' => 'Reports'],
        ['route' => 'admin.alerts', 'label' => 'Alerts'],
        ['route' => 'admin.user-management', 'label' => 'User Management'],
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
            <a class="side-link {{ request()->routeIs($link['route']) ? 'is-active' : '' }}" href="{{ route($link['route']) }}">
                {{ $link['label'] }}
            </a>
        @endforeach
    </nav>

    <div class="sidebar-account">
        <div class="sidebar-user">CJ PILAR</div>
        <div class="sidebar-role">Admin</div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="btn btn-secondary btn-block" type="submit">Logout</button>
        </form>
    </div>
</aside>
