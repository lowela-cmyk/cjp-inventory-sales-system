<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SalesOfficerSalesManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_out_is_created_only_when_sale_is_delivered_and_repeated_delivery_is_idempotent(): void
    {
        $records = $this->baseRecords();
        $beforeInventory = DB::table('inventory_movements')->count();

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.store'), $this->salePayload($records, [
                'sale_code' => 'SLS-REAL',
                'sales_order_number' => 'SO-CJP-1001',
                'quantity_liters' => '1500.25',
                'unit_price' => '62.50',
                'payment_method' => 'cheque',
                'line_total' => '1.00',
            ]))
            ->assertSessionHasErrors('line_total');

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.store'), $this->salePayload($records, [
                'sale_code' => 'SLS-REAL',
                'sales_order_number' => 'SO-CJP-1001',
                'quantity_liters' => '1500.25',
                'unit_price' => '62.50',
                'payment_method' => 'cheque',
            ]))
            ->assertRedirect(route('sales-officer.sales'));

        $sale = DB::table('sales')->where('sales_order_number', 'SO-CJP-1001')->first();
        $this->assertNotNull($sale);
        $this->assertMatchesRegularExpression('/^SLS-\d{6}-[A-Z0-9]{5}$/', $sale->sale_code);
        $this->assertSame($records['salesOfficer']->id, (int) $sale->created_by);
        $this->assertSame('SO-CJP-1001', $sale->sales_order_number);
        $this->assertSame('cheque', $sale->payment_method);
        $this->assertSame('confirmed', $sale->status);
        $this->assertSame('pending', $sale->delivery_status);

        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->id,
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_liters' => '1500.25',
            'unit_price' => '62.50',
            'line_total' => '93765.63',
        ]);
        $this->assertDatabaseHas('receivables', [
            'sale_id' => $sale->id,
            'status' => 'pending',
        ]);
        $this->assertSame($beforeInventory, DB::table('inventory_movements')->count());
        $this->assertSame(0, DB::table('stock_outs')->where('sale_id', $sale->id)->count());

        $this->actingAs($records['salesOfficer'])
            ->patch(route('sales-officer.sales.delivery-status.update', $sale->id), ['delivery_status' => 'out_for_delivery'])
            ->assertRedirect(route('sales-officer.sales'));

        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'delivery_status' => 'out_for_delivery']);
        $this->assertSame($beforeInventory, DB::table('inventory_movements')->count());
        $this->assertSame(0, DB::table('stock_outs')->where('sale_id', $sale->id)->count());

        $this->actingAs($records['salesOfficer'])
            ->patch(route('sales-officer.sales.delivery-status.update', $sale->id), ['delivery_status' => 'delivered'])
            ->assertRedirect(route('sales-officer.sales'));

        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'delivery_status' => 'delivered']);
        $this->assertSame($beforeInventory + 1, DB::table('inventory_movements')->count());
        $this->assertSame(1, DB::table('stock_outs')->where('sale_id', $sale->id)->where('source_type', 'garage')->count());
        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->id,
            'fulfilled_quantity_liters' => '1500.25',
        ]);

        $this->actingAs($records['salesOfficer'])
            ->patch(route('sales-officer.sales.delivery-status.update', $sale->id), ['delivery_status' => 'delivered'])
            ->assertRedirect(route('sales-officer.sales'));

        $this->assertSame($beforeInventory + 1, DB::table('inventory_movements')->count());
        $this->assertSame(1, DB::table('stock_outs')->where('sale_id', $sale->id)->count());
        $this->assertSame(0, DB::table('payments')->count());
    }

    public function test_order_id_is_generated_server_side_and_not_exposed_in_create_forms(): void
    {
        $records = $this->baseRecords();
        $approvedFuelTypeId = (int) DB::table('fuel_types')
            ->where('code', 'DSL')
            ->where('status', 'active')
            ->value('id');
        $this->assertGreaterThan(0, $approvedFuelTypeId);

        foreach (['SO-AUTO-ONE', 'SO-AUTO-TWO'] as $salesOrderNumber) {
            $this->actingAs($records['salesOfficer'])
                ->post(route('sales-officer.sales.store'), $this->salePayload($records, [
                    'sale_code' => 'CLIENT-SUPPLIED-ID',
                    'sales_order_number' => $salesOrderNumber,
                    'fuel_type_id' => $approvedFuelTypeId,
                ]))
                ->assertRedirect(route('sales-officer.sales'));
        }

        $sales = DB::table('sales')
            ->whereIn('sales_order_number', ['SO-AUTO-ONE', 'SO-AUTO-TWO'])
            ->orderBy('id')
            ->get(['sale_code', 'sales_order_number']);

        $this->assertCount(2, $sales);
        $this->assertSame(['SO-AUTO-ONE', 'SO-AUTO-TWO'], $sales->pluck('sales_order_number')->all());
        $this->assertSame(2, $sales->pluck('sale_code')->unique()->count());
        $this->assertNotContains('CLIENT-SUPPLIED-ID', $sales->pluck('sale_code')->all());
        foreach ($sales as $sale) {
            $this->assertMatchesRegularExpression('/^SLS-\d{6}-[A-Z0-9]{5}$/', $sale->sale_code);
        }

        $this->actingAs($records['salesOfficer'])
            ->get(route('sales-officer.sales'))
            ->assertOk()
            ->assertDontSee('name="sale_code"', false)
            ->assertSee('name="sales_order_number"', false)
            ->assertSee($sales->first()->sale_code);

        $this->actingAs($records['admin'])
            ->get(route('admin.sales'))
            ->assertOk()
            ->assertDontSee('name="sale_code"', false)
            ->assertSee('name="sales_order_number"', false)
            ->assertSee($sales->first()->sale_code);
    }

    public function test_delivery_stays_pending_and_creates_no_stock_out_when_inventory_is_insufficient(): void
    {
        $records = $this->baseRecords();
        $beforeInventory = DB::table('inventory_movements')->count();

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.store'), $this->salePayload($records, [
                'sale_code' => 'SLS-PREPARED-STOCK-OUT',
                'sales_order_number' => 'SO-PREPARED-STOCK-OUT',
                'quantity_liters' => '15000',
                'unit_price' => '62.50',
            ]))
            ->assertRedirect(route('sales-officer.sales'));

        $sale = DB::table('sales')->where('sales_order_number', 'SO-PREPARED-STOCK-OUT')->first();
        $saleItem = DB::table('sale_items')->where('sale_id', $sale->id)->first();
        $this->assertSame(0, DB::table('stock_outs')->where('sale_id', $sale->id)->count());

        $this->actingAs($records['salesOfficer'])
            ->patch(route('sales-officer.sales.delivery-status.update', $sale->id), ['delivery_status' => 'delivered'])
            ->assertSessionHasErrors('delivery_status');

        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'delivery_status' => 'pending']);
        $this->assertSame(0, DB::table('stock_outs')->where('sale_id', $sale->id)->count());
        $this->assertSame($beforeInventory, DB::table('inventory_movements')->count());
        $this->assertDatabaseHas('sale_items', [
            'id' => $saleItem->id,
            'fulfilled_quantity_liters' => '0.00',
        ]);
    }

    public function test_sales_order_number_is_unique_and_payment_method_does_not_record_payment(): void
    {
        $records = $this->baseRecords();
        $this->createSale($records, [
            'sale_code' => 'SLS-SO-EXISTING',
            'sales_order_number' => 'SO-CJP-DUP',
        ]);

        $this->actingAs($records['salesOfficer'])
            ->from(route('sales-officer.sales'))
            ->post(route('sales-officer.sales.store'), $this->salePayload($records, [
                'sale_code' => 'SLS-SO-DUP',
                'sales_order_number' => 'SO-CJP-DUP',
                'payment_method' => 'advance_payment',
            ]))
            ->assertRedirect(route('sales-officer.sales'))
            ->assertSessionHasErrors('sales_order_number');

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.store'), $this->salePayload($records, [
                'sale_code' => 'SLS-SO-METHOD',
                'sales_order_number' => 'SO-CJP-1002',
                'payment_method' => 'advance_payment',
            ]))
            ->assertRedirect(route('sales-officer.sales'));

        $sale = DB::table('sales')->where('sales_order_number', 'SO-CJP-1002')->first();
        $this->assertNotNull($sale);
        $this->assertSame('advance_payment', $sale->payment_method);
        $this->assertSame(0, DB::table('payments')->where('sale_id', $sale->id)->count());
        $this->assertDatabaseHas('receivables', [
            'sale_id' => $sale->id,
            'status' => 'pending',
        ]);
    }

    public function test_admin_can_create_and_update_sales_order_number_and_payment_method(): void
    {
        $records = $this->baseRecords();

        $this->actingAs($records['admin'])
            ->post(route('admin.sales.store'), $this->salePayload($records, [
                'sale_code' => 'SLS-ADMIN',
                'sales_order_number' => 'SO-CJP-ADMIN',
                'payment_method' => 'bank_transfer',
            ]))
            ->assertRedirect(route('admin.sales'));

        $sale = DB::table('sales')->where('sales_order_number', 'SO-CJP-ADMIN')->first();
        $this->assertNotNull($sale);
        $this->assertSame('SO-CJP-ADMIN', $sale->sales_order_number);
        $this->assertSame('bank_transfer', $sale->payment_method);

        $this->actingAs($records['admin'])
            ->patch(route('admin.sales.update', $sale->id), $this->salePayload($records, [
                'sales_order_number' => 'SO-CJP-ADMIN-EDIT',
                'payment_method' => 'cheque',
            ]))
            ->assertRedirect(route('admin.sales'));

        $this->assertDatabaseHas('sales', [
            'id' => $sale->id,
            'sales_order_number' => 'SO-CJP-ADMIN-EDIT',
            'payment_method' => 'cheque',
        ]);
    }

    public function test_sales_officer_can_view_real_sales_and_admin_can_monitor_them(): void
    {
        $records = $this->baseRecords();
        $this->createSale($records, ['sale_code' => 'SLS-VISIBLE']);

        $this->actingAs($records['salesOfficer'])
            ->get(route('sales-officer.sales'))
            ->assertOk()
            ->assertSee('SLS-VISIBLE')
            ->assertSee('Sales Customer')
            ->assertSee('Diesel')
            ->assertDontSee('Jay P. Calinisan');

        $this->actingAs($records['admin'])
            ->get(route('admin.sales'))
            ->assertOk()
            ->assertSee('SLS-VISIBLE')
            ->assertSee('Sales Company');
    }

    public function test_sale_validation_rejects_invalid_customer_fuel_quantity_price_and_method(): void
    {
        $records = $this->baseRecords();
        $inactiveCustomerId = $this->customer(['status' => 'inactive']);
        $inactiveFuelId = $this->fuelType(['status' => 'inactive']);

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.store'), $this->salePayload($records, [
                'customer_id' => $inactiveCustomerId,
                'fuel_type_id' => $inactiveFuelId,
                'quantity_liters' => '0',
                'unit_price' => '-1',
                'payment_method' => 'crypto',
                'status' => 'shipped',
            ]))
            ->assertSessionHasErrors(['customer_id', 'fuel_type_id', 'quantity_liters', 'unit_price', 'payment_method', 'status']);

        $this->assertSame(0, DB::table('sales')->count());
        $this->assertSame(0, DB::table('sale_items')->count());
    }

    public function test_multiple_sale_items_are_stored_under_one_sale_and_totaled_server_side(): void
    {
        $records = $this->baseRecords();
        $gasolineId = $this->fuelType(['code' => 'UNL', 'name' => 'UNLEADED']);

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.store'), $this->salePayload($records, [
                'sale_code' => 'SLS-MULTI',
                'sales_order_number' => 'SO-CJP-MULTI',
                'items' => [
                    ['fuel_type_id' => $records['fuelTypeId'], 'quantity_liters' => '5000', 'unit_price' => '60'],
                    ['fuel_type_id' => $gasolineId, 'quantity_liters' => '3000', 'unit_price' => '65.50'],
                ],
            ]))
            ->assertRedirect(route('sales-officer.sales'));

        $sale = DB::table('sales')->where('sales_order_number', 'SO-CJP-MULTI')->first();
        $this->assertNotNull($sale);
        $this->assertSame(2, DB::table('sale_items')->where('sale_id', $sale->id)->count());
        $this->assertDatabaseHas('sale_items', ['sale_id' => $sale->id, 'line_total' => '300000.00']);
        $this->assertDatabaseHas('sale_items', ['sale_id' => $sale->id, 'line_total' => '196500.00']);
    }

    public function test_duplicate_submission_token_does_not_create_second_sale(): void
    {
        $records = $this->baseRecords();
        $payload = $this->salePayload($records, [
            'idempotency_key' => (string) Str::uuid(),
            'sale_code' => null,
            'sales_order_number' => null,
        ]);

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.store'), $payload)
            ->assertRedirect(route('sales-officer.sales'));

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.store'), $payload)
            ->assertRedirect(route('sales-officer.sales'));

        $this->assertSame(1, DB::table('sales')->count());
        $this->assertSame(1, DB::table('sale_items')->count());
    }

    public function test_sale_can_be_edited_before_dependent_activity_and_recalculates_total(): void
    {
        $records = $this->baseRecords();
        $saleId = $this->createSale($records, ['sale_code' => 'SLS-EDIT']);

        $this->actingAs($records['salesOfficer'])
            ->patch(route('sales-officer.sales.update', $saleId), $this->salePayload($records, [
                'quantity_liters' => '2000',
                'unit_price' => '61.25',
                'payment_method' => 'bank_transfer',
            ]))
            ->assertRedirect(route('sales-officer.sales'));

        $this->assertDatabaseHas('sales', [
            'id' => $saleId,
            'payment_method' => 'bank_transfer',
        ]);
        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $saleId,
            'quantity_liters' => '2000.00',
            'unit_price' => '61.25',
            'line_total' => '122500.00',
        ]);
    }

    public function test_unsafe_sale_edit_is_blocked_and_cancel_preserves_dependent_payment_history(): void
    {
        $records = $this->baseRecords();
        $saleId = $this->createSale($records, ['sale_code' => 'SLS-LOCKED']);
        DB::table('payments')->insert([
            'payment_code' => 'PAY-LOCKED',
            'sale_id' => $saleId,
            'payment_date' => '2026-08-30',
            'amount' => 100,
            'method' => 'cash_on_delivery',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($records['salesOfficer'])
            ->patch(route('sales-officer.sales.update', $saleId), $this->salePayload($records, [
                'quantity_liters' => '9999',
            ]))
            ->assertSessionHasErrors('sale');

        $this->actingAs($records['salesOfficer'])
            ->patch(route('sales-officer.sales.cancel', $saleId))
            ->assertRedirect(route('sales-officer.sales'));

        $this->assertDatabaseHas('sales', [
            'id' => $saleId,
            'status' => 'cancelled',
            'cancelled_by' => $records['salesOfficer']->id,
        ]);
        $this->assertDatabaseHas('payments', ['sale_id' => $saleId, 'payment_code' => 'PAY-LOCKED']);
        $this->assertDatabaseHas('receivables', ['sale_id' => $saleId, 'status' => 'clear']);
        $this->assertDatabaseMissing('sale_items', ['sale_id' => $saleId, 'quantity_liters' => '9999.00']);
    }

    public function test_untouched_draft_sale_is_hard_deleted_with_generated_children(): void
    {
        $records = $this->baseRecords();
        $saleId = $this->createSale($records, [
            'sale_code' => 'SLS-DRAFT-DELETE',
            'status' => 'draft',
        ]);

        $this->actingAs($records['salesOfficer'])
            ->get(route('sales-officer.sales'))
            ->assertOk()
            ->assertSee('Delete this untouched draft sale?')
            ->assertSee('>Delete<', false);

        $this->actingAs($records['salesOfficer'])
            ->patch(route('sales-officer.sales.cancel', $saleId))
            ->assertRedirect(route('sales-officer.sales'))
            ->assertSessionHas('status', 'Untouched draft sale deleted successfully.');

        $this->assertDatabaseMissing('sales', ['id' => $saleId]);
        $this->assertDatabaseMissing('sale_items', ['sale_id' => $saleId]);
        $this->assertDatabaseMissing('receivables', ['sale_id' => $saleId]);
    }

    public function test_cancelled_sale_voids_stock_out_effect_without_deleting_inventory_history(): void
    {
        $records = $this->baseRecords();

        $this->actingAs($records['salesOfficer'])
            ->post(route('sales-officer.sales.store'), $this->salePayload($records, [
                'sales_order_number' => 'SO-CANCEL-STOCK',
                'quantity_liters' => '1000',
                'unit_price' => '60',
            ]))
            ->assertRedirect(route('sales-officer.sales'));

        $sale = DB::table('sales')->where('sales_order_number', 'SO-CANCEL-STOCK')->first();
        $this->actingAs($records['salesOfficer'])
            ->patch(route('sales-officer.sales.delivery-status.update', $sale->id), ['delivery_status' => 'delivered'])
            ->assertRedirect(route('sales-officer.sales'));
        $stockOut = DB::table('stock_outs')->where('sale_id', $sale->id)->first();
        $this->assertNotNull($stockOut);
        $this->assertSame('released', $stockOut->status);
        $this->assertNotNull($stockOut->inventory_movement_id);

        $this->actingAs($records['salesOfficer'])
            ->patch(route('sales-officer.sales.cancel', $sale->id))
            ->assertRedirect(route('sales-officer.sales'));

        $this->assertDatabaseHas('stock_outs', ['id' => $stockOut->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('inventory_movements', ['id' => $stockOut->inventory_movement_id]);
        $this->assertDatabaseHas('sale_items', [
            'sale_id' => $sale->id,
            'fulfilled_quantity_liters' => '0.00',
        ]);
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('sales', ['id' => $sale->id, 'delivery_status' => 'cancelled']);
        $this->assertDatabaseHas('receivables', ['sale_id' => $sale->id, 'status' => 'clear']);
    }

    public function test_admin_can_cancel_sale_through_admin_route(): void
    {
        $records = $this->baseRecords();
        $saleId = $this->createSale($records, ['sale_code' => 'SLS-ADMIN-CANCEL']);

        $this->actingAs($records['admin'])
            ->get(route('admin.sales'))
            ->assertOk()
            ->assertSee('Related payments and transaction history will be preserved')
            ->assertSee('>Cancel<', false);

        $this->actingAs($records['admin'])
            ->patch(route('admin.sales.cancel', $saleId), ['reason' => 'Incorrect customer order'])
            ->assertRedirect(route('admin.sales'));

        $this->assertDatabaseHas('sales', [
            'id' => $saleId,
            'status' => 'cancelled',
            'cancelled_by' => $records['admin']->id,
            'cancellation_reason' => 'Incorrect customer order',
        ]);

        $this->get(route('admin.sales'))
            ->assertOk()
            ->assertSee('Cancelled');
    }

    public function test_admin_can_update_delivery_status_but_unrelated_roles_cannot(): void
    {
        $records = $this->baseRecords();
        $saleId = $this->createSale($records, ['sale_code' => 'SLS-ADMIN-DELIVERY']);

        $this->actingAs($records['admin'])
            ->patch(route('admin.sales.delivery-status.update', $saleId), ['delivery_status' => 'out_for_delivery'])
            ->assertRedirect(route('admin.sales'));

        $this->assertDatabaseHas('sales', [
            'id' => $saleId,
            'delivery_status' => 'out_for_delivery',
            'delivery_status_updated_by' => $records['admin']->id,
        ]);
        $this->assertSame(0, DB::table('stock_outs')->where('sale_id', $saleId)->count());

        foreach (['inventory_officer', 'dispatch_officer', 'driver'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);

            $this->actingAs($user)
                ->patch(route('admin.sales.delivery-status.update', $saleId), ['delivery_status' => 'delivered'])
                ->assertForbidden();

            $this->actingAs($user)
                ->patch(route('sales-officer.sales.delivery-status.update', $saleId), ['delivery_status' => 'delivered'])
                ->assertForbidden();
        }

        $this->assertSame(0, DB::table('stock_outs')->where('sale_id', $saleId)->count());
    }

    public function test_paid_or_fulfilled_sale_cannot_be_changed_to_cancelled_via_edit_route(): void
    {
        $records = $this->baseRecords();
        $saleId = $this->createSale($records, ['sale_code' => 'SLS-PAID-NO-CANCEL']);
        DB::table('payments')->insert([
            'payment_code' => 'PAY-PAID-NO-CANCEL',
            'sale_id' => $saleId,
            'payment_date' => '2026-08-30',
            'amount' => 60000,
            'method' => 'cash_on_delivery',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('receivables')->where('sale_id', $saleId)->update(['status' => 'clear']);

        $this->actingAs($records['salesOfficer'])
            ->patch(route('sales-officer.sales.update', $saleId), $this->salePayload($records, [
                'status' => 'cancelled',
            ]))
            ->assertSessionHasErrors('sale');

        $this->assertDatabaseMissing('sales', ['id' => $saleId, 'status' => 'cancelled']);
        $this->assertDatabaseMissing('receivables', ['sale_id' => $saleId, 'status' => 'unpaid']);
    }

    public function test_confirming_draft_does_not_release_stock_until_delivery_is_marked_delivered(): void
    {
        $records = $this->baseRecords();
        $saleId = $this->createSale($records, [
            'sale_code' => 'SLS-DRAFT-CONFIRM',
            'status' => 'draft',
        ]);
        $saleItem = DB::table('sale_items')->where('sale_id', $saleId)->first();
        $this->assertNotNull($saleItem);
        $this->assertSame(0, DB::table('stock_outs')->where('sale_id', $saleId)->count());

        $this->actingAs($records['salesOfficer'])
            ->patch(route('sales-officer.sales.update', $saleId), $this->salePayload($records, [
                'status' => 'confirmed',
            ]))
            ->assertRedirect(route('sales-officer.sales'));

        $this->assertDatabaseHas('sales', ['id' => $saleId, 'status' => 'confirmed']);
        $this->assertSame(0, DB::table('stock_outs')->where('sale_id', $saleId)->count());

        $this->actingAs($records['salesOfficer'])
            ->patch(route('sales-officer.sales.delivery-status.update', $saleId), ['delivery_status' => 'delivered'])
            ->assertRedirect(route('sales-officer.sales'));

        $this->assertSame(1, DB::table('stock_outs')->where('sale_id', $saleId)->where('status', 'released')->count());
        $this->assertDatabaseHas('sale_items', [
            'id' => $saleItem->id,
            'fulfilled_quantity_liters' => '1000.00',
        ]);
    }

    public function test_cancellation_preserves_sale_history_without_deleting_records(): void
    {
        $records = $this->baseRecords();
        $saleId = $this->createSale($records, ['sale_code' => 'SLS-CANCEL']);

        $this->actingAs($records['salesOfficer'])
            ->patch(route('sales-officer.sales.cancel', $saleId))
            ->assertRedirect(route('sales-officer.sales'));

        $this->assertDatabaseHas('sales', ['id' => $saleId, 'status' => 'cancelled']);
        $this->assertDatabaseHas('sale_items', ['sale_id' => $saleId]);
    }

    public function test_non_sales_roles_cannot_manage_sales(): void
    {
        $records = $this->baseRecords();
        $saleId = $this->createSale($records);

        foreach (['admin', 'inventory_officer', 'dispatch_officer', 'driver'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);

            $this->actingAs($user)
                ->post(route('sales-officer.sales.store'), $this->salePayload($records))
                ->assertForbidden();

            $this->actingAs($user)
                ->patch(route('sales-officer.sales.update', $saleId), $this->salePayload($records))
                ->assertForbidden();

            $this->actingAs($user)
                ->patch(route('sales-officer.sales.cancel', $saleId))
                ->assertForbidden();
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function salePayload(array $records, array $overrides = []): array
    {
        $payload = [
            'idempotency_key' => (string) Str::uuid(),
            'sales_order_number' => 'SO-'.Str::upper(Str::random(8)),
            'customer_id' => $records['customerId'],
            'sale_date' => '2026-08-30',
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_liters' => '1000',
            'unit_price' => '60',
            'payment_method' => 'cash_on_delivery',
            'payment_terms' => 'cod',
            'status' => 'confirmed',
        ];

        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($payload[$key]);
            } else {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $saleOverrides
     */
    private function createSale(array $records, array $saleOverrides = []): int
    {
        $saleId = DB::table('sales')->insertGetId(array_merge([
            'sale_code' => 'SLS-'.Str::upper(Str::random(8)),
            'customer_id' => $records['customerId'],
            'sale_date' => '2026-08-30',
            'payment_method' => 'cash_on_delivery',
            'payment_terms' => 'cod',
            'status' => 'confirmed',
            'created_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], $saleOverrides));

        DB::table('sale_items')->insert([
            'sale_id' => $saleId,
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_liters' => 1000,
            'unit_price' => 60,
            'line_total' => 60000,
            'fulfilled_quantity_liters' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('receivables')->insert([
            'sale_id' => $saleId,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $saleId;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function customer(array $overrides = []): int
    {
        return DB::table('customers')->insertGetId(array_merge([
            'customer_code' => 'CSM-'.Str::upper(Str::random(8)),
            'name' => 'Sales Customer',
            'company_name' => 'Sales Company',
            'location' => 'Nasugbu, Batangas',
            'email' => Str::random(8).'@example.com',
            'phone' => '09171234567',
            'payment_status' => 'clear',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function fuelType(array $overrides = []): int
    {
        if (isset($overrides['status']) && $overrides['status'] === 'inactive') {
            return DB::table('fuel_types')->insertGetId(array_merge([
                'code' => 'INACTIVE-'.Str::upper(Str::random(4)),
                'name' => 'Inactive Fuel',
                'status' => 'inactive',
                'created_at' => now(),
                'updated_at' => now(),
            ], $overrides));
        }

        $code = $overrides['code'] ?? 'DSL';
        $name = $overrides['name'] ?? ($code === 'DSL' ? 'DIESEL' : ($code === 'UNL' ? 'UNLEADED' : $code));

        $existing = DB::table('fuel_types')->where('code', $code)->first();
        if ($existing && empty(array_diff_key($overrides, ['code' => true, 'name' => true]))) {
            return (int) $existing->id;
        }

        return DB::table('fuel_types')->insertGetId(array_merge([
            'code' => $code,
            'name' => $name,
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
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $customerId = $this->customer();
        $fuelTypeId = $this->fuelType();
        $storageLocationId = DB::table('storage_locations')->insertGetId([
            'location_code' => 'GAR-'.Str::upper(Str::random(8)),
            'name' => 'Sales Garage',
            'type' => 'garage',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('inventory_movements')->insert([
            'movement_code' => 'MOV-'.Str::upper(Str::random(8)),
            'storage_location_id' => $storageLocationId,
            'fuel_type_id' => $fuelTypeId,
            'movement_type' => 'beginning',
            'direction' => 'in',
            'quantity_liters' => 10000,
            'unit_cost' => 50,
            'reference_type' => 'test',
            'reference_id' => 1,
            'movement_date' => now(),
            'created_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('salesOfficer', 'admin', 'customerId', 'fuelTypeId', 'storageLocationId');
    }
}
