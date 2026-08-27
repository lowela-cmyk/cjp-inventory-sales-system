@php
    $sales = [
        ['SLS-000001', '8/22/2026', 'Ken C. Binhi', 'Binhi Green Homes', 'Premium', '10,000.00', '90.00', '900,000.00', '900,000.00', '0', 'Paid', ''],
        ['SLS-000002', '8/22/2026', 'Jay P. Calinisan', 'Jay P Constructions', 'Premium', '20,000.00', '90.00', '1,800,000.00', '800,000.00', '450,000.00', 'Partial', 'row-warning'],
        ['SLS-000003', '8/22/2026', 'Yuri Q. Mabini', 'Gold Steel Productions', 'Premium', '10,000.00', '90.00', '900,000.00', '0', '900,000.00', 'Unpaid', 'row-danger'],
    ];
@endphp

@component('layouts.admin', ['title' => 'Sales Management', 'active' => 'sales'])
    <div data-tabs>
        <h2 class="section-title">Receivables</h2>
        <div class="tabs">
            <button class="tab-button is-active" type="button" data-tab-target="receivables">Receivables</button>
            <button class="tab-button" type="button" data-tab-target="customers">Customers</button>
        </div>
        <div class="actions-right">
            <button class="btn btn-secondary" type="button">Export</button>
        </div>

        <section data-tab-panel="receivables">
            <div class="toolbar toolbar-narrow">
                <input type="search" placeholder="Search..." aria-label="Search sales">
                <button class="btn btn-primary" type="button">Date</button>
                <button class="btn btn-primary" type="button">Depot</button>
                <button class="btn btn-primary" type="button">Fuel Type (All)</button>
                <button class="btn btn-primary" type="button" data-modal-open="sales-add">+ Record Sales</button>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Order-ID</th><th>Transaction Date</th><th>Customer Name</th><th>Company Name</th><th>Fuel</th><th>QTY</th><th>Price / Liter</th><th>Total</th><th>Total Paid</th><th>Balance</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        @foreach ($sales as $row)
                            <tr class="{{ $row[11] }}"><td>{{ $row[0] }}</td><td>{{ $row[1] }}</td><td>{{ $row[2] }}</td><td>{{ $row[3] }}</td><td>{{ $row[4] }}</td><td>{{ $row[5] }}</td><td>{{ $row[6] }}</td><td>{{ $row[7] }}</td><td>{{ $row[8] }}</td><td>{{ $row[9] }}</td><td><x-admin.status-badge :status="$row[10]" /></td><td><button class="btn btn-secondary" type="button" data-modal-open="sales-detail">Edit</button></td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        <section data-tab-panel="customers" hidden>
            <p>Customer records are available in User Management.</p>
        </section>
    </div>

    <x-admin.modal id="sales-add" title="Record Sales Receivables" wide>
        <div class="modal-card">
            <div class="form-grid">
                @foreach (['Order ID', 'Transaction Date', 'Customer Name', 'Company Name', 'Fuel Type', 'Quantity', 'Price / Unit', 'Paid Amount'] as $field)
                    <div class="form-row">
                        <label>{{ $field }}</label>
                        <input type="{{ str_contains($field, 'Date') ? 'date' : (str_contains($field, 'Quantity') || str_contains($field, 'Price') || str_contains($field, 'Paid') ? 'number' : 'text') }}" placeholder="Enter {{ $field }}">
                    </div>
                @endforeach
            </div>
        </div>
        <div class="modal-actions"><button class="btn btn-pill btn-secondary" type="button">Add</button><button class="btn btn-pill btn-danger" type="button" data-modal-close>Cancel</button></div>
    </x-admin.modal>

    <x-admin.modal id="sales-detail" title="Sales Record">
        <div class="modal-card">
            <span class="detail-status">Partial</span>
            <p class="detail-id">SLS-000002</p>
            <div class="detail-grid">
                @foreach (['Transaction Date' => '8/22/2026', 'Customer Name' => 'Jay P. Calinisan', 'Company Name' => 'Jay P Constructions', 'Fuel Type' => 'Premium', 'Quantity Ordered' => '20,000 L', 'Price / Unit' => '90.00', 'Total Price' => '1,800,000.00', 'Total Paid' => '800,000.00', 'Balance' => '450,000.00'] as $label => $value)
                    <div class="detail-row"><div class="detail-label">{{ $label }}</div><div class="detail-value">{{ $value }}</div></div>
                @endforeach
            </div>
        </div>
        <div class="modal-actions"><button class="btn btn-pill btn-success" type="button" data-modal-open="payment-history">Payment History</button><button class="btn btn-pill btn-secondary" type="button">Edit</button><button class="btn btn-pill btn-danger" type="button">Delete</button></div>
    </x-admin.modal>

    <x-admin.modal id="payment-history" title="Payment History">
        <div class="modal-card">
            <p class="detail-id">SLS-000002</p>
            <div class="detail-row"><div class="detail-label">Total / Balance</div><div class="detail-value">PHP 450,000.00</div></div>
            <div class="table-wrap" style="margin-top: 18px">
                <table class="admin-table" style="min-width: 560px"><thead><tr><th>Price / Liter</th><th>Total Price</th><th>Date Recorded</th><th>Amount</th></tr></thead><tbody><tr><td>90.00</td><td>1,800,000.00</td><td>8/22/2026</td><td>800,000.00</td></tr></tbody></table>
            </div>
            <div class="form-grid" style="margin-top: 18px">
                <div class="form-row"><label>Amount</label><input type="number" placeholder="Enter Amount"></div>
                <div class="form-row"><label>Date Recorded</label><input type="date"></div>
            </div>
        </div>
        <div class="modal-actions"><button class="btn btn-pill btn-success" type="button">Add Payment</button></div>
    </x-admin.modal>
@endcomponent
