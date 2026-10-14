@php
    $activeTab = $activeTab ?? (($state ?? 'schedule') === 'hauled' ? 'hauled' : 'schedule');
    $filters = $filters ?? [];
    $filterOptions = $filterOptions ?? ['statuses' => [], 'fuelTypes' => collect(), 'drivers' => collect(), 'trucks' => collect()];
    $summaryCards = $summaryCards ?? [];
    $purchaseItems = $purchaseItems ?? collect();
    $truckAssignmentIdempotencyKey = $truckAssignmentIdempotencyKey ?? (string) \Illuminate\Support\Str::uuid();
    // The same page serves Dispatch Officers and Admin (who may also act on behalf of drivers).
    $isAdminLifting = request()->routeIs('admin.*');
    $liftRoutePrefix = $isAdminLifting ? 'admin.fuel-lifting' : 'dispatch.fuel-lifting';
    $liftLayout = $isAdminLifting ? 'layouts.admin' : 'layouts.dispatch';
    $scheduleFormFailed = old('form_context') === 'schedule-lift' && $errors->any();
    $liftLines = array_values(old('items', [[]])) ?: [[]];
@endphp

@component($liftLayout, ['title' => 'Fuel Lifting Operations', 'active' => 'fuel-lifting'])
    <div data-tabs>
        <h2 class="section-title" data-tab-heading>{{ $activeTab === 'hauled' ? 'Hauled' : 'Schedule' }}</h2>

        @if (session('status'))
            <div class="admin-flash admin-flash-success" role="status">{{ session('status') }}</div>
        @endif

        @if ($errors->any() && ! $scheduleFormFailed)
            <div class="admin-flash admin-flash-error" role="alert">{{ $errors->first() }}</div>
        @endif

        <div class="tabs">
            <button class="tab-button {{ $activeTab === 'schedule' ? 'is-active' : '' }}" type="button" data-tab-target="schedule" data-heading="Schedule">Schedule</button>
            <button class="tab-button {{ $activeTab === 'hauled' ? 'is-active' : '' }}" type="button" data-tab-target="hauled" data-heading="Hauled">Hauled</button>
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
            <form class="dispatch-filter-row dispatch-fuel-filter" method="GET" action="{{ route($liftRoutePrefix) }}" data-auto-filter>
                <input type="search" name="search" placeholder="Search..." aria-label="Search scheduled lifts" value="{{ $search }}">
                <input type="date" name="lift_date" aria-label="Filter by lift date" title="Lift date" value="{{ $filters['lift_date'] ?? '' }}">
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
                            <th>Pickup Depot</th>
                            <th>Driver</th>
                            <th>Driver's Contact No.</th>
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
            <form class="dispatch-filter-row dispatch-fuel-filter dispatch-hauled-filter" method="GET" action="{{ route($liftRoutePrefix.'.hauled') }}" data-auto-filter>
                <input type="search" name="search" placeholder="Search..." aria-label="Search hauled lifts" value="{{ $search }}">
                <select name="status" aria-label="Filter by lift status">
                    <option value="">Status (All)</option>
                    @foreach ($filterOptions['statuses'] as $status)
                        <option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ ucwords(str_replace('_', ' ', $status)) }}</option>
                    @endforeach
                </select>
                <input type="date" name="lift_date" aria-label="Filter by lift date" title="Lift date" value="{{ $filters['lift_date'] ?? '' }}">
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
            </form>

            <div class="table-wrap dispatch-table-wrap">
                <table class="admin-table dispatch-table">
                    <thead>
                        <tr>
                            <th>Lift-ID</th>
                            <th>Purchase-ID</th>
                            <th>DR Number</th>
                            <th>Lift Date</th>
                            <th>Pickup Depot</th>
                            <th>Driver</th>
                            <th>Driver's Contact No.</th>
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

    <x-admin.modal id="dispatch-lift-add" title="Schedule Lift" size="xl">
        @if ($scheduleFormFailed)
            <span hidden data-open-modal-on-load="dispatch-lift-add"></span>
        @endif
        <form id="lift-schedule-form" class="lift-form" method="POST" action="{{ route($liftRoutePrefix.'.hauls.store') }}" data-prevent-double-submit data-lifting-schedule-form>
            @csrf
            <input type="hidden" name="idempotency_key" value="{{ $createIdempotencyKey }}">
            <input type="hidden" name="form_context" value="schedule-lift">

            @error('lift')
                <p class="form-alert" role="alert">{{ $message }}</p>
            @enderror

            <section class="lift-form-section" aria-label="Lift details">
                <div class="lift-fields">
                    <div class="lift-field">
                        <label for="dispatch_truck_id">Truck-ID</label>
                        <select id="dispatch_truck_id" name="truck_id" required @error('truck_id') aria-invalid="true" @enderror>
                            <option value="">Select truck</option>
                            @foreach ($trucks as $truck)
                                <option value="{{ $truck->id }}" data-capacity="{{ $truck->capacity_liters }}" @selected((string) old('truck_id') === (string) $truck->id)>{{ $truck->plate_number ?: 'No plate' }} – {{ $truck->truck_code }} – {{ number_format((float) $truck->capacity_liters, 2) }} L – Available</option>
                            @endforeach
                        </select>
                        @error('truck_id')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="lift-field">
                        <label for="dispatch_driver_user_id">Driver</label>
                        <select id="dispatch_driver_user_id" name="driver_user_id" required @error('driver_user_id') aria-invalid="true" @enderror>
                            <option value="">{{ $drivers->isEmpty() ? 'No active drivers available' : 'Select driver' }}</option>
                            @foreach ($drivers as $driver)
                                <option value="{{ $driver->id }}" @selected((string) old('driver_user_id') === (string) $driver->id)>{{ $driver->name }}{{ $driver->driver_code ? ' / '.$driver->driver_code : '' }}</option>
                            @endforeach
                        </select>
                        @error('driver_user_id')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="lift-field">
                        <label for="dispatch_scheduled_at">Lift Date</label>
                        <input id="dispatch_scheduled_at" name="scheduled_at" type="datetime-local" value="{{ old('scheduled_at') }}" required @error('scheduled_at') aria-invalid="true" @enderror>
                        @error('scheduled_at')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="lift-field">
                        <label for="dispatch_dr_number">DR Number</label>
                        <input id="dispatch_dr_number" name="dr_number" type="text" value="{{ old('dr_number') }}" maxlength="100" placeholder="Optional" @error('dr_number') aria-invalid="true" @enderror>
                        @error('dr_number')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                </div>
                <dl class="lift-info">
                    <div class="lift-info-row"><dt>Truck Capacity</dt><dd data-truck-capacity>0.00 L</dd></div>
                    <div class="lift-info-row"><dt>Pickup Depot</dt><dd data-pickup-depot>Select a purchase to derive the official depot and address.</dd></div>
                </dl>
            </section>

            <section class="lift-form-section" aria-label="Purchases to lift">
                <h3 class="lift-section-title">Purchases to lift</h3>
                @if ($purchaseItems->isEmpty())
                    <p class="lifting-empty-note" role="note">No purchases have remaining unlifted quantity. Record a new purchase in Inventory, or cancel an unneeded lift to free its quantity.</p>
                @endif
                @error('items')<p class="field-error">{{ $message }}</p>@enderror
                <div class="lift-lines" data-lifting-items>
                    @foreach ($liftLines as $index => $oldItem)
                        <div class="lift-line" data-lifting-item>
                            <div class="lift-field lift-line-purchase">
                                <label for="dispatch_purchase_{{ $index }}">Purchase ID</label>
                                <select id="dispatch_purchase_{{ $index }}" name="items[{{ $index }}][purchase_item_id]" data-lifting-purchase required>
                                    <option value="">{{ $purchaseItems->isEmpty() ? 'No purchases available to lift' : 'Select purchase' }}</option>
                                    @foreach ($purchaseItems as $purchaseItem)
                                        <option value="{{ $purchaseItem->id }}" data-depot-id="{{ $purchaseItem->depot_id }}" data-pickup="{{ $purchaseItem->pickup_label }}" data-fuel="{{ $purchaseItem->fuel_name }}" data-remaining="{{ $purchaseItem->remaining_liters }}" @selected((string) ($oldItem['purchase_item_id'] ?? '') === (string) $purchaseItem->id)>{{ $purchaseItem->label }}</option>
                                    @endforeach
                                </select>
                                @error("items.$index.purchase_item_id")<p class="field-error">{{ $message }}</p>@enderror
                            </div>
                            <div class="lift-field lift-line-summary">
                                <span class="lift-field-label">Fuel / Remaining</span>
                                <p class="lift-line-summary-text" data-purchase-summary>Select a purchase</p>
                            </div>
                            <div class="lift-field lift-line-amount">
                                <label for="dispatch_quantity_{{ $index }}">Amount to Lift</label>
                                <div class="input-suffix">
                                    <input id="dispatch_quantity_{{ $index }}" name="items[{{ $index }}][quantity_liters]" data-lifting-quantity type="number" min="0.01" step="0.01" inputmode="decimal" value="{{ $oldItem['quantity_liters'] ?? '' }}" required>
                                    <span aria-hidden="true">L</span>
                                </div>
                                @error("items.$index.quantity_liters")<p class="field-error">{{ $message }}</p>@enderror
                            </div>
                            <div class="lift-line-remove">
                                <button class="btn btn-danger" type="button" data-lifting-item-remove disabled>Remove Lift</button>
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="lift-totals">
                    <button class="btn btn-secondary" type="button" data-lifting-item-add>+ Add Purchase</button>
                    <div class="lift-totals-figures">
                        <span>Total scheduled: <output data-lifting-total>0.00 L</output></span>
                        <span>Remaining capacity: <output data-lifting-remaining>0.00 L</output></span>
                    </div>
                    <p class="field-error lift-totals-warning" data-lifting-warning role="alert"></p>
                </div>
            </section>
        </form>
        <x-slot:footer>
            <button class="btn btn-pill btn-secondary" type="submit" form="lift-schedule-form">Schedule Lift</button>
            <button class="btn btn-pill btn-danger" type="button" data-modal-close>Cancel</button>
        </x-slot:footer>
    </x-admin.modal>

    @foreach ($scheduledRows->merge($deliveredRows) as $row)
        <x-admin.modal id="{{ $row['id'] }}" title="Fuel Lifting Schedule">
            <div class="modal-card">
                <x-admin.status-badge :status="$row['status']" />
                <p class="detail-id">{{ $row['cells'][0] }}</p>
                <div class="detail-grid">
                    @foreach ($row['details'] as $label => $value)
                        <div class="detail-row"><div class="detail-label">{{ $label }}</div><div class="detail-value">{{ $value }}</div></div>
                    @endforeach
                </div>
            </div>
            @if (! empty($row['allowed_statuses']))
                <form class="modal-card modal-form-card" method="POST" action="{{ route($liftRoutePrefix.'.hauls.status', $row['haul_id']) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="idempotency_key" value="{{ $statusIdempotencyKey }}">
                    <div class="form-row">
                        <label for="lifting_status_{{ $row['id'] }}">Status</label>
                        <select id="lifting_status_{{ $row['id'] }}" name="status" required>
                            @foreach ($row['allowed_statuses'] as $status)
                                <option value="{{ $status }}">{{ ucwords(str_replace('_', ' ', $status)) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="modal-actions"><button class="btn btn-pill btn-secondary" type="submit">Update Status</button></div>
                </form>
            @endif
            @if ($row['can_assign_truck'])
                <form class="modal-card modal-form-card" method="POST" action="{{ route($liftRoutePrefix.'.hauls.truck', $row['haul_id']) }}">
                    @csrf
                    @method('PATCH')
                    <input type="hidden" name="idempotency_key" value="{{ $truckAssignmentIdempotencyKey }}">
                    <div class="form-row">
                        <label for="haul_truck_{{ $row['haul_id'] }}">Truck-ID</label>
                        <select id="haul_truck_{{ $row['haul_id'] }}" name="truck_id" required>
                            @if (! $trucks->contains('id', $row['truck_id']))
                                <option value="{{ $row['truck_id'] }}" selected>{{ $row['details']['Truck-ID'] }} (current)</option>
                            @endif
                            @foreach ($trucks as $truck)
                                <option value="{{ $truck->id }}" @selected((int) $row['truck_id'] === (int) $truck->id)>{{ $truck->plate_number ?: 'No plate' }} – {{ $truck->truck_code }} – {{ number_format((float) $truck->capacity_liters, 2) }} L – Available</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="modal-actions"><button class="btn btn-pill btn-secondary" type="submit">Change Truck</button></div>
                </form>
            @endif
            @if ($isAdminLifting)
                <div class="modal-card modal-form-card">
                    <h3 class="lift-section-title">Withdrawal receipts (on behalf of the driver)</h3>
                    @foreach ($row['hauls'] as $haul)
                        <div class="receipt-line">
                            <p class="receipt-line-title"><strong>{{ $haul['code'] }}</strong> &middot; {{ $haul['purchase_code'] }} &middot; {{ $haul['withdrawal_uploaded_label'] }}</p>
                            @if ($haul['can_upload_withdrawal'])
                                <form class="withdrawal-upload-form" method="POST" action="{{ route('admin.fuel-lifting.hauls.withdrawal-receipt.store', $haul['id']) }}" enctype="multipart/form-data">
                                    @csrf
                                    <label class="file-button">
                                        <span>{{ $haul['withdrawal_uploaded'] ? 'Choose replacement image' : 'Choose receipt image' }}</span>
                                        <input name="withdrawal_receipt" type="file" accept="image/jpeg,image/png,image/webp" required>
                                    </label>
                                    <input name="withdrawal_notes" type="text" maxlength="1000" placeholder="Optional notes" aria-label="Receipt notes for {{ $haul['code'] }}">
                                    <button class="btn btn-pill btn-primary" type="submit">{{ $haul['withdrawal_uploaded'] ? 'Replace Receipt' : 'Upload Receipt' }}</button>
                                </form>
                            @else
                                <p class="receipt-line-note">Available after this lift is marked Lifted.</p>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
            <x-slot:footer>
                <button class="btn btn-pill btn-danger" type="button" data-modal-close>Close</button>
            </x-slot:footer>
        </x-admin.modal>
    @endforeach
@endcomponent
