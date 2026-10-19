<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * System Analysis compliance, Phase 2: Admin customer management in User Management (1.8) and Reports filters (6.A).
 */
class CompliancePhase2Test extends TestCase
{
    use RefreshDatabase;

    public function test_admin_manages_customers_in_sales_and_user_management_has_no_customers_tab(): void
    {
        // Part 8 #12 replaced the Phase 2 customer forms in User Management: customers aren't system users.
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);
        DB::table('customers')->insert(['id' => 157, 'customer_code' => 'CSM-000001', 'name' => 'Juan Dela Cruz', 'company_name' => 'Dela Cruz Hauling', 'payment_status' => 'clear', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($admin)->get(route('admin.user-management', ['tab' => 'customers']))->assertOk()
            ->assertDontSee('data-tab-target="customers"', false)
            ->assertDontSee('Dela Cruz Hauling')
            ->assertDontSee('customer-add', false);

        // Sales > Customers: Admin sees the stored code, adds and edits customers.
        $this->get(route('admin.sales.customers'))->assertOk()->assertSee('CSM-000001')->assertDontSee('CSM-000157');
        $this->post(route('admin.sales.customers.store'), [
            'name' => 'Maria Santos', 'company_name' => 'Santos Fuel Trading', 'bank_name' => 'BDO', 'payment_status' => 'clear', 'status' => 'active',
        ])->assertRedirect(route('admin.sales.customers'))->assertSessionHasNoErrors();
        $this->assertStringStartsWith('CSM-', (string) DB::table('customers')->where('company_name', 'Santos Fuel Trading')->value('customer_code'));

        $this->patch(route('admin.sales.customers.update', 157), [
            'edit_customer_id' => 157, 'name' => 'Juan Dela Cruz', 'company_name' => 'Dela Cruz Hauling Inc.', 'bank_name' => 'BPI', 'payment_status' => 'clear', 'status' => 'active',
        ])->assertRedirect(route('admin.sales.customers'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('customers', ['id' => 157, 'company_name' => 'Dela Cruz Hauling Inc.', 'bank_name' => 'BPI', 'customer_code' => 'CSM-000001']);

        $this->patch(route('admin.sales.customers.deactivate', 157))->assertRedirect(route('admin.sales.customers'));
        $this->assertDatabaseHas('customers', ['id' => 157, 'status' => 'inactive']);
    }

    public function test_reports_period_filter_shows_only_the_needed_inputs_and_has_no_generate_button(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);

        $html = $this->actingAs($admin)->get(route('admin.reports'))->assertOk()->getContent();
        $this->assertStringContainsString('data-report-filter', $html);
        $this->assertDoesNotMatchRegularExpression('#<button[^>]*>\s*Generate\s*</button>#', $html);
        // "All Time" needs no extra input, so every period input starts hidden and disabled.
        $this->assertSame(5, preg_match_all('#data-period-field="[a-z]+"\s+hidden disabled#', $html));

        $range = $this->get(route('admin.reports', ['period' => 'range', 'start_date' => '2026-10-01', 'end_date' => '2026-10-15']))->assertOk()->getContent();
        $this->assertSame(2, preg_match_all('#data-period-field="range"\s*>#', $range), 'Both range inputs are enabled for a range report.');
        $this->assertSame(3, preg_match_all('#data-period-field="[a-z]+"\s+hidden disabled#', $range));

        // The server still rejects an incomplete period.
        $this->get(route('admin.reports', ['period' => 'range', 'start_date' => '2026-10-01']))->assertSessionHasErrors('end_date');
    }
}
