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
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Purchase-ID</th><th>Date</th><th>Fuel</th><th>Depot</th><th>QTY (L)</th><th>Cost / Liter</th><th>Total Cost</th><th>Delivery Receipt</th><th>Payment Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        @forelse ($purchases as $row)
                            <tr class="{{ $row['class'] }}">
                                @foreach ($row['cells'] as $cell)
                                    @if ($loop->index === 7 && $row['receipt_url'])
                                        <td><a href="{{ $row['receipt_url'] }}">{{ $cell }}</a></td>
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
        </section>

        <section data-tab-panel="stock-in" hidden>
            <form class="toolbar toolbar-narrow" method="GET" action="{{ route('admin.inventory') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search stock-in" value="{{ $search }}">
                <button class="btn btn-primary" type="submit">Date</button>
                <button class="btn btn-primary" type="submit">Depot</button>
                <button class="btn btn-primary" type="submit">Fuel Type (All)</button>
            </form>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Purchase-ID</th><th>Order Date</th><th>Fuel</th><th>Depot</th><th>QTY Ordered</th><th>Cost / Liter</th><th>Total Cost</th><th>Current Quantity</th><th>Status</th><th>Actions</th></tr></thead>
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
        </section>

        <section data-tab-panel="stock-out" hidden>
            <form class="toolbar toolbar-narrow" method="GET" action="{{ route('admin.inventory') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search stock-out" value="{{ $search }}">
                <button class="btn btn-primary" type="submit">Date</button>
                <button class="btn btn-primary" type="submit">Fuel Type (All)</button>
                <button class="btn btn-primary" type="button" data-print-page>Print</button>
            </form>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Order-ID</th><th>Transaction Date</th><th>Customer Name</th><th>Company Name</th><th>Fuel</th><th>QTY</th><th>Price / Liter</th><th>Total</th><th>Total Paid</th><th>Current Stock</th><th>Result</th></tr></thead>
                    <tbody>
                        @forelse ($stockOut as $row)
                            <tr class="{{ $row['class'] }}">
                                @foreach ($row['cells'] as $cell)
                                    @if ($loop->last)
                                        <td><x-admin.status-badge :status="$cell" /></td>
                                    @else
                                        <td>{{ $cell }}</td>
                                    @endif
                                @endforeach
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="11">No records found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <x-admin.modal id="purchase-add" title="Record Purchases" wide>
        <div class="modal-card"><p class="detail-value">Purchase records are monitored here and are managed in the Inventory Officer workflow.</p></div>
        <div class="modal-actions"><button class="btn btn-pill btn-danger" type="button" data-modal-close>Close</button></div>
    </x-admin.modal>

    @foreach ($purchases as $row)
        <x-admin.modal id="{{ $row['id'] }}" title="Purchase Record">
            <div class="modal-card">
                <span class="detail-status">{{ $row['status'] }}</span>
                <p class="detail-id">{{ $row['cells'][0] }}</p>
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
            </div>
        </x-admin.modal>
    @endforeach

    @foreach ($stockIn as $row)
        <x-admin.modal id="{{ $row['id'] }}" title="Stock Details">
            <div class="modal-card">
                <span class="detail-status">{{ $row['status'] }}</span>
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
