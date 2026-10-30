<?php

namespace Tests\Feature;

use App\Services\RecordCodeService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PurchaseFlows;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Part 15 #6 (October 30, 2026): Purchase, Order and Lift IDs are PREFIX-FUEL-NUMBER, numbered per fuel, never reused;
 * existing records are renumbered by a migration that keeps the old codes findable and can be rolled back.
 */
class Part15RecordCodesTest extends AuditTestCase
{
    use PurchaseFlows;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-14 09:00:00');
    }

    public function test_new_ids_follow_prefix_fuel_number_and_count_per_fuel(): void
    {
        $a = $this->actors();
        $premium = $this->purchaseLine($a, 'PREM', 20000);
        $premiumTwo = $this->purchaseLine($a, 'PREM', 20000);
        $diesel = $this->purchaseLine($a, 'DSL', 20000);
        $f1 = $this->purchaseLine($a, 'F1', 20000);
        $this->assertSame(['PUR-PREM-001', 'PUR-PREM-002', 'PUR-DSL-001', 'PUR-F1-001'], $this->purchaseCodes([$premium, $premiumTwo, $diesel, $f1]));

        // An order takes its first line's main fuel; an F1 portion does not change it.
        $this->recordOrder($a, ['purchase_item_id' => $premium, 'quantity_liters' => 1000, 'unit_price' => 60, 'f1_percentage' => 10, 'f1_purchase_item_id' => $f1, 'f1_unit_price' => 40])->assertSessionHasNoErrors();
        $this->recordOrder($a, ['purchase_item_id' => $diesel, 'quantity_liters' => 1000, 'unit_price' => 60])->assertSessionHasNoErrors();
        $this->recordOrder($a, ['purchase_item_id' => $premiumTwo, 'quantity_liters' => 1000, 'unit_price' => 60])->assertSessionHasNoErrors();
        $this->assertSame(['SLS-PREM-001', 'SLS-DSL-001', 'SLS-PREM-002'], DB::table('sales')->orderBy('id')->pluck('sale_code')->all());

        // A lift takes its main fuel (F1 rides along); haul lines follow the lift ID.
        $trucks = collect(range(1, 3))->map(fn (int $n): int => DB::table('trucks')->insertGetId(['truck_code' => 'TRK-C'.$n, 'capacity_liters' => 40000, 'truck_type' => 'hauling', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]));
        $this->scheduleLift($a, [['purchase_item_id' => $premium, 'quantity_liters' => 5000]], [], ['truck_id' => $trucks[0]])->assertSessionHasNoErrors();
        $this->scheduleLift($a, [['purchase_item_id' => $f1, 'quantity_liters' => 2000], ['purchase_item_id' => $premiumTwo, 'quantity_liters' => 5000]], [], ['truck_id' => $trucks[1]])->assertSessionHasNoErrors();
        $this->scheduleLift($a, [['purchase_item_id' => $f1, 'quantity_liters' => 1000]], [], ['truck_id' => $trucks[2]])->assertSessionHasNoErrors();
        $this->assertSame(['LFT-PREM-001', 'LFT-PREM-002', 'LFT-F1-001'], DB::table('lifting_schedules')->orderBy('id')->pluck('schedule_code')->all());
        $this->assertSame(['LFT-PREM-001-01', 'LFT-PREM-002-01', 'LFT-PREM-002-02', 'LFT-F1-001-01'], DB::table('hauls')->orderBy('id')->pluck('haul_code')->all());

        // The number keeps growing past 999.
        DB::table('code_sequences')->where('prefix', 'PUR')->where('fuel_code', 'PREM')->update(['last_number' => 999]);
        $this->assertSame(['PUR-PREM-1000'], $this->purchaseCodes([$this->purchaseLine($a, 'PREM', 1000)]));
        $this->assertSame(DB::table('purchases')->count(), DB::table('purchases')->distinct()->count('purchase_code'));
    }

    public function test_a_fuel_change_gives_the_next_id_of_the_new_fuel_and_never_reuses_the_old_number(): void
    {
        $a = $this->actors();
        $pending = $this->purchaseLine($a, 'PREM', 10000);
        $this->assertSame(['PUR-PREM-001'], $this->purchaseCodes([$pending]));

        $this->actingAs($a['inventory'])->from(route('inventory-officer.inventory'))->patch(route('inventory-officer.inventory.purchases.update', $pending), [
            'purchase_date' => '2026-10-14', 'depot_id' => $a['depot'], 'fuel_type_id' => $this->fuel('DSL'), 'quantity_ordered_liters' => 10000, 'unit_cost' => 50, 'payment_status' => 'unpaid', 'payment_mode' => 'cash',
        ])->assertSessionHasNoErrors()->assertSessionHas('status', 'Purchase record updated successfully. New Purchase ID: PUR-DSL-001.');
        $this->assertSame(['PUR-DSL-001'], $this->purchaseCodes([$pending]));
        $this->assertDatabaseHas('code_renumbering', ['entity' => 'purchase', 'old_code' => 'PUR-PREM-001', 'new_code' => 'PUR-DSL-001', 'reason' => 'fuel_change']);
        // PUR-PREM-001 is not given out again.
        $this->assertSame(['PUR-PREM-002'], $this->purchaseCodes([$this->purchaseLine($a, 'PREM', 10000)]));

        // An order nothing depends on yet: a new first-line fuel gives it the next Order ID of that fuel.
        $unleaded = $this->purchaseLine($a, 'UNL', 10000);
        $this->recordOrder($a, ['purchase_item_id' => $unleaded, 'quantity_liters' => 1000, 'unit_price' => 60])->assertSessionHasNoErrors();
        $saleId = $this->lastSaleId();
        $this->assertSame('SLS-UNL-001', DB::table('sales')->where('id', $saleId)->value('sale_code'));
        $this->actingAs($a['sales'])->from(route('sales-officer.sales'))->patch(route('sales-officer.sales.update', $saleId), [
            'edit_sale_id' => $saleId, 'delivery_type' => 'standard', 'customer_id' => $a['customer'], 'sale_date' => '2026-10-15',
            'items' => [['purchase_item_id' => $pending, 'quantity_liters' => 1000, 'unit_price' => 60]],
            'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment',
        ])->assertSessionHasNoErrors()->assertSessionHas('status', 'Sale record updated successfully. New Order ID: SLS-DSL-001.');
        $this->assertDatabaseHas('code_renumbering', ['entity' => 'sale', 'old_code' => 'SLS-UNL-001', 'new_code' => 'SLS-DSL-001']);
    }

    public function test_search_boxes_and_old_links_find_a_record_by_its_old_code(): void
    {
        $a = $this->actors();
        $item = $this->purchaseLine($a, 'PREM', 10000);
        $purchaseId = (int) DB::table('purchase_items')->where('id', $item)->value('purchase_id');
        DB::table('code_renumbering')->insert(['entity' => 'purchase', 'record_id' => $purchaseId, 'old_code' => 'PUR-261030-MQOBW', 'new_code' => 'PUR-PREM-001', 'reason' => 'renumber', 'created_at' => now()]);
        $this->purchaseLine($a, 'DSL', 10000);

        foreach (['PUR-261030-MQOBW', 'pur-261030-mqobw', 'MQOBW'] as $term) {
            $page = $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory', ['search' => $term]))->assertOk();
            $codes = collect($page->viewData('purchases')->items())->pluck('purchase_code')->all();
            $this->assertSame(['PUR-PREM-001'], $codes, $term);
            $page->assertSee(strtoupper($term).' is now PUR-PREM-001.');
        }

        // An old alert link (?search=&highlight=) and the dashboard search work the same way.
        $this->actingAs($a['admin'])->get(route('admin.inventory', ['search' => 'PUR-261030-MQOBW', 'highlight' => 'PUR-261030-MQOBW']))
            ->assertOk()->assertSee('PUR-PREM-001');
        $this->actingAs($a['admin'])->get(route('admin.dashboard', ['inv_search' => 'PUR-261030-MQOBW']))->assertOk()->assertSee('PUR-261030-MQOBW is now PUR-PREM-001.');

        // A term that matches a current code is left alone.
        $page = $this->actingAs($a['inventory'])->get(route('inventory-officer.inventory', ['search' => 'PUR-DSL']))->assertOk();
        $this->assertSame(['PUR-DSL-001'], collect($page->viewData('purchases')->items())->pluck('purchase_code')->all());
        $page->assertDontSee(' is now ');
    }

    public function test_the_renumbering_migration_renumbers_by_date_updates_quoted_codes_and_rolls_back(): void
    {
        $a = $this->actors();
        $prem = $this->fuel('PREM');
        $f1 = $this->fuel('F1');
        $purchase = function (string $code, string $date, int $fuel) use ($a): array {
            $id = DB::table('purchases')->insertGetId(['purchase_code' => $code, 'depot_id' => $a['depot'], 'purchase_date' => $date, 'payment_status' => 'unpaid', 'status' => 'ordered', 'workflow_status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            $item = DB::table('purchase_items')->insertGetId(['purchase_id' => $id, 'fuel_type_id' => $fuel, 'quantity_ordered_liters' => 10000, 'unit_cost' => 50, 'line_total' => 500000, 'quantity_hauled_liters' => 0, 'status' => 'unlifted', 'created_at' => now(), 'updated_at' => now()]);

            return [$id, $item];
        };
        // Inserted out of date order, in the old formats.
        [$later] = $purchase('PUR-261030-MQOBW', '2026-10-30', $prem);
        [$earlier, $earlierItem] = $purchase('PUR-ACC-PREM-001', '2026-09-10', $prem);
        [$f1Purchase, $f1Item] = $purchase('PUR-261026-ZZZZZ', '2026-10-26', $f1);
        $sale = DB::table('sales')->insertGetId(['sale_code' => 'SLS-261026-PUIU7', 'customer_id' => $a['customer'], 'sale_date' => '2026-10-26', 'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment', 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sale_items')->insert(['sale_id' => $sale, 'fuel_type_id' => $prem, 'purchase_item_id' => $earlierItem, 'quantity_liters' => 100, 'main_quantity_liters' => 100, 'unit_price' => 60, 'line_total' => 6000, 'fulfilled_quantity_liters' => 0, 'created_at' => now(), 'updated_at' => now()]);
        $schedule = DB::table('lifting_schedules')->insertGetId(['schedule_code' => 'LFT-261026-CIWMS', 'depot_id' => $a['depot'], 'truck_id' => $a['truck'], 'driver_user_id' => $a['driver']->id, 'scheduled_at' => '2026-10-26 08:00:00', 'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[$f1Purchase, $f1Item, $f1, '01'], [$earlier, $earlierItem, $prem, '02']] as [$purchaseId, $itemId, $fuel, $line]) {
            DB::table('hauls')->insert(['lifting_schedule_id' => $schedule, 'haul_code' => 'LFT-261026-CIWMS-'.$line, 'purchase_id' => $purchaseId, 'purchase_item_id' => $itemId, 'depot_id' => $a['depot'], 'fuel_type_id' => $fuel, 'truck_id' => $a['truck'], 'driver_user_id' => $a['driver']->id, 'scheduled_at' => '2026-10-26 08:00:00', 'quantity_liters' => 1000, 'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()]);
        }
        $alert = DB::table('alerts')->insertGetId(['alert_code' => 'ALT-OLD', 'type' => 'haul', 'severity' => 'warning', 'title' => 'Lift LFT-261026-CIWMS-01 delayed', 'message' => 'PUR-ACC-PREM-001 for SLS-261026-PUIU7 (lift LFT-261026-CIWMS)', 'action_url' => '/admin/inventory?search=PUR-ACC-PREM-001', 'reference_type' => 'hauls', 'reference_id' => 1, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $before = $this->snapshot();

        $migration = require database_path('migrations/2026_10_30_000002_renumber_record_codes.php');
        $migration->up();

        // Date order per fuel; the lift is Premium (F1 rides along), its haul lines follow it.
        $this->assertSame('PUR-PREM-001', DB::table('purchases')->where('id', $earlier)->value('purchase_code'));
        $this->assertSame('PUR-PREM-002', DB::table('purchases')->where('id', $later)->value('purchase_code'));
        $this->assertSame('PUR-F1-001', DB::table('purchases')->where('id', $f1Purchase)->value('purchase_code'));
        $this->assertSame('SLS-PREM-001', DB::table('sales')->where('id', $sale)->value('sale_code'));
        $this->assertSame('LFT-PREM-001', DB::table('lifting_schedules')->where('id', $schedule)->value('schedule_code'));
        $this->assertSame(['LFT-PREM-001-01', 'LFT-PREM-001-02'], DB::table('hauls')->orderBy('id')->pluck('haul_code')->all());
        $this->assertDatabaseHas('alerts', ['id' => $alert, 'title' => 'Lift LFT-PREM-001-01 delayed', 'message' => 'PUR-PREM-001 for SLS-PREM-001 (lift LFT-PREM-001)', 'action_url' => '/admin/inventory?search=PUR-PREM-001']);
        $this->assertSame(7, DB::table('code_renumbering')->where('reason', 'renumber')->count());
        $this->assertSame(2, (int) DB::table('code_sequences')->where('prefix', 'PUR')->where('fuel_code', 'PREM')->value('last_number'));
        // The next new purchase continues the numbering.
        $this->assertSame('PUR-PREM-003', app(RecordCodeService::class)->next(RecordCodeService::PURCHASE, $prem));

        $migration->down();
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, DB::table('code_renumbering')->count());
    }

    /**
     * @param  array<int, int>  $purchaseItemIds
     * @return array<int, string>
     */
    private function purchaseCodes(array $purchaseItemIds): array
    {
        return array_map(fn (int $id): string => (string) DB::table('purchase_items')->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')->where('purchase_items.id', $id)->value('purchase_code'), $purchaseItemIds);
    }

    /** @return array<string, array<int, mixed>> */
    private function snapshot(): array
    {
        return [
            'purchases' => DB::table('purchases')->orderBy('id')->pluck('purchase_code')->all(),
            'sales' => DB::table('sales')->orderBy('id')->pluck('sale_code')->all(),
            'lifts' => DB::table('lifting_schedules')->orderBy('id')->pluck('schedule_code')->all(),
            'hauls' => DB::table('hauls')->orderBy('id')->pluck('haul_code')->all(),
            'alerts' => DB::table('alerts')->orderBy('id')->get(['title', 'message', 'action_url'])->map(fn (object $row): array => (array) $row)->all(),
        ];
    }
}
