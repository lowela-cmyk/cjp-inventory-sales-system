@php
    $ledgerRows = [
        ['PUR-000001', '8/20/2026', 'Premium', 'Petron A', '100,000.00', '40,000.00', '40,000.00', '20,000.00', 'Partial'],
        ['PUR-000002', '8/26/2026', 'Unleaded', 'Shell A', '80,000.00', '0', '0', '80,000.00', 'Unlifted'],
        ['PUR-000003', '8/26/2026', 'Diesel', 'Phoenix A', '90,000.00', '0', '0', '90,000.00', 'Unlifted'],
    ];
@endphp

@component('layouts.dispatch', ['title' => 'Inventory Ledger', 'active' => 'ledger'])
    <h2 class="section-title">Ledger Tab</h2>
    <div class="actions-right"><button class="btn btn-secondary" type="button" data-export-table>Export</button></div>

    <div class="dispatch-filter-row">
        <input type="search" placeholder="Search..." aria-label="Search ledger records">
        <button class="btn btn-primary" type="button" data-sort-table="1">Date</button>
        <button class="btn btn-primary" type="button" data-sort-table="3">Depot</button>
        <button class="btn btn-primary" type="button" data-sort-table="2">Fuel Type (All)</button>
    </div>

    <div class="table-wrap dispatch-table-wrap">
        <table class="admin-table dispatch-table">
            <thead>
                <tr>
                    <th>Purchase-ID</th>
                    <th>Order Date</th>
                    <th>Fuel</th>
                    <th>Depot</th>
                    <th>QTY Ordered (L)</th>
                    <th>QTY Lifted (L)</th>
                    <th>Current QTY</th>
                    <th>Balance</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($ledgerRows as $row)
                    <tr>
                        <td>{{ $row[0] }}</td>
                        <td>{{ $row[1] }}</td>
                        <td>{{ $row[2] }}</td>
                        <td>{{ $row[3] }}</td>
                        <td>{{ $row[4] }}</td>
                        <td>{{ $row[5] }}</td>
                        <td>{{ $row[6] }}</td>
                        <td>{{ $row[7] }}</td>
                        <td><x-admin.status-badge :status="$row[8]" /></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endcomponent
