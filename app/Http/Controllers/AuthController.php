<?php

namespace App\Http\Controllers;

use App\Mail\PasswordResetCodeMail;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AuthController extends Controller
{
    /** Wrong codes allowed before a password-reset code is cancelled (audit C02). */
    private const MAX_RESET_CODE_ATTEMPTS = 5;

    /** Part 21 #3: failed logins allowed for one username from one IP inside the window, before the lockout. */
    public const MAX_LOGIN_ATTEMPTS = 5;

    public const LOGIN_ATTEMPT_WINDOW_SECONDS = 60;

    public const LOGIN_LOCKOUT_SECONDS = 900;

    /** Failed logins allowed for one username from ANY IP per lockout period (rotating IPs, audit H01). */
    public const MAX_ACCOUNT_LOGIN_ATTEMPTS = 10;

    /** Checked when the username matches no account, so a miss takes as long as a wrong password (bcrypt of a random string). */
    private const DUMMY_PASSWORD_HASH = '$2y$12$Vn0p4nH8T6vQdLq1k2uYNe0jSxD9o9l0b6r5m8F1mVq3nW7c2yE5a';

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

    public function redirectToDashboard(Request $request): RedirectResponse
    {
        $user = $request->user()?->fresh();

        abort_unless($user && $user->status === 'active' && $user->approval_status === 'approved', 403);

        Auth::setUser($user);

        return redirect()->route($this->dashboardRouteFor($user->role));
    }

    public function sendPasswordResetCode(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $user = User::where('email', $data['email'])
            ->where('status', 'active')
            ->where('approval_status', 'approved')
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
            Cache::forget($this->resetAttemptsKey($user->email));

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

    /**
     * Part 21 #3: 5 failed logins within 1 minute lock the username (from that IP) for 15 minutes; the 4th failure warns
     * that one attempt is left. Counted server-side with Laravel's RateLimiter, by the submitted username and never by
     * whether an account exists, so the warning and the lockout look the same for a real and a made-up username. A
     * second, IP-independent counter stops guessing one account from many IPs. A successful login clears the counters;
     * otherwise they expire on their own.
     */
    public function login(Request $request): RedirectResponse|Response
    {
        $credentials = $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $identifier = trim($credentials['username']);
        $keys = $this->loginThrottleKeys($identifier, (string) $request->ip());

        // A locked username is refused before its password is looked at, right or wrong.
        if ($seconds = $this->loginLockedFor($keys)) {
            return $this->lockedOutResponse($request, $seconds);
        }

        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            $user = User::query()->where('email', $identifier)->first();
        } else {
            $matchingUsers = User::query()->where('name', $identifier)->get();

            if ($matchingUsers->count() > 1) {
                return $this->failedLogin($request, $keys, 'Multiple accounts exist with this name. Please log in using your unique email address.');
            }

            $user = $matchingUsers->first();
        }

        $passwordMatches = Hash::check($credentials['password'], $user ? $user->password : self::DUMMY_PASSWORD_HASH);

        if (! $user || ! $passwordMatches || $user->status !== 'active') {
            return $this->failedLogin($request, $keys, 'The provided credentials do not match an active account.');
        }

        // The password was right, so these are not guesses and do not count as failed attempts.
        if ($user->approval_status === 'pending') {
            throw ValidationException::withMessages([
                'username' => 'Your account is still pending Admin approval.',
            ]);
        }

        if ($user->approval_status === 'rejected') {
            throw ValidationException::withMessages([
                'username' => 'Your account registration was rejected by the Administrator.',
            ]);
        }

        RateLimiter::clear($keys['attempts']);
        RateLimiter::clear($keys['account_attempts']);

        Auth::login($user);

        $request->session()->regenerate();

        // A login is not a change to the account: keep updated_at so other users get no red dot for it.
        $user = $request->user();
        $user->timestamps = false;
        $user->forceFill(['last_login_at' => now()])->save();
        $user->timestamps = true;

        return redirect()
            ->route($this->dashboardRouteFor($request->user()->role))
            // Toast-only flash: the LOGIN SUCCESS toast shows it, inline page alerts (session('status')) do not.
            ->with('toast_message', 'Welcome back to CJP Southern Star. Your dashboard is ready.')
            ->with('toast_title', 'Login success')
            ->with('toast_type', 'success');
    }

    /**
     * @return array{attempts: string, lock: string, account_attempts: string, account_lock: string}
     */
    private function loginThrottleKeys(string $identifier, string $ip): array
    {
        $account = sha1(Str::lower($identifier));

        return [
            'attempts' => 'login-attempts:'.$account.'|'.$ip,
            'lock' => 'login-lock:'.$account.'|'.$ip,
            'account_attempts' => 'login-account-attempts:'.$account,
            'account_lock' => 'login-account-lock:'.$account,
        ];
    }

    /**
     * Seconds left on the lockout of this username (0 when it is not locked). An expired lock clears itself.
     *
     * @param  array{attempts: string, lock: string, account_attempts: string, account_lock: string}  $keys
     */
    private function loginLockedFor(array $keys): int
    {
        $seconds = 0;
        foreach ([$keys['lock'], $keys['account_lock']] as $lockKey) {
            if (RateLimiter::tooManyAttempts($lockKey, 1)) {
                $seconds = max($seconds, RateLimiter::availableIn($lockKey));
            }
        }

        return $seconds;
    }

    /**
     * Counts a failed login and answers it: the plain error, the error with the last-attempt warning, or the lockout.
     *
     * @param  array{attempts: string, lock: string, account_attempts: string, account_lock: string}  $keys
     *
     * @throws ValidationException
     */
    private function failedLogin(Request $request, array $keys, string $message): Response
    {
        $attempts = RateLimiter::hit($keys['attempts'], self::LOGIN_ATTEMPT_WINDOW_SECONDS);
        $accountAttempts = RateLimiter::hit($keys['account_attempts'], self::LOGIN_LOCKOUT_SECONDS);

        if ($attempts >= self::MAX_LOGIN_ATTEMPTS) {
            RateLimiter::clear($keys['attempts']);
            RateLimiter::hit($keys['lock'], self::LOGIN_LOCKOUT_SECONDS);
        }
        if ($accountAttempts >= self::MAX_ACCOUNT_LOGIN_ATTEMPTS) {
            RateLimiter::clear($keys['account_attempts']);
            RateLimiter::hit($keys['account_lock'], self::LOGIN_LOCKOUT_SECONDS);
        }
        if ($seconds = $this->loginLockedFor($keys)) {
            return $this->lockedOutResponse($request, $seconds);
        }

        if ($attempts === self::MAX_LOGIN_ATTEMPTS - 1) {
            $request->session()->flash('login_warning', 'Warning: You have 1 login attempt remaining.');
        }

        throw ValidationException::withMessages(['username' => $message]);
    }

    /** The login form again (429) with the time left; app.js counts it down. */
    private function lockedOutResponse(Request $request, int $seconds): Response
    {
        $request->session()->flashInput($request->only('username'));

        return response(
            view('auth.login', ['lockoutSeconds' => $seconds])->withErrors([
                'throttle' => 'Too many failed login attempts. Please try again in '.self::waitLabel($seconds).'.',
            ]),
            429,
            ['Retry-After' => $seconds]
        );
    }

    /** "15 minutes", "1 minute", "42 seconds": whole minutes are rounded up so the wait is never understated. */
    public static function waitLabel(int $seconds): string
    {
        if ($seconds < 60) {
            return max(1, $seconds).' '.Str::plural('second', max(1, $seconds));
        }
        $minutes = (int) ceil($seconds / 60);

        return $minutes.' '.Str::plural('minute', $minutes);
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'contact_number' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\\-\\s]+$/'],
            'role' => ['nullable', 'string', 'max:50'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        if (isset($data['role']) && strtolower($data['role']) === 'admin') {
            throw ValidationException::withMessages([
                'role' => 'The Administrator role cannot be requested through public registration.',
            ]);
        }

        $allowedRoles = ['inventory_officer', 'sales_officer', 'dispatch_officer', 'driver'];
        $requestedRole = in_array($data['role'] ?? null, $allowedRoles, true) ? $data['role'] : 'driver';

        User::create([
            'name' => $data['full_name'],
            'email' => $data['email'],
            'phone' => $data['contact_number'] ?? null,
            'role' => $requestedRole,
            'status' => 'active',
            'approval_status' => 'pending',
            'password' => $data['password'],
        ]);

        return redirect()
            ->route('login')
            ->with('status', 'Your registration is pending Admin approval.')
            ->with('toast_type', 'warning');
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
            ->where('approval_status', 'approved')
            ->first();

        $codeIsExpired = ! $reset || Carbon::parse($reset->created_at)->addMinutes(15)->isPast();

        if (! $user || $codeIsExpired || ! Hash::check($data['code'], $reset->token)) {
            // A code allows a few wrong tries; then it is cancelled and a new one must be requested (audit C02).
            if ($reset && ! $codeIsExpired) {
                $attemptsKey = $this->resetAttemptsKey($data['email']);
                $attempts = Cache::increment($attemptsKey);
                Cache::put($attemptsKey, $attempts, now()->addMinutes(15));

                if ($attempts >= self::MAX_RESET_CODE_ATTEMPTS) {
                    DB::table('password_reset_tokens')->where('email', $reset->email)->delete();
                    Cache::forget($attemptsKey);

                    throw ValidationException::withMessages([
                        'code' => 'Too many wrong codes. This reset code has been cancelled; please request a new one.',
                    ]);
                }
            }

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
        Cache::forget($this->resetAttemptsKey($user->email));

        return redirect()
            ->route('login')
            ->with('status', 'Password updated successfully. Please log in with your new password.')
            ->with('toast_type', 'success');
    }

    private function resetAttemptsKey(string $email): string
    {
        return 'password-reset-attempts:'.sha1(Str::lower(trim($email)));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->with('status', 'You have been signed out of CJP Southern Star.')
            ->with('toast_type', 'success')
            ->with('toast_title', 'Signed out')
            ->with('toast_context', 'logout');
    }

    /**
     * @param  'admin'|'inventory_officer'|'sales_officer'|'dispatch_officer'|'driver'  $role  users.role is this enum
     */
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
