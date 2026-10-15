<?php

namespace App\Http\Middleware;

use App\Services\ModuleActivityService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Opening a sidebar module (any GET of its pages) clears that module's red dot for the user.
 * Runs before the page renders so the sidebar of that same page is already cleared.
 */
class TrackModuleView
{
    public function __construct(private readonly ModuleActivityService $moduleActivity) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $module = $request->isMethod('GET') && $user ? $this->moduleActivity->moduleForRoute($request->route()?->getName()) : null;

        if ($module !== null) {
            try {
                $this->moduleActivity->markViewed($user, $module);
            } catch (Throwable $exception) {
                // A missing dot update must never block the page.
                report($exception);
            }
        }

        return $next($request);
    }
}
