{{-- Part 13: Garage tab (Inventory and Fuel Lifting). Expects: $garage (GarageStockService::tab()), $garageFilters,
     $garageSearch, $garageRoute. Each fuel shows its Tank 1 and Tank 2 separately; the per-fuel total is extra. --}}
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

<div class="table-wrap">
    <table class="admin-table garage-table">
        <thead><tr><th>Purchase ID</th><th>Fuel</th><th>Tank</th><th>Liters in Garage</th><th>Cost / Liter</th><th>From Lift</th><th>Date Unloaded</th></tr></thead>
        <tbody>
            @forelse ($garage['lots'] as $lot)
                <tr>
                    <td class="cell-id">{{ $lot->purchase_code }}</td>
                    <td>{{ $lot->fuel_name }}</td>
                    <td>{{ $lot->tank_name }}</td>
                    <td class="inventory-number">{{ number_format($lot->in_garage, 2) }} L</td>
                    <td class="inventory-number">{{ number_format($lot->unit_cost, 2) }}</td>
                    <td>{{ $lot->lift_label }}</td>
                    <td>{{ \Carbon\CarbonImmutable::parse($lot->unloaded_at)->format('M d, Y h:i A') }}</td>
                </tr>
            @empty
                <tr><td class="empty-cell" colspan="7">No fuel in the garage.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
