@component('layouts.dispatch', ['title' => 'Alerts Tab', 'active' => 'alerts'])
    <h2 class="section-title">System Alerts</h2>
    <form class="toolbar toolbar-narrow" method="GET" action="{{ route('dispatch.alerts') }}">
        <input type="search" name="search" placeholder="Search..." aria-label="Search alerts" value="{{ $search }}">
        <button class="btn btn-primary" type="submit">Search</button>
    </form>

    <x-admin.alert-list :alerts="$alerts" read-route="dispatch.alerts.read" />
@endcomponent
