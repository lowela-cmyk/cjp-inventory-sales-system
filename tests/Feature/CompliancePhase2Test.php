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

    public function test_admin_manages_customers_from_user_management_with_their_real_codes(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);
        // Row id and code deliberately differ: the screen must show the stored code, never one derived from the id.
        DB::table('customers')->insert(['id' => 157, 'customer_code' => 'CSM-000001', 'name' => 'Juan Dela Cruz', 'company_name' => 'Dela Cruz Hauling', 'payment_status' => 'clear', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $tab = route('admin.user-management', ['tab' => 'customers']);

        $this->actingAs($admin)->get($tab)->assertOk()
            ->assertSee('CSM-000001')
            ->assertDontSee('CSM-000157')
            ->assertDontSee('managed in the customer/sales workflow')
            ->assertSee('action="'.route('admin.sales.customers.store').'"', false)
            ->assertSee('action="'.route('admin.sales.customers.update', 157).'"', false);

        // Add from User Management: saved with a generated code, and the admin lands back on the Customers tab.
        $this->actingAs($admin)->from($tab)->post(route('admin.sales.customers.store'), [
            'form_context' => 'um-add-customer', 'return_to' => 'user-management',
            'name' => 'Maria Santos', 'company_name' => 'Santos Fuel Trading', 'location' => 'Subic', 'email' => 'maria@example.com',
            'phone' => '09171234567', 'bank_name' => 'BDO', 'payment_status' => 'clear', 'status' => 'active',
        ])->assertRedirect($tab)->assertSessionHasNoErrors();
        $created = DB::table('customers')->where('company_name', 'Santos Fuel Trading')->first();
        $this->assertStringStartsWith('CSM-', $created->customer_code);
        $this->assertSame('BDO', $created->bank_name);

        // Edit from User Management.
        $this->actingAs($admin)->from($tab)->patch(route('admin.sales.customers.update', 157), [
            'edit_customer_id' => 157, 'return_to' => 'user-management',
            'name' => 'Juan Dela Cruz', 'company_name' => 'Dela Cruz Hauling Inc.', 'bank_name' => 'BPI', 'payment_status' => 'clear', 'status' => 'active',
        ])->assertRedirect($tab)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('customers', ['id' => 157, 'company_name' => 'Dela Cruz Hauling Inc.', 'bank_name' => 'BPI', 'customer_code' => 'CSM-000001']);

        // A validation error comes back to User Management and reopens the same modal.
        $this->actingAs($admin)->from($tab)->post(route('admin.sales.customers.store'), [
            'form_context' => 'um-add-customer', 'return_to' => 'user-management',
            'name' => 'Duplicate', 'company_name' => 'Santos Fuel Trading', 'payment_status' => 'clear', 'status' => 'active',
        ])->assertRedirect($tab)->assertSessionHasErrors('company_name');
        $this->get($tab)->assertSee('data-open-modal-on-load="customer-add"', false);

        // Search and the CSV export use the stored code too.
        $this->get(route('admin.user-management', ['tab' => 'customers', 'search' => 'CSM-000001']))->assertSee('<td>Dela Cruz Hauling Inc.</td>', false)->assertDontSee('<td>Santos Fuel Trading</td>', false);
        $csv = $this->get(route('admin.user-management.export', ['tab' => 'customers']))->assertOk()->getContent();
        $this->assertStringContainsString('CSM-000001', $csv);
        $this->assertStringNotContainsString('CSM-000157', $csv);

        // Without return_to the Sales page keeps its own redirect.
        $this->actingAs($admin)->post(route('admin.sales.customers.store'), [
            'name' => 'Other', 'company_name' => 'Other Co.', 'payment_status' => 'clear', 'status' => 'active',
        ])->assertRedirect(route('admin.sales.customers'));
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
