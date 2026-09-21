@component($layout, ['title' => 'Truck Management', 'active' => 'trucks'])
    <div class="fleet-page">
        <div class="section-heading-row">
            <div>
                <h2 class="section-title">Trucks</h2>
                <p class="section-subtitle">Track each vehicle by plate number and see live lifting availability.</p>
            </div>
            <button class="btn btn-primary" type="button" data-modal-open="truck-add">+ Add Truck</button>
        </div>

        @if (session('status'))
            <div class="admin-flash admin-flash-success" role="status">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="admin-flash admin-flash-error" role="alert">{{ $errors->first() }}</div>
        @endif

        <form class="toolbar fleet-toolbar" method="GET" action="{{ route($routePrefix) }}">
            <input name="search" type="search" value="{{ $search }}" placeholder="Search plate, code, or truck name" aria-label="Search trucks">
            <select name="status" aria-label="Filter by availability">
                <option value="">All statuses</option>
                @foreach (['available', 'assigned', 'in_use', 'maintenance', 'inactive'] as $status)
                    <option value="{{ $status }}" @selected($statusFilter === $status)>{{ ucwords(str_replace('_', ' ', $status)) }}</option>
                @endforeach
            </select>
            <button class="btn btn-secondary" type="submit">Filter</button>
            @if ($search !== '' || $statusFilter)
                <a class="btn btn-light" href="{{ route($routePrefix) }}">Clear</a>
            @endif
        </form>

        <section class="admin-card fleet-card">
            <div class="table-wrap">
                <table class="admin-table fleet-table">
                    <thead><tr><th>Plate Number</th><th>Truck Code</th><th>Name / Description</th><th>Type</th><th>Capacity</th><th>Status</th><th>Active Lifts</th><th>Next Schedule</th><th>Actions</th></tr></thead>
                    <tbody>
                        @forelse ($trucks as $truck)
                            <tr>
                                <td><strong>{{ $truck->plate_number }}</strong></td>
                                <td>{{ $truck->truck_code }}</td>
                                <td><strong>{{ $truck->name }}</strong><span class="table-subtext">{{ $truck->description ?: 'No description' }}</span></td>
                                <td>{{ ucfirst($truck->truck_type) }}</td>
                                <td>{{ number_format((float) $truck->capacity_liters, 2) }} L</td>
                                <td><x-admin.status-badge :status="ucwords(str_replace('_', ' ', $truck->effective_status))" /></td>
                                <td>{{ number_format((int) $truck->active_lift_count) }}</td>
                                <td>{{ $truck->next_schedule ? \Carbon\CarbonImmutable::parse($truck->next_schedule)->format('M d, Y g:i A') : '—' }}</td>
                                <td><button class="btn btn-secondary" type="button" data-modal-open="truck-edit-{{ $truck->id }}">View / Edit</button></td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="9">No trucks found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="pagination-wrap">{{ $trucks->links() }}</div>
    </div>

    <x-admin.modal id="truck-add" title="Add Truck" wide>
        <form method="POST" action="{{ route($routePrefix.'.store') }}" data-prevent-double-submit>
            @csrf
            @include('trucks.partials.form', ['truck' => null])
            <div class="modal-actions"><button class="btn btn-primary" type="submit">Add Truck</button><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button></div>
        </form>
    </x-admin.modal>

    @foreach ($trucks as $truck)
        <x-admin.modal id="truck-edit-{{ $truck->id }}" title="Truck Details" wide>
            <div class="fleet-status-banner">
                <div><span>Current availability</span><x-admin.status-badge :status="ucwords(str_replace('_', ' ', $truck->effective_status))" /></div>
                <div><span>Active lift records</span><strong>{{ number_format((int) $truck->active_lift_count) }}</strong></div>
            </div>
            <form method="POST" action="{{ route($routePrefix.'.update', $truck->id) }}" data-prevent-double-submit>
                @csrf
                @method('PATCH')
                @include('trucks.partials.form', ['truck' => $truck])
                <div class="modal-actions">
                    <button class="btn btn-primary" type="submit">Save Changes</button>
                    <button class="btn btn-secondary" type="button" data-modal-close>Close</button>
                </div>
            </form>
            <form class="fleet-activation-form" method="POST" action="{{ route($routePrefix.'.status', $truck->id) }}" data-confirm-message="{{ $truck->status === 'inactive' ? 'Activate this truck?' : 'Deactivate this truck? Historical lift records will be preserved.' }}">
                @csrf
                @method('PATCH')
                <button class="btn {{ $truck->status === 'inactive' ? 'btn-secondary' : 'btn-danger' }}" type="submit" @disabled((int) $truck->active_lift_count > 0)>{{ $truck->status === 'inactive' ? 'Activate Truck' : 'Deactivate Truck' }}</button>
                @if ((int) $truck->active_lift_count > 0)<small>Complete or cancel the active lift before deactivating this truck.</small>@endif
            </form>
        </x-admin.modal>
    @endforeach
@endcomponent
