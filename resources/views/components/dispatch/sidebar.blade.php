@props(['active' => 'ledger'])

@php
    $links = [
        ['route' => 'dispatch.ledger', 'label' => 'Ledger'],
        ['route' => 'dispatch.fuel-lifting', 'label' => 'Fuel Lifting'],
        ['route' => 'dispatch.alerts', 'label' => 'Alerts'],
    ];
@endphp

<aside class="admin-sidebar" data-sidebar>
    <a class="brand-row brand-home" href="{{ route('dispatch.fuel-lifting') }}" aria-label="Dispatch fuel lifting">
        <img class="brand-logo" src="{{ asset('images/cjp-logo.png') }}" alt="CJP Southern Star OPC">
        <div>
            <div class="brand-name">CJP Southern Star OPC</div>
            <div class="brand-subtitle">INVENTORY AND SALES</div>
        </div>
    </a>

    <nav class="side-nav dispatch-nav" aria-label="Dispatch navigation">
        @foreach ($links as $link)
            <a class="side-link {{ request()->routeIs($link['route']) || request()->routeIs($link['route'] . '.*') ? 'is-active' : '' }}" href="{{ route($link['route']) }}">
                {{ $link['label'] }}
            </a>
        @endforeach
    </nav>

    <div class="sidebar-account">
        <div class="sidebar-user">JASON ALMAREZ</div>
        <div class="sidebar-role">Dispatch Officer</div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="btn btn-secondary btn-block" type="submit">Logout</button>
        </form>
    </div>
</aside>
