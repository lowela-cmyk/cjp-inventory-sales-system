@php
    $drivers = [
        ['DRV-000001', 'Manuel P. Ligaya', 'N01-22-555555', 'manuel.ligaya@example.com', '09876543219'],
        ['DRV-000002', 'Ariel C. Santos', 'N01-21-444444', 'ariel.santos@example.com', '09181234567'],
    ];
    $staff = [
        ['EMP-000001', 'Maria C. Pilar', 'Inventory Clerk', 'maria.pilar@example.com', '09175550111'],
        ['EMP-000002', 'Carlo M. Dizon', 'Sales Coordinator', 'carlo.dizon@example.com', '09175550222'],
    ];
    $customers = [
        ['CSM-000001', 'Ken C. Binhi', 'Binhi Green Homes', 'Nasugbu, Batangas', 'binhigreenhomes@gmail.com', '09876543211'],
        ['CSM-000002', 'Jay P. Calinisan', 'Jay P Constructions', 'Lian, Batangas', 'jay.construction@example.com', '09181231234'],
    ];
@endphp

@component('layouts.admin', ['title' => 'User Management', 'active' => 'user-management'])
    <div data-tabs>
        <h2 class="section-title">Drivers</h2>
        <div class="tabs">
            <button class="tab-button is-active" type="button" data-tab-target="drivers">Drivers</button>
            <button class="tab-button" type="button" data-tab-target="office">Office Staff</button>
            <button class="tab-button" type="button" data-tab-target="customers">Customers</button>
        </div>
        <div class="actions-right"><button class="btn btn-secondary" type="button">Export</button></div>

        <section data-tab-panel="drivers">
            <div class="toolbar toolbar-narrow">
                <input type="search" placeholder="Search..." aria-label="Search drivers">
                <button class="btn btn-primary" type="button">License</button>
                <button class="btn btn-primary" type="button">Contact</button>
                <button class="btn btn-primary" type="button" data-modal-open="driver-add">+ Add Driver</button>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Driver ID</th><th>Name</th><th>License No.</th><th>Email</th><th>Contact Number</th><th>Actions</th></tr></thead>
                    <tbody>
                        @foreach ($drivers as $row)
                            <tr><td>{{ $row[0] }}</td><td>{{ $row[1] }}</td><td>{{ $row[2] }}</td><td>{{ $row[3] }}</td><td>{{ $row[4] }}</td><td><button class="btn btn-secondary" type="button" data-modal-open="driver-edit">Edit</button></td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section data-tab-panel="office" hidden>
            <div class="toolbar toolbar-narrow">
                <input type="search" placeholder="Search..." aria-label="Search office staff">
                <button class="btn btn-primary" type="button">Position</button>
                <button class="btn btn-primary" type="button">Contact</button>
                <button class="btn btn-primary" type="button" data-modal-open="staff-add">+ Add Staff</button>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Staff ID</th><th>Name</th><th>Position</th><th>Email</th><th>Contact Number</th><th>Actions</th></tr></thead>
                    <tbody>
                        @foreach ($staff as $row)
                            <tr><td>{{ $row[0] }}</td><td>{{ $row[1] }}</td><td>{{ $row[2] }}</td><td>{{ $row[3] }}</td><td>{{ $row[4] }}</td><td><button class="btn btn-secondary" type="button" data-modal-open="staff-edit">Edit</button></td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section data-tab-panel="customers" hidden>
            <div class="toolbar toolbar-narrow">
                <input type="search" placeholder="Search..." aria-label="Search customers">
                <button class="btn btn-primary" type="button">Location</button>
                <button class="btn btn-primary" type="button">Company</button>
                <button class="btn btn-primary" type="button" data-modal-open="customer-add">+ Add Customer</button>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Customer ID</th><th>Customer Name</th><th>Company Name</th><th>Location</th><th>Email</th><th>Contact Number</th><th>Actions</th></tr></thead>
                    <tbody>
                        @foreach ($customers as $row)
                            <tr><td>{{ $row[0] }}</td><td>{{ $row[1] }}</td><td>{{ $row[2] }}</td><td>{{ $row[3] }}</td><td>{{ $row[4] }}</td><td>{{ $row[5] }}</td><td><button class="btn btn-secondary" type="button" data-modal-open="customer-edit">Edit</button></td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <x-admin.modal id="driver-add" title="Add Driver" wide>
        @include('admin.partials.entity-form', ['fields' => ['Driver Name', 'License Number', 'Email', 'Contact Number']])
    </x-admin.modal>
    <x-admin.modal id="staff-add" title="Add Staff" wide>
        @include('admin.partials.entity-form', ['fields' => ['Staff Name', 'Position', 'Email', 'Contact Number']])
    </x-admin.modal>
    <x-admin.modal id="customer-add" title="Add Customers" wide>
        @include('admin.partials.entity-form', ['fields' => ['Customer ID', 'Customer Name', 'Company Name', 'Location', 'Email', 'Contact Number']])
    </x-admin.modal>

    <x-admin.modal id="driver-edit" title="Edit Driver Record">
        @include('admin.partials.entity-detail', ['id' => 'DRV-000001', 'rows' => ['Driver Name' => 'Manuel P. Ligaya', 'License Number' => 'N01-22-555555', 'Email' => 'manuel.ligaya@example.com', 'Contact Number' => '09876543219']])
    </x-admin.modal>
    <x-admin.modal id="staff-edit" title="Edit Staff Record">
        @include('admin.partials.entity-detail', ['id' => 'EMP-000001', 'rows' => ['Staff Name' => 'Maria C. Pilar', 'Position' => 'Inventory Clerk', 'Email' => 'maria.pilar@example.com', 'Contact Number' => '09175550111']])
    </x-admin.modal>
    <x-admin.modal id="customer-edit" title="Edit Customer Record">
        @include('admin.partials.entity-detail', ['id' => 'CSM-000001', 'rows' => ['Customer Name' => 'Ken C. Binhi', 'Company Name' => 'Binhi Green Homes', 'Location' => 'Nasugbu, Batangas', 'Email' => 'binhigreenhomes@gmail.com', 'Contact Number' => '09876543211']])
    </x-admin.modal>
@endcomponent
