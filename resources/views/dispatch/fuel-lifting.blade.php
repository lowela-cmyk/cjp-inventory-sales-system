@php
    $activeTab = in_array($activeTab ?? 'schedule', ['schedule', 'hauled', 'trucks'], true) ? $activeTab : 'schedule';
    $filters = $filters ?? [];
    $filterOptions = $filterOptions ?? ['statuses' => [], 'fuelTypes' => collect(), 'drivers' => collect(), 'trucks' => collect()];
    $summaryCards = $summaryCards ?? [];
    $purchaseItems = $purchaseItems ?? collect();
    $directSaleOptions = $directSaleOptions ?? collect();
    $truckAssignmentIdempotencyKey = $truckAssignmentIdempotencyKey ?? (string) \Illuminate\Support\Str::uuid();
    // The same page serves Dispatch Officers and Admin (who may also act on behalf of drivers).
    $isAdminLifting = request()->routeIs('admin.*');
    $liftRoutePrefix = $isAdminLifting ? 'admin.fuel-lifting' : 'dispatch.fuel-lifting';
    $liftLayout = $isAdminLifting ? 'layouts.admin' : 'layouts.dispatch';
    $truckRoutePrefix = $isAdminLifting ? 'admin.trucks' : 'dispatch.trucks';
    $headings = ['schedule' => 'Schedule', 'hauled' => 'Hauled', 'trucks' => 'Trucks'];
    $scheduleFormFailed = old('form_context') === 'schedule-lift' && $errors->any();
    $liftLines = array_values(old('items', [[]])) ?: [[]];
    $oldDeliveries = array_values(old('deliveries', []));
    $directSaleOptions = $directSaleOptions ?? collect();
    $truckFilterOptions = $filterOptions['trucks']->mapWithKeys(fn (object $truck): array => [$truck->id => $truck->truck_code.($truck->plate_number ? ' / '.$truck->plate_number : '')]);
@endphp

