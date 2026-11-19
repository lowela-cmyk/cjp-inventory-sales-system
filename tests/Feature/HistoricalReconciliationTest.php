<?php

namespace Tests\Feature;

use App\Services\DashboardService;
use App\Services\StockInService;
use App\Support\HistoricalReconciliation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Historical import limitations: unknown garage tanks are allowed only for flagged historical rows, unattributed
 * historical F1 is not verified stock, and documented rounding is a reconciliation variance, not missing money.
 * Normal transactions keep the previous rules.
 */
class HistoricalReconciliationTest extends AuditTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        HistoricalReconciliation::flush();
    }

    public function test_a_new_garage_release_still_needs_a_tank_and_only_flagged_historical_rows_may_omit_it(): void
    {
        $a = $this->actors();
        [$saleId, $itemId] = $this->sale($a, 100);
        $purchaseItem = $this->purchaseItem($a, 1000);
        $row = fn (array $extra): array => $extra + ['stock_out_code' => 'SO-'.substr($this->uuid(), 0, 8), 'sale_id' => $saleId, 'sale_item_id' => $itemId,
            'customer_id' => $a['customer'], 'fuel_type_id' => $a['fuel'], 'portion' => 'main', 'source_type' => 'garage', 'purchase_item_id' => $purchaseItem,
            'quantity_liters' => 100, 'stock_out_at' => now(), 'status' => 'released', 'created_at' => now(), 'updated_at' => now()];

        foreach ([
            'garage without tank, not flagged' => [],
            'flagged but with a tank' => ['historical_tank_unknown' => true, 'storage_location_id' => $this->tank($a)],
            'flagged without its purchase' => ['historical_tank_unknown' => true, 'purchase_item_id' => null],
            'depot row flagged' => ['source_type' => 'depot', 'depot_id' => $a['depot'], 'historical_tank_unknown' => true],
        ] as $case => $extra) {
            try {
                DB::table('stock_outs')->insert($row($extra));
                $this->fail("Accepted: $case");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        DB::table('stock_outs')->insert($row(['historical_tank_unknown' => true]));
        DB::table('stock_outs')->insert($row(['storage_location_id' => $this->tank($a)]));
        $this->assertSame(2, DB::table('stock_outs')->count());
    }

    public function test_unattributed_historical_f1_is_excluded_from_verified_stock_and_shown_separately(): void
    {
        $a = $this->actors();
        $f1 = (int) DB::table('fuel_types')->where('code', 'F1')->value('id');
        $flagged = $this->purchaseItem($a, 40000, $f1);
        $normal = $this->purchaseItem($a, 5000);
        $this->register(HistoricalReconciliation::F1_UNATTRIBUTED, 'purchase_item', $flagged, 40000, null);

        $rows = app(StockInService::class)->records()->keyBy('purchase_item_id');
        $this->assertSame(0.0, $rows[$flagged]->remaining);
        $this->assertSame(40000.0, $rows[$flagged]->historical_unverified);
        $this->assertSame(5000.0, $rows[$normal]->remaining, 'lines without a register entry are unchanged');
        $this->assertSame(0.0, $rows[$normal]->historical_unverified);

        $inventory = app(DashboardService::class)->inventory();
        $this->assertSame(5000.0, $inventory['totals']['stock_liters']);
        $this->assertSame(40000.0, $inventory['totals']['historical_unverified_liters']);

        $this->actingAs($a['admin'])->get(route('admin.dashboard'))->assertOk()
            ->assertDontSee('Historical (unverified):', false) // the dashboard note was removed on request; the liters stay out of stock
            ->assertDontSee('Historical Import Reconciliation'); // the dashboard section was removed on request; the data stays
    }

    public function test_source_stock_in_figures_replace_blended_releases_and_read_sold_out(): void
    {
        $a = $this->actors();
        [$saleId, $itemId] = $this->sale($a, 1200, 50, 'delivered');
        $gasoline = $this->purchaseItem($a, 1000);
        $normal = $this->purchaseItem($a, 1000);
        DB::table('stock_outs')->insert(['stock_out_code' => 'HSTO-T-2', 'sale_id' => $saleId, 'sale_item_id' => $itemId, 'customer_id' => $a['customer'],
            'fuel_type_id' => $a['fuel'], 'portion' => 'main', 'source_type' => 'depot', 'depot_id' => $a['depot'], 'purchase_item_id' => $gasoline,
            'quantity_liters' => 1200, 'stock_out_at' => now(), 'status' => 'released', 'created_at' => now(), 'updated_at' => now()]);

        $before = app(StockInService::class)->records()->keyBy('purchase_item_id');
        $this->assertSame(1200.0, $before[$gasoline]->stock_out, 'without source figures the blended release is counted');
        $this->assertSame('sold_out', $before[$gasoline]->stock_status);

        $this->register(HistoricalReconciliation::F1_IN_GASOLINE, 'purchase_item', $gasoline, 200, null);
        $this->register(HistoricalReconciliation::HISTORICAL_STOCK_IN, 'purchase_item', $gasoline, 1000, null);
        $rows = app(StockInService::class)->records()->keyBy('purchase_item_id');
        $this->assertSame(1000.0, $rows[$gasoline]->stock_out, 'Sold QTY equals the source');
        $this->assertSame(0.0, $rows[$gasoline]->remaining);
        $this->assertSame('sold_out', $rows[$gasoline]->stock_status);
        $this->assertSame(200.0, $rows[$gasoline]->historical_blended_f1);
        $this->assertSame([1000.0, 0.0, 'available'], [$rows[$normal]->remaining, $rows[$normal]->stock_out, $rows[$normal]->stock_status], 'other purchases unchanged');
        $this->assertContains($gasoline, app(StockInService::class)->records(['status' => 'sold_out'])->pluck('purchase_item_id')->map(fn ($id) => (int) $id)->all(), 'the Sold Out filter lists it');
    }

    public function test_documented_rounding_is_a_historical_variance_not_missing(): void
    {
        $a = $this->actors();
        [$saleId, $itemId] = $this->sale($a, 4000, 50, 'delivered');
        $purchaseItem = $this->purchaseItem($a, 10000);
        DB::table('stock_outs')->insert(['stock_out_code' => 'HSTO-T-1', 'sale_id' => $saleId, 'sale_item_id' => $itemId, 'customer_id' => $a['customer'],
            'fuel_type_id' => $a['fuel'], 'portion' => 'main', 'source_type' => 'depot', 'depot_id' => $a['depot'], 'purchase_item_id' => $purchaseItem,
            'quantity_liters' => 4001, 'stock_out_at' => now(), 'status' => 'released', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('payments')->insert(['payment_code' => 'PAY-IMP-T', 'sale_id' => $saleId, 'payment_date' => now()->toDateString(), 'amount' => 200000,
            'method' => 'not_recorded', 'created_at' => now(), 'updated_at' => now()]);

        $before = app(DashboardService::class)->stockOutMargin();
        $this->assertSame(50.0, $before['missing'], 'without a register entry the over-release shows as missing');

        $this->register(HistoricalReconciliation::ROUNDING_VARIANCE, 'sale', $saleId, 1, 50);
        $after = app(DashboardService::class)->stockOutMargin();
        $this->assertSame(0.0, $after['missing']);
        $this->assertSame(['orders' => 1, 'liters' => 1.0, 'amount' => 50.0], $after['historical_variance']);
        $this->assertSame(200050.0, $after['stock_out'], 'stock-out value is unchanged');
    }

    /** @param array<string, mixed> $a @return array{0: int, 1: int} */
    private function sale(array $a, int $liters, float $price = 10, string $delivery = 'pending'): array
    {
        $saleId = DB::table('sales')->insertGetId(['sale_code' => 'SLS-HR-'.substr($this->uuid(), 0, 8), 'customer_id' => $a['customer'], 'sale_date' => now()->toDateString(),
            'payment_terms' => 'cod', 'status' => 'confirmed', 'delivery_status' => $delivery, 'created_by' => $a['sales']->id, 'created_at' => now(), 'updated_at' => now()]);
        $itemId = DB::table('sale_items')->insertGetId(['sale_id' => $saleId, 'fuel_type_id' => $a['fuel'], 'quantity_liters' => $liters, 'unit_price' => $price,
            'line_total' => $liters * $price, 'main_quantity_liters' => $liters, 'fulfilled_quantity_liters' => $delivery === 'delivered' ? $liters : 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('receivables')->insert(['sale_id' => $saleId, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        return [$saleId, $itemId];
    }

    /** @param array<string, mixed> $a */
    private function purchaseItem(array $a, int $liters, ?int $fuel = null): int
    {
        $purchaseId = DB::table('purchases')->insertGetId(['purchase_code' => 'PUR-HR-'.substr($this->uuid(), 0, 8), 'depot_id' => $a['depot'], 'purchase_date' => now()->toDateString(),
            'payment_status' => 'paid', 'status' => 'ordered', 'workflow_status' => 'pending', 'created_by' => $a['inventory']->id, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('purchase_items')->insertGetId(['purchase_id' => $purchaseId, 'fuel_type_id' => $fuel ?? $a['fuel'], 'quantity_ordered_liters' => $liters, 'unit_cost' => 40,
            'line_total' => $liters * 40, 'quantity_hauled_liters' => 0, 'status' => 'unlifted', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $a */
    private function tank(array $a): int
    {
        return (int) (DB::table('storage_locations')->where('type', 'garage')->where('fuel_type_id', $a['fuel'])->value('id')
            ?? DB::table('storage_locations')->insertGetId(['fuel_type_id' => $a['fuel'], 'tank_number' => 1, 'capacity_liters' => 24000, 'location_code' => 'T-HR',
                'name' => 'Test Tank 1', 'type' => 'garage', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]));
    }

    private function register(string $category, string $type, int $id, float $liters, ?float $amount): void
    {
        DB::table('historical_reconciliation_items')->insert(['source_document' => 'TEST.pdf', 'category' => $category, 'subject_type' => $type, 'subject_id' => $id,
            'liters' => $liters, 'amount' => $amount, 'detail' => 'test', 'created_at' => now()]);
    }
}
