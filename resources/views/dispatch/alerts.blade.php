@php
    $alerts = [
        ['critical', 'PUR-000002 is Unpaid. Settle Payment.'],
        ['critical', 'PUR-000002 is Unlifted. Schedule Lifts'],
        ['critical', 'PUR-000003 is Unlifted. Schedule Lifts'],
        ['warning', 'PUR-000001 is Partially Lifted. Schedule Lifts to collect the remaining 20,000.'],
    ];
@endphp

@component('layouts.dispatch', ['title' => 'Alerts Tab', 'active' => 'alerts'])
    <h2 class="section-title">System Alerts</h2>

    <div class="dispatch-alert-stack">
        @foreach ($alerts as [$type, $message])
            <div class="dispatch-alert dispatch-alert-{{ $type }}">
                <div class="dispatch-alert-icon" aria-hidden="true">!</div>
                <div>{{ $message }}</div>
            </div>
        @endforeach
    </div>
@endcomponent
