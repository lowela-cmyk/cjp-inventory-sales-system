@php
    $purchases = [
        ['PUR-000001', '8/20/2026', 'Premium', 'Petron A', '100,000.00', '80.00', '8,000,000.00', 'img.png', 'Paid', ''],
        ['PUR-000002', '8/26/2026', 'Unleaded', 'Shell A', '80,000.00', '70.00', '5,600,000.00', 'img.png', 'Unpaid', 'row-danger'],
        ['PUR-000003', '8/26/2026', 'Diesel', 'Phoenix A', '90,000.00', '85.00', '7,650,000.00', 'img.png', 'Partial', 'row-warning'],
    ];
    $stockIn = [
        ['PUR-000001', '8/20/2026', 'Premium', 'Petron A', '100,000.00', '80.00', '8,000,000.00', '40,000.00', '40,000.00', 'Partial', 'row-warning'],
        ['PUR-000002', '8/26/2026', 'Unleaded', 'Shell A', '80,000.00', '70.00', '5,600,000.00', '0', '0', 'Unlifted', 'row-danger'],
        ['PUR-000003', '8/26/2026', 'Diesel', 'Phoenix A', '90,000.00', '85.00', '7,650,000.00', '0', '0', 'Unlifted', 'row-danger'],
    ];
    $stockOut = [
        ['SLS-000001', '8/22/2026', 'Ken C. Binhi', 'Binhi Green Homes', 'Premium', '10,000.00', '90.00', '900,000.00', '900,000.00', '80.00', '800,000.00', '100,000.00', ''],
        ['SLS-000002', '8/22/2026', 'Jay P. Calinisan', 'Jay P Constructions', 'Premium', '20,000.00', '90.00', '1,800,000.00', '800,000.00', '80.00', '1,600,000.00', '- 800,000.00', 'row-danger'],
        ['SLS-000003', '8/22/2026', 'Yuri Q. Mabini', 'Gold Steel Productions', 'Premium', '10,000.00', '90.00', '900,000.00', '0', '80.00', '800,000.00', '- 800,000.00', 'row-danger'],
    ];
@endphp

