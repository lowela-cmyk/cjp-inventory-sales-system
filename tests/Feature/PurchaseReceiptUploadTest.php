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

    public function test_driver_uploads_withdrawal_receipt_for_assigned_lift_and_inventory_can_view_it(): void
    {
        Storage::fake('local');
        $records = $this->baseHaulRecords();

        $this->actingAs($records['driver'])
            ->post(route('driver.fuel-lifting.hauls.withdrawal-receipt.store', $records['haulId']), [
                'withdrawal_receipt' => $this->tinyImage('withdrawal.png'),
                'withdrawal_notes' => 'Received from depot dispatcher.',
            ])
            ->assertRedirect(route('driver.fuel-lifting.hauled'));

        $haul = DB::table('hauls')->where('id', $records['haulId'])->first();

        $this->assertStringStartsWith('withdrawal-receipts/', $haul->withdrawal_receipt_path);
        $this->assertSame('Received from depot dispatcher.', $haul->withdrawal_receipt_notes);
        Storage::disk('local')->assertExists($haul->withdrawal_receipt_path);

        $this->actingAs($records['inventoryOfficer'])
            ->get(route('inventory-officer.inventory'))
            ->assertOk()
            ->assertSee('1 Uploaded')
            ->assertSee('Received from depot dispatcher.');

        $this->actingAs($records['inventoryOfficer'])
            ->get(route('withdrawal-receipts.show', $records['haulId']))
            ->assertOk();
    }

    public function test_driver_upload_validates_image_type_size_ownership_and_lift_status(): void
    {
        Storage::fake('local');
        $records = $this->baseHaulRecords(['status' => 'scheduled', 'hauled_at' => null]);
        $otherDriver = User::factory()->create(['role' => 'driver', 'status' => 'active']);

        $this->actingAs($records['driver'])
            ->post(route('driver.fuel-lifting.hauls.withdrawal-receipt.store', $records['haulId']), [
                'withdrawal_receipt' => UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
            ])
            ->assertSessionHasErrors('withdrawal_receipt');

        $this->actingAs($records['driver'])
            ->post(route('driver.fuel-lifting.hauls.withdrawal-receipt.store', $records['haulId']), [
                'withdrawal_receipt' => $this->largeImage('large.png'),
            ])
            ->assertSessionHasErrors('withdrawal_receipt');

        $this->actingAs($otherDriver)
            ->post(route('driver.fuel-lifting.hauls.withdrawal-receipt.store', $records['haulId']), [
                'withdrawal_receipt' => $this->tinyImage('other.png'),
            ])
            ->assertSessionHasErrors('withdrawal_receipt');

        $this->actingAs($records['driver'])
            ->post(route('driver.fuel-lifting.hauls.withdrawal-receipt.store', $records['haulId']), [
                'withdrawal_receipt' => $this->tinyImage('early.png'),
            ])
            ->assertSessionHasErrors('withdrawal_receipt');

        $this->assertNull(DB::table('hauls')->where('id', $records['haulId'])->value('withdrawal_receipt_path'));
    }

    public function test_reupload_replaces_existing_withdrawal_without_creating_duplicate_receipt_records(): void
    {
        Storage::fake('local');
        $records = $this->baseHaulRecords();

        $this->actingAs($records['driver'])
            ->post(route('driver.fuel-lifting.hauls.withdrawal-receipt.store', $records['haulId']), [
                'withdrawal_receipt' => $this->tinyImage('old.png'),
            ]);

        $oldPath = DB::table('hauls')->where('id', $records['haulId'])->value('withdrawal_receipt_path');

        $this->actingAs($records['driver'])
            ->post(route('driver.fuel-lifting.hauls.withdrawal-receipt.store', $records['haulId']), [
                'withdrawal_receipt' => $this->tinyImage('new.png'),
                'withdrawal_notes' => 'Updated note',
            ])
            ->assertRedirect(route('driver.fuel-lifting.hauled'));

        $haul = DB::table('hauls')->where('id', $records['haulId'])->first();

        $this->assertNotSame($oldPath, $haul->withdrawal_receipt_path);
        $this->assertSame('Updated note', $haul->withdrawal_receipt_notes);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($haul->withdrawal_receipt_path);
        $this->assertSame(1, DB::table('hauls')->where('purchase_item_id', $records['purchaseItemId'])->whereNotNull('withdrawal_receipt_path')->count());
    }

    public function test_purchase_form_rejects_receipt_upload_tampering(): void
    {
        Storage::fake('local');
        $records = $this->basePurchaseRecords();

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.purchases.store'), $this->purchasePayload($records, [
                'receipt_file' => $this->tinyImage('not-from-driver.png'),
            ]))
            ->assertSessionHasErrors('receipt_file');

        $this->assertSame(0, DB::table('purchases')->count());
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function baseHaulRecords(array $overrides = []): array
    {
        $records = $this->basePurchaseRecords();
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active']);
        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => uniqid('TRK-'),
            'capacity_liters' => 50000,
            'truck_type' => 'hauling',
            'status' => 'assigned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => 'PUR-WITHDRAWAL',
            'depot_id' => $records['depotId'],
            'purchase_date' => '2026-08-30',
            'payment_status' => 'paid',
            'status' => 'hauled',
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
            'quantity_hauled_liters' => 40000,
            'status' => 'lifted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $haulId = DB::table('hauls')->insertGetId(array_merge([
            'haul_code' => 'LFT-WITHDRAWAL',
            'purchase_id' => $purchaseId,
            'purchase_item_id' => $purchaseItemId,
            'depot_id' => $records['depotId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'truck_id' => $truckId,
            'driver_user_id' => $driver->id,
            'scheduled_at' => '2026-08-31 08:00:00',
            'hauled_at' => '2026-08-31 10:00:00',
            'quantity_liters' => 40000,
            'status' => 'lifted',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return array_merge($records, compact('driver', 'purchaseId', 'purchaseItemId', 'haulId'));
    }

    /**
     * @return array<string, mixed>
     */
    private function basePurchaseRecords(): array
    {
        $inventoryOfficer = User::factory()->create(['role' => 'inventory_officer', 'status' => 'active']);
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

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function purchasePayload(array $records, array $overrides = []): array
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

    private function tinyImage(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));
    }

    private function largeImage(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=').str_repeat('0', 6 * 1024 * 1024));
    }
}
