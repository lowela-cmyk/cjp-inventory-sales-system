@php
    $activeTab = in_array($activeTab ?? 'schedule', ['schedule', 'hauled', 'trucks', 'garage'], true) ? $activeTab : 'schedule';
    $filters = $filters ?? [];
    $filterOptions = $filterOptions ?? ['statuses' => [], 'fuelTypes' => collect(), 'drivers' => collect(), 'trucks' => collect()];
    $summaryCards = $summaryCards ?? [];
    $purchaseItems = $purchaseItems ?? collect();
    $directSaleOptions = $directSaleOptions ?? collect();
    $liftEdits = $liftEdits ?? [];
    $truckAssignmentIdempotencyKey = $truckAssignmentIdempotencyKey ?? (string) \Illuminate\Support\Str::uuid();
    // The same page serves Dispatch Officers and Admin (who may also act on behalf of drivers).
    $isAdminLifting = request()->routeIs('admin.*');
    $liftRoutePrefix = $isAdminLifting ? 'admin.fuel-lifting' : 'dispatch.fuel-lifting';
    $liftLayout = $isAdminLifting ? 'layouts.admin' : 'layouts.dispatch';
    $truckRoutePrefix = $isAdminLifting ? 'admin.trucks' : 'dispatch.trucks';
    $headings = ['schedule' => 'Schedule', 'hauled' => 'Hauled', 'trucks' => 'Trucks', 'garage' => 'Garage'];
    $scheduleFormFailed = old('form_context') === 'schedule-lift' && $errors->any();
    $liftLines = array_values(old('items', [[]])) ?: [[]];
    $oldDeliveries = array_values(old('deliveries', []));
    $directSaleOptions = $directSaleOptions ?? collect();
    $truckFilterOptions = $filterOptions['trucks']->mapWithKeys(fn (object $truck): array => [$truck->id => \App\Support\TruckLabel::make($truck->name, $truck->truck_code)]);
@endphp

