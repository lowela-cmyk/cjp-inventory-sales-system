{{-- Fuel Lifting > Trucks tab. Expects: $truckDirectory, $truckSearch, $truckStatusFilter, $truckStatuses, $truckRoutePrefix, $liftRoutePrefix. --}}
<x-filter.bar class="fleet-toolbar" :action="route($liftRoutePrefix.'.trucks')">
    <x-filter.search label="Search trucks" placeholder="Search plate or truck code" :value="$truckSearch" />
    <x-filter.select name="status" label="Status" :selected="$truckStatusFilter"
        :options="collect($truckStatuses)->mapWithKeys(fn (string $status): array => [$status => ucwords(str_replace('_', ' ', $status))])" />
    <button class="btn btn-secondary fleet-add-button" type="button" data-modal-open="truck-add">+ Add Truck</button>
</x-filter.bar>

<div class="table-wrap">
    <table class="admin-table fleet-table" data-card-keys="0,1,3">
        <thead><tr><th>Plate Number</th><th>Truck Code</th><th>Capacity</th><th>On Truck</th><th>Status</th><th>Active Lifts</th><th>Next Schedule</th><th>Actions</th></tr></thead>
        <tbody>
            @forelse ($truckDirectory as $truck)
                <tr>
                    <td><strong>{{ $truck->plate_number }}</strong></td>
                    <td>{{ $truck->truck_code }}</td>
                    <td>{{ number_format((float) $truck->capacity_liters, 2) }} L</td>
                    {{-- Part 12: fuel still on the truck from a lift (Loaded until delivered or returned). --}}
                    {{-- Part 23 #8: never more than the truck can hold (only Lifted lines carry fuel). --}}
                    <td>{{ number_format(min((float) ($truck->leftover_liters ?? 0), (float) $truck->capacity_liters), 2) }} L</td>
                    <td><x-admin.status-badge :status="ucwords(str_replace('_', ' ', $truck->effective_status))" /></td>
                    <td>{{ number_format((int) $truck->active_lift_count) }}</td>
                    <td>{{ $truck->next_schedule ? \Carbon\CarbonImmutable::parse($truck->next_schedule)->format('M d, Y g:i A') : '-' }}</td>
                    <td><button class="btn btn-secondary" type="button" data-modal-open="truck-edit-{{ $truck->id }}">View / Edit</button></td>
                </tr>
            @empty
                <tr><td class="empty-cell" colspan="8">No trucks found.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="pagination-wrap">{{ $truckDirectory->links() }}</div>
