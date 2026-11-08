@php
    $accountId = fn ($user) => strtoupper(substr((string) $user->role, 0, 3)).'-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT);
@endphp

<div class="table-wrap">
    <table class="admin-table account-request-table" data-card-keys="0,1">
        <thead>
            <tr>
                <th>Name / Username</th>
                <th>Role</th>
                <th>Registration Date</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($requests as $requestUser)
                <tr>
                    <td>
                        <strong>{{ $requestUser->name }}</strong>
                        <span>{{ $requestUser->email }}</span>
                    </td>
                    <td>{{ $requestUser->role_label }}</td>
                    <td>{{ $requestUser->created_at?->format('M d, Y h:i A') ?? 'N/A' }}</td>
                    <td><x-admin.status-badge :status="ucfirst($requestUser->approval_status)" /></td>
                    <td>
                        @if ($requestUser->approval_status === 'pending')
                            <div class="account-request-actions">
                                <form method="POST" action="{{ route('admin.account-requests.update', $requestUser) }}"
                                    data-confirm="status" data-confirm-title="Approve account request?" data-confirm-item="{{ $requestUser->name }} ({{ $requestUser->role_label ?? ucwords(str_replace('_', ' ', $requestUser->role)) }}, {{ $requestUser->email }})"
                                    data-confirm-from="Pending" data-confirm-to="Approved" data-confirm-ok="Approve">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="approval_status" value="approved">
                                    <button class="btn btn-success" type="submit">Approve</button>
                                </form>
                                <form method="POST" action="{{ route('admin.account-requests.update', $requestUser) }}"
                                    data-confirm="delete" data-confirm-action="Reject account request" data-confirm-item="{{ $requestUser->name }} ({{ $requestUser->role_label ?? ucwords(str_replace('_', ' ', $requestUser->role)) }}, {{ $requestUser->email }})"
                                    data-confirm-undo="This can't be undone. They will not be able to sign in." data-confirm-ok="Reject"
                                    data-confirm-message="Reject the account request of {{ $requestUser->name }}? They will not be able to sign in.">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="approval_status" value="rejected">
                                    <button class="btn btn-danger" type="submit">Reject</button>
                                </form>
                            </div>
                        @else
                            <span class="account-request-decided" aria-label="No action needed">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td class="empty-cell" colspan="5">No account requests found.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
