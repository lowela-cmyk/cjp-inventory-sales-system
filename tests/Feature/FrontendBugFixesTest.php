<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FrontendBugFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_blade_frontend_avoids_inline_javascript_handlers(): void
    {
        foreach ($this->frontendFiles('resources/views') as $file) {
            $contents = file_get_contents($file) ?: '';

            $this->assertStringNotContainsString('onclick=', $contents, $file);
            $this->assertStringNotContainsString('onsubmit=', $contents, $file);
            $this->assertStringNotContainsString('javascript:', $contents, $file);
            $this->assertStringNotContainsString('href="#"', $contents, $file);
        }

        $driverDeliveryView = file_get_contents(resource_path('views/driver/assigned-deliveries.blade.php')) ?: '';

        $this->assertStringContainsString('data-confirm-message=', $driverDeliveryView);
        $this->assertStringContainsString('data-print-page', $driverDeliveryView);
    }

    public function test_shared_frontend_assets_guard_charts_and_duplicate_submissions(): void
    {
        $script = file_get_contents(resource_path('js/app.js')) ?: '';
        $styles = file_get_contents(resource_path('css/app.css')) ?: '';

        $this->assertStringContainsString('dataset.confirmMessage', $script);
        $this->assertStringContainsString('data-print-page', $script);
        $this->assertStringContainsString('form.dataset.submitted', $script);
        $this->assertStringContainsString('try {', $script);
        $this->assertStringContainsString('JSON.parse', $script);
        $this->assertStringContainsString('canvas.hidden = true', $script);
        $this->assertStringContainsString('--admin-red: var(--danger);', $styles);
        $this->assertStringContainsString('.btn:disabled', $styles);
        $this->assertStringContainsString('form[aria-busy="true"]', $styles);
    }

    public function test_admin_nested_routes_keep_parent_sidebar_active(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $response = $this->actingAs($admin)
            ->get(route('admin.reports', ['period' => 'month', 'month' => '2026-09', 'year' => 2026]))
            ->assertOk();

        $this->assertMatchesRegularExpression(
            '/class="side-link is-active" href="[^"]*\/admin\/reports"/',
            $response->getContent()
        );
    }

    public function test_dashboard_chart_markup_keeps_valid_fallbacks_for_empty_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-sales-trend-chart', false)
            ->assertSee('data-chart=', false)
            ->assertSee('No data available')
            ->assertSee('Generate an explanation from the current inventory variance values.');
    }

    public function test_ai_report_output_remains_escaped_in_frontend(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->withSession([
            'revenueInsight' => [
                'text' => "Revenue Summary\n<script>alert('x')</script>",
                'generated_at' => 'Sep 04, 2026 10:00 AM',
            ],
        ])
            ->actingAs($admin)
            ->get(route('admin.reports'))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;', false)
            ->assertDontSee("<script>alert('x')</script>", false);
    }

    /**
     * @return array<int, string>
     */
    private function frontendFiles(string $path): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($path)));

        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
