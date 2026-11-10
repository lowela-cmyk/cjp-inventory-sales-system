@php
    // Part 10 (October 22, 2026): Dashboard and Analytics, laid out exactly as the System Analysis mockup.
    $filterQuery = $filterQuery ?? [];
    $invFilters = $inventory['filters'];
    $salesFilters = $salesTrend['filters'];
    // Each section's filter form keeps the other section's filters.
    $keepFilters = fn (string $prefix): array => collect($filterQuery)->reject(fn (mixed $value, string $key): bool => str_starts_with($key, $prefix))->all();
    $margin = $revenue['margin'];
    $peak = $salesTrend['peak'];
    // Volume cards use the raw liter totals, so every page shows the same "717,791 L" format (see x-stat-value).
    $rawLiters = [
        'Total Inventory in Stock' => $inventory['totals']['stock_liters'] ?? null,
        'Remaining Balance in Depots' => $inventory['totals']['remaining_liters'] ?? null,
        'Total Delivered' => $salesTrend['totals']['delivered_liters'] ?? null,
    ];
    // The Fuel Type / Depot cards only echoed the filters; the active filters show as chips instead.
    $filterCards = ['Fuel Type', 'Depot'];
    $optionName = fn (iterable $options, mixed $id, string $field = 'name'): ?string => $id === null || $id === ''
        ? null
        : collect($options)->first(fn (object $option): bool => (string) $option->id === (string) $id)?->{$field};
    $inventoryChips = array_filter([
        'Fuel' => $optionName($filterOptions['fuelTypes'], $invFilters['fuel_type_id'] ?? null),
        'Depot' => $optionName($filterOptions['depots'], $invFilters['depot_id'] ?? null),
        'Search' => ($invFilters['search'] ?? '') !== '' ? $invFilters['search'] : null,
    ]);
    $customer = collect($filterOptions['customers'])->first(fn (object $c): bool => (string) $c->id === (string) ($salesFilters['customer_id'] ?? ''));
    $salesChips = array_filter([
        'Fuel' => $optionName($filterOptions['fuelTypes'], $salesFilters['fuel_type_id'] ?? null),
        'Customer' => $customer ? ($customer->company_name ?: $customer->name) : null,
        'From' => $salesFilters['date_from'] ?? null,
        'To' => $salesFilters['date_to'] ?? null,
        'Search' => ($salesFilters['search'] ?? '') !== '' ? $salesFilters['search'] : null,
    ]);
@endphp

