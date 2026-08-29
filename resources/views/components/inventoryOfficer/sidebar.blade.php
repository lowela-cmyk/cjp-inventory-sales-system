@props(['active' => 'inventory'])

@php
    $user = auth()->user();
    $links = [
        ['route' => 'inventory-officer.inventory', 'label' => 'Inventory'],
        ['route' => 'inventory-officer.ledger', 'label' => 'Ledger'],
        ['route' => 'inventory-officer.alerts', 'label' => 'Alerts'],
    ];
@endphp

<aside class="admin-sidebar" data-sidebar>
    <a class="brand-row brand-home" href="{{ route('inventory-officer.inventory') }}" aria-label="Inventory officer inventory">
        <img class="brand-logo" src="{{ asset('images/cjp-logo.png') }}" alt="CJP Southern Star OPC">
        <div>
            <div class="brand-name">CJP Southern Star OPC</div>
            <div class="brand-subtitle">INVENTORY AND SALES</div>
        </div>
    </a>

    <nav class="side-nav inventory-officer-nav" aria-label="Inventory officer navigation">
        @foreach ($links as $link)
            <a class="side-link {{ request()->routeIs($link['route']) || request()->routeIs($link['route'] . '.*') ? 'is-active' : '' }}" href="{{ route($link['route']) }}">
                {{ $link['label'] }}
            </a>
        @endforeach
    </nav>

    <div class="sidebar-account">
        <div class="sidebar-user">{{ strtoupper($user?->name ?? 'Account') }}</div>
        <div class="sidebar-role">{{ $user?->role_label ?? 'User' }}</div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="btn btn-secondary btn-block" type="submit">Logout</button>
        </form>
    </div>
</aside>
