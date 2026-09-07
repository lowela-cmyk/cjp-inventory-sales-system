@component('layouts.dispatch', ['title' => 'Alerts Tab', 'active' => 'alerts'])
    <h2 class="section-title">System Alerts</h2>
    <form class="toolbar toolbar-narrow" method="GET" action="{{ route('dispatch.alerts') }}">
        <input type="search" name="search" placeholder="Search..." aria-label="Search alerts" value="{{ $search }}">
        <button class="btn btn-primary" type="submit">Search</button>
    </form>

    <div class="dispatch-alert-stack">
        @forelse ($alerts as $alert)
            <div class="dispatch-alert dispatch-alert-{{ $alert['type'] }}">
                <div class="dispatch-alert-icon" aria-hidden="true">!</div>
                <div>
                    <strong>{{ $alert['title'] }}</strong>
                    <span>{{ $alert['message'] }}</span>
                </div>
            </div>
        @empty
            <div class="empty-state">No dispatch alerts found.</div>
        @endforelse
    </div>
@endcomponent