@component($liftLayout, ['title' => 'Fuel Lifting Operations', 'active' => 'fuel-lifting'])
    <div data-tabs>
        <h2 class="section-title" data-tab-heading>{{ $headings[$activeTab] }}</h2>



        <div class="tabs">
            <button class="tab-button {{ $activeTab === 'schedule' ? 'is-active' : '' }}" type="button" data-tab-target="schedule" data-tab-url="{{ route($liftRoutePrefix) }}" data-heading="Schedule">Schedule</button>
            <button class="tab-button {{ $activeTab === 'hauled' ? 'is-active' : '' }}" type="button" data-tab-target="hauled" data-tab-url="{{ route($liftRoutePrefix.'.hauled') }}" data-heading="Hauled">Hauled</button>
            <button class="tab-button {{ $activeTab === 'trucks' ? 'is-active' : '' }}" type="button" data-tab-target="trucks" data-tab-url="{{ route($liftRoutePrefix.'.trucks') }}" data-heading="Trucks">Trucks</button>
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
    </div>

    @include('trucks.partials.modals')

    <x-admin.modal id="dispatch-lift-add" reset-on-close title="Schedule Lift" size="xl">
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

            {{-- Part 2 revisions (mockup): Lift Details (truck, driver, date, purchases), then Delivery Details. --}}
            <h3 class="lift-section-title">Lift Details</h3>
            <section class="lift-form-section lift-header-card" aria-label="Lift details">
                <div class="lift-fields lift-fields-3">
                    <div class="lift-field">
                        <label for="dispatch_truck_id">Truck ID</label>
                        <select id="dispatch_truck_id" name="truck_id" required @error('truck_id') aria-invalid="true" @enderror>
                            <option value="">Select Truck</option>
                            @foreach ($trucks as $truck)
                                <option value="{{ $truck->id }}" data-capacity="{{ $truck->capacity_liters }}" @selected((string) old('truck_id') === (string) $truck->id)>{{ $truck->plate_number ?: 'No plate' }} – {{ $truck->truck_code }} – {{ number_format((float) $truck->capacity_liters, 2) }} L – Available</option>
                            @endforeach
                        </select>
                        @error('truck_id')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                    <div class="lift-field">
                        <label for="dispatch_driver_user_id">Driver</label>
                        <select id="dispatch_driver_user_id" name="driver_user_id" required @error('driver_user_id') aria-invalid="true" @enderror>
                            <option value="">{{ $drivers->isEmpty() ? 'No active drivers available' : 'Select Driver' }}</option>
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
                </div>

                <div class="lift-lines" data-lifting-items>
                    @foreach ($liftLines as $index => $oldItem)
                        <div class="lift-line" data-lifting-item>
                            <div class="lift-fields lift-fields-3">
                                <div class="lift-field">
                                    <label for="dispatch_purchase_{{ $index }}">Purchase ID</label>
                                    <select id="dispatch_purchase_{{ $index }}" name="items[{{ $index }}][purchase_item_id]" data-lifting-purchase required>
                                        <option value="">{{ $purchaseItems->isEmpty() ? 'No purchases in the depot' : 'Select Purchase ID' }}</option>
                                        @foreach ($purchaseItems as $purchaseItem)
                                            <option value="{{ $purchaseItem->id }}" data-depot-id="{{ $purchaseItem->depot_id }}" data-pickup="{{ $purchaseItem->pickup_label }}" data-fuel="{{ $purchaseItem->fuel_name }}" data-fuel-type-id="{{ $purchaseItem->fuel_type_id }}" data-remaining="{{ $purchaseItem->remaining_liters }}" data-remaining-label="{{ $purchaseItem->remaining_label }}" @selected((string) ($oldItem['purchase_item_id'] ?? '') === (string) $purchaseItem->id)>{{ $purchaseItem->label }}</option>
                                        @endforeach
                                    </select>
                                    @error("items.$index.purchase_item_id")<p class="field-error">{{ $message }}</p>@enderror
                                </div>
                                <div class="lift-field">
                                    <span class="lift-field-label">Fuel / Remaining in Depot</span>
                                    <p class="lift-line-summary-text" data-purchase-summary>Select Purchase ID</p>
                                </div>
                                <div class="lift-field">
                                    <label for="dispatch_quantity_{{ $index }}">Amount to Lift</label>
                                    <div class="input-suffix">
                                        <input id="dispatch_quantity_{{ $index }}" name="items[{{ $index }}][quantity_liters]" data-lifting-quantity type="number" min="0.01" step="0.01" inputmode="decimal" placeholder="Enter Amount" value="{{ $oldItem['quantity_liters'] ?? '' }}" required>
                                        <span aria-hidden="true">L</span>
                                    </div>
                                    @error("items.$index.quantity_liters")<p class="field-error">{{ $message }}</p>@enderror
                                </div>
                                <div class="lift-field lift-span-2">
                                    <label for="dispatch_atl_{{ $index }}">Authority to Load</label>
                                    <input id="dispatch_atl_{{ $index }}" name="items[{{ $index }}][atl_number]" type="text" maxlength="100" placeholder="Enter ATL" value="{{ $oldItem['atl_number'] ?? '' }}" @error("items.$index.atl_number") aria-invalid="true" @enderror>
                                    @error("items.$index.atl_number")<p class="field-error">{{ $message }}</p>@enderror
                                </div>
                                <div class="lift-purchase-buttons">
                                    <button class="btn btn-secondary" type="button" data-lifting-item-add>+ Add Purchase</button>
                                    <button class="btn btn-danger" type="button" data-lifting-item-remove disabled>Remove Purchase</button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Kept at the client's request (not on the mockup). --}}
                <div class="lift-fields lift-fields-3">
                    <div class="lift-field">
                        <label for="dispatch_dr_number">DR Number</label>
                        <input id="dispatch_dr_number" name="dr_number" type="text" value="{{ old('dr_number') }}" maxlength="100" placeholder="Enter DR Number" @error('dr_number') aria-invalid="true" @enderror>
                        @error('dr_number')<p class="field-error">{{ $message }}</p>@enderror
                    </div>
                </div>
                @if ($purchaseItems->isEmpty())
                    <p class="lifting-empty-note" role="note">No purchases have fuel remaining in the depot. Record a new purchase in Inventory, or cancel an unneeded lift to free its quantity.</p>
                @endif
                @error('items')<p class="field-error">{{ $message }}</p>@enderror
            </section>

            {{-- Depot to Client orders this lift delivers. Fuel Remaining in Truck = Amount to Lift - deliveries. --}}
            <div class="lift-deliveries" data-lift-deliveries>
                <h3 class="lift-section-title" data-deliveries-title @if (empty($oldDeliveries)) hidden @endif>Delivery Details</h3>
                <div class="lift-delivery-rows" data-delivery-rows>
                    @foreach ($oldDeliveries as $deliveryIndex => $delivery)
                        @include('dispatch.partials.lift-delivery-row', ['deliveryIndex' => $deliveryIndex, 'selectedSale' => $delivery['sale_id'] ?? null])
                    @endforeach
                </div>
                <template data-delivery-template>
                    @include('dispatch.partials.lift-delivery-row', ['deliveryIndex' => '__INDEX__', 'selectedSale' => null])
                </template>
                <div class="lift-delivery-footer">
                    <p class="lift-truck-remaining" data-truck-remaining-line aria-live="polite">Fuel Remaining in Truck: <output data-truck-remaining>0.00 L</output></p>
                    <button class="btn lift-add-row" type="button" data-delivery-add @disabled($directSaleOptions->isEmpty())>{{ $directSaleOptions->isEmpty() ? 'No open Depot to Client orders' : '+ Add Depot to Client Delivery' }}</button>
                </div>
                @error('deliveries')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                <p class="field-error" data-deliveries-warning role="alert"></p>
            </div>
            <dl class="lift-info">
                <div class="lift-info-row"><dt>Pickup Depot</dt><dd data-pickup-depot>Select a purchase to derive the official depot and address.</dd></div>
                <div class="lift-info-row"><dt>Truck Capacity</dt><dd data-truck-capacity>0.00 L</dd></div>
            </dl>
            <p class="field-error lift-totals-warning" data-lifting-warning role="alert"></p>
        </form>
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
                    <form method="POST" action="{{ route($liftRoutePrefix.'.hauls.return-to-depot', $row['haul_id']) }}" data-confirm-message="Return the {{ $row['truck_leftover_label'] }} left on the truck to the depot? The liters go back to their purchase.">
                        @csrf
                        <div class="modal-actions"><button class="btn btn-pill btn-danger-outline" type="submit">Return to Depot</button></div>
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
                @if ($row['can_delete'])
                    @include('dispatch.partials.lift-delete-form', ['row' => $row, 'pill' => true])
                @endif
                <button class="btn btn-pill btn-danger" type="button" data-modal-close>Close</button>
            </x-slot:footer>
        </x-admin.modal>
    @endforeach
@endcomponent
