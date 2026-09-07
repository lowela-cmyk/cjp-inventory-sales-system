@component('layouts.inventory-officer', ['title' => 'Alerts Tab', 'active' => 'alerts'])
    <h2 class="section-title">System Alerts</h2>
    <form class="toolbar toolbar-narrow" method="GET" action="{{ route('inventory-officer.alerts') }}">
        <input type="search" name="search" placeholder="Search..." aria-label="Search alerts" value="{{ $search }}">
        <button class="btn btn-primary" type="submit">Search</button>
    </form>
    <div class="dispatch-alert-stack inventory-alert-stack">
        @forelse ($alerts as $alert)
            <div class="dispatch-alert dispatch-alert-{{ $alert['type'] }}">
                <div class="dispatch-alert-icon" aria-hidden="true">!</div>
                <div>
                    <strong>{{ $alert['title'] }}</strong>
                    <span>{{ $alert['message'] }}</span>
                </div>
            </div>
        @empty
            <div class="empty-state">No inventory alerts found.</div>
        @endforelse
    </div>
@endcomponent
