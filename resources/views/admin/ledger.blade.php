@component('layouts.admin', ['title' => 'Inventory Ledger', 'active' => 'ledger'])
    <div data-tabs>
        <h2 class="section-title">Ledger Tab</h2>
        <div class="tabs">
            <button class="tab-button is-active" type="button" data-tab-target="ledger">Ledger</button>
            <button class="tab-button" type="button" data-tab-target="transaction">Transaction</button>
            <button class="tab-button" type="button" data-tab-target="empty">No Data</button>
        </div>

        <section data-tab-panel="ledger">
            <form class="toolbar toolbar-ledger" method="GET" action="{{ route('admin.ledger') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search ledger" value="{{ $search }}">
                <button class="btn btn-primary" type="submit">Date</button>
                <button class="btn btn-primary" type="submit">Depot</button>
                <button class="btn btn-primary" type="submit">Fuel Type (All)</button>
            </form>
            <div class="table-wrap">
                <table class="admin-table">
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

        <section data-tab-panel="transaction" hidden>
            <form class="toolbar toolbar-transaction" method="GET" action="{{ route('admin.ledger') }}">
                <input type="search" name="search" placeholder="Search transactions" aria-label="Search transactions" value="{{ $search }}">
                <button class="btn btn-primary" type="submit">Status</button>
                <button class="btn btn-primary" type="submit">Date</button>
                <button class="btn btn-primary" type="submit">Depot</button>
                <button class="btn btn-primary" type="submit">Fuel Type (All)</button>
            </form>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Reference</th><th>Date</th><th>Fuel</th><th>Garage</th><th>Stock In</th><th>Stock Out</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        @forelse ($transactions as $row)
                            <tr>
                                @foreach (array_slice($row['cells'], 0, 6) as $cell)
                                    <td>{{ $cell }}</td>
                                @endforeach
                                <td><x-admin.status-badge :status="$row['status']" /></td>
                                <td><button class="btn btn-secondary" type="button" data-modal-open="{{ $row['id'] }}">View</button></td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="8">No inventory movements found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section data-tab-panel="empty" hidden>
            <form class="toolbar toolbar-ledger" method="GET" action="{{ route('admin.ledger') }}">
                <input type="search" name="search" placeholder="Search empty ledger" aria-label="Search empty ledger" value="{{ $search }}">
                <button class="btn btn-primary" type="submit">Date</button>
                <button class="btn btn-primary" type="submit">Depot</button>
                <button class="btn btn-primary" type="submit">Fuel Type (All)</button>
            </form>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Reference</th><th>Date</th><th>Fuel</th><th>Garage</th><th>Stock In</th><th>Stock Out</th><th>Quantity</th><th>Balance</th><th>Status</th></tr></thead>
                    <tbody><tr><td class="empty-cell" colspan="9">No inventory movements found.</td></tr></tbody>
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
