@php
    $activeTab = $activeTab ?? (($state ?? 'ledger') === 'transactions' ? 'transactions' : 'ledger');
@endphp

@component('layouts.inventory-officer', ['title' => 'Inventory Ledger', 'active' => 'ledger'])
    <div data-tabs>
        <h2 class="section-title">Ledger Tab</h2>
        <div class="tabs">
            <button class="tab-button {{ $activeTab === 'ledger' ? 'is-active' : '' }}" type="button" data-tab-target="ledger">Ledger</button>
            <button class="tab-button {{ $activeTab === 'transactions' ? 'is-active' : '' }}" type="button" data-tab-target="transactions">Transaction</button>
        </div>
        <div class="actions-right"><button class="btn btn-secondary" type="button" data-export-table>Export</button></div>

        <section data-tab-panel="ledger" @hidden($activeTab !== 'ledger')>
            <form class="dispatch-filter-row" method="GET" action="{{ route('inventory-officer.ledger') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search ledger" value="{{ $search }}">
                <button class="btn btn-primary" type="submit">Date</button>
                <button class="btn btn-primary" type="submit">Depot</button>
                <button class="btn btn-primary" type="submit">Fuel Type (All)</button>
            </form>
            <div class="table-wrap dispatch-table-wrap">
                <table class="admin-table dispatch-table">
                    <thead><tr><th>Reference</th><th>Date</th><th>Fuel</th><th>Garage</th><th>Stock In</th><th>Stock Out</th><th>Quantity</th><th>Balance</th><th>Status</th></tr></thead>
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
                            <tr><td class="empty-cell" colspan="9">No inventory movements found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section data-tab-panel="transactions" @hidden($activeTab !== 'transactions')>
            <form class="dispatch-filter-row" method="GET" action="{{ route('inventory-officer.ledger.transactions') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search ledger transactions" value="{{ $search }}">
                <button class="btn btn-primary" type="submit">Date</button>
                <button class="btn btn-primary" type="submit">Depot</button>
                <button class="btn btn-primary" type="submit">Fuel Type (All)</button>
            </form>
            <div class="table-wrap dispatch-table-wrap">
                <table class="admin-table dispatch-table">
                    <thead><tr><th>Reference</th><th>Date</th><th>Fuel</th><th>Garage</th><th>Stock In</th><th>Stock Out</th><th>Actions</th></tr></thead>
                    <tbody>
                        @forelse ($transactions as $row)
                            <tr>
                                @foreach (array_slice($row['cells'], 0, 6) as $cell)
                                    <td>{{ $cell }}</td>
                                @endforeach
                                <td><button class="btn btn-secondary" type="button" data-modal-open="{{ $row['id'] }}">View</button></td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="7">No inventory movements found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    @foreach ($transactions as $row)
        <x-admin.modal id="{{ $row['id'] }}" title="View Transactions" wide>
            <div class="modal-heading-row">
                <p class="detail-id">{{ $row['details']['Movement ID'] }}</p>
                <p class="detail-id {{ $row['status'] === 'Stock In' || $row['status'] === 'Beginning' ? 'modal-complete' : 'modal-incomplete' }}">{{ $row['status'] }}</p>
            </div>
            <div class="modal-card">
                <div class="detail-grid">
                    @foreach ($row['details'] as $label => $value)
                        <div class="detail-row">
                            <div class="detail-label">{{ $label }}</div>
                            <div class="detail-value">{{ $value }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </x-admin.modal>
    @endforeach
@endcomponent
