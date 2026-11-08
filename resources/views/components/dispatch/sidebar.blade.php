@props(['active' => 'ledger'])

@php
    $user = auth()->user();
@endphp

<aside class="admin-sidebar" data-sidebar>
    <a class="brand-row brand-home" href="{{ route('dispatch.fuel-lifting') }}" aria-label="Dispatch fuel lifting">
        <img class="brand-logo" src="{{ asset('images/cjp-logo.png') }}" alt="CJP Southern Star OPC">
        <div>
            <div class="brand-name">CJP Southern Star OPC</div>
            <div class="brand-subtitle">INVENTORY AND SALES</div>
        </div>
    </a>

    <x-nav.sidebar-links role="dispatch" />

    <div class="sidebar-account">
        <div class="sidebar-user">{{ strtoupper($user?->name ?? 'Account') }}</div>
        <div class="sidebar-role">{{ $user?->role_label ?? 'User' }}</div>
        <form method="POST" action="{{ route('logout') }}" data-confirm="simple" data-confirm-title="Sign out?" data-confirm-ok="Sign Out" data-confirm-message="Are you sure you want to sign out of CJP Southern Star?">
            @csrf
            <button class="btn btn-secondary btn-block" type="submit">Logout</button>
        </form>
    </div>
</aside>
