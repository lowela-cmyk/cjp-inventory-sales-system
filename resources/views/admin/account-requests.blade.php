@php
    $accountId = fn ($user) => strtoupper(substr((string) $user->role, 0, 3)).'-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT);
    $rowClass = fn ($status) => match ($status) {
        'approved' => 'row-success',
        'rejected' => 'row-danger',
        default => 'row-warning',
    };
@endphp

@component('layouts.admin', ['title' => 'Account Requests', 'active' => 'account-requests'])
    <h2 class="section-title">Account Requests</h2>

    @if (session('status'))
        <div class="admin-flash admin-flash-success" role="status">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="admin-flash admin-flash-error" role="alert">{{ $errors->first() }}</div>
    @endif

    <div class="table-wrap">
        <table class="admin-table account-request-table">
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
                    <tr class="{{ $rowClass($requestUser->approval_status) }}">
                        <td>
                            <strong>{{ $requestUser->name }}</strong>
                            <span>{{ $requestUser->email }}</span>
                        </td>
                        <td>{{ $requestUser->role_label }}</td>
                        <td>{{ $requestUser->created_at?->format('M d, Y h:i A') ?? 'N/A' }}</td>
                        <td><x-admin.status-badge :status="ucfirst($requestUser->approval_status)" /></td>
                        <td>
                            <div class="account-request-actions">
                                <form method="POST" action="{{ route('admin.account-requests.update', $requestUser) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="approval_status" value="approved">
                                    <button class="btn btn-success" type="submit" @disabled($requestUser->approval_status === 'approved')>Approve</button>
                                </form>
                                <form method="POST" action="{{ route('admin.account-requests.update', $requestUser) }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="approval_status" value="rejected">
                                    <button class="btn btn-danger" type="submit" @disabled($requestUser->approval_status === 'rejected')>Reject</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr><td class="empty-cell" colspan="5">No account requests found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@endcomponent
