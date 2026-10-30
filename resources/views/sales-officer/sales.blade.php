@php
    $activeTab = $activeTab ?? (($state ?? 'receivables') === 'customers' ? 'customers' : 'receivables');
    // The same page serves Sales Officers and Admin (who may perform every sales function).
    $isAdminSales = request()->routeIs('admin.*');
    $salesRoutePrefix = $isAdminSales ? 'admin' : 'sales-officer';
    $salesLayout = $isAdminSales ? 'layouts.admin' : 'layouts.sales-officer';
    $recordSaleFailed = old('form_context') === 'record-sale' && $errors->any();
    $addCustomerFailed = old('form_context') === 'add-customer' && $errors->any();
    // Part 2 revisions: Record Sales has one order line (items[0]).
    $recordItem = array_values(old('items', [[]]))[0] ?? [];
    $recordDeliveryType = in_array(old('delivery_type'), ['depot_to_client', 'garage_to_client'], true) ? old('delivery_type') : 'standard';
    $recordLineError = fn (string $field): ?string => old('form_context') === 'record-sale' && $errors->has("items.0.$field") ? $errors->first("items.0.$field") : null;
    $fuelCodes = $fuelTypes->pluck('code', 'id');
    $filters = $filters ?? [];
    $receivableFilters = $activeTab === 'receivables' ? $filters : [];
    $customerFilters = $activeTab === 'customers' ? $filters : [];
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


        <div class="tabs">
            <button class="tab-button {{ $activeTab === 'receivables' ? 'is-active' : '' }}" type="button" data-tab-target="receivables" data-tab-url="{{ route($salesRoutePrefix.'.sales') }}" data-heading="Receivables">Receivables</button>
            <button class="tab-button {{ $activeTab === 'customers' ? 'is-active' : '' }}" type="button" data-tab-target="customers" data-tab-url="{{ route($salesRoutePrefix.'.sales.customers') }}" data-heading="Customers">Customers</button>
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
            <x-filter.bar class="toolbar-narrow" :action="route($salesRoutePrefix.'.sales')">
                <x-filter.search label="Search receivables" :value="$search" />
                <x-filter.select name="status" label="Status" :options="$filterOptions['receivableStatuses']" :selected="$receivableFilters['status'] ?? null" />
                <x-filter.select name="delivery_status" label="Delivery Status" :options="$filterOptions['deliveryStatuses']" :selected="$receivableFilters['delivery_status'] ?? null" />
                <x-filter.select name="delivery_type" label="Delivery Type" :options="$filterOptions['deliveryTypes']" :selected="$receivableFilters['delivery_type'] ?? null" />
                <x-filter.date-range label="Transaction date" :from-value="$receivableFilters['date_from'] ?? null" :to-value="$receivableFilters['date_to'] ?? null" />
                <x-filter.select name="fuel_type_id" label="Fuel Type" :options="$fuelTypes->pluck('name', 'id')" :selected="$receivableFilters['fuel_type_id'] ?? null" />
                <button class="btn btn-secondary btn-wide" type="button" data-modal-open="so-sales-add">+ Record Sales</button>
            </x-filter.bar>
            <p class="table-help">Stock-Out is deducted from the order's purchases when a Standard order is Delivered, or when the lift of a Depot to Client order is completed.</p>
            <div class="table-wrap">
                <table class="admin-table table-stacked">
                    <thead>
                        <tr>
                            <th><span class="th-line" data-export-part>Order-ID</span><span class="th-line" data-export-part>Sales Order No.</span><span class="th-line" data-export-part>Transaction Date</span></th>
                            <th>Delivery Type</th>
                            <th><span class="th-line" data-export-part>Customer Name</span><span class="th-line" data-export-part>Company Name</span></th>
                            <th><span class="th-line" data-export-part>Fuel</span><span class="th-line" data-export-part>QTY</span><span class="th-line" data-export-part>Price / Liter</span></th>
                            <th><span class="th-line" data-export-part>Total</span><span class="th-line" data-export-part>Total Paid</span><span class="th-line" data-export-part>Balance</span></th>
                            <th><span class="th-line" data-export-part>Due Date</span><span class="th-line" data-export-part>Latest Payment</span></th>
                            {{-- Part 12: one status per column. --}}
                            <th>Delivery Status</th>
                            <th>Payment Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($sales as $row)
                            @php $c = $row['cells']; @endphp
                            <tr class="{{ $row['class'] }}">
                                <td><x-table.stack :lines="[['value' => $c[0]], ['label' => 'SO', 'value' => $c[1], 'class' => 'is-secondary'], ['value' => $c[2], 'class' => 'is-secondary']]" /></td>
                                <td>{{ $row['delivery_type_label'] }}</td>
                                <td class="cell-wrap"><x-table.stack :lines="[['value' => $c[3]], ['value' => $c[4], 'class' => 'is-secondary']]" /></td>
                                <td><x-table.stack :lines="[['value' => $c[5]], ['label' => 'Qty', 'value' => $c[6]], ['label' => '/L', 'value' => $c[7]]]" /></td>
                                <td><x-table.stack :lines="[['label' => 'Total', 'value' => $c[8]], ['label' => 'Paid', 'value' => $c[9]], ['label' => 'Balance', 'value' => $c[10], 'class' => (float) str_replace(',', '', $c[10]) > 0 ? 'is-due' : null]]" /></td>
                                <td><x-table.stack :lines="[['label' => 'Due', 'value' => $c[11]], ['label' => 'Last paid', 'value' => $c[12], 'class' => 'is-secondary']]" /></td>
                                <td><x-admin.status-badge :status="$c[13]" /></td>
                                <td><x-admin.status-badge :status="$c[14]" /></td>
                                <td>
                                    <div class="sales-row-actions row-actions-stack">
                                        <button class="btn btn-secondary" type="button" data-modal-open="{{ $row['modal_id'] }}">View</button>
                                        @if ($row['correction_action'] === 'delete')
                                            {{-- An untouched draft is discarded outright (not the delivered-order Delete below). --}}
                                            <form method="POST" action="{{ route($salesRoutePrefix.'.sales.cancel', $row['id']) }}" data-confirm-message="Discard this untouched draft sale? This cannot be undone.">
                                                @csrf
                                                @method('PATCH')
                                                <button class="btn btn-danger" type="submit">Discard Draft</button>
                                            </form>
                                        @endif
                                        {{-- Part 12: Cancel Sale moved into the Edit Sales Record modal. --}}
                                        {{-- Part 11: only Delivered orders can be deleted (the server enforces it too). --}}
                                        @if ($isAdminSales && $row['delivery_status'] === 'delivered')
                                            <form method="POST" action="{{ route($salesRoutePrefix.'.sales.destroy', $row['id']) }}" data-confirm-message="Delete order {{ $row['sale_code'] }}? It will be removed from Receivables, Stock Out and the sales totals. Its fuel stays deducted from its purchase.">
                                                @csrf
                                                @method('DELETE')
                                                <button class="btn btn-danger" type="submit">Delete</button>
                                            </form>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="9">No records found.</td></tr>
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
            <x-filter.bar class="toolbar-narrow" :action="route($salesRoutePrefix.'.sales.customers')">
                <x-filter.search label="Search customers" :value="$search" />
                <x-filter.select name="status" label="Status" :options="$filterOptions['accountStatuses']" :selected="$customerFilters['status'] ?? null" />
                <x-filter.select name="location" label="Location" :options="$filterOptions['locations']" :selected="$customerFilters['location'] ?? null" />
                <button class="btn btn-secondary btn-wide" type="button" data-modal-open="so-customer-add">+ Add Customers</button>
            </x-filter.bar>
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

    <x-admin.modal id="so-sales-add" reset-on-close title="Record Sales Receivables" size="sales-wide">
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
            {{-- Part 2 revisions layout (mockup): Order Details, Order Payment Details, then Delivery Details (Standard). --}}
            <div class="sale-form" data-sale-form data-f1-codes="{{ implode(',', $f1EligibleCodes) }}">
                <h3 class="sale-form-section-title">Order Details</h3>
                <div class="modal-card sale-form-card" data-sales-items>
                    <div class="sales-item-row sale-order-line" data-sales-item>
                        <div class="form-row form-row-full">
                            <label for="sale_delivery_type">Delivery Type</label>
                            <select id="sale_delivery_type" name="delivery_type" data-delivery-type data-default="standard" required @error('delivery_type') aria-invalid="true" @enderror>
                                @foreach ($deliveryTypes as $value => $label)
                                    <option value="{{ $value }}" @selected($recordDeliveryType === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            @error('delivery_type')<p class="field-error form-row-message">{{ $message }}</p>@enderror
                        </div>
                        <div class="sale-pair">
                            <div class="form-row">
                                <label for="sale_customer_id">Customer</label>
                                <select id="sale_customer_id" name="customer_id" required>
                                    <option value="">Select Customer</option>
                                    @foreach ($customerOptions as $customer)
                                        <option value="{{ $customer->id }}" @selected((string) old('customer_id') === (string) $customer->id)>{{ $customer->name }} / {{ $customer->company_name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="form-row"><label for="sales_order_number">Sales Order</label><input id="sales_order_number" name="sales_order_number" type="text" maxlength="60" placeholder="Enter Sales Order (e.g. SH0957043)" value="{{ old('sales_order_number') }}"></div>
                        </div>
                        <div class="sale-pair">
                            <div class="form-row"><label for="sale_date">Order Date</label><input id="sale_date" name="sale_date" type="date" value="{{ old('sale_date', now()->toDateString()) }}" data-default="{{ now()->toDateString() }}" required></div>
                            <div class="form-row"><label for="sale_quantity_liters_0">Qty Ordered</label><div class="input-suffix"><input id="sale_quantity_liters_0" name="items[0][quantity_liters]" type="number" min="0.01" step="0.01" placeholder="Enter Quantity" value="{{ $recordItem['quantity_liters'] ?? '' }}" data-sale-quantity required><span aria-hidden="true">L</span></div></div>
                        </div>
                        @include('sales-officer.partials.order-line', ['index' => 0, 'item' => $recordItem, 'formId' => null, 'idPrefix' => 'sale', 'lineError' => $recordLineError])
                    </div>
                </div>

                <h3 class="sale-form-section-title">Order Payment Details</h3>
                <div class="modal-card sale-form-card">
                    <div class="form-grid">
                        <div class="form-row">
                            <label for="sale_amount_paid">Amount Payment</label>
                            <input id="sale_amount_paid" name="amount_paid" type="number" min="0" step="0.01" placeholder="Enter Payment Amount" value="{{ old('amount_paid') }}" data-sales-amount-paid @error('amount_paid') aria-invalid="true" @enderror>
                            @error('amount_paid')<p class="field-error form-row-message">{{ $message }}</p>@enderror
                        </div>
                        <div class="form-row">
                            <label for="sale_payment_reference">Reference Number</label>
                            <input id="sale_payment_reference" name="payment_reference_number" type="text" maxlength="100" placeholder="Enter Reference Number" value="{{ old('payment_reference_number') }}" @error('payment_reference_number') aria-invalid="true" @enderror>
                            @error('payment_reference_number')<p class="field-error form-row-message">{{ $message }}</p>@enderror
                        </div>
                        <div class="form-row">
                            <label for="sale_payment_date">Date of Payment</label>
                            <input id="sale_payment_date" name="payment_date" type="date" value="{{ old('payment_date') }}" @error('payment_date') aria-invalid="true" @enderror>
                            @error('payment_date')<p class="field-error form-row-message">{{ $message }}</p>@enderror
                        </div>
                        <div class="form-row">
                            <label for="sale_payment_terms">Payment Terms</label>
                            <select id="sale_payment_terms" name="payment_terms">
                                <option value="" @selected(! old('payment_terms'))>Select Payment Terms</option>
                                @foreach ($paymentTerms as $term)
                                    <option value="{{ $term }}" @selected(old('payment_terms') === $term)>{{ ucwords($term) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-row">
                            <label for="sale_payment_method">Payment Method</label>
                            <select id="sale_payment_method" name="payment_method" required>
                                <option value="" @selected(! old('payment_method'))>Select Payment Method</option>
                                @foreach ($paymentMethods as $method)
                                    <option value="{{ $method }}" @selected(old('payment_method') === $method)>{{ $paymentMethodLabels[$method] ?? ucwords(str_replace('_', ' ', $method)) }}</option>
                                @endforeach
                            </select>
                            @error('payment_method')<p class="field-error form-row-message">{{ $message }}</p>@enderror
                        </div>
                        <div class="form-row"><label for="sale_due_date">Due Date</label><input id="sale_due_date" name="due_date" type="date" value="{{ old('due_date') }}"></div>
                        {{-- Part 9: read-only, worked out from the payment entered here (Unpaid / Partially Paid / Fully Paid). --}}
                        <div class="form-row"><label>Payment Status</label><output class="sale-readonly-field" data-sales-payment-status-preview>Unpaid</output></div>
                    </div>
                </div>

                {{-- Standard orders only: delivered by their own truck. Delivered deducts the stock from the purchases. --}}
                <div data-standard-only @if ($recordDeliveryType === 'depot_to_client') hidden @endif>
                    <h3 class="sale-form-section-title">Delivery Details</h3>
                    <div class="modal-card sale-form-card">
                        <div class="form-grid">
                            <div class="form-row">
                                <label for="sale_delivery_truck_id">Truck ID</label>
                                <select id="sale_delivery_truck_id" name="delivery_truck_id" @disabled($recordDeliveryType === 'depot_to_client') @error('delivery_truck_id') aria-invalid="true" @enderror>
                                    <option value="">Select Truck</option>
                                    @foreach ($deliveryTrucks as $truck)
                                        <option value="{{ $truck->id }}" @selected((string) old('delivery_truck_id') === (string) $truck->id)>{{ \App\Support\TruckLabel::make($truck->name, $truck->truck_code) }}</option>
                                    @endforeach
                                </select>
                                @error('delivery_truck_id')<p class="field-error form-row-message">{{ $message }}</p>@enderror
                            </div>
                            <div class="form-row">
                                <label for="sale_delivery_driver">Driver Name</label>
                                <select id="sale_delivery_driver" name="delivery_driver_user_id" @disabled($recordDeliveryType === 'depot_to_client') @error('delivery_driver_user_id') aria-invalid="true" @enderror>
                                    <option value="">Select Driver</option>
                                    @foreach ($deliveryDrivers as $driver)
                                        <option value="{{ $driver->id }}" @selected((string) old('delivery_driver_user_id') === (string) $driver->id)>{{ $driver->name }}</option>
                                    @endforeach
                                </select>
                                @error('delivery_driver_user_id')<p class="field-error form-row-message">{{ $message }}</p>@enderror
                            </div>
                            <div class="form-row">
                                <label for="sale_delivery_date">Delivery Date</label>
                                <input id="sale_delivery_date" name="delivery_date" type="datetime-local" value="{{ old('delivery_date') }}" @disabled($recordDeliveryType === 'depot_to_client')>
                            </div>
                            <div class="form-row">
                                <label for="sale_delivery_status">Delivery Status</label>
                                <select id="sale_delivery_status" name="delivery_status" data-default="pending" @disabled($recordDeliveryType === 'depot_to_client') @error('delivery_status') aria-invalid="true" @enderror>
                                    @foreach ($newOrderDeliveryStatuses as $deliveryStatus)
                                        <option value="{{ $deliveryStatus }}" @selected(old('delivery_status', 'pending') === $deliveryStatus)>{{ ucwords(str_replace('_', ' ', $deliveryStatus)) }}</option>
                                    @endforeach
                                </select>
                                @error('delivery_status')<p class="field-error form-row-message">{{ $message }}</p>@enderror
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
        <x-slot:footer>
            <button class="btn btn-pill btn-secondary" type="submit" form="record-sale-form">Add Order</button>
            <button class="btn btn-pill btn-danger" type="button" data-modal-close>Cancel</button>
        </x-slot:footer>
    </x-admin.modal>

    @foreach ($sales as $row)
        @php
            // Old input and errors belong only to the sale whose Edit form was submitted.
            $isEditedSale = (int) old('edit_sale_id') === (int) $row['id'];
            $editOld = fn (string $key, mixed $default = null): mixed => $isEditedSale ? old($key, $default) : $default;
            $hasPayments = (float) str_replace(',', '', (string) $row['total_paid']) > 0;
            // Part 12: a Delivered sale's order details are final (the server refuses changes too).
            $isDeliveredSale = $row['delivery_status'] === 'delivered';
        @endphp
        <x-admin.modal id="{{ $row['modal_id'] }}" title="Edit Sales Record" size="sales">
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
                        <thead><tr><th>Fuel</th><th>Purchase ID</th><th>QTY</th><th>F1 %</th><th>F1 (L)</th><th>Fuel (L)</th><th>Fuel Price</th><th>F1 Price</th><th>Line Total</th><th>Fulfilled</th></tr></thead>
                        <tbody>
                            @foreach ($row['items'] as $item)
                                <tr><td>{{ $item['fuel_name'] }}</td><td>{{ $item['source'] }}</td><td>{{ number_format((float) $item['quantity_liters'], 2) }}</td><td>{{ rtrim(rtrim(number_format($item['f1_percentage'], 2), '0'), '.') }}%</td><td>{{ number_format($item['f1_quantity_liters'], 2) }}</td><td>{{ number_format($item['main_quantity_liters'], 2) }}</td><td>{{ number_format((float) $item['unit_price'], 2) }}</td><td>{{ $item['f1_quantity_liters'] > 0 ? number_format($item['f1_unit_price'], 2) : '—' }}</td><td>{{ number_format((float) $item['line_total'], 2) }}</td><td>{{ number_format((float) $item['fulfilled_quantity_liters'], 2) }}</td></tr>
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
                    @if ($isDeliveredSale)
                        <input form="sale-update-{{ $row['id'] }}" type="hidden" name="sale_date" value="{{ $row['sale_date'] }}">
                        <input form="sale-update-{{ $row['id'] }}" type="hidden" name="customer_id" value="{{ $row['customer_id'] }}">
                        <div class="form-row"><label>Transaction Date</label><output>{{ $row['details']['Transaction Date'] ?? $row['sale_date'] }}</output></div>
                        <div class="form-row"><label>Customer Name</label><output>{{ $row['details']['Customer Name'] ?? '' }}</output></div>
                        <p class="sale-locked-note">Delivered: the order details are final. Order No., payment method / terms and due date can still change.</p>
                    @else
                    <div class="form-row"><label for="sale_date_{{ $row['id'] }}">Transaction Date</label><input form="sale-update-{{ $row['id'] }}" id="sale_date_{{ $row['id'] }}" name="sale_date" type="date" value="{{ $editOld('sale_date', $row['sale_date']) }}" required></div>
                    <div class="form-row">
                        <label for="sale_customer_{{ $row['id'] }}">Customer Name</label>
                        <select form="sale-update-{{ $row['id'] }}" id="sale_customer_{{ $row['id'] }}" name="customer_id" required>
                            @foreach ($customerOptions as $customer)
                                <option value="{{ $customer->id }}" @selected((int) $editOld('customer_id', $row['customer_id']) === (int) $customer->id)>{{ $customer->name }} / {{ $customer->company_name }}</option>
                            @endforeach
                        </select>
                    </div>
                    @endif
                    <div class="form-row"><label>Delivery Type</label><output>{{ $row['delivery_type_label'] }}</output></div>
                    @unless ($isDeliveredSale)
                    <div class="sales-items" data-sales-items>
                        @foreach ($row['items'] as $itemIndex => $item)
                            @php
                                $editItem = [
                                    'purchase_item_id' => $editOld("items.$itemIndex.purchase_item_id", $item['purchase_item_id']),
                                    'unit_price' => $editOld("items.$itemIndex.unit_price", $item['unit_price']),
                                    'f1_purchase_item_id' => $editOld("items.$itemIndex.f1_purchase_item_id", $item['f1_purchase_item_id']),
                                    'garage_stock_id' => $item['garage_stock_id'],
                                    'f1_garage_stock_id' => $item['f1_garage_stock_id'],
                                    'f1_unit_price' => $editOld("items.$itemIndex.f1_unit_price", $item['f1_unit_price']),
                                    'f1_percentage' => $editOld("items.$itemIndex.f1_percentage", $item['f1_percentage']),
                                    // A line recorded before purchases were chosen keeps its fuel until a purchase is picked.
                                    'legacy' => ! $item['purchase_item_id'],
                                    'fuel_type_id' => $item['fuel_type_id'],
                                    'fuel_name' => $item['fuel_name'],
                                    'fuel_code' => $item['fuel_code'],
                                ];
                                $editLineError = fn (string $field): ?string => $isEditedSale && $errors->has("items.$itemIndex.$field") ? $errors->first("items.$itemIndex.$field") : null;
                            @endphp
                            <div class="sales-item-row" data-sales-item>
                                <div class="form-row"><label for="sale_qty_{{ $row['id'] }}_{{ $itemIndex }}">Qty Ordered</label><input form="sale-update-{{ $row['id'] }}" id="sale_qty_{{ $row['id'] }}_{{ $itemIndex }}" name="items[{{ $itemIndex }}][quantity_liters]" type="number" min="0.01" step="0.01" value="{{ $editOld("items.$itemIndex.quantity_liters", $item['quantity_liters']) }}" data-sale-quantity required></div>
                                @include('sales-officer.partials.order-line', ['index' => $itemIndex, 'item' => $editItem, 'formId' => 'sale-update-'.$row['id'], 'idPrefix' => 'sale_'.$row['id'], 'lineError' => $editLineError, 'lineDeliveryType' => $row['delivery_type']])
                            </div>
                        @endforeach
                    </div>
                    <div class="sales-item-actions">
                        <output class="sales-total-preview" data-sales-total-preview>Total: 0.00</output>
                    </div>
                    @endunless
                    @if ($row['delivery_type'] !== 'depot_to_client' && $isDeliveredSale)
                        <div class="form-row"><label>Truck ID</label><output>{{ $row['details']['Truck ID'] ?? 'N/A' }}</output></div>
                        <div class="form-row"><label>Driver Name</label><output>{{ $row['details']['Driver Name'] ?? 'N/A' }}</output></div>
                        <div class="form-row"><label>Delivery Date</label><output>{{ $row['details']['Delivery Date'] ?? 'N/A' }}</output></div>
                    @elseif ($row['delivery_type'] !== 'depot_to_client')
                        {{-- Standard orders: Delivery Details. --}}
                        <div class="form-row">
                            <label for="sale_delivery_truck_{{ $row['id'] }}">Truck ID</label>
                            <select form="sale-update-{{ $row['id'] }}" id="sale_delivery_truck_{{ $row['id'] }}" name="delivery_truck_id">
                                <option value="">Select Truck</option>
                                @foreach ($deliveryTrucks as $truck)
                                    <option value="{{ $truck->id }}" @selected((string) $editOld('delivery_truck_id', $row['delivery_truck_id']) === (string) $truck->id)>{{ \App\Support\TruckLabel::make($truck->name, $truck->truck_code) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-row">
                            <label for="sale_delivery_driver_{{ $row['id'] }}">Driver Name</label>
                            <select form="sale-update-{{ $row['id'] }}" id="sale_delivery_driver_{{ $row['id'] }}" name="delivery_driver_user_id">
                                <option value="">Select Driver</option>
                                @foreach ($deliveryDrivers as $driver)
                                    <option value="{{ $driver->id }}" @selected((string) $editOld('delivery_driver_user_id', $row['delivery_driver_user_id']) === (string) $driver->id)>{{ $driver->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="form-row"><label for="sale_delivery_date_{{ $row['id'] }}">Delivery Date</label><input form="sale-update-{{ $row['id'] }}" id="sale_delivery_date_{{ $row['id'] }}" name="delivery_date" type="datetime-local" value="{{ $editOld('delivery_date', $row['delivery_date']) }}"></div>
                    @endif
                    <div class="form-row">
                        <label for="sale_method_{{ $row['id'] }}">Payment Method</label>
                        <select form="sale-update-{{ $row['id'] }}" id="sale_method_{{ $row['id'] }}" name="payment_method" required>
                            {{-- A retired method (e.g. Advance Payment) stays selectable only on the sale that already uses it. --}}
                            @if (! in_array($row['payment_method'], $paymentMethods, true))
                                <option value="{{ $row['payment_method'] }}" @selected($editOld('payment_method', $row['payment_method']) === $row['payment_method'])>{{ ($paymentMethodLabels[$row['payment_method']] ?? ucwords(str_replace('_', ' ', (string) $row['payment_method']))).' (legacy)' }}</option>
                            @endif
                            @foreach ($paymentMethods as $method)
                                <option value="{{ $method }}" @selected($editOld('payment_method', $row['payment_method']) === $method)>{{ $paymentMethodLabels[$method] ?? ucwords(str_replace('_', ' ', $method)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="sale_terms_{{ $row['id'] }}">Payment Terms</label>
                        <select form="sale-update-{{ $row['id'] }}" id="sale_terms_{{ $row['id'] }}" name="payment_terms">
                            @if ($row['payment_terms'] && ! in_array($row['payment_terms'], $paymentTerms, true))
                                <option value="{{ $row['payment_terms'] }}" @selected($editOld('payment_terms', $row['payment_terms']) === $row['payment_terms'])>{{ ($row['payment_terms'] === 'cod' ? 'COD' : ucwords($row['payment_terms'])).' (legacy)' }}</option>
                            @endif
                            @foreach ($paymentTerms as $term)
                                <option value="{{ $term }}" @selected($editOld('payment_terms', $row['payment_terms']) === $term)>{{ ucwords($term) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-row"><label for="sale_due_{{ $row['id'] }}">Due Date</label><input form="sale-update-{{ $row['id'] }}" id="sale_due_{{ $row['id'] }}" name="due_date" type="date" value="{{ $editOld('due_date', $row['due_date']) }}"></div>
                    <div class="form-row"><label>Payment Status</label><output data-payment-status>{{ $row['payment_status'] }}</output></div>
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
                @if (! in_array($row['status'], ['draft', 'cancelled'], true) && (float) str_replace(',', '', (string) $row['balance']) > 0)
                    <button class="btn btn-pill btn-primary" type="button" data-open-payment-form="{{ $row['payment_id'] }}">+ Add Payment</button>
                @endif
                @if ($row['status'] !== 'cancelled')
                    <button class="btn btn-pill btn-secondary" type="submit" form="sale-update-{{ $row['id'] }}">Apply Changes</button>
                @endif
                @if ($row['correction_action'] === 'delete')
                    <form method="POST" action="{{ route($salesRoutePrefix.'.sales.cancel', $row['id']) }}" data-confirm-message="Delete this untouched draft sale? This cannot be undone.">
                        @csrf
                        @method('PATCH')
                        <button class="btn btn-pill btn-danger" type="submit">Delete</button>
                    </form>
                @elseif ($row['correction_action'] === 'cancel')
                    {{-- Part 12: cancelling lives here (full name, so it is not read as closing the modal); it still asks for a reason. --}}
                    <button class="btn btn-pill btn-danger" type="button" data-modal-swap="so-sale-cancel-{{ $row['id'] }}">Cancel Sale</button>
                @endif
                <button class="btn btn-pill btn-secondary" type="button" data-modal-close>Close</button>
            </x-slot:footer>
        </x-admin.modal>

        @if ($row['correction_action'] === 'cancel')
            @include('sales-officer.partials.cancel-sale-modal', ['row' => $row, 'salesRoutePrefix' => $salesRoutePrefix])
        @endif

        @include('admin.partials.payment-history-modal', [
            'row' => $row,
            'saleId' => $row['id'],
            'saleCode' => $row['sale_code'],
            'paymentStatus' => $row['cells'][14],
            'viewModalId' => $row['modal_id'],
            'paymentAction' => in_array($row['status'], ['draft', 'cancelled'], true) ? null : route($salesRoutePrefix.'.sales.payments.store', $row['id']),
        ])
    @endforeach

    <x-admin.modal id="so-customer-add" reset-on-close title="Add Customers" wide>
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
                    <div class="form-row"><label for="customer_payment_status">Payment Status</label><select id="customer_payment_status" name="payment_status" required data-default="clear">@foreach ($paymentStatuses as $status)<option value="{{ $status }}" @selected(old('payment_status', 'clear') === $status)>{{ ucwords($status) }}</option>@endforeach</select></div>
                    <div class="form-row"><label for="customer_status">Status</label><select id="customer_status" name="status" required data-default="active">@foreach ($accountStatuses as $status)<option value="{{ $status }}" @selected(old('status', 'active') === $status)>{{ ucwords($status) }}</option>@endforeach</select></div>
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
                <button class="btn btn-pill btn-secondary" type="submit" form="customer-update-{{ $row['id'] }}">Apply Changes</button>
                <form method="POST" action="{{ route($salesRoutePrefix.'.sales.customers.deactivate', $row['id']) }}" data-confirm-message="Deactivate this customer? Existing sales stay unchanged.">
                    @csrf
                    @method('PATCH')
                    <button class="btn btn-pill btn-danger" type="submit">Delete</button>
                </form>
            </x-slot:footer>
        </x-admin.modal>
    @endforeach
@endcomponent
