@component('layouts.admin', ['title' => 'Dashboard and Analytics', 'active' => 'dashboard'])
    <div class="metric-row">
        <div class="metric-card">
            <strong>PHP 3.6M</strong>
            <span>Sales Total</span>
        </div>
        <div class="metric-card">
            <strong>160,000 L</strong>
            <span>Fuel Lifted</span>
        </div>
        <div class="metric-card">
            <strong>40,000 L</strong>
            <span>Current Stocks</span>
        </div>
        <div class="metric-card">
            <strong>PHP 1.35M</strong>
            <span>Outstanding Receivables</span>
        </div>
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
                @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $day)
                    <div class="mini-bar">
                        <strong>PHP 0</strong>
                        <i></i>
                        <span>{{ $day }}</span>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="chart-panel">
            <div class="chart-header">
                <h2>Current Stock By Fuel Type</h2>
            </div>
            <div class="stock-bars">
                <div class="stock-bar">
                    <strong>40,000 L</strong>
                    <i style="--bar-height: 100px; --bar-color: #f7043a"></i>
                    <span>Premium</span>
                </div>
                <div class="stock-bar">
                    <strong>0 L</strong>
                    <i style="--bar-height: 2px; --bar-color: #339c46"></i>
                    <span>Diesel</span>
                </div>
                <div class="stock-bar">
                    <strong>0 L</strong>
                    <i style="--bar-height: 2px; --bar-color: #e28521"></i>
                    <span>Unleaded</span>
                </div>
            </div>
        </section>
    </div>
@endcomponent
