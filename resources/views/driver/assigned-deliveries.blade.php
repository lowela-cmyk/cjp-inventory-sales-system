@php
    $activeTab = $activeTab ?? 'active';
    $driverName = $driverName ?? auth()->user()?->name;
    $summaryCards = $summaryCards ?? [];
    $filters = $filters ?? [];
    $filterOptions = $filterOptions ?? ['statuses' => [], 'fuelTypes' => collect()];
    $pickupIdempotencyKey = $pickupIdempotencyKey ?? (string) \Illuminate\Support\Str::uuid();
    $deliveryStatusIdempotencyKey = $deliveryStatusIdempotencyKey ?? (string) \Illuminate\Support\Str::uuid();
@endphp

@component('layouts.driver', ['title' => 'Assigned Deliveries', 'active' => 'assigned-deliveries', 'driverName' => $driverName])
    <div data-tabs>
        <h2 class="section-title" data-driver-heading>{{ $activeTab === 'completed' ? 'Completed Deliveries' : 'Assigned Deliveries' }}</h2>

        @if (session('status'))
            <div class="alert-bar alert-success">{{ session('status') }}</div>
        @endif

        @if ($errors->any())
            <div class="alert-bar alert-warning">{{ $errors->first() }}</div>
        @endif

        <div class="tabs">
            <button class="tab-button {{ $activeTab === 'active' ? 'is-active' : '' }}" type="button" data-tab-target="active" data-heading="Assigned Deliveries">Active</button>
            <button class="tab-button {{ $activeTab === 'completed' ? 'is-active' : '' }}" type="button" data-tab-target="completed" data-heading="Completed Deliveries">Completed</button>
        </div>

        <div class="actions-right">
            <button class="btn btn-secondary" type="button" onclick="window.print()">Export</button>
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

        <section data-tab-panel="active" {{ $activeTab !== 'active' ? 'hidden' : '' }}>
            <form class="driver-filter-row" method="GET" action="{{ route('driver.assigned-deliveries') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search assigned deliveries" value="{{ $search }}">
                <select name="task_status" aria-label="Filter by delivery status">
                    <option value="">Status (All)</option>
                    @foreach ($filterOptions['statuses'] as $status)
                        <option value="{{ $status }}" @selected(($filters['task_status'] ?? '') === $status)>{{ ucwords(str_replace('_', ' ', $status)) }}</option>
                    @endforeach
                </select>
                <input type="date" name="date_from" aria-label="Filter from date" value="{{ $filters['date_from'] ?? '' }}">
                <input type="date" name="date_to" aria-label="Filter to date" value="{{ $filters['date_to'] ?? '' }}">
                <select name="source_type" aria-label="Filter by source">
                    <option value="">Source</option>
                    <option value="depot" @selected(($filters['source_type'] ?? '') === 'depot')>Depot</option>
                    <option value="garage" @selected(($filters['source_type'] ?? '') === 'garage')>Garage</option>
                </select>
                <select name="fuel_type_id" aria-label="Filter by fuel type">
                    <option value="">Fuel Type (All)</option>
                    @foreach ($filterOptions['fuelTypes'] as $fuelType)
                        <option value="{{ $fuelType->id }}" @selected((string) ($filters['fuel_type_id'] ?? '') === (string) $fuelType->id)>{{ $fuelType->name }}</option>
                    @endforeach
                </select>
                <button class="btn btn-primary" type="submit">Filter</button>
            </form>

            @include('driver.partials.assigned-deliveries-table', ['rows' => $activeRows, 'emptyText' => 'No assigned deliveries'])
        </section>

        <section data-tab-panel="completed" {{ $activeTab !== 'completed' ? 'hidden' : '' }}>
            <form class="driver-filter-row" method="GET" action="{{ route('driver.assigned-deliveries.completed') }}">
                <input type="search" name="search" placeholder="Search..." aria-label="Search completed deliveries" value="{{ $search }}">
                <select name="task_status" aria-label="Filter by delivery status">
                    <option value="">Status (All)</option>
                    @foreach ($filterOptions['statuses'] as $status)
                        <option value="{{ $status }}" @selected(($filters['task_status'] ?? '') === $status)>{{ ucwords(str_replace('_', ' ', $status)) }}</option>
                    @endforeach
                </select>
                <input type="date" name="date_from" aria-label="Filter from date" value="{{ $filters['date_from'] ?? '' }}">
                <input type="date" name="date_to" aria-label="Filter to date" value="{{ $filters['date_to'] ?? '' }}">
                <select name="source_type" aria-label="Filter by source">
                    <option value="">Source</option>
                    <option value="depot" @selected(($filters['source_type'] ?? '') === 'depot')>Depot</option>
                    <option value="garage" @selected(($filters['source_type'] ?? '') === 'garage')>Garage</option>
                </select>
                <select name="fuel_type_id" aria-label="Filter by fuel type">
                    <option value="">Fuel Type (All)</option>
                    @foreach ($filterOptions['fuelTypes'] as $fuelType)
                        <option value="{{ $fuelType->id }}" @selected((string) ($filters['fuel_type_id'] ?? '') === (string) $fuelType->id)>{{ $fuelType->name }}</option>
                    @endforeach
                </select>
                <button class="btn btn-primary" type="submit">Filter</button>
            </form>

            @include('driver.partials.assigned-deliveries-table', ['rows' => $completedRows, 'emptyText' => 'No completed deliveries'])
        </section>
    </div>

    @foreach ($activeRows->merge($completedRows) as $row)
        <x-admin.modal id="{{ $row['id'] }}" title="Delivery Details">
            <div class="modal-card">
                <p class="detail-id">{{ $row['delivery']['reference'] }}</p>
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
                @if (! empty($row['can_confirm_pickup']))
                    <form method="POST" action="{{ route('driver.assigned-deliveries.pickup', $row['record_id']) }}" onsubmit="return confirm('Confirm pickup for {{ $row['delivery']['reference'] }}?');">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="idempotency_key" value="{{ $pickupIdempotencyKey }}">
                        <button class="btn btn-pill btn-primary" type="submit">Confirm Pickup</button>
                    </form>
                @endif
                @if (! empty($row['allowed_driver_delivery_statuses']))
                    <form method="POST" action="{{ route('driver.assigned-deliveries.status', $row['record_id']) }}" onsubmit="return confirm('Update delivery status for {{ $row['delivery']['reference'] }}?');">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="idempotency_key" value="{{ $deliveryStatusIdempotencyKey }}">
                        <select name="status" aria-label="Update delivery status">
                            @foreach ($row['allowed_driver_delivery_statuses'] as $status)
                                <option value="{{ $status }}">{{ ucwords(str_replace('_', ' ', $status)) }}</option>
                            @endforeach
                        </select>
                        <button class="btn btn-pill btn-primary" type="submit">Update Status</button>
                    </form>
                @endif
                <button class="btn btn-pill btn-secondary" type="button" data-modal-close>Close</button>
            </div>
        </x-admin.modal>
    @endforeach
@endcomponent