@component($liftLayout, ['title' => 'Fuel Lifting Operations', 'active' => 'fuel-lifting'])
    <div data-tabs>
        <h2 class="section-title" data-tab-heading>{{ $headings[$activeTab] }}</h2>



        <div class="tabs">
            <button class="tab-button {{ $activeTab === 'schedule' ? 'is-active' : '' }}" type="button" data-tab-target="schedule" data-tab-url="{{ route($liftRoutePrefix) }}" data-heading="Schedule">Schedule</button>
            <button class="tab-button {{ $activeTab === 'hauled' ? 'is-active' : '' }}" type="button" data-tab-target="hauled" data-tab-url="{{ route($liftRoutePrefix.'.hauled') }}" data-heading="Hauled">Hauled</button>
            <button class="tab-button {{ $activeTab === 'trucks' ? 'is-active' : '' }}" type="button" data-tab-target="trucks" data-tab-url="{{ route($liftRoutePrefix.'.trucks') }}" data-heading="Trucks">Trucks</button>
            <button class="tab-button {{ $activeTab === 'garage' ? 'is-active' : '' }}" type="button" data-tab-target="garage" data-tab-url="{{ route($liftRoutePrefix.'.garage') }}" data-heading="Garage">Garage</button>
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
            <x-filter.bar class="dispatch-fuel-filter" :action="route($liftRoutePrefix)">
                <x-filter.search label="Search scheduled lifts" :value="$search" />
                <x-filter.date-range label="Lift date" from="lift_date_from" to="lift_date_to" :from-value="$filters['lift_date_from'] ?? null" :to-value="$filters['lift_date_to'] ?? null" />
                <x-filter.select name="fuel_type_id" label="Fuel Type" :options="$filterOptions['fuelTypes']->pluck('name', 'id')" :selected="$filters['fuel_type_id'] ?? null" />
                <x-filter.select name="driver_user_id" label="Driver" :options="$filterOptions['drivers']->pluck('name', 'id')" :selected="$filters['driver_user_id'] ?? null" />
                <x-filter.select name="truck_id" label="Truck" :options="$truckFilterOptions" :selected="$filters['truck_id'] ?? null" />
                <button class="btn btn-secondary btn-wide" type="button" data-modal-open="dispatch-lift-add">+ Schedule Lift</button>
            </x-filter.bar>

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
                                <td>
                                    <div class="dispatch-row-actions">
                                        <button class="btn btn-secondary" type="button" data-modal-open="{{ $row['id'] }}">View</button>
                                        {{-- Part 11: a lift still Scheduled can be deleted (the server checks it too). --}}
                                        @if ($row['can_delete'])
                                            @include('dispatch.partials.lift-delete-form', ['row' => $row])
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="12">No records found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section data-tab-panel="hauled" {{ $activeTab !== 'hauled' ? 'hidden' : '' }}>
            <x-filter.bar class="dispatch-fuel-filter" :action="route($liftRoutePrefix.'.hauled')">
                <x-filter.search label="Search hauled lifts" :value="$search" />
                <x-filter.select name="status" label="Status" :selected="$filters['status'] ?? null"
                    :options="collect($filterOptions['statuses'])->mapWithKeys(fn (string $status): array => [$status => ucwords(str_replace('_', ' ', $status))])" />
                <x-filter.date-range label="Lift date" from="lift_date_from" to="lift_date_to" :from-value="$filters['lift_date_from'] ?? null" :to-value="$filters['lift_date_to'] ?? null" />
                <x-filter.select name="fuel_type_id" label="Fuel Type" :options="$filterOptions['fuelTypes']->pluck('name', 'id')" :selected="$filters['fuel_type_id'] ?? null" />
                <x-filter.select name="driver_user_id" label="Driver" :options="$filterOptions['drivers']->pluck('name', 'id')" :selected="$filters['driver_user_id'] ?? null" />
                <x-filter.select name="truck_id" label="Truck" :options="$truckFilterOptions" :selected="$filters['truck_id'] ?? null" />
            </x-filter.bar>

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
                            <th>Delivered Quantity</th>
                            <th>On Truck</th>
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
                                {{-- Liters delivered to Depot to Client orders from this lift. --}}
                                <td>{{ $row['load']['delivered'] }}</td>
                                {{-- Part 12: fuel still on the truck (it keeps the truck Loaded until delivered or returned). --}}
                                <td @class(['is-due' => $row['truck_leftover'] > 0])>{{ $row['truck_leftover_label'] }}</td>
                                <td><x-admin.status-badge :status="$row['status']" /></td>
                                <td><button class="btn btn-secondary" type="button" data-modal-open="{{ $row['id'] }}">View</button></td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="14">No records found.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section data-tab-panel="trucks" {{ $activeTab !== 'trucks' ? 'hidden' : '' }}>
            @include('trucks.partials.tab')
        </section>

        {{-- Part 13: Garage tab: Tank 1 / Tank 2 per fuel and the garage lots. --}}
        <section data-tab-panel="garage" {{ $activeTab !== 'garage' ? 'hidden' : '' }}>
            @include('garage.partials.tab', ['garageRoute' => route($liftRoutePrefix.'.garage')])
        </section>
    </div>

    @include('trucks.partials.modals')

    {{-- Part 16 #4: Edit Lift (a lift still Scheduled), the Schedule Lift form with the lift's current values. --}}
    @foreach ($liftEdits as $rowId => $edit)
        @php
            $editContext = 'edit-'.$rowId;
            $editFailed = old('form_context') === $editContext && $errors->any();
            $editHaulId = (int) substr($rowId, strlen('dispatch-haul-'));
        @endphp
        <x-admin.modal id="{{ $rowId }}-edit" title="Edit Lift" size="xl">
            @if ($editFailed)
                <span hidden data-open-modal-on-load="{{ $rowId }}-edit"></span>
            @endif
            @include('dispatch.partials.lift-form', [
                'formId' => $rowId.'-edit-form',
                'action' => route($liftRoutePrefix.'.hauls.lift', $editHaulId),
                'method' => 'PATCH',
                'prefix' => $rowId.'_edit',
                'formContext' => $editContext,
                'idempotencyKey' => null,
                'values' => $editFailed
                    ? ['truck_id' => old('truck_id'), 'driver_user_id' => old('driver_user_id'), 'scheduled_at' => old('scheduled_at'), 'dr_number' => old('dr_number')]
                    : $edit['values'],
                'lines' => $editFailed ? (array_values(old('items', [])) ?: [[]]) : $edit['lines'],
                'deliveries' => $editFailed ? array_values(old('deliveries', [])) : $edit['deliveries'],
                'trucks' => $edit['trucks'],
                'purchaseItems' => $edit['purchaseItems'],
                'directSaleOptions' => $edit['directSaleOptions'],
                'showErrors' => $editFailed,
            ])
            <x-slot:footer>
                <button class="btn btn-pill btn-secondary" type="submit" form="{{ $rowId }}-edit-form">Apply Changes</button>
                <button class="btn btn-pill btn-danger" type="button" data-modal-close>Close</button>
            </x-slot:footer>
        </x-admin.modal>
    @endforeach

    <x-admin.modal id="dispatch-lift-add" reset-on-close title="Schedule Lift" size="xl">
        @if ($scheduleFormFailed)
            <span hidden data-open-modal-on-load="dispatch-lift-add"></span>
        @endif
        @include('dispatch.partials.lift-form', [
            'formId' => 'lift-schedule-form',
            'action' => route($liftRoutePrefix.'.hauls.store'),
            'method' => 'POST',
            'prefix' => 'dispatch',
            'formContext' => 'schedule-lift',
            'idempotencyKey' => $createIdempotencyKey,
            'values' => ['truck_id' => old('truck_id'), 'driver_user_id' => old('driver_user_id'), 'scheduled_at' => old('scheduled_at'), 'dr_number' => old('dr_number')],
            'lines' => $liftLines,
            'deliveries' => $oldDeliveries,
            'showErrors' => $scheduleFormFailed,
        ])
        <x-slot:footer>
            <button class="btn btn-pill btn-secondary" type="submit" form="lift-schedule-form">Add Schedule</button>
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
            <div class="modal-card modal-form-card">
                <h3 class="lift-section-title">Lift Details</h3>
                @foreach ($row['hauls'] as $haul)
                    <div class="receipt-line">
                        <p class="receipt-line-title"><strong>{{ $haul['purchase_code'] }}</strong> &middot; {{ $haul['fuel_name'] }} &middot; {{ $haul['quantity'] }} &middot; ATL: {{ $haul['atl_number'] }}</p>
                        <p class="receipt-line-note">Delivered: {{ number_format($haul['load']['delivered'], 2) }} L &middot; Remaining on truck: {{ number_format($haul['load']['remaining'], 2) }} L</p>
                        @forelse ($haul['lifts'] as $lift)
                            <p class="receipt-line-note">{{ $lift['type'] }}: {{ $lift['destination'] }} &middot; {{ $lift['quantity'] }} &middot; {{ $lift['status'] }}</p>
                        @empty
                            <p class="receipt-line-note">No Depot to Client deliveries on this lift.</p>
                        @endforelse
                    </div>
                @endforeach
            </div>
            @if ($row['truck_leftover'] > 0)
                {{-- Part 12: fuel left on the truck. Deliver more customer orders from it, or return the rest to the depot. --}}
                <div class="modal-card modal-form-card truck-leftover-card">
                    <h3 class="lift-section-title">Fuel Left on the Truck: {{ $row['truck_leftover_label'] }}</h3>
                    <p class="receipt-line-note">The truck stays Loaded (not Available) until this reaches 0.</p>
                    @if ($row['truck_order_options']->isNotEmpty())
                        <form class="truck-leftover-form" method="POST" action="{{ route($liftRoutePrefix.'.hauls.truck-deliveries.store', $row['haul_id']) }}" data-prevent-double-submit>
                            @csrf
                            <div class="form-row">
                                <label for="truck_order_{{ $row['haul_id'] }}">Order</label>
                                <select id="truck_order_{{ $row['haul_id'] }}" name="sale_id" required>
                                    <option value="">Select an order to deliver from this truck</option>
                                    @foreach ($row['truck_order_options'] as $order)
                                        <option value="{{ $order->sale_id }}">{{ $order->label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="modal-actions"><button class="btn btn-pill btn-secondary" type="submit">Deliver from Truck</button></div>
                        </form>
                    @else
                        <p class="receipt-line-note">No open order of this fuel fits in the liters left.</p>
                    @endif
                    {{-- Part 13: the rest goes into the fuel's garage tanks (Tank 1 and / or Tank 2), never back to the depot. --}}
                    <form class="garage-unload-form" method="POST" action="{{ route($liftRoutePrefix.'.hauls.unload-to-garage', $row['haul_id']) }}" data-confirm-message="Unload the entered liters into the garage tanks? Garage fuel can only leave through Garage to Client sales." data-prevent-double-submit>
                        @csrf
                        <h4 class="garage-unload-title">Unload to Garage</h4>
                        @foreach ($row['truck_leftover_lines'] as $line)
                            <p class="receipt-line-note">{{ $line['purchase_code'] }} · {{ $line['fuel_name'] }}: {{ number_format($line['leftover'], 2) }} L on the truck</p>
                            <div class="garage-unload-tanks">
                                @forelse ($garageTanks->where('fuel_type_id', $line['fuel_type_id']) as $tank)
                                    <label class="garage-unload-tank" for="unload_{{ $line['haul_id'] }}_{{ $tank->id }}">
                                        <span class="garage-unload-tank-name">{{ $tank->name }}</span>
                                        <span class="garage-unload-tank-fill">{{ number_format($tank->contents, 2) }} L / {{ number_format($tank->capacity, 2) }} L — {{ number_format($tank->free, 2) }} L free</span>
                                        <input id="unload_{{ $line['haul_id'] }}_{{ $tank->id }}" name="unload[{{ $line['haul_id'] }}][{{ $tank->id }}]" type="number" min="0" step="0.01" max="{{ min($tank->free, $line['leftover']) }}" placeholder="Liters" @disabled($tank->free <= 0)>
                                        @error('unload.'.$line['haul_id'].'.'.$tank->id)<p class="field-error">{{ $message }}</p>@enderror
                                    </label>
                                @empty
                                    <p class="receipt-line-note">No active {{ $line['fuel_name'] }} garage tank.</p>
                                @endforelse
                            </div>
                            @error('unload.'.$line['haul_id'])<p class="field-error">{{ $message }}</p>@enderror
                        @endforeach
                        @error('unload')<p class="field-error">{{ $message }}</p>@enderror
                        <div class="modal-actions"><button class="btn btn-pill btn-secondary" type="submit">Unload to Garage</button></div>
                    </form>
                </div>
            @endif
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
                                <option value="{{ $truck->id }}" @selected((int) $row['truck_id'] === (int) $truck->id)>{{ \App\Support\TruckLabel::make($truck->name, $truck->truck_code) }} – {{ number_format((float) $truck->capacity_liters, 2) }} L – Available</option>
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
                {{-- Part 16 #4: a lift still Scheduled can be edited (read-only from In Transit on). --}}
                @if (isset($liftEdits[$row['id']]))
                    <button class="btn btn-pill btn-secondary" type="button" data-modal-swap="{{ $row['id'] }}-edit">Edit</button>
                @endif
                @if ($row['can_delete'])
                    @include('dispatch.partials.lift-delete-form', ['row' => $row, 'pill' => true])
                @endif
                <button class="btn btn-pill btn-danger" type="button" data-modal-close>Close</button>
            </x-slot:footer>
        </x-admin.modal>
    @endforeach
@endcomponent
