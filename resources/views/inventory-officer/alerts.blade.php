@component('layouts.inventory-officer', ['title' => 'Alerts Tab', 'active' => 'alerts'])
    <div class="alerts-page">
        <h2 class="section-title">System Alerts</h2>
        <x-admin.alert-filters :action="route('inventory-officer.alerts')" :search="$search" :filters="$filters" :type-options="$typeOptions" />
        <x-admin.alert-list :alerts="$alerts" read-route="inventory-officer.alerts.read" />
    </div>
@endcomponent
