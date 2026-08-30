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
                    <thead><tr><th>Purchase-ID</th><th>Order Date</th><th>Fuel</th><th>Depot</th><th>QTY Ordered (L)</th><th>QTY Lifted (L)</th><th>Current QTY</th><th>Balance</th><th>Status</th></tr></thead>
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
                            <tr><td class="empty-cell" colspan="9">No records found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section data-tab-panel="transaction" hidden>
            <form class="toolbar toolbar-transaction" method="GET" action="{{ route('admin.ledger') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search transactions" value="{{ $search }}">
                <button class="btn btn-primary" type="submit">Status</button>
                <button class="btn btn-primary" type="submit">Date</button>
                <button class="btn btn-primary" type="submit">Depot</button>
                <button class="btn btn-primary" type="submit">Fuel Type (All)</button>
            </form>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Purchase-ID</th><th>Depot</th><th>Fuel</th><th>QTY Ordered</th><th>QTY Lifted</th><th>Remaining</th><th>Status</th><th>Actions</th></tr></thead>
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
                            <tr><td class="empty-cell" colspan="8">No records found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section data-tab-panel="empty" hidden>
            <form class="toolbar toolbar-ledger" method="GET" action="{{ route('admin.ledger') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search empty ledger" value="{{ $search }}">
                <button class="btn btn-primary" type="submit">Date</button>
                <button class="btn btn-primary" type="submit">Depot</button>
                <button class="btn btn-primary" type="submit">Fuel Type (All)</button>
            </form>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Purchase-ID</th><th>Order Date</th><th>Fuel</th><th>Depot</th><th>QTY Ordered (L)</th><th>QTY Lifted (L)</th><th>Current QTY</th><th>Balance</th><th>Status</th></tr></thead>
                    <tbody><tr><td class="empty-cell" colspan="9">No transaction records found</td></tr></tbody>
                </table>
            </div>
        </section>
    </div>

    @foreach ($transactions as $row)
        <x-admin.modal id="{{ $row['id'] }}" title="View Transactions" wide>
            <div class="modal-heading-row">
                <p class="detail-id">{{ $row['cells'][0] }}</p>
                <p class="detail-id {{ $row['status'] === 'Lifted' ? 'modal-complete' : 'modal-incomplete' }}">{{ $row['status'] }}</p>
            </div>
            @if ($row['hauls'])
                <div class="transaction-incomplete">
                    @foreach ($row['hauls'] as $haul)
                        <div title="{{ $haul['label'] }}">{{ $haul['quantity'] }} - {{ $haul['status'] }}</div>
                    @endforeach
                </div>
            @else
                <div class="table-wrap"><table class="admin-table"><tbody><tr><td class="empty-cell">No haul records found.</td></tr></tbody></table></div>
            @endif
        </x-admin.modal>
    @endforeach
@endcomponent
