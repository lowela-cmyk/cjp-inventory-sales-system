@php
    $activeTab = in_array($activeTab, ['office', 'drivers', 'customers'], true) ? $activeTab : 'office';
    $staffId = fn ($user) => 'EMP-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT);
    $driverId = fn ($driver) => $driver->driver_code ?: 'DRV-'.str_pad((string) $driver->id, 6, '0', STR_PAD_LEFT);
@endphp

@component('layouts.admin', ['title' => 'User Management', 'active' => 'user-management'])
    <div data-tabs>
        <h2 class="section-title">Office Staff</h2>

        @if (session('status'))
            <div class="admin-flash admin-flash-success" role="status">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="admin-flash admin-flash-error" role="alert">{{ $errors->first() }}</div>
        @endif

        <div class="tabs">
            <button class="tab-button {{ $activeTab === 'office' ? 'is-active' : '' }}" type="button" data-tab-target="office">Office Staff</button>
            <button class="tab-button {{ $activeTab === 'drivers' ? 'is-active' : '' }}" type="button" data-tab-target="drivers">Drivers</button>
            <button class="tab-button {{ $activeTab === 'customers' ? 'is-active' : '' }}" type="button" data-tab-target="customers">Customers</button>
        </div>
        <div class="actions-right"><button class="btn btn-secondary" type="button">Export</button></div>

        <section data-tab-panel="office" {{ $activeTab !== 'office' ? 'hidden' : '' }}>
            <div class="toolbar toolbar-narrow">
                <input type="search" placeholder="Search..." aria-label="Search office staff">
                <button class="btn btn-primary" type="button">Position</button>
                <button class="btn btn-primary" type="button">Contact</button>
                <button class="btn btn-primary" type="button" data-modal-open="staff-add">+ Add Office Staff</button>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Staff ID</th><th>Name</th><th>Position</th><th>Email</th><th>Contact Number</th><th>Actions</th></tr></thead>
                    <tbody>
                        @forelse ($staff as $row)
                            <tr>
                                <td>{{ $staffId($row) }}</td>
                                <td>{{ $row->name }}</td>
                                <td>{{ $row->role_label }} / {{ ucfirst($row->status) }}</td>
                                <td>{{ $row->email }}</td>
                                <td>{{ $row->phone ?: 'N/A' }}</td>
                                <td><button class="btn btn-secondary" type="button" data-modal-open="staff-edit-{{ $row->id }}">Edit</button></td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="6">No office staff accounts found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section data-tab-panel="drivers" {{ $activeTab !== 'drivers' ? 'hidden' : '' }}>
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
                        @forelse ($drivers as $row)
                            <tr>
                                <td>{{ $driverId($row) }}</td>
                                <td>{{ $row->name }}</td>
                                <td>{{ $row->license_number ?: 'N/A' }}</td>
                                <td>{{ $row->email }}</td>
                                <td>{{ $row->phone ?: 'N/A' }}</td>
                                <td><button class="btn btn-secondary" type="button" data-modal-open="driver-edit-{{ $row->id }}">Edit</button></td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="6">No driver accounts found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section data-tab-panel="customers" {{ $activeTab !== 'customers' ? 'hidden' : '' }}>
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
                        @forelse ($customers as $row)
                            <tr>
                                <td>{{ 'CSM-'.str_pad((string) $row->id, 6, '0', STR_PAD_LEFT) }}</td>
                                <td>{{ $row->name }}</td>
                                <td>{{ $row->company_name }}</td>
                                <td>{{ $row->location ?: 'N/A' }}</td>
                                <td>{{ $row->email ?: 'N/A' }}</td>
                                <td>{{ $row->phone ?: 'N/A' }}</td>
                                <td><button class="btn btn-secondary" type="button" data-modal-open="customer-edit-{{ $row->id }}">Edit</button></td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="7">No customer records found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <x-admin.modal id="staff-add" title="Add Staff" wide>
        <form method="POST" action="{{ route('admin.user-management.staff.store') }}">
            @csrf
            <div class="modal-card">
                <div class="form-grid">
                    <div class="form-row"><label for="staff_name">Staff Name</label><input id="staff_name" name="name" type="text" placeholder="Enter Staff Name" value="{{ old('name') }}" required></div>
                    <div class="form-row"><label for="staff_role">Position</label><select id="staff_role" name="role" required><option value="" disabled @selected(! old('role'))>Select Position</option>@foreach ($officeRoles as $value => $label)<option value="{{ $value }}" @selected(old('role') === $value)>{{ $label }}</option>@endforeach</select></div>
                    <div class="form-row"><label for="staff_email">Email</label><input id="staff_email" name="email" type="email" placeholder="Enter Email" value="{{ old('email') }}" required></div>
                    <div class="form-row"><label for="staff_phone">Contact Number</label><input id="staff_phone" name="phone" type="tel" placeholder="Enter Contact Number" value="{{ old('phone') }}"></div>
                    <div class="form-row"><label for="staff_status">Status</label><select id="staff_status" name="status" required>@foreach ($statuses as $status)<option value="{{ $status }}" @selected(old('status', 'active') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></div>
                    <div class="form-row"><label for="staff_password">Password</label><input id="staff_password" name="password" type="password" placeholder="Enter Password" required></div>
                    <div class="form-row"><label for="staff_password_confirmation">Confirm Password</label><input id="staff_password_confirmation" name="password_confirmation" type="password" placeholder="Confirm Password" required></div>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-pill btn-secondary" type="submit">Add</button>
                <button class="btn btn-pill btn-danger" type="button" data-modal-close>Cancel</button>
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal id="driver-add" title="Add Driver" wide>
        <form method="POST" action="{{ route('admin.user-management.drivers.store') }}">
            @csrf
            <input type="hidden" name="role" value="driver">
            <div class="modal-card">
                <div class="form-grid">
                    <div class="form-row"><label for="driver_name">Driver Name</label><input id="driver_name" name="name" type="text" placeholder="Enter Driver Name" value="{{ old('name') }}" required></div>
                    <div class="form-row"><label for="license_number">License Number</label><input id="license_number" name="license_number" type="text" placeholder="Enter License Number" value="{{ old('license_number') }}"></div>
                    <div class="form-row"><label for="driver_email">Email</label><input id="driver_email" name="email" type="email" placeholder="Enter Email" value="{{ old('email') }}" required></div>
                    <div class="form-row"><label for="driver_phone">Contact Number</label><input id="driver_phone" name="phone" type="tel" placeholder="Enter Contact Number" value="{{ old('phone') }}"></div>
                    <div class="form-row"><label for="driver_status">Status</label><select id="driver_status" name="status" required>@foreach ($statuses as $status)<option value="{{ $status }}" @selected(old('status', 'active') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></div>
                    <div class="form-row"><label for="driver_password">Password</label><input id="driver_password" name="password" type="password" placeholder="Enter Password" required></div>
                    <div class="form-row"><label for="driver_password_confirmation">Confirm Password</label><input id="driver_password_confirmation" name="password_confirmation" type="password" placeholder="Confirm Password" required></div>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-pill btn-secondary" type="submit">Add</button>
                <button class="btn btn-pill btn-danger" type="button" data-modal-close>Cancel</button>
            </div>
        </form>
    </x-admin.modal>

    <x-admin.modal id="customer-add" title="Add Customers" wide>
        <div class="modal-card"><p class="detail-value">Customer records are connected from the existing customers table and are managed in the customer/sales workflow.</p></div>
        <div class="modal-actions"><button class="btn btn-pill btn-danger" type="button" data-modal-close>Close</button></div>
    </x-admin.modal>

    @foreach ($staff as $row)
        <x-admin.modal id="staff-edit-{{ $row->id }}" title="Edit Staff Record">
            <form id="staff-update-{{ $row->id }}" method="POST" action="{{ route('admin.user-management.staff.update', $row) }}">
                @csrf
                @method('PATCH')
            </form>
            <div class="modal-card">
                <p class="detail-id">{{ $staffId($row) }}</p>
                <div class="form-grid">
                    <div class="form-row"><label for="staff_name_{{ $row->id }}">Staff Name</label><input form="staff-update-{{ $row->id }}" id="staff_name_{{ $row->id }}" name="name" type="text" value="{{ old('name', $row->name) }}" required></div>
                    <div class="form-row"><label for="staff_role_{{ $row->id }}">Position</label><select form="staff-update-{{ $row->id }}" id="staff_role_{{ $row->id }}" name="role" required>@foreach ($roles as $value => $label)<option value="{{ $value }}" @selected(old('role', $row->role) === $value)>{{ $label }}</option>@endforeach</select></div>
                    <div class="form-row"><label for="staff_email_{{ $row->id }}">Email</label><input form="staff-update-{{ $row->id }}" id="staff_email_{{ $row->id }}" name="email" type="email" value="{{ old('email', $row->email) }}" required></div>
                    <div class="form-row"><label for="staff_phone_{{ $row->id }}">Contact Number</label><input form="staff-update-{{ $row->id }}" id="staff_phone_{{ $row->id }}" name="phone" type="tel" value="{{ old('phone', $row->phone) }}"></div>
                    <div class="form-row"><label for="staff_status_{{ $row->id }}">Status</label><select form="staff-update-{{ $row->id }}" id="staff_status_{{ $row->id }}" name="status" required>@foreach ($statuses as $status)<option value="{{ $status }}" @selected(old('status', $row->status) === $status)>{{ ucfirst($status) }}</option>@endforeach</select></div>
                    <div class="form-row"><label for="staff_password_{{ $row->id }}">Password</label><input form="staff-update-{{ $row->id }}" id="staff_password_{{ $row->id }}" name="password" type="password" placeholder="Leave blank to keep current password"></div>
                    <div class="form-row"><label for="staff_password_confirmation_{{ $row->id }}">Confirm Password</label><input form="staff-update-{{ $row->id }}" id="staff_password_confirmation_{{ $row->id }}" name="password_confirmation" type="password" placeholder="Confirm New Password"></div>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-pill btn-secondary" type="submit" form="staff-update-{{ $row->id }}">Edit</button>
                <form method="POST" action="{{ route('admin.user-management.users.status', $row) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="tab" value="office">
                    <input type="hidden" name="status" value="{{ $row->status === 'active' ? 'inactive' : 'active' }}">
                    <button class="btn btn-pill btn-danger" type="submit">{{ $row->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                </form>
            </div>
        </x-admin.modal>
    @endforeach

    @foreach ($drivers as $row)
        <x-admin.modal id="driver-edit-{{ $row->id }}" title="Edit Driver Record">
            <form id="driver-update-{{ $row->id }}" method="POST" action="{{ route('admin.user-management.drivers.update', $row) }}">
                @csrf
                @method('PATCH')
            </form>
            <div class="modal-card">
                <p class="detail-id">{{ $driverId($row) }}</p>
                <div class="form-grid">
                    <div class="form-row"><label for="driver_name_{{ $row->id }}">Driver Name</label><input form="driver-update-{{ $row->id }}" id="driver_name_{{ $row->id }}" name="name" type="text" value="{{ old('name', $row->name) }}" required></div>
                    <div class="form-row"><label for="driver_role_{{ $row->id }}">Position</label><select form="driver-update-{{ $row->id }}" id="driver_role_{{ $row->id }}" name="role" required>@foreach ($roles as $value => $label)<option value="{{ $value }}" @selected(old('role', $row->role) === $value)>{{ $label }}</option>@endforeach</select></div>
                    <div class="form-row"><label for="license_number_{{ $row->id }}">License Number</label><input form="driver-update-{{ $row->id }}" id="license_number_{{ $row->id }}" name="license_number" type="text" value="{{ old('license_number', $row->license_number) }}"></div>
                    <div class="form-row"><label for="driver_email_{{ $row->id }}">Email</label><input form="driver-update-{{ $row->id }}" id="driver_email_{{ $row->id }}" name="email" type="email" value="{{ old('email', $row->email) }}" required></div>
                    <div class="form-row"><label for="driver_phone_{{ $row->id }}">Contact Number</label><input form="driver-update-{{ $row->id }}" id="driver_phone_{{ $row->id }}" name="phone" type="tel" value="{{ old('phone', $row->phone) }}"></div>
                    <div class="form-row"><label for="driver_status_{{ $row->id }}">Status</label><select form="driver-update-{{ $row->id }}" id="driver_status_{{ $row->id }}" name="status" required>@foreach ($statuses as $status)<option value="{{ $status }}" @selected(old('status', $row->status) === $status)>{{ ucfirst($status) }}</option>@endforeach</select></div>
                    <div class="form-row"><label for="driver_password_{{ $row->id }}">Password</label><input form="driver-update-{{ $row->id }}" id="driver_password_{{ $row->id }}" name="password" type="password" placeholder="Leave blank to keep current password"></div>
                    <div class="form-row"><label for="driver_password_confirmation_{{ $row->id }}">Confirm Password</label><input form="driver-update-{{ $row->id }}" id="driver_password_confirmation_{{ $row->id }}" name="password_confirmation" type="password" placeholder="Confirm New Password"></div>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-pill btn-secondary" type="submit" form="driver-update-{{ $row->id }}">Edit</button>
                <form method="POST" action="{{ route('admin.user-management.users.status', $row) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="tab" value="drivers">
                    <input type="hidden" name="status" value="{{ $row->status === 'active' ? 'inactive' : 'active' }}">
                    <button class="btn btn-pill btn-danger" type="submit">{{ $row->status === 'active' ? 'Deactivate' : 'Activate' }}</button>
                </form>
            </div>
        </x-admin.modal>
    @endforeach

    @foreach ($customers as $row)
        <x-admin.modal id="customer-edit-{{ $row->id }}" title="Edit Customer Record">
            <div class="modal-card">
                <p class="detail-id">{{ 'CSM-'.str_pad((string) $row->id, 6, '0', STR_PAD_LEFT) }}</p>
                <div class="detail-grid">
                    <div class="detail-row"><div class="detail-label">Customer Name</div><div class="detail-value">{{ $row->name }}</div></div>
                    <div class="detail-row"><div class="detail-label">Company Name</div><div class="detail-value">{{ $row->company_name }}</div></div>
                    <div class="detail-row"><div class="detail-label">Location</div><div class="detail-value">{{ $row->location ?: 'N/A' }}</div></div>
                    <div class="detail-row"><div class="detail-label">Email</div><div class="detail-value">{{ $row->email ?: 'N/A' }}</div></div>
                    <div class="detail-row"><div class="detail-label">Contact Number</div><div class="detail-value">{{ $row->phone ?: 'N/A' }}</div></div>
                </div>
            </div>
            <div class="modal-actions"><button class="btn btn-pill btn-danger" type="button" data-modal-close>Close</button></div>
        </x-admin.modal>
    @endforeach
@endcomponent
