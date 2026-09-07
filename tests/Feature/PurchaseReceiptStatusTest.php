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

    public function test_purchase_without_driver_withdrawal_shows_no_withdrawal_state(): void
    {
        $records = $this->createPurchaseWithoutWithdrawal();

        $this->actingAs($records['inventoryOfficer'])
            ->get(route('inventory-officer.inventory'))
            ->assertOk()
            ->assertSee('PUR-NO-WITHDRAWAL')
            ->assertSee('No Withdrawal');

        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin)
            ->get(route('admin.inventory'))
            ->assertOk()
            ->assertSee('PUR-NO-WITHDRAWAL')
            ->assertSee('No Withdrawal');
    }

    public function test_driver_withdrawal_shows_uploaded_state_without_changing_inventory(): void
    {
        Storage::fake('local');
        $records = $this->createPurchaseWithoutWithdrawal();
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active']);
        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-WD',
            'capacity_liters' => 40000,
            'truck_type' => 'hauling',
            'status' => 'assigned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $haulId = DB::table('hauls')->insertGetId([
            'haul_code' => 'LFT-WD',
            'purchase_id' => $records['purchaseId'],
            'purchase_item_id' => $records['purchaseItemId'],
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
        ]);

        $this->actingAs($driver)
            ->post(route('driver.fuel-lifting.hauls.withdrawal-receipt.store', $haulId), [
                'withdrawal_receipt' => $this->tinyImage('withdrawal.png'),
                'withdrawal_notes' => 'Driver uploaded withdrawal.',
            ])
            ->assertRedirect(route('driver.fuel-lifting.hauled'));

        $this->actingAs($records['inventoryOfficer'])
            ->get(route('inventory-officer.inventory'))
            ->assertOk()
            ->assertSee('1 Uploaded')
            ->assertSee('Driver uploaded withdrawal.')
            ->assertDontSee('Verified')
            ->assertDontSee('Rejected');

        $this->assertSame(0, DB::table('inventory_movements')->count());
        $this->assertFalse(Schema::hasTable('deliveries'));
        $this->assertSame(0, DB::table('payments')->count());
    }

    public function test_purchase_receipt_fields_are_rejected_and_schema_has_no_receipt_status_workflow(): void
    {
        $records = $this->baseRecords();

        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.purchases.store'), $this->payload($records, [
                'receipt_status' => 'verified',
                'receipt_file' => $this->tinyImage('receipt.png'),
                'receipt_reference' => 'DR-OLD',
            ]))
            ->assertSessionHasErrors(['receipt_status', 'receipt_file', 'receipt_reference']);

        $this->assertTrue(Schema::hasColumn('purchases', 'receipt_reference'));
        $this->assertTrue(Schema::hasColumn('hauls', 'withdrawal_receipt_path'));
        $this->assertTrue(Schema::hasColumn('hauls', 'withdrawal_receipt_notes'));
        $this->assertFalse(Schema::hasColumn('purchases', 'receipt_status'));
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
    private function createPurchaseWithoutWithdrawal(): array
    {
        $records = $this->baseRecords();

        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => 'PUR-NO-WITHDRAWAL',
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

    private function tinyImage(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));
    }
}
