<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * System Analysis compliance, Phase 3: one notification channel (2.1), status colours (3.3) and opaque sticky actions (1.10).
 */
class CompliancePhase3Test extends TestCase
{
    use RefreshDatabase;

    public function test_success_messages_are_toasts_only_on_every_role_page(): void
    {
        $pages = [
            'admin' => ['admin.inventory', 'admin.fuel-lifting', 'admin.sales', 'admin.user-management', 'admin.account-requests'],
            'inventory_officer' => ['inventory-officer.inventory'],
            'sales_officer' => ['sales-officer.sales'],
            'dispatch_officer' => ['dispatch.fuel-lifting'],
            'driver' => ['driver.fuel-lifting'],
        ];

        foreach ($pages as $role => $routes) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active', 'approval_status' => 'approved']);
            foreach ($routes as $route) {
                $this->actingAs($user)->withSession(['status' => 'Record saved for '.$route.'.'])->get(route($route))->assertOk()
                    ->assertSee('class="cjp-toast cjp-toast-success"', false)
                    ->assertSee('Record saved for '.$route.'.')
                    ->assertDontSee('admin-flash', false)
                    ->assertDontSee('driver-notice', false);
            }
        }

        // No view renders the old inline banners any more.
        foreach (File::allFiles(resource_path('views')) as $file) {
            $this->assertStringNotContainsString('admin-flash', $file->getContents(), $file->getRelativePathname());
        }
    }

    public function test_every_status_has_a_colour(): void
    {
        $expected = [
            'Confirmed' => 'scheduled', 'Info' => 'scheduled', 'Planned' => 'pending', 'Maintenance' => 'partial',
            'Warning' => 'partial', 'Rejected' => 'danger', 'Refund due: ₱1,000.00' => 'partial',
        ];

        foreach ($expected as $status => $tone) {
            $html = Blade::render('<x-admin.status-badge :status="$status" />', ['status' => $status]);
            $this->assertStringContainsString('status-tone-'.$tone, $html, $status);
        }
    }

    public function test_sticky_action_cells_always_paint_an_opaque_background(): void
    {
        $styles = File::get(resource_path('css/app.css'));

        $this->assertStringNotContainsString(".admin-table tbody tr td:last-child {\n    background: inherit;", $styles);
        $this->assertMatchesRegularExpression('/\.admin-table tbody td:last-child \{\s*background: #fff;/', $styles);
        foreach (['danger', 'warning', 'success'] as $tone) {
            $this->assertMatchesRegularExpression('/\.admin-table \.row-'.$tone.' td:last-child \{\s*background: var\(--'.$tone.'-soft\);/', $styles);
        }
    }

    public function test_purchases_sales_and_stock_out_stack_related_values_without_dropping_any(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);
        $headers = [
            'admin.inventory' => ['Purchase-ID', 'Date', 'Fuel', 'Depot', 'Purchased (L)', 'Hauled (L)', 'Received (L)', 'Cost / Liter', 'Total Cost', 'Receipt', 'Inventory Status', 'Purchase Status', 'Payment Status', 'Actions'],
            'admin.sales' => ['Order-ID', 'Sales Order No.', 'Transaction Date', 'Customer Name', 'Company Name', 'Fuel', 'QTY', 'Price / Liter', 'Total', 'Total Paid', 'Balance', 'Due Date', 'Latest Payment', 'Delivery Status', 'Status', 'Actions'],
            'admin.inventory.stock-out' => ['Order-ID', 'Transaction Date', 'Customer Name', 'Company Name', 'Fuel', 'QTY Released', 'Cost / Unit', 'Total Cost', 'Price / Unit', 'Total Price', 'Total Paid', 'Source', 'Status', 'Margin', 'Realized Profit'],
        ];

        foreach ($headers as $route => $expected) {
            $html = $this->actingAs($admin)->get(route($route))->assertOk()->getContent();
            $this->assertStringContainsString('table-stacked', $html, $route);
            $this->assertSeeInOrderText($html, $expected, $route);
        }

        // Each stacked header line is its own export column.
        $stockOut = $this->get(route('admin.inventory.stock-out'))->getContent();
        $this->assertStringContainsString('<span class="th-line" data-export-part>Cost / Unit</span><span class="th-line" data-export-part>Total Cost</span>', $stockOut);

        $styles = File::get(resource_path('css/app.css'));
        $this->assertMatchesRegularExpression('/\.cell-line \{\s*white-space: nowrap;/', $styles);
        $this->assertStringContainsString("parts.length > 0 ? [...parts].map(csvValue) : [csvValue(cell)]", File::get(resource_path('js/app.js')));
    }

    /**
     * @param  array<int, string>  $values
     */
    private function assertSeeInOrderText(string $html, array $values, string $message): void
    {
        $text = html_entity_decode(strip_tags(preg_replace('#<tbody.*?</tbody>#s', '', $html)));
        $position = 0;
        foreach ($values as $value) {
            $found = strpos($text, $value, $position);
            $this->assertNotFalse($found, $message.': missing header "'.$value.'"');
            $position = $found + strlen($value);
        }
    }
}
