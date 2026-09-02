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
            <div class="chart-header"><h2>Unlifted Fuel Monitoring</h2></div>
            <form class="dashboard-filter-row" method="GET" action="{{ route('admin.dashboard') }}">
                <input type="date" name="unlifted_date_from" value="{{ $unliftedFuelFilters['date_from'] ?? '' }}" aria-label="Purchase date from">
                <input type="date" name="unlifted_date_to" value="{{ $unliftedFuelFilters['date_to'] ?? '' }}" aria-label="Purchase date to">
                <select name="unlifted_depot_id" aria-label="Filter by depot">
                    <option value="">Depot (All)</option>
                    @foreach ($unliftedFuelFilterOptions['depots'] as $depot)
                        <option value="{{ $depot->id }}" @selected((string) ($unliftedFuelFilters['depot_id'] ?? '') === (string) $depot->id)>{{ $depot->name }}</option>
                    @endforeach
                </select>
                <select name="unlifted_fuel_type_id" aria-label="Filter by fuel type">
                    <option value="">Fuel Type (All)</option>
                    @foreach ($unliftedFuelFilterOptions['fuelTypes'] as $fuelType)
                        <option value="{{ $fuelType->id }}" @selected((string) ($unliftedFuelFilters['fuel_type_id'] ?? '') === (string) $fuelType->id)>{{ $fuelType->name }}</option>
                    @endforeach
                </select>
                <select name="unlifted_lifting_status" aria-label="Filter by lifting status">
                    <option value="">Lifting Status (All)</option>
                    @foreach ($unliftedFuelFilterOptions['statuses'] as $status)
                        <option value="{{ $status }}" @selected(($unliftedFuelFilters['lifting_status'] ?? '') === $status)>{{ $status === 'partial' ? 'Partially Lifted' : ($status === 'lifted' ? 'Fully Lifted' : 'Unlifted') }}</option>
                    @endforeach
                </select>
                <button class="btn btn-primary" type="submit">Apply</button>
            </form>
            <div class="unlifted-fuel-chart">
                <canvas data-unlifted-fuel-chart data-chart='@json($unliftedFuelChart)' aria-label="Purchased lifted and unlifted fuel" role="img"></canvas>
                <div class="revenue-bars unlifted-fuel-fallback">
                    @foreach ([
                        ['Purchased', $unliftedMonitoring['summary']['formatted_purchased'], 120, '#0d1424'],
                        ['Lifted', $unliftedMonitoring['summary']['formatted_lifted'], 120, '#3b9a35'],
                        ['Unlifted', $unliftedMonitoring['summary']['formatted_remaining'], 120, '#f7043a'],
                    ] as $bar)
                        <div class="revenue-bar">
                            <strong>{{ $bar[1] }}</strong>
                            <i style="--h: {{ $bar[2] }}px; --c: {{ $bar[3] }}"></i>
                            <span>{{ $bar[0] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="dashboard-kpi-strip">
                <div><span>Total Lifted</span><strong>{{ $unliftedMonitoring['summary']['formatted_lifted'] }}</strong></div>
                <div><span>Partially Lifted</span><strong>{{ number_format($unliftedMonitoring['summary']['partial_count']) }}</strong></div>
                <div><span>Fully Unlifted</span><strong>{{ number_format($unliftedMonitoring['summary']['unlifted_count']) }}</strong></div>
            </div>
            @if (! empty($unliftedFuelRows))
                <div class="dashboard-mini-table">
                    <table>
                        <thead><tr><th>Purchase</th><th>Depot</th><th>Fuel</th><th>Purchased</th><th>Lifted</th><th>Unlifted</th><th>Status</th><th>Date</th></tr></thead>
                        <tbody>
                            @foreach ($unliftedFuelRows as $row)
                                <tr>
                                    <td>{{ $row['purchase_code'] }}</td>
                                    <td>{{ $row['depot_name'] }}</td>
                                    <td>{{ $row['fuel_name'] }}</td>
                                    <td>{{ $row['formatted_purchased'] }}</td>
                                    <td>{{ $row['formatted_lifted'] }}</td>
                                    <td>{{ $row['formatted_remaining'] }}</td>
                                    <td>{{ $row['lift_status_label'] }}</td>
                                    <td>{{ $row['formatted_purchase_date'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="dashboard-empty-state dashboard-empty-state-small">0 L</div>
            @endif
            <div class="dashboard-breakdown-grid">
                <div>
                    <h3>By Fuel Type</h3>
                    @forelse ($unliftedMonitoring['fuelBreakdown'] as $row)
                        <div class="demand-row"><span>{{ $row['label'] }}</span><span class="demand-track"><i class="demand-fill" style="--p:{{ $unliftedMonitoring['summary']['remaining_liters'] > 0 ? round(($row['liters'] / $unliftedMonitoring['summary']['remaining_liters']) * 100) : 0 }}%"></i></span><strong>{{ $row['formatted_liters'] }}</strong></div>
                    @empty
                        <div class="dashboard-empty-state dashboard-empty-state-small">No data available</div>
                    @endforelse
                </div>
                <div>
                    <h3>By Depot</h3>
                    @forelse ($unliftedMonitoring['depotBreakdown'] as $row)
                        <div class="demand-row"><span>{{ $row['label'] }}</span><span class="demand-track"><i class="demand-fill" style="--p:{{ $unliftedMonitoring['summary']['remaining_liters'] > 0 ? round(($row['liters'] / $unliftedMonitoring['summary']['remaining_liters']) * 100) : 0 }}%"></i></span><strong>{{ $row['formatted_liters'] }}</strong></div>
                    @empty
                        <div class="dashboard-empty-state dashboard-empty-state-small">No data available</div>
                    @endforelse
                </div>
            </div>
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
