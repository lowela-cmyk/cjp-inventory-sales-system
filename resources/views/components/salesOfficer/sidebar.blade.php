@props(['active' => 'sales'])

@php
    $links = [
        ['route' => 'sales-officer.sales', 'label' => 'Sales'],
        ['route' => 'sales-officer.alerts', 'label' => 'Alerts'],
    ];
@endphp

<aside class="admin-sidebar" data-sidebar>
    <a class="brand-row brand-home" href="{{ route('sales-officer.sales') }}" aria-label="Sales officer sales">
        <img class="brand-logo" src="{{ asset('images/cjp-logo.png') }}" alt="CJP Southern Star OPC">
        <div>
            <div class="brand-name">CJP Southern Star OPC</div>
            <div class="brand-subtitle">INVENTORY AND SALES</div>
        </div>
    </a>

    <nav class="side-nav sales-officer-nav" aria-label="Sales officer navigation">
        @foreach ($links as $link)
            <a class="side-link {{ request()->routeIs($link['route']) || request()->routeIs($link['route'] . '.*') ? 'is-active' : '' }}" href="{{ route($link['route']) }}">
                {{ $link['label'] }}
            </a>
        @endforeach
    </nav>

    <div class="sidebar-account">
        <div class="sidebar-user">JOEL BANTA</div>
        <div class="sidebar-role">Sales Officer</div>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="btn btn-secondary btn-block" type="submit">Logout</button>
        </form>
    </div>
</aside>
