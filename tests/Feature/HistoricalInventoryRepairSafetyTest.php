<?php

namespace Tests\Feature;

use App\Services\HistoricalInventoryRepairService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PurchaseFlows;
use Tests\Feature\Audit\AuditTestCase;

class HistoricalInventoryRepairSafetyTest extends AuditTestCase
{
    use PurchaseFlows;

    private function plan(float $saleLiters, string $purchaseDate = '2026-10-14'): array
    {
        $a = $this->actors();
        $id = $this->purchaseLine($a, 'DSL', 1000);
        $this->recordOrder($a, ['fuel_type_id' => $this->fuel('DSL'), 'purchase_item_id' => $id, 'quantity_liters' => 100, 'unit_price' => 60])->assertSessionHasNoErrors();
        $sale = DB::table('sales')->orderByDesc('id')->first();
        DB::table('sales')->where('id', $sale->id)->update(['delivery_status' => 'delivered']);
        DB::table('sale_items')->where('sale_id', $sale->id)->update(['quantity_liters' => $saleLiters]);
        DB::table('purchases')->update(['purchase_date' => $purchaseDate]);
        $result = ['stock_out_unresolved' => [], 'stock_out_skipped' => 0];
        $method = new \ReflectionMethod(HistoricalInventoryRepairService::class, 'planStockOuts');
        $rows = [['source_row' => 2, 'sale_code' => $sale->sale_code, 'quantity' => $saleLiters, 'purchase_code' => 'MULTIPLE FIFO LOTS', 'fuel_code' => 'DSL', 'date' => '2026-10-15 00:00:00']];
        $plan = $method->invokeArgs(app(HistoricalInventoryRepairService::class), [$rows, &$result]);
        return [$plan, $result];
    }

    public function test_short_inventory_does_not_keep_partial_allocations(): void
    {
        [$plan, $result] = $this->plan(1500);
        $this->assertSame([], $plan['allocations']);
        $this->assertCount(1, $result['stock_out_unresolved']);
        $this->assertSame(0, DB::table('stock_outs')->count());
    }

    public function test_future_purchases_cannot_fulfil_historical_releases(): void
    {
        [$plan, $result] = $this->plan(100, '2026-10-16');
        $this->assertSame([], $plan['allocations']);
        $this->assertCount(1, $result['stock_out_unresolved']);
    }

    public function test_supported_release_plan_keeps_source_quantity_and_cost(): void
    {
        [$plan, $result] = $this->plan(100);
        $this->assertCount(1, $plan['allocations']);
        $this->assertEquals(100, $plan['allocations'][0]['quantity_liters']);
        $this->assertEquals(50, $plan['allocations'][0]['unit_cost']);
        $this->assertSame([], $result['stock_out_unresolved']);
    }

    public function test_qa_flag_cannot_authorize_another_database(): void
    {
        $this->artisan('inventory:repair-historical', ['path' => base_path('composer.json'), '--apply' => true, '--allow-qa' => true])
            ->expectsOutputToContain('--allow-qa only authorizes cjp_inventory_repair_qa')->assertFailed();
    }

    public function test_the_database_is_refused_before_the_workbook_is_looked_at(): void
    {
        // A missing workbook would fail with "Workbook was not found." if the file were checked first.
        $this->artisan('inventory:repair-historical', ['path' => base_path('does-not-exist.xlsx'), '--apply' => true, '--allow-qa' => true])
            ->expectsOutputToContain('Refusing to write')->doesntExpectOutputToContain('Workbook was not found.')->assertFailed();
    }

    public function test_stream_reader_handles_namespaced_cells_and_shared_strings(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cjp-reader-').'.xlsx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="urn:sheet" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Inventory - Stock Out" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="urn:rels"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="urn:sheet"><si><t>SLS-TEST</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="urn:sheet"><sheetData><row r="2"><c r="A2" t="s"><v>0</v></c><c r="B2"><v>43831</v></c><c r="E2" t="inlineStr"><is><t>Diesel</t></is></c><c r="F2" t="inlineStr"><is><t>MULTIPLE FIFO LOTS</t></is></c><c r="K2"><v>2000</v></c></row></sheetData></worksheet>');
        $zip->close();
        try {
            $method = new \ReflectionMethod(HistoricalInventoryRepairService::class, 'stockOutRows');
            $rows = $method->invoke(app(HistoricalInventoryRepairService::class), $path);
            $this->assertCount(1, $rows);
            $this->assertSame('SLS-TEST', $rows[0]['sale_code']);
            $this->assertSame('DSL', $rows[0]['fuel_code']);
            $this->assertEquals(2000, $rows[0]['quantity']);
        } finally {
            unlink($path);
        }
    }
}
