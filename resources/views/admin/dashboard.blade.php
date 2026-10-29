@php
    // Part 10 (October 22, 2026): Dashboard and Analytics, laid out exactly as the System Analysis mockup.
    $filterQuery = $filterQuery ?? [];
    $invFilters = $inventory['filters'];
    $salesFilters = $salesTrend['filters'];
    // Each section's filter form keeps the other section's filters.
    $keepFilters = fn (string $prefix): array => collect($filterQuery)->reject(fn (mixed $value, string $key): bool => str_starts_with($key, $prefix))->all();
    $margin = $revenue['margin'];
    $peak = $salesTrend['peak'];
@endphp

@component('layouts.admin', ['title' => 'Dashboard and Analytics', 'active' => 'dashboard'])
    <div class="dash">
        {{-- Part 11: Export the charts, KPI cards and AI insight as one PDF (see dashboard.js). --}}
        <div class="dash-actions">
            <form method="POST" action="{{ route('admin.dashboard.export') }}" data-dashboard-export>
                @csrf
                @foreach ($filterQuery as $name => $value)
                    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                @endforeach
                <button class="btn btn-secondary" type="submit" data-dashboard-export-button>Export</button>
            </form>
        </div>
        @if ($errors->any())
            <p class="form-alert" role="alert">{{ $errors->first() }}</p>
        @endif

        {{-- Current Inventory --}}
        <section class="dash-section" id="current-inventory" aria-labelledby="dash-inventory-title">
            <h2 class="dash-section-title" id="dash-inventory-title">Current Inventory</h2>
            <div class="dash-panel">
                <form class="dash-filters dash-filters-inventory" method="GET" action="{{ route('admin.dashboard') }}#current-inventory" data-auto-filter>
                    @foreach ($keepFilters('inv_') as $name => $value)
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endforeach
                    <input type="search" name="inv_search" placeholder="Search by Purchase ID" aria-label="Search by Purchase ID" value="{{ $invFilters['search'] }}">
                    <select name="inv_fuel_type_id" aria-label="Filter current inventory by fuel type">
                        <option value="">Fuel Type (All)</option>
                        @foreach ($filterOptions['fuelTypes'] as $fuelType)
                            <option value="{{ $fuelType->id }}" @selected((string) $invFilters['fuel_type_id'] === (string) $fuelType->id)>{{ $fuelType->name }}</option>
                        @endforeach
                    </select>
                    <select name="inv_depot_id" aria-label="Filter current inventory by depot">
                        <option value="">Depots (ALL)</option>
                        @foreach ($filterOptions['depots'] as $depot)
                            <option value="{{ $depot->id }}" @selected((string) $invFilters['depot_id'] === (string) $depot->id)>{{ $depot->name }}</option>
                        @endforeach
                    </select>
                </form>

                <div class="dash-kpis dash-kpis-6">
                    @foreach ($inventory['cards'] as [$label, $value])
                        <div class="dash-kpi"><span>{{ $label }}</span><strong>{{ $value }}</strong></div>
                    @endforeach
                </div>

                <div class="dash-charts dash-charts-3">
                    <div class="dash-chart-block">
                        <h3 class="dash-chart-title">Current Stock By Fuel Type (Stock Levels)</h3>
                        <div class="dash-chart">
                            @include('admin.partials.dashboard-chart', ['chart' => $inventory['stockChart'], 'label' => 'Current stock by fuel type'])
                            <p class="dash-note">Expected Stock Out Rate: <strong class="dash-red" data-stock-out-rate>{{ $inventory['stockOutRateLabel'] }}</strong></p>
                        </div>
                    </div>
                    <div class="dash-chart-block">
                        <h3 class="dash-chart-title">Fuel Lifting Status</h3>
                        <div class="dash-chart">
                            @include('admin.partials.dashboard-chart', ['chart' => $inventory['liftingChart'], 'label' => 'Purchases by lifting status'])
                        </div>
                    </div>
                    <div class="dash-chart-block">
                        <h3 class="dash-chart-title">Purchased V.S Stock In</h3>
                        <div class="dash-chart">
                            @include('admin.partials.dashboard-chart', ['chart' => $inventory['purchasedChart'], 'label' => 'Purchased liters completed versus uncompleted'])
                        </div>
                    </div>
                </div>
                {{-- Part 13: garage stock, each fuel's Tank 1 and Tank 2 (included in Total Inventory in Stock). --}}
                <div class="dash-garage" aria-label="Garage tanks">
                    <h3 class="dash-chart-title">Garage Tanks</h3>
                    <div class="dash-garage-fuels">
                        @foreach ($inventory['garageFuels'] as $fuel)
                            <div class="dash-garage-fuel">
                                <strong>{{ $fuel['fuel_name'] }}</strong>
                                @foreach ($fuel['tanks'] as $tank)
                                    <span>Tank {{ $tank->tank_number }}: {{ number_format($tank->contents, 2) }} L ({{ rtrim(rtrim(number_format($tank->percent, 1), '0'), '.') }}%)</span>
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        {{-- Expected Sales Revenue | Stock Out V.S Receivables Margin --}}
        <div class="dash-revenue-row">
            <section class="dash-section" aria-labelledby="dash-revenue-title">
                <h2 class="dash-section-title" id="dash-revenue-title">Expected Sales Revenue</h2>
                <div class="dash-chart dash-chart-panel" data-dashboard-revenue data-series='@json($revenue['series'])' data-default-period="month">
                    <div class="dash-chart-canvas dash-chart-canvas-tall">
                        <canvas role="img" aria-label="Actual and expected sales revenue" @if ($revenue['series']['month']['empty']) hidden @endif></canvas>
                        <div class="dashboard-empty-state" data-revenue-empty @unless ($revenue['series']['month']['empty']) hidden @endunless>No data yet</div>
                    </div>
                    <div class="dash-chart-footer">
                        <p class="dash-note">Increase Rate: <strong data-revenue-increase>{{ $revenue['series']['month']['increaseRateLabel'] }}</strong></p>
                        <div class="dash-toggle" role="group" aria-label="Revenue period">
                            @foreach (['week' => 'Week', 'month' => 'Month', 'year' => 'Year'] as $period => $periodLabel)
                                <button class="btn {{ $period === 'month' ? 'is-selected' : '' }}" type="button" data-revenue-period="{{ $period }}" aria-pressed="{{ $period === 'month' ? 'true' : 'false' }}">{{ $periodLabel }}</button>
                            @endforeach
                        </div>
                    </div>
                </div>
            </section>

            <section class="dash-section" aria-labelledby="dash-margin-title">
                <h2 class="dash-section-title" id="dash-margin-title">Stock Out V.S Receivables Margin</h2>
                <div class="dash-chart dash-chart-panel">
                    @include('admin.partials.dashboard-chart', ['chart' => $margin['chart'], 'label' => 'Stock out value versus receivables of delivered sales', 'class' => 'dash-chart-canvas-tall'])
                    <div class="dash-notes-stack">
                        <p class="dash-note">Inventory Variance Rate: <strong class="dash-red">{{ $margin['varianceRateLabel'] }}</strong></p>
                        <p class="dash-note">Missing: <strong>{{ $margin['missingLabel'] }}</strong></p>
                    </div>
                </div>
            </section>
        </div>

        {{-- Sales Trend --}}
        <section class="dash-section" id="sales-trend" aria-labelledby="dash-sales-title">
            <h2 class="dash-section-title" id="dash-sales-title">Sales Trend</h2>
            <div class="dash-panel">
                <form class="dash-filters dash-filters-sales" method="GET" action="{{ route('admin.dashboard') }}#sales-trend" data-auto-filter>
                    @foreach ($keepFilters('sales_') as $name => $value)
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endforeach
                    <input type="search" name="sales_search" placeholder="Search by Sales ID" aria-label="Search by Sales ID" value="{{ $salesFilters['search'] }}">
                    {{-- Part 12: Date is a From / To range (an older ?sales_period= link shows its days here). --}}
                    <x-filter.date-range label="Date" from="sales_date_from" to="sales_date_to" :from-value="$salesFilters['date_from']" :to-value="$salesFilters['date_to']" />
                    <select name="sales_fuel_type_id" aria-label="Filter sales by fuel type">
                        <option value="">Fuel Type (All)</option>
                        @foreach ($filterOptions['fuelTypes'] as $fuelType)
                            <option value="{{ $fuelType->id }}" @selected((string) $salesFilters['fuel_type_id'] === (string) $fuelType->id)>{{ $fuelType->name }}</option>
                        @endforeach
                    </select>
                    <select name="sales_customer_id" aria-label="Filter sales by customer">
                        <option value="">Customer (ALL)</option>
                        @foreach ($filterOptions['customers'] as $customer)
                            <option value="{{ $customer->id }}" @selected((string) $salesFilters['customer_id'] === (string) $customer->id)>{{ $customer->company_name ?: $customer->name }}</option>
                        @endforeach
                    </select>
                </form>

                <div class="dash-kpis dash-kpis-7">
                    @foreach ($salesTrend['cards'] as [$label, $value])
                        <div class="dash-kpi"><span>{{ $label }}</span><strong>{{ $value }}</strong></div>
                    @endforeach
                </div>

                <div class="dash-charts dash-charts-2">
                    <div class="dash-chart-block">
                        <h3 class="dash-chart-title">Fuel Sold</h3>
                        <div class="dash-chart">
                            @include('admin.partials.dashboard-chart', ['chart' => $salesTrend['fuelSoldChart'], 'label' => 'Liters sold by fuel type'])
                        </div>
                    </div>
                    <div class="dash-chart-block">
                        <h3 class="dash-chart-title">Payment Status (Customers)</h3>
                        <div class="dash-chart">
                            @include('admin.partials.dashboard-chart', ['chart' => $salesTrend['paymentChart'], 'label' => 'Customers by payment status'])
                        </div>
                    </div>
                </div>

                <div class="dash-chart-block">
                    <h3 class="dash-chart-title">Peak Demands</h3>
                    <div class="dash-chart">
                        @include('admin.partials.dashboard-chart', ['chart' => $peak['chart'], 'label' => 'Liters sold per month of '.$peak['year'], 'class' => 'dash-chart-canvas-tall'])
                        <div class="dash-peak-notes">
                            <div class="dash-notes-stack">
                                <p class="dash-note">Month With Highest Sales : <strong>{{ $peak['highestMonthLabel'] }}</strong></p>
                                <p class="dash-note">Expected Month with Most Demand (YoY) : <strong>{{ $peak['yoyMonthLabel'] }}</strong></p>
                            </div>
                            <p class="dash-note dash-note-end">Expected Increase Rate by next Month : <strong @class(['dash-red' => ($peak['nextMonthRate'] ?? 0) < 0])>{{ $peak['nextMonthRateLabel'] }}</strong></p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        {{-- Insights and A.I Reporting (includes the inventory variance explanation) --}}
        <section class="dash-section" id="insights" aria-labelledby="dash-insights-title">
            <h2 class="dash-section-title" id="dash-insights-title">Insights and A.I Reporting</h2>
            <div class="dash-panel ai-insight-panel">
                <div class="dash-insight-head">
                    <form method="POST" action="{{ route('admin.dashboard.insight') }}" data-ai-generate-form>
                        @csrf
                        @foreach ($filterQuery as $name => $value)
                            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                        @endforeach
                        <button class="btn btn-primary" type="submit">Generate Insight and Reports</button>
                    </form>
                </div>
                @if (session('dashboardInsight'))
                    <div class="ai-insight-body" aria-live="polite">
                        @if (session('dashboardInsight.note'))
                            <p class="dash-insight-note" role="note" data-insight-note>{{ session('dashboardInsight.note') }}</p>
                        @endif
                        <div class="ai-insight-text" data-insight-text data-insight-raw="{{ session('dashboardInsight.text') }}">{!! nl2br(e(session('dashboardInsight.text'))) !!}</div>
                        <p data-insight-source>{{ session('dashboardInsight.source') === 'ai' ? 'AI-assisted insight from the dashboard totals. Use it to support, not replace, record verification.' : 'Summary generated from current dashboard data.' }}</p>
                        <span data-insight-generated="{{ session('dashboardInsight.generated_at') }}">Generated {{ session('dashboardInsight.generated_at') }}</span>
                    </div>
                @endif
            </div>
        </section>
    </div>
@endcomponent
