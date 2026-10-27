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

        $this->assertFileDoesNotExist(resource_path('views/driver/assigned-deliveries.blade.php'));
    }

    public function test_shared_frontend_assets_guard_charts_and_duplicate_submissions(): void
    {
        // Dashboard charts live in dashboard.js (Part 10), imported by app.js.
        $script = (file_get_contents(resource_path('js/app.js')) ?: '').(file_get_contents(resource_path('js/dashboard.js')) ?: '');
        $styles = file_get_contents(resource_path('css/app.css')) ?: '';

        $this->assertStringContainsString('dataset.confirmMessage', $script);
        $this->assertStringContainsString('data-print-page', $script);
        $this->assertStringContainsString('data-export-table', $script);
        $this->assertStringContainsString('data-sort-table', $script);
        $this->assertStringContainsString('exportVisibleTable', $script);
        $this->assertStringContainsString('sortVisibleTable', $script);
        $this->assertStringContainsString('form.dataset.submitted', $script);
        $this->assertStringContainsString('try {', $script);
        $this->assertStringContainsString('JSON.parse', $script);
        $this->assertStringContainsString('canvas.hidden = true', $script);
        $this->assertStringContainsString('--admin-red: var(--danger);', $styles);
        $this->assertStringContainsString('.btn:disabled', $styles);
        $this->assertStringContainsString('form[aria-busy="true"]', $styles);
        $this->assertStringContainsString('.toast-stack', $styles);
        $this->assertStringContainsString('@keyframes cjp-toast-enter', $styles);
    }

    public function test_type_button_controls_have_frontend_behavior_hooks(): void
    {
        foreach ($this->frontendFiles('resources/views') as $file) {
            $contents = file_get_contents($file) ?: '';

            preg_match_all('/<button\b[^>]*type="button"[^>]*>/i', $contents, $matches);

            foreach ($matches[0] as $button) {
                $this->assertMatchesRegularExpression(
                    '/\bdata-[a-z0-9-]+(?:=|\s|>)/i',
                    $button,
                    $file.' contains a button without a working behavior hook: '.$button
                );
            }
        }
    }

    public function test_login_uses_username_password_form_and_renders_logout_only_toast(): void
    {
        $response = $this->withSession([
            'status' => 'You have been signed out of CJP Southern Star.',
            'toast_type' => 'success',
            'toast_context' => 'logout',
        ])->get(route('login'));

        $response->assertOk()
            ->assertSee('name="username"', false)
            ->assertSee('name="password"', false)
            ->assertDontSee('name="role"', false)
            ->assertSee('cjp-toast-success', false)
            ->assertSee('cjp-toast-logo', false)
            ->assertSee('data-toast-dismiss', false)
            ->assertSee('You have been signed out of CJP Southern Star.');

        $this->withSession([
            'status' => 'Your registration is pending Admin approval.',
            'toast_type' => 'warning',
            'toast_context' => null,
        ])->get(route('login'))
            ->assertOk()
            ->assertSee('class="login-notice"', false)
            ->assertSee('Your registration is pending Admin approval.')
            ->assertDontSee('class="cjp-toast', false);
    }

    public function test_driver_header_has_no_hardcoded_person_fallback(): void
    {
        $header = file_get_contents(resource_path('views/components/driver/header.blade.php')) ?: '';
        $sidebar = file_get_contents(resource_path('views/components/driver/sidebar.blade.php')) ?: '';

        $this->assertStringNotContainsString('Manuel Ligaya', $header);
        $this->assertStringNotContainsString('Manuel Ligaya', $sidebar);
        $this->assertStringContainsString("'driverName' => 'Account'", $header);
        $this->assertStringContainsString("'driverName' => 'Account'", $sidebar);
    }

    public function test_admin_nested_routes_keep_parent_sidebar_active(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $response = $this->actingAs($admin)
            ->get(route('admin.fuel-lifting.trucks'))
            ->assertOk();

        $this->assertMatchesRegularExpression(
            '/class="side-link is-active" href="[^"]*\/admin\/fuel-lifting"/',
            $response->getContent()
        );
    }

    public function test_dashboard_chart_markup_keeps_valid_fallbacks_for_empty_data(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-dashboard-revenue', false)
            ->assertSee('data-series=', false)
            ->assertSee('No data yet')
            ->assertDontSee('data-dashboard-chart', false)
            ->assertSee('Generate Insight and Reports');
    }

    public function test_ai_report_output_remains_escaped_in_frontend(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->withSession([
            'dashboardInsight' => [
                'source' => 'ai',
                'text' => "Revenue Summary\n<script>alert('x')</script>",
                'note' => null,
                'generated_at' => 'Sep 04, 2026 10:00 AM',
            ],
        ])
            ->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;', false)
            ->assertDontSee("<script>alert('x')</script>", false);
    }

    public function test_inventory_tables_use_content_fit_responsive_layout_classes(): void
    {
        $styles = file_get_contents(resource_path('css/app.css')) ?: '';
        $inventory = file_get_contents(resource_path('views/inventory-officer/inventory.blade.php')) ?: '';

        $this->assertStringContainsString('.inventory-table-wrap', $styles);
        $this->assertStringContainsString('width: fit-content;', $styles);
        $this->assertStringContainsString('.inventory-table {', $styles);
        $this->assertStringContainsString('width: max-content;', $styles);
        $this->assertStringContainsString('inventory-table inventory-table-actions', $inventory);
        $this->assertStringContainsString('inventory-receipt-cell', $inventory);
    }

    public function test_alert_pages_use_full_width_responsive_shared_layout(): void
    {
        $styles = file_get_contents(resource_path('css/app.css')) ?: '';
        $component = file_get_contents(resource_path('views/components/admin/alert-list.blade.php')) ?: '';

        $this->assertStringContainsString('.alert-stack {', $styles);
        $this->assertStringContainsString('max-width: none;', $styles);
        $this->assertStringContainsString('.alerts-page {', $styles);
        $this->assertStringContainsString('.workflow-alert-stack .alert-bar {', $styles);
        $this->assertStringContainsString('grid-template-columns: 42px minmax(0, 1fr) auto;', $styles);
        $this->assertStringContainsString('@media (max-width: 900px)', $styles);
        $this->assertStringContainsString('grid-column: 1 / -1;', $styles);
        $this->assertStringContainsString('workflow-alert-message', $component);
        $this->assertStringContainsString('workflow-alert-actions', $component);

        foreach ([
            'admin/alerts.blade.php',
            'inventory-officer/alerts.blade.php',
            'sales-officer/alerts.blade.php',
            'dispatch/alerts.blade.php',
        ] as $view) {
            $contents = file_get_contents(resource_path('views/'.$view)) ?: '';
            $this->assertStringContainsString('class="alerts-page"', $contents, $view);
        }
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
