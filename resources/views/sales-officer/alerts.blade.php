@php
    $alerts = [
        ['critical', 'SLS-000003 is partially paid. Collect Payment.'],
        ['critical', 'CSM-000002 is partially paid. Collect Payment.'],
        ['warning', 'SLS-000002 is partially paid. See payment history.'],
        ['warning', 'CSM-000003 has an unpaid order. Collect Payment.'],
    ];
@endphp

@component('layouts.sales-officer', ['title' => 'Alerts Tab', 'active' => 'alerts'])
    <h2 class="section-title">System Alerts</h2>
    <div class="dispatch-alert-stack sales-alert-stack">
        @foreach ($alerts as [$type, $message])
            <div class="dispatch-alert dispatch-alert-{{ $type }}">
                <div class="dispatch-alert-icon" aria-hidden="true">!</div>
                <div>{{ $message }}</div>
            </div>
        @endforeach
    </div>
@endcomponent
