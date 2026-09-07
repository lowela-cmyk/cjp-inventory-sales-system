@php
    $activeTab = $activeTab ?? (($state ?? 'schedule') === 'hauled' ? 'hauled' : 'schedule');
    $filters = $filters ?? [];
    $filterOptions = $filterOptions ?? ['statuses' => [], 'fuelTypes' => collect(), 'drivers' => collect(), 'trucks' => collect()];
    $summaryCards = $summaryCards ?? [];
    $purchaseItems = $purchaseItems ?? collect();
@endphp

@component('layouts.dispatch', ['title' => 'Fuel Lifting Operations', 'active' => 'fuel-lifting'])
    <div data-tabs>
        <h2 class="section-title">Schedule</h2>

        @if (session('status'))
            <div class="alert-bar alert-warning" style="margin-bottom:14px">
                <div class="alert-icon">!</div>
                <div><div class="alert-title">{{ session('status') }}</div></div>
                <span></span>
            </div>
        @endif

        @if ($errors->any())
            <div class="alert-bar alert-critical" style="margin-bottom:14px">
                <div class="alert-icon">!</div>
                <div><div class="alert-title">{{ $errors->first() }}</div></div>
                <span></span>
            </div>
        @endif

        <div class="tabs">
            <button class="tab-button {{ $activeTab === 'schedule' ? 'is-active' : '' }}" type="button" data-tab-target="schedule">Schedule</button>
            <button class="tab-button {{ $activeTab === 'hauled' ? 'is-active' : '' }}" type="button" data-tab-target="hauled">Hauled</button>
        </div>
        <div class="actions-right">
            <button class="btn btn-secondary" type="button" data-export-table>Export</button>
        </div>

        @if (! empty($summaryCards))
            <div class="metric-row">
                @foreach ($summaryCards as $card)
                    <div class="metric-card">
                        <em>{{ $card['label'] }}</em>
                        <strong>{{ $card['value'] }}</strong>
                    </div>
                @endforeach
            </div>
        @endif

        <section data-tab-panel="schedule" {{ $activeTab !== 'schedule' ? 'hidden' : '' }}>
            <form class="dispatch-filter-row dispatch-fuel-filter" method="GET" action="{{ route('dispatch.fuel-lifting') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search scheduled lifts" value="{{ $search }}">
                <input type="date" name="date_from" aria-label="Filter from date" value="{{ $filters['date_from'] ?? '' }}">
                <input type="date" name="date_to" aria-label="Filter to date" value="{{ $filters['date_to'] ?? '' }}">
                <select name="fuel_type_id" aria-label="Filter by fuel type">
                    <option value="">Fuel Type (All)</option>
                    @foreach ($filterOptions['fuelTypes'] as $fuelType)
                        <option value="{{ $fuelType->id }}" @selected((string) ($filters['fuel_type_id'] ?? '') === (string) $fuelType->id)>{{ $fuelType->name }}</option>
                    @endforeach
                </select>
                <select name="driver_user_id" aria-label="Filter by driver">
                    <option value="">Driver (All)</option>
                    @foreach ($filterOptions['drivers'] as $driver)
                        <option value="{{ $driver->id }}" @selected((string) ($filters['driver_user_id'] ?? '') === (string) $driver->id)>{{ $driver->name }}</option>
                    @endforeach
                </select>
                <select name="truck_id" aria-label="Filter by truck">
                    <option value="">Truck (All)</option>
                    @foreach ($filterOptions['trucks'] as $truck)
                        <option value="{{ $truck->id }}" @selected((string) ($filters['truck_id'] ?? '') === (string) $truck->id)>{{ $truck->truck_code }}{{ $truck->plate_number ? ' / '.$truck->plate_number : '' }}</option>
                    @endforeach
                </select>
                <button class="btn btn-primary" type="submit">Filter</button>
                <button class="btn btn-primary" type="button" data-modal-open="dispatch-lift-add">+ Schedule Lift</button>
            </form>

            <div class="table-wrap dispatch-table-wrap">
                <table class="admin-table dispatch-table">
                    <thead>
                        <tr>
                            <th>Lift-ID</th>
                            <th>Purchase-ID</th>
                            <th>DR Number</th>
                            <th>Lift Date</th>
                            <th>Location</th>
                            <th>Driver</th>
                            <th>Driver's<br>Contact No.</th>
                            <th>Truck-ID</th>
                            <th>Capacity</th>
                            <th>QTY Lift</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($scheduledRows as $row)
                            <tr>
                                @foreach ($row['cells'] as $cell)
                                    <td>{{ $cell }}</td>
                                @endforeach
                                <td><x-admin.status-badge :status="$row['status']" /></td>
                                <td><button class="btn btn-secondary" type="button" data-modal-open="{{ $row['id'] }}">View</button></td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="12">No records found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section data-tab-panel="hauled" {{ $activeTab !== 'hauled' ? 'hidden' : '' }}>
            <form class="dispatch-filter-row dispatch-fuel-filter dispatch-hauled-filter" method="GET" action="{{ route('dispatch.fuel-lifting.hauled') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search hauled lifts" value="{{ $search }}">
                <select name="status" aria-label="Filter by lift status">
                    <option value="">Status (All)</option>
                    @foreach ($filterOptions['statuses'] as $status)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucwords(str_replace('_', ' ', $status)) }}</option>
                    @endforeach
                </select>
                <input type="date" name="date_from" aria-label="Filter from date" value="{{ $filters['date_from'] ?? '' }}">
                <input type="date" name="date_to" aria-label="Filter to date" value="{{ $filters['date_to'] ?? '' }}">
                <select name="fuel_type_id" aria-label="Filter by fuel type">
                    <option value="">Fuel Type (All)</option>
                    @foreach ($filterOptions['fuelTypes'] as $fuelType)
                        <option value="{{ $fuelType->id }}" @selected((string) ($filters['fuel_type_id'] ?? '') === (string) $fuelType->id)>{{ $fuelType->name }}</option>
                    @endforeach
                </select>
                <select name="driver_user_id" aria-label="Filter by driver">
                    <option value="">Driver (All)</option>
                    @foreach ($filterOptions['drivers'] as $driver)
                        <option value="{{ $driver->id }}" @selected((string) ($filters['driver_user_id'] ?? '') === (string) $driver->id)>{{ $driver->name }}</option>
                    @endforeach
                </select>
                <select name="truck_id" aria-label="Filter by truck">
                    <option value="">Truck (All)</option>
                    @foreach ($filterOptions['trucks'] as $truck)
                        <option value="{{ $truck->id }}" @selected((string) ($filters['truck_id'] ?? '') === (string) $truck->id)>{{ $truck->truck_code }}{{ $truck->plate_number ? ' / '.$truck->plate_number : '' }}</option>
                    @endforeach
                </select>
                <button class="btn btn-primary" type="submit">Filter</button>
            </form>

            <div class="table-wrap dispatch-table-wrap">
                <table class="admin-table dispatch-table">
                    <thead>
                        <tr>
                            <th>Lift-ID</th>
                            <th>Purchase-ID</th>
                            <th>DR Number</th>
                            <th>Lift Date</th>
                            <th>Location</th>
                            <th>Driver</th>
                            <th>Driver's<br>Contact No.</th>
                            <th>Truck-ID</th>
                            <th>Capacity</th>
                            <th>Lifted</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($deliveredRows as $row)
                            <tr>
                                @foreach ($row['cells'] as $cell)
                                    <td>{{ $cell }}</td>
                                @endforeach
                                <td><x-admin.status-badge :status="$row['status']" /></td>
                                <td><button class="btn btn-secondary" type="button" data-modal-open="{{ $row['id'] }}">View</button></td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="12">No records found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <x-admin.modal id="dispatch-lift-add" title="Schedule Lift" wide>
        <form method="POST" action="{{ route('dispatch.fuel-lifting.hauls.store') }}" data-prevent-double-submit>
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ $createIdempotencyKey }}">
            <div class="modal-card">
                <div class="form-grid">
                    <div class="form-row">
                        <label for="dispatch_purchase_item_id">Purchase ID</label>
                        <select id="dispatch_purchase_item_id" name="purchase_item_id" required>
                            <option value="">Select purchase</option>
                            @foreach ($purchaseItems as $purchaseItem)
                                <option value="{{ $purchaseItem->id }}" @selected((string) old('purchase_item_id') === (string) $purchaseItem->id)>{{ $purchaseItem->label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="dispatch_quantity_liters">QTY Lift</label>
                        <input id="dispatch_quantity_liters" name="quantity_liters" type="number" min="0.01" step="0.01" value="{{ old('quantity_liters') }}" required>
                    </div>
                    <div class="form-row">
                        <label for="dispatch_truck_id">Truck-ID</label>
                        <select id="dispatch_truck_id" name="truck_id" required>
                            <option value="">Select truck</option>
                            @foreach ($trucks as $truck)
                                <option value="{{ $truck->id }}" @selected((string) old('truck_id') === (string) $truck->id)>{{ $truck->truck_code }}{{ $truck->plate_number ? ' / '.$truck->plate_number : '' }} / {{ number_format((float) $truck->capacity_liters, 2) }} L</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="dispatch_driver_user_id">Driver</label>
                        <select id="dispatch_driver_user_id" name="driver_user_id" required>
                            <option value="">Select driver</option>
                            @foreach ($drivers as $driver)
                                <option value="{{ $driver->id }}" @selected((string) old('driver_user_id') === (string) $driver->id)>{{ $driver->name }}{{ $driver->driver_code ? ' / '.$driver->driver_code : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="dispatch_scheduled_at">Lift Date</label>
                        <input id="dispatch_scheduled_at" name="scheduled_at" type="datetime-local" value="{{ old('scheduled_at') }}" required>
                    </div>
                    <div class="form-row">
                        <label for="dispatch_dr_number">DR Number</label>
                        <input id="dispatch_dr_number" name="dr_number" type="text" value="{{ old('dr_number') }}" maxlength="100">
                    </div>
                    <div class="form-row form-row-full">
                        <label for="dispatch_source_location">Location</label>
                        <input id="dispatch_source_location" name="source_location" type="text" value="{{ old('source_location') }}" maxlength="255">
                    </div>
                </div>
            </div>
            <div class="modal-actions"><button class="btn btn-pill btn-secondary" type="submit">Schedule Lift</button><button class="btn btn-pill btn-danger" type="button" data-modal-close>Cancel</button></div>
        </form>
    </x-admin.modal>

    @foreach ($scheduledRows->merge($deliveredRows) as $row)
        <x-admin.modal id="{{ $row['id'] }}" title="Fuel Lifting Schedule">
            <div class="modal-card">
                <span class="detail-status">{{ $row['status'] }}</span>
                <p class="detail-id">{{ $row['cells'][0] }}</p>
                <div class="detail-grid">
                    @foreach ($row['details'] as $label => $value)
                        <div class="detail-row"><div class="detail-label">{{ $label }}</div><div class="detail-value">{{ $value }}</div></div>
                    @endforeach
                </div>
            </div>
            @if (! empty($row['allowed_statuses']))
                <form method="POST" action="{{ route('dispatch.fuel-lifting.hauls.status', $row['haul_id']) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="idempotency_key" value="{{ $statusIdempotencyKey }}">
                    <div class="modal-card" style="margin-top:14px">
                        <div class="form-row">
                            <label for="lifting_status_{{ $row['id'] }}">Status</label>
                            <select id="lifting_status_{{ $row['id'] }}" name="status" required>
                                @foreach ($row['allowed_statuses'] as $status)
                                    <option value="{{ $status }}">{{ ucwords(str_replace('_', ' ', $status)) }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="modal-actions"><button class="btn btn-pill btn-secondary" type="submit">Edit</button><button class="btn btn-pill btn-danger" type="button" data-modal-close>Cancel</button></div>
                </form>
            @endif
        </x-admin.modal>
    @endforeach
@endcomponent
