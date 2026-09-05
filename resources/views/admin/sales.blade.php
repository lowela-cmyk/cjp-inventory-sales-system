@php
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

@component('layouts.admin', ['title' => 'Sales Management', 'active' => 'sales'])
    <div data-tabs>
        <h2 class="section-title">Receivables</h2>
        <div class="tabs">
            <button class="tab-button is-active" type="button" data-tab-target="receivables">Receivables</button>
            <button class="tab-button" type="button" data-tab-target="customers">Customers</button>
        </div>
        <div class="actions-right">
            <button class="btn btn-secondary" type="button" data-export-table>Export</button>
        </div>

        <section data-tab-panel="receivables">
            <form class="toolbar toolbar-narrow" method="GET" action="{{ route('admin.sales') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search sales" value="{{ $search }}">
                <button class="btn btn-primary" type="submit">Date</button>
                <button class="btn btn-primary" type="submit">Depot</button>
                <button class="btn btn-primary" type="submit">Fuel Type (All)</button>
                <button class="btn btn-primary" type="button" data-modal-open="sales-add">+ Record Sales</button>
            </form>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Order-ID</th><th>Sales Order No.</th><th>Transaction Date</th><th>Customer Name</th><th>Company Name</th><th>Fuel</th><th>QTY</th><th>Price / Liter</th><th>Total</th><th>Total Paid</th><th>Balance</th><th>Due Date</th><th>Latest Payment</th><th>Status</th><th>Actions</th></tr></thead>
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
                                <td><button class="btn btn-secondary" type="button" data-modal-open="{{ $row['id'] }}">View</button></td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="15">No records found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        <section data-tab-panel="customers" hidden>
            <form class="toolbar toolbar-narrow" method="GET" action="{{ route('admin.sales') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search customers" value="{{ $search }}">
                <button class="btn btn-primary" type="submit">Location</button>
                <button class="btn btn-primary" type="submit">Company</button>
                <button class="btn btn-primary" type="button" data-modal-open="customer-add">+ Add Customer</button>
            </form>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Customer ID</th><th>Customer Name</th><th>Company Name</th><th>Location</th><th>Email</th><th>Contact Number</th><th>Actions</th></tr></thead>
                    <tbody>
                        @forelse ($customers as $row)
                            <tr>
                                @foreach ($row['cells'] as $cell)
                                    <td>{{ $cell }}</td>
                                @endforeach
                                <td><button class="btn btn-secondary" type="button" data-modal-open="{{ $row['id'] }}">View</button></td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="7">No records found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <x-admin.modal id="sales-add" title="Record Sales Receivables" wide>
        <form method="POST" action="{{ route('admin.sales.store') }}">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $saleIdempotencyKey) }}">
            <div class="modal-card">
                <div class="form-grid">
                    <div class="form-row"><label for="admin_sale_code">Order ID</label><input id="admin_sale_code" name="sale_code" type="text" maxlength="30" placeholder="Auto-generated if blank" value="{{ old('sale_code') }}"></div>
                    <div class="form-row"><label for="admin_sales_order_number">Sales Order Number</label><input id="admin_sales_order_number" name="sales_order_number" type="text" maxlength="60" placeholder="Customer receipt/order number" value="{{ old('sales_order_number') }}"></div>
                    <div class="form-row"><label for="admin_sale_date">Transaction Date</label><input id="admin_sale_date" name="sale_date" type="date" value="{{ old('sale_date', now()->toDateString()) }}" required></div>
                    <div class="form-row">
                        <label for="admin_sale_customer_id">Customer Name</label>
                        <select id="admin_sale_customer_id" name="customer_id" required>
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
                                    <label for="admin_sale_fuel_type_id_{{ $itemIndex }}">Fuel Type</label>
                                    <select id="admin_sale_fuel_type_id_{{ $itemIndex }}" name="items[{{ $itemIndex }}][fuel_type_id]" required>
                                        <option value="">Select Fuel Type</option>
                                        @foreach ($fuelTypes as $fuel)
                                            <option value="{{ $fuel->id }}" @selected((string) ($item['fuel_type_id'] ?? '') === (string) $fuel->id)>{{ $fuel->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="form-row"><label for="admin_sale_quantity_liters_{{ $itemIndex }}">Quantity</label><input id="admin_sale_quantity_liters_{{ $itemIndex }}" name="items[{{ $itemIndex }}][quantity_liters]" type="number" min="0.01" step="0.01" value="{{ $item['quantity_liters'] ?? '' }}" required></div>
                                <div class="form-row"><label for="admin_sale_unit_price_{{ $itemIndex }}">Price / Liter</label><input id="admin_sale_unit_price_{{ $itemIndex }}" name="items[{{ $itemIndex }}][unit_price]" type="number" min="0.01" step="0.01" value="{{ $item['unit_price'] ?? '' }}" required></div>
                                <button class="btn btn-secondary sales-item-remove" type="button" data-sales-item-remove @disabled($loop->first && count($saleItemOldRows) === 1)>Remove Item</button>
                            </div>
                        @endforeach
                    </div>
                    <div class="sales-item-actions"><button class="btn btn-primary" type="button" data-sales-item-add>Add Item</button></div>
                    <div class="form-row">
                        <label for="admin_sale_payment_method">Payment Method</label>
                        <select id="admin_sale_payment_method" name="payment_method" required>
                            @foreach ($paymentMethods as $method)
                                <option value="{{ $method }}" @selected(old('payment_method', 'cash_on_delivery') === $method)>{{ $paymentMethodLabels[$method] ?? ucwords(str_replace('_', ' ', $method)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="admin_sale_payment_terms">Payment Terms</label>
                        <select id="admin_sale_payment_terms" name="payment_terms">
                            @foreach ($paymentTerms as $term)
                                <option value="{{ $term }}" @selected(old('payment_terms', 'cod') === $term)>{{ ucwords($term) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-row"><label for="admin_sale_due_date">Due Date</label><input id="admin_sale_due_date" name="due_date" type="date" value="{{ old('due_date') }}"></div>
                    <div class="form-row">
                        <label for="admin_sale_status">Status</label>
                        <select id="admin_sale_status" name="status">
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
        <x-admin.modal id="{{ $row['id'] }}" title="Sales Record">
            <form id="admin-sale-update-{{ $row['sale_id'] }}" method="POST" action="{{ route('admin.sales.update', $row['sale_id']) }}">
                @csrf
                @method('PATCH')
            </form>
            <div class="modal-card">
                <span class="detail-status">{{ $row['status'] }}</span>
                <p class="detail-id">{{ $row['cells'][0] }}</p>
                <div class="detail-grid">
                    @foreach ($row['details'] as $label => $value)
                        <div class="detail-row"><div class="detail-label">{{ $label }}</div><div class="detail-value">{{ $value }}</div></div>
                    @endforeach
                </div>
                <div class="form-grid" style="margin-top:18px">
                    <div class="form-row"><label for="admin_sales_order_number_{{ $row['sale_id'] }}">Sales Order Number</label><input form="admin-sale-update-{{ $row['sale_id'] }}" id="admin_sales_order_number_{{ $row['sale_id'] }}" name="sales_order_number" type="text" maxlength="60" value="{{ old('sales_order_number', $row['sales_order_number']) }}"></div>
                    <div class="form-row"><label for="admin_sale_date_{{ $row['sale_id'] }}">Transaction Date</label><input form="admin-sale-update-{{ $row['sale_id'] }}" id="admin_sale_date_{{ $row['sale_id'] }}" name="sale_date" type="date" value="{{ old('sale_date', $row['sale_date']) }}" required></div>
                    <div class="form-row">
                        <label for="admin_sale_customer_{{ $row['sale_id'] }}">Customer Name</label>
                        <select form="admin-sale-update-{{ $row['sale_id'] }}" id="admin_sale_customer_{{ $row['sale_id'] }}" name="customer_id" required>
                            @foreach ($customerOptions as $customer)
                                <option value="{{ $customer->id }}" @selected((int) old('customer_id', $row['customer_id']) === (int) $customer->id)>{{ $customer->name }} / {{ $customer->company_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @foreach ($row['items'] as $itemIndex => $item)
                        <input form="admin-sale-update-{{ $row['sale_id'] }}" type="hidden" name="items[{{ $itemIndex }}][fuel_type_id]" value="{{ $item['fuel_type_id'] }}">
                        <input form="admin-sale-update-{{ $row['sale_id'] }}" type="hidden" name="items[{{ $itemIndex }}][quantity_liters]" value="{{ $item['quantity_liters'] }}">
                        <input form="admin-sale-update-{{ $row['sale_id'] }}" type="hidden" name="items[{{ $itemIndex }}][unit_price]" value="{{ $item['unit_price'] }}">
                    @endforeach
                    <div class="form-row">
                        <label for="admin_sale_method_{{ $row['sale_id'] }}">Payment Method</label>
                        <select form="admin-sale-update-{{ $row['sale_id'] }}" id="admin_sale_method_{{ $row['sale_id'] }}" name="payment_method" required>
                            @foreach ($paymentMethods as $method)
                                <option value="{{ $method }}" @selected(old('payment_method', $row['payment_method']) === $method)>{{ $paymentMethodLabels[$method] ?? ucwords(str_replace('_', ' ', $method)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="admin_sale_terms_{{ $row['sale_id'] }}">Payment Terms</label>
                        <select form="admin-sale-update-{{ $row['sale_id'] }}" id="admin_sale_terms_{{ $row['sale_id'] }}" name="payment_terms">
                            @foreach ($paymentTerms as $term)
                                <option value="{{ $term }}" @selected(old('payment_terms', $row['payment_terms']) === $term)>{{ ucwords($term) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-row"><label for="admin_sale_due_{{ $row['sale_id'] }}">Due Date</label><input form="admin-sale-update-{{ $row['sale_id'] }}" id="admin_sale_due_{{ $row['sale_id'] }}" name="due_date" type="date" value="{{ old('due_date', $row['due_date']) }}"></div>
                    <div class="form-row">
                        <label for="admin_sale_status_{{ $row['sale_id'] }}">Status</label>
                        <select form="admin-sale-update-{{ $row['sale_id'] }}" id="admin_sale_status_{{ $row['sale_id'] }}" name="status">
                            @foreach ($editableSaleStatuses as $status)
                                <option value="{{ $status }}" @selected(old('status', $row['raw_status']) === $status)>{{ ucwords(str_replace('_', ' ', $status)) }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-pill btn-success" type="button" data-modal-open="{{ $row['payment_id'] }}">Payment History</button>
                <button class="btn btn-pill btn-secondary" type="submit" form="admin-sale-update-{{ $row['sale_id'] }}">Edit</button>
                <form method="POST" action="{{ route('admin.sales.cancel', $row['sale_id']) }}">
                    @csrf
                    @method('PATCH')
                    <button class="btn btn-pill btn-danger" type="submit">Delete</button>
                </form>
            </div>
        </x-admin.modal>

        <x-admin.modal id="{{ $row['payment_id'] }}" title="Payment History">
            <div class="modal-card">
                <p class="detail-id">{{ $row['cells'][0] }}</p>
                <div class="detail-grid">
                    <div class="detail-row"><div class="detail-label">Sale Total</div><div class="detail-value">PHP {{ $row['sale_total'] }}</div></div>
                    <div class="detail-row"><div class="detail-label">Total Paid</div><div class="detail-value">PHP {{ $row['total_paid'] }}</div></div>
                    <div class="detail-row"><div class="detail-label">Remaining Balance</div><div class="detail-value">PHP {{ $row['balance'] }}</div></div>
                    <div class="detail-row"><div class="detail-label">Payment Status</div><div class="detail-value">{{ $row['status'] }}</div></div>
                </div>
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
                <div class="table-wrap" style="margin-top: 18px">
                    <table class="admin-table" style="min-width: 560px">
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

    <x-admin.modal id="customer-add" title="Add Customers" wide>
        <div class="modal-card"><p class="detail-value">Customer records are monitored here and are managed in the Sales Officer workflow.</p></div>
        <div class="modal-actions"><button class="btn btn-pill btn-danger" type="button" data-modal-close>Close</button></div>
    </x-admin.modal>

    @foreach ($customers as $row)
        <x-admin.modal id="{{ $row['id'] }}" title="Customer Record">
            <div class="modal-card">
                <p class="detail-id">{{ $row['cells'][0] }}</p>
                <div class="detail-grid">
                    @foreach ($row['details'] as $label => $value)
                        <div class="detail-row"><div class="detail-label">{{ $label }}</div><div class="detail-value">{{ $value }}</div></div>
                    @endforeach
                </div>
            </div>
        </x-admin.modal>
    @endforeach
@endcomponent
