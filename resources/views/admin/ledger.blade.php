@component('layouts.admin', ['title' => 'Inventory Ledger', 'active' => 'ledger'])
    <div data-tabs>
        <h2 class="section-title">Ledger Tab</h2>
        <div class="tabs">
            <button class="tab-button is-active" type="button" data-tab-target="ledger">Ledger</button>
            <button class="tab-button" type="button" data-tab-target="transaction">Transaction</button>
        </div>

        <section data-tab-panel="ledger">
            <form class="toolbar toolbar-ledger" method="GET" action="{{ route('admin.ledger') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search ledger" value="{{ $search }}">
                <button class="btn btn-primary" type="submit">Date</button>
                <button class="btn btn-primary" type="submit">Depot</button>
                <button class="btn btn-primary" type="submit">Fuel Type (All)</button>
            </form>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Purchase ID</th><th>Fuel Type</th><th>Depot</th><th>Purchased Quantity</th><th>Total Lifted</th><th>Remaining Quantity</th><th>Status</th></tr></thead>
                    <tbody>
                        @forelse ($ledger as $row)
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
                            <tr><td class="empty-cell" colspan="7">No inventory movements found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section data-tab-panel="transaction" hidden>
            <form class="toolbar toolbar-transaction" method="GET" action="{{ route('admin.ledger') }}">
                <input type="search" name="search" placeholder="Search transactions" aria-label="Search transactions" value="{{ $search }}">
                <button class="btn btn-primary" type="submit">Status</button>
                <button class="btn btn-primary" type="submit">Date</button>
                <button class="btn btn-primary" type="submit">Depot</button>
                <button class="btn btn-primary" type="submit">Fuel Type (All)</button>
            </form>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Purchase ID</th><th>Fuel Type</th><th>Depot</th><th>Purchased Quantity</th><th>Total Lifted</th><th>Remaining Quantity</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                        @forelse ($transactions as $row)
                            <tr>
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
                            <tr><td class="empty-cell" colspan="8">No inventory movements found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

    </div>

    @foreach ($transactions as $row)
        <x-admin.modal id="{{ $row['id'] }}" title="VIEW TRANSACTIONS" :show-brand="false">
            <div class="modal-heading-row">
                <p class="detail-id">Purchase ID: {{ $row['purchase_code'] }}</p>
                <x-admin.status-badge :status="$row['status']" />
            </div>
            <div class="lift-summary" aria-label="Purchase lifting totals">
                <span>Purchased: <strong>{{ $row['purchased_liters'] }}</strong></span>
                <span>Lifted: <strong>{{ $row['lifted_liters'] }}</strong></span>
                <span>Remaining / Unlifted: <strong>{{ $row['remaining_liters'] }}</strong></span>
            </div>
            <div class="lift-transaction-modal">
                <div class="lift-blocks" aria-label="Lift transactions for {{ $row['purchase_code'] }}">
                    @forelse ($row['lifts'] as $lift)
                        <div class="lift-block {{ $lift['counts_as_lifted'] ? 'is-complete' : 'is-open' }}" tabindex="0" aria-label="Lift {{ $lift['sequence'] }}: {{ $lift['display_quantity'] }}. Lift ID: {{ $lift['code'] }}. Date Lifted: {{ $lift['display_date'] }}.">
                            <span class="visually-hidden">Lift {{ $lift['sequence'] }}</span>
                            <div class="lift-block-meta" aria-label="Lift ID: {{ $lift['details']['Lift ID'] }}">
                                <span>Lift-ID: {{ $lift['details']['Lift ID'] }}</span>
                                <span>Date Lifted: {{ $lift['display_date'] }}</span>
                            </div>
                            <strong class="lift-block-quantity">{{ $lift['display_quantity'] }}</strong>
                            <span class="visually-hidden">Driver: {{ $lift['details']['Driver'] }}. Truck: {{ $lift['details']['Truck'] }}. Status: {{ $lift['status'] }}.</span>
                        </div>
                    @empty
                        <div class="lift-empty">No lift assignments have been created for this purchase yet.</div>
                    @endforelse
                </div>
            </div>
        </x-admin.modal>
    @endforeach
@endcomponent
