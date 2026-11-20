<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Audit\AuditTestCase;

/**
 * Imported historical payments whose source states the amount and date but not the method are stored as
 * "not_recorded": counted as collected, labelled as unverified, and never selectable for new payments.
 */
class HistoricalPaymentMethodTest extends AuditTestCase
{
    public function test_a_historical_payment_without_a_recorded_method_counts_as_collected_and_is_labelled_unverified(): void
    {
        $a = $this->actors();
        $saleId = $this->sale($a);
        DB::table('payments')->insert([
            'payment_code' => 'PAY-IMP-TEST', 'sale_id' => $saleId, 'payment_date' => '2026-10-16', 'amount' => 1000,
            'method' => 'not_recorded', 'remarks' => 'Imported; method not stated in the source.', 'received_by' => $a['admin']->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame('not_recorded', DB::table('payments')->where('sale_id', $saleId)->value('method'));
        $page = $this->actingAs($a['admin'])->get(route('admin.sales'))->assertOk();
        $page->assertSee('Not recorded (historical)');
        $page->assertDontSee('Not Recorded</td>', false);
    }

    public function test_not_recorded_cannot_be_chosen_for_a_new_payment(): void
    {
        $a = $this->actors();
        $saleId = $this->sale($a);

        foreach ([[$a['sales'], 'sales-officer'], [$a['admin'], 'admin']] as [$user, $prefix]) {
            $this->actingAs($user)->from(route($prefix.'.sales'))
                ->post(route($prefix.'.sales.payments.store', $saleId), [
                    'idempotency_key' => $this->uuid(), 'payment_date' => '2026-10-16', 'amount' => 100, 'method' => 'not_recorded',
                ])
                ->assertSessionHasErrors('method');
        }
        $this->assertSame(0, DB::table('payments')->where('sale_id', $saleId)->count());
    }

    /** @param array<string, mixed> $a */
    private function sale(array $a): int
    {
        $saleId = DB::table('sales')->insertGetId(['sale_code' => 'SLS-HIST-'.substr($this->uuid(), 0, 8), 'customer_id' => $a['customer'], 'sale_date' => '2026-10-15',
            'payment_terms' => 'cod', 'status' => 'confirmed', 'delivery_status' => 'pending', 'created_by' => $a['sales']->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sale_items')->insert(['sale_id' => $saleId, 'fuel_type_id' => $a['fuel'], 'quantity_liters' => 1000, 'unit_price' => 10, 'line_total' => 10000,
            'fulfilled_quantity_liters' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('receivables')->insert(['sale_id' => $saleId, 'status' => 'unpaid', 'created_at' => now(), 'updated_at' => now()]);

        return $saleId;
    }
}
