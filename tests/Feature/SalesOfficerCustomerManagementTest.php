<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesOfficerCustomerManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_officer_can_view_real_customers(): void
    {
        $records = $this->baseRecords();
        $this->customer([
            'customer_code' => 'CSM-REAL',
            'name' => 'Real Contact',
            'company_name' => 'Real Fuel Company',
            'location' => 'Nasugbu, Batangas',
            'email' => 'real@example.com',
            'phone' => '09171234567',
            'payment_status' => 'pending',
        ]);

        $this->actingAs($records['salesOfficer'])
            ->get(route('sales-officer.sales.customers'))
            ->assertOk()
            ->assertSee('CSM-REAL')
            ->assertSee('Real Contact')
            ->assertSee('Real Fuel Company')
            ->assertSee('Pending')
            ->assertDontSee('Binhi Green Homes');
    }

    public function test_sales_officer_can_add_valid_customer(): void
    {
        $records = $this->baseRecords();

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.customers.store'), [
                'name' => 'Maria Santos',
                'company_name' => 'Santos Fuel Supply',
                'location' => 'Batangas City',
                'email' => 'maria@santos.test',
                'phone' => '09170000001',
                'payment_status' => 'clear',
                'status' => 'active',
                'customer_code' => 'HACKED',
            ])
            ->assertRedirect(route('sales-officer.sales.customers'));

        $customer = DB::table('customers')->where('company_name', 'Santos Fuel Supply')->first();

        $this->assertNotNull($customer);
        $this->assertStringStartsWith('CSM-', $customer->customer_code);
        $this->assertNotSame('HACKED', $customer->customer_code);
        $this->assertSame('active', $customer->status);

        $this->actingAs($records['salesOfficer'])
            ->get(route('sales-officer.sales.customers'))
            ->assertOk()
            ->assertSee('Santos Fuel Supply');
    }

    public function test_customer_validation_rejects_invalid_required_duplicate_and_status_values(): void
    {
        $records = $this->baseRecords();
        $this->customer(['company_name' => 'Duplicate Company']);

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.customers.store'), [
                'name' => '',
                'company_name' => 'Duplicate Company',
                'email' => 'not-an-email',
                'phone' => 'phone<script>',
                'payment_status' => 'hacked',
                'status' => 'deleted',
            ])
            ->assertSessionHasErrors(['name', 'company_name', 'email', 'phone', 'payment_status', 'status']);

        $this->assertSame(1, DB::table('customers')->count());
    }

    public function test_sales_officer_can_edit_customer_without_changing_customer_id(): void
    {
        $records = $this->baseRecords();
        $customerId = $this->customer([
            'customer_code' => 'CSM-EDIT',
            'name' => 'Original Contact',
            'company_name' => 'Original Company',
        ]);

        $this->actingAs($records['salesOfficer'])
            ->patch(route('sales-officer.sales.customers.update', $customerId), [
                'name' => 'Updated Contact',
                'company_name' => 'Updated Company',
                'location' => 'Lipa City',
                'email' => 'updated@example.com',
                'phone' => '09179999999',
                'payment_status' => 'partial',
                'status' => 'active',
            ])
            ->assertRedirect(route('sales-officer.sales.customers'));

        $this->assertSame(1, DB::table('customers')->count());
        $this->assertDatabaseHas('customers', [
            'id' => $customerId,
            'customer_code' => 'CSM-EDIT',
            'name' => 'Updated Contact',
            'company_name' => 'Updated Company',
            'payment_status' => 'partial',
        ]);
    }

    public function test_customer_details_show_actual_data_and_related_transaction_summary(): void
    {
        $records = $this->baseRecords();
        $customerId = $this->customer([
            'customer_code' => 'CSM-DETAIL',
            'name' => 'Detail Contact',
            'company_name' => 'Detail Fuel Corp',
        ]);
        DB::table('sales')->insert([
            'sale_code' => 'SLS-DETAIL',
            'customer_id' => $customerId,
            'sale_date' => '2026-08-30',
            'payment_method' => 'cash_on_delivery',
            'payment_terms' => 'cod',
            'status' => 'confirmed',
            'created_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($records['salesOfficer'])
            ->get(route('sales-officer.sales.customers'))
            ->assertOk()
            ->assertSee('CSM-DETAIL')
            ->assertSee('Detail Contact')
            ->assertSee('Detail Fuel Corp')
            ->assertSee('1 sales');
    }

    public function test_customer_search_filters_records(): void
    {
        $records = $this->baseRecords();
        $this->customer(['customer_code' => 'CSM-ALPHA', 'company_name' => 'Alpha Fuel']);
        $this->customer(['customer_code' => 'CSM-BETA', 'company_name' => 'Beta Fuel']);

        $this->actingAs($records['salesOfficer'])
            ->get(route('sales-officer.sales.customers', ['search' => 'Alpha']))
            ->assertOk()
            ->assertSee('Alpha Fuel')
            ->assertDontSee('Beta Fuel');
    }

    public function test_deactivation_preserves_customer_and_historical_sales(): void
    {
        $records = $this->baseRecords();
        $customerId = $this->customer([
            'customer_code' => 'CSM-HISTORY',
            'company_name' => 'Historical Fuel',
        ]);
        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => 'SLS-HISTORY',
            'customer_id' => $customerId,
            'sale_date' => '2026-08-30',
            'payment_method' => 'cash_on_delivery',
            'payment_terms' => 'cod',
            'status' => 'confirmed',
            'created_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($records['salesOfficer'])
            ->patch(route('sales-officer.sales.customers.deactivate', $customerId))
            ->assertRedirect(route('sales-officer.sales.customers'));

        $this->assertDatabaseHas('customers', [
            'id' => $customerId,
            'status' => 'inactive',
        ]);
        $this->assertDatabaseHas('sales', [
            'id' => $saleId,
            'customer_id' => $customerId,
        ]);
    }

    public function test_admin_monitoring_still_displays_customer_read_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $this->customer([
            'customer_code' => 'CSM-ADMIN',
            'name' => 'Admin Visible',
            'company_name' => 'Admin Visible Co',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.sales'))
            ->assertOk()
            ->assertSee('CSM-ADMIN')
            ->assertSee('Admin Visible Co');
    }

    public function test_non_sales_roles_cannot_manage_customers(): void
    {
        $customerId = $this->customer();
        $payload = [
            'name' => 'Blocked Contact',
            'company_name' => 'Blocked Company',
            'payment_status' => 'clear',
            'status' => 'active',
        ];

        foreach (['admin', 'inventory_officer', 'dispatch_officer', 'driver'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);

            $this->actingAs($user)
                ->post(route('sales-officer.sales.customers.store'), $payload)
                ->assertForbidden();

            $this->actingAs($user)
                ->patch(route('sales-officer.sales.customers.update', $customerId), $payload)
                ->assertForbidden();

            $this->actingAs($user)
                ->patch(route('sales-officer.sales.customers.deactivate', $customerId))
                ->assertForbidden();
        }

        $this->assertSame(1, DB::table('customers')->count());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function customer(array $overrides = []): int
    {
        return DB::table('customers')->insertGetId(array_merge([
            'customer_code' => uniqid('CSM-'),
            'name' => 'Customer Contact',
            'company_name' => uniqid('Customer Company '),
            'location' => 'Nasugbu, Batangas',
            'email' => uniqid('customer').'@example.com',
            'phone' => '09171234567',
            'payment_status' => 'clear',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private function baseRecords(): array
    {
        $salesOfficer = User::factory()->create([
            'role' => 'sales_officer',
            'status' => 'active',
        ]);

        return compact('salesOfficer');
    }
}
