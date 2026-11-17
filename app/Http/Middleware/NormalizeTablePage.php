<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** After deletions, return an out-of-range page to its last remaining page. */
class NormalizeTablePage
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if ($request->isMethod('GET') && method_exists($response, 'getOriginalContent') && $response->getOriginalContent() instanceof View) {
            foreach ($response->getOriginalContent()->getData() as $value) {
                if ($value instanceof LengthAwarePaginator && $request->has($value->getPageName()) && $value->currentPage() > $value->lastPage()) {
                    return redirect()->to($request->fullUrlWithQuery([$value->getPageName() => $value->lastPage()]));
                }
            }
        }

        return $response;
    }
}
