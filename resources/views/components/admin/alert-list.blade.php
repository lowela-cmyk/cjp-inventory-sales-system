@props(['alerts', 'readRoute'])

<div class="alert-stack workflow-alert-stack">
    @forelse ($alerts as $alert)
        <article class="alert-bar {{ $alert['class'] }} {{ $alert['read'] ? 'is-read' : 'is-unread' }}">
            <div class="alert-icon" aria-hidden="true">!</div>
            <div class="workflow-alert-content">
                <div class="alert-title">{{ $alert['title'] }}</div>
                <div class="workflow-alert-message">{{ $alert['message'] }}</div>
                <div class="workflow-alert-meta">
                    <span>{{ $alert['meta'] }}</span>
                    <span aria-hidden="true">·</span>
                    <x-admin.status-badge :status="$alert['read_state']" />
                    <span aria-hidden="true">·</span>
                    <time>{{ $alert['time'] }}</time>
                </div>
            </div>
            <div class="workflow-alert-actions">
                @if ($alert['action_url'])
                    <a class="btn btn-secondary" href="{{ $alert['action_url'] }}">View</a>
                @endif
                @unless ($alert['read'])
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
