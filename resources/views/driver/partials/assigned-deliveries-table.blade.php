<div class="table-wrap driver-table-wrap">
    <table class="admin-table driver-table">
        <thead>
            <tr>
                <th>Delivery Ref</th>
                <th>Lift Ref</th>
                <th>Customer</th>
                <th>Fuel Type</th>
                <th>Quantity</th>
                <th>Source</th>
                <th>Destination</th>
                <th>Truck</th>
                <th>Schedule</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ $row['delivery']['reference'] }}</td>
                    <td>{{ $row['delivery']['lift_reference'] }}</td>
                    <td>{{ $row['delivery']['customer'] }}</td>
                    <td>{{ $row['delivery']['fuel_type'] }}</td>
                    <td>{{ $row['delivery']['quantity'] }}</td>
                    <td>{{ $row['delivery']['source'] }}</td>
                    <td>{{ $row['delivery']['destination'] }}</td>
                    <td>{{ $row['delivery']['truck'] }}</td>
                    <td>{{ $row['delivery']['scheduled_at'] }}</td>
                    <td>{{ $row['delivery']['status'] }}</td>
                    <td><button class="btn btn-secondary" type="button" data-modal-open="{{ $row['id'] }}">View</button></td>
                </tr>
            @empty
                <tr>
                    <td class="driver-empty-cell" colspan="11">{{ $emptyText }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
