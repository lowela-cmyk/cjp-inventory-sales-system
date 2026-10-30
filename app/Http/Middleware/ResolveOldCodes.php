<?php

namespace App\Http\Middleware;

use App\Services\RecordCodeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Part 15 #6 (October 30, 2026): a search box (or an old alert / bookmarked link with ?highlight=) given a Purchase,
 * Order or Lift ID from before the renumbering, or from before a fuel change, finds the record by its current ID. The
 * page says which ID it became.
 */
class ResolveOldCodes
{
    private const PARAMETERS = ['search', 'inv_search', 'sales_search', 'highlight'];

    public function __construct(private readonly RecordCodeService $codes) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && $request->user()) {
            try {
                $this->resolve($request);
            } catch (Throwable $exception) {
                // Searching must keep working even if the mapping can't be read.
                report($exception);
            }
        }

        return $next($request);
    }

    private function resolve(Request $request): void
    {
        $terms = array_filter(array_map(fn (string $name): ?string => is_string($request->query($name)) ? $request->query($name) : null, array_combine(self::PARAMETERS, self::PARAMETERS)));
        if ($terms === []) {
            return;
        }

        foreach ($terms as $name => $term) {
            $current = $this->codes->currentCodeFor($term);
            if ($current === null) {
                continue;
            }

            $request->query->set($name, $current);
            $request->merge([$name => $current]);
            if ($name !== 'highlight' && $request->hasSession()) {
                $request->session()->now('status', strtoupper(trim($term)).' is now '.$current.'.');
            }
        }
    }
}
