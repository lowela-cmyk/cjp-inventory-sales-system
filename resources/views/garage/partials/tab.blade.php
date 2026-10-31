{{-- Part 13: Garage tab (Inventory and Fuel Lifting). Expects: $garage (GarageStockService::tab()), $garageFilters,
     $garageSearch, $garageRoute. Each fuel shows its Tank 1 and Tank 2 separately; the per-fuel total is extra.
     Part 16 #5: the table has one row per tank, and View opens the tank's stock by purchase and its lots, newest first. --}}
<div class="garage-fuels">
    @foreach ($garage['fuels'] as $fuel)
        <section class="garage-fuel-card" aria-label="{{ $fuel['fuel_name'] }} garage tanks">
            <header class="garage-fuel-head">
                <h3>{{ $fuel['fuel_name'] }}</h3>
                <span>Total {{ number_format($fuel['total'], 2) }} L of {{ number_format($fuel['capacity'], 2) }} L</span>
            </header>
            <div class="garage-tanks">
                @foreach ($fuel['tanks'] as $tank)
                    <div class="garage-tank" data-garage-tank="{{ $tank->id }}">
                        <div class="garage-tank-name">Tank {{ $tank->tank_number }}</div>
                        <div class="garage-tank-liters">{{ number_format($tank->contents, 2) }} L <small>({{ rtrim(rtrim(number_format($tank->percent, 1), '0'), '.') }}%)</small></div>
                        <div class="garage-tank-bar" role="meter" aria-label="{{ $tank->name }} fill" aria-valuemin="0" aria-valuemax="{{ $tank->capacity }}" aria-valuenow="{{ $tank->contents }}">
                            <span style="width: {{ min(100, $tank->percent) }}%"></span>
                        </div>
                        <div class="garage-tank-free">{{ number_format($tank->free, 2) }} L free of {{ number_format($tank->capacity, 2) }} L</div>
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach
</div>

<x-filter.bar class="toolbar-inventory garage-toolbar" :action="$garageRoute">
    <x-filter.search label="Search garage stock" :value="$garageSearch" />
    <x-filter.select name="fuel_type_id" label="Fuel Type" :selected="$garageFilters['fuel_type_id'] ?? null" :options="$garage['fuelOptions']" />
    <x-filter.select name="tank_id" label="Tank" :selected="$garageFilters['tank_id'] ?? null" :options="$garage['tankOptions']" />
    <x-filter.date-range label="Date unloaded" from="unloaded_from" to="unloaded_to" :from-value="$garageFilters['date_from'] ?? null" :to-value="$garageFilters['date_to'] ?? null" />
</x-filter.bar>

@php
    $garageLiters = fn (float $value): string => number_format($value, 2).' L';
@endphp
<div class="table-wrap">
    <table class="admin-table garage-table garage-tank-table">
        <thead><tr><th>Tank</th><th>Capacity</th><th>Stock Quantity</th><th>Purchase IDs</th><th class="export-only" hidden>Lots (newest first)</th><th>Actions</th></tr></thead>
        <tbody>
            @forelse ($garage['tankRows'] as $tank)
                <tr>
                    <td><strong>{{ $tank->name }}</strong></td>
                    <td class="inventory-number">{{ $garageLiters($tank->capacity) }}</td>
                    <td class="inventory-number">{{ $garageLiters($tank->contents) }}</td>
                    <td>{{ $tank->purchases->pluck('purchase_code')->implode(', ') ?: '—' }}</td>
                    <td class="export-only" hidden>{{ $tank->lots->map(fn (object $lot): string => $lot->purchase_code.' '.$garageLiters($lot->liters).' unloaded '.\Carbon\CarbonImmutable::parse($lot->unloaded_at)->format('M d, Y h:i A').' ('.$garageLiters($lot->in_garage).' left)')->implode('; ') ?: '—' }}</td>
                    <td><button class="btn btn-secondary" type="button" data-modal-open="garage-tank-{{ $tank->id }}">View</button></td>
                </tr>
            @empty
                <tr><td class="empty-cell" colspan="6">No garage tank matches the filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

@foreach ($garage['tankRows'] as $tank)
    <x-admin.modal id="garage-tank-{{ $tank->id }}" title="View Tank Stock">
        <div class="modal-card garage-tank-view">
            <p class="detail-id">{{ $tank->name }}</p>
            <div class="garage-tank-figures">
                <span>Quantity: <strong>{{ $garageLiters($tank->contents) }}</strong></span>
                <span>Remaining Capacity: <strong>{{ $garageLiters($tank->free) }}</strong></span>
            </div>
            <h3 class="lift-section-title">Stock by purchase</h3>
            @if ($tank->purchases->isEmpty())
                <p class="table-help">This tank is empty.</p>
            @else
                <div class="garage-tank-split" role="img" aria-label="{{ $tank->name }} stock by purchase">
                    @foreach ($tank->purchases as $share)
                        <div class="garage-tank-share" style="flex: {{ max(1, $share['liters']) }} 1 0">
                            <span class="garage-tank-share-code">{{ $share['purchase_code'] }}</span>
                            <span>{{ rtrim(rtrim(number_format($share['percent'], 1), '0'), '.') }}%</span>
                            <strong>{{ $garageLiters($share['liters']) }}</strong>
                        </div>
                    @endforeach
                </div>
            @endif
            <h3 class="lift-section-title">Lots, newest first</h3>
            <div class="table-wrap">
                <table class="admin-table garage-lot-table">
                    <thead><tr><th>Purchase ID</th><th>Unloaded</th><th>In Tank Now</th><th>From Lift / Truck</th><th>Date Unloaded</th></tr></thead>
                    <tbody>
                        @forelse ($tank->lots as $lot)
                            <tr>
                                <td class="cell-id">{{ $lot->purchase_code }}</td>
                                <td class="inventory-number">{{ $garageLiters($lot->liters) }}</td>
                                <td class="inventory-number">{{ $garageLiters($lot->in_garage) }}</td>
                                <td>{{ $lot->lift_label }}</td>
                                <td>{{ \Carbon\CarbonImmutable::parse($lot->unloaded_at)->format('M d, Y h:i A') }}</td>
                            </tr>
                        @empty
                            <tr><td class="empty-cell" colspan="5">Nothing was unloaded into this tank yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <x-slot:footer>
            <button class="btn btn-pill btn-danger" type="button" data-modal-close>Close</button>
        </x-slot:footer>
    </x-admin.modal>
@endforeach
