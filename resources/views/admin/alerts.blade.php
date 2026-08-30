@component('layouts.admin', ['title' => 'Alerts Tab', 'active' => 'alerts'])
    <h2 class="section-title">System Alerts</h2>
    <form class="toolbar toolbar-narrow" method="GET" action="{{ route('admin.alerts') }}">
        <input type="search" name="search" placeholder="Search..." aria-label="Search alerts" value="{{ $search }}">
        <button class="btn btn-primary" type="submit">Status</button>
    </form>
    <div class="alert-stack">
        @forelse ($alerts as $alert)
            <div class="alert-bar {{ $alert['class'] }}">
                <div class="alert-icon">!</div>
                <div>
                    <div class="alert-title">{{ $alert['title'] }}</div>
                    <div>{{ $alert['message'] }}</div>
                    @if ($alert['meta'])
                        <div>{{ $alert['meta'] }} / {{ $alert['status'] }}</div>
                    @endif
                </div>
                <div class="alert-time">{{ $alert['time'] }}</div>
            </div>
        @empty
            <div class="table-wrap"><table class="admin-table"><tbody><tr><td class="empty-cell">No records found.</td></tr></tbody></table></div>
        @endforelse
    </div>
@endcomponent
