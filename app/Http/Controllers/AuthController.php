<?php

namespace App\Http\Controllers;

use App\Mail\PasswordResetCodeMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class AuthController extends Controller
{
    public function showLogin(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route($this->dashboardRouteFor(Auth::user()->role));
        }

        return view('auth.login');
    }

    public function showRegister(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route($this->dashboardRouteFor(Auth::user()->role));
        }

        return view('auth.register');
    }

    public function showForgotPassword(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route($this->dashboardRouteFor(Auth::user()->role));
        }

        return view('auth.forgot-password');
    }

    public function sendPasswordResetCode(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $user = User::where('email', $data['email'])
            ->where('status', 'active')
            ->first();

        if ($user) {
            $code = (string) random_int(100000, 999999);

            DB::table('password_reset_tokens')->updateOrInsert(
                ['email' => $user->email],
                [
                    'token' => Hash::make($code),
                    'created_at' => now(),
                ]
            );

            try {
                Mail::to($user->email)->send(new PasswordResetCodeMail($user, $code));
            } catch (Throwable $exception) {
                report($exception);

                DB::table('password_reset_tokens')
                    ->where('email', $user->email)
                    ->delete();

                throw ValidationException::withMessages([
                    'email' => 'The reset code could not be sent because Gmail did not accept the sender email settings. Please check the Gmail App Password.',
                ]);
            }
        }

        return redirect()
            ->route('password.reset')
            ->withInput(['email' => $data['email']])
            ->with('status', 'If the email matches an active account, a reset code has been sent.');
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

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'contact_number' => ['nullable', 'string', 'max:30'],
            'role' => ['required', 'string', 'in:admin,inventory_officer,sales_officer,dispatch_officer,driver'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        User::create([
            'name' => $data['full_name'],
            'email' => $data['email'],
            'phone' => $data['contact_number'] ?? null,
            'role' => $data['role'],
            'status' => 'active',
            'password' => $data['password'],
        ]);

        return redirect()
            ->route('login')
            ->with('status', 'Account created successfully. Please log in to continue.');
    }

    public function showResetPassword(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route($this->dashboardRouteFor(Auth::user()->role));
        }

        return view('auth.reset-password');
    }

    public function resetPassword(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'code' => ['required', 'digits:6'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $reset = DB::table('password_reset_tokens')
            ->where('email', $data['email'])
            ->first();

        $user = User::where('email', $data['email'])
            ->where('status', 'active')
            ->first();

        $codeIsExpired = ! $reset || Carbon::parse($reset->created_at)->addMinutes(15)->isPast();

        if (! $user || $codeIsExpired || ! Hash::check($data['code'], $reset->token)) {
            throw ValidationException::withMessages([
                'code' => 'The reset code is invalid or has expired.',
            ]);
        }

        $user->forceFill([
            'password' => $data['password'],
            'remember_token' => null,
        ])->save();

        DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->delete();

        return redirect()
            ->route('login')
            ->with('status', 'Password updated successfully. Please log in with your new password.');
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
