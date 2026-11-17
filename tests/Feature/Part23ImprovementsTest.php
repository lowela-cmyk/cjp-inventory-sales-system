<?php

namespace Tests\Feature;

use App\Models\ImportBatch;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\ImportedTransactionAuditService;
use App\Services\SpreadsheetImportService;
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
        return DB::table('customers')->insertGetId($extra + ['customer_code' => 'C23-'.$i, 'name' => 'Contact '.$i, 'company_name' => 'Company '.$i, 'status' => 'active', 'payment_status' => 'clear', 'location' => 'Lipa', 'created_at' => now(), 'updated_at' => now()]);
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
        $data = ['name' => 'New contact', 'company_name' => 'New company', 'location' => 'Lipa', 'phone' => '+63 917 000 0000', 'bank_name' => 'BDO', 'payment_status' => 'clear', 'status' => 'active'];
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
        $clear = $this->customer(101, ['payment_status' => 'pending']);
        $this->sale($clear, 'paid', 500);
        $this->actingAs($this->actor());
        $response = $this->get(route('admin.sales.customers', ['payment_status' => 'pending', 'search' => 'Company', 'location' => 'Lipa', 'status' => 'active', 'per_page' => 10]));
        $response->assertOk()->assertViewHas('customers', fn ($rows) => $rows->total() === 1 && $rows->first()['id'] === $pending);
        $this->get(route('admin.sales.customers', ['payment_status' => 'clear', 'per_page' => 10]))->assertOk()->assertViewHas('customers', fn ($rows) => $rows->total() === 32);
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
        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()->assertSee('Purchase Reports')->assertSee('data-dashboard-export', false)->assertDontSee('data-revenue-period')->assertDontSee('page-heading-actions');
    }

    public function test_invalid_import_rows_are_not_written_and_valid_rows_have_exact_audit_provenance(): void
    {
        Storage::fake('local');
        $admin = $this->actor();
        DB::table('depots')->insert(['depot_code' => 'D23', 'name' => 'Depot', 'status' => 'active']);
        $service = app(SpreadsheetImportService::class);
        $file = UploadedFile::fake()->createWithContent('purchases.csv', "Purchase ID,Date,Fuel,Depot Code,Purchased (L),Cost / Liter,Status\nP23-OK,2026-09-01,Diesel,D23,100,50,ordered\nP23-BAD,2026-09-01,Diesel,D23,100,garbage,ordered\nP23-DATE,2026-02-30,Diesel,D23,100,50,ordered\nP23-STATUS,2026-09-01,Diesel,D23,100,50,completed\n");
        $batch = $service->store($file, $admin->id);
        $this->assertSame(3, $batch->validation_summary['total_invalid']);
        $result = $service->import($batch, $admin->id);
        $this->assertSame(1, $result['created']['purchases']);
        $this->assertSame(3, $result['skipped']['purchases']);
        $this->assertDatabaseCount('purchases', 1);
        $this->assertSame('purchases', $result['records'][0]['table']);
        $again = $service->store($file, $admin->id);
        $this->assertSame(1, $again->validation_summary['total_duplicates']);
        $service->import($again, $admin->id);
        $this->assertDatabaseCount('purchases', 1);
    }

    public function test_import_rejects_unproven_paid_and_delivered_sales_and_is_admin_only(): void
    {
        Storage::fake('local');
        $admin = $this->actor();
        $this->customer(1);
        $service = app(SpreadsheetImportService::class);
        $file = UploadedFile::fake()->createWithContent('sales.csv', "Order-ID,Transaction Date,Company Name,Fuel,QTY,Price / Liter,Total Paid,Delivery Status\nS23-A,2026-09-01,Company 1,Diesel,10,50,100,pending\nS23-B,2026-09-01,Company 1,Diesel,10,50,0,delivered\n");
        $batch = $service->store($file, $admin->id);
        $this->assertSame(2, $batch->validation_summary['total_invalid']);
        $result = $service->import($batch, $admin->id);
        $this->assertSame(0, $result['created']['sales']);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('payments', 0);
        foreach (['sales_officer', 'inventory_officer', 'dispatch_officer', 'driver'] as $role) $this->actingAs($this->actor($role))->get(route('admin.imports'))->assertForbidden();
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

    public function test_generated_transaction_ids_do_not_duplicate_on_repeat_upload(): void
    {
        Storage::fake('local');
        $admin = $this->actor();
        DB::table('depots')->insert(['depot_code' => 'D23', 'name' => 'Depot', 'status' => 'active']);
        $service = app(SpreadsheetImportService::class);
        $csv = "Date,Fuel,Depot Code,Purchased (L),Cost / Liter\n2026-09-01,Diesel,D23,100,50\n2026-09-02,Diesel,D23,200,50\n";
        $batch = $service->store(UploadedFile::fake()->createWithContent('purchase.csv', $csv), $admin->id);
        $result = $service->import($batch, $admin->id);
        $this->assertSame(2, $result['created']['purchases']);
        $batch = $service->store(UploadedFile::fake()->createWithContent('purchase.csv', $csv), $admin->id);
        $this->assertSame(2, $batch->validation_summary['total_duplicates']);
        $service->import($batch, $admin->id);
        $this->assertDatabaseCount('purchases', 2);
    }

    public function test_xlsx_import_and_explicit_partial_payment_keep_financial_balances(): void
    {
        Storage::fake('local');
        $admin = $this->actor();
        $this->customer(1);
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet;
        $book->getActiveSheet()->setTitle('Sales Receivables');
        $book->getActiveSheet()->fromArray([
            ['Order-ID', 'Transaction Date', 'Company Name', 'Fuel', 'QTY', 'Price / Liter', 'Total Paid', 'Payment Date', 'Payment Method', 'Payment Reference', 'Payment Status'],
            ['S23-XLSX', '2026-09-01', 'Company 1', 'Diesel', 10, 50, 100, '2026-09-01', 'bank_transfer', 'SOURCE-PARTIAL-001', 'partial'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'part23-xlsx');
        try {
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book))->save($path);
            $file = UploadedFile::fake()->createWithContent('sales.xlsx', file_get_contents($path));
            $service = app(SpreadsheetImportService::class);
            $batch = $service->store($file, $admin->id);
            $this->assertSame(1, $batch->validation_summary['total_valid']);
            $service->import($batch, $admin->id);
            $sale = DB::table('sales')->where('sale_code', 'S23-XLSX')->first();
            $this->assertSame('partially_paid', $sale->status);
            $this->assertSame('pending', $sale->delivery_status);
            $this->assertDatabaseHas('payments', ['sale_id' => $sale->id, 'amount' => 100, 'reference_number' => 'SOURCE-PARTIAL-001']);
            $this->assertDatabaseHas('receivables', ['sale_id' => $sale->id, 'status' => 'partial']);
            $this->assertDatabaseCount('stock_outs', 0);
        } finally { unlink($path); $book->disconnectWorksheets(); }
    }

    public function test_import_transaction_rolls_back_records_and_fingerprints_on_write_failure(): void
    {
        Storage::fake('local');
        $admin = $this->actor();
        DB::table('depots')->insert(['depot_code' => 'D23', 'name' => 'Depot', 'status' => 'active']);
        $service = app(SpreadsheetImportService::class);
        $batch = $service->store(UploadedFile::fake()->createWithContent('purchase.csv', "Purchase ID,Date,Fuel,Depot Code,Purchased (L),Cost / Liter\nP23-ROLLBACK,2026-09-01,Diesel,D23,100,50\n"), $admin->id);
        $failOnce = true;
        DB::listen(function ($query) use (&$failOnce): void {
            if ($failOnce && str_contains(strtolower($query->sql), 'insert into') && str_contains($query->sql, 'purchase_items')) {
                $failOnce = false;
                throw new \RuntimeException('Simulated storage failure');
            }
        });
        try { $service->import($batch, $admin->id); $this->fail('Expected a rolled back import'); }
        catch (\RuntimeException $e) { $this->assertSame('Simulated storage failure', $e->getMessage()); }
        $this->assertDatabaseCount('purchases', 0);
        $this->assertDatabaseCount('purchase_items', 0);
        $this->assertDatabaseCount('import_row_fingerprints', 0);
        $this->assertSame('failed', $batch->fresh()->status);
    }

    public function test_lifting_import_respects_purchase_availability_and_truck_capacity(): void
    {
        Storage::fake('local');
        $admin = $this->actor();
        DB::table('depots')->insert(['depot_code' => 'D23', 'name' => 'Depot', 'status' => 'active']);
        DB::table('trucks')->insert(['truck_code' => 'T23', 'name' => 'Truck', 'capacity_liters' => 600, 'status' => 'available']);
        $service = app(SpreadsheetImportService::class);
        $batch = $service->store(UploadedFile::fake()->createWithContent('purchase.csv', "Purchase ID,Date,Fuel,Depot Code,Purchased (L),Cost / Liter\nP23-LIFT,2026-09-01,Diesel,D23,1000,50\n"), $admin->id);
        $service->import($batch, $admin->id);
        $header = "Lift-ID,Purchase-ID,Lift Date,Pickup Depot,Driver,Truck-ID,Lifted,Status\n";
        $batch = $service->store(UploadedFile::fake()->createWithContent('hauling.csv', $header."L23-OK,P23-LIFT,2026-09-01,Depot,Historical Driver,T23,500,scheduled\nL23-OVER,P23-LIFT,2026-09-02,Depot,Historical Driver,T23,700,scheduled\n"), $admin->id);
        $this->assertSame(1, $batch->validation_summary['total_valid']);
        $this->assertSame(1, $batch->validation_summary['total_invalid']);
        $result = $service->import($batch, $admin->id);
        $this->assertSame(1, $result['created']['hauling']);
        $this->assertDatabaseHas('hauls', ['haul_code' => 'L23-OK', 'quantity_liters' => 500, 'status' => 'scheduled']);
        $this->assertDatabaseMissing('hauls', ['haul_code' => 'L23-OVER']);
        $this->assertDatabaseCount('stock_outs', 0);
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
