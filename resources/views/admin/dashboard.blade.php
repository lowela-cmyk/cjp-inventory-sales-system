@component('layouts.admin', ['title' => 'Dashboard and Analytics', 'active' => 'dashboard'])
    <div class="metric-row">
        @foreach ([
            ['Total Inventory (KL)', '320 KL', 'Across all depots', ''],
            ['Total Sales Revenue', 'PHP 4,580,000', 'Cumulative', ''],
            ['Outstanding Balance', 'PHP 2,280,000', 'Receivables', 'color:#a31318'],
            ['Unlifted Fuel (KL)', '320 KL', 'Pending lifting', ''],
            ['Active Deliveries', '1', 'In transit', ''],
        ] as $card)
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
                <div class="stock-bar"><strong>40,000 L</strong><i style="--bar-height: 100px; --bar-color: #f7043a"></i><span>Premium</span></div>
                <div class="stock-bar"><strong>0 L</strong><i style="--bar-height: 2px; --bar-color: #3b9a35"></i><span>Diesel</span></div>
                <div class="stock-bar"><strong>0 L</strong><i style="--bar-height: 2px; --bar-color: #e28a22"></i><span>Unleaded</span></div>
            </div>
        </section>

        <section class="chart-panel">
            <div class="chart-header"><h2>Predicted Revenue Trend (Next 5 Years)</h2></div>
            <div class="mock-chart-line">
                <div class="mock-line-grid"></div>
                <div class="mock-line-path">
                    <span class="mock-point" style="left:1%;top:58%"></span>
                    <span class="mock-point" style="left:20%;top:50%"></span>
                    <span class="mock-point" style="left:40%;top:42%"></span>
                    <span class="mock-point" style="left:59%;top:33%"></span>
                    <span class="mock-point" style="left:78%;top:23%"></span>
                    <span class="mock-point" style="left:97%;top:11%"></span>
                </div>
            </div>
            <div class="legend-row">
                <span><i class="legend-dot"></i>Historical revenue</span>
                <span><i class="legend-dot red"></i>Predicted (+18% YoY)</span>
                <strong style="margin-left:auto;color:#ef2a2f">Year 2031 target: PHP10.5M</strong>
            </div>
            <div class="chart-header"><h2>Revenue vs Receivables</h2></div>
            <div class="revenue-bars">
                <div class="revenue-bar"><strong>PHP4,580,000</strong><i style="--h:150px;--c:#0d1424"></i><span>Revenue</span></div>
                <div class="revenue-bar"><strong>PHP2,300,000</strong><i style="--h:92px;--c:#0d1424"></i><span>Collected</span></div>
                <div class="revenue-bar"><strong>PHP2,280,000</strong><i style="--h:90px;--c:#a7191d"></i><span>Receivables</span></div>
            </div>
        </section>

        <section class="chart-panel">
            <div class="chart-header"><h2>Predicted Peak Demand</h2></div>
            <div class="demand-list">
                <h3>Peak Days of the Week</h3>
                @foreach ([['Sun',0],['Mon',65],['Tue',0],['Wed',100],['Thu',0],['Fri',0],['Sat',0]] as $item)
                    <div class="demand-row"><span>{{ in_array($item[0], ['Sun','Mon','Wed']) ? 'Hot ' : '' }}{{ $item[0] }}</span><span class="demand-track"><i class="demand-fill" style="--p:{{ $item[1] }}%"></i></span><strong>{{ $item[1] }}%</strong></div>
                @endforeach
                <h3 style="margin-top:20px">Peak Months of the Year</h3>
                @foreach ([['Jan',0],['Feb',0],['Mar',0],['Apr',100],['May',0],['Jun',0],['Jul',0],['Aug',0],['Sep',0],['Oct',0],['Nov',0],['Dec',0]] as $item)
                    <div class="demand-row"><span>{{ $item[0] === 'Apr' ? 'Hot ' : '' }}{{ $item[0] }}</span><span class="demand-track"><i class="demand-fill" style="--p:{{ $item[1] }}%"></i></span><strong>{{ $item[1] }}%</strong></div>
                @endforeach
            </div>
        </section>
    </div>
@endcomponent
