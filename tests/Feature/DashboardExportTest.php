<?php

namespace Tests\Feature;

use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Part 11: Export the dashboard's charts, KPI cards and AI insight as one PDF with the date and the applied filters.
 */
class DashboardExportTest extends TestCase
{
    use RefreshDatabase;

    /** A 1x1 PNG, as Chart.js toBase64Image() would send. */
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function test_the_dashboard_has_an_export_button_and_downloads_a_pdf_report(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $premium = (int) DB::table('fuel_types')->where('code', 'PREM')->value('id');

        $this->actingAs($admin)->get(route('admin.dashboard', ['inv_fuel_type_id' => $premium]))->assertOk()
            ->assertSee('data-dashboard-export-button', false)
            ->assertSee('<input type="hidden" name="inv_fuel_type_id" value="'.$premium.'">', false);

        $response = $this->actingAs($admin)->post(route('admin.dashboard.export'), [
            'inv_fuel_type_id' => $premium,
            'charts' => [['title' => 'Current Stock By Fuel Type (Stock Levels)', 'image' => self::PNG]],
            'insight_text' => 'Inventory: 20.00 KL in stock.',
            'insight_source' => 'Rule-based summary of the dashboard totals.',
            'insight_generated_at' => 'Oct 24, 2026 06:00 PM',
        ]);

        $response->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('attachment; filename=cjp-dashboard-report-', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_the_report_view_lists_the_filters_and_notes_a_missing_insight(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $premium = (int) DB::table('fuel_types')->where('code', 'PREM')->value('id');
        Pdf::shouldReceive('loadView')->once()->withArgs(function (string $view, array $data) {
            $html = view($view, $data)->render();
            $this->assertStringContainsString('Dashboard and Analytics Report', $html);
            $this->assertStringContainsString('Current Inventory: Fuel Type</td><td>Premium', $html);
            $this->assertStringContainsString('No AI insight was generated before this export.', $html);
            $this->assertSame([], $data['charts']);

            return true;
        })->andReturnSelf();
        Pdf::shouldReceive('setPaper')->andReturnSelf();
        Pdf::shouldReceive('download')->andReturn(response('%PDF', 200, ['Content-Type' => 'application/pdf']));

        $this->actingAs($admin)->post(route('admin.dashboard.export'), ['inv_fuel_type_id' => $premium])->assertOk();
    }

    public function test_only_png_chart_images_are_accepted_and_only_admins_can_export(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin)->from(route('admin.dashboard'))->post(route('admin.dashboard.export'), [
            'charts' => [['title' => 'Bad', 'image' => 'data:image/png;base64,'.base64_encode('<svg onload=alert(1)>')]],
        ])->assertSessionHasErrors('charts.0.image');

        $officer = User::factory()->create(['role' => 'inventory_officer', 'status' => 'active']);
        $this->actingAs($officer)->post(route('admin.dashboard.export'))->assertForbidden();
    }
}
