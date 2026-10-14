<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Records every state-changing request made by a signed-in user (who, which action, which records,
 * outcome). Admin may act on behalf of every role, so this keeps a trail of who really did what.
 * Request bodies are never stored, so passwords and personal data stay out of the log.
 */
class RecordActivity
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->isMethodSafe() || ! $request->user()) {
            return $response;
        }

        try {
            $hasErrors = $request->hasSession() && $request->session()->has('errors');

            DB::table('activity_logs')->insert([
                'user_id' => $request->user()->getKey(),
                'user_role' => $request->user()->role,
                'route_name' => $request->route()?->getName(),
                'method' => $request->method(),
                'path' => mb_substr('/'.ltrim($request->path(), '/'), 0, 255),
                'route_parameters' => json_encode($this->routeParameters($request)),
                'status_code' => $response->getStatusCode(),
                'succeeded' => $response->getStatusCode() < 400 && ! $hasErrors,
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        } catch (Throwable $exception) {
            // Auditing must never break the action the user just performed.
            report($exception);
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function routeParameters(Request $request): array
    {
        return collect($request->route()?->parameters() ?? [])
            ->map(fn (mixed $value): mixed => $value instanceof Model ? $value->getKey() : $value)
            ->all();
    }
}
