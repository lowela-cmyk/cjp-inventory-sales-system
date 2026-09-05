@php
    $activeTab = in_array($activeTab ?? 'purchases', ['purchases', 'stock-in', 'stock-out'], true) ? $activeTab : 'purchases';
@endphp

@component('layouts.inventory-officer', ['title' => 'Inventory Management', 'active' => 'inventory'])
    <div data-tabs>
        <h2 class="section-title" data-tab-heading>{{ ['purchases' => 'Purchases', 'stock-in' => 'Stock-In', 'stock-out' => 'Stock-Out'][$activeTab] }}</h2>

        @if (session('status'))
            <div class="admin-flash admin-flash-success" role="status">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="admin-flash admin-flash-error" role="alert">{{ $errors->first() }}</div>
        @endif

        <div class="tabs">
            <button class="tab-button {{ $activeTab === 'purchases' ? 'is-active' : '' }}" type="button" data-tab-target="purchases" data-heading="Purchases">Purchases</button>
            <button class="tab-button {{ $activeTab === 'stock-in' ? 'is-active' : '' }}" type="button" data-tab-target="stock-in" data-heading="Stock-In">Stock-In</button>
            <button class="tab-button {{ $activeTab === 'stock-out' ? 'is-active' : '' }}" type="button" data-tab-target="stock-out" data-heading="Stock-Out">Stock Out</button>
        </div>
        <div class="actions-right"><button class="btn btn-secondary" type="button" data-export-table>Export</button></div>

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

        <section data-tab-panel="purchases" @hidden($activeTab !== 'purchases')>
            <form class="toolbar" method="GET" action="{{ route('inventory-officer.inventory') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search purchases" value="{{ $search }}">
                <button class="btn btn-primary" type="submit">Status</button>
                <button class="btn btn-primary" type="submit">Date</button>
                <button class="btn btn-primary" type="submit">Depot</button>
                <button class="btn btn-primary" type="submit">Fuel Type (All)</button>
                <button class="btn btn-primary" type="button" data-modal-open="io-purchase-add">+ Record Purchases</button>
            </form>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Purchase-ID</th><th>Date</th><th>Fuel</th><th>Depot</th><th>Purchased (L)</th><th>Hauled (L)</th><th>Garage Allocation</th><th>Direct Allocation</th><th>Received</th><th>Inventory Status</th><th>Cost / Liter</th><th>Total Cost</th><th>Delivery Receipt</th><th>Payment Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        @forelse ($purchases as $row)
                            <tr class="{{ $row['class'] }}">
                                @foreach ($row['cells'] as $cell)
                                    @if ($loop->last || $loop->index === 9)
                                        <td><x-admin.status-badge :status="$cell" /></td>
                                    @elseif ($loop->index === 12 && $row['receipt_url'])
                                        <td><a href="{{ $row['receipt_url'] }}">{{ $cell }}</a></td>
                                    @else
                                        <td>{{ $cell }}</td>
                                    @endif
                                @endforeach
                                <td><button class="btn btn-secondary" type="button" data-modal-open="{{ $row['modal_id'] }}">Edit</button></td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="15">No records found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section data-tab-panel="stock-in" @hidden($activeTab !== 'stock-in')>
            <form class="toolbar" method="GET" action="{{ route('inventory-officer.inventory.stock-in') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search stock-in" value="{{ $search }}">
                <span></span>
                <button class="btn btn-primary" type="submit">Status</button>
                <button class="btn btn-primary" type="submit">Date</button>
                <button class="btn btn-primary" type="submit">Depot</button>
                <button class="btn btn-primary" type="submit">Fuel Type (All)</button>
                <button class="btn btn-primary" type="button" data-modal-open="io-stockin-add">+ Record Stock-In</button>
            </form>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Purchase / Haul</th><th>Received Date</th><th>Fuel</th><th>Garage</th><th>QTY Received (L)</th><th>Cost / Liter</th><th>Total Cost</th><th>Stock In</th><th>Stock Out</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        @forelse ($stockIn as $row)
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
                            <tr><td class="empty-cell" colspan="11">No records found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section data-tab-panel="stock-out" @hidden($activeTab !== 'stock-out')>
            <form class="toolbar" method="GET" action="{{ route('inventory-officer.inventory.stock-out') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search stock-out" value="{{ $search }}">
                <button class="btn btn-primary" type="submit">Status</button>
                <button class="btn btn-primary" type="submit">Date</button>
                <button class="btn btn-primary" type="submit">Depot</button>
                <button class="btn btn-primary" type="submit">Fuel Type (All)</button>
                <button class="btn btn-primary" type="button" data-modal-open="io-stockout-add">+ Record Stock-Out</button>
            </form>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Order-ID</th><th>Transaction Date</th><th>Customer Name</th><th>Company Name</th><th>Fuel</th><th>QTY Released</th><th>Price / Unit</th><th>Total Price</th><th>Total Paid</th><th>Source</th><th>Total Cost</th><th>Profit</th></tr></thead>
                    <tbody>
                        @forelse ($stockOut as $row)
                            <tr class="{{ $row[12] }}"><td>{{ $row[0] }}</td><td>{{ $row[1] }}</td><td>{{ $row[2] }}</td><td>{{ $row[3] }}</td><td>{{ $row[4] }}</td><td>{{ $row[5] }}</td><td>{{ $row[6] }}</td><td>{{ $row[7] }}</td><td>{{ $row[8] }}</td><td>{{ $row[9] }}</td><td>{{ $row[10] }}</td><td>{{ $row[11] }}</td></tr>
                        @empty
                            <tr><td class="empty-cell" colspan="12">No records found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <x-admin.modal id="io-purchase-add" title="Add Purchase Record" wide>
        <form method="POST" action="{{ route('inventory-officer.inventory.purchases.store') }}" enctype="multipart/form-data">
            @csrf
            <div class="modal-card">
                <div class="form-grid">
                    <div class="form-row"><label for="purchase_date">Date</label><input id="purchase_date" name="purchase_date" type="date" value="{{ old('purchase_date', now()->toDateString()) }}" required></div>
                    <div class="form-row"><label for="fuel_type_id">Fuel Type</label><select id="fuel_type_id" name="fuel_type_id" required><option value="" disabled @selected(! old('fuel_type_id'))>Select Fuel Type</option>@foreach ($fuelTypes as $fuelType)<option value="{{ $fuelType->id }}" @selected((string) old('fuel_type_id') === (string) $fuelType->id)>{{ $fuelType->name }}</option>@endforeach</select></div>
                    <div class="form-row"><label for="depot_id">Depot</label><select id="depot_id" name="depot_id" required><option value="" disabled @selected(! old('depot_id'))>Select Depot</option>@foreach ($depots as $depot)<option value="{{ $depot->id }}" @selected((string) old('depot_id') === (string) $depot->id)>{{ $depot->name }}</option>@endforeach</select></div>
                    <div class="form-row"><label for="quantity_ordered_liters">Quantity (Liters)</label><input id="quantity_ordered_liters" name="quantity_ordered_liters" type="number" min="0.01" step="0.01" placeholder="Enter Quantity (Liters)" value="{{ old('quantity_ordered_liters') }}" required></div>
                    <div class="form-row"><label for="receipt_file">Delivery Receipt</label><input id="receipt_file" name="receipt_file" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"></div>
                    <div class="form-row"><label for="unit_cost">Cost / Liter</label><input id="unit_cost" name="unit_cost" type="number" min="0" step="0.01" placeholder="Enter Cost / Liter" value="{{ old('unit_cost') }}" required></div>
                    <div class="form-row"><label for="payment_status">Payment Status</label><select id="payment_status" name="payment_status" required>@foreach ($paymentStatuses as $status)<option value="{{ $status }}" @selected(old('payment_status', 'unpaid') === $status)>{{ ucwords($status) }}</option>@endforeach</select></div>
                    <div class="form-row"><label for="purchase_status">Status</label><select id="purchase_status" name="status" required>@foreach ($purchaseStatuses as $status)<option value="{{ $status }}" @selected(old('status', 'ordered') === $status)>{{ ucwords(str_replace('_', ' ', $status)) }}</option>@endforeach</select></div>
                </div>
            </div>
            <div class="modal-actions"><button class="btn btn-pill btn-secondary" type="submit">Add</button><button class="btn btn-pill btn-danger" type="button" data-modal-close>Cancel</button></div>
        </form>
    </x-admin.modal>

    @foreach ($purchases as $row)
        <x-admin.modal id="{{ $row['modal_id'] }}" title="Edit Purchase Record">
            <form id="purchase-update-{{ $row['id'] }}" method="POST" action="{{ route('inventory-officer.inventory.purchases.update', $row['id']) }}" enctype="multipart/form-data">
                @csrf
                @method('PATCH')
            </form>
            <div class="modal-card">
                <span class="detail-status" style="{{ $row['payment_status'] === 'unpaid' ? 'color:#f50037' : '' }}">{{ ucwords($row['payment_status']) }}</span>
                <p class="detail-id">{{ $row['purchase_code'] }}</p>
                <div class="detail-grid">
                    @foreach ($row['details'] as $label => $value)
                        <div class="detail-row">
                            <div class="detail-label">{{ $label }}</div>
                            <div class="detail-value">
                                @if ($label === 'Delivery Receipt' && $row['receipt_url'])
                                    <a href="{{ $row['receipt_url'] }}">{{ $value }}</a>
                                @else
                                    {{ $value }}
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="form-grid" style="margin-top: 18px">
                    <div class="form-row"><label for="purchase_date_{{ $row['id'] }}">Date</label><input form="purchase-update-{{ $row['id'] }}" id="purchase_date_{{ $row['id'] }}" name="purchase_date" type="date" value="{{ old('purchase_date', $row['purchase_date']) }}" required></div>
                    <div class="form-row"><label for="fuel_type_id_{{ $row['id'] }}">Fuel Type</label><select form="purchase-update-{{ $row['id'] }}" id="fuel_type_id_{{ $row['id'] }}" name="fuel_type_id" required>@foreach ($fuelTypes as $fuelType)<option value="{{ $fuelType->id }}" @selected((string) old('fuel_type_id', $row['fuel_type_id']) === (string) $fuelType->id)>{{ $fuelType->name }}</option>@endforeach</select></div>
                    <div class="form-row"><label for="depot_id_{{ $row['id'] }}">Depot</label><select form="purchase-update-{{ $row['id'] }}" id="depot_id_{{ $row['id'] }}" name="depot_id" required>@foreach ($depots as $depot)<option value="{{ $depot->id }}" @selected((string) old('depot_id', $row['depot_id']) === (string) $depot->id)>{{ $depot->name }}</option>@endforeach</select></div>
                    <div class="form-row"><label for="quantity_ordered_liters_{{ $row['id'] }}">Quantity (Liters)</label><input form="purchase-update-{{ $row['id'] }}" id="quantity_ordered_liters_{{ $row['id'] }}" name="quantity_ordered_liters" type="number" min="0.01" step="0.01" value="{{ old('quantity_ordered_liters', $row['quantity_ordered_liters']) }}" required></div>
                    <div class="form-row"><label for="receipt_file_{{ $row['id'] }}">Delivery Receipt</label><input form="purchase-update-{{ $row['id'] }}" id="receipt_file_{{ $row['id'] }}" name="receipt_file" type="file" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"></div>
                    <div class="form-row"><label for="unit_cost_{{ $row['id'] }}">Cost / Liter</label><input form="purchase-update-{{ $row['id'] }}" id="unit_cost_{{ $row['id'] }}" name="unit_cost" type="number" min="0" step="0.01" value="{{ old('unit_cost', $row['unit_cost']) }}" required></div>
                    <div class="form-row"><label for="payment_status_{{ $row['id'] }}">Payment Status</label><select form="purchase-update-{{ $row['id'] }}" id="payment_status_{{ $row['id'] }}" name="payment_status" required>@foreach ($paymentStatuses as $status)<option value="{{ $status }}" @selected(old('payment_status', $row['payment_status']) === $status)>{{ ucwords($status) }}</option>@endforeach</select></div>
                    <div class="form-row"><label for="purchase_status_{{ $row['id'] }}">Status</label><select form="purchase-update-{{ $row['id'] }}" id="purchase_status_{{ $row['id'] }}" name="status" required>@foreach ($purchaseStatuses as $status)<option value="{{ $status }}" @selected(old('status', $row['purchase_status']) === $status)>{{ ucwords(str_replace('_', ' ', $status)) }}</option>@endforeach</select></div>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-pill btn-secondary" type="submit" form="purchase-update-{{ $row['id'] }}">Edit</button>
                <form method="POST" action="{{ route('inventory-officer.inventory.purchases.cancel', $row['id']) }}">
                    @csrf
                    @method('PATCH')
                    <button class="btn btn-pill btn-danger" type="submit">Cancel</button>
                </form>
            </div>
        </x-admin.modal>
    @endforeach

    <x-admin.modal id="io-stockin-add" title="Edit Stock-In Record" wide>
        <form method="POST" action="{{ route('inventory-officer.inventory.stock-in.store') }}">
            @csrf
            <div class="modal-card">
                <div class="form-grid">
                    <div class="form-row"><label for="haul_allocation_id">Source Allocation</label><select id="haul_allocation_id" name="haul_allocation_id" required><option value="" disabled @selected(! old('haul_allocation_id'))>Select Allocation</option>@foreach ($garageAllocations as $allocation)<option value="{{ $allocation->id }}" @selected((string) old('haul_allocation_id') === (string) $allocation->id)>{{ $allocation->label }}</option>@endforeach</select></div>
                    <div class="form-row"><label for="stock_in_storage_location_id">Garage</label><select id="stock_in_storage_location_id" name="storage_location_id" required><option value="" disabled @selected(! old('storage_location_id'))>Select Garage</option>@foreach ($garages as $garage)<option value="{{ $garage->id }}" @selected((string) old('storage_location_id') === (string) $garage->id)>{{ $garage->name }}</option>@endforeach</select></div>
                    <div class="form-row"><label for="stock_in_quantity_liters">Quantity (Liters)</label><input id="stock_in_quantity_liters" name="quantity_liters" type="number" min="0.01" step="0.01" placeholder="Enter Quantity (Liters)" value="{{ old('quantity_liters') }}" required></div>
                    <div class="form-row"><label for="stock_in_movement_date">Date</label><input id="stock_in_movement_date" name="movement_date" type="datetime-local" value="{{ old('movement_date', now()->format('Y-m-d\TH:i')) }}" required></div>
                    <div class="form-row"><label for="stock_in_remarks">Remarks</label><input id="stock_in_remarks" name="remarks" type="text" placeholder="Enter Remarks" value="{{ old('remarks') }}"></div>
                </div>
            </div>
            <div class="modal-actions"><button class="btn btn-pill btn-secondary" type="submit">Add</button><button class="btn btn-pill btn-danger" type="button" data-modal-close>Cancel</button></div>
        </form>
    </x-admin.modal>

    @foreach ($stockIn as $row)
        <x-admin.modal id="{{ $row['modal_id'] }}" title="Edit Stock-In Record">
            <div class="modal-card">
                <p class="detail-id">{{ $row['cells'][0] }}</p>
                <div class="detail-grid">
                    @foreach ($row['details'] as $label => $value)
                        <div class="detail-row"><div class="detail-label">{{ $label }}</div><div class="detail-value">{{ $value }}</div></div>
                    @endforeach
                </div>
            </div>
            <div class="modal-actions"><button class="btn btn-pill btn-secondary" type="button" data-modal-close>Close</button></div>
        </x-admin.modal>
    @endforeach

    <x-admin.modal id="io-stockout-add" title="Record Stock-Out" wide>
        <form method="POST" action="{{ route('inventory-officer.inventory.stock-out.store') }}">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $stockOutIdempotencyKey) }}">
            <div class="modal-card">
                <div class="form-grid">
                    <div class="form-row"><label for="stock_out_source_type">Source</label><select id="stock_out_source_type" name="source_type" required><option value="garage" @selected(old('source_type', 'garage') === 'garage')>Garage Inventory</option><option value="depot" @selected(old('source_type') === 'depot')>Direct Depot Release</option></select></div>
                    <div class="form-row"><label for="stock_out_sale_item_id">Sale / Item</label><select id="stock_out_sale_item_id" name="sale_item_id" required><option value="" disabled @selected(! old('sale_item_id'))>Select Sale Item</option>@foreach ($stockOutSaleItems as $item)<option value="{{ $item->id }}" @selected((string) old('sale_item_id') === (string) $item->id)>{{ $item->label }}</option>@endforeach</select></div>
                    <div class="form-row"><label for="stock_out_storage_location_id">Garage</label><select id="stock_out_storage_location_id" name="storage_location_id"><option value="" @selected(! old('storage_location_id'))>Select Garage</option>@foreach ($garages as $garage)<option value="{{ $garage->id }}" @selected((string) old('storage_location_id') === (string) $garage->id)>{{ $garage->name }}</option>@endforeach</select></div>
                    <div class="form-row"><label for="stock_out_haul_allocation_id">Depot Allocation</label><select id="stock_out_haul_allocation_id" name="haul_allocation_id"><option value="" @selected(! old('haul_allocation_id'))>Select Direct Allocation</option>@foreach ($directDeliveryAllocations as $allocation)<option value="{{ $allocation->id }}" @selected((string) old('haul_allocation_id') === (string) $allocation->id)>{{ $allocation->label }}</option>@endforeach</select></div>
                    <div class="form-row"><label for="stock_out_quantity_liters">Quantity (Liters)</label><input id="stock_out_quantity_liters" name="quantity_liters" type="number" min="0.01" step="0.01" placeholder="Enter Quantity (Liters)" value="{{ old('quantity_liters') }}" required></div>
                    <div class="form-row"><label for="stock_out_at">Date</label><input id="stock_out_at" name="stock_out_at" type="datetime-local" value="{{ old('stock_out_at', now()->format('Y-m-d\TH:i')) }}" required></div>
                    <div class="form-row"><label for="stock_out_remarks">Remarks</label><input id="stock_out_remarks" name="remarks" type="text" placeholder="Enter Remarks" value="{{ old('remarks') }}"></div>
                </div>
            </div>
            <div class="modal-actions"><button class="btn btn-pill btn-secondary" type="submit">Add</button><button class="btn btn-pill btn-danger" type="button" data-modal-close>Cancel</button></div>
        </form>
    </x-admin.modal>
@endcomponent
