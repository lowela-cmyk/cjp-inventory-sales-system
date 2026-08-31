@component('layouts.admin', ['title' => 'Sales Management', 'active' => 'sales'])
    <div data-tabs>
        <h2 class="section-title">Receivables</h2>
        <div class="tabs">
            <button class="tab-button is-active" type="button" data-tab-target="receivables">Receivables</button>
            <button class="tab-button" type="button" data-tab-target="customers">Customers</button>
        </div>
        <div class="actions-right">
            <button class="btn btn-secondary" type="button">Export</button>
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
                    <thead><tr><th>Order-ID</th><th>Transaction Date</th><th>Customer Name</th><th>Company Name</th><th>Fuel</th><th>QTY</th><th>Price / Liter</th><th>Total</th><th>Total Paid</th><th>Balance</th><th>Due Date</th><th>Latest Payment</th><th>Status</th><th>Actions</th></tr></thead>
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
                            <tr><td class="empty-cell" colspan="14">No records found.</td></tr>
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
        <div class="modal-card"><p class="detail-value">Sales and receivable records are monitored here and are managed in the Sales Officer workflow.</p></div>
        <div class="modal-actions"><button class="btn btn-pill btn-danger" type="button" data-modal-close>Close</button></div>
    </x-admin.modal>

    @foreach ($sales as $row)
        <x-admin.modal id="{{ $row['id'] }}" title="Sales Record">
            <div class="modal-card">
                <span class="detail-status">{{ $row['status'] }}</span>
                <p class="detail-id">{{ $row['cells'][0] }}</p>
                <div class="detail-grid">
                    @foreach ($row['details'] as $label => $value)
                        <div class="detail-row"><div class="detail-label">{{ $label }}</div><div class="detail-value">{{ $value }}</div></div>
                    @endforeach
                </div>
            </div>
            <div class="modal-actions"><button class="btn btn-pill btn-success" type="button" data-modal-open="{{ $row['payment_id'] }}">Payment History</button></div>
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
