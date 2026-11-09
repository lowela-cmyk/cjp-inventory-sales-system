@php
    $activeTab = in_array($activeTab ?? 'purchases', ['purchases', 'stock-in', 'stock-out', 'depots', 'garage'], true) ? $activeTab : 'purchases';
    $inventoryRoutePrefix = request()->routeIs('admin.inventory*') ? 'admin.inventory' : 'inventory-officer.inventory';
    $inventoryLayout = request()->routeIs('admin.inventory*') ? 'layouts.admin' : 'layouts.inventory-officer';
    $headings = ['purchases' => 'Purchases', 'stock-in' => 'Stock-In', 'stock-out' => 'Stock-Out', 'depots' => 'Depots', 'garage' => 'Garage'];
    $stockIn = $stockIn ?? collect();
    $filters = $filters ?? [];
    $filterOptions = $filterOptions ?? ['statuses' => [], 'depots' => collect(), 'fuelTypes' => collect()];
@endphp

@component($inventoryLayout, ['title' => 'Inventory Management', 'active' => 'inventory'])
    <div data-tabs>
        <div class="page-heading-row">
            <h2 class="section-title" data-tab-heading>{{ $headings[$activeTab] }}</h2>
            <x-export-button />
        </div>
        <div class="tabs">
            <button class="tab-button {{ $activeTab === 'purchases' ? 'is-active' : '' }}" type="button" data-tab-target="purchases" data-tab-url="{{ route($inventoryRoutePrefix) }}" data-heading="Purchases">Purchases</button>
            <button class="tab-button {{ $activeTab === 'stock-in' ? 'is-active' : '' }}" type="button" data-tab-target="stock-in" data-tab-url="{{ route($inventoryRoutePrefix.'.stock-in') }}" data-heading="Stock-In">Stock In</button>
            <button class="tab-button {{ $activeTab === 'stock-out' ? 'is-active' : '' }}" type="button" data-tab-target="stock-out" data-tab-url="{{ route($inventoryRoutePrefix.'.stock-out') }}" data-heading="Stock-Out">Stock Out</button>
            <button class="tab-button {{ $activeTab === 'depots' ? 'is-active' : '' }}" type="button" data-tab-target="depots" data-tab-url="{{ route($inventoryRoutePrefix.'.depots') }}" data-heading="Depots">Depots</button>
            <button class="tab-button {{ $activeTab === 'garage' ? 'is-active' : '' }}" type="button" data-tab-target="garage" data-tab-url="{{ route($inventoryRoutePrefix.'.garage') }}" data-heading="Garage">Garage</button>
        </div>

        @if (! empty($summaryCards))
            <div class="metric-row inventory-summary-row">
                @foreach ($summaryCards as $card)
                    <div class="metric-card">
                        <em>{{ $card['label'] }}</em>
                        <x-stat-value :value="$card['value']" />
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
                <table class="admin-table inventory-table inventory-table-actions table-stacked purchases-table" data-card-keys="0,2,4">
                    <thead><tr>
                        <th>Purchase-ID</th><th>Date</th><th>Fuel</th><th>Depot</th>
                        <th>Purchased (L)</th>
                        <th>Cost / Liter</th><th>Total Cost</th>
                        {{-- The Bank line is hidden on screen but kept for the export, so the CSV still has a Bank column. --}}
                        <th><span class="th-line" data-export-part>Payment Mode</span><span class="visually-hidden" data-export-part>Bank</span></th>
                        <th>Receipt</th>
                        {{-- Part 12: one status per column. --}}
                        <th class="inventory-status-column">Purchase Status</th>
                        <th class="inventory-status-column">Payment Status</th>
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
                                <td class="inventory-number">{{ $c[8] }}</td>
                                <td class="inventory-number">{{ $c[9] }}</td>
                                <td @class(['payment-mode-cell', 'is-empty' => $c[13] === 'N/A' && $c[14] === 'N/A'])><x-table.stack :lines="[['value' => $c[13]], ['value' => $c[14], 'class' => 'is-secondary']]" /></td>
                                {{-- Withdrawal receipts the driver uploaded for this purchase's lifts. --}}
                                <td class="inventory-receipt-cell">
                                    @if (count($row['withdrawals']) > 0)
                                        <button class="btn btn-secondary btn-small receipt-view-button" type="button" data-modal-open="{{ $row['modal_id'] }}-receipts">{{ count($row['withdrawals']) === 1 ? 'View Receipt' : 'View Receipts ('.count($row['withdrawals']).')' }}</button>
                                    @else
                                        <span class="receipt-none" aria-label="No receipt uploaded">-</span>
                                    @endif
                                </td>
                                <td class="purchase-status-cell"><x-admin.status-badge :status="$c[11]" /></td>
                                <td class="purchase-status-cell"><x-admin.status-badge :status="$c[12]" /></td>
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

        {{-- Stock-In revisions: one record per purchase, created when the purchase is recorded (no manual stock-in). --}}
        <section data-tab-panel="stock-in" @if ($activeTab !== 'stock-in') hidden @endif>
            <x-filter.bar class="toolbar-inventory" :action="route($inventoryRoutePrefix.'.stock-in')">
                <x-filter.search label="Search stock-in" :value="$search" />
                @include('inventory-officer.partials.filter-controls', ['tab' => 'stock-in'])
            </x-filter.bar>
            <p class="table-help">Every purchase is stocked in automatically. Sales deduct from the purchase they select as soon as they are saved; cancelling a sale gives the liters back.</p>
            <div class="table-wrap inventory-table-wrap">
                <table class="admin-table inventory-table inventory-table-actions table-stacked stock-in-table" data-card-keys="0,2,7">
                    <thead><tr>
                        {{-- The original Stock-In columns (August 2026 design), now filled from each purchase's stock-in:
                             Current QTY = liters left, Sold QTY = liters sales took (delivered + promised). --}}
                        <th>Purchase-ID</th><th>Order Date</th><th>Fuel</th><th>Depot</th>
                        <th>QTY Ordered (L)</th><th>Cost / Liter</th><th>Total Cost</th>
                        <th>Current QTY</th><th>Sold QTY</th>
                        <th class="inventory-status-column">Status</th><th>Actions</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($stockIn as $row)
                            <tr>
                                <td class="cell-id">{{ $row['purchase_code'] }}</td>
                                <td>{{ $row['date'] }}</td>
                                <td>{{ $row['fuel'] }}</td>
                                <td class="cell-wrap">{{ $row['depot'] }}</td>
                                <td class="inventory-number">{{ $row['purchased'] }}</td>
                                <td class="inventory-number">{{ $row['unit_cost'] }}</td>
                                <td class="inventory-number">{{ $row['total_cost'] }}</td>
                                <td class="inventory-number">{{ $row['remaining'] }}</td>
                                <td class="inventory-number">{{ $row['stock_out'] }}</td>
                                <td><x-admin.status-badge :status="$row['status']" /></td>
                                <td>
                                    <div class="inventory-row-actions">
                                        <button class="btn btn-secondary" type="button" data-modal-open="{{ $row['modal_id'] }}">Edit</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="11">No stock-in records found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if (method_exists($stockIn, 'links'))
                <div class="pagination-wrap" style="margin-top: 16px;">
                    {{ $stockIn->links() }}
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
                {{-- Part 4 #6 / Part 8 #4: the table fits its frame. Related values share a cell (one export column each);
                     every money value keeps its own column, as on the Part 4 example. --}}
                <table class="admin-table inventory-table inventory-table-actions stock-out-table table-stacked" data-card-keys="0,1,16">
                    <thead><tr>
                        <th><span class="th-line" data-export-part>Order-ID</span><span class="th-line" data-export-part>Transaction Date</span></th>
                        {{-- Part 12: customer and company in their own columns. --}}
                        <th>Customer Name</th>
                        <th>Company Name</th>
                        <th><span class="th-line" data-export-part>Fuel</span><span class="th-line" data-export-part>Purchase ID</span></th>
                        <th>Delivery Type</th>
                        {{-- Part 17 #11: the driver and truck (name) that delivered it are visible columns again (and exported). --}}
                        <th>Driver Name</th>
                        <th>Truck</th>
                        <th>Source</th>
                        {{-- Part 12: the other delivery details live in the Edit Stock-Out modal; these hidden columns keep them in Export. --}}
                        @foreach (['Delivery Date', 'Delivery Status', 'ATL', 'DR Number'] as $exportOnly)
                            <th class="export-only" hidden>{{ $exportOnly }}</th>
                        @endforeach
                        <th>QTY Released</th><th>Cost / Unit</th><th>Total Cost</th><th>Price / Unit</th><th>Total Price</th><th>Total Paid</th>
                        <th class="inventory-status-column">Status</th>
                        <th class="stock-out-profit-heading"><span class="th-hint" tabindex="0" title="Margin = Total Price − Total Cost (expected profit once fully paid)">Margin</span></th>
                        <th class="stock-out-profit-heading"><span class="th-hint" tabindex="0" title="Realized Profit = Total Paid − Total Cost (negative until payments cover the cost)">Realized Profit</span></th>
                        <th>Actions</th>
                    </tr></thead>
                    <tbody>
                        @forelse ($stockOut as $row)
                            @php
                                $c = $row['cells'];
                                $d = $row['delivery'];
                                $negative = fn (mixed $value): bool => is_numeric($value) && $value < 0;
                            @endphp
                            <tr class="{{ $row['class'] }}">
                                <td><x-table.stack :lines="[['value' => $c[0], 'class' => 'cell-id'], ['value' => $c[1], 'class' => 'is-secondary']]" /></td>
                                <td class="stock-out-name">{{ $c[2] }}</td>
                                <td class="stock-out-name">{{ $c[3] }}</td>
                                <td>
                                    <x-table.stack :lines="[['value' => $c[5]], ['value' => $c[6], 'class' => 'cell-id is-secondary']]" />
                                    @if ($row['source_change_note'])<small class="sale-source-change-tag" title="{{ $row['source_change_note'] }}">Source changed</small>@endif
                                </td>
                                <td>{{ $c[4] }}</td>
                                <td class="stock-out-name stock-out-crew">{{ $d['driver'] }}</td>
                                <td class="stock-out-name stock-out-crew">{{ $d['truck_name'] }}</td>
                                <td class="stock-out-name">{{ $c[13] }}</td>
                                @foreach ([$d['date'], $d['status'], $d['atl'], $d['dr']] as $exportOnly)
                                    <td class="export-only" hidden>{{ $exportOnly }}</td>
                                @endforeach
                                @foreach ([7, 8, 9, 10, 11, 12] as $i)
                                    <td class="inventory-number">{{ $c[$i] }}</td>
                                @endforeach
                                <td><x-admin.status-badge :status="$c[14]" /></td>
                                <td @class(['inventory-number', 'inventory-profit', 'stock-out-profit-cell', 'is-negative' => $negative($row['margin'])])>{{ $c[15] }}</td>
                                <td @class(['inventory-number', 'inventory-profit', 'stock-out-profit-cell', 'is-negative' => $negative($row['profit'])])>{{ $c[16] }}</td>
                                <td><div class="inventory-row-actions"><button class="btn btn-secondary" type="button" data-modal-open="{{ $row['modal_id'] }}">View</button></div></td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="17">No records found.</td></tr>
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
                <table class="admin-table depot-table inventory-table" data-card-keys="0,1">
                    <thead><tr><th>Depot Code</th><th>Name</th><th>Address</th><th>Contact Person</th><th>Phone</th><th class="inventory-status-column">Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        @forelse ($depotRows as $row)
                            <tr>
                                <td>{{ $row['depot_code'] }}</td><td>{{ $row['name'] }}</td><td>{{ $row['address'] }}</td><td>{{ $row['contact_person'] }}</td><td>{{ $row['phone'] }}</td>
                                <td><x-admin.status-badge :status="$row['status']" /></td>
                                <td><button class="btn btn-secondary" type="button" data-modal-open="io-depot-edit-{{ $row['id'] }}">Edit</button></td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="7">No depot records found.</td></tr>
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

        {{-- Part 13: Garage tab: Tank 1 / Tank 2 per fuel and the garage lots. --}}
        <section data-tab-panel="garage" @if ($activeTab !== 'garage') hidden @endif>
            @include('garage.partials.tab', ['garageRoute' => route($inventoryRoutePrefix.'.garage'), 'garageSearch' => $activeTab === 'garage' ? $search : null])
        </section>
    </div>

    <x-admin.modal id="io-purchase-add" reset-on-close title="Add Purchase Record" wide>
        <form id="purchase-add-form" method="POST" action="{{ route($inventoryRoutePrefix.'.purchases.store') }}">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $purchaseIdempotencyKey) }}">
            <div class="modal-card">
                <div class="form-grid">
                    <div class="form-row"><label for="purchase_date">Date</label><input id="purchase_date" name="purchase_date" type="date" value="{{ old('purchase_date', now()->toDateString()) }}" data-default="{{ now()->toDateString() }}" required></div>
                    <div class="form-row"><label for="fuel_type_id">Fuel Type</label><select id="fuel_type_id" name="fuel_type_id" required><option value="" disabled @selected(! old('fuel_type_id'))>Select Fuel Type</option>@foreach ($fuelTypes as $fuelType)<option value="{{ $fuelType->id }}" @selected((string) old('fuel_type_id') === (string) $fuelType->id)>{{ $fuelType->name }}</option>@endforeach</select></div>
                    <div class="form-row"><label for="depot_id">Depot</label><select id="depot_id" name="depot_id" required><option value="" disabled @selected(! old('depot_id'))>Select Depot</option>@foreach ($depots as $depot)<option value="{{ $depot->id }}" @selected((string) old('depot_id') === (string) $depot->id)>{{ $depot->name }}</option>@endforeach</select></div>
                    <div class="form-row"><label for="quantity_ordered_liters">Quantity (Liters)</label><input id="quantity_ordered_liters" name="quantity_ordered_liters" type="number" min="0.01" step="0.01" placeholder="Enter Quantity (Liters)" value="{{ old('quantity_ordered_liters') }}" required></div>
                    <div class="form-row"><label for="unit_cost">Cost / Liter</label><input id="unit_cost" name="unit_cost" type="number" min="0" step="0.01" placeholder="Enter Cost / Liter" value="{{ old('unit_cost') }}" required></div>
                    <div class="form-row"><label for="payment_status">Payment Status</label><select id="payment_status" name="payment_status" required data-default="unpaid">@foreach ($paymentStatuses as $status)<option value="{{ $status }}" @selected(old('payment_status', 'unpaid') === $status)>{{ ucwords($status) }}</option>@endforeach</select></div>
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
            <form id="purchase-update-{{ $row['id'] }}" data-confirm-item="{{ $row['purchase_code'] }}" method="POST" action="{{ route($inventoryRoutePrefix.'.purchases.update', $row['id']) }}">
                @csrf
                @method('PATCH')
            </form>
            @php
                // Part 17 #9: the withdrawal files (links) close the second section.
                $purchasePages = $row['detail_pages'];
                if (! empty($row['withdrawals'])) {
                    $purchasePages[1]['fields']['Withdrawal Files'] = new \Illuminate\Support\HtmlString(collect($row['withdrawals'])->map(fn (array $withdrawal): string => '<div><a class="btn btn-secondary btn-small" href="'.e($withdrawal['url']).'">'.e($withdrawal['haul_code']).'</a> <span>'.e($withdrawal['uploaded_at']).'</span>'.($withdrawal['notes'] ? ' <span>'.e($withdrawal['notes']).'</span>' : '').'</div>')->implode(''));
                }
            @endphp
            <div class="modal-card">
                <x-admin.status-badge :status="ucwords($row['payment_status'])" />
                <p class="detail-id">{{ $row['purchase_code'] }}</p>
                {{-- Part 17 #9: "< 1 of 2 >" sections; the editable fields stay below (Part 15 locks unchanged). --}}
                <x-admin.detail-pages :pages="$purchasePages" />
                <div class="form-grid" style="margin-top: 18px">
                    @if ($row['stock_locked'])
                        {{-- Part 15: once fuel is lifted, Date, Fuel Type, Depot, Quantity and Cost / Liter stay as recorded.
                             Part 18 #4: their values are in the cards above; here they are only sent back unchanged. --}}
                        <p class="form-locked-note" role="note">{{ \App\Services\PurchaseService::LOCKED_MESSAGE }}</p>
                        @foreach (['purchase_date', 'fuel_type_id', 'depot_id', 'quantity_ordered_liters', 'unit_cost'] as $field)
                            <input form="purchase-update-{{ $row['id'] }}" type="hidden" name="{{ $field }}" value="{{ $row[$field] }}" data-locked-field="{{ $field }}">
                            @error($field)<p class="field-error">{{ $message }}</p>@enderror
                        @endforeach
                    @else
                        <div class="form-row"><label for="purchase_date_{{ $row['id'] }}">Date</label><input form="purchase-update-{{ $row['id'] }}" id="purchase_date_{{ $row['id'] }}" name="purchase_date" type="date" value="{{ old('purchase_date', $row['purchase_date']) }}" required></div>
                        <div class="form-row"><label for="fuel_type_id_{{ $row['id'] }}">Fuel Type</label><select form="purchase-update-{{ $row['id'] }}" id="fuel_type_id_{{ $row['id'] }}" name="fuel_type_id" required>@foreach ($fuelTypes as $fuelType)<option value="{{ $fuelType->id }}" @selected((string) old('fuel_type_id', $row['fuel_type_id']) === (string) $fuelType->id)>{{ $fuelType->name }}</option>@endforeach</select></div>
                        <div class="form-row"><label for="depot_id_{{ $row['id'] }}">Depot</label><select form="purchase-update-{{ $row['id'] }}" id="depot_id_{{ $row['id'] }}" name="depot_id" required>@foreach ($depots as $depot)<option value="{{ $depot->id }}" @selected((string) old('depot_id', $row['depot_id']) === (string) $depot->id)>{{ $depot->name }}</option>@endforeach</select></div>
                        <div class="form-row"><label for="quantity_ordered_liters_{{ $row['id'] }}">Quantity (Liters)</label><input form="purchase-update-{{ $row['id'] }}" id="quantity_ordered_liters_{{ $row['id'] }}" name="quantity_ordered_liters" type="number" min="0.01" step="0.01" value="{{ old('quantity_ordered_liters', $row['quantity_ordered_liters']) }}" required></div>
                        <div class="form-row"><label for="unit_cost_{{ $row['id'] }}">Cost / Liter</label><input form="purchase-update-{{ $row['id'] }}" id="unit_cost_{{ $row['id'] }}" name="unit_cost" type="number" min="0" step="0.01" value="{{ old('unit_cost', $row['unit_cost']) }}" required></div>
                    @endif
                    <div class="form-row"><label for="payment_status_{{ $row['id'] }}">Payment Status</label><select form="purchase-update-{{ $row['id'] }}" id="payment_status_{{ $row['id'] }}" name="payment_status" required>@foreach ($paymentStatuses as $status)<option value="{{ $status }}" @selected(old('payment_status', $row['payment_status']) === $status)>{{ ucwords($status) }}</option>@endforeach</select></div>
                    @include('inventory-officer.partials.purchase-payment-mode', ['idSuffix' => '_'.$row['id'], 'formId' => 'purchase-update-'.$row['id'], 'mode' => old('payment_mode', $row['payment_mode']), 'bank' => old('bank_name', $row['bank_name'])])
                </div>
            </div>
            <x-slot:footer>
                <button class="btn btn-pill btn-secondary" type="submit" form="purchase-update-{{ $row['id'] }}">Apply Changes</button>
                {{-- Part 15: Cancel Purchase (full name, so it is not read as closing the modal) only while the purchase is
                     Pending and no lift, sale, stock-out or garage lot uses it. Close only closes. --}}
                @if ($row['can_cancel'])
                    <form method="POST" action="{{ route($inventoryRoutePrefix.'.purchases.cancel', $row['id']) }}"
                        data-confirm="delete" data-confirm-action="Cancel purchase" data-confirm-typed="{{ $row['purchase_code'] }}"
                        data-confirm-item="{{ $row['purchase_code'] }} ({{ $row['cells'][2] }}, {{ $row['cells'][4] }} L, {{ $row['cells'][3] }})"
                        data-confirm-undo="This can't be undone. The purchase is marked Cancelled and can no longer be lifted or sold from."
                        data-confirm-message="Cancel purchase {{ $row['purchase_code'] }}? It will be marked Cancelled and can no longer be lifted or sold from.">
                        @csrf
                        @method('PATCH')
                        <button class="btn btn-pill btn-danger" type="submit">Cancel Purchase</button>
                    </form>
                @endif
                <button class="btn btn-pill btn-secondary" type="button" data-modal-close>Close</button>
            </x-slot:footer>
        </x-admin.modal>
    @endforeach

    @foreach ($stockIn as $row)
        @php
            // Old input and errors belong only to the stock-in whose Edit form was submitted.
            $isEditedStockIn = (int) old('edit_stock_in_id') === $row['id'];
            $stockInOld = fn (string $key): mixed => $isEditedStockIn ? old($key, $row['form'][$key]) : $row['form'][$key];
            $editableFields = ['Order Date', 'Fuel', 'Depot', 'QTY Ordered', 'Cost / Liter'];
        @endphp
        <x-admin.modal id="{{ $row['modal_id'] }}" title="Edit Stock-In Record" size="xl">
            @if ($isEditedStockIn && $errors->any())
                <span hidden data-open-modal-on-load="{{ $row['modal_id'] }}"></span>
                @if ($errors->has('stock_in'))<p class="form-alert" role="alert">{{ $errors->first('stock_in') }}</p>@endif
            @endif
            <div class="modal-card">
                <x-admin.status-badge :status="$row['status']" />
                <p class="detail-id">{{ $row['purchase_code'] }}</p>
                @if ($row['locked'])
                    <p class="form-locked-note" role="note">{{ \App\Services\PurchaseService::LOCKED_MESSAGE }}</p>
                @endif
                @if ($row['editable'])
                    <form id="stock-in-update-{{ $row['id'] }}" data-confirm-item="{{ $row['purchase_code'] }}" method="POST" action="{{ route($inventoryRoutePrefix.'.stock-in.update', $row['purchase_item_id']) }}" data-prevent-double-submit>
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="edit_stock_in_id" value="{{ $row['id'] }}">
                    </form>
                    <div class="form-grid">
                        <div class="form-row"><label for="si_purchase_date_{{ $row['id'] }}">Order Date</label><input form="stock-in-update-{{ $row['id'] }}" id="si_purchase_date_{{ $row['id'] }}" name="purchase_date" type="date" value="{{ $stockInOld('purchase_date') }}" required>@if ($isEditedStockIn)@error('purchase_date')<p class="field-error">{{ $message }}</p>@enderror @endif</div>
                        <div class="form-row"><label for="si_fuel_type_id_{{ $row['id'] }}">Fuel</label><select form="stock-in-update-{{ $row['id'] }}" id="si_fuel_type_id_{{ $row['id'] }}" name="fuel_type_id" required>@foreach ($filterOptions['fuelTypes'] as $fuelType)<option value="{{ $fuelType->id }}" @selected((string) $stockInOld('fuel_type_id') === (string) $fuelType->id)>{{ $fuelType->name }}</option>@endforeach</select>@if ($isEditedStockIn)@error('fuel_type_id')<p class="field-error">{{ $message }}</p>@enderror @endif</div>
                        <div class="form-row"><label for="si_depot_id_{{ $row['id'] }}">Depot</label><select form="stock-in-update-{{ $row['id'] }}" id="si_depot_id_{{ $row['id'] }}" name="depot_id" required>@foreach ($depots as $depot)<option value="{{ $depot->id }}" @selected((string) $stockInOld('depot_id') === (string) $depot->id)>{{ $depot->name }}</option>@endforeach</select>@if ($isEditedStockIn)@error('depot_id')<p class="field-error">{{ $message }}</p>@enderror @endif</div>
                        <div class="form-row"><label for="si_quantity_{{ $row['id'] }}">QTY Ordered (L)</label><input form="stock-in-update-{{ $row['id'] }}" id="si_quantity_{{ $row['id'] }}" name="quantity_ordered_liters" type="number" min="0.01" step="0.01" value="{{ $stockInOld('quantity_ordered_liters') }}" required>@if ($isEditedStockIn)@error('quantity_ordered_liters')<p class="field-error">{{ $message }}</p>@enderror @endif</div>
                        <div class="form-row"><label for="si_unit_cost_{{ $row['id'] }}">Cost / Liter</label><input form="stock-in-update-{{ $row['id'] }}" id="si_unit_cost_{{ $row['id'] }}" name="unit_cost" type="number" min="0" step="0.01" value="{{ $stockInOld('unit_cost') }}" required>@if ($isEditedStockIn)@error('unit_cost')<p class="field-error">{{ $message }}</p>@enderror @endif</div>
                    </div>
                @endif
                <div class="detail-grid">
                    @foreach ($row['details'] as $label => $value)
                        @continue($row['editable'] && in_array($label, $editableFields, true))
                        <div class="detail-row">
                            <div class="detail-label">{{ $label }}</div>
                            <div class="detail-value">{{ $value }}</div>
                        </div>
                    @endforeach
                </div>
                <h3 class="stock-in-deductions-title">Sold QTY: sales deducted from this purchase</h3>
                @if ($row['deductions'] === [])
                    <p class="table-help">No sales have deducted from this purchase yet.</p>
                @else
                    <div class="table-wrap stock-in-deductions-wrap">
                        <table class="admin-table stock-in-deductions">
                            <thead><tr><th>Sales Order</th><th>Date</th><th>Customer</th><th>Quantity</th><th>Delivery Type</th><th>Delivery Status</th><th>Stock Out</th></tr></thead>
                            <tbody>
                                @foreach ($row['deductions'] as $deduction)
                                    <tr>
                                        <td class="cell-id">{{ $deduction['order'] }}</td>
                                        <td>{{ $deduction['date'] }}</td>
                                        <td>{{ $deduction['customer'] }}</td>
                                        <td class="inventory-number">{{ $deduction['quantity'] }}</td>
                                        <td>{{ $deduction['delivery_type'] }}</td>
                                        <td><x-admin.status-badge :status="$deduction['delivery_status']" /></td>
                                        <td>{{ $deduction['stock_out'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
            <x-slot:footer>
                @if ($row['editable'])
                    <button class="btn btn-pill btn-secondary" type="submit" form="stock-in-update-{{ $row['id'] }}">Apply Changes</button>
                @endif
                <button class="btn btn-pill btn-danger" type="button" data-modal-close>Close</button>
            </x-slot:footer>
        </x-admin.modal>
    @endforeach

    @foreach ($stockOut as $row)
        @php
            // Old input and errors belong only to the stock-out whose Edit form was submitted.
            $isEditedStockOut = (int) old('edit_stock_out_id') === $row['id'];
            $stockOutOld = fn (string $key): mixed => $isEditedStockOut ? old($key, $row['form'][$key]) : $row['form'][$key];
            $c = $row['cells'];
            $d = $row['delivery'];
        @endphp
        <x-admin.modal id="{{ $row['modal_id'] }}" title="Stock-Out Record" size="xl">
            @if ($isEditedStockOut && $errors->any())
                <span hidden data-open-modal-on-load="{{ $row['modal_id'] }}"></span>
                @if ($errors->has('stock_out'))<p class="form-alert" role="alert">{{ $errors->first('stock_out') }}</p>@endif
            @endif
            <div class="modal-card">
                <x-admin.status-badge :status="$c[14]" />
                <p class="detail-id">{{ $c[0] }}</p>
                <div class="detail-grid">
                    {{-- Kept as a non-editable compatibility value for integrations; Stock-Out has no edit controls. --}}
                    <input type="hidden" id="so_cost_{{ $row['id'] }}" value="{{ $c[8] }}" readonly>
                    @foreach ([
                        'Transaction Date' => $c[1],
                        'Customer' => $c[2].($c[3] && $c[3] !== $c[2] ? ' — '.$c[3] : ''),
                        'Delivery Type' => $c[4],
                        'Fuel' => $c[5],
                        'Purchase ID' => $c[6],
                        // Part 18 add-on: a garage tank release names the purchases (lots) it drew from.
                        'Drawn From' => $row['drawn_from'],
                        'QTY Released' => $c[7].' L',
                        'Cost / Unit' => $c[8],
                        'Total Cost' => $c[9],
                        'Price / Unit' => $c[10],
                        'Total Price' => $c[11],
                        'Total Paid' => $c[12],
                        'Margin' => $c[15],
                        'Realized Profit' => $c[16],
                        'Truck' => $d['truck'],
                        'Driver' => $d['driver'],
                        'Delivery / Lift Date' => $d['date'],
                        'Delivery Status' => $d['status'],
                        'ATL' => $d['atl'],
                        'DR Number' => $d['dr'],
                        'Source' => $c[13],
                    ] as $label => $value)
                        @continue($value === null)
                        <div class="detail-row">
                            <div class="detail-label">{{ $label }}</div>
                            <div class="detail-value">{{ $value }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
            <x-slot:footer>
                <button class="btn btn-pill btn-danger" type="button" data-modal-close>Close</button>
            </x-slot:footer>
        </x-admin.modal>
    @endforeach

        @foreach ($depotRows as $depotRow)
            @php
                // Old input and errors belong only to the depot whose Edit form was submitted.
                $isEditedDepot = (int) old('edit_depot_id') === $depotRow['id'];
                $depotOld = fn (string $key): mixed => $isEditedDepot ? old($key, $depotRow['form'][$key]) : $depotRow['form'][$key];
            @endphp
            <x-admin.modal id="io-depot-edit-{{ $depotRow['id'] }}" title="Edit Depot">
                @if ($isEditedDepot && $errors->any())
                    <span hidden data-open-modal-on-load="io-depot-edit-{{ $depotRow['id'] }}"></span>
                @endif
                <form id="depot-update-{{ $depotRow['id'] }}" data-confirm-item="{{ $depotRow['depot_code'] }} ({{ $depotRow['name'] }})" method="POST" action="{{ route($inventoryRoutePrefix.'.depots.update', $depotRow['id']) }}">@csrf @method('PATCH')<input type="hidden" name="edit_depot_id" value="{{ $depotRow['id'] }}"></form>
                <div class="modal-card"><div class="form-grid">
                    @foreach (['depot_code' => 'Code', 'name' => 'Name', 'address' => 'Address', 'contact_person' => 'Contact', 'phone' => 'Phone'] as $field => $label)
                        <div class="form-row"><label for="depot_{{ $field }}_{{ $depotRow['id'] }}">{{ $label }}</label><input form="depot-update-{{ $depotRow['id'] }}" id="depot_{{ $field }}_{{ $depotRow['id'] }}" name="{{ $field }}" type="text" maxlength="{{ in_array($field, ['phone', 'depot_code'], true) ? 30 : 255 }}" value="{{ $depotOld($field) }}" @if(in_array($field, ['depot_code', 'name'], true)) required @endif @if($isEditedDepot && $errors->has($field)) aria-invalid="true" @endif>@if ($isEditedDepot)@error($field)<p class="field-error form-row-message">{{ $message }}</p>@enderror @endif</div>
                    @endforeach
                    <div class="form-row"><label for="depot_status_{{ $depotRow['id'] }}">Status</label><select form="depot-update-{{ $depotRow['id'] }}" id="depot_status_{{ $depotRow['id'] }}" name="status" required><option value="active" @selected($depotOld('status') === 'active')>Active</option><option value="inactive" @selected($depotOld('status') === 'inactive')>Inactive</option></select>@if ($isEditedDepot)@error('status')<p class="field-error form-row-message">{{ $message }}</p>@enderror @endif</div>
                </div></div>
                <x-slot:footer><button class="btn btn-pill btn-secondary" type="submit" form="depot-update-{{ $depotRow['id'] }}">Apply Changes</button><button class="btn btn-pill btn-danger" type="button" data-modal-close>Close</button></x-slot:footer>
            </x-admin.modal>
        @endforeach

    {{-- old() here belongs to Add Depot only, never to a failed Edit Depot submission. --}}
    @php $depotAddOld = fn (string $key, ?string $default = null): ?string => old('edit_depot_id') ? $default : old($key, $default); @endphp
    <x-admin.modal id="io-depot-add" reset-on-close title="Add Depot">
        <form id="depot-add-form" method="POST" action="{{ route($inventoryRoutePrefix.'.depots.store') }}">
            @csrf
            <div class="modal-card">
                <div class="form-grid">
                    <div class="form-row"><label for="depot_code_master">Code</label><input id="depot_code_master" name="depot_code" type="text" maxlength="30" value="{{ $depotAddOld('depot_code') }}" required></div>
                    <div class="form-row"><label for="depot_name_master">Name</label><input id="depot_name_master" name="name" type="text" maxlength="255" value="{{ $depotAddOld('name') }}" required></div>
                    <div class="form-row"><label for="depot_status_master">Status</label><select id="depot_status_master" name="status" required data-default="active"><option value="active" @selected($depotAddOld('status', 'active') === 'active')>Active</option><option value="inactive" @selected($depotAddOld('status') === 'inactive')>Inactive</option></select></div>
                    <div class="form-row"><label for="depot_phone_master">Phone</label><input id="depot_phone_master" name="phone" type="text" maxlength="30" value="{{ $depotAddOld('phone') }}"></div>
                    <div class="form-row"><label for="depot_contact_master">Contact</label><input id="depot_contact_master" name="contact_person" type="text" maxlength="255" value="{{ $depotAddOld('contact_person') }}"></div>
                    <div class="form-row"><label for="depot_address_master">Address</label><input id="depot_address_master" name="address" type="text" maxlength="255" value="{{ $depotAddOld('address') }}"></div>
                </div>
            </div>
        </form>
        <x-slot:footer>
            <button class="btn btn-pill btn-secondary" type="submit" form="depot-add-form">Add</button>
            <button class="btn btn-pill btn-danger" type="button" data-modal-close>Cancel</button>
        </x-slot:footer>
    </x-admin.modal>
@endcomponent
