@php
    $activeTab = in_array($activeTab ?? 'purchases', ['purchases', 'stock-out', 'depots'], true) ? $activeTab : 'purchases';
    $inventoryRoutePrefix = request()->routeIs('admin.inventory*') ? 'admin.inventory' : 'inventory-officer.inventory';
    $inventoryLayout = request()->routeIs('admin.inventory*') ? 'layouts.admin' : 'layouts.inventory-officer';
    $headings = ['purchases' => 'Purchases', 'stock-out' => 'Stock-Out', 'depots' => 'Depots'];
    $filters = $filters ?? [];
    $filterOptions = $filterOptions ?? ['statuses' => [], 'depots' => collect(), 'fuelTypes' => collect()];
@endphp

@component($inventoryLayout, ['title' => 'Inventory Management', 'active' => 'inventory'])
    <div data-tabs>
        <h2 class="section-title" data-tab-heading>{{ $headings[$activeTab] }}</h2>



        <div class="tabs">
            <button class="tab-button {{ $activeTab === 'purchases' ? 'is-active' : '' }}" type="button" data-tab-target="purchases" data-tab-url="{{ route($inventoryRoutePrefix) }}" data-heading="Purchases">Purchases</button>
            <button class="tab-button {{ $activeTab === 'stock-out' ? 'is-active' : '' }}" type="button" data-tab-target="stock-out" data-tab-url="{{ route($inventoryRoutePrefix.'.stock-out') }}" data-heading="Stock-Out">Stock Out</button>
            <button class="tab-button {{ $activeTab === 'depots' ? 'is-active' : '' }}" type="button" data-tab-target="depots" data-tab-url="{{ route($inventoryRoutePrefix.'.depots') }}" data-heading="Depots">Depots</button>
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

        <section data-tab-panel="purchases" @if ($activeTab !== 'purchases') hidden @endif>
            <x-filter.bar class="toolbar-inventory" :action="route($inventoryRoutePrefix)">
                <x-filter.search label="Search purchases" :value="$search" />
                @include('inventory-officer.partials.filter-controls', ['tab' => 'purchases'])
                <button class="btn btn-secondary btn-wide" type="button" data-modal-open="io-purchase-add">+ Record Purchase</button>
            </x-filter.bar>
            <div class="table-wrap inventory-table-wrap">
                <table class="admin-table inventory-table inventory-table-actions table-stacked">
                    <thead><tr>
                        <th>Purchase-ID</th><th>Date</th><th>Fuel</th><th>Depot</th>
                        <th>Purchased (L)</th>
                        <th>Remaining (L)</th>
                        <th>Cost / Liter</th><th>Total Cost</th>
                        <th><span class="th-line" data-export-part>Payment Mode</span><span class="th-line" data-export-part>Bank</span></th>
                        <th>Receipt</th>
                        <th class="inventory-status-column"><span class="th-line" data-export-part>Inventory Status</span><span class="th-line" data-export-part>Purchase Status</span><span class="th-line" data-export-part>Payment Status</span></th>
                        <th>Actions</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($purchases as $row)
                            @php $c = $row['cells']; @endphp
                            <tr class="{{ $row['class'] }}">
                                {{-- Hauled / Received ($c[5], $c[6]) stay in the data and the Edit modal; the table shows Purchased only (Part 8 #1). --}}
                                <td class="cell-id">{{ $c[0] }}</td>
                                <td>{{ $c[1] }}</td>
                                <td>{{ $c[2] }}</td>
                                <td class="cell-wrap">{{ $c[3] }}</td>
                                <td class="inventory-number">{{ $c[4] }}</td>
                                {{-- Part 2 revisions: the purchase's stock (ordered - delivered). --}}
                                <td class="inventory-number">{{ $c[15] }}</td>
                                <td class="inventory-number">{{ $c[8] }}</td>
                                <td class="inventory-number">{{ $c[9] }}</td>
                                <td><x-table.stack :lines="[['value' => $c[13]], ['value' => $c[14], 'class' => 'is-secondary']]" /></td>
                                {{-- Withdrawal receipts the driver uploaded for this purchase's lifts. --}}
                                <td class="inventory-receipt-cell">
                                    @if (count($row['withdrawals']) > 0)
                                        <button class="btn btn-secondary btn-small receipt-view-button" type="button" data-modal-open="{{ $row['modal_id'] }}-receipts">{{ count($row['withdrawals']) === 1 ? 'View Receipt' : 'View Receipts ('.count($row['withdrawals']).')' }}</button>
                                    @else
                                        <span class="receipt-none" aria-label="No receipt uploaded">-</span>
                                    @endif
                                </td>
                                <td>
                                    <span class="cell-stack cell-stack-badges">
                                        <span class="cell-line" data-export-part title="Inventory status"><x-admin.status-badge :status="$c[7]" /></span>
                                        <span class="cell-line" data-export-part title="Purchase status"><x-admin.status-badge :status="$c[11]" /></span>
                                        <span class="cell-line" data-export-part title="Payment status"><x-admin.status-badge :status="$c[12]" /></span>
                                    </span>
                                </td>
                                <td>
                                    <div class="inventory-row-actions">
                                        <button class="btn btn-secondary" type="button" data-modal-open="{{ $row['modal_id'] }}">Edit</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="12">No records found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if (method_exists($purchases, 'links'))
                <div class="pagination-wrap" style="margin-top: 16px;">
                    {{ $purchases->links() }}
                </div>
            @endif
        </section>

        <section data-tab-panel="stock-out" @if ($activeTab !== 'stock-out') hidden @endif>
            <x-filter.bar class="toolbar-inventory" :action="route($inventoryRoutePrefix.'.stock-out')">
                <x-filter.search label="Search stock-out" :value="$search" />
                @include('inventory-officer.partials.filter-controls', ['tab' => 'stock-out'])
                <x-filter.select name="delivery_type" label="Delivery Type" :selected="$activeTab === 'stock-out' ? ($filters['delivery_type'] ?? null) : null" :options="$deliveryTypes" />
            </x-filter.bar>
            <p class="table-help">Both delivery types appear here: a Standard order when it is Delivered, a Depot to Client order when its lift is completed. Each row names the purchase it was deducted from.</p>
            <div class="table-wrap inventory-table-wrap">
                {{-- Part 8 #4: one value per column with room to read; the frame scrolls sideways when narrower. --}}
                <table class="admin-table inventory-table stock-out-table table-stacked">
                    <thead><tr>
                        <th>Order-ID</th><th>Transaction Date</th><th>Customer Name</th><th>Company Name</th><th>Delivery Type</th><th>Fuel</th><th>Purchase ID</th>
                        <th>QTY Released</th><th>Cost / Unit</th><th>Total Cost</th><th>Price / Unit</th><th>Total Price</th><th>Total Paid</th>
                        <th><span class="th-line" data-export-part>Truck</span><span class="th-line" data-export-part>Driver</span><span class="th-line" data-export-part>Delivery / Lift Date</span><span class="th-line" data-export-part>Delivery Status</span></th>
                        <th><span class="th-line" data-export-part>ATL</span><span class="th-line" data-export-part>DR Number</span></th>
                        <th>Source</th><th class="inventory-status-column">Status</th>
                        <th><span class="th-hint" tabindex="0" title="Margin = Total Price − Total Cost (expected profit once fully paid)">Margin</span></th>
                        <th><span class="th-hint" tabindex="0" title="Realized Profit = Total Paid − Total Cost (negative until payments cover the cost)">Realized Profit</span></th>
                    </tr></thead>
                    <tbody>
                        @forelse ($stockOut as $row)
                            @php
                                $c = $row['cells'];
                                $d = $row['delivery'];
                                $negative = fn (mixed $value): bool => is_numeric($value) && $value < 0;
                            @endphp
                            <tr class="{{ $row['class'] }}">
                                <td class="cell-id">{{ $c[0] }}</td>
                                <td>{{ $c[1] }}</td>
                                <td class="cell-name">{{ $c[2] }}</td>
                                <td class="cell-name">{{ $c[3] }}</td>
                                <td>{{ $c[4] }}</td>
                                <td>{{ $c[5] }}</td>
                                <td class="cell-id">{{ $c[6] }}</td>
                                @foreach ([7, 8, 9, 10, 11, 12] as $i)
                                    <td class="inventory-number">{{ $c[$i] }}</td>
                                @endforeach
                                <td><x-table.stack :lines="[['label' => 'Truck', 'value' => $d['truck']], ['label' => 'Driver', 'value' => $d['driver'], 'class' => 'is-secondary'], ['label' => 'Date', 'value' => $d['date'], 'class' => 'is-secondary'], ['label' => 'Status', 'value' => $d['status'], 'class' => 'is-secondary']]" /></td>
                                <td><x-table.stack :lines="[['label' => 'ATL', 'value' => $d['atl']], ['label' => 'DR', 'value' => $d['dr'], 'class' => 'is-secondary']]" /></td>
                                <td class="cell-name">{{ $c[13] }}</td>
                                <td><x-admin.status-badge :status="$c[14]" /></td>
                                <td @class(['inventory-number', 'inventory-profit', 'is-negative' => $negative($row['margin'])])>{{ $c[15] }}</td>
                                <td @class(['inventory-number', 'inventory-profit', 'is-negative' => $negative($row['profit'])])>{{ $c[16] }}</td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="19">No records found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if (method_exists($stockOut, 'links'))
                <div class="pagination-wrap" style="margin-top: 16px;">
                    {{ $stockOut->links() }}
                </div>
            @endif
        </section>

        <section data-tab-panel="depots" @if ($activeTab !== 'depots') hidden @endif>
            <x-filter.bar class="toolbar-inventory" :action="route($inventoryRoutePrefix.'.depots')">
                <x-filter.search label="Search depots" :value="$search" />
                @include('inventory-officer.partials.filter-controls', ['tab' => 'depots', 'fields' => ['status']])
                <button class="btn btn-secondary" type="button" data-modal-open="io-depot-add">+ Depot</button>
            </x-filter.bar>
            <div class="table-wrap inventory-table-wrap">
                <table class="admin-table depot-table inventory-table">
                    <thead><tr><th>Depot Code</th><th>Name</th><th>Address</th><th>Contact Person</th><th>Phone</th><th class="inventory-status-column">Status</th></tr></thead>
                    <tbody>
                        @forelse ($depotRows as $row)
                            <tr>
                                @foreach ($row as $cell)
                                    @if ($loop->last)
                                        <td><x-admin.status-badge :status="$cell" /></td>
                                    @else
                                        <td>{{ $cell }}</td>
                                    @endif
                                @endforeach
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="6">No depot records found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if (method_exists($depotRows, 'links'))
                <div class="pagination-wrap" style="margin-top: 16px;">
                    {{ $depotRows->links() }}
                </div>
            @endif
        </section>
    </div>

    <x-admin.modal id="io-purchase-add" title="Add Purchase Record" wide>
        <form id="purchase-add-form" method="POST" action="{{ route($inventoryRoutePrefix.'.purchases.store') }}">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $purchaseIdempotencyKey) }}">
            <div class="modal-card">
                <div class="form-grid">
                    <div class="form-row"><label for="purchase_date">Date</label><input id="purchase_date" name="purchase_date" type="date" value="{{ old('purchase_date', now()->toDateString()) }}" required></div>
                    <div class="form-row"><label for="fuel_type_id">Fuel Type</label><select id="fuel_type_id" name="fuel_type_id" required><option value="" disabled @selected(! old('fuel_type_id'))>Select Fuel Type</option>@foreach ($fuelTypes as $fuelType)<option value="{{ $fuelType->id }}" @selected((string) old('fuel_type_id') === (string) $fuelType->id)>{{ $fuelType->name }}</option>@endforeach</select></div>
                    <div class="form-row"><label for="depot_id">Depot</label><select id="depot_id" name="depot_id" required><option value="" disabled @selected(! old('depot_id'))>Select Depot</option>@foreach ($depots as $depot)<option value="{{ $depot->id }}" @selected((string) old('depot_id') === (string) $depot->id)>{{ $depot->name }}</option>@endforeach</select></div>
                    <div class="form-row"><label for="quantity_ordered_liters">Quantity (Liters)</label><input id="quantity_ordered_liters" name="quantity_ordered_liters" type="number" min="0.01" step="0.01" placeholder="Enter Quantity (Liters)" value="{{ old('quantity_ordered_liters') }}" required></div>
                    <div class="form-row"><label for="unit_cost">Cost / Liter</label><input id="unit_cost" name="unit_cost" type="number" min="0" step="0.01" placeholder="Enter Cost / Liter" value="{{ old('unit_cost') }}" required></div>
                    <div class="form-row"><label for="payment_status">Payment Status</label><select id="payment_status" name="payment_status" required>@foreach ($paymentStatuses as $status)<option value="{{ $status }}" @selected(old('payment_status', 'unpaid') === $status)>{{ ucwords($status) }}</option>@endforeach</select></div>
                    @include('inventory-officer.partials.purchase-payment-mode', ['idSuffix' => '', 'formId' => null, 'mode' => old('payment_mode'), 'bank' => old('bank_name')])
                    <div class="form-row"><label>Purchase Status</label><x-admin.status-badge status="Pending" /></div>
                </div>
            </div>
        </form>
        <x-slot:footer>
            <button class="btn btn-pill btn-secondary" type="submit" form="purchase-add-form">Add</button>
            <button class="btn btn-pill btn-danger" type="button" data-modal-close>Cancel</button>
        </x-slot:footer>
    </x-admin.modal>

    @foreach ($purchases as $row)
        @if (count($row['withdrawals']) > 0)
            @include('inventory-officer.partials.receipt-lightbox', ['lightboxId' => $row['modal_id'].'-receipts', 'purchaseCode' => $row['purchase_code'], 'receipts' => $row['withdrawals']])
        @endif
        <x-admin.modal id="{{ $row['modal_id'] }}" title="Edit Purchase Record">
            <form id="purchase-update-{{ $row['id'] }}" method="POST" action="{{ route($inventoryRoutePrefix.'.purchases.update', $row['id']) }}">
                @csrf
                @method('PATCH')
            </form>
            <div class="modal-card">
                <x-admin.status-badge :status="ucwords($row['payment_status'])" />
                <p class="detail-id">{{ $row['purchase_code'] }}</p>
                <div class="detail-grid">
                    @foreach ($row['details'] as $label => $value)
                        <div class="detail-row">
                            <div class="detail-label">{{ $label }}</div>
                            <div class="detail-value">
                                {{ $value }}
                            </div>
                        </div>
                    @endforeach
                    @if (! empty($row['withdrawals']))
                        <div class="detail-row">
                            <div class="detail-label">Withdrawal Files</div>
                            <div class="detail-value withdrawal-list">
                                @foreach ($row['withdrawals'] as $withdrawal)
                                    <div>
                                        <a class="btn btn-secondary btn-small" href="{{ $withdrawal['url'] }}">{{ $withdrawal['haul_code'] }}</a>
                                        <span>{{ $withdrawal['uploaded_at'] }}</span>
                                        @if ($withdrawal['notes'])
                                            <span>{{ $withdrawal['notes'] }}</span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
                <div class="form-grid" style="margin-top: 18px">
                    <div class="form-row"><label for="purchase_date_{{ $row['id'] }}">Date</label><input form="purchase-update-{{ $row['id'] }}" id="purchase_date_{{ $row['id'] }}" name="purchase_date" type="date" value="{{ old('purchase_date', $row['purchase_date']) }}" required></div>
                    <div class="form-row"><label for="fuel_type_id_{{ $row['id'] }}">Fuel Type</label><select form="purchase-update-{{ $row['id'] }}" id="fuel_type_id_{{ $row['id'] }}" name="fuel_type_id" required>@foreach ($fuelTypes as $fuelType)<option value="{{ $fuelType->id }}" @selected((string) old('fuel_type_id', $row['fuel_type_id']) === (string) $fuelType->id)>{{ $fuelType->name }}</option>@endforeach</select></div>
                    <div class="form-row"><label for="depot_id_{{ $row['id'] }}">Depot</label><select form="purchase-update-{{ $row['id'] }}" id="depot_id_{{ $row['id'] }}" name="depot_id" required>@foreach ($depots as $depot)<option value="{{ $depot->id }}" @selected((string) old('depot_id', $row['depot_id']) === (string) $depot->id)>{{ $depot->name }}</option>@endforeach</select></div>
                    <div class="form-row"><label for="quantity_ordered_liters_{{ $row['id'] }}">Quantity (Liters)</label><input form="purchase-update-{{ $row['id'] }}" id="quantity_ordered_liters_{{ $row['id'] }}" name="quantity_ordered_liters" type="number" min="0.01" step="0.01" value="{{ old('quantity_ordered_liters', $row['quantity_ordered_liters']) }}" required></div>
                    <div class="form-row"><label for="unit_cost_{{ $row['id'] }}">Cost / Liter</label><input form="purchase-update-{{ $row['id'] }}" id="unit_cost_{{ $row['id'] }}" name="unit_cost" type="number" min="0" step="0.01" value="{{ old('unit_cost', $row['unit_cost']) }}" required></div>
                    <div class="form-row"><label for="payment_status_{{ $row['id'] }}">Payment Status</label><select form="purchase-update-{{ $row['id'] }}" id="payment_status_{{ $row['id'] }}" name="payment_status" required>@foreach ($paymentStatuses as $status)<option value="{{ $status }}" @selected(old('payment_status', $row['payment_status']) === $status)>{{ ucwords($status) }}</option>@endforeach</select></div>
                    @include('inventory-officer.partials.purchase-payment-mode', ['idSuffix' => '_'.$row['id'], 'formId' => 'purchase-update-'.$row['id'], 'mode' => old('payment_mode', $row['payment_mode']), 'bank' => old('bank_name', $row['bank_name'])])
                    <div class="form-row"><label>Purchase Status</label><x-admin.status-badge :status="ucwords(str_replace('_', ' ', $row['purchase_status']))" /></div>
                </div>
            </div>
            <x-slot:footer>
                <button class="btn btn-pill btn-secondary" type="submit" form="purchase-update-{{ $row['id'] }}">Edit</button>
                <form method="POST" action="{{ route($inventoryRoutePrefix.'.purchases.cancel', $row['id']) }}" data-confirm-message="Cancel this purchase? It can no longer be lifted.">
                    @csrf
                    @method('PATCH')
                    <button class="btn btn-pill btn-danger" type="submit">Cancel</button>
                </form>
            </x-slot:footer>
        </x-admin.modal>
    @endforeach

    <x-admin.modal id="io-depot-add" title="Add Depot">
        <form id="depot-add-form" method="POST" action="{{ route($inventoryRoutePrefix.'.depots.store') }}">
            @csrf
            <div class="modal-card">
                <div class="form-grid">
                    <div class="form-row"><label for="depot_code_master">Code</label><input id="depot_code_master" name="depot_code" type="text" maxlength="30" value="{{ old('depot_code') }}" required></div>
                    <div class="form-row"><label for="depot_name_master">Name</label><input id="depot_name_master" name="name" type="text" maxlength="255" value="{{ old('name') }}" required></div>
                    <div class="form-row"><label for="depot_status_master">Status</label><select id="depot_status_master" name="status" required><option value="active" @selected(old('status', 'active') === 'active')>Active</option><option value="inactive" @selected(old('status') === 'inactive')>Inactive</option></select></div>
                    <div class="form-row"><label for="depot_phone_master">Phone</label><input id="depot_phone_master" name="phone" type="text" maxlength="30" value="{{ old('phone') }}"></div>
                    <div class="form-row"><label for="depot_contact_master">Contact</label><input id="depot_contact_master" name="contact_person" type="text" maxlength="255" value="{{ old('contact_person') }}"></div>
                    <div class="form-row"><label for="depot_address_master">Address</label><input id="depot_address_master" name="address" type="text" maxlength="255" value="{{ old('address') }}"></div>
                </div>
            </div>
        </form>
        <x-slot:footer>
            <button class="btn btn-pill btn-secondary" type="submit" form="depot-add-form">Add</button>
            <button class="btn btn-pill btn-danger" type="button" data-modal-close>Cancel</button>
        </x-slot:footer>
    </x-admin.modal>
@endcomponent
