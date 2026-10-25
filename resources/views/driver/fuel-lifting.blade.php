@php
    $activeTab = $activeTab ?? 'schedule';
    $driverName = $driverName ?? auth()->user()?->name;
    $summaryCards = $summaryCards ?? [];
    $driverProfile = $driverProfile ?? [];
    $filters = $filters ?? [];
    $filterOptions = $filterOptions ?? ['statuses' => [], 'fuelTypes' => collect(), 'depots' => collect()];
    $liftingStatusIdempotencyKey = $liftingStatusIdempotencyKey ?? (string) \Illuminate\Support\Str::uuid();
@endphp

@component('layouts.driver', ['title' => 'Fuel Lifting Operations', 'active' => 'fuel-lifting', 'driverName' => $driverName])
    <div data-tabs>
        <h2 class="section-title" data-driver-heading>{{ $activeTab === 'hauled' ? 'Hauled' : 'Schedule' }}</h2>



        <div class="tabs">
            <button class="tab-button {{ $activeTab === 'schedule' ? 'is-active' : '' }}" type="button" data-tab-target="schedule" data-tab-url="{{ route('driver.fuel-lifting') }}" data-heading="Schedule">Schedule</button>
            <button class="tab-button {{ $activeTab === 'hauled' ? 'is-active' : '' }}" type="button" data-tab-target="hauled" data-tab-url="{{ route('driver.fuel-lifting.hauled') }}" data-heading="Hauled">Hauled</button>
        </div>

        <div class="actions-right">
            <button class="btn btn-secondary" type="button" data-export-table>Export</button>
        </div>

        @if (! empty($summaryCards))
            <div class="metric-row driver-metric-row">
                @foreach ($summaryCards as $card)
                    <div class="metric-card">
                        <em>{{ $card['label'] }}</em>
                        <strong>{{ $card['value'] }}</strong>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="driver-overview-card">
            <div class="driver-overview-identity">
                <div>
                    <span class="driver-overview-kicker">Driver</span>
                    <strong>{{ $driverProfile['Name'] ?? $driverName ?? 'Driver' }}</strong>
                    <span>{{ $driverProfile['Driver ID'] ?? 'ID unavailable' }}</span>
                </div>
                <x-admin.status-badge :status="$driverProfile['Profile Status'] ?? 'N/A'" />
            </div>
            <dl class="driver-profile-grid">
                @foreach (collect($driverProfile)->except(['Name', 'Driver ID', 'Profile Status']) as $label => $value)
                    <div class="driver-profile-field">
                        <dt>{{ $label }}</dt>
                        <dd>{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>

        <section data-tab-panel="schedule" {{ $activeTab !== 'schedule' ? 'hidden' : '' }}>
            <x-filter.bar class="driver-filter-row" :action="route('driver.fuel-lifting')">
                <x-filter.search label="Search scheduled lifts" :value="$search" />
                <x-filter.select name="task_status" label="Status" :selected="$filters['task_status'] ?? null"
                    :options="collect($filterOptions['statuses'])->mapWithKeys(fn (string $status): array => [$status => ucwords(str_replace('_', ' ', $status))])" />
                <x-filter.date-select name="date_from" label="From date" :value="$filters['date_from'] ?? null" :dates="$filterOptions['liftDates']" />
                <x-filter.date-select name="date_to" label="To date" :value="$filters['date_to'] ?? null" :dates="$filterOptions['liftDates']" />
                <x-filter.select name="depot_id" label="Depot" :options="$filterOptions['depots']->pluck('name', 'id')" :selected="$filters['depot_id'] ?? null" />
                <x-filter.select name="destination_type" label="Destination" :options="['customer' => 'Client']" :selected="$filters['destination_type'] ?? null" />
                <x-filter.select name="fuel_type_id" label="Fuel Type" :options="$filterOptions['fuelTypes']->pluck('name', 'id')" :selected="$filters['fuel_type_id'] ?? null" />
            </x-filter.bar>

            <div class="table-wrap driver-table-wrap">
                <table class="admin-table driver-table">
                    <thead>
                        <tr>
                            <th>Lift-ID</th>
                            <th>Purchase IDs</th>
                            <th>Pickup Depot</th>
                            <th>Lift Date</th>
                            <th>Depot Address</th>
                            <th>Truck-ID</th>
                            <th>Capacity</th>
                            <th>QTY to Lift</th>
                            <th>Status</th>
                            <th>Receipt</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($scheduleRows as $row)
                            <tr>
                                @foreach ($row['cells'] as $cell)
                                    <td>@if ($loop->last)<x-admin.status-badge :status="$cell" />@else{{ $cell }}@endif</td>
                                @endforeach
                                <td><span class="receipt-status {{ $row['withdrawal_uploaded'] ? 'is-uploaded' : '' }}" data-receipt-status="{{ $row['record_id'] }}">{{ $row['withdrawal_uploaded'] ? 'Uploaded' : 'Not uploaded' }}</span></td>
                                <td>
                                    {{-- The flex wrapper sits inside the cell: a flex <td> stops being a table cell. --}}
                                    <div class="driver-row-actions">
                                        <button class="btn btn-secondary" type="button" data-modal-open="{{ $row['id'] }}">View</button>
                                        <button class="btn btn-primary" type="button" data-modal-open="{{ $row['id'] }}" @disabled(! $row['can_upload_withdrawal']) title="{{ $row['can_upload_withdrawal'] ? 'Upload withdrawal receipt' : 'Available after this lift is marked Lifted' }}">Upload Withdrawal Receipt</button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td class="driver-empty-cell" colspan="11">No Schedules</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section data-tab-panel="hauled" {{ $activeTab !== 'hauled' ? 'hidden' : '' }}>
            <x-filter.bar class="driver-filter-row" :action="route('driver.fuel-lifting.hauled')">
                <x-filter.search label="Search hauled lifts" :value="$search" />
                <x-filter.select name="task_status" label="Status" :selected="$filters['task_status'] ?? null"
                    :options="collect($filterOptions['statuses'])->mapWithKeys(fn (string $status): array => [$status => ucwords(str_replace('_', ' ', $status))])" />
                <x-filter.date-select name="date_from" label="From date" :value="$filters['date_from'] ?? null" :dates="$filterOptions['liftDates']" />
                <x-filter.date-select name="date_to" label="To date" :value="$filters['date_to'] ?? null" :dates="$filterOptions['liftDates']" />
                <x-filter.select name="depot_id" label="Depot" :options="$filterOptions['depots']->pluck('name', 'id')" :selected="$filters['depot_id'] ?? null" />
                <x-filter.select name="destination_type" label="Destination" :options="['customer' => 'Client']" :selected="$filters['destination_type'] ?? null" />
                <x-filter.select name="fuel_type_id" label="Fuel Type" :options="$filterOptions['fuelTypes']->pluck('name', 'id')" :selected="$filters['fuel_type_id'] ?? null" />
            </x-filter.bar>

            <div class="table-wrap driver-table-wrap">
                <table class="admin-table driver-table">
                    <thead>
                        <tr>
                            <th>Lift-ID</th>
                            <th>Purchase IDs</th>
                            <th>Pickup Depot</th>
                            <th>Lift Date</th>
                            <th>Depot Address</th>
                            <th>Truck-ID</th>
                            <th>Capacity</th>
                            <th>QTY Lifted</th>
                            <th>Status</th>
                            <th>Receipt</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($hauledRows as $row)
                            <tr>
                                @foreach ($row['cells'] as $cell)
                                    <td>@if ($loop->last)<x-admin.status-badge :status="$cell" />@else{{ $cell }}@endif</td>
                                @endforeach
                                <td><span class="receipt-status {{ $row['withdrawal_uploaded'] ? 'is-uploaded' : '' }}" data-receipt-status="{{ $row['record_id'] }}">{{ $row['withdrawal_uploaded'] ? 'Uploaded' : 'Not uploaded' }}</span></td>
                                <td>
                                    <div class="driver-row-actions">
                                        <button class="btn btn-secondary" type="button" data-modal-open="{{ $row['id'] }}">View</button>
                                        @if ($row['can_upload_withdrawal'])
                                            <button class="btn btn-primary" type="button" data-modal-open="{{ $row['id'] }}">{{ $row['withdrawal_uploaded'] ? 'Replace Withdrawal Receipt' : 'Upload Withdrawal Receipt' }}</button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td class="driver-empty-cell" colspan="11">No Records Available</td>
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
                            {{-- Withdrawal fields update in place after an upload (see app.js). --}}
                            <div class="detail-value" data-detail="{{ \Illuminate\Support\Str::slug($label) }}">{{ $value }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
            <div class="withdrawal-status-card {{ $row['withdrawal_uploaded'] ? 'is-uploaded' : '' }}" data-withdrawal-card>
                <strong data-withdrawal-title>Withdrawal receipt: {{ $row['withdrawal_uploaded'] ? 'Uploaded' : 'Not uploaded' }}</strong>
                <span data-withdrawal-uploaded-at @unless ($row['withdrawal_uploaded_at']) hidden @endunless>Uploaded {{ $row['withdrawal_uploaded_at'] }}</span>
                <span data-withdrawal-notes @unless ($row['withdrawal_notes']) hidden @endunless>Notes: {{ $row['withdrawal_notes'] }}</span>
                {{-- The uploaded receipt: click to open it full size in a new tab. --}}
                <a class="withdrawal-thumb" href="{{ $row['withdrawal_url'] ?? '#' }}" target="_blank" rel="noopener" data-withdrawal-link @unless ($row['withdrawal_url'] ?? null) hidden @endunless>
                    <img src="{{ $row['withdrawal_url'] ?? '' }}" alt="Uploaded withdrawal receipt for {{ $row['cells'][0] }}" loading="lazy" data-withdrawal-image>
                    <span>View full size</span>
                </a>
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
                @if (($row['kind'] ?? '') === 'Lift' && ! empty($row['can_upload_withdrawal']))
                    <form class="withdrawal-upload-form" method="POST" action="{{ route('driver.fuel-lifting.hauls.withdrawal-receipt.store', $row['record_id']) }}" enctype="multipart/form-data" data-withdrawal-upload="{{ $row['record_id'] }}">
                        @csrf
                        <label class="file-button">
                            <span data-file-label>{{ $row['withdrawal_uploaded'] ? 'Choose replacement image' : 'Choose receipt image' }}</span>
                            <input name="withdrawal_receipt" type="file" accept="image/jpeg,image/png,image/webp" required>
                        </label>
                        <input name="withdrawal_notes" type="text" maxlength="1000" placeholder="Optional notes">
                        <button class="btn btn-pill btn-primary" type="submit" data-upload-button>{{ $row['withdrawal_uploaded'] ? 'Replace Receipt' : 'Upload Withdrawal Receipt' }}</button>
                        {{-- The chosen file, shown before it is uploaded. --}}
                        <div class="withdrawal-preview" data-withdrawal-preview hidden>
                            <img alt="" data-preview-image>
                            <span data-preview-name></span>
                        </div>
                        <p class="withdrawal-upload-message" role="status" aria-live="polite" data-upload-message hidden></p>
                    </form>
                @endif
                <button class="btn btn-pill btn-secondary" type="button" data-modal-close>Close</button>
            </div>
        </x-admin.modal>
    @endforeach
@endcomponent
