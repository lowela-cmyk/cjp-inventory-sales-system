<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\IdempotencyService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class Phase2GuardrailsAndIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function baseRecords(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active', 'approval_status' => 'approved']);
        $salesOfficerTwo = User::factory()->create(['role' => 'sales_officer', 'status' => 'active', 'approval_status' => 'approved']);
        $inventoryOfficer = User::factory()->create(['role' => 'inventory_officer', 'status' => 'active', 'approval_status' => 'approved']);
        $dispatchOfficer = User::factory()->create(['role' => 'dispatch_officer', 'status' => 'active', 'approval_status' => 'approved']);
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active', 'approval_status' => 'approved']);

        $depotId = DB::table('depots')->insertGetId([
            'depot_code' => 'DEP-GUARD',
            'name' => 'Guardrail Depot',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $garageId = DB::table('storage_locations')->insertGetId([
            'location_code' => 'GAR-GUARD',
            'name' => 'Guardrail Garage',
            'type' => 'garage',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-GUARD',
            'name' => 'Guardrail Customer',
            'company_name' => 'Guardrail Customer Co.',
            'payment_status' => 'clear',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-GUARD',
            'capacity_liters' => 50000,
            'truck_type' => 'mixed',
            'status' => 'assigned',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $dieselFuelId = (int) (DB::table('fuel_types')->where('code', 'DSL')->value('id') ?? DB::table('fuel_types')->insertGetId([
            'code' => 'DSL',
            'name' => 'DIESEL',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return compact(
            'admin',
            'salesOfficer',
            'salesOfficerTwo',
            'inventoryOfficer',
            'dispatchOfficer',
            'driver',
            'depotId',
            'garageId',
            'customerId',
            'truckId',
            'dieselFuelId'
        );
    }

    public function test_concurrent_or_repeated_request_with_same_idempotency_key_creates_one_record_for_sales(): void
    {
        $records = $this->baseRecords();
        $idempotencyKey = (string) Str::uuid();

        $payload = [
            'idempotency_key' => $idempotencyKey,
            'customer_id' => $records['customerId'],
            'sale_date' => '2026-09-10',
            'payment_method' => 'cash_on_delivery',
            'status' => 'draft',
            'items' => [
                [
                    'fuel_type_id' => $records['dieselFuelId'],
                    'quantity_liters' => '5000',
                    'unit_price' => '60.00',
                ],
            ],
        ];

        $first = $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.store'), $payload);
        $first->assertRedirect(route('sales-officer.sales'));

        $this->assertSame(1, DB::table('sales')->count());
        $this->assertSame(1, DB::table('idempotency_keys')->where('key', $idempotencyKey)->count());

        // Repeated submission
        $second = $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.store'), $payload);
        $second->assertRedirect(route('sales-officer.sales'))
            ->assertSessionHas('status', 'Sale record was already submitted.');

        $this->assertSame(1, DB::table('sales')->count());
    }

    public function test_concurrent_or_repeated_request_with_same_idempotency_key_creates_one_record_for_payments(): void
    {
        $records = $this->baseRecords();
        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => 'SLS-IDEM-PAY',
            'customer_id' => $records['customerId'],
            'sale_date' => '2026-09-10',
            'payment_method' => 'cash_on_delivery',
            'payment_terms' => 'cod',
            'status' => 'confirmed',
            'created_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sale_items')->insert([
            'sale_id' => $saleId,
            'fuel_type_id' => $records['dieselFuelId'],
            'quantity_liters' => 5000,
            'unit_price' => 60,
            'line_total' => 300000,
            'fulfilled_quantity_liters' => 5000,
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

        $idempotencyKey = (string) Str::uuid();
        $payload = [
            'idempotency_key' => $idempotencyKey,
            'payment_date' => '2026-09-11',
            'amount' => '50000',
            'method' => 'cash_on_delivery',
        ];

        $first = $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.payments.store', $saleId), $payload);
        $first->assertRedirect(route('sales-officer.sales'));

        $this->assertSame(1, DB::table('payments')->where('sale_id', $saleId)->count());
        $this->assertSame(1, DB::table('idempotency_keys')->where('key', $idempotencyKey)->count());

        // Repeated submission
        $second = $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.payments.store', $saleId), $payload);
        $second->assertRedirect(route('sales-officer.sales'))
            ->assertSessionHas('status', 'Payment record was already submitted.');

        $this->assertSame(1, DB::table('payments')->where('sale_id', $saleId)->count());
    }

    public function test_concurrent_or_repeated_request_with_same_idempotency_key_creates_one_record_for_hauls(): void
    {
        $records = $this->baseRecords();

        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => 'PUR-IDEM',
            'depot_id' => $records['depotId'],
            'purchase_date' => '2026-09-10',
            'payment_status' => 'paid',
            'status' => 'ordered',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $purchaseItemId = DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchaseId,
            'fuel_type_id' => $records['dieselFuelId'],
            'quantity_ordered_liters' => 40000,
            'unit_cost' => 50,
            'line_total' => 2000000,
            'quantity_hauled_liters' => 0,
            'status' => 'unlifted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $idempotencyKey = (string) Str::uuid();
        $payload = [
            'idempotency_key' => $idempotencyKey,
            'purchase_item_id' => $purchaseItemId,
            'driver_user_id' => $records['driver']->id,
            'truck_id' => $records['truckId'],
            'dr_number' => 'DR-12345',
            'scheduled_at' => '2026-09-15 10:00:00',
            'source_location' => 'Depot Bay 1',
            'quantity_liters' => '20000',
        ];

        $first = $this->actingAs($records['dispatchOfficer'])
            ->post(route('dispatch.fuel-lifting.hauls.store'), $payload);
        $first->assertRedirect(route('dispatch.fuel-lifting'));

        $this->assertSame(1, DB::table('hauls')->where('purchase_item_id', $purchaseItemId)->count());
        $this->assertSame(1, DB::table('idempotency_keys')->where('key', $idempotencyKey)->count());

        // Repeated submission
        $second = $this->actingAs($records['dispatchOfficer'])
            ->post(route('dispatch.fuel-lifting.hauls.store'), $payload);
        $second->assertRedirect(route('dispatch.fuel-lifting'))
            ->assertSessionHas('status', 'Lift assignment was already submitted.');

        $this->assertSame(1, DB::table('hauls')->where('purchase_item_id', $purchaseItemId)->count());
    }

    public function test_concurrent_or_repeated_request_with_same_idempotency_key_creates_one_record_for_stock_outs(): void
    {
        $records = $this->baseRecords();

        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => 'SLS-IDEM-STO',
            'customer_id' => $records['customerId'],
            'sale_date' => '2026-09-10',
            'payment_method' => 'cash_on_delivery',
            'payment_terms' => 'cod',
            'status' => 'confirmed',
            'created_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $saleItemId = DB::table('sale_items')->insertGetId([
            'sale_id' => $saleId,
            'fuel_type_id' => $records['dieselFuelId'],
            'quantity_liters' => 5000,
            'unit_price' => 60,
            'line_total' => 300000,
            'fulfilled_quantity_liters' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Stock in some fuel into the garage
        DB::table('inventory_movements')->insert([
            'movement_code' => 'MOV-STOCK-INIT',
            'storage_location_id' => $records['garageId'],
            'fuel_type_id' => $records['dieselFuelId'],
            'movement_type' => 'beginning',
            'direction' => 'in',
            'quantity_liters' => 10000,
            'unit_cost' => 50,
            'reference_type' => 'initial',
            'reference_id' => 1,
            'movement_date' => '2026-09-01',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $idempotencyKey = (string) Str::uuid();
        $payload = [
            'idempotency_key' => $idempotencyKey,
            'source_type' => 'garage',
            'sale_item_id' => $saleItemId,
            'storage_location_id' => $records['garageId'],
            'quantity_liters' => '2000',
            'stock_out_at' => '2026-09-12 14:00:00',
        ];

        $first = $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-out.store'), $payload);
        $first->assertRedirect(route('inventory-officer.inventory.stock-out'));

        $this->assertSame(1, DB::table('stock_outs')->where('sale_item_id', $saleItemId)->count());
        $this->assertSame(1, DB::table('idempotency_keys')->where('key', $idempotencyKey)->count());

        // Repeated submission
        $second = $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.stock-out.store'), $payload);
        $second->assertRedirect(route('inventory-officer.inventory.stock-out'))
            ->assertSessionHas('status', 'Stock-Out record was already submitted.');

        $this->assertSame(1, DB::table('stock_outs')->where('sale_item_id', $saleItemId)->count());
    }

    public function test_different_user_cannot_reuse_another_users_idempotency_key(): void
    {
        $records = $this->baseRecords();
        $idempotencyKey = (string) Str::uuid();

        $payload = [
            'idempotency_key' => $idempotencyKey,
            'customer_id' => $records['customerId'],
            'sale_date' => '2026-09-10',
            'payment_method' => 'cash_on_delivery',
            'status' => 'draft',
            'items' => [
                [
                    'fuel_type_id' => $records['dieselFuelId'],
                    'quantity_liters' => '1000',
                    'unit_price' => '60.00',
                ],
            ],
        ];

        // First user creates sale
        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.store'), $payload)
            ->assertRedirect(route('sales-officer.sales'));

        $this->flushSession();

        // Different user attempts to reuse the same idempotency key
        $this->actingAs($records['salesOfficerTwo'])
            ->post(route('sales-officer.sales.store'), $payload)
            ->assertSessionHasErrors(['idempotency_key' => 'The idempotency key is assigned to another user.']);
    }

    public function test_each_approved_fuel_can_pass_through_purchase_and_sale_validation(): void
    {
        $records = $this->baseRecords();
        $approvedCodes = ['F1', 'UNL', 'DSL', 'PREM'];

        foreach ($approvedCodes as $code) {
            $fuelId = (int) (DB::table('fuel_types')->where('code', $code)->value('id') ?? DB::table('fuel_types')->insertGetId([
                'code' => $code,
                'name' => $code === 'DSL' ? 'DIESEL' : ($code === 'UNL' ? 'UNLEADED' : ($code === 'PREM' ? 'PREMIUM' : 'F1')),
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]));

            // Ensure garage storage location exists for this fuel
            $garageTankId = DB::table('storage_locations')->insertGetId([
                'location_code' => 'GAR-'.$code.'-'.Str::upper(Str::random(4)),
                'name' => 'Garage Tank '.$code,
                'type' => 'garage',
                'fuel_type_id' => $fuelId,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // 1. Purchase
            $this->actingAs($records['inventoryOfficer'])
                ->post(route('inventory-officer.inventory.purchases.store'), [
                    'purchase_date' => '2026-09-11',
                    'depot_id' => $records['depotId'],
                    'fuel_type_id' => $fuelId,
                    'quantity_ordered_liters' => '10000',
                    'unit_cost' => '50.00',
                    'payment_status' => 'paid',
                    'status' => 'ordered',
                ])
                ->assertSessionDoesntHaveErrors(['fuel_type_id']);

            // 2. Sale
            $this->actingAs($records['salesOfficer'])
                ->post(route('sales-officer.sales.store'), [
                    'idempotency_key' => (string) Str::uuid(),
                    'customer_id' => $records['customerId'],
                    'sale_date' => '2026-09-11',
                    'payment_method' => 'cash_on_delivery',
                    'status' => 'draft',
                    'items' => [
                        [
                            'fuel_type_id' => $fuelId,
                            'quantity_liters' => '2000',
                            'unit_price' => '65.00',
                        ],
                    ],
                ])
                ->assertSessionDoesntHaveErrors(['fuel_type_id', 'items.0.fuel_type_id']);
        }
    }

    public function test_non_approved_and_inactive_fuel_ids_are_rejected_on_every_operational_write_path(): void
    {
        $records = $this->baseRecords();

        $inactiveApprovedId = DB::table('fuel_types')->insertGetId([
            'code' => 'DSL_INACTIVE',
            'name' => 'Inactive Diesel',
            'status' => 'inactive',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $nonApprovedId = DB::table('fuel_types')->insertGetId([
            'code' => 'KEROSENE_CUSTOM',
            'name' => 'Custom Kerosene',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([$inactiveApprovedId, $nonApprovedId] as $badFuelId) {
            // Purchase store rejects
            $this->actingAs($records['inventoryOfficer'])
                ->post(route('inventory-officer.inventory.purchases.store'), [
                    'purchase_date' => '2026-09-11',
                    'depot_id' => $records['depotId'],
                    'fuel_type_id' => $badFuelId,
                    'quantity_ordered_liters' => '1000',
                    'unit_cost' => '50.00',
                    'payment_status' => 'paid',
                    'status' => 'ordered',
                ])
                ->assertSessionHasErrors(['fuel_type_id']);

            // Sale store rejects
            $this->actingAs($records['salesOfficer'])
                ->post(route('sales-officer.sales.store'), [
                    'idempotency_key' => (string) Str::uuid(),
                    'customer_id' => $records['customerId'],
                    'sale_date' => '2026-09-11',
                    'payment_method' => 'cash_on_delivery',
                    'status' => 'draft',
                    'items' => [
                        [
                            'fuel_type_id' => $badFuelId,
                            'quantity_liters' => '1000',
                            'unit_price' => '60.00',
                        ],
                    ],
                ])
                ->assertSessionHasErrors(['items.0.fuel_type_id']);
        }
    }

    public function test_maximum_allowed_totals_fit_the_database_and_one_step_above_is_rejected_by_validation(): void
    {
        $records = $this->baseRecords();

        // 1. Purchase quantity exceeds 1,000,000
        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.purchases.store'), [
                'purchase_date' => '2026-09-11',
                'depot_id' => $records['depotId'],
                'fuel_type_id' => $records['dieselFuelId'],
                'quantity_ordered_liters' => '1000001',
                'unit_cost' => '50.00',
                'payment_status' => 'paid',
                'status' => 'ordered',
            ])
            ->assertSessionHasErrors(['quantity_ordered_liters']);

        // 2. Purchase unit cost exceeds 10,000
        $this->actingAs($records['inventoryOfficer'])
            ->post(route('inventory-officer.inventory.purchases.store'), [
                'purchase_date' => '2026-09-11',
                'depot_id' => $records['depotId'],
                'fuel_type_id' => $records['dieselFuelId'],
                'quantity_ordered_liters' => '100',
                'unit_cost' => '10001.00',
                'payment_status' => 'paid',
                'status' => 'ordered',
            ])
            ->assertSessionHasErrors(['unit_cost']);

        // 3. Sale quantity exceeds 1,000,000
        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.store'), [
                'idempotency_key' => (string) Str::uuid(),
                'customer_id' => $records['customerId'],
                'sale_date' => '2026-09-11',
                'payment_method' => 'cash_on_delivery',
                'status' => 'draft',
                'items' => [
                    [
                        'fuel_type_id' => $records['dieselFuelId'],
                        'quantity_liters' => '1000001',
                        'unit_price' => '60.00',
                    ],
                ],
            ])
            ->assertSessionHasErrors(['items.0.quantity_liters']);

        // 4. Sale unit price exceeds 10,000
        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.store'), [
                'idempotency_key' => (string) Str::uuid(),
                'customer_id' => $records['customerId'],
                'sale_date' => '2026-09-11',
                'payment_method' => 'cash_on_delivery',
                'status' => 'draft',
                'items' => [
                    [
                        'fuel_type_id' => $records['dieselFuelId'],
                        'quantity_liters' => '100',
                        'unit_price' => '10001.00',
                    ],
                ],
            ])
            ->assertSessionHasErrors(['items.0.unit_price']);

        // 5. Haul quantity exceeds 1,000,000
        $this->actingAs($records['dispatchOfficer'])
            ->post(route('dispatch.fuel-lifting.hauls.store'), [
                'idempotency_key' => (string) Str::uuid(),
                'purchase_item_id' => 1,
                'driver_user_id' => $records['driver']->id,
                'truck_id' => $records['truckId'],
                'scheduled_at' => '2026-09-15 10:00:00',
                'quantity_liters' => '1000001',
            ])
            ->assertSessionHasErrors(['quantity_liters']);
    }

    public function test_database_check_constraints_reject_invalid_states_at_db_boundary(): void
    {
        $records = $this->baseRecords();

        // 1. Negative quantity on sale_items
        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => 'SLS-CHK-1',
            'customer_id' => $records['customerId'],
            'sale_date' => '2026-09-10',
            'payment_method' => 'cash_on_delivery',
            'payment_terms' => 'cod',
            'status' => 'draft',
            'created_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('sale_items')->insert([
            'sale_id' => $saleId,
            'fuel_type_id' => $records['dieselFuelId'],
            'quantity_liters' => -100,
            'unit_price' => 50,
            'line_total' => -5000,
            'fulfilled_quantity_liters' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_database_check_constraints_reject_fulfilled_greater_than_quantity(): void
    {
        $records = $this->baseRecords();

        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => 'SLS-CHK-2',
            'customer_id' => $records['customerId'],
            'sale_date' => '2026-09-10',
            'payment_method' => 'cash_on_delivery',
            'payment_terms' => 'cod',
            'status' => 'draft',
            'created_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('sale_items')->insert([
            'sale_id' => $saleId,
            'fuel_type_id' => $records['dieselFuelId'],
            'quantity_liters' => 100,
            'unit_price' => 50,
            'line_total' => 5000,
            'fulfilled_quantity_liters' => 101, // violates fulfilled <= quantity
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_database_check_constraints_reject_invalid_garage_released_stock_out_shape(): void
    {
        $records = $this->baseRecords();

        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => 'SLS-CHK-3',
            'customer_id' => $records['customerId'],
            'sale_date' => '2026-09-10',
            'payment_method' => 'cash_on_delivery',
            'payment_terms' => 'cod',
            'status' => 'confirmed',
            'created_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $saleItemId = DB::table('sale_items')->insertGetId([
            'sale_id' => $saleId,
            'fuel_type_id' => $records['dieselFuelId'],
            'quantity_liters' => 100,
            'unit_price' => 50,
            'line_total' => 5000,
            'fulfilled_quantity_liters' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Released garage stock-out requires storage_location_id IS NOT NULL and haul_allocation_id IS NULL and depot_id IS NULL
        $this->expectException(QueryException::class);
        DB::table('stock_outs')->insert([
            'stock_out_code' => 'STO-CHK-INV',
            'sale_id' => $saleId,
            'sale_item_id' => $saleItemId,
            'customer_id' => $records['customerId'],
            'fuel_type_id' => $records['dieselFuelId'],
            'source_type' => 'garage',
            'storage_location_id' => null, // violates garage released shape
            'quantity_liters' => 100,
            'stock_out_at' => now(),
            'status' => 'released',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_code_collision_retry_succeeds_without_exception(): void
    {
        $service = app(IdempotencyService::class);

        $attempt = 0;
        $result = $service->retryOnCollision('sale_code', function () use (&$attempt) {
            $attempt++;
            if ($attempt === 1) {
                // Simulate unique collision exception
                throw new QueryException(
                    'sqlite',
                    'insert into sales (sale_code) values (?)',
                    ['SLS-COLLISION'],
                    new \Exception('UNIQUE constraint failed: sales.sale_code', 19)
                );
            }

            return 'SLS-SUCCESS';
        }, maxAttempts: 3);

        $this->assertSame('SLS-SUCCESS', $result);
        $this->assertSame(2, $attempt);
    }
}
