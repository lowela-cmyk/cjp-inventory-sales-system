@php
    $sales = [
        ['SLS-000001', '8/22/2026', 'Ken C. Binhi', 'Binhi Green Homes', 'Premium', '10,000.00', '90.00', '900,000.00', '900,000.00', '0', 'Paid', ''],
        ['SLS-000002', '8/22/2026', 'Jay P. Calinisan', 'Jay P Constructions', 'Premium', '20,000.00', '90.00', '1,800,000.00', '800,000.00', '450,000.00', 'Partial', 'row-warning'],
        ['SLS-000003', '8/22/2026', 'Yuri Q. Mabini', 'Gold Steel Productions', 'Premium', '10,000.00', '90.00', '900,000.00', '0', '900,000.00', 'Unpaid', 'row-danger'],
    ];
    $customers = [
        ['CSM-000001', 'Ken C. Binhi', 'Binhi Green Homes', 'Nasugbu, Batangas', 'bihingreenhomes@gmail.com', '09876543211', 'Clear', ''],
        ['CSM-000002', 'Jay P. Calinisan', 'Jay P Constructions', 'Nasugbu, Batangas', 'jaypconstructions@gmail.com', '09876543212', 'Pending', 'row-warning'],
        ['CSM-000003', 'Yuri Q. Mabini', 'Gold Steel Productions', 'Nasugbu, Batangas', 'goldsteeelproductions@gmail.com', '09876543213', 'Unpaid', 'row-danger'],
    ];
@endphp

@component('layouts.sales-officer', ['title' => 'Sales Management', 'active' => 'sales'])
    <div data-tabs>
        <h2 class="section-title" data-tab-heading>Receivables</h2>
        <div class="tabs">
            <button class="tab-button is-active" type="button" data-tab-target="receivables" data-heading="Receivables">Receivables</button>
            <button class="tab-button" type="button" data-tab-target="customers" data-heading="Customers">Customers</button>
        </div>
        <div class="actions-right">
            <button class="btn btn-secondary" type="button">Export</button>
        </div>

        <section data-tab-panel="receivables">
            <div class="toolbar toolbar-narrow">
                <input type="search" placeholder="Search..." aria-label="Search receivables">
                <button class="btn btn-primary" type="button">Date</button>
                <button class="btn btn-primary" type="button">Depot</button>
                <button class="btn btn-primary" type="button">Fuel Type (All)</button>
                <button class="btn btn-primary" type="button">+ Record Sales</button>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Order-ID</th>
                            <th>Transaction Date</th>
                            <th>Customer Name</th>
                            <th>Company Name</th>
                            <th>Fuel</th>
                            <th>QTY</th>
                            <th>Price / Liter</th>
                            <th>Total</th>
                            <th>Total Paid</th>
                            <th>Balance</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($sales as $row)
                            <tr class="{{ $row[11] }}">
                                <td>{{ $row[0] }}</td>
                                <td>{{ $row[1] }}</td>
                                <td>{{ $row[2] }}</td>
                                <td>{{ $row[3] }}</td>
                                <td>{{ $row[4] }}</td>
                                <td>{{ $row[5] }}</td>
                                <td>{{ $row[6] }}</td>
                                <td>{{ $row[7] }}</td>
                                <td>{{ $row[8] }}</td>
                                <td>{{ $row[9] }}</td>
                                <td><x-admin.status-badge :status="$row[10]" /></td>
                                <td><button class="btn btn-secondary" type="button">Edit</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section data-tab-panel="customers" hidden>
            <div class="toolbar toolbar-narrow">
                <input type="search" placeholder="Search..." aria-label="Search customers">
                <button class="btn btn-primary" type="button">Date</button>
                <button class="btn btn-primary" type="button">Depot</button>
                <button class="btn btn-primary" type="button">Fuel Type (All)</button>
                <button class="btn btn-primary" type="button">+ Add Customers</button>
            </div>
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
                            <th>Payment Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($customers as $row)
                            <tr class="{{ $row[7] }}">
                                <td>{{ $row[0] }}</td>
                                <td>{{ $row[1] }}</td>
                                <td>{{ $row[2] }}</td>
                                <td>{{ $row[3] }}</td>
                                <td>{{ $row[4] }}</td>
                                <td>{{ $row[5] }}</td>
                                <td><x-admin.status-badge :status="$row[6]" /></td>
                                <td><button class="btn btn-secondary" type="button">Edit</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endcomponent
