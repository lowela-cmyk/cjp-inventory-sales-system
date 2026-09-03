@component('layouts.admin', ['title' => 'Reports Tab', 'active' => 'reports'])
    <h2 class="section-title">Reports and A.I Insights</h2>
    <form class="report-intro report-toolbar" method="GET" action="{{ route('admin.reports') }}">
        <div class="filter-row" style="margin-bottom:0">
            <select class="form-control" name="period" aria-label="Report period">
                @foreach (['all' => 'All Time', 'today' => 'Today', 'date' => 'Specific Date', 'range' => 'Date Range', 'month' => 'Month', 'year' => 'Year'] as $value => $label)
                    <option value="{{ $value }}" @selected($filters['period'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <input class="form-control" type="date" name="date" value="{{ $filters['date'] }}" aria-label="Specific date">
            <input class="form-control" type="date" name="start_date" value="{{ $filters['start_date'] }}" aria-label="Start date">
            <input class="form-control" type="date" name="end_date" value="{{ $filters['end_date'] }}" aria-label="End date">
            <input class="form-control" type="month" name="month" value="{{ $filters['month'] }}" aria-label="Month">
            <input class="form-control" type="number" name="year" min="2000" max="2100" value="{{ $filters['year'] }}" aria-label="Year">
        </div>
        <div class="report-actions">
            <button class="btn btn-primary" type="submit">Generate</button>
            <a class="btn btn-secondary" href="{{ route('admin.reports.export', request()->query()) }}">Export</a>
            <button class="btn btn-secondary" type="button" onclick="window.print()">Print</button>
        </div>
    </form>
    <section class="report-section ai-insight-panel">
        <div class="chart-header">
            <h2>Revenue AI Insight</h2>
            <form method="POST" action="{{ route('admin.reports.revenue-insight') }}" data-ai-generate-form>
                @csrf
                <input type="hidden" name="period" value="{{ $filters['period'] }}">
                <input type="hidden" name="date" value="{{ $filters['date'] }}">
                <input type="hidden" name="start_date" value="{{ $filters['start_date'] }}">
                <input type="hidden" name="end_date" value="{{ $filters['end_date'] }}">
                <input type="hidden" name="month" value="{{ $filters['month'] }}">
                <input type="hidden" name="year" value="{{ $filters['year'] }}">
                <button class="btn btn-primary" type="submit">Generate Insight</button>
            </form>
        </div>
        <div class="ai-insight-body">
            @if (session('revenueInsight'))
                <div class="ai-insight-text">{!! nl2br(e(session('revenueInsight.text'))) !!}</div>
                <p>AI-assisted business insight based on system records. Use it to support, not replace, management judgment.</p>
                <span>Generated {{ session('revenueInsight.generated_at') }}</span>
            @elseif (session('revenueInsightNotice'))
                <div class="dashboard-empty-state dashboard-empty-state-small">{{ session('revenueInsightNotice') }}</div>
            @else
                <div class="dashboard-empty-state dashboard-empty-state-small">Generate a revenue insight from the current report values.</div>
            @endif
        </div>
    </section>
    <section class="report-section ai-insight-panel">
        <div class="chart-header">
            <h2>Business AI Insights</h2>
            <form method="POST" action="{{ route('admin.reports.business-insight') }}" data-ai-generate-form>
                @csrf
                <input type="hidden" name="period" value="{{ $filters['period'] }}">
                <input type="hidden" name="date" value="{{ $filters['date'] }}">
                <input type="hidden" name="start_date" value="{{ $filters['start_date'] }}">
                <input type="hidden" name="end_date" value="{{ $filters['end_date'] }}">
                <input type="hidden" name="month" value="{{ $filters['month'] }}">
                <input type="hidden" name="year" value="{{ $filters['year'] }}">
                <button class="btn btn-primary" type="submit">Generate Insight</button>
            </form>
        </div>
        <div class="ai-insight-body">
            @if (session('businessInsight'))
                <div class="ai-insight-text">{!! nl2br(e(session('businessInsight.text'))) !!}</div>
                <p>AI-assisted business insights based on system-calculated analytics. Use them to support, not replace, management judgment.</p>
                <span>Generated {{ session('businessInsight.generated_at') }}</span>
            @elseif (session('businessInsightNotice'))
                <div class="dashboard-empty-state dashboard-empty-state-small">{{ session('businessInsightNotice') }}</div>
            @else
                <div class="dashboard-empty-state dashboard-empty-state-small">Generate consolidated business insights from the current analytics.</div>
            @endif
        </div>
    </section>
    <section class="report-section ai-insight-panel">
        <div class="chart-header">
            <h2>Sales Trend AI Summary</h2>
            <form method="POST" action="{{ route('admin.reports.sales-trend-summary') }}" data-ai-generate-form>
                @csrf
                <input type="hidden" name="period" value="{{ $filters['period'] }}">
                <input type="hidden" name="date" value="{{ $filters['date'] }}">
                <input type="hidden" name="start_date" value="{{ $filters['start_date'] }}">
                <input type="hidden" name="end_date" value="{{ $filters['end_date'] }}">
                <input type="hidden" name="month" value="{{ $filters['month'] }}">
                <input type="hidden" name="year" value="{{ $filters['year'] }}">
                <button class="btn btn-primary" type="submit">Generate Summary</button>
            </form>
        </div>
        <div class="ai-insight-body">
            @if (session('salesTrendSummary'))
                <div class="ai-insight-text">{!! nl2br(e(session('salesTrendSummary.text'))) !!}</div>
                <p>AI-assisted trend summary based on system-calculated Sales Trends. Use it to support, not replace, management judgment.</p>
                <span>Generated {{ session('salesTrendSummary.generated_at') }}</span>
            @elseif (session('salesTrendSummaryNotice'))
                <div class="dashboard-empty-state dashboard-empty-state-small">{{ session('salesTrendSummaryNotice') }}</div>
            @else
                <div class="dashboard-empty-state dashboard-empty-state-small">Generate a sales trend summary from the current Sales Trends values.</div>
            @endif
        </div>
    </section>
    <section class="report-panel">
        <div class="report-intro">
            <strong>Sales report generated from current database records.</strong>
            <span>{{ $filterLabel }}</span>
        </div>
        <div class="report-sheet">
            @if ($errors->any())
                <div class="alert-bar alert-warning" style="margin-bottom:18px">
                    <div class="alert-icon">!</div>
                    <div>
                        <div class="alert-title">Invalid report filters</div>
                        <div class="detail-meta">{{ $errors->first() }}</div>
                    </div>
                    <span></span>
                </div>
            @endif

            <div class="metric-row report-metrics">
                @foreach ($summary as $card)
                    <div class="metric-card">
                        <em>{{ $card['label'] }}</em>
                        <strong>{{ $card['value'] }}</strong>
                        <span>{{ $filterLabel }}</span>
                    </div>
                @endforeach
            </div>

            <div class="dashboard-grid report-grid">
                <section class="chart-panel">
                    <div class="chart-header"><h2>Sales Over Time</h2></div>
                    <div class="sales-bars report-bars">
                        @forelse ($salesTrend as $bar)
                            <div class="mini-bar">
                                <strong>{{ $bar['value'] }}</strong>
                                <i style="--bar-height: {{ $bar['height'] }}px; background: {{ $bar['color'] }}"></i>
                                <span>{{ $bar['label'] }}</span>
                            </div>
                        @empty
                            <p class="empty-cell">No records found.</p>
                        @endforelse
                    </div>
                </section>

                <section class="chart-panel">
                    <div class="chart-header"><h2>Revenue vs Receivables</h2></div>
                    <div class="sales-bars report-bars">
                        @foreach ($revenueBars as $bar)
                            <div class="mini-bar">
                                <strong>{{ $bar['value'] }}</strong>
                                <i style="--bar-height: {{ $bar['height'] }}px; background: {{ $bar['color'] }}"></i>
                                <span>{{ $bar['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </section>
            </div>

            <section class="report-section">
                <div class="chart-header"><h2>Sales Transactions</h2></div>
                <div class="table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Fuel/Items</th>
                                <th>Qty</th>
                                <th>Total</th>
                                <th>Paid</th>
                                <th>Balance</th>
                                <th>Status</th>
                                <th>Latest Payment</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($transactions as $row)
                                <tr>
                                    <td>{{ $row['sale_code'] }}</td>
                                    <td>{{ $row['sale_date'] }}</td>
                                    <td>{{ $row['customer_name'] }}</td>
                                    <td>{{ $row['items'] }}</td>
                                    <td>{{ $row['quantity_liters'] }}</td>
                                    <td>{{ $row['sale_total'] }}</td>
                                    <td>{{ $row['paid'] }}</td>
                                    <td>{{ $row['balance'] }}</td>
                                    <td>{{ $row['status'] }}</td>
                                    <td>{{ $row['latest_payment_date'] }}</td>
                                </tr>
                            @empty
                                <tr><td class="empty-cell" colspan="10">No records found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="dashboard-grid report-grid">
                <section class="report-section">
                    <div class="chart-header"><h2>Sales by Fuel Type</h2></div>
                    <div class="table-wrap">
                        <table class="admin-table">
                            <thead><tr><th>Fuel Type</th><th>Qty Sold</th><th>Transactions</th><th>Sales Amount</th></tr></thead>
                            <tbody>
                                @forelse ($fuelBreakdown as $row)
                                    <tr>
                                        <td>{{ $row['name'] }}</td>
                                        <td>{{ $row['quantity'] }}</td>
                                        <td>{{ $row['transactions'] }}</td>
                                        <td>{{ $row['total'] }}</td>
                                    </tr>
                                @empty
                                    <tr><td class="empty-cell" colspan="4">No records found.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="report-section">
                    <div class="chart-header"><h2>Payment Report</h2></div>
                    <div class="table-wrap">
                        <table class="admin-table">
                            <thead><tr><th>Payment Type</th><th>Payments</th><th>Total Received</th></tr></thead>
                            <tbody>
                                @foreach ($paymentBreakdown as $row)
                                    <tr>
                                        <td>{{ $row['label'] }}</td>
                                        <td>{{ $row['count'] }}</td>
                                        <td>{{ $row['total'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <section class="report-section">
                <div class="chart-header"><h2>Payment History</h2></div>
                <div class="table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Payment Ref</th>
                                <th>Sale Ref</th>
                                <th>Date</th>
                                <th>Customer</th>
                                <th>Method</th>
                                <th>Amount</th>
                                <th>Received By</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($paymentHistory as $row)
                                <tr>
                                    <td>{{ $row['payment_code'] }}</td>
                                    <td>{{ $row['sale_code'] }}</td>
                                    <td>{{ $row['payment_date'] }}</td>
                                    <td>{{ $row['customer_name'] }}</td>
                                    <td>{{ $row['method'] }}</td>
                                    <td>{{ $row['amount'] }}</td>
                                    <td>{{ $row['received_by'] }}</td>
                                </tr>
                            @empty
                                <tr><td class="empty-cell" colspan="7">No records found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="dashboard-grid report-grid">
                <section class="report-section">
                    <div class="chart-header"><h2>Sales by Customer</h2></div>
                    <div class="table-wrap">
                        <table class="admin-table">
                            <thead><tr><th>Customer</th><th>Transactions</th><th>Total Sales</th><th>Total Paid</th><th>Outstanding</th></tr></thead>
                            <tbody>
                                @forelse ($customerBreakdown as $row)
                                    <tr>
                                        <td>{{ $row['customer'] }}</td>
                                        <td>{{ $row['transactions'] }}</td>
                                        <td>{{ $row['sales'] }}</td>
                                        <td>{{ $row['paid'] }}</td>
                                        <td>{{ $row['balance'] }}</td>
                                    </tr>
                                @empty
                                    <tr><td class="empty-cell" colspan="5">No records found.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="report-section">
                    <div class="chart-header"><h2>Receivable Report</h2></div>
                    <div class="table-wrap">
                        <table class="admin-table">
                            <thead><tr><th>Customer</th><th>Reference</th><th>Sale Total</th><th>Paid</th><th>Balance</th><th>Status</th><th>Due</th></tr></thead>
                            <tbody>
                                @forelse ($receivables as $row)
                                    <tr>
                                        <td>{{ $row['customer_name'] }}</td>
                                        <td>{{ $row['sale_code'] }}</td>
                                        <td>{{ $row['sale_total'] }}</td>
                                        <td>{{ $row['paid'] }}</td>
                                        <td>{{ $row['balance'] }}</td>
                                        <td>{{ $row['status'] }}</td>
                                        <td>{{ $row['due_date'] ?: 'N/A' }}</td>
                                    </tr>
                                @empty
                                    <tr><td class="empty-cell" colspan="7">No records found.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </section>
@endcomponent
