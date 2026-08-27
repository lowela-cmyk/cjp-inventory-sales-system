@props(['active' => 'ledger'])

@php
    $links = [
        ['route' => 'dispatch.ledger', 'label' => 'Ledger'],
        ['route' => 'dispatch.fuel-lifting', 'label' => 'Fuel Lifting'],
        ['route' => 'dispatch.alerts', 'label' => 'Alerts'],
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

    <nav class="side-nav dispatch-nav" aria-label="Dispatch navigation">
        @foreach ($links as $link)
            <a class="side-link {{ request()->routeIs($link['route']) ? 'is-active' : '' }}" href="{{ route($link['route']) }}">
                {{ $link['label'] }}
            </a>
        @endforeach
    </nav>

    <div class="sidebar-account">
        <div class="sidebar-user">JASON ALMAREZ</div>
        <div class="sidebar-role">Dispatch Officer</div>
        <a class="btn btn-secondary btn-block" href="{{ route('login') }}">Logout</a>
    </div>
</aside>