@component('layouts.inventory-officer', ['title' => 'Inventory Management', 'active' => 'inventory'])
    <div data-tabs>
        <h2 class="section-title" data-tab-heading>Purchases</h2>
        <div class="tabs">
            <button class="tab-button is-active" type="button" data-tab-target="purchases" data-heading="Purchases">Purchases</button>
            <button class="tab-button" type="button" data-tab-target="stock-in" data-heading="Stock-In">Stock-In</button>
            <button class="tab-button" type="button" data-tab-target="stock-out" data-heading="Stock-Out">Stock Out</button>
        </div>
        <div class="actions-right"><button class="btn btn-secondary" type="button">Export</button></div>

        <section data-tab-panel="purchases">
            <div class="toolbar">
                <input type="search" placeholder="Search..." aria-label="Search purchases">
                <button class="btn btn-primary" type="button">Status</button>
                <button class="btn btn-primary" type="button">Date</button>
                <button class="btn btn-primary" type="button">Depot</button>
                <button class="btn btn-primary" type="button">Fuel Type (All)</button>
                <button class="btn btn-primary" type="button" data-modal-open="io-purchase-add">+ Record Purchases</button>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Purchase-ID</th><th>Date</th><th>Fuel</th><th>Depot</th><th>QTY (L)</th><th>Cost / Liter</th><th>Total Cost</th><th>Delivery Receipt</th><th>Payment Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        @foreach ($purchases as $row)
                            <tr class="{{ $row[9] }}"><td>{{ $row[0] }}</td><td>{{ $row[1] }}</td><td>{{ $row[2] }}</td><td>{{ $row[3] }}</td><td>{{ $row[4] }}</td><td>{{ $row[5] }}</td><td>{{ $row[6] }}</td><td>{{ $row[7] }}</td><td><x-admin.status-badge :status="$row[8]" /></td><td><button class="btn btn-secondary" type="button" data-modal-open="io-purchase-edit">Edit</button></td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section data-tab-panel="stock-in" hidden>
            <div class="toolbar">
                <input type="search" placeholder="Search..." aria-label="Search stock-in">
                <span></span>
                <button class="btn btn-primary" type="button">Status</button>
                <button class="btn btn-primary" type="button">Date</button>
                <button class="btn btn-primary" type="button">Depot</button>
                <button class="btn btn-primary" type="button">Fuel Type (All)</button>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Purchase-ID</th><th>Order Date</th><th>Fuel</th><th>Depot</th><th>QTY Ordered (L)</th><th>Cost / Liter</th><th>Total Cost</th><th>Current QTY</th><th>Sold QTY</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        @foreach ($stockIn as $row)
                            <tr class="{{ $row[10] }}"><td>{{ $row[0] }}</td><td>{{ $row[1] }}</td><td>{{ $row[2] }}</td><td>{{ $row[3] }}</td><td>{{ $row[4] }}</td><td>{{ $row[5] }}</td><td>{{ $row[6] }}</td><td>{{ $row[7] }}</td><td>{{ $row[8] }}</td><td><x-admin.status-badge :status="$row[9]" /></td><td><button class="btn btn-secondary" type="button" data-modal-open="io-stockin-edit">Edit</button></td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section data-tab-panel="stock-out" hidden>
            <div class="toolbar">
                <input type="search" placeholder="Search..." aria-label="Search stock-out">
                <button class="btn btn-primary" type="button">Status</button>
                <button class="btn btn-primary" type="button">Date</button>
                <button class="btn btn-primary" type="button">Depot</button>
                <button class="btn btn-primary" type="button">Fuel Type (All)</button>
                <button class="btn btn-primary" type="button">+ Record Stock-Out</button>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Order-ID</th><th>Transaction Date</th><th>Customer Name</th><th>Company Name</th><th>Fuel</th><th>QTY</th><th>Price / Unit</th><th>Total Price</th><th>Total Paid</th><th>Cost / Unit</th><th>Total Cost</th><th>Profit</th></tr></thead>
                    <tbody>
                        @foreach ($stockOut as $row)
                            <tr class="{{ $row[12] }}"><td>{{ $row[0] }}</td><td>{{ $row[1] }}</td><td>{{ $row[2] }}</td><td>{{ $row[3] }}</td><td>{{ $row[4] }}</td><td>{{ $row[5] }}</td><td>{{ $row[6] }}</td><td>{{ $row[7] }}</td><td>{{ $row[8] }}</td><td>{{ $row[9] }}</td><td>{{ $row[10] }}</td><td>{{ $row[11] }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <x-admin.modal id="io-purchase-add" title="Add Purchase Record" wide>
        <div class="modal-card">
            <div class="form-grid">
                @foreach (['Date' => 'date', 'Fuel Type' => 'text', 'Depot' => 'text', 'Quantity (Liters)' => 'number', 'Delivery Receipt' => 'text', 'Cost / Liter' => 'number'] as $field => $type)
                    <div class="form-row">
                        <label>{{ $field }}</label>
                        <input type="{{ $type }}" placeholder="{{ $field === 'Delivery Receipt' ? 'Upload document' : 'Enter ' . ($field === 'Depot' ? 'Depot Name' : $field) }}">
                    </div>
                @endforeach
            </div>
        </div>
        <div class="modal-actions"><button class="btn btn-pill btn-secondary" type="button">Add</button><button class="btn btn-pill btn-danger" type="button" data-modal-close>Delete</button></div>
    </x-admin.modal>

    <x-admin.modal id="io-purchase-edit" title="Edit Purchase Record">
        <div class="modal-card">
            <span class="detail-status" style="color:#f50037">Unpaid</span>
            <p class="detail-id">PUR-000002</p>
            <div class="detail-grid">
                @foreach (['Date' => '8/26/2026', 'Fuel' => 'Unleaded', 'Depot' => 'Shell A', 'Quantity' => '80,000 L', 'Cost/Liter' => '70.00', 'Total Cost' => '5,600,000 L', 'Delivery Receipt' => 'img.png'] as $label => $value)
                    <div class="detail-row"><div class="detail-label">{{ $label }}</div><div class="detail-value">{{ $value }}</div></div>
                @endforeach
            </div>
        </div>
        <div class="modal-actions"><button class="btn btn-pill btn-secondary" type="button">Edit</button><button class="btn btn-pill btn-danger" type="button">Delete</button></div>
    </x-admin.modal>

    <x-admin.modal id="io-stockin-edit" title="Edit Stock-In Record">
        <div class="modal-card">
            <p class="detail-id">PUR-000001</p>
            <div class="detail-grid">
                @foreach (['Date' => '8/20/2026', 'Fuel' => 'Premium', 'Depot' => 'Petron A', 'Quantity' => '100,000 L', 'Cost / Liter' => '80.00', 'Total Cost' => '8,000,000 L', 'Current Quantity' => '40,000.00 L', 'Sold Quantity' => '40,000.00 L'] as $label => $value)
                    <div class="detail-row"><div class="detail-label">{{ $label }}</div><div class="detail-value">{{ $value }}</div></div>
                @endforeach
            </div>
        </div>
        <div class="modal-actions"><button class="btn btn-pill btn-secondary" type="button">Send to Garage</button></div>
    </x-admin.modal>
@endcomponent
