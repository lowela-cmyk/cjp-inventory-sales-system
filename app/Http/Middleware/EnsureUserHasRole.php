<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        $currentUser = $user?->fresh();

        if (! $currentUser || $currentUser->status !== 'active' || $currentUser->approval_status !== 'approved') {
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            abort(403);
        }

        // Admin may perform every role's functions; all other roles stay limited to their own routes.
        if ($currentUser->role !== 'admin' && ! in_array($currentUser->role, $roles, true)) {
            abort(403);
        }

        Auth::setUser($currentUser);

        return $next($request);
    }
}
