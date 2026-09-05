@component('layouts.admin', ['title' => 'Fuel Lifting Operations', 'active' => 'fuel-lifting'])
    @php
        $trucks = $trucks ?? collect();
        $truckAssignmentIdempotencyKey = $truckAssignmentIdempotencyKey ?? (string) \Illuminate\Support\Str::uuid();
    @endphp
    <div data-tabs>
        <h2 class="section-title">Schedule</h2>
        @if (session('status'))
            <div class="admin-flash admin-flash-success" role="status">{{ session('status') }}</div>
        @endif
        @if ($errors->any())
            <div class="admin-flash admin-flash-error" role="alert">{{ $errors->first() }}</div>
        @endif
        <div class="tabs">
            <button class="tab-button is-active" type="button" data-tab-target="schedule">Schedule</button>
            <button class="tab-button" type="button" data-tab-target="hauled">Hauled</button>
        </div>
        <div class="actions-right"><button class="btn btn-secondary" type="button" data-export-table>Export</button></div>

        <section data-tab-panel="schedule">
            <form class="toolbar toolbar-narrow" method="GET" action="{{ route('admin.fuel-lifting') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search scheduled lifts" value="{{ $search }}">
                <button class="btn btn-primary" type="submit">Status</button>
                <button class="btn btn-primary" type="submit">Date</button>
                <button class="btn btn-primary" type="submit">Location</button>
                <button class="btn btn-primary" type="button" data-modal-open="lift-add">+ Schedule Lift</button>
            </form>
            @include('admin.partials.lift-table', ['rows' => $scheduled])
        </section>

        <section data-tab-panel="hauled" hidden>
            <form class="toolbar toolbar-narrow" method="GET" action="{{ route('admin.fuel-lifting') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search hauled lifts" value="{{ $search }}">
                <button class="btn btn-primary" type="submit">Status</button>
                <button class="btn btn-primary" type="submit">Date</button>
                <button class="btn btn-primary" type="submit">Location</button>
            </form>
            @include('admin.partials.lift-table', ['rows' => $hauled])
        </section>
    </div>

    <x-admin.modal id="lift-add" title="Schedule Lift" wide>
        <div class="modal-card"><p class="detail-value">Fuel lifting records are monitored here and are managed in the Dispatch workflow.</p></div>
        <div class="modal-actions"><button class="btn btn-pill btn-danger" type="button" data-modal-close>Close</button></div>
    </x-admin.modal>

    @foreach ($scheduled->merge($hauled) as $row)
        <x-admin.modal id="{{ $row['id'] }}" title="Scheduled Lifts">
            <div class="modal-card">
                <span class="detail-status">{{ $row['status'] }}</span>
                <p class="detail-id">{{ $row['cells'][0] }}</p>
                <div class="detail-grid">
                    @foreach ($row['details'] as $label => $value)
                        <div class="detail-row"><div class="detail-label">{{ $label }}</div><div class="detail-value">{{ $value }}</div></div>
                    @endforeach
                </div>
            </div>
            @if (! empty($row['can_assign_truck']))
                <form method="POST" action="{{ route('admin.fuel-lifting.hauls.truck', $row['haul_id']) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="idempotency_key" value="{{ $truckAssignmentIdempotencyKey }}">
                    <div class="modal-card" style="margin-top:14px">
                        <div class="form-row">
                            <label for="haul_truck_{{ $row['haul_id'] }}">Truck ID</label>
                            <select id="haul_truck_{{ $row['haul_id'] }}" name="truck_id" required>
                                @if ($row['truck_id'] && ! $trucks->contains('id', $row['truck_id']))
                                    <option value="{{ $row['truck_id'] }}" selected>{{ $row['details']['Truck ID'] ?? 'Assigned Truck' }} / {{ $row['details']['Capacity'] ?? 'N/A' }} L</option>
                                @endif
                                @foreach ($trucks as $truck)
                                    <option value="{{ $truck->id }}" @selected((string) old('truck_id', $row['truck_id']) === (string) $truck->id)>{{ $truck->truck_code }}{{ $truck->plate_number ? ' / '.$truck->plate_number : '' }} / {{ number_format((float) $truck->capacity_liters, 2) }} L</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="modal-actions"><button class="btn btn-pill btn-secondary" type="submit">Assign Truck</button><button class="btn btn-pill btn-danger" type="button" data-modal-close>Cancel</button></div>
                </form>
            @endif
            @if (! empty($row['allowed_statuses']))
                <form method="POST" action="{{ route('admin.fuel-lifting.hauls.status', $row['haul_id']) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="idempotency_key" value="{{ $liftingStatusIdempotencyKey }}">
                    <div class="modal-card" style="margin-top:14px">
                        <div class="form-row">
                            <label for="lifting_status_{{ $row['haul_id'] }}">Status</label>
                            <select id="lifting_status_{{ $row['haul_id'] }}" name="status" required>
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
