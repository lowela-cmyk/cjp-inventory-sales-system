@php
    $activeTab = $activeTab ?? (($state ?? 'ledger') === 'transactions' ? 'transactions' : 'ledger');
    $ledgerLayout = request()->routeIs('dispatch.ledger*') ? 'layouts.dispatch' : 'layouts.inventory-officer';
    $ledgerRoutePrefix = request()->routeIs('dispatch.ledger*') ? 'dispatch.ledger' : 'inventory-officer.ledger';
@endphp

@component($ledgerLayout, ['title' => 'Inventory Ledger', 'active' => 'ledger'])
    <div data-tabs>
        <h2 class="section-title">Ledger Tab</h2>
        <div class="tabs">
            <button class="tab-button {{ $activeTab === 'ledger' ? 'is-active' : '' }}" type="button" data-tab-target="ledger">Ledger</button>
            <button class="tab-button {{ $activeTab === 'transactions' ? 'is-active' : '' }}" type="button" data-tab-target="transactions">Transaction</button>
        </div>
        <div class="actions-right"><button class="btn btn-secondary" type="button" data-export-table>Export</button></div>

        <section data-tab-panel="ledger" {{ $activeTab !== 'ledger' ? 'hidden' : '' }}>
            <form class="dispatch-filter-row" method="GET" action="{{ route($ledgerRoutePrefix) }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search ledger" value="{{ $search }}">
                <button class="btn btn-primary" type="submit">Date</button>
                <button class="btn btn-primary" type="submit">Depot</button>
                <button class="btn btn-primary" type="submit">Fuel Type (All)</button>
            </form>
            <div class="table-wrap dispatch-table-wrap">
                <table class="admin-table dispatch-table">
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
            <form class="dispatch-filter-row" method="GET" action="{{ route($ledgerRoutePrefix.'.transactions') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search ledger transactions" value="{{ $search }}">
                <button class="btn btn-primary" type="submit">Date</button>
                <button class="btn btn-primary" type="submit">Depot</button>
                <button class="btn btn-primary" type="submit">Fuel Type (All)</button>
            </form>
            <div class="table-wrap dispatch-table-wrap">
                <table class="admin-table dispatch-table">
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
        <x-admin.modal id="{{ $row['id'] }}" title="VIEW TRANSACTIONS" wide>
            <div class="modal-heading-row">
                <p class="detail-id">{{ $row['purchase_code'] }}</p>
                <p class="detail-id {{ $row['status_class'] }}">{{ $row['status'] }}</p>
            </div>
            <div class="modal-card lift-transaction-modal">
                <div class="lift-summary-grid">
                    @foreach ($row['details'] as $label => $value)
                        <div><span>{{ $label }}</span><strong>{{ $value }}</strong></div>
                    @endforeach
                </div>
                <div class="lift-blocks" aria-label="Lift transactions for {{ $row['purchase_code'] }}">
                    @forelse ($row['lifts'] as $lift)
                        <div class="lift-block {{ $lift['counts_as_lifted'] ? 'is-complete' : 'is-open' }}" tabindex="0">
                            <span>Lift {{ $lift['sequence'] }}</span>
                            <strong>{{ $lift['quantity'] }}</strong>
                            <em>{{ $lift['status'] }}</em>
                            <div class="lift-tooltip" role="tooltip">
                                @foreach ($lift['details'] as $label => $value)
                                    <div><span>{{ $label }}</span><strong>{{ $value }}</strong></div>
                                @endforeach
                            </div>
                        </div>
                    @empty
                        <div class="lift-empty">No lift assignments have been created for this purchase yet.</div>
                    @endforelse
                </div>
            </div>
        </x-admin.modal>
    @endforeach
@endcomponent
