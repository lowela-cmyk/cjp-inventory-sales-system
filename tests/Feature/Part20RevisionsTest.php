<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DashboardInsightService;
use App\Services\IdempotencyService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\QueryException;
use Carbon\Carbon;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\PurchaseFlows;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Part 20 (November 10, 2026): Fuel Lifting Operations & Inventory module revisions.
 * 1. Driver withdrawal receipts per lift (upload, replace, remove, view in Ledger -> View Transactions).
 * 2. Sales -> Receivables: a visible Update Delivery action, Pending included.
 * 3. Inventory -> Purchases: one compact table rule set.
 * 4. Insights and A.I Reporting: overview dashboard and grouped bullet recommendations.
 * 5. Persistence and module exports.
 */
class Part20RevisionsTest extends AuditTestCase
{
    use PurchaseFlows;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 09:00:00');
        Storage::fake('local');
        Config::set('services.ai.api_key', '');
    }

    // ----- helpers ---------------------------------------------------------------------------------------------

    /**
     * Two lifts of one purchase line, each on its own truck and driver, both Lifted.
     *
     * @param  array<string, mixed>  $a
     * @return array{line: int, first: int, second: int, driver2: User, truck2: int}
     */
    private function twoLiftsOfOnePurchase(array $a): array
    {
        $line = $this->purchaseLine($a, 'DSL', 20000);
        $this->scheduleLift($a, [['purchase_item_id' => $line, 'quantity_liters' => 8000, 'atl_number' => 'ATL-A']])->assertSessionHasNoErrors();
        $first = $this->lastHaulId();
        $this->completeLift($a, $first);

        $driver2 = User::factory()->create(['role' => 'driver', 'status' => 'active', 'approval_status' => 'approved', 'name' => 'Second Driver']);
        DB::table('driver_profiles')->insert(['user_id' => $driver2->id, 'driver_code' => 'DRV-TWO', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        $truck2 = DB::table('trucks')->insertGetId(['truck_code' => 'TRK-TWO', 'name' => 'Isuzu Two', 'capacity_liters' => 30000, 'truck_type' => 'hauling', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
        $this->scheduleLift($a, [['purchase_item_id' => $line, 'quantity_liters' => 5000, 'atl_number' => 'ATL-B']], [], [
            'driver_user_id' => $driver2->id, 'truck_id' => $truck2, 'scheduled_at' => '2026-10-17 08:00:00',
        ])->assertSessionHasNoErrors();
        $second = $this->lastHaulId();
        foreach (['in_transit', 'lifted'] as $status) {
            $this->actingAs($driver2)->patch(route('driver.fuel-lifting.hauls.status', $second), ['idempotency_key' => (string) Str::uuid(), 'lifting_status' => $status])
                ->assertSessionHasNoErrors();
        }

        return ['line' => $line, 'first' => $first, 'second' => $second, 'driver2' => $driver2, 'truck2' => $truck2];
    }

    private function upload(User $user, int $haulId, string $route = 'driver.fuel-lifting.hauls.withdrawal-receipt.store', ?UploadedFile $file = null)
    {
        return $this->actingAs($user)->from(route($user->role === 'admin' ? 'admin.fuel-lifting' : 'driver.fuel-lifting'))
            ->post(route($route, $haulId), ['withdrawal_receipt' => $file ?? UploadedFile::fake()->image('receipt.jpg', 600, 800), 'withdrawal_notes' => 'Depot copy']);
    }

    /** @return array<string, mixed> */
    private function ledgerRow(User $user, int $purchaseItemId, string $route = 'inventory-officer.ledger.transactions'): array
    {
        return collect($this->actingAs($user)->get(route($route))->assertOk()->viewData('transactions'))
            ->firstWhere('purchase_item_id', $purchaseItemId);
    }

    // ----- 1. Driver withdrawal receipts ----------------------------------------------------------------------

    public function test_each_lift_of_one_purchase_keeps_its_own_receipt_driver_truck_date_and_quantity(): void
    {
        $a = $this->actors();
        $lifts = $this->twoLiftsOfOnePurchase($a);

        $this->upload($a['driver'], $lifts['first'])->assertSessionHasNoErrors()->assertRedirect(route('driver.fuel-lifting.hauled'));

        $haul = DB::table('hauls')->where('id', $lifts['first'])->first();
        $this->assertStringStartsWith('withdrawal-receipts/', $haul->withdrawal_receipt_path);
        Storage::disk('local')->assertExists($haul->withdrawal_receipt_path);
        $this->assertSame('Depot copy', $haul->withdrawal_receipt_notes);
        $this->assertSame((int) $a['driver']->id, (int) $haul->withdrawal_receipt_uploaded_by);
        $this->assertNotNull($haul->withdrawal_receipt_uploaded_at);
        // The other lift of the same purchase is untouched.
        $this->assertNull(DB::table('hauls')->where('id', $lifts['second'])->value('withdrawal_receipt_path'));

        $row = $this->ledgerRow($a['inventory'], $lifts['line']);
        $this->assertCount(2, $row['lifts']);
        [$first, $second] = [$row['lifts'][0], $row['lifts'][1]];
        $this->assertSame($a['driver']->name, $first['details']['Driver']);
        $this->assertSame('8,000.00 L', $first['quantity']);
        $this->assertNotNull($first['receipt']);
        $this->assertStringContainsString(route('withdrawal-receipts.show', $lifts['first']), $first['receipt']['url']);
        $this->assertSame('Second Driver', $second['details']['Driver']);
        $this->assertStringContainsString('Isuzu Two', $second['details']['Truck']);
        $this->assertSame('5,000.00 L', $second['quantity']);
        $this->assertNull($second['receipt']);
        $this->assertSame('Not uploaded', $second['details']['Receipt']);
        $this->assertSame(1, $row['receipt_count']);

        // The modal shows the receipt image to the Inventory Officer; Dispatch sees the status only (no file access).
        $page = $this->actingAs($a['inventory'])->get(route('inventory-officer.ledger.transactions'))->getContent();
        $this->assertStringContainsString('src="'.e($first['receipt']['url']).'"', $page);
        $this->assertStringContainsString('Withdrawal Receipt</span>', $page);
        $dispatchPage = $this->actingAs($a['dispatch'])->get(route('dispatch.ledger.transactions'))->assertOk()->getContent();
        $this->assertStringNotContainsString(route('withdrawal-receipts.show', $lifts['first']), $dispatchPage);
        $this->assertStringContainsString('Uploaded 11/10/2026', $dispatchPage);
    }

    public function test_receipt_files_are_served_only_to_authorized_users(): void
    {
        $a = $this->actors();
        $lifts = $this->twoLiftsOfOnePurchase($a);
        $this->upload($a['driver'], $lifts['first'])->assertSessionHasNoErrors();
        $url = route('withdrawal-receipts.show', $lifts['first']);

        $this->actingAs($a['inventory'])->get($url)->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->actingAs($a['admin'])->get($url)->assertOk();
        $this->actingAs($a['driver'])->get($url)->assertOk();
        $this->actingAs($lifts['driver2'])->get($url)->assertNotFound();
        $this->actingAs($a['dispatch'])->get($url)->assertForbidden();
        $this->actingAs($a['sales'])->get($url)->assertForbidden();
        auth()->logout();
        $this->get($url)->assertRedirect(route('login'));
    }

    public function test_upload_validation_and_lift_ownership_are_enforced(): void
    {
        $a = $this->actors();
        $lifts = $this->twoLiftsOfOnePurchase($a);

        // Only JPG, PNG and WEBP images up to 5 MB.
        $this->upload($a['driver'], $lifts['first'], file: UploadedFile::fake()->create('receipt.pdf', 20, 'application/pdf'))->assertSessionHasErrors('withdrawal_receipt');
        $this->upload($a['driver'], $lifts['first'], file: UploadedFile::fake()->image('big.png')->size(6000))->assertSessionHasErrors('withdrawal_receipt');
        $this->upload($a['driver'], $lifts['first'], file: UploadedFile::fake()->image('ok.webp'))->assertSessionHasNoErrors();
        $this->assertStringEndsWith('.webp', DB::table('hauls')->where('id', $lifts['first'])->value('withdrawal_receipt_path'));

        // Another driver's lift is refused, and so is a lift that is not Lifted yet.
        $this->upload($a['driver'], $lifts['second'])->assertSessionHasErrors('withdrawal_receipt');
        $this->assertNull(DB::table('hauls')->where('id', $lifts['second'])->value('withdrawal_receipt_path'));
        $this->actingAs($a['inventory'])->post(route('driver.fuel-lifting.hauls.withdrawal-receipt.store', $lifts['first']), ['withdrawal_receipt' => UploadedFile::fake()->image('r.jpg')])->assertForbidden();
    }

    public function test_replacing_a_receipt_swaps_the_file_and_removing_it_clears_the_reference(): void
    {
        $a = $this->actors();
        $lifts = $this->twoLiftsOfOnePurchase($a);
        $this->upload($a['driver'], $lifts['first'])->assertSessionHasNoErrors();
        $old = DB::table('hauls')->where('id', $lifts['first'])->value('withdrawal_receipt_path');

        // Replace (the AJAX form): a new file, the old one deleted.
        $this->actingAs($a['driver'])->postJson(route('driver.fuel-lifting.hauls.withdrawal-receipt.store', $lifts['first']), ['withdrawal_receipt' => UploadedFile::fake()->image('new.png')])
            ->assertOk()->assertJsonPath('message', 'Withdrawal receipt uploaded successfully.');
        $new = DB::table('hauls')->where('id', $lifts['first'])->value('withdrawal_receipt_path');
        $this->assertNotSame($old, $new);
        Storage::disk('local')->assertMissing($old);
        Storage::disk('local')->assertExists($new);

        // Another driver cannot remove it; the assigned driver can.
        $this->actingAs($lifts['driver2'])->from(route('driver.fuel-lifting'))->delete(route('driver.fuel-lifting.hauls.withdrawal-receipt.destroy', $lifts['first']))
            ->assertSessionHasErrors('withdrawal_receipt');
        Storage::disk('local')->assertExists($new);
        $this->actingAs($a['driver'])->get(route('driver.fuel-lifting'))->assertOk(); // the redirect that shows (and clears) the error
        $this->actingAs($a['driver'])->from(route('driver.fuel-lifting'))->delete(route('driver.fuel-lifting.hauls.withdrawal-receipt.destroy', $lifts['first']))
            ->assertSessionHasNoErrors()->assertRedirect(route('driver.fuel-lifting.hauled'));
        $haul = DB::table('hauls')->where('id', $lifts['first'])->first();
        $this->assertNull($haul->withdrawal_receipt_path);
        $this->assertNull($haul->withdrawal_receipt_uploaded_at);
        $this->assertNull($haul->withdrawal_receipt_uploaded_by);
        Storage::disk('local')->assertMissing($new);
        // The lift itself is kept as it was.
        $this->assertSame('lifted', $haul->status);
        $this->assertEquals(8000, $haul->quantity_liters);
        $this->assertDatabaseHas('activity_logs', ['route_name' => 'driver.fuel-lifting.hauls.withdrawal-receipt.destroy', 'user_id' => $a['driver']->id, 'succeeded' => true]);

        // Removing again says there is nothing to remove.
        $this->actingAs($a['driver'])->from(route('driver.fuel-lifting'))->delete(route('driver.fuel-lifting.hauls.withdrawal-receipt.destroy', $lifts['first']))
            ->assertSessionHasErrors(['withdrawal_receipt' => 'There is no withdrawal receipt to remove on this lift.']);

        // Admin uploads and removes on behalf of any driver.
        $this->upload($a['admin'], $lifts['second'], 'admin.fuel-lifting.hauls.withdrawal-receipt.store')->assertSessionHasNoErrors();
        $this->assertSame((int) $a['admin']->id, (int) DB::table('hauls')->where('id', $lifts['second'])->value('withdrawal_receipt_uploaded_by'));
        $this->actingAs($a['admin'])->from(route('admin.fuel-lifting'))->delete(route('admin.fuel-lifting.hauls.withdrawal-receipt.destroy', $lifts['second']))->assertSessionHasNoErrors();
        $this->assertNull(DB::table('hauls')->where('id', $lifts['second'])->value('withdrawal_receipt_path'));
        $this->actingAs($a['dispatch'])->delete(route('admin.fuel-lifting.hauls.withdrawal-receipt.destroy', $lifts['second']))->assertForbidden();
    }

    public function test_the_driver_modal_offers_remove_and_its_upload_form_stacks_on_phones(): void
    {
        $a = $this->actors();
        $lifts = $this->twoLiftsOfOnePurchase($a);
        $this->upload($a['driver'], $lifts['first'])->assertSessionHasNoErrors();

        $page = $this->actingAs($a['driver'])->get(route('driver.fuel-lifting.hauled'))->assertOk()->getContent();
        $this->assertStringContainsString('action="'.route('driver.fuel-lifting.hauls.withdrawal-receipt.destroy', $lifts['first']).'"', $page);
        $this->assertStringContainsString('Remove Receipt</button>', $page);

        // The screenshot bug: ".modal-actions form" made the receipt form inline-flex and the phone rule squeezed it.
        $css = File::get(resource_path('css/app.css'));
        $responsive = File::get(resource_path('css/responsive.css'));
        $this->assertStringContainsString(".modal-actions > form.withdrawal-upload-form {\n    display: grid;", $css);
        $this->assertStringContainsString('.modal-body .modal-actions > form.withdrawal-upload-form {', $responsive);
        $this->assertStringContainsString('form:has(select, input[type="file"]):not(.withdrawal-upload-form) {', $responsive);
    }

    // ----- 2. Sales delivery status ---------------------------------------------------------------------------

    public function test_a_pending_sale_has_a_visible_update_delivery_action_that_persists_and_keeps_payments(): void
    {
        $a = $this->actors();
        $line = $this->purchaseLine($a, 'DSL', 10000);
        $this->recordOrder($a, ['purchase_item_id' => $line, 'quantity_liters' => 1000, 'unit_price' => 70])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $modal = 'so-sales-edit-'.$saleId.'-delivery';

        $page = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->assertOk()->getContent();
        $this->assertStringContainsString('data-modal-open="'.$modal.'">Update Delivery</button>', $page);
        $this->assertStringContainsString('id="'.$modal.'"', $page);
        $receivableBefore = DB::table('receivables')->where('sale_id', $saleId)->first();
        $saleBefore = DB::table('sales')->where('id', $saleId)->first(['status']);

        $key = (string) Str::uuid();
        $send = fn (string $status, string $key) => $this->actingAs($a['sales'])->from(route('sales-officer.sales'))
            ->patch(route('sales-officer.sales.delivery-status.update', $saleId), ['delivery_status' => $status, 'idempotency_key' => $key, 'delivery_update_sale_id' => $saleId]);

        $send('out_for_delivery', $key)->assertSessionHasNoErrors()->assertSessionHas('status', 'Delivery status updated successfully.');
        $sale = DB::table('sales')->where('id', $saleId)->first();
        $this->assertSame('out_for_delivery', $sale->delivery_status);
        $this->assertSame((int) $a['sales']->id, (int) $sale->delivery_status_updated_by);
        $this->assertSame('2026-11-10 09:00:00', Carbon::parse($sale->delivery_status_updated_at)->format('Y-m-d H:i:s'));
        $this->assertSame(0, DB::table('stock_outs')->where('sale_id', $saleId)->count());

        // The same submission again (double click / resend) saves nothing more.
        $send('out_for_delivery', $key)->assertSessionHas('status', 'This delivery update was already saved.');

        // After a refresh the row shows the new status and offers Delivered next.
        $row = collect($this->actingAs($a['sales'])->get(route('sales-officer.sales'))->viewData('sales')->items())->firstWhere('id', $saleId);
        $this->assertSame('out_for_delivery', $row['delivery_status']);
        $this->assertSame(['pending', 'delivered'], $row['delivery_update']['options']);
        $this->assertStringContainsString($a['sales']->name, $row['delivery_update']['last_update']);

        $send('delivered', (string) Str::uuid())->assertSessionHasNoErrors();
        $this->assertSame('delivered', DB::table('sales')->where('id', $saleId)->value('delivery_status'));
        $this->assertEquals(1000, DB::table('stock_outs')->where('sale_id', $saleId)->sum('quantity_liters'));

        // Payment status, receivable and totals are unchanged by the delivery steps.
        $receivableAfter = DB::table('receivables')->where('sale_id', $saleId)->first();
        $this->assertEquals($receivableBefore->status, $receivableAfter->status);
        $this->assertEquals($receivableBefore->due_date, $receivableAfter->due_date);
        $this->assertSame($saleBefore->status, DB::table('sales')->where('id', $saleId)->value('status'));
        $this->assertSame(0, DB::table('payments')->where('sale_id', $saleId)->count());

        // Delivered is final: no more action on the row, and the server refuses a step back.
        $row = collect($this->actingAs($a['sales'])->get(route('sales-officer.sales'))->viewData('sales')->items())->firstWhere('id', $saleId);
        $this->assertFalse($row['delivery_update']['available']);
        $send('pending', (string) Str::uuid())->assertSessionHasErrors(['delivery_status' => 'A delivered sale cannot be moved back because its inventory was already released.']);
    }

    public function test_delivery_updates_are_authorized_and_validated(): void
    {
        $a = $this->actors();
        $line = $this->purchaseLine($a, 'DSL', 10000);
        $this->recordOrder($a, ['purchase_item_id' => $line, 'quantity_liters' => 1000, 'unit_price' => 70])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();

        foreach (['inventory', 'dispatch', 'driver'] as $role) {
            $this->actingAs($a[$role])->patch(route('sales-officer.sales.delivery-status.update', $saleId), ['delivery_status' => 'out_for_delivery'])->assertForbidden();
        }
        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.delivery-status.update', $saleId), ['delivery_status' => 'shipped'])
            ->assertSessionHasErrors('delivery_status');
        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.delivery-status.update', $saleId), ['delivery_status' => 'cancelled'])
            ->assertSessionHasErrors(['delivery_status' => 'Use the Cancel action to preserve and reconcile the sale audit history.']);
        $this->assertSame('pending', DB::table('sales')->where('id', $saleId)->value('delivery_status'));

        // Admin works the same action.
        $this->actingAs($a['admin'])->from(route('admin.sales'))->patch(route('admin.sales.delivery-status.update', $saleId), ['delivery_status' => 'out_for_delivery'])
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.sales'));
        $this->assertSame((int) $a['admin']->id, (int) DB::table('sales')->where('id', $saleId)->value('delivery_status_updated_by'));
        $this->assertStringContainsString('data-modal-open="so-sales-edit-'.$saleId.'-delivery">Update Delivery</button>', $this->actingAs($a['admin'])->get(route('admin.sales'))->getContent());
    }

    public function test_a_depot_to_client_order_moves_out_for_delivery_by_hand_and_delivers_once_its_lift_is_lifted(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 2000, 'unit_price' => 70], ['delivery_type' => 'depot_to_client'])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $row = fn (): array => collect($this->actingAs($a['sales'])->get(route('sales-officer.sales'))->viewData('sales')->items())->firstWhere('id', $saleId);

        // The screenshot case: a Pending Depot to Client order now has Update Delivery (Out for Delivery only for now).
        $this->assertTrue($row()['delivery_update']['available']);
        $this->assertSame(['out_for_delivery'], $row()['delivery_update']['options']);
        $this->assertStringContainsString('marked Lifted', $row()['delivery_update']['note']);
        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.delivery-status.update', $saleId), ['delivery_status' => 'out_for_delivery'])
            ->assertSessionHasNoErrors();
        $this->assertSame('out_for_delivery', DB::table('sales')->where('id', $saleId)->value('delivery_status'));

        // Lifted: Delivered is offered and releases the stock through the lift, once.
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 2000, 'atl_number' => 'ATL-1']], [$saleId])->assertSessionHasNoErrors();
        $haulId = $this->lastHaulId();
        $this->actingAs($a['driver'])->patch(route('driver.fuel-lifting.hauls.status', $haulId), ['idempotency_key' => (string) Str::uuid(), 'lifting_status' => 'in_transit'])->assertSessionHasNoErrors();
        $this->assertSame(['pending'], $row()['delivery_update']['options']);
        $this->actingAs($a['driver'])->patch(route('driver.fuel-lifting.hauls.status', $haulId), ['idempotency_key' => (string) Str::uuid(), 'lifting_status' => 'lifted'])->assertSessionHasNoErrors();
        // Completing the lift delivered it (as before Part 20); nothing is released twice.
        $this->assertSame('delivered', DB::table('sales')->where('id', $saleId)->value('delivery_status'));
        $this->assertEquals(2000, DB::table('stock_outs')->where('sale_id', $saleId)->sum('quantity_liters'));
        $this->assertFalse($row()['delivery_update']['available']);
    }

    // ----- 3. Purchases table ---------------------------------------------------------------------------------

    public function test_the_purchases_table_has_one_compact_rule_set_and_keeps_lift_details_out_of_its_columns(): void
    {
        $a = $this->actors();
        $this->purchaseLine($a, 'DSL', 10000);
        $css = File::get(resource_path('css/app.css'));

        $this->assertSame(1, substr_count($css, 'Part 20 #3: Inventory -> Purchases table, one compact rule set.'));
        // The old layered blocks are gone.
        foreach (['min-width: 1465px', 'Purchases final cascade fix', 'Final Purchases table guardrails', 'Purchases (November 2, 2026): roomier cells'] as $old) {
            $this->assertStringNotContainsString($old, $css);
        }
        $block = substr($css, strpos($css, 'Part 20 #3: Inventory -> Purchases table'));
        $block = substr($block, 0, strpos($block, "\n}\n") + 2);
        $this->assertStringNotContainsString('!important', $block);
        $this->assertStringContainsString('@media (min-width: 768px)', $block);
        $this->assertStringContainsString('padding: 8px 5px;', $block);
        $this->assertMatchesRegularExpression('/:nth-child\(5\), :nth-child\(6\), :nth-child\(7\)\)\s*\{\s*text-align: right;/', $block);
        $this->assertStringContainsString('tr.row-danger > td', $block);
        // No other stylesheet rule targets the Purchases table any more.
        $this->assertSame(substr_count($css, '.purchases-table'), substr_count($block, '.purchases-table'));

        $page = $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory'))->assertOk()->getContent();
        $table = substr($page, strpos($page, 'purchases-table'), 3000);
        foreach (['Remaining Fuel', 'Driver', 'Truck', 'Inventory Status'] as $hidden) {
            $this->assertStringNotContainsString('<th>'.$hidden, $table);
        }
        $this->assertStringContainsString('<th>Date</th>', $table);
    }

    // ----- 4. AI insights -------------------------------------------------------------------------------------

    public function test_the_insight_report_is_an_overview_dashboard_with_grouped_bullet_recommendations_from_the_records(): void
    {
        $a = $this->actors();
        $lifts = $this->twoLiftsOfOnePurchase($a);
        $this->upload($a['driver'], $lifts['first'])->assertSessionHasNoErrors();
        $this->recordOrder($a, ['purchase_item_id' => $lifts['line'], 'quantity_liters' => 1000, 'unit_price' => 70])->assertSessionHasNoErrors();

        $this->actingAs($a['admin'])->post(route('admin.dashboard.insight'))->assertSessionHas('dashboardInsight.source', 'rules');
        $insight = session('dashboardInsight');
        $this->assertSame(['Inventory Overview', 'Sales and Receivables Overview', 'Fuel Lifting Overview', 'Operational Insights'], array_column($insight['sections'], 'title'));
        $metric = fn (string $section, string $label): ?string => collect(collect($insight['sections'])->firstWhere('title', $section)['items'])->firstWhere('label', $label)['value'] ?? null;
        // Figures come from the database: 2 lifted lines, 1 without a receipt; 1 order pending delivery.
        $this->assertSame('1', $metric('Fuel Lifting Overview', 'Lifted lines without receipt'));
        $this->assertSame('1', $metric('Operational Insights', 'Orders pending delivery'));
        $this->assertSame('1', $metric('Sales and Receivables Overview', 'Sales'));
        $this->assertSame(1, app(DashboardInsightService::class)->operations()['lifted_lines_without_withdrawal_receipt']);

        $groups = $insight['recommendations'];
        $this->assertSame(array_values(array_intersect(DashboardInsightService::GROUPS, array_keys($groups))), array_keys($groups));
        $this->assertContains('Ask drivers to upload the **withdrawal receipt** for **1 lifted load** without one.', $groups['Fuel Lifting']);
        $this->assertContains('Move the **1 pending order** to **Out for Delivery** as their trucks leave.', $groups['Operational Improvements']);
        foreach ($groups as $bullets) {
            foreach ($bullets as $bullet) {
                $this->assertStringNotContainsString("\n", $bullet);
                $this->assertMatchesRegularExpression('/\*\*[^*]+\*\*/', $bullet);
            }
            $this->assertSame(count($bullets), count(array_unique($bullets)));
        }

        $page = $this->actingAs($a['admin'])->get(route('admin.dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('<h3 id="ai-report-recommendations-title">AI Recommendations</h3>', $page);
        $this->assertStringContainsString('<h4>Fuel Lifting</h4>', $page);
        $this->assertStringContainsString('<li>Ask drivers to upload the <strong>withdrawal receipt</strong> for <strong>1 lifted load</strong> without one.</li>', $page);
        $this->assertStringContainsString('<h3 id="ai-report-0">Inventory Overview</h3>', $page);
        $this->assertStringContainsString('Regenerate Insight and Reports', $page);
        $this->assertStringContainsString('data-loading-label="Generating…"', $page);
        // The PDF export gets the plain-text report with the bullets.
        $this->assertStringContainsString('- Ask drivers to upload the withdrawal receipt for 1 lifted load without one.', $insight['report']);
    }

    public function test_ai_text_is_parsed_into_grouped_bullets_and_rendered_safely(): void
    {
        $a = $this->actors();
        $this->purchaseLine($a, 'DSL', 10000);
        Config::set('services.ai.provider', 'groq');
        Config::set('services.ai.model', 'openai/gpt-oss-20b');
        Config::set('services.ai.api_key', 'test-key');
        Config::set('services.ai.base_url', 'https://api.groq.com/openai/v1');
        $answer = "Here is the report.\n### Inventory\n- Restock **Diesel** soon <script>alert(1)</script>\n- Restock **Diesel** soon <script>alert(1)</script>\n"
            ."### Fuel Lifting\nSchedule the **10.00 KL** still at the depot. Assign a driver this week.\n## Summary\n* Keep **receipts** complete";
        Http::fake(['api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => $answer]]]])]);

        $this->actingAs($a['admin'])->post(route('admin.dashboard.insight'))->assertSessionHas('dashboardInsight.source', 'ai');
        $groups = session('dashboardInsight.recommendations');
        // Repeats are dropped; a paragraph becomes one bullet per sentence; headings outside the groups add nothing.
        $this->assertSame(['Restock **Diesel** soon alert(1)'], $groups['Inventory']);
        $this->assertSame(['Schedule the **10.00 KL** still at the depot.', 'Assign a driver this week.', 'Keep **receipts** complete'], $groups['Fuel Lifting']);
        $this->assertArrayNotHasKey('Operational Improvements', $groups);
        Http::assertSent(fn (HttpRequest $request): bool => str_contains(json_encode($request->data()), 'One concise, actionable recommendation per bullet'));

        // Whatever the AI writes is escaped; only the bold markers become tags.
        $html = (string) DashboardInsightService::bulletHtml('Check **<img src=x onerror=alert(1)>** & <b>this</b>');
        $this->assertSame('Check <strong>&lt;img src=x onerror=alert(1)&gt;</strong> &amp; &lt;b&gt;this&lt;/b&gt;', $html);
        $page = $this->actingAs($a['admin'])->get(route('admin.dashboard'))->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $page);
        $this->assertStringContainsString('<li>Restock <strong>Diesel</strong> soon alert(1)</li>', $page);
    }

    public function test_an_unusable_ai_answer_falls_back_to_rule_based_bullets_and_states_are_shown(): void
    {
        $a = $this->actors();
        // Empty state before anything is generated.
        $this->assertStringContainsString('No report generated yet', $this->actingAs($a['admin'])->get(route('admin.dashboard'))->getContent());
        // No data.
        $this->actingAs($a['admin'])->post(route('admin.dashboard.insight'));
        $this->assertSame([], session('dashboardInsight.sections'));
        $this->assertStringContainsString('No data to report yet', $this->actingAs($a['admin'])->get(route('admin.dashboard'))->getContent());

        $this->purchaseLine($a, 'DSL', 10000);
        Config::set('services.ai.api_key', 'test-key');
        Config::set('services.ai.provider', 'groq');
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '###']]]])]);
        $this->actingAs($a['admin'])->post(route('admin.dashboard.insight'))->assertSessionHas('dashboardInsight.source', 'rules');
        $this->assertNotEmpty(session('dashboardInsight.recommendations'));
    }

    // ----- 5. Exports -----------------------------------------------------------------------------------------

    public function test_module_exports_carry_lift_receipts_and_delivery_updates_and_respect_filters(): void
    {
        $a = $this->actors();
        $lifts = $this->twoLiftsOfOnePurchase($a);
        $this->upload($a['driver'], $lifts['first'])->assertSessionHasNoErrors();
        $other = $this->purchaseLine($a, 'PREM', 4000);

        // Ledger -> Transaction export: each lift with its truck, driver and receipt status (no file URLs).
        $page = $this->actingAs($a['inventory'])->get(route('inventory-officer.ledger.transactions'))->getContent();
        $this->assertStringContainsString('<th class="export-only" hidden>Lifts (Truck, Driver, Receipt)</th>', $page);
        $row = $this->ledgerRow($a['inventory'], $lifts['line']);
        $this->assertStringContainsString('Driver: '.$a['driver']->name.', Receipt: Uploaded 11/10/2026', $row['lift_export']);
        $this->assertStringContainsString('Driver: Second Driver, Receipt: Not uploaded', $row['lift_export']);
        $this->assertStringNotContainsString('withdrawal-receipts', $row['lift_export']);
        $this->assertStringContainsString('<td class="export-only" hidden>1 of 2 uploaded</td>', $page);

        // Filters narrow the exported rows (the export reads the filtered table).
        $filtered = collect($this->actingAs($a['inventory'])->get(route('inventory-officer.ledger.transactions', ['fuel_type_id' => $this->fuel('PREM')]))->viewData('transactions'));
        $this->assertSame([$other], $filtered->pluck('purchase_item_id')->all());

        // Sales export: the delivery status column and who last updated it.
        $this->recordOrder($a, ['purchase_item_id' => $lifts['line'], 'quantity_liters' => 500, 'unit_price' => 70])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.delivery-status.update', $saleId), ['delivery_status' => 'out_for_delivery'])->assertSessionHasNoErrors();
        $sales = $this->actingAs($a['sales'])->get(route('sales-officer.sales'))->getContent();
        $this->assertStringContainsString('<th class="export-only" hidden>Delivery Status Updated</th>', $sales);
        $this->assertStringContainsString('<td class="export-only" hidden>'.e($a['sales']->name).' · 11/10/2026', $sales);
    }

    // ----- Final fixes: AI safeguards, error state, PDF, delivery rules, Larastan fixes ---------------------------

    private function configureFakeAi(string $answer): void
    {
        Config::set('services.ai.provider', 'groq');
        Config::set('services.ai.model', 'openai/gpt-oss-20b');
        Config::set('services.ai.api_key', 'test-key');
        Config::set('services.ai.base_url', 'https://api.groq.com/openai/v1');
        Config::set('services.ai.cache_ttl', 0);
        Http::fake(['api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => $answer]]]])]);
    }

    public function test_ai_bullets_citing_figures_not_in_the_dashboard_data_are_dropped(): void
    {
        $a = $this->actors();
        $this->purchaseLine($a, 'DSL', 10000, 50);
        Log::spy();
        // 10,000 L purchased at PHP 50: 10.00 KL at the depot, PHP 500,000.00 stock value. 99.99 KL and PHP 123,456.00 are invented.
        $this->configureFakeAi("### Inventory\n- Stock is **99.99 KL**, record more purchases\n- Lift the **10.00 KL** still at the depots\n"
            ."### Sales\n- Collect **PHP 123,456.00** from customers\n### Operational Improvements\n- Review the **3-month** trend before buying");

        $this->actingAs($a['admin'])->post(route('admin.dashboard.insight'))->assertSessionHas('dashboardInsight.source', 'ai');
        $groups = session('dashboardInsight.recommendations');
        $this->assertSame(['Lift the **10.00 KL** still at the depots'], $groups['Inventory']);
        $this->assertArrayNotHasKey('Sales', $groups);
        $this->assertSame(['Review the **3-month** trend before buying'], $groups['Operational Improvements']);
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'citing figures not in the dashboard data'))->twice();
        // The overview figures are the database ones, whatever the AI wrote.
        $stock = collect(collect(session('dashboardInsight.sections'))->firstWhere('title', 'Fuel Lifting Overview')['items'])->firstWhere('label', 'Still at the depots')['value'];
        $this->assertSame('10.00 KL', $stock);
    }

    public function test_an_ai_answer_made_only_of_invented_figures_falls_back_to_the_rule_based_bullets(): void
    {
        $a = $this->actors();
        $this->purchaseLine($a, 'DSL', 10000, 50);
        $this->configureFakeAi("### Inventory\n- Stock is **88.88 KL**\n### Sales\n- Revenue grew **42.42%**");

        $this->actingAs($a['admin'])->post(route('admin.dashboard.insight'))->assertSessionHas('dashboardInsight.source', 'rules');
        $this->assertSame(['Schedule lifts for **1 unlifted purchase line**; **10.00 KL** is still at the depots.'], session('dashboardInsight.recommendations.Fuel Lifting'));
        $this->assertStringNotContainsString('88.88', json_encode(session('dashboardInsight')));
    }

    public function test_a_failing_report_shows_the_error_state_and_keeps_the_generate_button(): void
    {
        $a = $this->actors();
        $this->mock(DashboardInsightService::class, fn ($mock) => $mock->shouldReceive('generate')->andThrow(new \RuntimeException('database is down')));
        Log::spy();

        $this->actingAs($a['admin'])->post(route('admin.dashboard.insight'))
            ->assertRedirect(route('admin.dashboard').'#insights')
            ->assertSessionHasErrors(['insight' => 'The insight report could not be generated. Please try again.']);
        Log::shouldHaveReceived('error')->withArgs(fn (string $message): bool => str_contains($message, 'database is down'))->once();
        $page = $this->actingAs($a['admin'])->get(route('admin.dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('The insight report could not be generated. Please try again.', $page);
        $this->assertStringNotContainsString('database is down', $page);
        $this->assertStringContainsString('Generate Insight and Reports</button>', $page);
    }

    public function test_the_pdf_export_carries_the_overview_and_the_bullet_recommendations(): void
    {
        $a = $this->actors();
        $lifts = $this->twoLiftsOfOnePurchase($a);
        $this->actingAs($a['admin'])->post(route('admin.dashboard.insight'));
        $report = session('dashboardInsight.report');
        $this->assertStringContainsString("Fuel Lifting Overview\n  Purchases / lifts: 1 / 2", $report);
        $this->assertStringContainsString("Fuel Lifting\n  - Lift the remaining 7.00 KL still at the depots.\n  - Complete the 1 partially lifted purchase line.\n  - Ask drivers to upload the withdrawal receipt for 2 lifted loads without one.", $report);
        $this->assertStringContainsString("AI Recommendations\nInventory\n  - Premium has no stock", $report);
        $this->assertStringNotContainsString('**', $report);

        Pdf::shouldReceive('loadView')->once()->withArgs(function (string $view, array $data) use ($report): bool {
            $html = view($view, $data)->render();
            $this->assertStringContainsString('<div class="insight">'.e($report).'</div>', $html);

            return true;
        })->andReturnSelf();
        Pdf::shouldReceive('setPaper')->andReturnSelf();
        Pdf::shouldReceive('download')->andReturn(response('%PDF', 200, ['Content-Type' => 'application/pdf']));
        // dashboard.js posts the plain-text report (data-insight-raw) as insight_text.
        $page = $this->actingAs($a['admin'])->get(route('admin.dashboard'))->getContent();
        $this->assertStringContainsString('data-insight-raw="'.e($report).'"', $page);
        $this->actingAs($a['admin'])->post(route('admin.dashboard.export'), ['insight_text' => $report, 'insight_source' => 'Summary generated from current dashboard data.'])
            ->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertNotNull($lifts['first']);
    }

    public function test_delivered_waits_for_a_lifted_lift_never_deducts_twice_and_never_touches_payments(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 2000, 'unit_price' => 70], ['delivery_type' => 'depot_to_client'])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->post(route('sales-officer.sales.payments.store', $saleId), [
            'idempotency_key' => (string) Str::uuid(), 'payment_sale_id' => $saleId, 'amount' => 50000, 'method' => 'cash_on_delivery', 'payment_date' => '2026-11-10',
        ])->assertSessionHasNoErrors();
        $this->assertEquals(50000, DB::table('payments')->where('sale_id', $saleId)->sum('amount'));
        $money = fn (): array => [
            (float) DB::table('payments')->where('sale_id', $saleId)->sum('amount'),
            DB::table('receivables')->where('sale_id', $saleId)->value('status'),
            DB::table('sales')->where('id', $saleId)->value('status'),
            (float) DB::table('sale_items')->where('sale_id', $saleId)->sum('line_total'),
        ];
        $before = $money();
        $refused = 'This Depot to Client sale is delivered through its lift. Add it to a lift in Fuel Lifting and complete the lift first.';
        $deliver = fn () => $this->actingAs($a['sales'])->from(route('sales-officer.sales'))
            ->patch(route('sales-officer.sales.delivery-status.update', $saleId), ['delivery_status' => 'delivered', 'idempotency_key' => (string) Str::uuid()]);

        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 2000, 'atl_number' => 'ATL-9']], [$saleId])->assertSessionHasNoErrors();
        $haulId = $this->lastHaulId();
        $deliver()->assertSessionHasErrors(['delivery_status' => $refused]);       // Scheduled
        $this->actingAs($a['driver'])->patch(route('driver.fuel-lifting.hauls.status', $haulId), ['idempotency_key' => (string) Str::uuid(), 'lifting_status' => 'in_transit'])->assertSessionHasNoErrors();
        $deliver()->assertSessionHasErrors(['delivery_status' => $refused]);       // In Transit
        $this->assertSame(0, DB::table('stock_outs')->where('sale_id', $saleId)->count());
        $this->assertSame(20000.0, $this->remaining($premium));

        // Lifted: the lift delivers it; a manual Delivered afterwards changes nothing.
        $this->actingAs($a['driver'])->patch(route('driver.fuel-lifting.hauls.status', $haulId), ['idempotency_key' => (string) Str::uuid(), 'lifting_status' => 'lifted'])->assertSessionHasNoErrors();
        $this->assertSame('delivered', DB::table('sales')->where('id', $saleId)->value('delivery_status'));
        $deliver()->assertSessionHasNoErrors();
        $deliver()->assertSessionHasNoErrors();
        $this->assertEquals(2000, DB::table('stock_outs')->where('sale_id', $saleId)->sum('quantity_liters'));
        $this->assertSame(18000.0, $this->remaining($premium));
        $this->assertSame($before, $money());
        $this->assertDatabaseHas('activity_logs', ['route_name' => 'sales-officer.sales.delivery-status.update', 'user_id' => $a['sales']->id, 'succeeded' => false]);
    }

    public function test_a_database_error_while_recording_a_sale_shows_the_friendly_message_not_the_sql(): void
    {
        $a = $this->actors();
        $line = $this->purchaseLine($a, 'DSL', 10000);
        // QueryException extends RuntimeException; it used to be caught as one and its SQL shown to the user.
        $this->mock(IdempotencyService::class, fn ($mock) => $mock->shouldReceive('run')
            ->andThrow(new QueryException('mysql', 'insert into sales (secret_column) values (?)', [1], new \Exception('SQLSTATE[23000] secret detail'))));

        $this->recordOrder($a, ['purchase_item_id' => $line, 'quantity_liters' => 100, 'unit_price' => 70])
            ->assertSessionHasErrors(['sale' => 'Sale record could not be saved. Please review the details and try again.']);
        $this->assertStringNotContainsString('secret', (string) session('errors')->first('sale'));
    }
}
