<?php

namespace App\Http\Controllers;

use App\Services\DashboardSummaryService;
use App\Services\InventoryVarianceExplanationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminInventoryVarianceExplanationController extends Controller
{
    public function __invoke(Request $request, InventoryVarianceExplanationService $explanations): RedirectResponse
    {
        $filters = $this->validatedFilters($request);
        $result = $explanations->generateForUser($request->user(), $this->aiFilters($filters));

        $redirect = redirect()->route('admin.dashboard', $filters);

        if ($result['ok']) {
            return $redirect->with('inventoryVarianceExplanation', [
                'text' => $result['text'],
                'generated_at' => now()->format('M d, Y h:i A'),
            ]);
        }

        return $redirect->with('inventoryVarianceExplanationNotice', $result['text']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'variance_date_from' => ['nullable', 'date'],
            'variance_date_to' => ['nullable', 'date', 'after_or_equal:variance_date_from'],
            'variance_fuel_type_id' => ['nullable', 'integer', Rule::exists('fuel_types', 'id')],
            'variance_customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')],
            'variance_status' => ['nullable', Rule::in(DashboardSummaryService::INVENTORY_VARIANCE_STATUSES)],
        ]);

        return [
            'variance_date_from' => $validated['variance_date_from'] ?? null,
            'variance_date_to' => $validated['variance_date_to'] ?? null,
            'variance_fuel_type_id' => $validated['variance_fuel_type_id'] ?? null,
            'variance_customer_id' => $validated['variance_customer_id'] ?? null,
            'variance_status' => $validated['variance_status'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function aiFilters(array $filters): array
    {
        return [
            'date_from' => $filters['variance_date_from'],
            'date_to' => $filters['variance_date_to'],
            'variance_fuel_type_id' => $filters['variance_fuel_type_id'],
            'variance_customer_id' => $filters['variance_customer_id'],
            'variance_status' => $filters['variance_status'],
            'trend_period' => 'month',
            'trend_year' => now()->year,
            'expected_year' => now()->year,
            'limit' => 3,
        ];
    }
}
