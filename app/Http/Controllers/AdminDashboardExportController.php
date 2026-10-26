<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Part 11: Export the dashboard as one PDF report: its charts (captured in the browser with Chart.js toBase64Image()),
 * the KPI cards, the AI insight shown on the page (or a note when none was generated yet), the date generated and the
 * filters applied. The KPI figures are recomputed here from the same filters, so the report never trusts the page for
 * numbers; only the chart pictures and the insight text come from the browser.
 */
class AdminDashboardExportController extends Controller
{
    private const MAX_CHARTS = 20;

    private const MAX_IMAGE_BYTES = 3 * 1024 * 1024;

    public function __invoke(Request $request, DashboardService $dashboard): Response
    {
        $filters = AdminDashboardController::validatedFilters($request);
        $data = $request->validate([
            'charts' => ['nullable', 'array', 'max:'.self::MAX_CHARTS],
            'charts.*.title' => ['required', 'string', 'max:120'],
            'charts.*.image' => ['required', 'string', 'starts_with:data:image/png;base64,', 'max:'.(int) ceil(self::MAX_IMAGE_BYTES * 4 / 3 + 32)],
            'insight_text' => ['nullable', 'string', 'max:20000'],
            'insight_note' => ['nullable', 'string', 'max:2000'],
            'insight_source' => ['nullable', 'string', 'max:500'],
            'insight_generated_at' => ['nullable', 'string', 'max:60'],
        ]);

        $charts = [];
        foreach ($data['charts'] ?? [] as $index => $chart) {
            $binary = base64_decode(substr($chart['image'], strlen('data:image/png;base64,')), true);
            // Only real PNG pictures reach the PDF renderer.
            if ($binary === false || strlen($binary) > self::MAX_IMAGE_BYTES || ! str_starts_with($binary, "\x89PNG\r\n\x1a\n") || @getimagesizefromstring($binary) === false) {
                throw ValidationException::withMessages(["charts.$index.image" => 'Chart "'.$chart['title'].'" could not be read as an image.']);
            }
            $charts[] = ['title' => $chart['title'], 'src' => 'data:image/png;base64,'.base64_encode($binary)];
        }

        $page = $dashboard->data(AdminDashboardController::inventoryFilters($filters), AdminDashboardController::salesFilters($filters));
        $generatedAt = now()->format('F j, Y g:i A');

        // Part 12: if the PDF cannot be built (e.g. the DomPDF package is not installed: run composer install), the user
        // gets an error toast on the dashboard instead of a 500 page; the details go to the server log.
        try {
            return $this->pdf($generatedAt, $filters, $page, $charts, $data);
        } catch (\Throwable $exception) {
            Log::error('Dashboard export failed: '.$exception->getMessage(), ['exception' => $exception]);

            return redirect()
                ->route('admin.dashboard', array_filter($filters, fn (mixed $value): bool => $value !== null && $value !== ''))
                ->withErrors(['export' => 'The dashboard report could not be exported. Please try again or contact the administrator.']);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array<string, mixed>  $page
     * @param  array<int, array{title: string, src: string}>  $charts
     * @param  array<string, mixed>  $data
     */
    private function pdf(string $generatedAt, array $filters, array $page, array $charts, array $data): Response
    {
        $pdf = Pdf::loadView('admin.dashboard-export', [
            'generatedAt' => $generatedAt,
            'filters' => $this->filterLabels($filters),
            'inventoryCards' => $page['inventory']['cards'],
            'salesCards' => $page['salesTrend']['cards'],
            'charts' => $charts,
            'insight' => trim((string) ($data['insight_text'] ?? '')) === '' ? null : [
                'text' => trim($data['insight_text']),
                'note' => $data['insight_note'] ?? null,
                'source' => $data['insight_source'] ?? null,
                'generated_at' => $data['insight_generated_at'] ?? null,
            ],
        ])->setPaper('a4', 'portrait');

        return $pdf->download('cjp-dashboard-report-'.now()->format('Y-m-d-His').'.pdf');
    }

    /**
     * The applied filters in words, as the dashboard shows them.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, string>
     */
    private function filterLabels(array $filters): array
    {
        $fuel = fn (mixed $id): string => $id ? (string) DB::table('fuel_types')->where('id', $id)->value('name') : 'All';
        $salesDate = match (true) {
            (bool) $filters['sales_period'] => AdminDashboardController::SALES_PERIODS[$filters['sales_period']],
            $filters['sales_date_from'] || $filters['sales_date_to'] => trim(($filters['sales_date_from'] ?? '…').' to '.($filters['sales_date_to'] ?? '…')),
            default => 'All',
        };

        return [
            'Current Inventory: Search' => $filters['inv_search'] ?: 'None',
            'Current Inventory: Fuel Type' => $fuel($filters['inv_fuel_type_id']),
            'Current Inventory: Depot' => $filters['inv_depot_id'] ? (string) DB::table('depots')->where('id', $filters['inv_depot_id'])->value('name') : 'All',
            'Sales Trend: Search' => $filters['sales_search'] ?: 'None',
            'Sales Trend: Date' => $salesDate,
            'Sales Trend: Fuel Type' => $fuel($filters['sales_fuel_type_id']),
            'Sales Trend: Customer' => $filters['sales_customer_id'] ? (string) DB::table('customers')->where('id', $filters['sales_customer_id'])->value('name') : 'All',
        ];
    }
}
