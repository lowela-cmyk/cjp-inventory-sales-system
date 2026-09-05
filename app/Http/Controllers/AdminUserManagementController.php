<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AdminUserManagementController extends Controller
{
    private const ROLES = [
        'admin' => 'Admin',
        'inventory_officer' => 'Inventory Officer',
        'sales_officer' => 'Sales Officer',
        'dispatch_officer' => 'Dispatch Officer',
        'driver' => 'Driver',
    ];

    private const OFFICE_ROLES = [
        'admin' => 'Admin',
        'inventory_officer' => 'Inventory Officer',
        'sales_officer' => 'Sales Officer',
        'dispatch_officer' => 'Dispatch Officer',
    ];

    private const STATUSES = ['active', 'inactive'];
    private const APPROVAL_STATUSES = ['pending', 'approved', 'rejected'];

    public function index(Request $request): View
    {
        return view('admin.user-management', [
            'staff' => $this->staffRows(),
            'drivers' => $this->driverRows(),
            'customers' => $this->customerRows(),
            'officeRoles' => self::OFFICE_ROLES,
            'roles' => self::ROLES,
            'statuses' => self::STATUSES,
            'activeTab' => $request->query('tab', session('user_management_tab', 'office')),
        ]);
    }

    public function export(Request $request): Response
    {
        $data = $request->validate([
            'tab' => ['nullable', Rule::in(['office', 'drivers', 'customers'])],
        ]);

        $tab = $data['tab'] ?? 'office';
        $filename = 'user-management-'.$tab.'-'.now()->format('Ymd-His').'.csv';
        $handle = fopen('php://temp', 'r+');

        fputcsv($handle, ['CJP Southern Star OPC User Management']);
        fputcsv($handle, ['Section', ucfirst($tab)]);
        fputcsv($handle, ['Generated At', now()->format('M d, Y h:i A')]);
        fputcsv($handle, []);

        if ($tab === 'drivers') {
            fputcsv($handle, ['Driver ID', 'Name', 'License No.', 'Account Status', 'Approval Status', 'Email', 'Contact Number']);

            foreach ($this->driverRows() as $row) {
                fputcsv($handle, [
                    $this->driverCodeFor($row),
                    $row->name,
                    $row->license_number ?: 'N/A',
                    ucfirst($row->status),
                    ucfirst($row->approval_status),
                    $row->email,
                    $row->phone ?: 'N/A',
                ]);
            }
        } elseif ($tab === 'customers') {
            fputcsv($handle, ['Customer ID', 'Customer Name', 'Company Name', 'Location', 'Email', 'Contact Number']);

            foreach ($this->customerRows() as $row) {
                fputcsv($handle, [
                    'CSM-'.str_pad((string) $row->id, 6, '0', STR_PAD_LEFT),
                    $row->name,
                    $row->company_name,
                    $row->location ?: 'N/A',
                    $row->email ?: 'N/A',
                    $row->phone ?: 'N/A',
                ]);
            }
        } else {
            fputcsv($handle, ['Staff ID', 'Name', 'Position', 'Account Status', 'Approval Status', 'Email', 'Contact Number']);

            foreach ($this->staffRows() as $row) {
                fputcsv($handle, [
                    $this->staffCodeFor($row),
                    $row->name,
                    $row->role_label,
                    ucfirst($row->status),
                    ucfirst($row->approval_status),
                    $row->email,
                    $row->phone ?: 'N/A',
                ]);
            }
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function storeStaff(Request $request): RedirectResponse
    {
        $data = $request->validate($this->accountRules(
            roleRule: Rule::in(array_keys(self::OFFICE_ROLES)),
            passwordRules: ['required', 'confirmed', Password::defaults()],
        ));

        User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'role' => $data['role'],
            'status' => $data['status'],
            'approval_status' => 'approved',
            'password' => $data['password'],
        ]);

        return $this->backToTab('office', 'Office staff account created successfully.');
    }

    public function storeDriver(Request $request): RedirectResponse
    {
        $data = $request->validate($this->accountRules(
            roleRule: Rule::in(['driver']),
            passwordRules: ['required', 'confirmed', Password::defaults()],
            extraRules: [
                'license_number' => ['nullable', 'string', 'max:100'],
            ],
        ));

        DB::transaction(function () use ($data): void {
            $driver = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'role' => 'driver',
                'status' => $data['status'],
                'approval_status' => 'approved',
                'password' => $data['password'],
            ]);

            DB::table('driver_profiles')->insert([
                'user_id' => $driver->id,
                'driver_code' => $this->driverCodeFor($driver),
                'license_number' => $data['license_number'] ?? null,
                'status' => $data['status'] === 'active' ? 'available' : 'inactive',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return $this->backToTab('drivers', 'Driver account created successfully.');
    }

    public function updateStaff(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->role !== 'driver', 404);

        $data = $request->validate($this->accountRules(
            roleRule: Rule::in(array_keys(self::ROLES)),
            passwordRules: ['nullable', 'confirmed', Password::defaults()],
            user: $user,
        ));

        if ($this->removesFinalActiveAdmin($user, $data['role'], $data['status'])) {
            return $this->finalAdminError();
        }

        DB::transaction(function () use ($user, $data): void {
            $this->updateUser($user, $data);
            $this->syncDriverProfileForRole($user, $data);
        });

        return $this->backToTab($data['role'] === 'driver' ? 'drivers' : 'office', 'Account role updated successfully.');
    }

    public function updateDriver(Request $request, User $user): RedirectResponse
    {
        abort_unless($user->role === 'driver', 404);

        $data = $request->validate($this->accountRules(
            roleRule: Rule::in(array_keys(self::ROLES)),
            passwordRules: ['nullable', 'confirmed', Password::defaults()],
            user: $user,
            extraRules: [
                'license_number' => ['nullable', 'string', 'max:100'],
            ],
        ));

        if ($this->removesFinalActiveAdmin($user, $data['role'], $data['status'])) {
            return $this->finalAdminError();
        }

        DB::transaction(function () use ($user, $data): void {
            $this->updateUser($user, $data);
            $this->syncDriverProfileForRole($user, $data);
        });

        return $this->backToTab($data['role'] === 'driver' ? 'drivers' : 'office', 'Account role updated successfully.');
    }

    public function updateStatus(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(self::STATUSES)],
            'tab' => ['nullable', Rule::in(['office', 'drivers'])],
        ]);

        if ($this->removesFinalActiveAdmin($user, $user->role, $data['status'])) {
            return $this->finalAdminError();
        }

        if ($request->user()->is($user) && $data['status'] === 'inactive') {
            return back()
                ->withErrors(['status' => 'You cannot deactivate your own active admin session.'])
                ->withInput();
        }

        DB::transaction(function () use ($user, $data): void {
            $user->forceFill(['status' => $data['status']])->save();

            if ($user->role === 'driver') {
                DB::table('driver_profiles')
                    ->where('user_id', $user->id)
                    ->update([
                        'status' => $data['status'] === 'active' ? 'available' : 'inactive',
                        'updated_at' => now(),
                    ]);
            }
        });

        return $this->backToTab($data['tab'] ?? ($user->role === 'driver' ? 'drivers' : 'office'), 'Account status updated successfully.');
    }

    public function accountRequests(): View
    {
        $requests = User::query()
            ->whereIn('approval_status', self::APPROVAL_STATUSES)
            ->orderByRaw("CASE approval_status WHEN 'pending' THEN 0 WHEN 'rejected' THEN 1 ELSE 2 END")
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return view('admin.account-requests', [
            'requests' => $requests,
            'approvalStatuses' => self::APPROVAL_STATUSES,
        ]);
    }

    public function updateApproval(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'approval_status' => ['required', Rule::in(['approved', 'rejected'])],
        ]);

        if ($data['approval_status'] === 'rejected' && $this->removesFinalActiveAdmin($user, $user->role, $user->status, 'rejected')) {
            return $this->finalAdminError();
        }

        $user->forceFill([
            'approval_status' => $data['approval_status'],
        ])->save();

        $message = $data['approval_status'] === 'approved'
            ? 'Account approved. The user can now enter the CJP dashboard.'
            : 'Account request rejected. The user remains blocked from CJP dashboards.';

        return redirect()
            ->route('admin.account-requests')
            ->with('status', $message)
            ->with('toast_type', $data['approval_status'] === 'approved' ? 'success' : 'warning');
    }

    /**
     * @param array<int, mixed> $passwordRules
     * @param array<string, array<int, mixed>> $extraRules
     * @return array<string, array<int, mixed>>
     */
    private function accountRules(mixed $roleRule, array $passwordRules, ?User $user = null, array $extraRules = []): array
    {
        return array_merge([
            'name' => ['required', 'string', 'max:255', Rule::unique('users', 'name')->ignore($user?->id)],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\\-\\s]+$/'],
            'role' => ['required', 'string', $roleRule],
            'status' => ['required', Rule::in(self::STATUSES)],
            'password' => $passwordRules,
        ], $extraRules);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function updateUser(User $user, array $data): void
    {
        $payload = [
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'role' => $data['role'],
            'status' => $data['status'],
        ];

        if (! empty($data['password'])) {
            $payload['password'] = $data['password'];
        }

        $user->forceFill($payload)->save();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function syncDriverProfileForRole(User $user, array $data): void
    {
        $existingProfile = DB::table('driver_profiles')
            ->where('user_id', $user->id)
            ->first();

        if ($data['role'] !== 'driver') {
            if ($existingProfile) {
                DB::table('driver_profiles')
                    ->where('user_id', $user->id)
                    ->update([
                        'status' => 'inactive',
                        'updated_at' => now(),
                    ]);
            }

            return;
        }

        $driverProfileStatus = $data['status'] === 'inactive'
            ? 'inactive'
            : ($existingProfile?->status === 'assigned' ? 'assigned' : 'available');

        if ($existingProfile) {
            DB::table('driver_profiles')
                ->where('user_id', $user->id)
                ->update([
                    'license_number' => $data['license_number'] ?? $existingProfile->license_number,
                    'status' => $driverProfileStatus,
                    'updated_at' => now(),
                ]);

            return;
        }

        DB::table('driver_profiles')->insert([
            'user_id' => $user->id,
            'driver_code' => $this->driverCodeFor($user),
            'license_number' => $data['license_number'] ?? null,
            'status' => $driverProfileStatus,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function removesFinalActiveAdmin(User $user, string $newRole, string $newStatus, ?string $newApprovalStatus = null): bool
    {
        if ($user->role !== 'admin' || $user->status !== 'active' || $user->approval_status !== 'approved') {
            return false;
        }

        if ($newRole === 'admin' && $newStatus === 'active' && ($newApprovalStatus ?? $user->approval_status) === 'approved') {
            return false;
        }

        return ! User::query()
            ->where('role', 'admin')
            ->where('status', 'active')
            ->where('approval_status', 'approved')
            ->whereKeyNot($user->id)
            ->exists();
    }

    private function finalAdminError(): RedirectResponse
    {
        return back()
            ->withErrors(['role' => 'At least one active Admin account must remain.'])
            ->withInput();
    }

    private function driverCodeFor(User $user): string
    {
        return $user->driver_code ?: 'DRV-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT);
    }

    private function staffCodeFor(User $user): string
    {
        return 'EMP-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT);
    }

    private function backToTab(string $tab, string $message): RedirectResponse
    {
        return redirect()
            ->route('admin.user-management', ['tab' => $tab])
            ->with('user_management_tab', $tab)
            ->with('status', $message)
            ->with('toast_type', 'success');
    }

    private function staffRows()
    {
        return User::query()
            ->whereIn('role', array_keys(self::OFFICE_ROLES))
            ->orderBy('id')
            ->get();
    }

    private function driverRows()
    {
        return User::query()
            ->leftJoin('driver_profiles', 'driver_profiles.user_id', '=', 'users.id')
            ->where('users.role', 'driver')
            ->orderBy('users.id')
            ->get([
                'users.*',
                'driver_profiles.driver_code',
                'driver_profiles.license_number',
            ]);
    }

    private function customerRows()
    {
        return DB::table('customers')
            ->orderBy('id')
            ->get();
    }
}
