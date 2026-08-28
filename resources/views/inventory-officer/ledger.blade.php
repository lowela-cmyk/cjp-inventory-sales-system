@php
    $ledger = [
        ['PUR-000001', '8/20/2026', 'Premium', 'Petron A', '100,000.00', '40,000.00', '40,000.00', '20,000.00', 'Partial'],
        ['PUR-000002', '8/26/2026', 'Unleaded', 'Shell A', '80,000.00', '0', '0', '80,000.00', 'Unlifted'],
        ['PUR-000003', '8/26/2026', 'Diesel', 'Phoenix A', '90,000.00', '0', '0', '90,000.00', 'Unlifted'],
    ];
    $transactions = [
        ['PUR-000001', '8/20/2026', 'Premium', 'Petron A', '100,000.00 L', 'LFT-000001, LFT-000002, LFT-000003'],
        ['PUR-000002', '8/26/2026', 'Unleaded', 'Shell A', '80,000.00 L', '-'],
        ['PUR-000001', '8/26/2026', 'Diesel', 'Phoenix A', '90,000.00 L', '-'],
        ['PUR-000004', '8/27/2026', 'Premium', 'Petron A', '100,000.00 L', 'LFT-000003'],
    ];
    $activeTab = ($state ?? 'ledger') === 'transactions' ? 'transactions' : 'ledger';
@endphp

@component('layouts.inventory-officer', ['title' => 'Inventory Ledger', 'active' => 'ledger'])
    <div data-tabs>
        <h2 class="section-title">Ledger Tab</h2>
        <div class="tabs">
            <button class="tab-button {{ $activeTab === 'ledger' ? 'is-active' : '' }}" type="button" data-tab-target="ledger">Ledger</button>
            <button class="tab-button {{ $activeTab === 'transactions' ? 'is-active' : '' }}" type="button" data-tab-target="transactions">Transaction</button>
        </div>
        <div class="actions-right"><button class="btn btn-secondary" type="button">Export</button></div>

        <section data-tab-panel="ledger" @hidden($activeTab !== 'ledger')>
            <div class="dispatch-filter-row">
                <input type="search" placeholder="Search..." aria-label="Search ledger">
                <button class="btn btn-primary" type="button">Date</button>
                <button class="btn btn-primary" type="button">Depot</button>
                <button class="btn btn-primary" type="button">Fuel Type (All)</button>
            </div>
            <div class="table-wrap dispatch-table-wrap">
                <table class="admin-table dispatch-table">
                    <thead><tr><th>Purchase-ID</th><th>Order Date</th><th>Fuel</th><th>Depot</th><th>QTY Ordered (L)</th><th>QTY Lifted (L)</th><th>Current QTY</th><th>Balance</th><th>Status</th></tr></thead>
                    <tbody>
                        @foreach ($ledger as $row)
                            <tr><td>{{ $row[0] }}</td><td>{{ $row[1] }}</td><td>{{ $row[2] }}</td><td>{{ $row[3] }}</td><td>{{ $row[4] }}</td><td>{{ $row[5] }}</td><td>{{ $row[6] }}</td><td>{{ $row[7] }}</td><td><x-admin.status-badge :status="$row[8]" /></td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section data-tab-panel="transactions" @hidden($activeTab !== 'transactions')>
            <div class="dispatch-filter-row">
                <input type="search" placeholder="Search..." aria-label="Search ledger transactions">
                <button class="btn btn-primary" type="button">Date</button>
                <button class="btn btn-primary" type="button">Depot</button>
                <button class="btn btn-primary" type="button">Fuel Type (All)</button>
            </div>
            <div class="table-wrap dispatch-table-wrap">
                <table class="admin-table dispatch-table">
                    <thead><tr><th>Purchase-ID</th><th>Order Date</th><th>Fuel</th><th>Depot</th><th>QTY Ordered (L)</th><th>Transactions (Lift -D)</th><th>Actions</th></tr></thead>
                    <tbody>
                        @foreach ($transactions as $row)
                            <tr><td>{{ $row[0] }}</td><td>{{ $row[1] }}</td><td>{{ $row[2] }}</td><td>{{ $row[3] }}</td><td>{{ $row[4] }}</td><td>{{ $row[5] }}</td><td><button class="btn btn-secondary" type="button" data-modal-open="io-ledger-view">View</button></td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <x-admin.modal id="io-ledger-view" title="View Transactions" wide>
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
@endcomponent
