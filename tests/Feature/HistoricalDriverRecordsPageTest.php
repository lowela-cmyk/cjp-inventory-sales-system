<?php

namespace Tests\Feature;

use App\Support\HistoricalReconciliation;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Audit\AuditTestCase;

/** The historical driver records page shows unmatched source rows read-only, to admins only. */
class HistoricalDriverRecordsPageTest extends AuditTestCase
{
    public function test_admin_sees_source_rows_by_sheet_and_other_roles_are_refused(): void
    {
        HistoricalReconciliation::flush();
        $a = $this->actors();
        foreach ([['Driver Schedule', 2, 'DSCH-UNL-0002', ['Lift-ID' => 'DSCH-UNL-0002', 'Truck-ID' => 'NDQ9861', 'QTY to Lift' => '40,000']],
            ['Driver Deliveries', 2, 'DDL-UNL-00001', ['Order-ID' => 'DDL-UNL-00001', 'Customer' => 'GREATOIL LOOB', 'QTY' => '4,000']]] as [$sheet, $row, $ref, $values]) {
            DB::table('historical_reconciliation_items')->insert(['source_document' => 'CJP_DATA.pdf', 'category' => HistoricalReconciliation::SOURCE_ROW_UNMATCHED,
                'source_reference' => $ref, 'source_sheet' => $sheet, 'source_row' => $row, 'detail' => 'not imported', 'source_values' => json_encode($values), 'created_at' => now()]);
        }

        $this->actingAs($a['admin'])->get(route('admin.historical.driver-records'))->assertOk()
            ->assertSee('DSCH-UNL-0002')->assertSee('NDQ9861')->assertSee('QTY to Lift')->assertDontSee('DDL-UNL-00001')
            ->assertSee('Driver Schedule (1)')->assertSee('Driver Deliveries (1)')->assertSee('not</strong> counted', false);
        $this->actingAs($a['admin'])->get(route('admin.historical.driver-records', ['sheet' => 'deliveries', 'search' => 'GREATOIL']))->assertOk()
            ->assertSee('DDL-UNL-00001')->assertSee('GREATOIL LOOB')->assertDontSee('DSCH-UNL-0002');
        $this->actingAs($a['admin'])->get(route('admin.historical.driver-records', ['sheet' => 'deliveries', 'search' => 'NOPE']))->assertOk()->assertSee('No records found.');
        $this->actingAs($a['admin'])->get(route('admin.historical.driver-records', ['sheet' => 'bogus']))->assertRedirect();

        foreach (['inventory', 'sales', 'dispatch', 'driver'] as $role) {
            $this->actingAs($a[$role])->get(route('admin.historical.driver-records'))->assertForbidden();
        }
    }
}
