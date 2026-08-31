@php
    $activeTab = $activeTab ?? (($state ?? 'schedule') === 'hauled' ? 'hauled' : 'schedule');
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
            <button class="btn btn-secondary" type="button" onclick="window.print()">Export</button>
        </div>

        <section data-tab-panel="schedule" @hidden($activeTab !== 'schedule')>
            <form class="dispatch-filter-row dispatch-fuel-filter" method="GET" action="{{ route('dispatch.fuel-lifting') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search scheduled deliveries" value="{{ $search }}">
                <button class="btn btn-primary" type="submit">Date</button>
                <button class="btn btn-primary" type="submit">Source</button>
                <button class="btn btn-primary" type="submit">Fuel Type (All)</button>
                <button class="btn btn-primary" type="button" data-modal-open="dispatch-lift-add">+ Schedule Lift</button>
            </form>

            <div class="table-wrap dispatch-table-wrap">
                <table class="admin-table dispatch-table">
                    <thead>
                        <tr>
                            <th>Lift-ID</th>
                            <th>Sale-ID</th>
                            <th>Source Ref</th>
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

        <section data-tab-panel="hauled" @hidden($activeTab !== 'hauled')>
            <form class="dispatch-filter-row dispatch-fuel-filter dispatch-hauled-filter" method="GET" action="{{ route('dispatch.fuel-lifting.hauled') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search delivered lifts" value="{{ $search }}">
                <button class="btn btn-primary" type="submit">Date</button>
                <button class="btn btn-primary" type="submit">Source</button>
                <button class="btn btn-primary" type="submit">Fuel Type (All)</button>
            </form>

            <div class="table-wrap dispatch-table-wrap">
                <table class="admin-table dispatch-table">
                    <thead>
                        <tr>
                            <th>Lift-ID</th>
                            <th>Sale-ID</th>
                            <th>Source Ref</th>
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
        <form method="POST" action="{{ route('dispatch.fuel-lifting.deliveries.store') }}">
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', $idempotencyKey) }}">
            <div class="modal-card">
                <div class="form-grid">
                    <div class="form-row">
                        <label for="source_type">Source</label>
                        <select id="source_type" name="source_type" required>
                            <option value="garage" @selected(old('source_type', 'garage') === 'garage')>Garage to Client</option>
                            <option value="depot" @selected(old('source_type') === 'depot')>Depot to Client</option>
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="stock_out_id">Garage Stock-Out</label>
                        <select id="stock_out_id" name="stock_out_id">
                            <option value="">Select released stock-out</option>
                            @foreach ($garageStockOuts as $stockOut)
                                <option value="{{ $stockOut->id }}" @selected((string) old('stock_out_id') === (string) $stockOut->id)>{{ $stockOut->label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="haul_allocation_id">Depot Allocation</label>
                        <select id="haul_allocation_id" name="haul_allocation_id">
                            <option value="">Select direct allocation</option>
                            @foreach ($directAllocations as $allocation)
                                <option value="{{ $allocation->id }}" @selected((string) old('haul_allocation_id') === (string) $allocation->id)>{{ $allocation->label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="driver_user_id">Driver</label>
                        <select id="driver_user_id" name="driver_user_id" required>
                            <option value="">Select driver</option>
                            @foreach ($drivers as $driver)
                                <option value="{{ $driver->id }}" @selected((string) old('driver_user_id') === (string) $driver->id)>{{ $driver->name }}{{ $driver->phone ? ' / '.$driver->phone : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="truck_id">Truck ID</label>
                        <select id="truck_id" name="truck_id" required>
                            <option value="">Select truck</option>
                            @foreach ($trucks as $truck)
                                <option value="{{ $truck->id }}" @selected((string) old('truck_id') === (string) $truck->id)>{{ $truck->truck_code }} / {{ number_format((float) $truck->capacity_liters, 2) }} L</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="scheduled_at">Date</label>
                        <input id="scheduled_at" type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at') }}" required>
                    </div>
                    <div class="form-row">
                        <label for="quantity_liters">Amount To Lift</label>
                        <input id="quantity_liters" type="number" name="quantity_liters" min="0.01" step="0.01" value="{{ old('quantity_liters') }}" placeholder="Enter authorized delivery quantity" required>
                    </div>
                </div>
            </div>
            <div class="modal-actions"><button class="btn btn-pill btn-secondary" type="submit">Add</button><button class="btn btn-pill btn-danger" type="button" data-modal-close>Cancel</button></div>
        </form>
    </x-admin.modal>

    @foreach ($scheduledRows->merge($deliveredRows) as $row)
        <x-admin.modal id="{{ $row['id'] }}" title="Delivery Schedule">
            <div class="modal-card">
                <span class="detail-status">{{ $row['status'] }}</span>
                <p class="detail-id">{{ $row['cells'][0] }}</p>
                <div class="detail-grid">
                    @foreach ($row['details'] as $label => $value)
                        <div class="detail-row"><div class="detail-label">{{ $label }}</div><div class="detail-value">{{ $value }}</div></div>
                    @endforeach
                </div>
            </div>
        </x-admin.modal>
    @endforeach
@endcomponent
