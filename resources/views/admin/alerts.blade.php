@component('layouts.admin', ['title' => 'Alerts Tab', 'active' => 'alerts'])
    <div class="alerts-page">
        <h2 class="section-title">System Alerts</h2>
        <x-admin.alert-filters :action="route('admin.alerts')" :search="$search" :filters="$filters" :type-options="$typeOptions" />
        <x-admin.alert-list :alerts="$alerts" read-route="admin.alerts.read" />
    </div>
@endcomponent
