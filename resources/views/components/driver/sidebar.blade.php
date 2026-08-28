@props([
    'active' => 'fuel-lifting',
    'driverName' => 'Manuel Ligaya',
])

<aside class="admin-sidebar" data-sidebar>
    <div class="brand-row">
        <img class="brand-logo" src="{{ asset('images/cjp-logo.png') }}" alt="CJP Southern Star OPC">
        <div>
            <div class="brand-name">CJP Southern Star OPC</div>
            <div class="brand-subtitle">INVENTORY AND SALES</div>
        </div>
    </div>

    <nav class="side-nav driver-nav" aria-label="Driver navigation">
        <a class="side-link {{ request()->routeIs('driver.fuel-lifting*') ? 'is-active' : '' }}" href="{{ route('driver.fuel-lifting') }}">
            Fuel Lifting
        </a>
    </nav>

    <div class="sidebar-account">
        <div class="sidebar-user">{{ strtoupper($driverName) }}</div>
        <div class="sidebar-role">Driver</div>
        <a class="btn btn-secondary btn-block" href="{{ route('login') }}">Logout</a>
    </div>
</aside>
