@props(['alerts', 'readRoute'])

{{-- Part 12: a card shows the record and event (no alert ID or type label), its Read / Unread pill next to the title,
     the details and the time; View and Mark Read stay on the right. Read cards are plain white and muted. --}}
<div class="alert-stack workflow-alert-stack">
    @forelse ($alerts as $alert)
        <article class="alert-bar {{ $alert['class'] }} {{ $alert['read'] || ($alert['resolved'] ?? false) ? 'is-read' : 'is-unread' }}">
            <div class="alert-icon" aria-hidden="true">!</div>
            <div class="workflow-alert-content">
                <div class="workflow-alert-heading">
                    <span class="alert-title">{{ $alert['title'] }}</span>
                    <x-admin.status-badge :status="$alert['read_state']" />
                </div>
                <div class="workflow-alert-message">{{ $alert['message'] }}</div>
                <time class="workflow-alert-time">{{ $alert['time'] }}</time>
            </div>
            <div class="workflow-alert-actions">
                @if ($alert['action_url'])
                    <a class="btn btn-secondary" href="{{ $alert['action_url'] }}">View</a>
                @endif
                @unless ($alert['read'] || ($alert['resolved'] ?? false))
                    <form method="POST" action="{{ route($readRoute, $alert['id']) }}">
                        @csrf
                        @method('PATCH')
                        <button class="btn btn-primary" type="submit">Mark Read</button>
                    </form>
                @endunless
            </div>
        </article>
    @empty
        <div class="empty-state">No records found. No alerts require your attention.</div>
    @endforelse
</div>
