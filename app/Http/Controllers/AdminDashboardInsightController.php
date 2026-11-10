<?php

namespace App\Http\Controllers;

use App\Services\DashboardInsightService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Part 10: "Generate Insight and Reports" on the dashboard, for the dashboard's current filters.
 * Part 20 #4: the result is the overview sections and the grouped bullet recommendations (see DashboardInsightService).
 */
class AdminDashboardInsightController extends Controller
{
    public function __invoke(Request $request, DashboardInsightService $insights): RedirectResponse
    {
        $filters = AdminDashboardController::validatedFilters($request);
        $target = route('admin.dashboard', array_filter($filters, fn (mixed $value): bool => $value !== null && $value !== '')).'#insights';

        try {
            $result = $insights->generate(AdminDashboardController::inventoryFilters($filters), AdminDashboardController::salesFilters($filters));
        } catch (\Throwable $exception) {
            // Error state: the dashboard says so; the details go to the server log only.
            Log::error('Dashboard insight failed: '.$exception->getMessage(), ['exception' => $exception]);

            return redirect()->to($target)->withErrors(['insight' => 'The insight report could not be generated. Please try again.']);
        }

        return redirect()
            ->to($target)
            ->with('dashboardInsight', $result + ['generated_at' => now()->format('M d, Y h:i A')]);
    }
}
