@php
    $accountId = fn ($user) => strtoupper(substr((string) $user->role, 0, 3)).'-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT);
@endphp

@component('layouts.admin', ['title' => 'Account Requests', 'active' => 'account-requests'])
    <h2 class="section-title">Account Requests</h2>



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
                    {{-- Part 13: rows are plain white; the status pill shows Approved / Rejected / Pending. --}}
                    <tr>
                        <td>
                            <strong>{{ $requestUser->name }}</strong>
                            <span>{{ $requestUser->email }}</span>
                        </td>
                        <td>{{ $requestUser->role_label }}</td>
                        <td>{{ $requestUser->created_at?->format('M d, Y h:i A') ?? 'N/A' }}</td>
                        <td><x-admin.status-badge :status="ucfirst($requestUser->approval_status)" /></td>
                        <td>
                            {{-- Part 12: only a pending request can be decided; a decided row shows just its status. --}}
                            @if ($requestUser->approval_status === 'pending')
                                <div class="account-request-actions">
                                    <form method="POST" action="{{ route('admin.account-requests.update', $requestUser) }}">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="approval_status" value="approved">
                                        <button class="btn btn-success" type="submit">Approve</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.account-requests.update', $requestUser) }}" data-confirm-message="Reject the account request of {{ $requestUser->name }}? They will not be able to sign in.">
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
@endcomponent
