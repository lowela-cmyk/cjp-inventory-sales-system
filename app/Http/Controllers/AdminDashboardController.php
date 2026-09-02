<?php

namespace App\Http\Controllers;

use App\Services\AdminDashboardService;
use App\Services\DashboardSummaryService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function __invoke(Request $request, AdminDashboardService $dashboard): View
    {
        $filters = $request->validate([
            'trend_period' => ['nullable', Rule::in(DashboardSummaryService::SALES_TREND_PERIODS)],
            'trend_year' => ['nullable', 'integer', 'between:2000,2100'],
            'expected_year' => ['nullable', 'integer', 'between:2000,2100'],
            'unlifted_date_from' => ['nullable', 'date'],
            'unlifted_date_to' => ['nullable', 'date', 'after_or_equal:unlifted_date_from'],
            'unlifted_depot_id' => ['nullable', 'integer', Rule::exists('depots', 'id')],
            'unlifted_fuel_type_id' => ['nullable', 'integer', Rule::exists('fuel_types', 'id')],
            'unlifted_lifting_status' => ['nullable', Rule::in(DashboardSummaryService::LIFTING_PROGRESS_STATUSES)],
        ]);

        return view('admin.dashboard', $dashboard->data($filters));
    }
}
