@php
    $activeTab = in_array($activeTab, ['office', 'drivers'], true) ? $activeTab : 'office';
    $staffId = fn ($user) => 'EMP-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT);
    $driverId = fn ($driver) => $driver->driver_code ?: 'DRV-'.str_pad((string) $driver->id, 6, '0', STR_PAD_LEFT);
    $filters = $filters ?? [];
    $filterOptions = $filterOptions ?? ['statuses' => [], 'approvalStatuses' => [], 'locations' => collect(), 'companies' => collect()];
    $tabFilters = fn (string $tab): array => $tab === $activeTab ? $filters : [];
@endphp

@component('layouts.admin', ['title' => 'User Management', 'active' => 'user-management'])
    <div data-tabs>
        <h2 class="section-title" data-tab-heading>{{ ['office' => 'Office Staff', 'drivers' => 'Drivers'][$activeTab] }}</h2>



        <div class="tabs">
            <button class="tab-button {{ $activeTab === 'office' ? 'is-active' : '' }}" type="button" data-tab-target="office" data-heading="Office Staff" data-tab-url="{{ route('admin.user-management', ['tab' => 'office']) }}">Office Staff</button>
            <button class="tab-button {{ $activeTab === 'drivers' ? 'is-active' : '' }}" type="button" data-tab-target="drivers" data-heading="Drivers" data-tab-url="{{ route('admin.user-management', ['tab' => 'drivers']) }}">Drivers</button>
        </div>
        <div class="actions-right">
            <a
                class="btn btn-secondary"
                href="{{ route('admin.user-management.export', ['tab' => $activeTab]) }}"
                data-tab-export-url="{{ route('admin.user-management.export', ['tab' => '__TAB__']) }}"
            >Export</a>
        </div>

        <section data-tab-panel="office" {{ $activeTab !== 'office' ? 'hidden' : '' }}>
            <x-filter.bar class="toolbar-narrow" :action="route('admin.user-management')">
                <input type="hidden" name="tab" value="office">
                <x-filter.search label="Search office staff" :value="$tabFilters('office')['search'] ?? null" />
                <x-filter.select name="role" label="Position" :options="$officeRoles" :selected="$tabFilters('office')['role'] ?? null" />
                <x-filter.select name="status" label="Account Status" :options="$filterOptions['statuses']" :selected="$tabFilters('office')['status'] ?? null" />
                <x-filter.select name="approval_status" label="Approval Status" :options="$filterOptions['approvalStatuses']" :selected="$tabFilters('office')['approval_status'] ?? null" />
                <button class="btn btn-secondary btn-wide" type="button" data-modal-open="staff-add">+ Add Office Staff</button>
            </x-filter.bar>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Staff ID</th><th>Name</th><th>Position</th><th>Account Status</th><th>Approval Status</th><th>Email</th><th>Contact Number</th><th>Actions</th></tr></thead>
                    <tbody>
                        @forelse ($staff as $row)
                            <tr>
                                <td>{{ $staffId($row) }}</td>
                                <td>{{ $row->name }}</td>
                                <td>{{ $row->role_label }}</td>
                                <td><x-admin.status-badge :status="ucfirst($row->status)" /></td>
                                <td><x-admin.status-badge :status="ucfirst($row->approval_status)" /></td>
                                <td>{{ $row->email }}</td>
                                <td>{{ $row->phone ?: 'N/A' }}</td>
                                <td><button class="btn btn-secondary" type="button" data-modal-open="staff-edit-{{ $row->id }}">Edit</button></td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="8">No office staff accounts found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if (method_exists($staff, 'links'))
                <div class="pagination-wrap" style="margin-top: 16px;">
                    {{ $staff->links() }}
                </div>
            @endif
        </section>

        <section data-tab-panel="drivers" {{ $activeTab !== 'drivers' ? 'hidden' : '' }}>
            <x-filter.bar class="toolbar-narrow" :action="route('admin.user-management')">
                <input type="hidden" name="tab" value="drivers">
                <x-filter.search label="Search drivers" :value="$tabFilters('drivers')['search'] ?? null" />
                <x-filter.select name="status" label="Account Status" :options="$filterOptions['statuses']" :selected="$tabFilters('drivers')['status'] ?? null" />
                <x-filter.select name="approval_status" label="Approval Status" :options="$filterOptions['approvalStatuses']" :selected="$tabFilters('drivers')['approval_status'] ?? null" />
                <button class="btn btn-secondary btn-wide" type="button" data-modal-open="driver-add">+ Add Driver</button>
            </x-filter.bar>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Driver ID</th><th>Name</th><th>License No.</th><th>Account Status</th><th>Approval Status</th><th>Email</th><th>Contact Number</th><th>Actions</th></tr></thead>
                    <tbody>
                        @forelse ($drivers as $row)
                            <tr>
                                <td>{{ $driverId($row) }}</td>
                                <td>{{ $row->name }}</td>
                                <td>{{ $row->license_number ?: 'N/A' }}</td>
                                <td><x-admin.status-badge :status="ucfirst($row->status)" /></td>
                                <td><x-admin.status-badge :status="ucfirst($row->approval_status)" /></td>
                                <td>{{ $row->email }}</td>
                                <td>{{ $row->phone ?: 'N/A' }}</td>
                                <td><button class="btn btn-secondary" type="button" data-modal-open="driver-edit-{{ $row->id }}">Edit</button></td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="8">No driver accounts found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if (method_exists($drivers, 'links'))
                <div class="pagination-wrap" style="margin-top: 16px;">
                    {{ $drivers->links() }}
                </div>
            @endif
        </section>

    </div>

    <x-admin.modal id="staff-add" reset-on-close title="Add Staff" wide>
        <form method="POST" action="{{ route('admin.user-management.staff.store') }}">
            @csrf
            <div class="modal-card">
                <div class="form-grid">
                    <div class="form-row"><label for="staff_name">Staff Name</label><input id="staff_name" name="name" type="text" placeholder="Enter Staff Name" value="{{ old('name') }}" required></div>
                    <div class="form-row"><label for="staff_role">Position</label><select id="staff_role" name="role" required><option value="" disabled @selected(! old('role'))>Select Position</option>@foreach ($officeRoles as $value => $label)<option value="{{ $value }}" @selected(old('role') === $value)>{{ $label }}</option>@endforeach</select></div>
                    <div class="form-row"><label for="staff_email">Email</label><input id="staff_email" name="email" type="email" placeholder="Enter Email" value="{{ old('email') }}" required></div>
                    <div class="form-row"><label for="staff_phone">Contact Number</label><input id="staff_phone" name="phone" type="tel" placeholder="Enter Contact Number" value="{{ old('phone') }}"></div>
                    <div class="form-row"><label for="staff_status">Status</label><select id="staff_status" name="status" required data-default="active">@foreach ($statuses as $status)<option value="{{ $status }}" @selected(old('status', 'active') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></div>
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

    <x-admin.modal id="driver-add" reset-on-close title="Add Driver" wide>
        <form method="POST" action="{{ route('admin.user-management.drivers.store') }}">
            @csrf
            <input type="hidden" name="role" value="driver">
            <div class="modal-card">
                <div class="form-grid">
                    <div class="form-row"><label for="driver_name">Driver Name</label><input id="driver_name" name="name" type="text" placeholder="Enter Driver Name" value="{{ old('name') }}" required></div>
                    <div class="form-row"><label for="license_number">License Number</label><input id="license_number" name="license_number" type="text" placeholder="Enter License Number" value="{{ old('license_number') }}"></div>
                    <div class="form-row"><label for="driver_email">Email</label><input id="driver_email" name="email" type="email" placeholder="Enter Email" value="{{ old('email') }}" required></div>
                    <div class="form-row"><label for="driver_phone">Contact Number</label><input id="driver_phone" name="phone" type="tel" placeholder="Enter Contact Number" value="{{ old('phone') }}"></div>
                    <div class="form-row"><label for="driver_status">Status</label><select id="driver_status" name="status" required data-default="active">@foreach ($statuses as $status)<option value="{{ $status }}" @selected(old('status', 'active') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></div>
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
                    <div class="form-row"><label>Approval Status</label><div class="form-status-value"><x-admin.status-badge :status="ucfirst($row->approval_status)" /></div></div>
                    <div class="form-row"><label for="staff_password_{{ $row->id }}">Password</label><input form="staff-update-{{ $row->id }}" id="staff_password_{{ $row->id }}" name="password" type="password" placeholder="Leave blank to keep current password"></div>
                    <div class="form-row"><label for="staff_password_confirmation_{{ $row->id }}">Confirm Password</label><input form="staff-update-{{ $row->id }}" id="staff_password_confirmation_{{ $row->id }}" name="password_confirmation" type="password" placeholder="Confirm New Password"></div>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-pill btn-secondary" type="submit" form="staff-update-{{ $row->id }}">Apply Changes</button>
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
                    <div class="form-row"><label>Approval Status</label><div class="form-status-value"><x-admin.status-badge :status="ucfirst($row->approval_status)" /></div></div>
                    <div class="form-row"><label for="driver_password_{{ $row->id }}">Password</label><input form="driver-update-{{ $row->id }}" id="driver_password_{{ $row->id }}" name="password" type="password" placeholder="Leave blank to keep current password"></div>
                    <div class="form-row"><label for="driver_password_confirmation_{{ $row->id }}">Confirm Password</label><input form="driver-update-{{ $row->id }}" id="driver_password_confirmation_{{ $row->id }}" name="password_confirmation" type="password" placeholder="Confirm New Password"></div>
                </div>
            </div>
            <div class="modal-actions">
                <button class="btn btn-pill btn-secondary" type="submit" form="driver-update-{{ $row->id }}">Apply Changes</button>
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

@endcomponent
