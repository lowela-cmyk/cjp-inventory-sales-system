@php
    $activeTab = $activeTab ?? 'schedule';
    $driverName = $driverName ?? auth()->user()?->name;
    $summaryCards = $summaryCards ?? [];
    $driverProfile = $driverProfile ?? [];
    $currentAssignment = $currentAssignment ?? null;
    $filters = $filters ?? [];
    $filterOptions = $filterOptions ?? ['statuses' => [], 'fuelTypes' => collect()];
    $liftingStatusIdempotencyKey = $liftingStatusIdempotencyKey ?? (string) \Illuminate\Support\Str::uuid();
@endphp

@component('layouts.driver', ['title' => 'Fuel Lifting Operations', 'active' => 'fuel-lifting', 'driverName' => $driverName])
    <div data-tabs>
        <h2 class="section-title" data-driver-heading>{{ $activeTab === 'hauled' ? 'Hauled' : 'Schedule' }}</h2>

        @if (session('status'))
            <div class="alert-bar alert-success">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert-bar alert-warning">{{ $errors->first() }}</div>
        @endif

        <div class="tabs">
            <button class="tab-button {{ $activeTab === 'schedule' ? 'is-active' : '' }}" type="button" data-tab-target="schedule" data-heading="Schedule">Schedule</button>
            <button class="tab-button {{ $activeTab === 'hauled' ? 'is-active' : '' }}" type="button" data-tab-target="hauled" data-heading="Hauled">Hauled</button>
        </div>

        <div class="actions-right">
            <button class="btn btn-secondary" type="button" onclick="window.print()">Export</button>
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

        <div class="dashboard-grid">
            <div class="modal-card">
                <span class="detail-status">{{ $driverProfile['Profile Status'] ?? 'N/A' }}</span>
                <p class="detail-id">{{ $driverProfile['Driver ID'] ?? 'N/A' }}</p>
                <div class="detail-grid driver-detail-grid">
                    @foreach ($driverProfile as $label => $value)
                        <div class="detail-row">
                            <div class="detail-label">{{ $label }}</div>
                            <div class="detail-value">{{ $value }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="modal-card">
                <span class="detail-status">{{ $currentAssignment['details']['Status'] ?? 'N/A' }}</span>
                <p class="detail-id">{{ $currentAssignment['cells'][0] ?? 'No Assignment' }}</p>
                <div class="detail-grid driver-detail-grid">
                    @if ($currentAssignment)
                        @foreach ($currentAssignment['details'] as $label => $value)
                            <div class="detail-row">
                                <div class="detail-label">{{ $label }}</div>
                                <div class="detail-value">{{ $value }}</div>
                            </div>
                        @endforeach
                    @else
                        <div class="detail-row">
                            <div class="detail-label">Status</div>
                            <div class="detail-value">No active assignment</div>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <section data-tab-panel="schedule" {{ $activeTab !== 'schedule' ? 'hidden' : '' }}>
            <form class="driver-filter-row" method="GET" action="{{ route('driver.fuel-lifting') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search scheduled lifts" value="{{ $search }}">
                <select name="task_status" aria-label="Filter by status">
                    <option value="">Status (All)</option>
                    @foreach ($filterOptions['statuses'] as $status)
                        <option value="{{ $status }}" @selected(($filters['task_status'] ?? '') === $status)>{{ ucwords(str_replace('_', ' ', $status)) }}</option>
                    @endforeach
                </select>
                <input type="date" name="date_from" aria-label="Filter from date" value="{{ $filters['date_from'] ?? '' }}">
                <input type="date" name="date_to" aria-label="Filter to date" value="{{ $filters['date_to'] ?? '' }}">
                <select name="source_type" aria-label="Filter by source">
                    <option value="">Source</option>
                    <option value="depot" @selected(($filters['source_type'] ?? '') === 'depot')>Depot</option>
                    <option value="garage" @selected(($filters['source_type'] ?? '') === 'garage')>Garage</option>
                </select>
                <select name="destination_type" aria-label="Filter by destination">
                    <option value="">Destination</option>
                    <option value="garage" @selected(($filters['destination_type'] ?? '') === 'garage')>Garage</option>
                    <option value="customer" @selected(($filters['destination_type'] ?? '') === 'customer')>Client</option>
                </select>
                <select name="fuel_type_id" aria-label="Filter by fuel type">
                    <option value="">Fuel Type (All)</option>
                    @foreach ($filterOptions['fuelTypes'] as $fuelType)
                        <option value="{{ $fuelType->id }}" @selected((string) ($filters['fuel_type_id'] ?? '') === (string) $fuelType->id)>{{ $fuelType->name }}</option>
                    @endforeach
                </select>
                <button class="btn btn-primary" type="submit">Filter</button>
            </form>

            <div class="table-wrap driver-table-wrap">
                <table class="admin-table driver-table">
                    <thead>
                        <tr>
                            <th>Lift-ID</th>
                            <th>Sale-ID</th>
                            <th>Source Ref</th>
                            <th>Lift Date</th>
                            <th>Location</th>
                            <th>Truck-ID</th>
                            <th>Capacity</th>
                            <th>QTY to Lift</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($scheduleRows as $row)
                            <tr>
                                @foreach ($row['cells'] as $cell)
                                    <td>{{ $cell }}</td>
                                @endforeach
                                <td><button class="btn btn-secondary" type="button" data-modal-open="{{ $row['id'] }}">View</button></td>
                            </tr>
                        @empty
                            <tr>
                                <td class="driver-empty-cell" colspan="10">No Schedules</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section data-tab-panel="hauled" {{ $activeTab !== 'hauled' ? 'hidden' : '' }}>
            <form class="driver-filter-row" method="GET" action="{{ route('driver.fuel-lifting.hauled') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search hauled lifts" value="{{ $search }}">
                <select name="task_status" aria-label="Filter by status">
                    <option value="">Status (All)</option>
                    @foreach ($filterOptions['statuses'] as $status)
                        <option value="{{ $status }}" @selected(($filters['task_status'] ?? '') === $status)>{{ ucwords(str_replace('_', ' ', $status)) }}</option>
                    @endforeach
                </select>
                <input type="date" name="date_from" aria-label="Filter from date" value="{{ $filters['date_from'] ?? '' }}">
                <input type="date" name="date_to" aria-label="Filter to date" value="{{ $filters['date_to'] ?? '' }}">
                <select name="source_type" aria-label="Filter by source">
                    <option value="">Source</option>
                    <option value="depot" @selected(($filters['source_type'] ?? '') === 'depot')>Depot</option>
                    <option value="garage" @selected(($filters['source_type'] ?? '') === 'garage')>Garage</option>
                </select>
                <select name="destination_type" aria-label="Filter by destination">
                    <option value="">Destination</option>
                    <option value="garage" @selected(($filters['destination_type'] ?? '') === 'garage')>Garage</option>
                    <option value="customer" @selected(($filters['destination_type'] ?? '') === 'customer')>Client</option>
                </select>
                <select name="fuel_type_id" aria-label="Filter by fuel type">
                    <option value="">Fuel Type (All)</option>
                    @foreach ($filterOptions['fuelTypes'] as $fuelType)
                        <option value="{{ $fuelType->id }}" @selected((string) ($filters['fuel_type_id'] ?? '') === (string) $fuelType->id)>{{ $fuelType->name }}</option>
                    @endforeach
                </select>
                <button class="btn btn-primary" type="submit">Filter</button>
            </form>

            <div class="table-wrap driver-table-wrap">
                <table class="admin-table driver-table">
                    <thead>
                        <tr>
                            <th>Lift-ID</th>
                            <th>Sale-ID</th>
                            <th>Source Ref</th>
                            <th>Lift Date</th>
                            <th>Location</th>
                            <th>Truck-ID</th>
                            <th>Capacity</th>
                            <th>QTY Lifted</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($hauledRows as $row)
                            <tr>
                                @foreach ($row['cells'] as $cell)
                                    <td>{{ $cell }}</td>
                                @endforeach
                                <td><button class="btn btn-secondary" type="button" data-modal-open="{{ $row['id'] }}">View</button></td>
                            </tr>
                        @empty
                            <tr>
                                <td class="driver-empty-cell" colspan="10">No Records Available</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    @foreach ($scheduleRows->merge($hauledRows) as $row)
        <x-admin.modal id="{{ $row['id'] }}" title="Schedule">
            <div class="modal-card">
                <p class="detail-id">{{ $row['cells'][0] }}</p>
                <div class="detail-grid driver-detail-grid">
                    @foreach ($row['details'] as $label => $value)
                        <div class="detail-row">
                            <div class="detail-label">{{ $label }}</div>
                            <div class="detail-value">{{ $value }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="modal-actions">
                @if (($row['kind'] ?? '') === 'Lift' && ! empty($row['allowed_driver_statuses']))
                    <form method="POST" action="{{ route('driver.fuel-lifting.hauls.status', $row['record_id']) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="idempotency_key" value="{{ $liftingStatusIdempotencyKey }}">
                        <select name="lifting_status" aria-label="Update lifting status">
                            @foreach ($row['allowed_driver_statuses'] as $status)
                                <option value="{{ $status }}">{{ ucwords(str_replace('_', ' ', $status)) }}</option>
                            @endforeach
                        </select>
                        <button class="btn btn-pill btn-primary" type="submit">Update</button>
                    </form>
                @endif
                <button class="btn btn-pill btn-secondary" type="button" data-modal-close>Close</button>
            </div>
        </x-admin.modal>
    @endforeach
@endcomponent
