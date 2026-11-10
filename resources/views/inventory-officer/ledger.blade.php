@php
    $activeTab = $activeTab ?? (($state ?? 'ledger') === 'transactions' ? 'transactions' : 'ledger');
    // One ledger page serves Inventory Officers, Dispatch Officers and Admin.
    [$ledgerLayout, $ledgerRoutePrefix] = match (true) {
        request()->routeIs('admin.*') => ['layouts.admin', 'admin.ledger'],
        request()->routeIs('dispatch.*') => ['layouts.dispatch', 'dispatch.ledger'],
        default => ['layouts.inventory-officer', 'inventory-officer.ledger'],
    };
    $filters = $filters ?? [];
    $filterOptions = $filterOptions ?? ['depots' => collect(), 'fuelTypes' => collect(), 'statuses' => []];
    // Part 20 #1: the receipt images are served only to the roles the withdrawal-receipts.show route allows.
    $canViewReceipts = in_array(auth()->user()?->role, ['admin', 'inventory_officer'], true);
@endphp

@component($ledgerLayout, ['title' => 'Inventory Ledger', 'active' => 'ledger'])
    <div data-tabs>
        <div class="page-heading-row">
            <h2 class="section-title">Ledger Tab</h2>
            <x-export-button />
        </div>
        <div class="tabs">
            <button class="tab-button {{ $activeTab === 'ledger' ? 'is-active' : '' }}" type="button" data-tab-target="ledger" data-tab-url="{{ route($ledgerRoutePrefix) }}">Ledger</button>
            <button class="tab-button {{ $activeTab === 'transactions' ? 'is-active' : '' }}" type="button" data-tab-target="transactions" data-tab-url="{{ route($ledgerRoutePrefix.'.transactions') }}">Transaction</button>
        </div>

        <section data-tab-panel="ledger" {{ $activeTab !== 'ledger' ? 'hidden' : '' }}>
            <x-filter.bar class="toolbar-ledger" :action="route($ledgerRoutePrefix)">
                <x-filter.search label="Search ledger" :value="$search" />
                <x-filter.date-range label="Purchase date" :from-value="$filters['date_from'] ?? null" :to-value="$filters['date_to'] ?? null" />
                <x-filter.select name="depot_id" label="Depot" :options="$filterOptions['depots']" :selected="$filters['depot_id'] ?? null" />
                <x-filter.select name="fuel_type_id" label="Fuel Type" :options="$filterOptions['fuelTypes']" :selected="$filters['fuel_type_id'] ?? null" />
            </x-filter.bar>
            <div class="table-wrap">
                <table class="admin-table ledger-table" data-card-keys="0,1,5">
                    <thead><tr><th>Purchase ID</th><th>Fuel Type</th><th>Depot</th><th>Purchased Quantity</th><th>Total Lifted</th><th>Remaining Quantity</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse ($ledger as $row)
                            <tr>
                                @foreach ($row as $cell)
                                    @if ($loop->last)
                                        <td><x-admin.status-badge :status="$cell" /></td>
                                    @else
                                        <td>{{ $cell }}</td>
                                    @endif
                                @endforeach
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="7">No inventory movements found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section data-tab-panel="transactions" {{ $activeTab !== 'transactions' ? 'hidden' : '' }}>
            <x-filter.bar class="toolbar-ledger" :action="route($ledgerRoutePrefix.'.transactions')">
                <x-filter.search label="Search ledger transactions" :value="$search" />
                <x-filter.select name="status" label="Status" :options="$filterOptions['statuses']" :selected="$filters['status'] ?? null" />
                <x-filter.date-range label="Purchase date" :from-value="$filters['date_from'] ?? null" :to-value="$filters['date_to'] ?? null" />
                <x-filter.select name="depot_id" label="Depot" :options="$filterOptions['depots']" :selected="$filters['depot_id'] ?? null" />
                <x-filter.select name="fuel_type_id" label="Fuel Type" :options="$filterOptions['fuelTypes']" :selected="$filters['fuel_type_id'] ?? null" />
            </x-filter.bar>
            <div class="table-wrap">
                <table class="admin-table ledger-table" data-card-keys="0,1,5">
                    <thead><tr><th>Purchase ID</th><th>Fuel Type</th><th>Depot</th><th>Purchased Quantity</th><th>Total Lifted</th><th>Remaining Quantity</th><th>Status</th>
                        {{-- Part 20 #5: kept for Export only (each lift with its truck, driver and receipt status). --}}
                        <th class="export-only" hidden>Lifts (Truck, Driver, Receipt)</th><th class="export-only" hidden>Withdrawal Receipts</th><th>Actions</th></tr></thead>
                    <tbody>
                        @forelse ($transactions as $row)
                            <tr>
                                @foreach ($row['cells'] as $cell)
                                    @if ($loop->last)
                                        <td><x-admin.status-badge :status="$cell" /></td>
                                    @else
                                        <td>{{ $cell }}</td>
                                    @endif
                                @endforeach
                                <td class="export-only" hidden>{{ $row['lift_export'] }}</td>
                                <td class="export-only" hidden>{{ $row['receipt_count'] }} of {{ count($row['lifts']) }} uploaded</td>
                                <td><button class="btn btn-secondary" type="button" data-modal-open="{{ $row['id'] }}">View</button></td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="10">No inventory movements found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    @foreach ($transactions as $row)
        <x-admin.modal id="{{ $row['id'] }}" title="View Transactions" :show-brand="false">
            <div class="ledger-transaction-header">
                <p class="detail-id">Purchase ID: {{ $row['purchase_code'] }}</p>
                <x-admin.status-badge :status="$row['status']" />
            </div>
            <div class="ledger-fuel-summary" aria-label="Purchase lifting totals">
                <div><span>Purchased</span><strong>{{ $row['purchased_liters'] }}</strong></div>
                <div><span>Lifted</span><strong>{{ $row['lifted_liters'] }}</strong></div>
                <div><span>Remaining Fuel</span><strong>{{ $row['remaining_liters'] }}</strong></div>
            </div>
            <div class="ledger-purchase-details">
                <div class="detail-row"><div class="detail-label">Driver Name</div><div class="detail-value">{{ $row['driver_names'] }}</div></div>
                <div class="detail-row"><div class="detail-label">Assigned Truck / Truck Number</div><div class="detail-value">{{ $row['truck_names'] }}</div></div>
                <div class="detail-row"><div class="detail-label">Inventory Status</div><div class="detail-value"><x-admin.status-badge :status="$row['inventory_status']" /></div></div>
            </div>
            <section class="ledger-lift-history" aria-labelledby="{{ $row['id'] }}-lift-history-title">
                <h3 id="{{ $row['id'] }}-lift-history-title">Lift History</h3>
                <div class="ledger-lift-cards" aria-label="Lift transactions for {{ $row['purchase_code'] }}">
                    @forelse ($row['lifts'] as $lift)
                        <article class="ledger-lift-card" tabindex="0" aria-label="Lift {{ $lift['sequence'] }}: {{ $lift['display_quantity'] }}. Lift ID: {{ $lift['code'] }}. Date Lifted: {{ $lift['display_date'] }}.">
                            <div class="ledger-lift-card-header">
                                <div>
                                    <span class="ledger-card-label">Lift ID</span>
                                    <strong>{{ $lift['details']['Lift ID'] ?: 'N/A' }}</strong>
                                </div>
                                <div>
                                    <span class="ledger-card-label">Date Lifted</span>
                                    <strong>{{ $lift['display_date'] ?: 'N/A' }}</strong>
                                </div>
                                <x-admin.status-badge :status="$lift['status'] ?: 'N/A'" />
                            </div>
                            <div class="ledger-lift-quantity">
                                <span>Quantity Lifted (L)</span>
                                <strong>{{ $lift['display_quantity'] ?: 'N/A' }}</strong>
                            </div>
                            <div class="ledger-lift-crew">
                                <div><span class="ledger-card-label">Truck</span><strong>{{ $lift['details']['Truck'] ?: 'Unassigned' }}</strong></div>
                                <div><span class="ledger-card-label">Driver</span><strong>{{ $lift['details']['Driver'] ?: 'Unassigned' }}</strong></div>
                            </div>
                            {{-- Part 20 #1: this lift's own withdrawal receipt. --}}
                            <div @class(['ledger-lift-receipt', 'is-uploaded' => $lift['receipt']])>
                                <span class="ledger-card-label">Withdrawal Receipt</span>
                                @if ($lift['receipt'])
                                    @if ($canViewReceipts)
                                        <a class="withdrawal-thumb ledger-receipt-thumb" href="{{ $lift['receipt']['url'] }}" target="_blank" rel="noopener">
                                            <img src="{{ $lift['receipt']['url'] }}" alt="Withdrawal receipt for {{ $lift['code'] }}" loading="lazy">
                                            <span>View receipt</span>
                                        </a>
                                    @endif
                                    <strong>Uploaded {{ $lift['receipt']['uploaded_at'] }}@if ($lift['receipt']['uploaded_by']) by {{ $lift['receipt']['uploaded_by'] }}@endif</strong>
                                    @if ($lift['receipt']['notes'])<small>Notes: {{ $lift['receipt']['notes'] }}</small>@endif
                                @else
                                    <strong>Not uploaded</strong>
                                @endif
                            </div>
                        </article>
                    @empty
                        <div class="ledger-no-lifts">No lifts recorded</div>
                    @endforelse
                </div>
            </section>
        </x-admin.modal>
    @endforeach
@endcomponent
