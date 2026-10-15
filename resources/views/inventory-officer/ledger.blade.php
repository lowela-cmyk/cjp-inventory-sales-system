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
@endphp

@component($ledgerLayout, ['title' => 'Inventory Ledger', 'active' => 'ledger'])
    <div data-tabs>
        <h2 class="section-title">Ledger Tab</h2>
        <div class="tabs">
            <button class="tab-button {{ $activeTab === 'ledger' ? 'is-active' : '' }}" type="button" data-tab-target="ledger" data-tab-url="{{ route($ledgerRoutePrefix) }}">Ledger</button>
            <button class="tab-button {{ $activeTab === 'transactions' ? 'is-active' : '' }}" type="button" data-tab-target="transactions" data-tab-url="{{ route($ledgerRoutePrefix.'.transactions') }}">Transaction</button>
        </div>
        <div class="actions-right"><button class="btn btn-secondary" type="button" data-export-table>Export</button></div>

        <section data-tab-panel="ledger" {{ $activeTab !== 'ledger' ? 'hidden' : '' }}>
            <x-filter.bar class="toolbar-ledger" :action="route($ledgerRoutePrefix)">
                <x-filter.search label="Search ledger" :value="$search" />
                <x-filter.date name="date" label="Purchase date" :value="$filters['date'] ?? null" />
                <x-filter.select name="depot_id" label="Depot" :options="$filterOptions['depots']" :selected="$filters['depot_id'] ?? null" />
                <x-filter.select name="fuel_type_id" label="Fuel Type" :options="$filterOptions['fuelTypes']" :selected="$filters['fuel_type_id'] ?? null" />
            </x-filter.bar>
            <div class="table-wrap">
                <table class="admin-table ledger-table">
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
                <x-filter.date name="date" label="Purchase date" :value="$filters['date'] ?? null" />
                <x-filter.select name="depot_id" label="Depot" :options="$filterOptions['depots']" :selected="$filters['depot_id'] ?? null" />
                <x-filter.select name="fuel_type_id" label="Fuel Type" :options="$filterOptions['fuelTypes']" :selected="$filters['fuel_type_id'] ?? null" />
            </x-filter.bar>
            <div class="table-wrap">
                <table class="admin-table ledger-table">
                    <thead><tr><th>Purchase ID</th><th>Fuel Type</th><th>Depot</th><th>Purchased Quantity</th><th>Total Lifted</th><th>Remaining Quantity</th><th>Status</th><th>Actions</th></tr></thead>
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
                                <td><button class="btn btn-secondary" type="button" data-modal-open="{{ $row['id'] }}">View</button></td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="8">No inventory movements found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    @foreach ($transactions as $row)
        <x-admin.modal id="{{ $row['id'] }}" title="VIEW TRANSACTIONS" :show-brand="false">
            <div class="modal-heading-row">
                <p class="detail-id">Purchase ID: {{ $row['purchase_code'] }}</p>
                <x-admin.status-badge :status="$row['status']" />
            </div>
            <div class="lift-summary" aria-label="Purchase lifting totals">
                <span>Purchased: <strong>{{ $row['purchased_liters'] }}</strong></span>
                <span>Lifted: <strong>{{ $row['lifted_liters'] }}</strong></span>
                <span>Remaining / Unlifted: <strong>{{ $row['remaining_liters'] }}</strong></span>
            </div>
            <div class="lift-transaction-modal">
                <div class="lift-blocks" aria-label="Lift transactions for {{ $row['purchase_code'] }}">
                    @forelse ($row['lifts'] as $lift)
                        <div class="lift-block {{ $lift['counts_as_lifted'] ? 'is-complete' : 'is-open' }}" tabindex="0" aria-label="Lift {{ $lift['sequence'] }}: {{ $lift['display_quantity'] }}. Lift ID: {{ $lift['code'] }}. Date Lifted: {{ $lift['display_date'] }}.">
                            <span class="visually-hidden">Lift {{ $lift['sequence'] }}</span>
                            <div class="lift-block-meta" aria-label="Lift ID: {{ $lift['details']['Lift ID'] }}">
                                <span>Lift-ID: {{ $lift['details']['Lift ID'] }}</span>
                                <span>Date Lifted: {{ $lift['display_date'] }}</span>
                            </div>
                            <strong class="lift-block-quantity">{{ $lift['display_quantity'] }}</strong>
                            <span class="visually-hidden">Driver: {{ $lift['details']['Driver'] }}. Truck: {{ $lift['details']['Truck'] }}. Status: {{ $lift['status'] }}.</span>
                        </div>
                    @empty
                        <div class="lift-empty">No lift assignments have been created for this purchase yet.</div>
                    @endforelse
                </div>
            </div>
        </x-admin.modal>
    @endforeach
@endcomponent
