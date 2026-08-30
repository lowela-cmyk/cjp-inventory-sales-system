@component('layouts.admin', ['title' => 'Fuel Lifting Operations', 'active' => 'fuel-lifting'])
    <div data-tabs>
        <h2 class="section-title">Schedule</h2>
        <div class="tabs">
            <button class="tab-button is-active" type="button" data-tab-target="schedule">Schedule</button>
            <button class="tab-button" type="button" data-tab-target="hauled">Hauled</button>
        </div>
        <div class="actions-right"><button class="btn btn-secondary" type="button">Export</button></div>

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
        </x-admin.modal>
    @endforeach
@endcomponent
