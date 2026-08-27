@php
    $state = $state ?? 'schedule';
    $activeTab = in_array($state, ['hauled', 'no-hauled'], true) ? 'hauled' : 'schedule';
    $driverName = in_array($state, ['hauled', 'no-schedule'], true) ? 'Patrick Aala' : 'Manuel Ligaya';

    $scheduleRows = $state === 'no-schedule' ? [] : [
        ['LFT-000001', 'PUR-000001', '0000053', '8/22/2026', 'Nasugbu, Batangas', 'TRK-000002', '40,000.00', '40,000'],
    ];

    $hauledRows = $state === 'no-hauled' ? [] : [
        ['LFT-000001', 'PUR-000001', '0000053', '8/22/2026', 'Nasugbu, Batangas', 'TRK-000001', '40,000.00', '40,000', '3,200,000.00'],
    ];
@endphp

@component('layouts.driver', ['title' => 'Fuel Lifting Operations', 'active' => 'fuel-lifting', 'driverName' => $driverName])
    <div data-tabs>
        <h2 class="section-title" data-driver-heading>{{ $activeTab === 'hauled' ? 'Hauled' : 'Schedule' }}</h2>

        <div class="tabs">
            <button class="tab-button {{ $activeTab === 'schedule' ? 'is-active' : '' }}" type="button" data-tab-target="schedule" data-heading="Schedule">Schedule</button>
            <button class="tab-button {{ $activeTab === 'hauled' ? 'is-active' : '' }}" type="button" data-tab-target="hauled" data-heading="Hauled">Hauled</button>
        </div>

        <div class="actions-right">
            <button class="btn btn-secondary" type="button">Export</button>
        </div>

        <section data-tab-panel="schedule" @hidden($activeTab !== 'schedule')>
            <div class="driver-filter-row">
                <input type="search" placeholder="Search..." aria-label="Search scheduled lifts">
                <button class="btn btn-primary" type="button">Date</button>
                <button class="btn btn-primary" type="button">Depot</button>
                <button class="btn btn-primary" type="button">Fuel Type (All)</button>
            </div>

            <div class="table-wrap driver-table-wrap">
                <table class="admin-table driver-table">
                    <thead>
                        <tr>
                            <th>Lift-ID</th>
                            <th>Purchase-ID</th>
                            <th>DR Number</th>
                            <th>Lift Date</th>
                            <th>Location</th>
                            <th>Truck-ID</th>
                            <th>Capacity</th>
                            <th>QTY to Lift</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($scheduleRows as $row)
                            <tr>
                                @foreach ($row as $cell)
                                    <td>{{ $cell }}</td>
                                @endforeach
                                <td><button class="btn btn-secondary" type="button" data-modal-open="driver-schedule-detail">Edit</button></td>
                            </tr>
                        @empty
                            <tr>
                                <td class="driver-empty-cell" colspan="9">No Schedules</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section data-tab-panel="hauled" @hidden($activeTab !== 'hauled')>
            <div class="driver-filter-row">
                <input type="search" placeholder="Search..." aria-label="Search hauled lifts">
                <button class="btn btn-primary" type="button">Date</button>
                <button class="btn btn-primary" type="button">Depot</button>
                <button class="btn btn-primary" type="button">Fuel Type (All)</button>
            </div>

            <div class="table-wrap driver-table-wrap">
                <table class="admin-table driver-table">
                    <thead>
                        <tr>
                            <th>Lift-ID</th>
                            <th>Purchase-ID</th>
                            <th>DR Number</th>
                            <th>Lift Date</th>
                            <th>Location</th>
                            <th>Truck-ID</th>
                            <th>Capacity</th>
                            <th>QTY Lifted</th>
                            <th>Cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($hauledRows as $row)
                            <tr>
                                @foreach ($row as $cell)
                                    <td>{{ $cell }}</td>
                                @endforeach
                            </tr>
                        @empty
                            <tr>
                                <td class="driver-empty-cell" colspan="9">No Records Available</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <x-admin.modal id="driver-schedule-detail" title="Schedule">
        <div class="modal-card">
            <p class="detail-id">LFT-000001</p>
            <div class="detail-grid driver-detail-grid">
                @foreach (['Purchase ID' => 'PUR-000001', 'DR Number' => '0000053', 'Lift Date' => '8/22/2026', 'Location' => 'Nasugbu, Batangas', 'Truck-ID' => '000002', 'Capacity' => '40,000.00', 'Quantity to Lift' => '40,000 L'] as $label => $value)
                    <div class="detail-row">
                        <div class="detail-label">{{ $label }}</div>
                        <div class="detail-value">{{ $value }}</div>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="modal-actions">
            <button class="btn btn-pill btn-secondary" type="button" data-modal-close>Mark as Done</button>
        </div>
    </x-admin.modal>
@endcomponent
