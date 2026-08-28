@php
    $scheduled = [
        ['LFT-000001', 'PUR-000001', '0000053', '8/22/2026', 'Nasugbu Batangas', 'Manuel P. Ligaya', '09876543219', 'TRK-000001', '40,000.00', '40,000 L', 'In Transit', 'row-warning'],
        ['LFT-000002', 'PUR-000003', '0000054', '8/26/2026', 'Batangas City', 'Ariel C. Santos', '09181234567', 'TRK-000002', '30,000.00', '30,000 L', 'Scheduled', ''],
    ];
    $hauled = [
        ['LFT-000003', 'PUR-000004', '0000055', '8/21/2026', 'Phoenix Depot', 'Ramon D. Cruz', '09190001122', 'TRK-000003', '40,000.00', '40,000 L', 'Completed', 'row-success'],
        ['LFT-000004', 'PUR-000005', '0000056', '8/22/2026', 'Petron Depot', 'Lito M. Reyes', '09221113344', 'TRK-000004', '20,000.00', '20,000 L', 'Completed', 'row-success'],
    ];
@endphp

@component('layouts.admin', ['title' => 'Fuel Lifting Operations', 'active' => 'fuel-lifting'])
    <div data-tabs>
        <h2 class="section-title">Schedule</h2>
        <div class="tabs">
            <button class="tab-button is-active" type="button" data-tab-target="schedule">Schedule</button>
            <button class="tab-button" type="button" data-tab-target="hauled">Hauled</button>
        </div>
        <div class="actions-right"><button class="btn btn-secondary" type="button">Export</button></div>

        <section data-tab-panel="schedule">
            <div class="toolbar toolbar-narrow">
                <input type="search" placeholder="Search..." aria-label="Search scheduled lifts">
                <button class="btn btn-primary" type="button">Status</button>
                <button class="btn btn-primary" type="button">Date</button>
                <button class="btn btn-primary" type="button">Location</button>
                <button class="btn btn-primary" type="button" data-modal-open="lift-add">+ Schedule Lift</button>
            </div>
            @include('admin.partials.lift-table', ['rows' => $scheduled])
        </section>

        <section data-tab-panel="hauled" hidden>
            <div class="toolbar toolbar-narrow">
                <input type="search" placeholder="Search..." aria-label="Search hauled lifts">
                <button class="btn btn-primary" type="button">Status</button>
                <button class="btn btn-primary" type="button">Date</button>
                <button class="btn btn-primary" type="button">Location</button>
            </div>
            @include('admin.partials.lift-table', ['rows' => $hauled])
        </section>
    </div>

    <x-admin.modal id="lift-add" title="Schedule Lift" wide>
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

    <x-admin.modal id="lift-detail" title="Scheduled Lifts">
        <div class="modal-card">
            <span class="detail-status">In Transit</span>
            <p class="detail-id">LFT-000001</p>
            <div class="detail-grid">
                @foreach (['Purchase ID' => 'PUR-000001', 'DR Number' => '0000053', 'Lift Date' => '8/22/2026', 'Location' => 'Nasugbu Batangas', 'Driver' => 'Manuel P. Ligaya', "Driver's Contact" => '09876543219', 'Truck ID' => 'TRK-000001', 'Capacity' => '40,000.00', 'Quantity Lift' => '40,000 L'] as $label => $value)
                    <div class="detail-row"><div class="detail-label">{{ $label }}</div><div class="detail-value">{{ $value }}</div></div>
                @endforeach
            </div>
        </div>
        <div class="modal-actions"><button class="btn btn-pill btn-secondary" type="button" data-modal-swap="lift-edit">Edit</button><button class="btn btn-pill btn-danger" type="button">Delete</button></div>
    </x-admin.modal>

    <x-admin.modal id="lift-edit" title="Edit Lift Record" wide>
        <div class="modal-card">
            <p class="detail-id">LFT-000001</p>
            <div class="form-grid">
                @foreach (['Purchase ID' => 'PUR-000001', 'DR Number' => '0000053', 'Lift Date' => '2026-08-22', 'Location' => 'Nasugbu Batangas', 'Driver' => 'Manuel P. Ligaya', 'Truck ID' => 'TRK-000001', 'Capacity' => '40000', 'Quantity Lift' => '40000'] as $field => $value)
                    <div class="form-row">
                        <label>{{ $field }}</label>
                        <input type="{{ str_contains($field, 'Date') ? 'date' : (str_contains($field, 'Capacity') || str_contains($field, 'Quantity') ? 'number' : 'text') }}" value="{{ $value }}">
                    </div>
                @endforeach
            </div>
        </div>
        <div class="modal-actions"><button class="btn btn-pill btn-secondary" type="button">Edit</button><button class="btn btn-pill btn-danger" type="button" data-modal-close>Delete</button></div>
    </x-admin.modal>
@endcomponent
