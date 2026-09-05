<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class FormValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_and_password_forms_validate_required_formats_and_keep_safe_old_input(): void
    {
        Mail::fake();

        $this->from(route('login'))
            ->post(route('login.store'), [
                'username' => 'bad-user',
                'role' => 'manager',
                'password' => '',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors([
                'password' => 'The password field is required.',
            ])
            ->assertSessionHasInput('username', 'bad-user');

        $this->assertNull(session()->getOldInput('password'));

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => 'not-an-email'])
            ->assertRedirect(route('password.request'))
            ->assertSessionHasErrors(['email' => 'The email field must be a valid email address.'])
            ->assertSessionHasInput('email', 'not-an-email');

        Mail::assertNothingSent();

        $this->from(route('password.reset'))
            ->post(route('password.update'), [
                'email' => 'reset@example.test',
                'code' => 'abc',
                'password' => 'short',
                'password_confirmation' => 'different',
            ])
            ->assertRedirect(route('password.reset'))
            ->assertSessionHasErrors(['code', 'password'])
            ->assertSessionHasInput('email', 'reset@example.test')
            ->assertSessionHasInput('code', 'abc');

        $this->assertNull(session()->getOldInput('password'));
        $this->assertNull(session()->getOldInput('password_confirmation'));
    }

    public function test_registration_validates_lengths_phone_format_duplicates_and_confirmation(): void
    {
        User::factory()->create(['email' => 'taken@example.test']);

        $payload = [
            'full_name' => str_repeat('A', 256),
            'email' => 'taken@example.test',
            'contact_number' => 'phone<script>',
            'password' => 'password',
            'password_confirmation' => 'different',
        ];

        $this->from(route('register'))
            ->post(route('register.store'), $payload)
            ->assertRedirect(route('register'))
            ->assertSessionHasErrors(['full_name', 'email', 'contact_number', 'password'])
            ->assertSessionHasInput('email', 'taken@example.test')
            ->assertSessionHasInput('contact_number', 'phone<script>');

        $this->assertSame(1, User::count());
    }

    public function test_admin_account_forms_validate_required_fields_uniques_lengths_statuses_and_old_input(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        User::factory()->create(['name' => 'Existing User', 'email' => 'existing@example.test']);

        $this->actingAs($admin)
            ->from(route('admin.user-management'))
            ->post(route('admin.user-management.drivers.store'), [
                'name' => 'Existing User',
                'email' => 'existing@example.test',
                'phone' => 'abc<script>',
                'role' => 'sales_officer',
                'status' => 'archived',
                'password' => 'short',
                'password_confirmation' => 'different',
                'license_number' => str_repeat('L', 101),
            ])
            ->assertRedirect(route('admin.user-management'))
            ->assertSessionHasErrors(['name', 'email', 'phone', 'role', 'status', 'password', 'license_number'])
            ->assertSessionHasInput('name', 'Existing User')
            ->assertSessionHasInput('email', 'existing@example.test')
            ->assertSessionHasInput('phone', 'abc<script>');

        $this->assertNull(session()->getOldInput('password'));
        $this->assertSame(2, User::count());
    }

    public function test_customer_form_validates_required_fields_formats_lengths_duplicates_and_status_options(): void
    {
        $records = $this->baseRecords();
        $this->customer(['company_name' => 'Duplicate Customer Co.']);

        $this->actingAs($records['salesOfficer'])
            ->from(route('sales-officer.sales.customers'))
            ->post(route('sales-officer.sales.customers.store'), [
                'name' => '',
                'company_name' => 'Duplicate Customer Co.',
                'location' => str_repeat('L', 256),
                'email' => 'bad-email',
                'phone' => 'bad<script>',
                'payment_status' => 'late',
                'status' => 'deleted',
            ])
            ->assertRedirect(route('sales-officer.sales.customers'))
            ->assertSessionHasErrors(['name', 'company_name', 'location', 'email', 'phone', 'payment_status', 'status'])
            ->assertSessionHasInput('company_name', 'Duplicate Customer Co.')
            ->assertSessionHasInput('email', 'bad-email');

        $this->assertSame(2, DB::table('customers')->count());
    }

    public function test_purchase_and_stock_in_forms_validate_related_records_numbers_dates_lengths_and_business_limits(): void
    {
        $records = $this->baseRecords();
        $allocationId = $this->garageAllocation($records, 1000);

        $this->actingAs($records['inventoryOfficer'])
            ->from(route('inventory-officer.inventory'))
            ->post(route('inventory-officer.inventory.purchases.store'), [
                'purchase_date' => 'not-a-date',
                'depot_id' => 999999,
                'fuel_type_id' => 999999,
                'quantity_ordered_liters' => 0,
                'unit_cost' => -1,
                'receipt_reference' => str_repeat('R', 256),
                'receipt_status' => 'approved',
                'payment_status' => 'settled',
                'status' => 'done',
            ])
            ->assertRedirect(route('inventory-officer.inventory'))
            ->assertSessionHasErrors([
                'purchase_date',
                'depot_id',
                'fuel_type_id',
                'quantity_ordered_liters',
                'unit_cost',
                'receipt_reference',
                'receipt_status',
                'payment_status',
                'status',
            ])
            ->assertSessionHasInput('quantity_ordered_liters', 0)
            ->assertSessionHasInput('receipt_status', 'approved');

        $this->assertSame(1, DB::table('purchases')->count());

        $this->actingAs($records['inventoryOfficer'])
            ->from(route('inventory-officer.inventory.stock-in'))
            ->post(route('inventory-officer.inventory.stock-in.store'), [
                'haul_allocation_id' => $allocationId,
                'storage_location_id' => $records['garageId'],
                'quantity_liters' => 1500,
                'movement_date' => '2026-09-04 08:00:00',
                'remarks' => 'over allocation',
            ])
            ->assertRedirect(route('inventory-officer.inventory.stock-in'))
            ->assertSessionHasErrors(['stock_in' => 'Quantity received cannot exceed the remaining garage allocation.'])
            ->assertSessionHasInput('quantity_liters', 1500);

        $this->actingAs($records['inventoryOfficer'])
            ->from(route('inventory-officer.inventory.stock-in'))
            ->post(route('inventory-officer.inventory.stock-in.store'), [
                'haul_allocation_id' => 999999,
                'storage_location_id' => 999999,
                'quantity_liters' => -5,
                'movement_date' => 'bad-date',
                'remarks' => str_repeat('R', 1001),
            ])
            ->assertRedirect(route('inventory-officer.inventory.stock-in'))
            ->assertSessionHasErrors(['haul_allocation_id', 'storage_location_id', 'quantity_liters', 'movement_date', 'remarks']);

        $this->assertSame(0, DB::table('inventory_movements')->count());
    }

    public function test_sales_stock_out_payment_and_receivable_forms_reject_invalid_tampered_and_over_limit_values(): void
    {
        $records = $this->baseRecords();
        $sale = $this->sale($records, 1000, 50);

        $this->actingAs($records['salesOfficer'])
            ->from(route('sales-officer.sales'))
            ->post(route('sales-officer.sales.store'), [
                'idempotency_key' => 'not-a-uuid',
                'sale_code' => str_repeat('S', 31),
                'customer_id' => 999999,
                'sale_date' => '2026-09-04',
                'fuel_type_id' => 999999,
                'quantity_liters' => 0,
                'unit_price' => -1,
                'payment_method' => 'crypto',
                'payment_terms' => 'forever',
                'status' => 'paid',
                'due_date' => '2026-09-01',
                'line_total' => 1,
            ])
            ->assertRedirect(route('sales-officer.sales'))
            ->assertSessionHasErrors([
                'idempotency_key',
                'sale_code',
                'customer_id',
                'fuel_type_id',
                'quantity_liters',
                'unit_price',
                'payment_method',
                'payment_terms',
                'status',
                'due_date',
                'line_total',
            ])
            ->assertSessionHasInput('sale_code', str_repeat('S', 31))
            ->assertSessionHasInput('payment_method', 'crypto');

        $this->actingAs($records['inventoryOfficer'])
            ->from(route('inventory-officer.inventory.stock-out'))
            ->post(route('inventory-officer.inventory.stock-out.store'), [
                'idempotency_key' => 'bad-token',
                'source_type' => 'garage',
                'sale_item_id' => $sale['saleItemId'],
                'storage_location_id' => $records['garageId'],
                'quantity_liters' => 1500,
                'stock_out_at' => '2026-09-04 09:00:00',
                'remarks' => str_repeat('R', 1001),
            ])
            ->assertRedirect(route('inventory-officer.inventory.stock-out'))
            ->assertSessionHasErrors(['idempotency_key', 'remarks']);

        $this->actingAs($records['inventoryOfficer'])
            ->from(route('inventory-officer.inventory.stock-out'))
            ->post(route('inventory-officer.inventory.stock-out.store'), [
                'idempotency_key' => (string) Str::uuid(),
                'source_type' => 'garage',
                'sale_item_id' => $sale['saleItemId'],
                'storage_location_id' => $records['garageId'],
                'quantity_liters' => 1500,
                'stock_out_at' => '2026-09-04 09:00:00',
            ])
            ->assertRedirect(route('inventory-officer.inventory.stock-out'))
            ->assertSessionHasErrors(['stock_out' => 'Quantity released cannot exceed the remaining sale quantity.'])
            ->assertSessionHasInput('quantity_liters', 1500);

        $this->actingAs($records['salesOfficer'])
            ->from(route('sales-officer.sales'))
            ->post(route('sales-officer.sales.payments.store', $sale['saleId']), [
                'idempotency_key' => 'bad-token',
                'payment_date' => 'bad-date',
                'amount' => 0,
                'method' => 'crypto',
                'reference_number' => str_repeat('R', 101),
                'remarks' => str_repeat('X', 1001),
                'sale_id' => $sale['saleId'],
                'remaining_balance' => 1,
            ])
            ->assertRedirect(route('sales-officer.sales'))
            ->assertSessionHasErrors([
                'idempotency_key',
                'payment_date',
                'amount',
                'method',
                'reference_number',
                'remarks',
                'sale_id',
                'remaining_balance',
            ]);

        $this->assertSame(0, DB::table('stock_outs')->count());
        $this->assertSame(0, DB::table('payments')->count());
    }

    public function test_delivery_and_fuel_lifting_forms_validate_ids_dates_statuses_and_capacity_limits(): void
    {
        $records = $this->baseRecords();
        $sale = $this->sale($records, 1000, 50);
        $stockOutId = $this->stockOut($records, $sale, 1000);
        $smallTruckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-SMALL-VAL',
            'capacity_liters' => 500,
            'truck_type' => 'mixed',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $haul = $this->haulForValidation($records, 1000);

        $this->actingAs($records['dispatchOfficer'])
            ->from(route('dispatch.fuel-lifting'))
            ->post(route('dispatch.fuel-lifting.deliveries.store'), [
                'idempotency_key' => 'bad-token',
                'source_type' => 'garage',
                'stock_out_id' => 999999,
                'driver_user_id' => 999999,
                'truck_id' => 999999,
                'scheduled_at' => 'bad-date',
                'quantity_liters' => 0,
            ])
            ->assertRedirect(route('dispatch.fuel-lifting'))
            ->assertSessionHasErrors(['idempotency_key', 'stock_out_id', 'driver_user_id', 'truck_id', 'scheduled_at', 'quantity_liters']);

        $this->actingAs($records['dispatchOfficer'])
            ->from(route('dispatch.fuel-lifting'))
            ->post(route('dispatch.fuel-lifting.deliveries.store'), [
                'idempotency_key' => (string) Str::uuid(),
                'source_type' => 'garage',
                'stock_out_id' => $stockOutId,
                'driver_user_id' => $records['driver']->id,
                'truck_id' => $smallTruckId,
                'scheduled_at' => now()->addDay()->toDateTimeString(),
                'quantity_liters' => 1000,
            ])
            ->assertRedirect(route('dispatch.fuel-lifting'))
            ->assertSessionHasErrors(['delivery' => 'Delivery quantity cannot exceed the selected truck capacity.'])
            ->assertSessionHasInput('quantity_liters', 1000);

        $this->actingAs($records['dispatchOfficer'])
            ->from(route('dispatch.fuel-lifting'))
            ->patch(route('dispatch.fuel-lifting.hauls.status', $haul['haulId']), [
                'idempotency_key' => 'bad-token',
                'status' => 'loaded',
            ])
            ->assertRedirect(route('dispatch.fuel-lifting'))
            ->assertSessionHasErrors(['idempotency_key', 'status']);

        $this->actingAs($records['dispatchOfficer'])
            ->from(route('dispatch.fuel-lifting'))
            ->patch(route('dispatch.fuel-lifting.hauls.truck', $haul['haulId']), [
                'idempotency_key' => (string) Str::uuid(),
                'truck_id' => $smallTruckId,
            ])
            ->assertRedirect(route('dispatch.fuel-lifting'))
            ->assertSessionHasErrors(['truck' => 'Lift quantity cannot exceed the selected truck capacity.']);

        $this->actingAs($records['driver'])
            ->from(route('driver.fuel-lifting'))
            ->patch(route('driver.fuel-lifting.hauls.status', $haul['haulId']), [
                'idempotency_key' => 'bad-token',
                'lifting_status' => 'loaded',
            ])
            ->assertRedirect(route('driver.fuel-lifting'))
            ->assertSessionHasErrors(['idempotency_key', 'lifting_status']);

        $this->assertSame(0, DB::table('deliveries')->count());
        $this->assertDatabaseHas('hauls', ['id' => $haul['haulId'], 'status' => 'scheduled']);
    }

    public function test_filter_and_ai_report_forms_validate_dates_ids_periods_and_status_values(): void
    {
        Http::fake();
        $records = $this->baseRecords();

        $this->actingAs($records['admin'])
            ->get(route('admin.dashboard', [
                'trend_period' => 'quarter',
                'expected_year' => 2200,
                'unlifted_date_from' => '2026-09-10',
                'unlifted_date_to' => '2026-09-01',
                'unlifted_lifting_status' => 'stuck',
                'variance_fuel_type_id' => 999999,
                'variance_status' => 'ignored',
            ]))
            ->assertSessionHasErrors([
                'trend_period',
                'expected_year',
                'unlifted_date_to',
                'unlifted_lifting_status',
                'variance_fuel_type_id',
                'variance_status',
            ]);

        $this->actingAs($records['admin'])
            ->from(route('admin.reports'))
            ->post(route('admin.reports.sales-trend-summary'), [
                'period' => 'quarter',
                'date' => 'bad-date',
                'start_date' => '2026-09-10',
                'end_date' => '2026-09-01',
                'month' => '09-2026',
                'year' => 1999,
            ])
            ->assertRedirect(route('admin.reports'))
            ->assertSessionHasErrors(['period', 'date', 'end_date', 'month', 'year']);

        Http::assertNothingSent();
    }

    /**
     * @return array<string, mixed>
     */
    private function baseRecords(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $inventoryOfficer = User::factory()->create(['role' => 'inventory_officer', 'status' => 'active']);
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active']);
        $dispatchOfficer = User::factory()->create(['role' => 'dispatch_officer', 'status' => 'active']);
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active']);
        $depotId = DB::table('depots')->insertGetId([
            'depot_code' => 'DEP-VAL',
            'name' => 'Validation Depot',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $garageId = DB::table('storage_locations')->insertGetId([
            'location_code' => 'GAR-VAL',
            'name' => 'Validation Garage',
            'type' => 'garage',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $fuelTypeId = DB::table('fuel_types')->insertGetId([
            'code' => 'DSL-VAL',
            'name' => 'Validation Diesel',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerId = $this->customer();
        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-VAL',
            'capacity_liters' => 5000,
            'truck_type' => 'mixed',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('admin', 'inventoryOfficer', 'salesOfficer', 'dispatchOfficer', 'driver', 'depotId', 'garageId', 'fuelTypeId', 'customerId', 'truckId');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function customer(array $overrides = []): int
    {
        return DB::table('customers')->insertGetId(array_merge([
            'customer_code' => 'CUS-VAL-'.Str::upper(Str::random(5)),
            'name' => 'Validation Customer',
            'company_name' => 'Validation Customer '.Str::upper(Str::random(5)),
            'email' => 'customer-'.Str::lower(Str::random(5)).'@example.test',
            'phone' => '09170000000',
            'payment_status' => 'clear',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param array<string, mixed> $records
     */
    private function garageAllocation(array $records, float $quantity): int
    {
        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => 'PUR-VAL-'.Str::upper(Str::random(5)),
            'depot_id' => $records['depotId'],
            'purchase_date' => '2026-09-04',
            'payment_status' => 'paid',
            'status' => 'hauled',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $purchaseItemId = DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchaseId,
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_ordered_liters' => $quantity,
            'unit_cost' => 50,
            'line_total' => $quantity * 50,
            'quantity_hauled_liters' => $quantity,
            'status' => 'lifted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $haulId = DB::table('hauls')->insertGetId([
            'haul_code' => 'LFT-VAL-'.Str::upper(Str::random(5)),
            'purchase_id' => $purchaseId,
            'purchase_item_id' => $purchaseItemId,
            'depot_id' => $records['depotId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'truck_id' => $records['truckId'],
            'driver_user_id' => $records['driver']->id,
            'scheduled_at' => '2026-09-04 07:00:00',
            'hauled_at' => '2026-09-04 08:00:00',
            'quantity_liters' => $quantity,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('haul_allocations')->insertGetId([
            'haul_id' => $haulId,
            'fuel_type_id' => $records['fuelTypeId'],
            'destination_type' => 'garage',
            'storage_location_id' => $records['garageId'],
            'quantity_liters' => $quantity,
            'allocated_at' => '2026-09-04 08:30:00',
            'status' => 'delivered',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $records
     * @return array{saleId: int, saleItemId: int}
     */
    private function sale(array $records, float $quantity, float $unitPrice): array
    {
        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => 'SLS-VAL-'.Str::upper(Str::random(5)),
            'customer_id' => $records['customerId'],
            'sale_date' => '2026-09-04',
            'payment_method' => 'bank_transfer',
            'payment_terms' => 'installment',
            'status' => 'confirmed',
            'created_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $saleItemId = DB::table('sale_items')->insertGetId([
            'sale_id' => $saleId,
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_liters' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $quantity * $unitPrice,
            'fulfilled_quantity_liters' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('receivables')->insert([
            'sale_id' => $saleId,
            'due_date' => '2026-09-30',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('saleId', 'saleItemId');
    }

    /**
     * @param array<string, mixed> $records
     * @param array{saleId: int, saleItemId: int} $sale
     */
    private function stockOut(array $records, array $sale, float $quantity): int
    {
        return DB::table('stock_outs')->insertGetId([
            'stock_out_code' => 'STO-VAL-'.Str::upper(Str::random(5)),
            'sale_id' => $sale['saleId'],
            'sale_item_id' => $sale['saleItemId'],
            'customer_id' => $records['customerId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'storage_location_id' => $records['garageId'],
            'quantity_liters' => $quantity,
            'stock_out_at' => '2026-09-04 09:00:00',
            'status' => 'released',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param array<string, mixed> $records
     * @return array{haulId: int, purchaseItemId: int}
     */
    private function haulForValidation(array $records, float $quantity): array
    {
        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => 'PUR-HVAL-'.Str::upper(Str::random(5)),
            'depot_id' => $records['depotId'],
            'purchase_date' => '2026-09-04',
            'payment_status' => 'paid',
            'status' => 'ordered',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $purchaseItemId = DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchaseId,
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_ordered_liters' => $quantity,
            'unit_cost' => 50,
            'line_total' => $quantity * 50,
            'quantity_hauled_liters' => 0,
            'status' => 'unlifted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $haulId = DB::table('hauls')->insertGetId([
            'haul_code' => 'LFT-HVAL-'.Str::upper(Str::random(5)),
            'purchase_id' => $purchaseId,
            'purchase_item_id' => $purchaseItemId,
            'depot_id' => $records['depotId'],
            'fuel_type_id' => $records['fuelTypeId'],
            'truck_id' => $records['truckId'],
            'driver_user_id' => $records['driver']->id,
            'scheduled_at' => now()->addDay()->toDateTimeString(),
            'quantity_liters' => $quantity,
            'status' => 'scheduled',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('haulId', 'purchaseItemId');
    }
}
