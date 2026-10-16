{{-- Notification bell (Part 8): unread alerts this user can see; opens their Alerts page. --}}
@php
    $bell = app(\App\Services\AlertBellService::class);
    $user = auth()->user();
@endphp
@if ($bell->hasBell($user))
    @php
        $unread = $bell->unreadCount($user);
        $badge = $bell->badge($unread);
    @endphp
    <a class="header-bell" href="{{ route($bell->alertsRoute($user)) }}" data-alert-bell
        aria-label="Alerts{{ $badge ? ': '.$unread.' unread' : '' }}" title="{{ $badge ? $unread.' unread alerts' : 'Alerts' }}">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"></path>
            <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"></path>
        </svg>
        @if ($badge)
            <span class="header-bell-badge" data-alert-bell-count>{{ $badge }}</span>
        @endif
    </a>
@endif
