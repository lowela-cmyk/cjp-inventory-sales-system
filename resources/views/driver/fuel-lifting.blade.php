@php
    $activeTab = $activeTab ?? 'schedule';
    $driverName = $driverName ?? auth()->user()?->name;
@endphp

@component('layouts.driver', ['title' => 'Fuel Lifting Operations', 'active' => 'fuel-lifting', 'driverName' => $driverName])
    <div data-tabs>
        <h2 class="section-title" data-driver-heading>{{ $activeTab === 'hauled' ? 'Hauled' : 'Schedule' }}</h2>

        <div class="tabs">
            <button class="tab-button {{ $activeTab === 'schedule' ? 'is-active' : '' }}" type="button" data-tab-target="schedule" data-heading="Schedule">Schedule</button>
            <button class="tab-button {{ $activeTab === 'hauled' ? 'is-active' : '' }}" type="button" data-tab-target="hauled" data-heading="Hauled">Hauled</button>
        </div>

        <div class="actions-right">
            <button class="btn btn-secondary" type="button" onclick="window.print()">Export</button>
        </div>

        <section data-tab-panel="schedule" @hidden($activeTab !== 'schedule')>
            <form class="driver-filter-row" method="GET" action="{{ route('driver.fuel-lifting') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search scheduled lifts" value="{{ $search }}">
                <button class="btn btn-primary" type="submit">Date</button>
                <button class="btn btn-primary" type="submit">Source</button>
                <button class="btn btn-primary" type="submit">Fuel Type (All)</button>
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

        <section data-tab-panel="hauled" @hidden($activeTab !== 'hauled')>
            <form class="driver-filter-row" method="GET" action="{{ route('driver.fuel-lifting.hauled') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search hauled lifts" value="{{ $search }}">
                <button class="btn btn-primary" type="submit">Date</button>
                <button class="btn btn-primary" type="submit">Source</button>
                <button class="btn btn-primary" type="submit">Fuel Type (All)</button>
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
                <button class="btn btn-pill btn-secondary" type="button" data-modal-close>Close</button>
            </div>
        </x-admin.modal>
    @endforeach
@endcomponent
