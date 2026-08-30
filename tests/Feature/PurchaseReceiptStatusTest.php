<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurchaseReceiptStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_purchase_without_receipt_shows_no_receipt_state(): void
    {
        $records = $this->createPurchaseWithoutReceipt();

        $this->actingAs($records['inventoryOfficer'])
            ->get(route('inventory-officer.inventory'))
            ->assertOk()
            ->assertSee('PUR-NO-RECEIPT')
            ->assertSee('No Receipt');

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin)
            ->get(route('admin.inventory'))
            ->assertOk()
            ->assertSee('PUR-NO-RECEIPT')
            ->assertSee('No Receipt');
    }

    public function test_uploading_receipt_shows_submitted_state_without_changing_inventory(): void
    {
        Storage::fake('local');
        $records = $this->baseRecords();

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.purchases.store'), $this->payload($records, [
                'receipt_file' => UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
            ]))
            ->assertRedirect(route('inventory-officer.inventory'));

        $purchase = DB::table('purchases')->latest('id')->first();
        Storage::disk('local')->assertExists($purchase->receipt_reference);

        $this->actingAs($records['inventoryOfficer'])
            ->get(route('inventory-officer.inventory'))
            ->assertOk()
            ->assertSee('Submitted')
            ->assertDontSee('Verified')
            ->assertDontSee('Rejected');

        $this->assertSame(0, DB::table('inventory_movements')->count());
        $this->assertSame(0, DB::table('hauls')->count());
        $this->assertSame(0, DB::table('deliveries')->count());
        $this->assertSame(0, DB::table('payments')->count());
    }

    public function test_admin_monitoring_uses_same_receipt_status_source_as_purchase(): void
    {
        Storage::fake('local');
        $records = $this->createPurchaseWithReceipt();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($records['inventoryOfficer'])
            ->get(route('inventory-officer.inventory'))
            ->assertOk()
            ->assertSee('Submitted');

        $this->actingAs($admin)
            ->get(route('admin.inventory'))
            ->assertOk()
            ->assertSee('PUR-000001')
            ->assertSee('Submitted');
    }

    public function test_receipt_status_form_tampering_is_rejected_and_does_not_create_records(): void
    {
        $records = $this->baseRecords();

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.purchases.store'), $this->payload($records, [
                'receipt_status' => 'hacked',
            ]))
            ->assertSessionHasErrors('receipt_status');

        $this->assertSame(0, DB::table('purchases')->count());
        $this->assertSame(0, DB::table('purchase_items')->count());
        $this->assertSame(0, DB::table('inventory_movements')->count());
    }

    public function test_receipt_status_form_tampering_on_update_is_rejected_and_preserves_receipt(): void
    {
        Storage::fake('local');
        $records = $this->createPurchaseWithReceipt();
        $originalPath = DB::table('purchases')->where('id', $records['purchaseId'])->value('receipt_reference');

        $this->actingAs($records['inventoryOfficer'])
            ->patch(route('inventory-officer.inventory.purchases.update', $records['purchaseItemId']), $this->payload($records, [
                'receipt_status' => 'verified',
            ]))
            ->assertSessionHasErrors('receipt_status');

        $this->assertSame($originalPath, DB::table('purchases')->where('id', $records['purchaseId'])->value('receipt_reference'));
        Storage::disk('local')->assertExists($originalPath);
        $this->assertSame(1, DB::table('purchases')->count());
        $this->assertSame(0, DB::table('inventory_movements')->count());
    }

    public function test_unauthorized_roles_cannot_use_purchase_update_to_change_receipt_status(): void
    {
        Storage::fake('local');
        $records = $this->createPurchaseWithReceipt();
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active']);

        foreach ([$admin, $salesOfficer] as $user) {
            $this->actingAs($user)
                ->patch(route('inventory-officer.inventory.purchases.update', $records['purchaseItemId']), $this->payload($records, [
                    'receipt_status' => 'verified',
                ]))
                ->assertForbidden();
        }
    }

    public function test_replacing_receipt_keeps_status_submitted_and_preserves_purchase_identity(): void
    {
        Storage::fake('local');
        $records = $this->createPurchaseWithReceipt('old.pdf');
        $beforePurchaseCount = DB::table('purchases')->count();
        $oldPath = DB::table('purchases')->where('id', $records['purchaseId'])->value('receipt_reference');

        $this->actingAs($records['inventoryOfficer'])
            ->patch(route('inventory-officer.inventory.purchases.update', $records['purchaseItemId']), $this->payload($records, [
                'receipt_file' => UploadedFile::fake()->create('replacement.png', 10, 'image/png'),
            ]))
            ->assertRedirect(route('inventory-officer.inventory'));

        $newPath = DB::table('purchases')->where('id', $records['purchaseId'])->value('receipt_reference');

        $this->assertSame($beforePurchaseCount, DB::table('purchases')->count());
        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('local')->assertExists($newPath);

        $this->actingAs($records['inventoryOfficer'])
            ->get(route('inventory-officer.inventory'))
            ->assertOk()
            ->assertSee('Submitted')
            ->assertDontSee('Verified');
    }

    public function test_existing_schema_has_no_persistent_receipt_status_workflow_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('purchases', 'receipt_reference'));
        $this->assertFalse(Schema::hasColumn('purchases', 'receipt_status'));
        $this->assertFalse(Schema::hasColumn('purchases', 'receipt_verified_by'));
        $this->assertFalse(Schema::hasColumn('purchases', 'receipt_verified_at'));
        $this->assertFalse(Schema::hasTable('purchase_receipts'));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function payload(array $records, array $overrides = []): array
    {
        return array_merge([
            'purchase_date' => '2026-08-30',
            'depot_id' => $records['depotId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_ordered_liters' => 40000,
            'unit_cost' => 50,
            'payment_status' => 'unpaid',
            'status' => 'ordered',
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function createPurchaseWithReceipt(string $name = 'receipt.pdf'): array
    {
        $records = $this->baseRecords();

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.purchases.store'), $this->payload($records, [
                'receipt_file' => str_ends_with($name, '.png')
                    ? UploadedFile::fake()->create($name, 10, 'image/png')
                    : UploadedFile::fake()->create($name, 10, 'application/pdf'),
            ]));

        $purchase = DB::table('purchases')->latest('id')->first();
        $purchaseItem = DB::table('purchase_items')->where('purchase_id', $purchase->id)->first();

        return array_merge($records, [
            'purchaseId' => $purchase->id,
            'purchaseItemId' => $purchaseItem->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function createPurchaseWithoutReceipt(): array
    {
        $records = $this->baseRecords();

        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => 'PUR-NO-RECEIPT',
            'depot_id' => $records['depotId'],
            'purchase_date' => '2026-08-30',
            'payment_status' => 'unpaid',
            'status' => 'ordered',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $purchaseItemId = DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchaseId,
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_ordered_liters' => 40000,
            'unit_cost' => 50,
            'line_total' => 2000000,
            'quantity_hauled_liters' => 0,
            'status' => 'unlifted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return array_merge($records, compact('purchaseId', 'purchaseItemId'));
    }

    /**
     * @return array<string, mixed>
     */
    private function baseRecords(): array
    {
        $inventoryOfficer = User::factory()->create([
            'role' => 'inventory_officer',
            'status' => 'active',
        ]);

        $depotId = DB::table('depots')->insertGetId([
            'depot_code' => uniqid('DEP-'),
            'name' => uniqid('Depot '),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fuelTypeId = DB::table('fuel_types')->insertGetId([
            'code' => uniqid('FUEL-'),
            'name' => uniqid('Fuel '),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('inventoryOfficer', 'depotId', 'fuelTypeId');
    }
}
