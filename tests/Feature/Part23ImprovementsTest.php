<?php

namespace Tests\Feature;

use App\Models\ImportBatch;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\ImportedTransactionAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Part23ImprovementsTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $role = 'admin'): User
    {
        return User::factory()->create(['role' => $role, 'status' => 'active', 'approval_status' => 'approved']);
    }

    private function customer(int $i, array $extra = []): int
    {
        return DB::table('customers')->insertGetId($extra + ['customer_code' => 'C23-'.$i, 'name' => 'Contact '.$i, 'company_name' => 'Company '.$i, 'status' => 'active', 'payment_status' => 'paid', 'location' => 'Lipa', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function sale(int $customer, string $status, float $paid = 0, string $delivery = 'pending'): int
    {
        $id = DB::table('sales')->insertGetId(['sale_code' => 'S23-'.uniqid(), 'customer_id' => $customer, 'sale_date' => '2026-09-01', 'status' => $status, 'delivery_status' => $delivery, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sale_items')->insert(['sale_id' => $id, 'fuel_type_id' => DB::table('fuel_types')->where('code', 'DSL')->value('id'), 'quantity_liters' => 10, 'unit_price' => 50, 'line_total' => 500, 'created_at' => now(), 'updated_at' => now()]);
        if ($paid > 0) DB::table('payments')->insert(['payment_code' => 'P23-'.$id, 'sale_id' => $id, 'payment_date' => '2026-09-01', 'amount' => $paid, 'method' => 'bank_transfer', 'created_at' => now(), 'updated_at' => now()]);
        return $id;
    }

    public function test_customer_forms_save_without_email_and_preserve_existing_email_and_relationships(): void
    {
        $user = $this->actor('sales_officer');
        $data = ['name' => 'New contact', 'company_name' => 'New company', 'location' => 'Lipa', 'phone' => '+63 917 000 0000', 'bank_name' => 'BDO', 'payment_status' => 'paid', 'status' => 'active'];
        $this->actingAs($user)->post(route('sales-officer.sales.customers.store'), $data)->assertSessionHasNoErrors();
        $id = DB::table('customers')->where('company_name', 'New company')->value('id');
        $this->assertNotNull($id);
        DB::table('customers')->where('id', $id)->update(['email' => 'keep@example.com']);
        $sale = $this->sale($id, 'partially_paid', 100);
        $this->patch(route('sales-officer.sales.customers.update', $id), ['name' => 'Updated', 'email' => 'erase@example.com'] + $data)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('customers', ['id' => $id, 'name' => 'Updated', 'email' => 'keep@example.com']);
        $this->assertDatabaseHas('sales', ['id' => $sale, 'customer_id' => $id]);
        $this->get(route('sales-officer.sales.customers'))->assertOk()->assertSee('Updated')->assertDontSee('customer_email')->assertDontSee('<th>Email</th>', false)->assertDontSee('keep@example.com');
    }

    public function test_payment_filter_uses_balances_before_pagination_and_combines_search_location_and_status(): void
    {
        for ($i = 0; $i < 31; $i++) $this->customer($i);
        $pending = $this->customer(100);
        $this->sale($pending, 'partially_paid', 100);
        $clear = $this->customer(101, ['payment_status' => 'unpaid']);
        $this->sale($clear, 'paid', 500);
        $this->actingAs($this->actor());
        $response = $this->get(route('admin.sales.customers', ['payment_status' => 'partially_paid', 'search' => 'Company', 'location' => 'Lipa', 'status' => 'active', 'per_page' => 10]));
        $response->assertOk()->assertViewHas('customers', fn ($rows) => $rows->total() === 1 && $rows->first()['id'] === $pending);
        $this->get(route('admin.sales.customers', ['payment_status' => 'paid', 'per_page' => 10]))->assertOk()->assertViewHas('customers', fn ($rows) => $rows->total() === 32);
        $first = $this->get(route('admin.sales.customers', ['per_page' => 10]))->viewData('customers');
        $second = $this->get(route('admin.sales.customers', ['per_page' => 10, 'customers_page' => 2]))->viewData('customers');
        $this->assertCount(10, $first);
        $this->assertEmpty($first->pluck('id')->intersect($second->pluck('id')));
        $this->assertStringContainsString('per_page=10', $second->nextPageUrl());
        $this->get(route('admin.sales.customers', ['per_page' => 100]))->assertSee('Showing 1 to 33 of 33 records.');
        $this->get(route('admin.sales.customers', ['per_page' => 10, 'customers_page' => 99]))->assertRedirect(route('admin.sales.customers', ['per_page' => 10, 'customers_page' => 4]));
        $this->get(route('admin.sales.customers', ['per_page' => '999999']))->assertViewHas('customers', fn ($rows) => $rows->perPage() === 25);
    }

    public function test_dashboard_counts_transactions_once_and_omits_removed_controls(): void
    {
        $admin = $this->actor();
        $depot = DB::table('depots')->insertGetId(['depot_code' => 'D23', 'name' => 'Depot', 'status' => 'active']);
        foreach (['ordered', 'hauled', 'cancelled', 'draft'] as $i => $status) {
            $id = DB::table('purchases')->insertGetId(['purchase_code' => 'P23-'.$i, 'purchase_date' => '2026-09-15', 'depot_id' => $depot, 'status' => $status]);
            foreach (['DSL', 'F1'] as $code) DB::table('purchase_items')->insert(['purchase_id' => $id, 'fuel_type_id' => DB::table('fuel_types')->where('code', $code)->value('id'), 'quantity_ordered_liters' => 100, 'unit_cost' => 50, 'line_total' => 5000]);
        }
        $report = app(DashboardService::class)->purchaseReport();
        $this->assertSame(2, array_sum($report['datasets'][0]['data']));
        $this->assertSame('count', $report['format']);
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertDontSee('Purchase Reports')->assertSee('data-dashboard-export', false)->assertDontSee('data-revenue-period')->assertDontSee('page-heading-actions');
    }



    public function test_page_sizes_across_modules_and_authorized_roles(): void
    {
        $cases = [
            'admin' => ['admin.inventory' => 'purchases', 'admin.inventory.stock-in' => 'stockIn', 'admin.inventory.stock-out' => 'stockOut', 'admin.ledger' => 'ledgerPages', 'admin.fuel-lifting' => 'haulPages', 'admin.sales' => 'sales', 'admin.sales.customers' => 'customers', 'admin.user-management' => 'staff'],
            'inventory_officer' => ['inventory-officer.inventory' => 'purchases', 'inventory-officer.ledger' => 'ledgerPages'],
            'sales_officer' => ['sales-officer.sales' => 'sales', 'sales-officer.sales.customers' => 'customers'],
            'driver' => ['driver.fuel-lifting' => 'schedulePages', 'driver.fuel-lifting.hauled' => 'hauledPages', 'driver.fuel-lifting.deliveries' => 'deliveryPages'],
            'dispatch_officer' => ['dispatch.fuel-lifting' => 'haulPages', 'dispatch.ledger' => 'ledgerPages'],
        ];
        foreach ($cases as $role => $routes) {
            $this->actingAs($this->actor($role));
            foreach ($routes as $route => $key) {
                foreach ([10, 25, 50, 100] as $size) {
                    $this->get(route($route, ['per_page' => $size]))->assertOk()->assertViewHas($key, fn ($rows) => $rows->perPage() === $size);
                }
            }
        }
    }





    public function test_read_only_audit_never_promotes_partial_payments_or_guesses_legacy_provenance(): void
    {
        $admin = $this->actor();
        $id = $this->sale($this->customer(1), 'partially_paid', 100);
        $batch = ImportBatch::create(['batch_uuid' => (string) \Illuminate\Support\Str::uuid(), 'original_filename' => 'demo.csv', 'stored_path' => 'unused', 'file_hash' => str_repeat('a', 64), 'file_type' => 'csv', 'status' => 'completed', 'mapping_summary' => [], 'validation_summary' => [], 'created_by' => $admin->id, 'workbook_summary' => ['dataset_kind' => 'mock_demo'], 'result_summary' => ['records' => [['table' => 'sales', 'id' => $id]]]]);
        $report = app(ImportedTransactionAuditService::class)->report($batch);
        $this->assertSame([], $report['proposals']);
        $this->assertSame(0, $report['applied_changes']);
        $this->assertNotEmpty($report['issues']);
        $this->assertDatabaseHas('sales', ['id' => $id, 'status' => 'partially_paid']);
        $batch->update(['result_summary' => []]);
        $this->assertStringContainsString('no exact record provenance', app(ImportedTransactionAuditService::class)->report($batch)['provenance_note']);
    }
}
