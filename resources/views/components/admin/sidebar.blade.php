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
    <div class="brand-row">
        <div class="brand-mark" aria-hidden="true"></div>
        <div>
            <div class="brand-name">CJP Southern Star</div>
            <div class="brand-subtitle">INVENTORY AND SALES</div>
        </div>
    </div>

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
        <a class="btn btn-secondary btn-block" href="{{ route('login') }}">Logout</a>
    </div>
</aside>
