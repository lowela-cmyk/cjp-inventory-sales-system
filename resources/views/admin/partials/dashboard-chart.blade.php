{{-- Part 10: one dashboard chart (Chart.js). An empty chart shows "No data yet" instead. --}}
<div class="dash-chart-canvas {{ $class ?? '' }}">
    @if ($chart['empty'])
        <div class="dashboard-empty-state" data-dashboard-empty>No data yet</div>
    @else
        <canvas data-dashboard-chart data-chart='@json($chart)' role="img" aria-label="{{ $label }}"></canvas>
    @endif
</div>
