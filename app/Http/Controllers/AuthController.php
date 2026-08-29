<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route($this->dashboardRouteFor(Auth::user()->role));
        }

        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'role' => ['required', 'string', 'in:admin,inventory_officer,sales_officer,dispatch_officer,driver'],
            'password' => ['required', 'string'],
        ]);

        $attempt = Auth::attempt([
            'email' => $credentials['username'],
            'password' => $credentials['password'],
            'role' => $credentials['role'],
            'status' => 'active',
        ]);

        if (! $attempt) {
            throw ValidationException::withMessages([
                'username' => 'The provided credentials do not match an active account.',
            ]);
        }

        $request->session()->regenerate();

        $request->user()->forceFill([
            'last_login_at' => now(),
        ])->save();

        return redirect()->route($this->dashboardRouteFor($request->user()->role));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function dashboardRouteFor(string $role): string
    {
        return match ($role) {
            'admin' => 'admin.dashboard',
            'inventory_officer' => 'inventory-officer.inventory',
            'sales_officer' => 'sales-officer.sales',
            'dispatch_officer' => 'dispatch.fuel-lifting',
            'driver' => 'driver.fuel-lifting',
        };
    }
}
