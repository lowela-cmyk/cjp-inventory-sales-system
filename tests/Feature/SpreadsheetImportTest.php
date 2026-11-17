<?php

namespace Tests\Feature;

use App\Models\ImportBatch;
use App\Models\User;
use App\Services\SpreadsheetImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SpreadsheetImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_is_staged_and_previewed_without_writing_records(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);
        $this->actingAs($admin)->get(route('admin.imports'))
            ->assertOk()
            ->assertSee('Encode data in bulk')
            ->assertSee('Export Import Template')
            ->assertSee('data-analysis-form')
            ->assertSee('data-confirm="none"', false)
            ->assertSee('Analyze File')
            ->assertDontSee('data-analysis-progress', false);

        $file = UploadedFile::fake()->createWithContent('purchase.csv', "Purchase-ID,Date,Fuel,Depot Code,Depot,Purchased (L),Cost / Liter\nPUR-DSL-TEST,2026-01-02,Diesel,DEP-TEST,Test Depot,1000,50\n");
        $batch = app(SpreadsheetImportService::class)->store($file, (int) $admin->id);

        $this->assertSame('preview', $batch->status);
        $this->assertSame(1, $batch->workbook_summary['categories']['purchases']);
        $this->assertSame(1, $batch->validation_summary['categories']['purchases']['invalid']);
        $this->assertSame(0, DB::table('purchases')->count());
        $this->assertSame(0, DB::table('import_batches')->where('status', 'completed')->count());
    }

    public function test_import_template_definition_uses_only_supported_non_calculated_fields(): void
    {
        $definition = app(SpreadsheetImportService::class)->importTemplateDefinition();

        $this->assertSame(['purchases', 'hauling', 'trucks', 'sales', 'depots', 'customers', 'lifting_schedules'], array_keys($definition));
        $allFields = collect($definition)->flatMap(fn ($category) => collect($category['fields'])->pluck('field'))->all();
        $this->assertNotContains('line_total', $allFields);
        $this->assertNotContains('balance', $allFields);
        $this->assertNotContains('month', $allFields);
        $this->assertContains('purchase_date', $allFields);
        $this->assertContains('company_name', collect($definition['customers']['fields'])->pluck('field')->all());
    }

    public function test_import_template_download_route_returns_the_generated_workbook(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);

        $this->actingAs($admin)->get(route('admin.imports.template'))
            ->assertDownload('CJP_System_Import_Template.xlsx');
    }

    public function test_unmapped_display_and_action_columns_are_staged_as_ignored(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);
        $file = UploadedFile::fake()->createWithContent('purchase.csv', "Purchase-ID,Date,Fuel,Depot Code,Purchased (L),Cost / Liter,Actions,Total Cost\nPUR-DSL-IGNORED,2026-01-02,Diesel,DEP-TEST,1000,50,Edit,50000\n");

        $batch = app(SpreadsheetImportService::class)->store($file, (int) $admin->id);
        $mapping = collect($batch->mapping_summary)->first(fn ($value) => is_array($value) && ($value['category'] ?? null) === 'purchases') ?? [];
        $ignored = $mapping['ignored_columns'] ?? [];

        $this->assertNotEmpty($ignored);
        $this->assertContains('Action column', array_values($ignored));
        $this->assertContains('Calculated column', array_values($ignored));
        $this->assertStringNotContainsString('Unmatched', json_encode($mapping));
        $this->assertSame(0, DB::table('purchases')->count());
    }

    public function test_workbook_master_rows_are_not_marked_duplicate_by_analysis_sentinels(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);
        $file = UploadedFile::fake()->createWithContent('masters.csv', "Customer ID,Name,Company Name,Location,Status\nCUST-001,Ana,ARIESONE,Calaca,Active\nCUST-002,Ben,CRUIZER,Lipa,Active\n");

        $batch = app(SpreadsheetImportService::class)->store($file, (int) $admin->id);

        $customers = $batch->validation_summary['categories']['customers'];
        $this->assertSame(2, $customers['valid']);
        $this->assertSame(0, $customers['duplicates']);
    }

    public function test_preview_exposes_only_missing_required_columns_for_correction(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);
        $batch = ImportBatch::create([
            'batch_uuid' => (string) \Illuminate\Support\Str::uuid(), 'original_filename' => 'incomplete.csv', 'stored_path' => 'imports/incomplete.csv',
            'file_hash' => str_repeat('g', 64), 'file_type' => 'csv', 'status' => 'preview',
            'workbook_summary' => ['total_rows' => 1, 'worksheets' => [['name' => 'Purchases', 'category' => 'purchases', 'category_label' => 'Purchases', 'headers' => ['0' => 'Purchase-ID', '1' => 'Date', '2' => 'Fuel'], 'rows' => 1]]],
            'mapping_summary' => ['Purchases' => ['category' => 'purchases', 'columns' => ['0' => 'purchase_code', '1' => null, '2' => 'fuel'], 'ignored_columns' => ['3' => 'Action column'], 'missing_fields' => ['purchase_date'], 'needs_edit' => true]],
            'validation_summary' => [], 'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->get(route('admin.imports.preview', $batch))
            ->assertOk()
            ->assertSee('Fix Missing Columns')
            ->assertSee('Purchase date')
            ->assertSee('data-cancel-mapping', false)
            ->assertDontSee('data-modal-close', false)
            ->assertDontSee('Show All Columns')
            ->assertDontSee('Actions');
    }

    public function test_completed_batch_cannot_be_previewed_or_confirmed_again(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);
        $batch = ImportBatch::create([
            'batch_uuid' => (string) \Illuminate\Support\Str::uuid(), 'original_filename' => 'completed.csv', 'stored_path' => 'imports/completed.csv',
            'file_hash' => str_repeat('c', 64), 'file_type' => 'csv', 'status' => 'completed', 'workbook_summary' => [],
            'mapping_summary' => [], 'validation_summary' => [], 'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->get(route('admin.imports.preview', $batch))
            ->assertRedirect(route('admin.imports'))
            ->assertSessionHas('status', 'This import has already completed. No records were imported again.');

        $this->actingAs($admin)->post(route('admin.imports.confirm', $batch), ['confirm_import' => '1'])
            ->assertRedirect(route('admin.imports'))
            ->assertSessionHasErrors('import');
    }

    public function test_processing_batch_shows_locked_status_without_confirmation_form(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);
        $batch = ImportBatch::create([
            'batch_uuid' => (string) \Illuminate\Support\Str::uuid(), 'original_filename' => 'processing.csv', 'stored_path' => 'imports/processing.csv',
            'file_hash' => str_repeat('d', 64), 'file_type' => 'csv', 'status' => 'processing', 'workbook_summary' => [],
            'mapping_summary' => [], 'validation_summary' => [], 'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->get(route('admin.imports.preview', $batch))
            ->assertOk()
            ->assertSee('This import is already processing.')
            ->assertDontSee('data-import-confirm', false)
            ->assertDontSee('Confirm Import');
    }

    public function test_customer_import_generates_the_database_required_code_when_input_omits_it(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);
        $file = UploadedFile::fake()->createWithContent('customers.csv', "Name,Company Name,Location\nBuyer Contact,Buyer Company,Manila\n");

        $service = app(SpreadsheetImportService::class);
        $batch = $service->store($file, (int) $admin->id);
        $mapping = collect($batch->mapping_summary)->first(fn ($value) => is_array($value) && ($value['category'] ?? null) === 'customers') ?? [];
        $this->assertNotContains('customer_code', $mapping['missing_fields'] ?? []);

        $result = $service->import($batch, (int) $admin->id);
        $this->assertSame(1, $result['created']['customers']);
        $this->assertDatabaseHas('customers', ['name' => 'Buyer Contact', 'company_name' => 'Buyer Company']);
        $this->assertMatchesRegularExpression('/^CSM-\d{6}-[A-Z0-9]{5}$/', (string) DB::table('customers')->where('company_name', 'Buyer Company')->value('customer_code'));
    }

    public function test_generated_ui_identifiers_are_not_required_import_columns(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);
        $files = [
            ['purchase.csv', "Date,Fuel,Depot,Purchased (L),Cost / Liter\n2026-01-02,Diesel,DEP-TEST,1000,50\n", 'purchases', 'purchase_code'],
            ['truck.csv', "Plate Number,Name / Description,Capacity,Status\nABC123,Truck A,20000,available\n", 'trucks', 'truck_code'],
            ['sale.csv', "Sales Order No.,Transaction Date,Customer Name,Fuel,QTY,Price / Liter\nSO-1,2026-01-02,Buyer Company,Diesel,1000,60\n", 'sales', 'sale_code'],
            ['haul.csv', "Purchase-ID,DR Number,Lift Date,Driver,Truck-ID,Lifted\nPUR-1,DR-1,2026-01-02,Driver One,TRK-1,1000\n", 'hauling', 'haul_code'],
        ];

        foreach ($files as [$filename, $contents, $category, $generatedField]) {
            $batch = app(SpreadsheetImportService::class)->store(UploadedFile::fake()->createWithContent($filename, $contents), (int) $admin->id);
            $mapping = collect($batch->mapping_summary)->first(fn ($value) => is_array($value) && ($value['category'] ?? null) === $category) ?? [];
            $this->assertNotContains($generatedField, $mapping['missing_fields'] ?? [], $category.' should not require '.$generatedField);
        }
    }

    public function test_confirmed_import_is_transactional_and_creates_stock_in(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);
        DB::table('depots')->insert(['depot_code' => 'DEP-TEST', 'name' => 'Test Depot', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $file = UploadedFile::fake()->createWithContent('purchase.csv', "Purchase-ID,Date,Fuel,Depot Code,Depot,Purchased (L),Cost / Liter,Payment Status\nPUR-DSL-TEST,2026-01-02,Diesel,DEP-TEST,Test Depot,1000,50,PAID\n");
        $service = app(SpreadsheetImportService::class);
        $batch = $service->store($file, (int) $admin->id);
        $result = $service->import($batch, (int) $admin->id);

        $this->assertSame(1, $result['created']['purchases']);
        $this->assertSame(1, DB::table('purchases')->where('purchase_code', 'PUR-DSL-TEST')->count());
        $this->assertSame(1, DB::table('purchase_items')->count());
        $this->assertSame(1, DB::table('stock_ins')->count());
        $this->assertSame('completed', $batch->fresh()->status);
    }

    public function test_confirm_route_requires_explicit_confirmation(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);
        $batch = ImportBatch::create([
            'batch_uuid' => (string) \Illuminate\Support\Str::uuid(), 'original_filename' => 'x.csv', 'stored_path' => 'imports/x.csv',
            'file_hash' => str_repeat('a', 64), 'file_type' => 'csv', 'status' => 'preview', 'workbook_summary' => [],
            'mapping_summary' => [], 'validation_summary' => [], 'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.imports.confirm', $batch));
        $response->assertSessionHasErrors('confirm_import');
    }

    public function test_completed_import_shows_a_success_notification_once(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);
        DB::table('depots')->insert(['depot_code' => 'DEP-TEST', 'name' => 'Test Depot', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $file = UploadedFile::fake()->createWithContent('success.csv', "Date,Fuel,Depot Code,Depot,Purchased (L),Cost / Liter,Payment Status\n2026-01-02,Diesel,DEP-TEST,Test Depot,1000,50,PAID\n");
        $batch = app(SpreadsheetImportService::class)->store($file, (int) $admin->id);

        $response = $this->actingAs($admin)->post(route('admin.imports.confirm', $batch), ['confirm_import' => '1']);

        $response->assertRedirect(route('admin.imports'))
            ->assertSessionHas('import_result.status', 'success')
            ->assertSessionHas('import_result.imported', 1);
        $this->actingAs($admin)->get(route('admin.imports'))
            ->assertSee('Import Successful')
            ->assertSee('success.csv')
            ->assertSee('View Imported Records')
            ->assertSee('Close');
        $this->actingAs($admin)->get(route('admin.imports'))->assertDontSee('Import Successful');
    }

    public function test_partially_importable_file_shows_a_warning_notification_with_counts(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);
        DB::table('depots')->insert(['depot_code' => 'DEP-TEST', 'name' => 'Test Depot', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $contents = "Date,Fuel,Depot Code,Depot,Purchased (L),Cost / Liter,Payment Status\n2026-01-02,Diesel,DEP-TEST,Test Depot,,50,PAID\n";
        $service = app(SpreadsheetImportService::class);
        $first = $service->store(UploadedFile::fake()->createWithContent('first.csv', $contents), (int) $admin->id);
        $service->import($first, (int) $admin->id);
        $second = $service->store(UploadedFile::fake()->createWithContent('partial.csv', $contents), (int) $admin->id);

        $response = $this->actingAs($admin)->post(route('admin.imports.confirm', $second), ['confirm_import' => '1']);

        $response->assertRedirect(route('admin.imports'))
            ->assertSessionHas('import_result.status', 'warning')
            ->assertSessionHas('import_result.duplicates', 0)
            ->assertSessionHas('import_result.imported', 0);
        $this->actingAs($admin)->get(route('admin.imports'))
            ->assertSee('Import Completed with Warnings')
            ->assertSee('partial.csv')
            ->assertSee('View reasons');
    }

    public function test_failed_import_shows_a_failure_notification_without_writing_records(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);
        $batch = ImportBatch::create([
            'batch_uuid' => (string) \Illuminate\Support\Str::uuid(), 'original_filename' => 'failed.csv', 'stored_path' => 'imports/missing.csv',
            'file_hash' => str_repeat('z', 64), 'file_type' => 'csv', 'status' => 'preview', 'workbook_summary' => [],
            'mapping_summary' => [], 'validation_summary' => [], 'created_by' => $admin->id,
        ]);

        $response = $this->actingAs($admin)->post(route('admin.imports.confirm', $batch), ['confirm_import' => '1']);

        $response->assertRedirect(route('admin.imports'))->assertSessionHas('import_result.status', 'failed');
        $this->actingAs($admin)->get(route('admin.imports'))
            ->assertSee('Import Failed')
            ->assertSee('failed.csv')
            ->assertSee('View reasons');
        $this->assertSame(0, DB::table('purchases')->count());
    }

    public function test_preview_import_can_be_cancelled_without_touching_operational_records(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);
        Storage::disk('local')->put('imports/pending.csv', 'pending');
        $batch = ImportBatch::create([
            'batch_uuid' => (string) \Illuminate\Support\Str::uuid(), 'original_filename' => 'pending.csv', 'stored_path' => 'imports/pending.csv',
            'file_hash' => str_repeat('b', 64), 'file_type' => 'csv', 'status' => 'preview', 'workbook_summary' => ['total_rows' => 1],
            'mapping_summary' => [], 'validation_summary' => [], 'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->get(route('admin.imports.preview', $batch))
            ->assertOk()->assertSee('Cancel Import')->assertSee('Keep Import')->assertSee('Yes, Cancel Import');
        $this->actingAs($admin)->post(route('admin.imports.cancel', $batch))->assertRedirect(route('admin.imports'));

        $this->assertDatabaseHas('import_batches', ['id' => $batch->id, 'status' => 'cancelled']);
        Storage::disk('local')->assertMissing('imports/pending.csv');
        $this->assertSame(0, DB::table('purchases')->count());

        // Old preview/confirm URLs cannot resume a deleted batch.
        $this->actingAs($admin)->get(route('admin.imports.preview', $batch))->assertNotFound();
        $this->actingAs($admin)->post(route('admin.imports.confirm', $batch), ['confirm_import' => '1'])->assertNotFound();
    }

    public function test_only_the_batch_owner_can_cancel_a_preview_import(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);
        $otherAdmin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);
        $batch = ImportBatch::create([
            'batch_uuid' => (string) \Illuminate\Support\Str::uuid(), 'original_filename' => 'private.csv', 'stored_path' => 'imports/private.csv',
            'file_hash' => str_repeat('c', 64), 'file_type' => 'csv', 'status' => 'preview', 'workbook_summary' => [],
            'mapping_summary' => [], 'validation_summary' => [], 'created_by' => $owner->id,
        ]);

        $this->actingAs($otherAdmin)->post(route('admin.imports.cancel', $batch))->assertForbidden();
        $this->assertSame('preview', $batch->fresh()->status);
    }

    public function test_json_cancellation_is_single_step_and_processing_batches_are_not_cancelled(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);

        Storage::disk('local')->put('imports/instant.csv', 'pending');
        $preview = ImportBatch::create([
            'batch_uuid' => (string) \Illuminate\Support\Str::uuid(), 'original_filename' => 'instant.csv', 'stored_path' => 'imports/instant.csv',
            'file_hash' => str_repeat('e', 64), 'file_type' => 'csv', 'status' => 'preview', 'workbook_summary' => [],
            'mapping_summary' => [], 'validation_summary' => [], 'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->withHeader('Accept', 'application/json')
            ->post(route('admin.imports.cancel', $preview))
            ->assertOk()->assertJson(['removed' => true]);
        $this->assertSame('cancelled', $preview->fresh()->status);
        Storage::disk('local')->assertMissing('imports/instant.csv');

        Storage::disk('local')->put('imports/processing.csv', 'still processing');
        $processing = ImportBatch::create([
            'batch_uuid' => (string) \Illuminate\Support\Str::uuid(), 'original_filename' => 'processing.csv', 'stored_path' => 'imports/processing.csv',
            'file_hash' => str_repeat('f', 64), 'file_type' => 'csv', 'status' => 'processing', 'workbook_summary' => [],
            'mapping_summary' => [], 'validation_summary' => [], 'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)->withHeader('Accept', 'application/json')
            ->post(route('admin.imports.cancel', $processing))
            ->assertStatus(409)->assertJson(['removed' => false]);
        $this->assertSame('processing', $processing->fresh()->status);
        Storage::disk('local')->assertExists('imports/processing.csv');
    }

    public function test_all_cancelled_batches_are_retained_for_audit_but_hidden_from_history(): void
    {
        Storage::fake('local');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);
        foreach (['CJP_DATA.xlsx', 'CJP_DATA-copy.xlsx'] as $filename) {
            ImportBatch::create([
                'batch_uuid' => (string) \Illuminate\Support\Str::uuid(), 'original_filename' => $filename, 'stored_path' => 'imports/'.$filename,
                'file_hash' => str_repeat('d', 64), 'file_type' => 'xlsx', 'status' => 'cancelled', 'workbook_summary' => [],
                'mapping_summary' => [], 'validation_summary' => [], 'created_by' => $admin->id,
            ]);
        }

        $this->assertSame(2, ImportBatch::where('status', 'cancelled')->count());
        $this->actingAs($admin)->get(route('admin.imports'))
            ->assertOk()->assertDontSee('CJP_DATA.xlsx')->assertDontSee('CJP_DATA-copy.xlsx')->assertSee('No import history available.');
    }
}
