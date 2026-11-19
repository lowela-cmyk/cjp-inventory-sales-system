@component('layouts.admin', ['title' => 'Historical Driver Records', 'subtitle' => 'CJP_DATA.PDF · KEPT FOR REFERENCE, NOT COUNTED IN INVENTORY OR SALES', 'active' => 'dashboard'])
    <div class="import-page" data-historical-driver-records>
        <p class="dash-note" role="note" style="border-left:4px solid #b45309;padding:8px 12px;background:#fffbeb;color:#78350f">
            These rows come from the Driver Schedule, Driver Hauled and Driver Deliveries sheets of CJP_DATA.pdf. Their IDs
            (DSCH-/DPO-, DHL-/DHP-, DDL-) match no purchase, lift or order in the imported records, and their volumes exceed the
            fuel actually purchased, so they are shown exactly as in the source and are <strong>not</strong> counted as lifts,
            deliveries or sales.
        </p>

        <nav class="tab-nav" aria-label="Driver record sheets" style="display:flex;gap:8px;margin:12px 0">
            @foreach ($sheets as $key => $label)
                <a class="btn {{ $key === $sheetKey ? 'btn-primary' : 'btn-secondary' }}" href="{{ route('admin.historical.driver-records', ['sheet' => $key]) }}" @if ($key === $sheetKey) aria-current="page" @endif>
                    {{ $label }} ({{ number_format($counts[$label] ?? 0) }})
                </a>
            @endforeach
        </nav>

        <x-filter.bar :action="route('admin.historical.driver-records')">
            <input type="hidden" name="sheet" value="{{ $sheetKey }}">
            <x-filter.search label="Search records" :value="$search" />
        </x-filter.bar>

        <div class="table-wrap">
            @if (! $rows || $rows->isEmpty())
                <div class="import-empty-state"><h3>No records found.</h3><p>{{ $rows ? 'Nothing matches this search.' : 'No historical import has been recorded.' }}</p></div>
            @else
                <table class="admin-table">
                    <thead><tr><th>Source row</th>@foreach ($columns as $column)<th>{{ $column }}</th>@endforeach</tr></thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr><td>{{ $row->source_row }}</td>@foreach ($columns as $column)<td>{{ $row->values[$column] ?? '' }}</td>@endforeach</tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        @if ($rows){{ $rows->links() }}@endif
    </div>
@endcomponent
