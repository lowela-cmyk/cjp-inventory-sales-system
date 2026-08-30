<div class="table-wrap">
    <table class="admin-table">
        <thead>
            <tr><th>Lift ID</th><th>Purchase ID</th><th>DR Number</th><th>Lift Date</th><th>Location</th><th>Driver Name</th><th>Driver's Contact No.</th><th>Truck ID</th><th>Capacity</th><th>Quantity Lift</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr class="{{ $row['class'] }}">
                    @foreach ($row['cells'] as $cell)
                        @if ($loop->last)
                            <td><x-admin.status-badge :status="$cell" /></td>
                        @else
                            <td>{{ $cell }}</td>
                        @endif
                    @endforeach
                    <td><button class="btn btn-secondary" type="button" data-modal-open="{{ $row['id'] }}">View</button></td>
                </tr>
            @empty
                <tr><td class="empty-cell" colspan="12">No records found.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
