@php
    $scheduleRows = [
        ['LFT-000001', 'PUR-000001', '0000053', '8/22/2026', 'Nasugbu, Batangas', 'Manuel P. Ligaya', '09876543219', 'TRK-000001', '40,000.00', '40,000', 'In Transit'],
        ['LFT-000002', 'PUR-000001', '0000053', '8/22/2026', 'Nasugbu, Batangas', 'Patrick M. Aala', '09876543218', 'TRK-000002', '40,000.00', '40,000', 'Lifted'],
    ];
    $hauledRows = [
        ['LFT-000002', 'PUR-000001', '0000053', '8/22/2026', 'Nasugbu, Batangas', 'Patrick M. Aala', '09078752533', 'TRK-000002', '40,000.00', '40,000.00', '3,200,000.00'],
    ];
    $activeTab = ($state ?? 'schedule') === 'hauled' ? 'hauled' : 'schedule';
@endphp

@component('layouts.dispatch', ['title' => 'Fuel Lifting Operations', 'active' => 'fuel-lifting'])
    <div data-tabs>
        <h2 class="section-title">Schedule</h2>

        <div class="tabs">
            <button class="tab-button {{ $activeTab === 'schedule' ? 'is-active' : '' }}" type="button" data-tab-target="schedule">Schedule</button>
            <button class="tab-button {{ $activeTab === 'hauled' ? 'is-active' : '' }}" type="button" data-tab-target="hauled">Hauled</button>
        </div>
        <div class="actions-right">
            <button class="btn btn-secondary" type="button">Export</button>
        </div>

        <section data-tab-panel="schedule" @hidden($activeTab !== 'schedule')>
            <div class="dispatch-filter-row dispatch-fuel-filter">
                <input type="search" placeholder="Search..." aria-label="Search scheduled lifts">
                <button class="btn btn-primary" type="button">Date</button>
                <button class="btn btn-primary" type="button">Depot</button>
                <button class="btn btn-primary" type="button">Fuel Type (All)</button>
                <button class="btn btn-primary" type="button" data-modal-open="dispatch-lift-add">+ Schedule Lift</button>
            </div>

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
                        @foreach ($scheduleRows as $row)
                            <tr>
                                @foreach (array_slice($row, 0, 10) as $cell)
                                    <td>{{ $cell }}</td>
                                @endforeach
                                <td><x-admin.status-badge :status="$row[10]" /></td>
                                <td><button class="btn btn-secondary" type="button" data-modal-open="dispatch-lift-edit">Edit</button></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section data-tab-panel="hauled" @hidden($activeTab !== 'hauled')>
            <div class="dispatch-filter-row dispatch-fuel-filter dispatch-hauled-filter">
                <input type="search" placeholder="Search..." aria-label="Search hauled lifts">
                <button class="btn btn-primary" type="button">Date</button>
                <button class="btn btn-primary" type="button">Depot</button>
                <button class="btn btn-primary" type="button">Fuel Type (All)</button>
            </div>

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
                            <th>Cost</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($hauledRows as $row)
                            <tr>
                                @foreach ($row as $cell)
                                    <td>{{ $cell }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <x-admin.modal id="dispatch-lift-add" title="Schedule Lift" wide>
        <div class="modal-card">
            <div class="form-grid">
                @foreach (['Truck ID', 'Truck Capacity', 'Driver', 'Location', 'Date'] as $field)
                    <div class="form-row">
                        <label>{{ $field }}</label>
                        <input type="{{ $field === 'Date' ? 'date' : (str_contains($field, 'Capacity') ? 'number' : 'text') }}" placeholder="Enter {{ $field === 'Date' ? 'Lift Date' : $field }}">
                    </div>
                @endforeach
                <div class="nested-form-box">
                    <div class="form-grid">
                        @foreach (['Purchase ID', 'DR Number', 'Price / Unit', 'Amount To Lift'] as $field)
                            <div class="form-row">
                                <label>{{ $field }}</label>
                                <input type="{{ str_contains($field, 'Price') || str_contains($field, 'Amount') ? 'number' : 'text' }}" placeholder="Enter {{ $field === 'Amount To Lift' ? 'Amount from Purchase ID' : $field }}">
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
        <div style="text-align:center;margin-top:12px;font-weight:800">+ Add Purchase ID</div>
        <div class="modal-actions"><button class="btn btn-pill btn-secondary" type="button">Add</button><button class="btn btn-pill btn-danger" type="button" data-modal-close>Delete</button></div>
    </x-admin.modal>

    <x-admin.modal id="dispatch-lift-edit" title="Edit Lift Record" wide>
        <div class="modal-card">
            <p class="detail-id">LFT-000001</p>
            <div class="form-grid">
                @foreach (['Purchase ID' => 'PUR-000001', 'DR Number' => '0000053', 'Lift Date' => '2026-08-22', 'Location' => 'Nasugbu, Batangas', 'Driver' => 'Manuel P. Ligaya', 'Driver Contact' => '09876543219', 'Truck ID' => 'TRK-000001', 'Capacity' => '40000', 'QTY Lift' => '40000'] as $field => $value)
                    <div class="form-row">
                        <label>{{ $field }}</label>
                        <input type="{{ str_contains($field, 'Date') ? 'date' : (str_contains($field, 'Capacity') || str_contains($field, 'QTY') ? 'number' : 'text') }}" value="{{ $value }}">
                    </div>
                @endforeach
            </div>
        </div>
        <div class="modal-actions"><button class="btn btn-pill btn-secondary" type="button">Edit</button><button class="btn btn-pill btn-danger" type="button" data-modal-close>Delete</button></div>
    </x-admin.modal>
@endcomponent
