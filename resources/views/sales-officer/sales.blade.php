@php
    $activeTab = $activeTab ?? (($state ?? 'receivables') === 'customers' ? 'customers' : 'receivables');
    $saleItemOldRows = old('items', [[
        'fuel_type_id' => old('fuel_type_id'),
        'quantity_liters' => old('quantity_liters'),
        'unit_price' => old('unit_price'),
    ]]);
    $paymentMethodLabels = [
        'cash_on_delivery' => 'COD',
        'cheque' => 'Cheque',
        'advance_payment' => 'Advance Payment',
        'bank_transfer' => 'Banking',
    ];
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
                            <th>Due Date</th>
                            <th>Latest Payment</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($sales as $row)
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
                            <tr><td class="empty-cell" colspan="14">No records found.</td></tr>
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
        <form method="POST" action="{{ route('sales-officer.sales.store') }}">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $saleIdempotencyKey) }}">
            <div class="modal-card">
                <div class="form-grid">
                    <div class="form-row"><label for="sale_code">Order ID</label><input id="sale_code" name="sale_code" type="text" placeholder="Auto-generated if blank" value="{{ old('sale_code') }}"></div>
                    <div class="form-row"><label for="sale_date">Transaction Date</label><input id="sale_date" name="sale_date" type="date" value="{{ old('sale_date', now()->toDateString()) }}" required></div>
                    <div class="form-row">
                        <label for="sale_customer_id">Customer Name</label>
                        <select id="sale_customer_id" name="customer_id" required>
                            <option value="">Select Customer</option>
                            @foreach ($customerOptions as $customer)
                                <option value="{{ $customer->id }}" @selected((string) old('customer_id') === (string) $customer->id)>{{ $customer->name }} / {{ $customer->company_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sales-items" data-sales-items>
                        @foreach ($saleItemOldRows as $itemIndex => $item)
                            <div class="sales-item-row" data-sales-item>
                                <div class="form-row">
                                    <label for="sale_fuel_type_id_{{ $itemIndex }}">Fuel Type</label>
                                    <select id="sale_fuel_type_id_{{ $itemIndex }}" name="items[{{ $itemIndex }}][fuel_type_id]" required>
                                        <option value="">Select Fuel Type</option>
                                        @foreach ($fuelTypes as $fuel)
                                            <option value="{{ $fuel->id }}" @selected((string) ($item['fuel_type_id'] ?? '') === (string) $fuel->id)>{{ $fuel->name }} (Garage: {{ number_format((float) $fuel->available_liters, 2) }} L)</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="form-row"><label for="sale_quantity_liters_{{ $itemIndex }}">Quantity</label><input id="sale_quantity_liters_{{ $itemIndex }}" name="items[{{ $itemIndex }}][quantity_liters]" type="number" min="0.01" step="0.01" placeholder="Enter Quantity" value="{{ $item['quantity_liters'] ?? '' }}" required></div>
                                <div class="form-row"><label for="sale_unit_price_{{ $itemIndex }}">Price / Liter</label><input id="sale_unit_price_{{ $itemIndex }}" name="items[{{ $itemIndex }}][unit_price]" type="number" min="0.01" step="0.01" placeholder="Enter Price / Liter" value="{{ $item['unit_price'] ?? '' }}" required></div>
                                <button class="btn btn-secondary sales-item-remove" type="button" data-sales-item-remove @disabled($loop->first && count($saleItemOldRows) === 1)>Remove Item</button>
                            </div>
                        @endforeach
                    </div>
                    <div class="sales-item-actions">
                        <button class="btn btn-primary" type="button" data-sales-item-add>Add Item</button>
                    </div>
                    <div class="form-row">
                        <label for="sale_payment_method">Payment Method</label>
                        <select id="sale_payment_method" name="payment_method" required>
                            @foreach ($paymentMethods as $method)
                                <option value="{{ $method }}" @selected(old('payment_method', 'cash_on_delivery') === $method)>{{ $paymentMethodLabels[$method] ?? ucwords(str_replace('_', ' ', $method)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="sale_payment_terms">Payment Terms</label>
                        <select id="sale_payment_terms" name="payment_terms">
                            @foreach ($paymentTerms as $term)
                                <option value="{{ $term }}" @selected(old('payment_terms', 'cod') === $term)>{{ ucwords($term) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-row"><label for="sale_due_date">Due Date</label><input id="sale_due_date" name="due_date" type="date" value="{{ old('due_date') }}"></div>
                    <div class="form-row">
                        <label for="sale_status">Status</label>
                        <select id="sale_status" name="status">
                            @foreach ($editableSaleStatuses as $status)
                                <option value="{{ $status }}" @selected(old('status', 'confirmed') === $status)>{{ ucwords(str_replace('_', ' ', $status)) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-actions"><button class="btn btn-pill btn-secondary" type="submit">Add</button><button class="btn btn-pill btn-danger" type="button" data-modal-close>Cancel</button></div>
        </form>
    </x-admin.modal>

    @foreach ($sales as $row)
        <x-admin.modal id="{{ $row['modal_id'] }}" title="Edit Sales Record">
            <form id="sale-update-{{ $row['id'] }}" method="POST" action="{{ route('sales-officer.sales.update', $row['id']) }}">
                @csrf
                @method('PATCH')
            </form>
            <div class="modal-card">
                <span class="detail-status">{{ $row['cells'][12] }}</span>
                <p class="detail-id">{{ $row['sale_code'] }}</p>
                <div class="detail-grid">
                    @foreach ($row['details'] as $label => $value)
                        <div class="detail-row"><div class="detail-label">{{ $label }}</div><div class="detail-value">{{ $value }}</div></div>
                    @endforeach
                </div>
                <div class="table-wrap sales-items-table">
                    <table class="admin-table">
                        <thead><tr><th>Fuel</th><th>QTY</th><th>Price / Liter</th><th>Subtotal</th><th>Fulfilled</th></tr></thead>
                        <tbody>
                            @foreach ($row['items'] as $item)
                                <tr><td>{{ $item['fuel_name'] }}</td><td>{{ number_format((float) $item['quantity_liters'], 2) }}</td><td>{{ number_format((float) $item['unit_price'], 2) }}</td><td>{{ number_format((float) $item['line_total'], 2) }}</td><td>{{ number_format((float) $item['fulfilled_quantity_liters'], 2) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="form-grid" style="margin-top:18px">
                    <div class="form-row"><label for="sale_date_{{ $row['id'] }}">Transaction Date</label><input form="sale-update-{{ $row['id'] }}" id="sale_date_{{ $row['id'] }}" name="sale_date" type="date" value="{{ old('sale_date', $row['sale_date']) }}" required></div>
                    <div class="form-row">
                        <label for="sale_customer_{{ $row['id'] }}">Customer Name</label>
                        <select form="sale-update-{{ $row['id'] }}" id="sale_customer_{{ $row['id'] }}" name="customer_id" required>
                            @foreach ($customerOptions as $customer)
                                <option value="{{ $customer->id }}" @selected((int) old('customer_id', $row['customer_id']) === (int) $customer->id)>{{ $customer->name }} / {{ $customer->company_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sales-items" data-sales-items>
                        @foreach ($row['items'] as $itemIndex => $item)
                            <div class="sales-item-row" data-sales-item>
                                <div class="form-row">
                                    <label for="sale_fuel_{{ $row['id'] }}_{{ $itemIndex }}">Fuel Type</label>
                                    <select form="sale-update-{{ $row['id'] }}" id="sale_fuel_{{ $row['id'] }}_{{ $itemIndex }}" name="items[{{ $itemIndex }}][fuel_type_id]" required>
                                        @foreach ($fuelTypes as $fuel)
                                            <option value="{{ $fuel->id }}" @selected((int) old("items.$itemIndex.fuel_type_id", $item['fuel_type_id']) === (int) $fuel->id)>{{ $fuel->name }} (Garage: {{ number_format((float) $fuel->available_liters, 2) }} L)</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="form-row"><label for="sale_qty_{{ $row['id'] }}_{{ $itemIndex }}">Quantity</label><input form="sale-update-{{ $row['id'] }}" id="sale_qty_{{ $row['id'] }}_{{ $itemIndex }}" name="items[{{ $itemIndex }}][quantity_liters]" type="number" min="0.01" step="0.01" value="{{ old("items.$itemIndex.quantity_liters", $item['quantity_liters']) }}" required></div>
                                <div class="form-row"><label for="sale_price_{{ $row['id'] }}_{{ $itemIndex }}">Price / Liter</label><input form="sale-update-{{ $row['id'] }}" id="sale_price_{{ $row['id'] }}_{{ $itemIndex }}" name="items[{{ $itemIndex }}][unit_price]" type="number" min="0.01" step="0.01" value="{{ old("items.$itemIndex.unit_price", $item['unit_price']) }}" required></div>
                                <button class="btn btn-secondary sales-item-remove" type="button" data-sales-item-remove @disabled($loop->first && count($row['items']) === 1)>Remove Item</button>
                            </div>
                        @endforeach
                    </div>
                    <div class="sales-item-actions">
                        <button class="btn btn-primary" type="button" data-sales-item-add>Add Item</button>
                    </div>
                    <div class="form-row">
                        <label for="sale_method_{{ $row['id'] }}">Payment Method</label>
                        <select form="sale-update-{{ $row['id'] }}" id="sale_method_{{ $row['id'] }}" name="payment_method" required>
                            @foreach ($paymentMethods as $method)
                                <option value="{{ $method }}" @selected(old('payment_method', $row['payment_method']) === $method)>{{ $paymentMethodLabels[$method] ?? ucwords(str_replace('_', ' ', $method)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="sale_terms_{{ $row['id'] }}">Payment Terms</label>
                        <select form="sale-update-{{ $row['id'] }}" id="sale_terms_{{ $row['id'] }}" name="payment_terms">
                            @foreach ($paymentTerms as $term)
                                <option value="{{ $term }}" @selected(old('payment_terms', $row['payment_terms']) === $term)>{{ ucwords($term) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-row"><label for="sale_due_{{ $row['id'] }}">Due Date</label><input form="sale-update-{{ $row['id'] }}" id="sale_due_{{ $row['id'] }}" name="due_date" type="date" value="{{ old('due_date', $row['due_date']) }}"></div>
                    <div class="form-row">
                        <label for="sale_status_{{ $row['id'] }}">Status</label>
                        <select form="sale-update-{{ $row['id'] }}" id="sale_status_{{ $row['id'] }}" name="status">
                            @foreach ($editableSaleStatuses as $status)
                                <option value="{{ $status }}" @selected(old('status', $row['status']) === $status)>{{ ucwords(str_replace('_', ' ', $status)) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-pill btn-success" type="button" data-modal-open="{{ $row['payment_id'] }}">Payment History</button>
                <button class="btn btn-pill btn-secondary" type="submit" form="sale-update-{{ $row['id'] }}">Edit</button>
                <form method="POST" action="{{ route('sales-officer.sales.cancel', $row['id']) }}">
                    @csrf
                    @method('PATCH')
                    <button class="btn btn-pill btn-danger" type="submit" @disabled($row['has_dependencies'])>Delete</button>
                </form>
            </div>
        </x-admin.modal>

        <x-admin.modal id="{{ $row['payment_id'] }}" title="Payment History">
            <div class="modal-card">
                <p class="detail-id">{{ $row['sale_code'] }}</p>
                <div class="detail-grid">
                    <div class="detail-row"><div class="detail-label">Sale Total</div><div class="detail-value">PHP {{ $row['sale_total'] }}</div></div>
                    <div class="detail-row"><div class="detail-label">Total Paid</div><div class="detail-value">PHP {{ $row['total_paid'] }}</div></div>
                    <div class="detail-row"><div class="detail-label">Remaining Balance</div><div class="detail-value">PHP {{ $row['balance'] }}</div></div>
                    <div class="detail-row"><div class="detail-label">Payment Status</div><div class="detail-value">{{ $row['cells'][12] }}</div></div>
                </div>
                <form method="POST" action="{{ route('sales-officer.sales.payments.store', $row['id']) }}" style="margin-top:18px">
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $row['payment_token']) }}">
                    <div class="form-grid">
                        <div class="form-row"><label for="payment_amount_{{ $row['id'] }}">Amount Paid</label><input id="payment_amount_{{ $row['id'] }}" name="amount" type="number" min="0.01" step="0.01" max="{{ str_replace(',', '', $row['balance']) }}" value="{{ old('amount') }}" required></div>
                        <div class="form-row">
                            <label for="payment_method_{{ $row['id'] }}">Payment Method</label>
                            <select id="payment_method_{{ $row['id'] }}" name="method" required>
                                @foreach ($paymentMethods as $method)
                                    <option value="{{ $method }}" @selected(old('method', $row['payment_method'] ?: 'cash_on_delivery') === $method)>{{ $paymentMethodLabels[$method] ?? ucwords(str_replace('_', ' ', $method)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        @if (! empty($row['payment_schedules']))
                            <div class="form-row">
                                <label for="payment_schedule_{{ $row['id'] }}">Installment</label>
                                <select id="payment_schedule_{{ $row['id'] }}" name="payment_schedule_id">
                                    <option value="">Unscheduled Payment</option>
                                    @foreach ($row['payment_schedules'] as $schedule)
                                        <option value="{{ $schedule['id'] }}" @selected((string) old('payment_schedule_id') === (string) $schedule['id']) @disabled(! $schedule['is_payable'])>{{ $schedule['label'] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                        <div class="form-row"><label for="payment_reference_{{ $row['id'] }}">Reference Number</label><input id="payment_reference_{{ $row['id'] }}" name="reference_number" type="text" maxlength="100" value="{{ old('reference_number') }}"></div>
                        <div class="form-row"><label for="payment_date_{{ $row['id'] }}">Payment Date</label><input id="payment_date_{{ $row['id'] }}" name="payment_date" type="date" value="{{ old('payment_date', now()->toDateString()) }}" required></div>
                        <div class="form-row"><label for="payment_remarks_{{ $row['id'] }}">Remarks</label><input id="payment_remarks_{{ $row['id'] }}" name="remarks" type="text" maxlength="1000" value="{{ old('remarks') }}"></div>
                    </div>
                    <div class="modal-actions"><button class="btn btn-pill btn-secondary" type="submit" @disabled((float) str_replace(',', '', $row['balance']) <= 0)>Record Payment</button></div>
                </form>
                @if (! empty($row['payment_schedules']))
                    <div class="table-wrap" style="margin-top:18px;min-height:auto">
                        <table class="admin-table" style="min-width:640px">
                            <thead><tr><th>Installment</th><th>Due Date</th><th>Amount Due</th><th>Paid</th><th>Remaining</th><th>Status</th></tr></thead>
                            <tbody>
                                @foreach ($row['payment_schedules'] as $schedule)
                                    <tr><td>{{ $schedule['sequence'] }}</td><td>{{ $schedule['due_date'] }}</td><td>{{ $schedule['amount_due'] }}</td><td>{{ $schedule['paid'] }}</td><td>{{ $schedule['remaining'] }}</td><td><x-admin.status-badge :status="$schedule['status']" /></td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <div class="table-wrap" style="margin-top:18px;min-height:auto">
                    <table class="admin-table" style="min-width:560px">
                        <thead><tr><th>Installment</th><th>Payment ID</th><th>Date Recorded</th><th>Amount</th><th>Method</th><th>Reference</th><th>Recorded By</th><th>Status</th></tr></thead>
                        <tbody>
                            @forelse ($row['payments'] as $payment)
                                <tr><td>{{ $payment['sequence'] }}</td><td>{{ $payment['code'] }}</td><td>{{ $payment['date'] }}</td><td>{{ $payment['amount'] }}</td><td>{{ $payment['method'] }}</td><td>{{ $payment['reference'] }}</td><td>{{ $payment['recorded_by'] }}</td><td>{{ $payment['status'] }}</td></tr>
                            @empty
                                <tr><td class="empty-cell" colspan="8">No payment records found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </x-admin.modal>
    @endforeach

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
