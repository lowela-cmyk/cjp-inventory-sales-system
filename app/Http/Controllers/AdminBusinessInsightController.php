<?php

namespace App\Http\Controllers;

use App\Services\BusinessInsightService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminBusinessInsightController extends Controller
{
    public function __invoke(Request $request, BusinessInsightService $insights): RedirectResponse
    {
        $filters = $this->validatedFilters($request);
        $result = $insights->generateForUser($request->user(), $this->aiFilters($filters));

        $redirect = redirect()->route('admin.reports', $filters);

        if ($result['ok']) {
            return $redirect->with('businessInsight', [
                'text' => $result['text'],
                'generated_at' => now()->format('M d, Y h:i A'),
            ]);
        }

        return $redirect->with('businessInsightNotice', $result['text']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedFilters(Request $request): array
    {
        $validated = $request->validate([
            'period' => ['nullable', Rule::in(['all', 'today', 'date', 'range', 'month', 'year'])],
            'date' => ['nullable', 'date'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'month' => ['nullable', 'date_format:Y-m'],
            'year' => ['nullable', 'integer', 'between:2000,2100'],
        ]);

        return [
            'period' => $validated['period'] ?? 'all',
            'date' => $validated['date'] ?? now()->toDateString(),
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
            'month' => $validated['month'] ?? now()->format('Y-m'),
            'year' => (string) ($validated['year'] ?? now()->year),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function aiFilters(array $filters): array
    {
        [$dateFrom, $dateTo] = match ($filters['period']) {
            'today' => [now()->toDateString(), now()->toDateString()],
            'date' => [$filters['date'], $filters['date']],
            'range' => [$filters['start_date'], $filters['end_date']],
            'month' => [
                CarbonImmutable::parse($filters['month'].'-01')->startOfMonth()->toDateString(),
                CarbonImmutable::parse($filters['month'].'-01')->endOfMonth()->toDateString(),
            ],
            'year' => [$filters['year'].'-01-01', $filters['year'].'-12-31'],
            default => [null, null],
        };

        $trendYear = (int) ($filters['period'] === 'month'
            ? CarbonImmutable::parse($filters['month'].'-01')->year
            : $filters['year']);

        return [
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'trend_period' => $filters['period'] === 'year' ? 'year' : 'month',
            'trend_year' => $trendYear,
            'expected_year' => $trendYear,
            'limit' => 3,
        ];
    }
}
