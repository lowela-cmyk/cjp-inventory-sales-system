<?php

namespace App\Providers;

use App\Services\ModuleActivityService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One instance per request so every sidebar link shares the same red-dot lookup.
        $this->app->scoped(ModuleActivityService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Each limiter layers a per-account limit (any IP), a per-account+IP limit and a per-IP cap,
        // so neither rotating IPs nor rotating accounts gives unlimited guesses (audit H01/C02).
        RateLimiter::for('login', function (Request $request): array {
            $username = Str::lower(trim((string) $request->input('username', '')));

            return $this->tooManyAttempts([
                Limit::perMinute(5)->by('login:'.$username.'|'.$request->ip()),
                Limit::perMinutes(15, 10)->by('login-account:'.$username),
                Limit::perMinute(30)->by('login-ip:'.$request->ip()),
            ]);
        });

        RateLimiter::for('registration', function (Request $request): Limit {
            $email = Str::lower((string) $request->input('email', ''));

            return Limit::perMinute(3)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('password-reset', function (Request $request): array {
            $email = Str::lower(trim((string) $request->input('email', '')));

            return $this->tooManyAttempts([
                Limit::perMinute(5)->by('reset:'.$email.'|'.$request->ip()),
                Limit::perMinutes(15, 10)->by('reset-account:'.$email),
                Limit::perMinute(20)->by('reset-ip:'.$request->ip()),
            ]);
        });
    }

    /**
     * A throttled auth request re-renders its own form (status 429) with a plain-language wait time.
     *
     * @param  array<int, Limit>  $limits
     * @return array<int, Limit>
     */
    private function tooManyAttempts(array $limits): array
    {
        $response = function (Request $request, array $headers) {
            $minutes = max(1, (int) ceil(((int) ($headers['Retry-After'] ?? 60)) / 60));
            $message = 'Too many attempts. Please try again in '.$minutes.' '.Str::plural('minute', $minutes).'.';
            $view = match ($request->route()?->getName()) {
                'password.email' => 'auth.forgot-password',
                'password.update' => 'auth.reset-password',
                default => 'auth.login',
            };
            $request->session()->flashInput($request->only('username', 'email'));

            return response(view($view)->withErrors(['throttle' => $message]), 429, $headers);
        };

        return array_map(fn (Limit $limit): Limit => $limit->response($response), $limits);
    }
}
