@component('layouts.sales-officer', ['title' => 'Alerts Tab', 'active' => 'alerts'])
    <div class="alerts-page">
        <h2 class="section-title">System Alerts</h2>
        <x-admin.alert-filters :action="route('sales-officer.alerts')" :search="$search" :filters="$filters" :type-options="$typeOptions" />
        <x-admin.alert-list :alerts="$alerts" read-route="sales-officer.alerts.read" />
    </div>
@endcomponent
