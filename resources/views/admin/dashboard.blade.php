@component('layouts.admin', ['title' => 'Dashboard and Analytics', 'active' => 'dashboard'])
    <div class="metric-row">
        @foreach ($metricCards as $card)
            <div class="metric-card">
                <em>{{ $card[0] }}</em>
                <strong style="{{ $card[3] }}">{{ $card[1] }}</strong>
                <span>{{ $card[2] }}</span>
            </div>
        @endforeach
    </div>

    <div class="dashboard-grid">
        <section class="chart-panel">
            <div class="chart-header">
                <h2>Sales Trend</h2>
                <div class="chart-tabs" aria-label="Sales period">
                    <span class="is-selected">Per Week</span>
                    <span>Per Month</span>
                    <span>Per Year</span>
                </div>
            </div>
            <div class="sales-bars">
                @foreach ($salesTrend as $day)
                    <div class="mini-bar">
                        <strong>{{ $day['value'] }}</strong>
                        <i style="--bar-height: {{ $day['height'] }}px"></i>
                        <span>{{ $day['label'] }}</span>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="chart-panel">
            <div class="chart-header">
                <h2>Current Stock By Fuel Type</h2>
            </div>
            <div class="stock-bars">
                @foreach ($stockByFuelType as $stock)
                    <div class="stock-bar">
                        <strong>{{ $stock['value'] }}</strong>
                        <i style="--bar-height: {{ $stock['height'] }}px; --bar-color: {{ $stock['color'] }}"></i>
                        <span>{{ $stock['label'] }}</span>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="chart-panel">
            <div class="chart-header"><h2>Predicted Revenue Trend (Next 5 Years)</h2></div>
            @if ($hasRevenueProjection)
                <div class="mock-chart-line">
                    <div class="mock-line-grid"></div>
                </div>
            @else
                <div class="dashboard-empty-state">No data available</div>
            @endif
            <div class="chart-header"><h2>Revenue vs Receivables</h2></div>
            <div class="revenue-bars">
                @foreach ($revenueBars as $bar)
                    <div class="revenue-bar">
                        <strong>{{ $bar['value'] }}</strong>
                        <i style="--h: {{ $bar['height'] }}px; --c: {{ $bar['color'] }}"></i>
                        <span>{{ $bar['label'] }}</span>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="chart-panel">
            <div class="chart-header"><h2>Predicted Peak Demand</h2></div>
            <div class="demand-list">
                <h3>Peak Days of the Week</h3>
                @foreach ($demandDays as $item)
                    <div class="demand-row"><span>{{ $item['hot'] ? 'Hot ' : '' }}{{ $item['label'] }}</span><span class="demand-track"><i class="demand-fill" style="--p:{{ $item['percent'] }}%"></i></span><strong>{{ $item['percent'] }}%</strong></div>
                @endforeach
                <h3 style="margin-top:20px">Peak Months of the Year</h3>
                @foreach ($demandMonths as $item)
                    <div class="demand-row"><span>{{ $item['hot'] ? 'Hot ' : '' }}{{ $item['label'] }}</span><span class="demand-track"><i class="demand-fill" style="--p:{{ $item['percent'] }}%"></i></span><strong>{{ $item['percent'] }}%</strong></div>
                @endforeach
            </div>
        </section>
    </div>
@endcomponent
