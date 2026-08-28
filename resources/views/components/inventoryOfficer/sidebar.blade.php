@props(['active' => 'inventory'])

@php
    $links = [
        ['route' => 'inventory-officer.inventory', 'label' => 'Inventory'],
        ['route' => 'inventory-officer.ledger', 'label' => 'Ledger'],
        ['route' => 'inventory-officer.alerts', 'label' => 'Alerts'],
    ];
@endphp

<aside class="admin-sidebar" data-sidebar>
    <div class="brand-row">
        <img class="brand-logo" src="{{ asset('images/cjp-logo.png') }}" alt="CJP Southern Star OPC">
        <div>
            <div class="brand-name">CJP Southern Star OPC</div>
            <div class="brand-subtitle">INVENTORY AND SALES</div>
        </div>
    </div>

    <nav class="side-nav inventory-officer-nav" aria-label="Inventory officer navigation">
        @foreach ($links as $link)
            <a class="side-link {{ request()->routeIs($link['route']) || request()->routeIs($link['route'] . '.*') ? 'is-active' : '' }}" href="{{ route($link['route']) }}">
                {{ $link['label'] }}
            </a>
        @endforeach
    </nav>

    <div class="sidebar-account">
        <div class="sidebar-user">Janeth Magsombol</div>
        <div class="sidebar-role">Inventory Officer</div>
        <a class="btn btn-secondary btn-block" href="{{ route('login') }}">Logout</a>
    </div>
</aside>
