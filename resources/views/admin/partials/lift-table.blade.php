<div class="table-wrap">
    <table class="admin-table">
        <thead>
            <tr><th>Lift ID</th><th>Purchase ID</th><th>DR Number</th><th>Lift Date</th><th>Location</th><th>Driver Name</th><th>Driver's Contact No.</th><th>Truck ID</th><th>Capacity</th><th>Quantity Lift</th><th>Status</th><th>Actions</th></tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr class="{{ $row[11] }}"><td>{{ $row[0] }}</td><td>{{ $row[1] }}</td><td>{{ $row[2] }}</td><td>{{ $row[3] }}</td><td>{{ $row[4] }}</td><td>{{ $row[5] }}</td><td>{{ $row[6] }}</td><td>{{ $row[7] }}</td><td>{{ $row[8] }}</td><td>{{ $row[9] }}</td><td><x-admin.status-badge :status="$row[10]" /></td><td><button class="btn btn-secondary" type="button" data-modal-open="lift-detail">Edit</button></td></tr>
            @endforeach
        </tbody>
    </table>
</div>
