@php
    $ledger = [
        ['PUR-000001', '8/20/2026', 'Premium', 'Petron A', '100,000.00', '60,000.00', '40,000.00', '40%', 'Available', ''],
        ['PUR-000002', '8/26/2026', 'Unleaded', 'Shell A', '80,000.00', '80,000.00', '0.00', '0%', 'Depleted', 'row-danger'],
        ['PUR-000003', '8/26/2026', 'Diesel', 'Phoenix A', '90,000.00', '78,000.00', '12,000.00', '13%', 'Low Stock', 'row-warning'],
    ];
@endphp

@component('layouts.admin', ['title' => 'Inventory Ledger', 'active' => 'ledger'])
    <h2 class="section-title">Ledger Tab</h2>
    <div class="filter-row">
        <select aria-label="Fuel filter"><option>Fuel Type (All)</option><option>Premium</option><option>Diesel</option><option>Unleaded</option></select>
        <input type="date" aria-label="Date filter">
        <input type="search" placeholder="Search..." aria-label="Search ledger">
        <button class="btn btn-primary" type="button">Reset</button>
        <button class="btn btn-secondary" type="button">Export</button>
    </div>
    <div class="table-wrap">
        <table class="admin-table">
            <thead><tr><th>Purchase ID</th><th>Order Date</th><th>Fuel</th><th>Depot</th><th>Quantity Ordered</th><th>Quantity Used</th><th>Current Quantity</th><th>Balance</th><th>Status</th></tr></thead>
            <tbody>
                @foreach ($ledger as $row)
                    <tr class="{{ $row[9] }}"><td>{{ $row[0] }}</td><td>{{ $row[1] }}</td><td>{{ $row[2] }}</td><td>{{ $row[3] }}</td><td>{{ $row[4] }}</td><td>{{ $row[5] }}</td><td>{{ $row[6] }}</td><td>{{ $row[7] }}</td><td><x-admin.status-badge :status="$row[8]" /></td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endcomponent
