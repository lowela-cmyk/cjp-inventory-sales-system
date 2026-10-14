@php
    $activeTab = $activeTab ?? (($state ?? 'receivables') === 'customers' ? 'customers' : 'receivables');
    // The same page serves Sales Officers and Admin (who may perform every sales function).
    $isAdminSales = request()->routeIs('admin.*');
    $salesRoutePrefix = $isAdminSales ? 'admin' : 'sales-officer';
    $salesLayout = $isAdminSales ? 'layouts.admin' : 'layouts.sales-officer';
    $recordSaleFailed = old('form_context') === 'record-sale' && $errors->any();
    $addCustomerFailed = old('form_context') === 'add-customer' && $errors->any();
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

@component($salesLayout, ['title' => 'Sales Management', 'active' => 'sales'])
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
            <button class="btn btn-secondary" type="button" data-export-table>Export</button>
        </div>

        @if (! empty($summaryCards))
            <div class="metric-row">
                @foreach ($summaryCards as $card)
                    <div class="metric-card">
                        <em>{{ $card['label'] }}</em>
                        <strong>{{ $card['value'] }}</strong>
                        <span>{{ $card['caption'] }}</span>
                    </div>
                @endforeach
            </div>
        @endif

        <section data-tab-panel="receivables" @if ($activeTab !== 'receivables') hidden @endif>
            <form class="toolbar toolbar-narrow" method="GET" action="{{ route($salesRoutePrefix.'.sales') }}">
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
                            <th>Sales Order No.</th>
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
                            <th>Delivery Status</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($sales as $row)
                            <tr class="{{ $row['class'] }}">
                                @foreach ($row['cells'] as $cell)
                                    @if ($loop->last || $loop->index === count($row['cells']) - 2)
                                        <td><x-admin.status-badge :status="$cell" /></td>
                                    @else
                                        <td>{{ $cell }}</td>
                                    @endif
                                @endforeach
                                <td>
                                    <div class="sales-row-actions">
                                        <button class="btn btn-secondary" type="button" data-modal-open="{{ $row['modal_id'] }}">View</button>
                                        @if ($row['correction_action'])
                                            <form method="POST" action="{{ route($salesRoutePrefix.'.sales.cancel', $row['id']) }}" data-confirm-message="{{ $row['correction_action'] === 'delete' ? 'Delete this untouched draft sale? This cannot be undone.' : 'Cancel this sale? Related payments and transaction history will be preserved, while stock and receivable effects will be reversed.' }}">
                                                @csrf
                                                @method('PATCH')
                                                <button class="btn btn-danger" type="submit">{{ $row['correction_action'] === 'delete' ? 'Delete' : 'Cancel' }}</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="16">No records found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if (method_exists($sales, 'links'))
                <div class="pagination-wrap" style="margin-top: 16px;">
                    {{ $sales->links() }}
                </div>
            @endif
        </section>

        <section data-tab-panel="customers" @if ($activeTab !== 'customers') hidden @endif>
            <form class="toolbar toolbar-narrow" method="GET" action="{{ route($salesRoutePrefix.'.sales.customers') }}">
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
                            <th>Bank Name</th>
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
                            <tr><td class="empty-cell" colspan="9">No customer records found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if (method_exists($customers, 'links'))
                <div class="pagination-wrap" style="margin-top: 16px;">
                    {{ $customers->links() }}
                </div>
            @endif
        </section>
    </div>

    <x-admin.modal id="so-sales-add" title="Record Sales Receivables" wide>
        @if ($recordSaleFailed)
            <span hidden data-open-modal-on-load="so-sales-add"></span>
        @endif
        <form id="record-sale-form" method="POST" action="{{ route($salesRoutePrefix.'.sales.store') }}">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $saleIdempotencyKey) }}">
            <input type="hidden" name="form_context" value="record-sale">
            @if ($recordSaleFailed && $errors->hasAny(['sale', 'quantity_liters']))
                <p class="form-alert" role="alert">{{ $errors->first('sale') ?: $errors->first('quantity_liters') }}</p>
            @endif
            <div class="modal-card">
                <div class="form-grid">
                    <div class="form-row"><label for="sales_order_number">Sales Order Number</label><input id="sales_order_number" name="sales_order_number" type="text" maxlength="60" placeholder="Customer receipt/order number" value="{{ old('sales_order_number') }}"></div>
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
                                <div class="form-row sale-line-total"><label>Line Total</label><output data-sales-line-total>0.00</output></div>
                                <button class="btn btn-secondary sales-item-remove" type="button" data-sales-item-remove @disabled($loop->first && count($saleItemOldRows) === 1)>Remove Item</button>
                            </div>
                        @endforeach
                    </div>
                    <div class="sales-item-actions">
                        <button class="btn btn-primary" type="button" data-sales-item-add>Add Item</button>
                    </div>
                    <div class="sales-payment-summary" data-sales-payment-summary>
                        <div class="sales-payment-stat"><span>Total</span><output data-sales-total-preview>PHP 0.00</output></div>
                        <div class="sales-payment-stat"><span>Remaining Balance</span><output data-sales-balance-preview>PHP 0.00</output></div>
                        <div class="sales-payment-stat"><span>Payment Status</span><output data-sales-payment-status-preview>Unpaid</output></div>
                    </div>
                    <div class="form-row">
                        <label for="sale_amount_paid">Amount Payment</label>
                        <input id="sale_amount_paid" name="amount_paid" type="number" min="0" step="0.01" placeholder="Initial amount paid (optional)" value="{{ old('amount_paid', '0.00') }}" data-sales-amount-paid aria-describedby="sale_amount_paid_hint" @error('amount_paid') aria-invalid="true" @enderror>
                        <small class="form-hint" id="sale_amount_paid_hint">Recorded as the first payment when greater than 0. Cannot exceed the sale total.</small>
                        @error('amount_paid')<p class="field-error form-row-message">{{ $message }}</p>@enderror
                    </div>
                    <div class="form-row">
                        <label for="sale_payment_method">Payment Method</label>
                        <select id="sale_payment_method" name="payment_method" required>
                            @foreach ($paymentMethods as $method)
                                <option value="{{ $method }}" @selected(old('payment_method', 'cash_on_delivery') === $method)>{{ $paymentMethodLabels[$method] ?? ucwords(str_replace('_', ' ', $method)) }}</option>
                            @endforeach
                        </select>
                        @error('payment_method')<p class="field-error form-row-message">{{ $message }}</p>@enderror
                    </div>
                    <div class="form-row">
                        <label for="sale_payment_reference">Reference Number</label>
                        <input id="sale_payment_reference" name="payment_reference_number" type="text" maxlength="100" placeholder="Required for Cheque / Banking payments" value="{{ old('payment_reference_number') }}" @error('payment_reference_number') aria-invalid="true" @enderror>
                        @error('payment_reference_number')<p class="field-error form-row-message">{{ $message }}</p>@enderror
                    </div>
                    <div class="form-row">
                        <label for="sale_payment_date">Payment Date</label>
                        <input id="sale_payment_date" name="payment_date" type="date" value="{{ old('payment_date') }}" aria-describedby="sale_payment_date_hint" @error('payment_date') aria-invalid="true" @enderror>
                        <small class="form-hint" id="sale_payment_date_hint">Defaults to the transaction date when left blank.</small>
                        @error('payment_date')<p class="field-error form-row-message">{{ $message }}</p>@enderror
                    </div>
                    <div class="form-row">
                        <label for="sale_payment_remarks">Remarks</label>
                        <input id="sale_payment_remarks" name="payment_remarks" type="text" maxlength="1000" placeholder="Optional payment remarks" value="{{ old('payment_remarks') }}">
                        @error('payment_remarks')<p class="field-error form-row-message">{{ $message }}</p>@enderror
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
                    <div class="form-row"><label>Delivery Status</label><output>Pending &mdash; no Stock-Out until Delivered</output></div>
                </div>
            </div>
        </form>
        <x-slot:footer>
            <button class="btn btn-pill btn-secondary" type="submit" form="record-sale-form">Add</button>
            <button class="btn btn-pill btn-danger" type="button" data-modal-close>Cancel</button>
        </x-slot:footer>
    </x-admin.modal>

    @foreach ($sales as $row)
        @php
            // Old input and errors belong only to the sale whose Edit form was submitted.
            $isEditedSale = (int) old('edit_sale_id') === (int) $row['id'];
            $editOld = fn (string $key, mixed $default = null): mixed => $isEditedSale ? old($key, $default) : $default;
            $hasPayments = (float) str_replace(',', '', (string) $row['total_paid']) > 0;
        @endphp
        <x-admin.modal id="{{ $row['modal_id'] }}" title="Edit Sales Record">
            @if ($isEditedSale && $errors->any())
                <span hidden data-open-modal-on-load="{{ $row['modal_id'] }}"></span>
                <p class="form-alert" role="alert">{{ $errors->first() }}</p>
            @endif
            <form id="sale-update-{{ $row['id'] }}" method="POST" action="{{ route($salesRoutePrefix.'.sales.update', $row['id']) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="edit_sale_id" value="{{ $row['id'] }}">
            </form>
            @if (! in_array($row['delivery_status'], ['delivered', 'cancelled'], true))
                <form id="sale-delivery-{{ $row['id'] }}" method="POST" action="{{ route($salesRoutePrefix.'.sales.delivery-status.update', $row['id']) }}" data-confirm-message="Update this delivery status? Selecting Delivered will create Stock-Out and deduct inventory.">
                    @csrf
                    @method('PATCH')
                </form>
            @endif
            <div class="modal-card">
                <div class="sales-row-actions"><x-admin.status-badge :status="$row['delivery_status']" /><x-admin.status-badge :status="$row['cells'][14]" /></div>
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
                @if (! in_array($row['delivery_status'], ['delivered', 'cancelled'], true))
                    <div class="form-grid" style="margin-top:18px">
                        <div class="form-row">
                            <label for="sale_delivery_status_{{ $row['id'] }}">Delivery Status</label>
                            <select form="sale-delivery-{{ $row['id'] }}" id="sale_delivery_status_{{ $row['id'] }}" name="delivery_status">
                                @foreach ($editableDeliveryStatuses as $deliveryStatus)
                                    <option value="{{ $deliveryStatus }}" @selected($row['delivery_status'] === $deliveryStatus)>{{ ucwords(str_replace('_', ' ', $deliveryStatus)) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-row"><label>Inventory</label><button class="btn btn-primary" type="submit" form="sale-delivery-{{ $row['id'] }}">Update Delivery</button></div>
                    </div>
                @endif
                <div class="form-grid" style="margin-top:18px">
                    <div class="form-row"><label for="sales_order_number_{{ $row['id'] }}">Sales Order Number</label><input form="sale-update-{{ $row['id'] }}" id="sales_order_number_{{ $row['id'] }}" name="sales_order_number" type="text" maxlength="60" value="{{ $editOld('sales_order_number', $row['sales_order_number']) }}"></div>
                    <div class="form-row"><label for="sale_date_{{ $row['id'] }}">Transaction Date</label><input form="sale-update-{{ $row['id'] }}" id="sale_date_{{ $row['id'] }}" name="sale_date" type="date" value="{{ $editOld('sale_date', $row['sale_date']) }}" required></div>
                    <div class="form-row">
                        <label for="sale_customer_{{ $row['id'] }}">Customer Name</label>
                        <select form="sale-update-{{ $row['id'] }}" id="sale_customer_{{ $row['id'] }}" name="customer_id" required>
                            @foreach ($customerOptions as $customer)
                                <option value="{{ $customer->id }}" @selected((int) $editOld('customer_id', $row['customer_id']) === (int) $customer->id)>{{ $customer->name }} / {{ $customer->company_name }}</option>
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
                                            <option value="{{ $fuel->id }}" @selected((int) $editOld("items.$itemIndex.fuel_type_id", $item['fuel_type_id']) === (int) $fuel->id)>{{ $fuel->name }} (Garage: {{ number_format((float) $fuel->available_liters, 2) }} L)</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="form-row"><label for="sale_qty_{{ $row['id'] }}_{{ $itemIndex }}">Quantity</label><input form="sale-update-{{ $row['id'] }}" id="sale_qty_{{ $row['id'] }}_{{ $itemIndex }}" name="items[{{ $itemIndex }}][quantity_liters]" type="number" min="0.01" step="0.01" value="{{ $editOld("items.$itemIndex.quantity_liters", $item['quantity_liters']) }}" required></div>
                                <div class="form-row"><label for="sale_price_{{ $row['id'] }}_{{ $itemIndex }}">Price / Liter</label><input form="sale-update-{{ $row['id'] }}" id="sale_price_{{ $row['id'] }}_{{ $itemIndex }}" name="items[{{ $itemIndex }}][unit_price]" type="number" min="0.01" step="0.01" value="{{ $editOld("items.$itemIndex.unit_price", $item['unit_price']) }}" required></div>
                                <div class="form-row sale-line-total"><label>Line Total</label><output data-sales-line-total>0.00</output></div>
                                <button class="btn btn-secondary sales-item-remove" type="button" data-sales-item-remove @disabled($loop->first && count($row['items']) === 1)>Remove Item</button>
                            </div>
                        @endforeach
                    </div>
                    <div class="sales-item-actions">
                        <button class="btn btn-primary" type="button" data-sales-item-add>Add Item</button>
                        <output class="sales-total-preview" data-sales-total-preview>Total: 0.00</output>
                    </div>
                    <div class="form-row">
                        <label for="sale_method_{{ $row['id'] }}">Payment Method</label>
                        <select form="sale-update-{{ $row['id'] }}" id="sale_method_{{ $row['id'] }}" name="payment_method" required>
                            @foreach ($paymentMethods as $method)
                                <option value="{{ $method }}" @selected($editOld('payment_method', $row['payment_method']) === $method)>{{ $paymentMethodLabels[$method] ?? ucwords(str_replace('_', ' ', $method)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="sale_terms_{{ $row['id'] }}">Payment Terms</label>
                        <select form="sale-update-{{ $row['id'] }}" id="sale_terms_{{ $row['id'] }}" name="payment_terms">
                            @foreach ($paymentTerms as $term)
                                <option value="{{ $term }}" @selected($editOld('payment_terms', $row['payment_terms']) === $term)>{{ ucwords($term) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-row"><label for="sale_due_{{ $row['id'] }}">Due Date</label><input form="sale-update-{{ $row['id'] }}" id="sale_due_{{ $row['id'] }}" name="due_date" type="date" value="{{ $editOld('due_date', $row['due_date']) }}"></div>
                    <div class="form-row">
                        <label for="sale_status_{{ $row['id'] }}">Status</label>
                        @if ($hasPayments)
                            {{-- Status follows the recorded payments (Unpaid / Partially Paid / Paid), so it is not editable here. --}}
                            <output id="sale_status_{{ $row['id'] }}">{{ ucwords(str_replace('_', ' ', $row['status'])) }} (set by payments)</output>
                        @else
                            <select form="sale-update-{{ $row['id'] }}" id="sale_status_{{ $row['id'] }}" name="status">
                                @foreach ($editableSaleStatuses as $status)
                                    <option value="{{ $status }}" @selected($editOld('status', $row['status']) === $status)>{{ ucwords(str_replace('_', ' ', $status)) }}</option>
                                @endforeach
                            </select>
                        @endif
                    </div>
                </div>
            </div>
            <x-slot:footer>
                <button class="btn btn-pill btn-success" type="button" data-modal-open="{{ $row['payment_id'] }}">Payment History</button>
                @if (! in_array($row['status'], ['draft', 'cancelled'], true))
                    <button class="btn btn-pill btn-primary" type="button" data-open-payment-form="{{ $row['payment_id'] }}">+ Add Payment</button>
                @endif
                @if ($row['status'] !== 'cancelled')
                    <button class="btn btn-pill btn-secondary" type="submit" form="sale-update-{{ $row['id'] }}">Edit</button>
                @endif
                @if ($row['correction_action'])
                    <form method="POST" action="{{ route($salesRoutePrefix.'.sales.cancel', $row['id']) }}" data-confirm-message="{{ $row['correction_action'] === 'delete' ? 'Delete this untouched draft sale? This cannot be undone.' : 'Cancel this sale? Related payments and transaction history will be preserved, while stock and receivable effects will be reversed.' }}">
                        @csrf
                        @method('PATCH')
                        <button class="btn btn-pill btn-danger" type="submit">{{ $row['correction_action'] === 'delete' ? 'Delete' : 'Cancel' }}</button>
                    </form>
                @endif
            </x-slot:footer>
        </x-admin.modal>

        @include('admin.partials.payment-history-modal', [
            'row' => $row,
            'saleId' => $row['id'],
            'saleCode' => $row['sale_code'],
            'paymentStatus' => $row['cells'][14],
            'viewModalId' => $row['modal_id'],
            'paymentAction' => in_array($row['status'], ['draft', 'cancelled'], true) ? null : route($salesRoutePrefix.'.sales.payments.store', $row['id']),
        ])
    @endforeach

    <x-admin.modal id="so-customer-add" title="Add Customers" wide>
        @if ($addCustomerFailed)
            <span hidden data-open-modal-on-load="so-customer-add"></span>
            <p class="form-alert" role="alert">{{ $errors->first() }}</p>
        @endif
        <form id="customer-add-form" method="POST" action="{{ route($salesRoutePrefix.'.sales.customers.store') }}">
            @csrf
            <input type="hidden" name="form_context" value="add-customer">
            <div class="modal-card">
                <div class="form-grid">
                    <div class="form-row"><label for="customer_name">Customer Name</label><input id="customer_name" name="name" type="text" placeholder="Enter Customer Name" value="{{ old('name') }}" required></div>
                    <div class="form-row"><label for="customer_company_name">Company Name</label><input id="customer_company_name" name="company_name" type="text" placeholder="Enter Company Name" value="{{ old('company_name') }}" required></div>
                    <div class="form-row"><label for="customer_location">Location</label><input id="customer_location" name="location" type="text" placeholder="Enter Location" value="{{ old('location') }}"></div>
                    <div class="form-row"><label for="customer_email">Email</label><input id="customer_email" name="email" type="email" placeholder="Enter Email" value="{{ old('email') }}"></div>
                    <div class="form-row"><label for="customer_phone">Contact Number</label><input id="customer_phone" name="phone" type="tel" placeholder="Enter Contact Number" value="{{ old('phone') }}"></div>
                    <div class="form-row"><label for="customer_bank_name">Bank Name</label><input id="customer_bank_name" name="bank_name" type="text" maxlength="255" placeholder="Enter Bank Name" value="{{ old('bank_name') }}"></div>
                    <div class="form-row"><label for="customer_payment_status">Payment Status</label><select id="customer_payment_status" name="payment_status" required>@foreach ($paymentStatuses as $status)<option value="{{ $status }}" @selected(old('payment_status', 'clear') === $status)>{{ ucwords($status) }}</option>@endforeach</select></div>
                    <div class="form-row"><label for="customer_status">Status</label><select id="customer_status" name="status" required>@foreach ($accountStatuses as $status)<option value="{{ $status }}" @selected(old('status', 'active') === $status)>{{ ucwords($status) }}</option>@endforeach</select></div>
                </div>
            </div>
        </form>
        <x-slot:footer>
            <button class="btn btn-pill btn-secondary" type="submit" form="customer-add-form">Add</button>
            <button class="btn btn-pill btn-danger" type="button" data-modal-close>Cancel</button>
        </x-slot:footer>
    </x-admin.modal>

    @foreach ($customers as $row)
        @php
            $isEditedCustomer = (int) old('edit_customer_id') === (int) $row['id'];
            $customerOld = fn (string $key, mixed $default = null): mixed => $isEditedCustomer ? old($key, $default) : $default;
        @endphp
        <x-admin.modal id="{{ $row['modal_id'] }}" title="Edit Customer Record">
            @if ($isEditedCustomer && $errors->any())
                <span hidden data-open-modal-on-load="{{ $row['modal_id'] }}"></span>
                <p class="form-alert" role="alert">{{ $errors->first() }}</p>
            @endif
            <form id="customer-update-{{ $row['id'] }}" method="POST" action="{{ route($salesRoutePrefix.'.sales.customers.update', $row['id']) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="edit_customer_id" value="{{ $row['id'] }}">
            </form>
            <div class="modal-card">
                <x-admin.status-badge :status="ucwords($row['status'])" />
                <p class="detail-id">{{ $row['customer_code'] }}</p>
                <div class="detail-grid">
                    @foreach ($row['details'] as $label => $value)
                        <div class="detail-row"><div class="detail-label">{{ $label }}</div><div class="detail-value">{{ $value }}</div></div>
                    @endforeach
                </div>
                <div class="form-grid" style="margin-top: 18px">
                    <div class="form-row"><label for="customer_name_{{ $row['id'] }}">Customer Name</label><input form="customer-update-{{ $row['id'] }}" id="customer_name_{{ $row['id'] }}" name="name" type="text" value="{{ $customerOld('name', $row['name']) }}" required></div>
                    <div class="form-row"><label for="customer_company_name_{{ $row['id'] }}">Company Name</label><input form="customer-update-{{ $row['id'] }}" id="customer_company_name_{{ $row['id'] }}" name="company_name" type="text" value="{{ $customerOld('company_name', $row['company_name']) }}" required></div>
                    <div class="form-row"><label for="customer_location_{{ $row['id'] }}">Location</label><input form="customer-update-{{ $row['id'] }}" id="customer_location_{{ $row['id'] }}" name="location" type="text" value="{{ $customerOld('location', $row['location']) }}"></div>
                    <div class="form-row"><label for="customer_email_{{ $row['id'] }}">Email</label><input form="customer-update-{{ $row['id'] }}" id="customer_email_{{ $row['id'] }}" name="email" type="email" value="{{ $customerOld('email', $row['email']) }}"></div>
                    <div class="form-row"><label for="customer_phone_{{ $row['id'] }}">Contact Number</label><input form="customer-update-{{ $row['id'] }}" id="customer_phone_{{ $row['id'] }}" name="phone" type="tel" value="{{ $customerOld('phone', $row['phone']) }}"></div>
                    <div class="form-row"><label for="customer_bank_name_{{ $row['id'] }}">Bank Name</label><input form="customer-update-{{ $row['id'] }}" id="customer_bank_name_{{ $row['id'] }}" name="bank_name" type="text" maxlength="255" value="{{ $customerOld('bank_name', $row['bank_name']) }}"></div>
                    <div class="form-row"><label for="customer_payment_status_{{ $row['id'] }}">Payment Status</label><select form="customer-update-{{ $row['id'] }}" id="customer_payment_status_{{ $row['id'] }}" name="payment_status" required>@foreach ($paymentStatuses as $status)<option value="{{ $status }}" @selected($customerOld('payment_status', $row['payment_status']) === $status)>{{ ucwords($status) }}</option>@endforeach</select></div>
                    <div class="form-row"><label for="customer_status_{{ $row['id'] }}">Status</label><select form="customer-update-{{ $row['id'] }}" id="customer_status_{{ $row['id'] }}" name="status" required>@foreach ($accountStatuses as $status)<option value="{{ $status }}" @selected($customerOld('status', $row['status']) === $status)>{{ ucwords($status) }}</option>@endforeach</select></div>
                </div>
            </div>
            <x-slot:footer>
                <button class="btn btn-pill btn-secondary" type="submit" form="customer-update-{{ $row['id'] }}">Edit</button>
                <form method="POST" action="{{ route($salesRoutePrefix.'.sales.customers.deactivate', $row['id']) }}" data-confirm-message="Deactivate this customer? Existing sales stay unchanged.">
                    @csrf
                    @method('PATCH')
                    <button class="btn btn-pill btn-danger" type="submit">Delete</button>
                </form>
            </x-slot:footer>
        </x-admin.modal>
    @endforeach
@endcomponent
