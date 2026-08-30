<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function index(Request $request): View
    {
        $staff = User::query()
            ->whereIn('role', array_keys(self::OFFICE_ROLES))
            ->orderBy('id')
            ->get();

        $drivers = User::query()
            ->leftJoin('driver_profiles', 'driver_profiles.user_id', '=', 'users.id')
            ->where('users.role', 'driver')
            ->orderBy('users.id')
            ->get([
                'users.*',
                'driver_profiles.driver_code',
                'driver_profiles.license_number',
            ]);

        $customers = DB::table('customers')
            ->orderBy('id')
            ->get();

        return view('admin.user-management', [
            'staff' => $staff,
            'drivers' => $drivers,
            'customers' => $customers,
            'officeRoles' => self::OFFICE_ROLES,
            'roles' => self::ROLES,
            'statuses' => self::STATUSES,
            'activeTab' => $request->query('tab', session('user_management_tab', 'office')),
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

    private function removesFinalActiveAdmin(User $user, string $newRole, string $newStatus): bool
    {
        if ($user->role !== 'admin' || $user->status !== 'active') {
            return false;
        }

        if ($newRole === 'admin' && $newStatus === 'active') {
            return false;
        }

        return ! User::query()
            ->where('role', 'admin')
            ->where('status', 'active')
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
        return 'DRV-'.str_pad((string) $user->id, 6, '0', STR_PAD_LEFT);
    }

    private function backToTab(string $tab, string $message): RedirectResponse
    {
        return redirect()
            ->route('admin.user-management', ['tab' => $tab])
            ->with('user_management_tab', $tab)
            ->with('status', $message);
    }
}
