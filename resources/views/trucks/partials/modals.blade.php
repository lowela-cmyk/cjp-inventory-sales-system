{{-- Add / View-Edit truck modals for the Fuel Lifting > Trucks tab. --}}
<x-admin.modal id="truck-add" reset-on-close title="Add Truck" wide>
    <form method="POST" action="{{ route($truckRoutePrefix.'.store') }}" data-prevent-double-submit>
        @csrf
        @include('trucks.partials.form', ['truck' => null])
        <div class="modal-actions"><button class="btn btn-primary" type="submit">Add Truck</button><button class="btn btn-secondary" type="button" data-modal-close>Cancel</button></div>
    </form>
</x-admin.modal>

@foreach ($truckDirectory as $truck)
    <x-admin.modal id="truck-edit-{{ $truck->id }}" title="Truck Details" wide>
        <div class="fleet-status-banner">
            <div><span>Current availability</span><x-admin.status-badge :status="ucwords(str_replace('_', ' ', $truck->effective_status))" /></div>
            <div><span>Active lift records</span><strong>{{ number_format((int) $truck->active_lift_count) }}</strong></div>
        </div>
        <form method="POST" action="{{ route($truckRoutePrefix.'.update', $truck->id) }}" data-prevent-double-submit data-confirm-item="{{ $truck->truck_code }}{{ $truck->plate_number ? ' · '.$truck->plate_number : '' }}">
            @csrf
            @method('PATCH')
            @include('trucks.partials.form', ['truck' => $truck])
            <div class="modal-actions">
                <button class="btn btn-primary" type="submit">Apply Changes</button>
                <button class="btn btn-secondary" type="button" data-modal-close>Close</button>
            </div>
        </form>
        {{-- Part 23 #8: trucks are no longer deactivated (Maintenance covers it); a legacy inactive truck can be activated. --}}
        @if ($truck->status === 'inactive')
            <form class="fleet-activation-form" method="POST" action="{{ route($truckRoutePrefix.'.status', $truck->id) }}" data-confirm="status" data-confirm-title="Activate truck?"
                data-confirm-item="{{ $truck->truck_code }}{{ $truck->plate_number ? ' · '.$truck->plate_number : '' }}" data-confirm-from="Inactive" data-confirm-to="Available" data-confirm-message="Activate this truck?">
                @csrf
                @method('PATCH')
                <button class="btn btn-secondary" type="submit">Activate Truck</button>
            </form>
        @endif
    </x-admin.modal>
@endforeach
