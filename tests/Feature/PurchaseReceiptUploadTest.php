<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurchaseReceiptUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_officer_can_upload_pdf_jpg_and_png_receipts(): void
    {
        Storage::fake('local');
        $records = $this->baseRecords();

        foreach ([
            UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
            UploadedFile::fake()->create('receipt.jpg', 10, 'image/jpeg'),
            UploadedFile::fake()->create('receipt.png', 10, 'image/png'),
        ] as $file) {
            $this->actingAs($records['inventoryOfficer'])
                ->post(route('inventory-officer.inventory.purchases.store'), $this->payload($records, ['receipt_file' => $file]))
                ->assertRedirect(route('inventory-officer.inventory'));

            $purchase = DB::table('purchases')->latest('id')->first();

            $this->assertStringStartsWith('purchase-receipts/', $purchase->receipt_reference);
            Storage::disk('local')->assertExists($purchase->receipt_reference);
        }
    }

    public function test_receipt_upload_rejects_unsupported_and_oversized_files(): void
    {
        Storage::fake('local');
        $records = $this->baseRecords();

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.purchases.store'), $this->payload($records, [
                'receipt_file' => UploadedFile::fake()->create('script.php', 10, 'application/x-php'),
            ]))
            ->assertSessionHasErrors('receipt_file');

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.purchases.store'), $this->payload($records, [
                'receipt_file' => UploadedFile::fake()->create('large.pdf', 6000, 'application/pdf'),
            ]))
            ->assertSessionHasErrors('receipt_file');

        $this->assertSame(0, DB::table('purchases')->count());
    }

    public function test_receipt_links_to_correct_purchase_and_authorized_users_can_view_it(): void
    {
        Storage::fake('local');
        $records = $this->createPurchaseWithReceipt('purchase-a.pdf');
        $other = $this->createPurchaseWithReceipt('purchase-b.pdf');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($records['inventoryOfficer'])
            ->get(route('purchase-receipts.show', $records['purchaseId']))
            ->assertOk()
            ->assertDownload('pur-000001-receipt.pdf');

        $this->actingAs($admin)
            ->get(route('purchase-receipts.show', $records['purchaseId']))
            ->assertOk();

        $this->assertNotSame(
            DB::table('purchases')->where('id', $records['purchaseId'])->value('receipt_reference'),
            DB::table('purchases')->where('id', $other['purchaseId'])->value('receipt_reference')
        );
    }

    public function test_unauthorized_roles_and_invalid_purchase_ids_cannot_access_receipts(): void
    {
        Storage::fake('local');
        $records = $this->createPurchaseWithReceipt();
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active']);

        $this->actingAs($salesOfficer)
            ->get(route('purchase-receipts.show', $records['purchaseId']))
            ->assertForbidden();

        $this->actingAs($records['inventoryOfficer'])
            ->get(route('purchase-receipts.show', 999999))
            ->assertNotFound();
    }

    public function test_missing_receipt_does_not_break_purchase_page(): void
    {
        $records = $this->createPurchaseWithoutReceipt();

        $this->actingAs($records['inventoryOfficer'])
            ->get(route('inventory-officer.inventory'))
            ->assertOk()
            ->assertSee('No receipt uploaded');

        $this->actingAs($records['inventoryOfficer'])
            ->get(route('purchase-receipts.show', $records['purchaseId']))
            ->assertNotFound();
    }

    public function test_receipt_replacement_updates_reference_and_removes_old_file(): void
    {
        Storage::fake('local');
        $records = $this->createPurchaseWithReceipt('old.pdf');
        $oldPath = DB::table('purchases')->where('id', $records['purchaseId'])->value('receipt_reference');

        $this->actingAs($records['inventoryOfficer'])
            ->patch(route('inventory-officer.inventory.purchases.update', $records['purchaseItemId']), $this->payload($records, [
                'receipt_file' => UploadedFile::fake()->create('new.png', 10, 'image/png'),
            ]))
            ->assertRedirect(route('inventory-officer.inventory'));

        $newPath = DB::table('purchases')->where('id', $records['purchaseId'])->value('receipt_reference');

        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($newPath);
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
