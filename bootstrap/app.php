<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\RecordActivity;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrackModuleView;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Only proxies listed in TRUSTED_PROXIES (comma-separated IPs/CIDRs, or "*" behind a managed load
        // balancer) may set X-Forwarded-For. With none listed, a client-supplied header can't change the
        // client IP, so it can't be used to dodge the login and password-reset rate limits (audit H01/C02).
        $trustedProxies = array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))));
        if ($trustedProxies !== []) {
            $middleware->trustProxies(at: $trustedProxies === ['*'] ? '*' : $trustedProxies);
        }
        $middleware->append(SecurityHeaders::class);
        $middleware->web(append: [RecordActivity::class, TrackModuleView::class]);
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
