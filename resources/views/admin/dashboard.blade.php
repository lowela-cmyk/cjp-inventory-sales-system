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
                    @foreach (['week' => 'Per Week', 'month' => 'Per Month', 'year' => 'Per Year'] as $period => $label)
                        <a href="{{ route('admin.dashboard', array_filter(['trend_period' => $period, 'trend_year' => $salesTrendFilters['year'] ?? now()->year])) }}" class="{{ ($salesTrendFilters['period'] ?? 'week') === $period ? 'is-selected' : '' }}">{{ $label }}</a>
                    @endforeach
                </div>
            </div>
            <div class="sales-trend-chart">
                <canvas data-sales-trend-chart data-chart='@json($salesTrendChart)' aria-label="Sales revenue trend" role="img"></canvas>
                <div class="sales-bars sales-trend-fallback">
                    @foreach ($salesTrend as $day)
                        <div class="mini-bar">
                            <strong>{{ $day['value'] }}</strong>
                            <i style="--bar-height: {{ $day['height'] }}px"></i>
                            <span>{{ $day['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="chart-panel">
            <div class="chart-header">
                <h2>Current Stock By Fuel Type</h2>
            </div>
            @if (! empty($stockByFuelType))
                <div class="stock-level-chart">
                    <canvas data-stock-level-chart data-chart='@json($stockLevelChart)' aria-label="Current stock by fuel type" role="img"></canvas>
                    <div class="stock-bars stock-level-fallback">
                        @foreach ($stockByFuelType as $stock)
                            <div class="stock-bar">
                                <strong>{{ $stock['value'] }}</strong>
                                <i style="--bar-height: {{ $stock['height'] }}px; --bar-color: {{ $stock['color'] }}"></i>
                                <span>{{ $stock['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
                <div class="dashboard-empty-state">No data available</div>
            @endif
        </section>

        <section class="chart-panel">
            <div class="chart-header"><h2>Expected Revenue ({{ $expectedRevenue['period'] }})</h2></div>
            <div class="expected-revenue-chart">
                <canvas data-expected-revenue-chart data-chart='@json($expectedRevenueChart)' aria-label="Expected revenue by month" role="img"></canvas>
                <div class="sales-bars expected-revenue-fallback">
                    @foreach ($expectedRevenue['bars'] as $bar)
                        <div class="mini-bar">
                            <strong>{{ $bar['value'] }}</strong>
                            <i style="--bar-height: {{ $bar['height'] }}px"></i>
                            <span>{{ $bar['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="dashboard-kpi-strip">
                <div><span>Expected</span><strong>{{ $expectedRevenue['formattedTotalExpected'] }}</strong></div>
                <div><span>Collected</span><strong>{{ $expectedRevenue['formattedTotalCollected'] }}</strong></div>
                <div><span>Due Outstanding</span><strong>{{ $expectedRevenue['formattedTotalDueOutstanding'] }}</strong></div>
            </div>
            <div class="chart-header"><h2>Revenue vs Receivables</h2></div>
            <div class="receivables-chart">
                <canvas data-receivables-chart data-chart='@json($receivablesChart)' aria-label="Payments collected versus outstanding receivables" role="img"></canvas>
                <div class="revenue-bars receivables-fallback">
                    @foreach ($revenueBars as $bar)
                        <div class="revenue-bar">
                            <strong>{{ $bar['value'] }}</strong>
                            <i style="--h: {{ $bar['height'] }}px; --c: {{ $bar['color'] }}"></i>
                            <span>{{ $bar['label'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
            @if (! empty($receivableRows))
                <div class="dashboard-mini-table">
                    <table>
                        <thead><tr><th>Customer</th><th>Reference</th><th>Paid</th><th>Balance</th><th>Status</th></tr></thead>
                        <tbody>
                            @foreach ($receivableRows as $row)
                                <tr>
                                    <td>{{ $row['customer_name'] }}</td>
                                    <td>{{ $row['sale_code'] }}</td>
                                    <td>{{ $row['formatted_paid'] }}</td>
                                    <td>{{ $row['formatted_balance'] }}</td>
                                    <td>{{ $row['status_label'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="dashboard-empty-state dashboard-empty-state-small">{{ $receivablesMonitoring['formattedTotalOutstanding'] }}</div>
            @endif
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
