@component('layouts.dispatch', ['title' => 'Inventory Ledger', 'active' => 'ledger'])
    <h2 class="section-title">Ledger Tab</h2>
    <div class="actions-right"><button class="btn btn-secondary" type="button" data-export-table>Export</button></div>

    <div class="dispatch-filter-row">
        <input type="search" placeholder="Search..." aria-label="Search ledger records">
        <button class="btn btn-primary" type="button" data-sort-table="1">Date</button>
        <button class="btn btn-primary" type="button" data-sort-table="3">Depot</button>
        <button class="btn btn-primary" type="button" data-sort-table="2">Fuel Type (All)</button>
    </div>

    <div class="table-wrap dispatch-table-wrap">
        <table class="admin-table dispatch-table">
            <thead>
                <tr>
                    <th>Purchase-ID</th>
                    <th>Order Date</th>
                    <th>Fuel</th>
                    <th>Depot</th>
                    <th>QTY Ordered (L)</th>
                    <th>QTY Lifted (L)</th>
                    <th>Current QTY</th>
                    <th>Balance</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <tr><td class="empty-cell" colspan="9">No dispatch ledger records found.</td></tr>
            </tbody>
        </table>
    </div>
@endcomponent
