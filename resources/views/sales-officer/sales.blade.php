@php
    $activeTab = $activeTab ?? (($state ?? 'receivables') === 'customers' ? 'customers' : 'receivables');
@endphp

@component('layouts.sales-officer', ['title' => 'Sales Management', 'active' => 'sales'])
    <div data-tabs>
        <h2 class="section-title" data-tab-heading>{{ $activeTab === 'customers' ? 'Customers' : 'Receivables' }}</h2>
        @if (session('status'))
            <div class="admin-flash admin-flash-success" role="status">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="admin-flash admin-flash-error" role="alert">{{ $errors->first() }}</div>
        @endif

        <div class="tabs">
            <button class="tab-button {{ $activeTab === 'receivables' ? 'is-active' : '' }}" type="button" data-tab-target="receivables" data-heading="Receivables">Receivables</button>
            <button class="tab-button {{ $activeTab === 'customers' ? 'is-active' : '' }}" type="button" data-tab-target="customers" data-heading="Customers">Customers</button>
        </div>
        <div class="actions-right">
            <button class="btn btn-secondary" type="button">Export</button>
        </div>

        <section data-tab-panel="receivables" @hidden($activeTab !== 'receivables')>
            <form class="toolbar toolbar-narrow" method="GET" action="{{ route('sales-officer.sales') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search receivables" value="{{ $search }}">
                <button class="btn btn-primary" type="submit">Date</button>
                <button class="btn btn-primary" type="submit">Depot</button>
                <button class="btn btn-primary" type="submit">Fuel Type (All)</button>
                <button class="btn btn-primary" type="button" data-modal-open="so-sales-add">+ Record Sales</button>
            </form>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Order-ID</th>
                            <th>Transaction Date</th>
                            <th>Customer Name</th>
                            <th>Company Name</th>
                            <th>Fuel</th>
                            <th>QTY</th>
                            <th>Price / Liter</th>
                            <th>Total</th>
                            <th>Total Paid</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($sales as $row)
                            <tr class="{{ $row[11] }}">
                                <td>{{ $row[0] }}</td>
                                <td>{{ $row[1] }}</td>
                                <td>{{ $row[2] }}</td>
                                <td>{{ $row[3] }}</td>
                                <td>{{ $row[4] }}</td>
                                <td>{{ $row[5] }}</td>
                                <td>{{ $row[6] }}</td>
                                <td>{{ $row[7] }}</td>
                                <td>{{ $row[8] }}</td>
                                <td>{{ $row[9] }}</td>
                                <td><x-admin.status-badge :status="$row[10]" /></td>
                                <td><button class="btn btn-secondary" type="button" data-modal-open="so-sales-edit">Edit</button></td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="12">No records found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section data-tab-panel="customers" @hidden($activeTab !== 'customers')>
            <form class="toolbar toolbar-narrow" method="GET" action="{{ route('sales-officer.sales.customers') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search customers" value="{{ $search }}">
                <button class="btn btn-primary" type="submit">Date</button>
                <button class="btn btn-primary" type="submit">Depot</button>
                <button class="btn btn-primary" type="submit">Fuel Type (All)</button>
                <button class="btn btn-primary" type="button" data-modal-open="so-customer-add">+ Add Customers</button>
            </form>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Customer ID</th>
                            <th>Name</th>
                            <th>Company Name</th>
                            <th>Location</th>
                            <th>Email</th>
                            <th>Contact Number</th>
                            <th>Payment Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($customers as $row)
                            <tr class="{{ $row['class'] }}">
                                @foreach ($row['cells'] as $cell)
                                    @if ($loop->last)
                                        <td><x-admin.status-badge :status="$cell" /></td>
                                    @else
                                        <td>{{ $cell }}</td>
                                    @endif
                                @endforeach
                                <td><button class="btn btn-secondary" type="button" data-modal-open="{{ $row['modal_id'] }}">Edit</button></td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="8">No customer records found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <x-admin.modal id="so-sales-add" title="Record Sales Receivables" wide>
        <div class="modal-card">
            <div class="form-grid">
                @foreach (['Order ID', 'Transaction Date', 'Customer Name', 'Company Name', 'Fuel Type', 'Quantity', 'Price / Liter', 'Paid Amount'] as $field)
                    <div class="form-row">
                        <label>{{ $field }}</label>
                        <input type="{{ str_contains($field, 'Date') ? 'date' : (str_contains($field, 'Quantity') || str_contains($field, 'Price') || str_contains($field, 'Paid') ? 'number' : 'text') }}" placeholder="Enter {{ $field }}">
                    </div>
                @endforeach
            </div>
        </div>
        <div class="modal-actions"><button class="btn btn-pill btn-secondary" type="button">Add</button><button class="btn btn-pill btn-danger" type="button" data-modal-close>Delete</button></div>
    </x-admin.modal>

    <x-admin.modal id="so-sales-edit" title="Edit Sales Record">
        <div class="modal-card">
            <span class="detail-status">Partial</span>
            <p class="detail-id">SLS-000002</p>
            <div class="detail-grid">
                @foreach (['Transaction Date' => '8/22/2026', 'Customer Name' => 'Jay P. Calinisan', 'Company Name' => 'Jay P Constructions', 'Fuel Type' => 'Premium', 'Quantity' => '20,000.00', 'Price / Liter' => '90.00', 'Total' => '1,800,000.00', 'Total Paid' => '800,000.00', 'Balance' => '450,000.00'] as $label => $value)
                    <div class="detail-row"><div class="detail-label">{{ $label }}</div><div class="detail-value">{{ $value }}</div></div>
                @endforeach
            </div>
        </div>
        <div class="modal-actions"><button class="btn btn-pill btn-success" type="button" data-modal-open="so-payment-history">Payment History</button><button class="btn btn-pill btn-secondary" type="button">Edit</button><button class="btn btn-pill btn-danger" type="button">Delete</button></div>
    </x-admin.modal>

    <x-admin.modal id="so-payment-history" title="Payment History">
        <div class="modal-card">
            <p class="detail-id">SLS-000002</p>
            <div class="detail-row"><div class="detail-label">Total / Balance</div><div class="detail-value">PHP 450,000.00</div></div>
            <div class="table-wrap" style="margin-top:18px;min-height:auto">
                <table class="admin-table" style="min-width:560px"><thead><tr><th>Price / Liter</th><th>Total Price</th><th>Date Recorded</th><th>Amount</th></tr></thead><tbody><tr><td>90.00</td><td>1,800,000.00</td><td>8/22/2026</td><td>800,000.00</td></tr></tbody></table>
            </div>
            <div class="form-grid" style="margin-top:18px">
                <div class="form-row"><label>Amount</label><input type="number" placeholder="Enter Amount"></div>
                <div class="form-row"><label>Date Recorded</label><input type="date"></div>
            </div>
        </div>
        <div class="modal-actions"><button class="btn btn-pill btn-success" type="button">Add Payment</button></div>
    </x-admin.modal>

    <x-admin.modal id="so-customer-add" title="Add Customers" wide>
        <form method="POST" action="{{ route('sales-officer.sales.customers.store') }}">
            @csrf
            <div class="modal-card">
                <div class="form-grid">
                    <div class="form-row"><label for="customer_name">Customer Name</label><input id="customer_name" name="name" type="text" placeholder="Enter Customer Name" value="{{ old('name') }}" required></div>
                    <div class="form-row"><label for="customer_company_name">Company Name</label><input id="customer_company_name" name="company_name" type="text" placeholder="Enter Company Name" value="{{ old('company_name') }}" required></div>
                    <div class="form-row"><label for="customer_location">Location</label><input id="customer_location" name="location" type="text" placeholder="Enter Location" value="{{ old('location') }}"></div>
                    <div class="form-row"><label for="customer_email">Email</label><input id="customer_email" name="email" type="email" placeholder="Enter Email" value="{{ old('email') }}"></div>
                    <div class="form-row"><label for="customer_phone">Contact Number</label><input id="customer_phone" name="phone" type="tel" placeholder="Enter Contact Number" value="{{ old('phone') }}"></div>
                    <div class="form-row"><label for="customer_payment_status">Payment Status</label><select id="customer_payment_status" name="payment_status" required>@foreach ($paymentStatuses as $status)<option value="{{ $status }}" @selected(old('payment_status', 'clear') === $status)>{{ ucwords($status) }}</option>@endforeach</select></div>
                    <div class="form-row"><label for="customer_status">Status</label><select id="customer_status" name="status" required>@foreach ($accountStatuses as $status)<option value="{{ $status }}" @selected(old('status', 'active') === $status)>{{ ucwords($status) }}</option>@endforeach</select></div>
                </div>
            </div>
            <div class="modal-actions"><button class="btn btn-pill btn-secondary" type="submit">Add</button><button class="btn btn-pill btn-danger" type="button" data-modal-close>Cancel</button></div>
        </form>
    </x-admin.modal>

    @foreach ($customers as $row)
        <x-admin.modal id="{{ $row['modal_id'] }}" title="Edit Customer Record">
            <form id="customer-update-{{ $row['id'] }}" method="POST" action="{{ route('sales-officer.sales.customers.update', $row['id']) }}">
                @csrf
                @method('PATCH')
            </form>
            <div class="modal-card">
                <span class="detail-status">{{ ucwords($row['status']) }}</span>
                <p class="detail-id">{{ $row['customer_code'] }}</p>
                <div class="detail-grid">
                    @foreach ($row['details'] as $label => $value)
                        <div class="detail-row"><div class="detail-label">{{ $label }}</div><div class="detail-value">{{ $value }}</div></div>
                    @endforeach
                </div>
                <div class="form-grid" style="margin-top: 18px">
                    <div class="form-row"><label for="customer_name_{{ $row['id'] }}">Customer Name</label><input form="customer-update-{{ $row['id'] }}" id="customer_name_{{ $row['id'] }}" name="name" type="text" value="{{ old('name', $row['name']) }}" required></div>
                    <div class="form-row"><label for="customer_company_name_{{ $row['id'] }}">Company Name</label><input form="customer-update-{{ $row['id'] }}" id="customer_company_name_{{ $row['id'] }}" name="company_name" type="text" value="{{ old('company_name', $row['company_name']) }}" required></div>
                    <div class="form-row"><label for="customer_location_{{ $row['id'] }}">Location</label><input form="customer-update-{{ $row['id'] }}" id="customer_location_{{ $row['id'] }}" name="location" type="text" value="{{ old('location', $row['location']) }}"></div>
                    <div class="form-row"><label for="customer_email_{{ $row['id'] }}">Email</label><input form="customer-update-{{ $row['id'] }}" id="customer_email_{{ $row['id'] }}" name="email" type="email" value="{{ old('email', $row['email']) }}"></div>
                    <div class="form-row"><label for="customer_phone_{{ $row['id'] }}">Contact Number</label><input form="customer-update-{{ $row['id'] }}" id="customer_phone_{{ $row['id'] }}" name="phone" type="tel" value="{{ old('phone', $row['phone']) }}"></div>
                    <div class="form-row"><label for="customer_payment_status_{{ $row['id'] }}">Payment Status</label><select form="customer-update-{{ $row['id'] }}" id="customer_payment_status_{{ $row['id'] }}" name="payment_status" required>@foreach ($paymentStatuses as $status)<option value="{{ $status }}" @selected(old('payment_status', $row['payment_status']) === $status)>{{ ucwords($status) }}</option>@endforeach</select></div>
                    <div class="form-row"><label for="customer_status_{{ $row['id'] }}">Status</label><select form="customer-update-{{ $row['id'] }}" id="customer_status_{{ $row['id'] }}" name="status" required>@foreach ($accountStatuses as $status)<option value="{{ $status }}" @selected(old('status', $row['status']) === $status)>{{ ucwords($status) }}</option>@endforeach</select></div>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-pill btn-secondary" type="submit" form="customer-update-{{ $row['id'] }}">Edit</button>
                <form method="POST" action="{{ route('sales-officer.sales.customers.deactivate', $row['id']) }}">
                    @csrf
                    @method('PATCH')
                    <button class="btn btn-pill btn-danger" type="submit">Delete</button>
                </form>
            </div>
        </x-admin.modal>
    @endforeach
@endcomponent
