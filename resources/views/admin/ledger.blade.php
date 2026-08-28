@php
    $ledger = [
        ['PUR-000002', '8/26/2026', 'Unleaded', 'Shell A', '80,000.00 L', '0', '0', '80,000.00', 'Unlifted'],
        ['PUR-000003', '8/26/2026', 'Diesel', 'Phoenix A', '90,000.00 L', '0', '0', '90,000.00', 'Unlifted'],
        ['PUR-000004', '8/27/2026', 'Diesel', 'Petron A', '100,000.00 L', '40,000.00 L', '20,000.00 L', '40,000.00', 'Partial'],
    ];
    $transactions = [
        ['PUR-000001', 'Petron A', 'Premium', '100,000.00 L', '100,000.00 L', '0', 'Complete', 'ledger-complete'],
        ['PUR-000004', 'Petron A', 'Diesel', '100,000.00 L', '80,000.00 L', '20,000.00 L', 'Incomplete', 'ledger-incomplete'],
    ];
@endphp

@component('layouts.admin', ['title' => 'Inventory Ledger', 'active' => 'ledger'])
    <div data-tabs>
        <h2 class="section-title">Ledger Tab</h2>
        <div class="tabs">
            <button class="tab-button is-active" type="button" data-tab-target="ledger">Ledger</button>
            <button class="tab-button" type="button" data-tab-target="transaction">Transaction</button>
            <button class="tab-button" type="button" data-tab-target="empty">No Data</button>
        </div>

        <section data-tab-panel="ledger">
            <div class="toolbar toolbar-ledger">
                <input type="search" placeholder="Search..." aria-label="Search ledger">
                <button class="btn btn-primary" type="button">Date</button>
                <button class="btn btn-primary" type="button">Depot</button>
                <button class="btn btn-primary" type="button">Fuel Type (All)</button>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Purchase-ID</th><th>Order Date</th><th>Fuel</th><th>Depot</th><th>QTY Ordered (L)</th><th>QTY Lifted (L)</th><th>Current QTY</th><th>Balance</th><th>Status</th></tr></thead>
                    <tbody>
                        @foreach ($ledger as $row)
                            <tr><td>{{ $row[0] }}</td><td>{{ $row[1] }}</td><td>{{ $row[2] }}</td><td>{{ $row[3] }}</td><td>{{ $row[4] }}</td><td>{{ $row[5] }}</td><td>{{ $row[6] }}</td><td>{{ $row[7] }}</td><td><x-admin.status-badge :status="$row[8]" /></td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section data-tab-panel="transaction" hidden>
            <div class="toolbar toolbar-transaction">
                <input type="search" placeholder="Search..." aria-label="Search transactions">
                <button class="btn btn-primary" type="button">Status</button>
                <button class="btn btn-primary" type="button">Date</button>
                <button class="btn btn-primary" type="button">Depot</button>
                <button class="btn btn-primary" type="button">Fuel Type (All)</button>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Purchase-ID</th><th>Depot</th><th>Fuel</th><th>QTY Ordered</th><th>QTY Lifted</th><th>Remaining</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        @foreach ($transactions as $row)
                            <tr><td>{{ $row[0] }}</td><td>{{ $row[1] }}</td><td>{{ $row[2] }}</td><td>{{ $row[3] }}</td><td>{{ $row[4] }}</td><td>{{ $row[5] }}</td><td><x-admin.status-badge :status="$row[6]" /></td><td><button class="btn btn-secondary" type="button" data-modal-open="{{ $row[7] }}">View</button></td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section data-tab-panel="empty" hidden>
            <div class="toolbar toolbar-ledger">
                <input type="search" placeholder="Search..." aria-label="Search empty ledger">
                <button class="btn btn-primary" type="button">Date</button>
                <button class="btn btn-primary" type="button">Depot</button>
                <button class="btn btn-primary" type="button">Fuel Type (All)</button>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Purchase-ID</th><th>Order Date</th><th>Fuel</th><th>Depot</th><th>QTY Ordered (L)</th><th>QTY Lifted (L)</th><th>Current QTY</th><th>Balance</th><th>Status</th></tr></thead>
                    <tbody><tr><td class="empty-cell" colspan="9">No transaction records found</td></tr></tbody>
                </table>
            </div>
        </section>
    </div>

    <x-admin.modal id="ledger-complete" title="View Transactions" wide>
        <div class="modal-heading-row">
            <p class="detail-id">PUR-000001</p>
            <p class="detail-id modal-complete">Complete</p>
        </div>
        <div class="transaction-strip">
            <div class="transaction-slice" style="--slice:#b42b2e" data-tip="LFT-000001 / DR 0000053 / 8-22-2026">40,000 L</div>
            <div class="transaction-slice" style="--slice:#922124" data-tip="LFT-000002 / DR 0000054 / 8-23-2026">40,000 L</div>
            <div class="transaction-slice" style="--slice:#751d1f" data-tip="LFT-000003 / DR 0000055 / 8-24-2026">20,000 L</div>
        </div>
    </x-admin.modal>

    <x-admin.modal id="ledger-incomplete" title="View Transactions" wide>
        <div class="modal-heading-row">
            <p class="detail-id">PUR-000004</p>
            <p class="detail-id modal-incomplete">Incomplete</p>
        </div>
        <div class="transaction-incomplete">
            <div>40,000 L</div>
            <div>40,000 L</div>
            <div>20,000 L</div>
        </div>
    </x-admin.modal>
@endcomponent
