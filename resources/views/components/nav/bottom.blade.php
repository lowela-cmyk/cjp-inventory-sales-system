@props(['role'])

{{-- Bottom nav for phones, tablets and touch screens up to 1366px (responsive.css); laptops keep the sidebar.
     Built from config/navigation.php, like the sidebar. Up to five slots: with more than five pages, four go on the
     bar and the rest (plus the profile and Sign Out) open in the "More" sheet. The Alerts badge mirrors the header
     bell's unread count (responsive.js), so the page runs no extra query; red dots come from the same per-request
     module check as the sidebar. --}}
@php
    $nav = config('navigation.'.$role);
    $user = auth()->user();
    $unseen = $user ? app(\App\Services\ModuleActivityService::class)->unseenModules($user) : [];
    $items = collect($nav['items'])->map(fn (array $item): array => $item + [
        'active' => request()->routeIs($item['route']) || request()->routeIs($item['route'].'.*'),
        'dot' => isset($item['module']) && in_array($item['module'], $unseen, true),
    ]);
    $needsMore = $items->count() > 5;
    $bar = $needsMore
        ? $items->filter(fn (array $item): bool => isset($item['bar']))->sortBy('bar')->take(4)->values()
        : $items->sortBy(fn (array $item): int => $item['bar'] ?? 99)->values();
    $more = $needsMore ? $items->reject(fn (array $item): bool => $bar->contains('route', $item['route']))->values() : collect();
    $moreActive = $more->contains('active', true);
    $moreAlerts = $more->contains('alerts', true);
    $moreDot = $more->contains('dot', true);
    $sheetId = 'more-sheet-'.$role;
    $initials = collect(preg_split('/\s+/', trim($user?->name ?? 'Account')) ?: [])->filter()->take(2)->map(fn (string $p): string => mb_strtoupper(mb_substr($p, 0, 1)))->implode('') ?: '?';
@endphp

<nav class="bottom-nav" aria-label="{{ $nav['label'] }} (bottom bar)" data-bottom-nav style="--bottom-nav-slots: {{ $bar->count() + ($needsMore ? 1 : 0) }}">
    <ul>
        @foreach ($bar as $item)
            <li>
                <a @class(['bottom-nav-item', 'is-active' => $item['active']]) href="{{ route($item['route']) }}"{!! $item['active'] ? ' aria-current="page"' : '' !!}>
                    <span class="bottom-nav-icon">
                        <x-nav.icon :name="$item['icon']" />
                        @if ($item['alerts'] ?? false)
                            <span class="bottom-nav-badge" data-alert-mirror hidden></span>
                        @elseif ($item['dot'])
                            <span class="bottom-nav-dot" data-bottom-dot="{{ $item['module'] }}"><span class="visually-hidden">(new updates)</span></span>
                        @endif
                    </span>
                    <span class="bottom-nav-label">{{ $item['short'] }}</span>
                </a>
            </li>
        @endforeach
        @if ($needsMore)
            <li>
                <button @class(['bottom-nav-item', 'is-active' => $moreActive]) type="button" aria-haspopup="dialog" aria-expanded="false" aria-controls="{{ $sheetId }}" data-more-toggle>
                    <span class="bottom-nav-icon">
                        <x-nav.icon name="more" />
                        @if ($moreAlerts)
                            <span class="bottom-nav-badge" data-alert-mirror hidden></span>
                        @elseif ($moreDot)
                            <span class="bottom-nav-dot"><span class="visually-hidden">(new updates)</span></span>
                        @endif
                    </span>
                    <span class="bottom-nav-label">More</span>
                </button>
            </li>
        @endif
    </ul>
</nav>

@if ($needsMore)
    <div class="more-sheet" id="{{ $sheetId }}" data-more-sheet hidden>
        <div class="more-scrim" data-more-close></div>
        <section class="more-panel" role="dialog" aria-modal="true" aria-labelledby="{{ $sheetId }}-title">
            <header class="more-head">
                <h2 id="{{ $sheetId }}-title">More</h2>
                <button class="more-close" type="button" data-more-close aria-label="Close"></button>
            </header>
            <ul class="more-links">
                @foreach ($more as $item)
                    <li>
                        <a @class(['more-link', 'is-active' => $item['active']]) href="{{ route($item['route']) }}"{!! $item['active'] ? ' aria-current="page"' : '' !!}>
                            <x-nav.icon :name="$item['icon']" />
                            <span>{{ $item['label'] }}</span>
                            @if ($item['alerts'] ?? false)
                                <span class="bottom-nav-badge is-inline" data-alert-mirror hidden></span>
                            @elseif ($item['dot'])
                                <span class="bottom-nav-dot is-inline"><span class="visually-hidden">(new updates)</span></span>
                            @endif
                        </a>
                    </li>
                @endforeach
            </ul>
            <div class="more-profile">
                <span class="more-avatar" aria-hidden="true">{{ $initials }}</span>
                <div>
                    <div class="profile-name">{{ $user?->name ?? 'Account' }}</div>
                    <div class="profile-role">{{ $user?->role_label ?? 'User' }}</div>
                </div>
            </div>
            <form class="more-signout" method="POST" action="{{ route('logout') }}" data-confirm="simple" data-confirm-title="Sign out?" data-confirm-ok="Sign Out" data-confirm-message="Are you sure you want to sign out of CJP Southern Star?">
                @csrf
                <button class="btn btn-secondary" type="submit"><x-nav.icon name="signout" /> Sign Out</button>
            </form>
        </section>
    </div>
@endif