@component('layouts.admin', ['title' => 'Dashboard and Analytics', 'active' => 'dashboard'])
    <div class="dash">
        @if ($errors->any())
            <p class="form-alert" role="alert">{{ $errors->first() }}</p>
        @endif

        {{-- Current Inventory --}}
        <section class="dash-section" id="current-inventory" aria-labelledby="dash-inventory-title">
            <div class="page-heading-row">
                <h2 class="dash-section-title" id="dash-inventory-title">Current Inventory</h2>
                {{-- Part 11: Export the charts, KPI cards and AI insight as one PDF (see dashboard.js). --}}
                <form class="dash-actions" method="POST" action="{{ route('admin.dashboard.export') }}" data-dashboard-export data-confirm="none">
                    @csrf
                    @foreach ($filterQuery as $name => $value)
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endforeach
                    <x-export-button type="submit" data-dashboard-export-button />
                </form>
            </div>
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

                @include('admin.partials.dashboard-filter-chips', ['chips' => $inventoryChips])

                <div class="dash-kpis dash-kpis-6">
                    @foreach ($inventory['cards'] as [$label, $value])
                        @continue(in_array($label, $filterCards, true))
                        <div class="dash-kpi"><span>{{ $label }}</span><x-stat-value :value="$value" :liters="$rawLiters[$label] ?? null" /></div>
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

                @include('admin.partials.dashboard-filter-chips', ['chips' => $salesChips])

                <div class="dash-kpis dash-kpis-7">
                    @foreach ($salesTrend['cards'] as [$label, $value])
                        <div class="dash-kpi"><span>{{ $label }}</span><x-stat-value :value="$value" :liters="$rawLiters[$label] ?? null" /></div>
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
            {{-- Part 20 #4: a dashboard of four overview cards (database figures) and the AI Recommendations as grouped
                 bullets. Every bullet is escaped; only its **bold** markers become <strong> (DashboardInsightService::bulletHtml). --}}
            @php
                $insight = session('dashboardInsight');
                $insightSections = $insight['sections'] ?? [];
                $insightRecommendations = $insight['recommendations'] ?? [];
                if ($insight && $insightRecommendations === [] && $insightSections !== []) {
                    $insightRecommendations = app(\App\Services\DashboardInsightService::class)->parseRecommendations((string) ($insight['text'] ?? ''));
                }
            @endphp
            <div class="dash-panel ai-insight-panel ai-report-panel">
                <div class="dash-insight-head ai-report-head">
                    <p class="ai-report-lead">Overview of inventory, sales, lifting and open work for the filters above, with recommended next steps.</p>
                    <form method="POST" action="{{ route('admin.dashboard.insight') }}" data-ai-generate-form data-confirm="none">
                        @csrf
                        @foreach ($filterQuery as $name => $value)
                            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                        @endforeach
                        <button class="btn btn-primary" type="submit" data-loading-label="Generating…">{{ $insight ? 'Regenerate Insight and Reports' : 'Generate Insight and Reports' }}</button>
                    </form>
                </div>
                @if ($errors->has('insight'))
                    <p class="form-alert" role="alert">{{ $errors->first('insight') }}</p>
                @endif
                @if ($insight)
                    <div class="ai-insight-body ai-report" aria-live="polite">
                        @if (! empty($insight['note']))
                            <p class="dash-insight-note" role="note" data-insight-note>{{ $insight['note'] }}</p>
                        @endif
                        {{-- The plain-text report goes into the PDF export (dashboard.js). --}}
                        <div class="ai-insight-text" data-insight-text data-insight-raw="{{ $insight['report'] ?? $insight['text'] }}" hidden></div>
                        @if ($insightSections === [])
                            <div class="ai-report-empty" role="status">
                                <strong>No data to report yet</strong>
                                <p>{{ $insight['text'] }}</p>
                            </div>
                        @else
                            <div class="ai-report-overview">
                                @foreach ($insightSections as $section)
                                    <section class="ai-report-card" aria-labelledby="ai-report-{{ $loop->index }}">
                                        <header>
                                            <h3 id="ai-report-{{ $loop->index }}">{{ $section['title'] }}</h3>
                                            @if ($section['caption'])<small>{{ $section['caption'] }}</small>@endif
                                        </header>
                                        <dl>
                                            @foreach ($section['items'] as $item)
                                                <div @class(['ai-report-metric', 'is-attention' => $item['tone'] === 'attention'])>
                                                    <dt>{{ $item['label'] }}</dt>
                                                    <dd>{{ $item['value'] }}</dd>
                                                </div>
                                            @endforeach
                                        </dl>
                                    </section>
                                @endforeach
                            </div>
                            <section class="ai-report-recommendations" aria-labelledby="ai-report-recommendations-title" data-ai-recommendations>
                                <h3 id="ai-report-recommendations-title">AI Recommendations</h3>
                                @forelse ($insightRecommendations as $group => $bullets)
                                    <div class="ai-report-group">
                                        <h4>{{ $group }}</h4>
                                        <ul>
                                            @foreach ($bullets as $bullet)
                                                <li>{{ \App\Services\DashboardInsightService::bulletHtml($bullet) }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @empty
                                    <p class="ai-report-none">No recommendations for these filters.</p>
                                @endforelse
                            </section>
                        @endif
                        <footer class="ai-report-footer">
                            <p data-insight-source>{{ ($insight['source'] ?? '') === 'ai' ? 'AI-assisted recommendations from the dashboard totals; the overview figures come straight from the records. Use it to support, not replace, record verification.' : 'Summary generated from current dashboard data.' }}</p>
                            <p class="ai-report-generated" data-insight-generated="{{ $insight['generated_at'] ?? '' }}">Generated {{ $insight['generated_at'] ?? '' }}</p>
                        </footer>
                    </div>
                @else
                    <div class="ai-report-empty" role="status">
                        <strong>No report generated yet</strong>
                        <p>Click <em>Generate Insight and Reports</em> to build the overview and recommendations for the current filters.</p>
                    </div>
                @endif
            </div>
        </section>
    </div>
@endcomponent
