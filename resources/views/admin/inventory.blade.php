@component('layouts.admin', ['title' => 'Inventory Management', 'active' => 'inventory'])
    <div data-tabs>
        <h2 class="section-title">Purchases</h2>
        <div class="tabs">
            <button class="tab-button is-active" type="button" data-tab-target="purchases">Purchases</button>
            <button class="tab-button" type="button" data-tab-target="stock-in">Stock-In</button>
            <button class="tab-button" type="button" data-tab-target="stock-out">Stock Out</button>
        </div>
        <div class="actions-right">
            <button class="btn btn-secondary" type="button" data-export-table>Export</button>
        </div>

        <section data-tab-panel="purchases">
            <form class="toolbar" method="GET" action="{{ route('admin.inventory') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search purchases" value="{{ $search }}">
                <button class="btn btn-primary" type="submit">Status</button>
                <button class="btn btn-primary" type="submit">Date</button>
                <button class="btn btn-primary" type="submit">Depot</button>
                <button class="btn btn-primary" type="submit">Fuel Type (All)</button>
                <button class="btn btn-primary" type="button" data-modal-open="purchase-add">+ Record Purchases</button>
            </form>
            <div class="table-wrap inventory-table-wrap">
                <table class="admin-table inventory-table inventory-table-actions">
                    <thead><tr><th>Purchase-ID</th><th>Date</th><th>Fuel</th><th>Depot</th><th>QTY (L)</th><th>Cost / Liter</th><th>Total Cost</th><th>Withdrawals</th><th class="inventory-status-column">Payment Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        @forelse ($purchases as $row)
                            <tr class="{{ $row['class'] }}">
                                @foreach ($row['cells'] as $cell)
                                    @if ($loop->index === 7)
                                        <td class="inventory-withdrawal-cell">
                                            @if (empty($row['withdrawals']))
                                                <x-admin.status-badge status="No Receipt" />
                                            @else
                                                <x-admin.status-badge status="Uploaded" />
                                                <button class="btn btn-secondary btn-small" type="button" data-modal-open="{{ $row['id'] }}">View Receipt{{ count($row['withdrawals']) > 1 ? 's' : '' }}</button>
                                            @endif
                                        </td>
                                    @elseif ($loop->last)
                                        <td><x-admin.status-badge :status="$cell" /></td>
                                    @else
                                        <td>{{ $cell }}</td>
                                    @endif
                                @endforeach
                                <td><button class="btn btn-secondary" type="button" data-modal-open="{{ $row['id'] }}">View</button></td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="10">No records found.</td></tr>
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

        <section data-tab-panel="stock-in" hidden>
            <form class="toolbar toolbar-narrow" method="GET" action="{{ route('admin.inventory') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search stock-in" value="{{ $search }}">
                <button class="btn btn-primary" type="submit">Date</button>
                <button class="btn btn-primary" type="submit">Depot</button>
                <button class="btn btn-primary" type="submit">Fuel Type (All)</button>
            </form>
            <div class="table-wrap inventory-table-wrap">
                <table class="admin-table inventory-table inventory-table-actions">
                    <thead><tr><th>Purchase-ID</th><th>Order Date</th><th>Fuel</th><th>Depot</th><th>QTY Ordered</th><th>Cost / Liter</th><th>Total Cost</th><th>Current Quantity</th><th class="inventory-status-column">Status</th><th>Actions</th></tr></thead>
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
                                <td><button class="btn btn-secondary" type="button" data-modal-open="{{ $row['id'] }}">View</button></td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="10">No records found.</td></tr>
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

        <section data-tab-panel="stock-out" hidden>
            <form class="toolbar toolbar-narrow" method="GET" action="{{ route('admin.inventory') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search stock-out" value="{{ $search }}">
                <button class="btn btn-primary" type="submit">Date</button>
                <button class="btn btn-primary" type="submit">Fuel Type (All)</button>
                <button class="btn btn-primary" type="button" data-print-page>Print</button>
            </form>
            <div class="table-wrap inventory-table-wrap">
                <table class="admin-table inventory-table">
                    <thead><tr><th>Order-ID</th><th>Transaction Date</th><th>Customer Name</th><th>Company Name</th><th>Fuel</th><th>QTY Released</th><th>Cost / Unit</th><th>Total Cost</th><th>Price / Unit</th><th>Total Price</th><th>Total Paid</th><th>Source</th><th>Profit</th></tr></thead>
                    <tbody>
                        @forelse ($stockOut as $row)
                            <tr class="{{ $row['class'] }}">
                                @foreach ($row['cells'] as $cell)
                                    <td @class([
                                        'inventory-number' => in_array($loop->index, [5, 6, 7, 8, 9, 10, 12], true),
                                        'inventory-profit' => $loop->last,
                                        'is-negative' => $loop->last && is_numeric($row['profit']) && $row['profit'] < 0,
                                    ])>{{ $cell }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="13">No records found.</td></tr>
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
    </div>

    <x-admin.modal id="purchase-add" title="Record Purchases" wide>
        <div class="modal-card"><p class="detail-value">Purchase records are monitored here and are managed in the Inventory Officer workflow.</p></div>
        <div class="modal-actions"><button class="btn btn-pill btn-danger" type="button" data-modal-close>Close</button></div>
    </x-admin.modal>

    @foreach ($purchases as $row)
        <x-admin.modal id="{{ $row['id'] }}" title="Purchase Record">
            <div class="modal-card">
                <x-admin.status-badge :status="$row['status']" />
                <p class="detail-id">{{ $row['cells'][0] }}</p>
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
            </div>
        </x-admin.modal>
    @endforeach

    @foreach ($stockIn as $row)
        <x-admin.modal id="{{ $row['id'] }}" title="Stock Details">
            <div class="modal-card">
                <x-admin.status-badge :status="$row['status']" />
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
