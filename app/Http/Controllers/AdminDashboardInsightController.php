<?php

namespace App\Http\Controllers;

use App\Services\DashboardInsightService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Part 10: "Generate Insight and Reports" on the dashboard, for the dashboard's current filters.
 */
class AdminDashboardInsightController extends Controller
{
    public function __invoke(Request $request, DashboardInsightService $insights): RedirectResponse
    {
        $filters = AdminDashboardController::validatedFilters($request);
        $result = $insights->generate(AdminDashboardController::inventoryFilters($filters), AdminDashboardController::salesFilters($filters));

        return redirect()
            ->to(route('admin.dashboard', array_filter($filters, fn (mixed $value): bool => $value !== null && $value !== '')).'#insights')
            ->with('dashboardInsight', $result + ['generated_at' => now()->format('M d, Y h:i A')]);
    }
}
