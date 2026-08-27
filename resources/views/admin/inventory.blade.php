@php
    $purchases = [
        ['PUR-000001', '8/20/2026', 'Premium', 'Petron A', '100,000.00', '80.00', '8,000,000.00', 'img.png', 'Paid', ''],
        ['PUR-000002', '8/26/2026', 'Unleaded', 'Shell A', '80,000.00', '70.00', '5,600,000.00', 'img.png', 'Unpaid', 'row-danger'],
        ['PUR-000003', '8/26/2026', 'Diesel', 'Phoenix A', '90,000.00', '85.00', '7,650,000.00', 'img.png', 'Partial', 'row-warning'],
    ];
    $stockIn = [
        ['PUR-000001', '8/20/2026', 'Premium', 'Petron A', '100,000.00', '80.00', '8,000,000.00', '40,000.00', 'Available', ''],
        ['PUR-000002', '8/26/2026', 'Unleaded', 'Shell A', '80,000.00', '70.00', '5,600,000.00', '0.00', 'Depleted', 'row-danger'],
        ['PUR-000003', '8/26/2026', 'Diesel', 'Phoenix A', '90,000.00', '85.00', '7,650,000.00', '12,000.00', 'Low Stock', 'row-warning'],
    ];
    $stockOut = [
        ['SLS-000001', '8/22/2026', 'Ken C. Binhi', 'Binhi Green Homes', 'Premium', '10,000.00', '90.00', '900,000.00', '900,000.00', '40,000.00', 'Available', ''],
        ['SLS-000002', '8/22/2026', 'Jay P. Calinisan', 'Jay P Constructions', 'Premium', '20,000.00', '90.00', '1,800,000.00', '800,000.00', '20,000.00', 'Low Stock', 'row-warning'],
        ['SLS-000003', '8/22/2026', 'Yuri Q. Mabini', 'Gold Steel Productions', 'Premium', '10,000.00', '90.00', '900,000.00', '0', '0.00', 'Critical', 'row-danger'],
    ];
@endphp

@component('layouts.admin', ['title' => 'Inventory Management', 'active' => 'inventory'])
    <div data-tabs>
        <h2 class="section-title">Purchases</h2>
        <div class="tabs">
            <button class="tab-button is-active" type="button" data-tab-target="purchases">Purchases</button>
            <button class="tab-button" type="button" data-tab-target="stock-in">Stock-In</button>
            <button class="tab-button" type="button" data-tab-target="stock-out">Stock Out</button>
        </div>
        <div class="actions-right">
            <button class="btn btn-secondary" type="button">Export</button>
        </div>

        <section data-tab-panel="purchases">
            <div class="toolbar">
                <input type="search" placeholder="Search..." aria-label="Search purchases">
                <button class="btn btn-primary" type="button">Status</button>
                <button class="btn btn-primary" type="button">Date</button>
                <button class="btn btn-primary" type="button">Depot</button>
                <button class="btn btn-primary" type="button">Fuel Type (All)</button>
                <button class="btn btn-primary" type="button">+ Record Purchases</button>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Purchase-ID</th><th>Date</th><th>Fuel</th><th>Depot</th><th>QTY (L)</th><th>Cost / Liter</th><th>Total Cost</th><th>Delivery Receipt</th><th>Payment Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        @foreach ($purchases as $row)
                            <tr class="{{ $row[9] }}"><td>{{ $row[0] }}</td><td>{{ $row[1] }}</td><td>{{ $row[2] }}</td><td>{{ $row[3] }}</td><td>{{ $row[4] }}</td><td>{{ $row[5] }}</td><td>{{ $row[6] }}</td><td>{{ $row[7] }}</td><td><x-admin.status-badge :status="$row[8]" /></td><td><button class="btn btn-secondary" type="button">Edit</button></td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section data-tab-panel="stock-in" hidden>
            <div class="toolbar toolbar-narrow">
                <input type="search" placeholder="Search..." aria-label="Search stock-in">
                <button class="btn btn-primary" type="button">Date</button>
                <button class="btn btn-primary" type="button">Depot</button>
                <button class="btn btn-primary" type="button">Fuel Type (All)</button>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Purchase-ID</th><th>Order Date</th><th>Fuel</th><th>Depot</th><th>QTY Ordered</th><th>Cost / Liter</th><th>Total Cost</th><th>Current Quantity</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        @foreach ($stockIn as $row)
                            <tr class="{{ $row[9] }}"><td>{{ $row[0] }}</td><td>{{ $row[1] }}</td><td>{{ $row[2] }}</td><td>{{ $row[3] }}</td><td>{{ $row[4] }}</td><td>{{ $row[5] }}</td><td>{{ $row[6] }}</td><td>{{ $row[7] }}</td><td><x-admin.status-badge :status="$row[8]" /></td><td><button class="btn btn-secondary" type="button">Edit</button></td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section data-tab-panel="stock-out" hidden>
            <div class="toolbar toolbar-narrow">
                <input type="search" placeholder="Search..." aria-label="Search stock-out">
                <button class="btn btn-primary" type="button">Date</button>
                <button class="btn btn-primary" type="button">Fuel Type (All)</button>
                <button class="btn btn-primary" type="button">Print</button>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Order-ID</th><th>Transaction Date</th><th>Customer Name</th><th>Company Name</th><th>Fuel</th><th>QTY</th><th>Price / Liter</th><th>Total</th><th>Total Paid</th><th>Current Stock</th><th>Result</th></tr></thead>
                    <tbody>
                        @foreach ($stockOut as $row)
                            <tr class="{{ $row[11] }}"><td>{{ $row[0] }}</td><td>{{ $row[1] }}</td><td>{{ $row[2] }}</td><td>{{ $row[3] }}</td><td>{{ $row[4] }}</td><td>{{ $row[5] }}</td><td>{{ $row[6] }}</td><td>{{ $row[7] }}</td><td>{{ $row[8] }}</td><td>{{ $row[9] }}</td><td><x-admin.status-badge :status="$row[10]" /></td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endcomponent
