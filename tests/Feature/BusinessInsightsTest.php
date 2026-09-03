<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\BusinessInsightService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BusinessInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_generate_business_insights_from_consolidated_system_values(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $records = $this->records();
        $this->businessData($records);

        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => "Executive Summary\nSeptember sales and receivables require management review.\n\nKey Business Findings\nSales increased against the previous month.\n\nAreas Requiring Attention\nReceivables, unlifted fuel, and detected variance require follow-up.\n\nPositive Trends\nSales revenue improved.\n\nRecommended Management Actions\nReview variance records and monitor collections.",
                    ],
                ]],
            ]),
        ]);
        config($this->aiConfig());

        $response = $this->actingAs($records['admin'])->post(route('admin.reports.business-insight'), [
            'period' => 'month',
            'month' => '2026-09',
            'year' => '2026',
        ]);

        $response->assertRedirect(route('admin.reports', [
            'period' => 'month',
            'date' => now()->toDateString(),
            'start_date' => null,
            'end_date' => null,
            'month' => '2026-09',
            'year' => '2026',
        ]));
        $response->assertSessionHas('businessInsight');

        $this->followingRedirects()
            ->actingAs($records['admin'])
            ->get(route('admin.reports', ['period' => 'month', 'month' => '2026-09', 'year' => '2026']))
            ->assertOk()
            ->assertSee('Business AI Insights')
            ->assertSee('Executive Summary')
            ->assertSee('AI-assisted business insights based on system-calculated analytics');

        Http::assertSent(function ($request): bool {
            $body = $request->data();
            $promptText = $body['messages'][0]['content']."\n".$body['messages'][1]['content'];

            return $request->url() === 'https://api.groq.com/openai/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-groq-key')
                && $body['model'] === 'openai/gpt-oss-20b'
                && $body['max_tokens'] === 520
                && str_contains($promptText, BusinessInsightService::SYSTEM_PROMPT)
                && str_contains($promptText, '"total_valid_sales": 145000')
                && str_contains($promptText, '"collected_revenue": 40000')
                && str_contains($promptText, '"outstanding_receivables": 105000')
                && str_contains($promptText, '"collection_percentage": 27.6')
                && str_contains($promptText, '"current_period_sales": 145000')
                && str_contains($promptText, '"previous_period_sales": 50000')
                && str_contains($promptText, '"percentage_change": 190')
                && str_contains($promptText, '"current_stock_liters": 15000')
                && str_contains($promptText, '"unlifted_liters": 4000')
                && str_contains($promptText, '"total_outstanding": 155000')
                && str_contains($promptText, '"transactions_checked": 2')
                && str_contains($promptText, '"variance_count": 2')
                && str_contains($promptText, '"variance_rate_percent": 100')
                && str_contains($promptText, '"quantity_difference_liters": -2250')
                && str_contains($promptText, '"reason": "Missing Stock-Out"')
                && str_contains($promptText, '"reason": "Quantity Mismatch"')
                && str_contains($promptText, '"revenue_insights"')
                && str_contains($promptText, '"sales_trend_summaries"')
                && str_contains($promptText, '"inventory_variance_explanation"')
                && ! str_contains($promptText, 'Business Customer')
                && ! str_contains($promptText, 'business@example.com')
                && ! str_contains($promptText, 'SLS-BIZ')
                && ! array_key_exists('key', $body);
        });

        Carbon::setTestNow();
    }

    public function test_declining_sales_and_no_detected_variance_are_supplied_without_extra_ai_calls(): void
    {
        Carbon::setTestNow('2026-10-15 10:00:00');
        $records = $this->records();
        [$augSaleId, $augItemId] = $this->saleWithItem($records, 'SLS-BIZ-AUG-STRONG', '2026-08-10', 2000, 50);
        $this->stockOutForSale($records, $augSaleId, $augItemId, 'STO-BIZ-AUG-STRONG', 2000);
        [$sepSaleId, $sepItemId] = $this->saleWithItem($records, 'SLS-BIZ-SEP-DECLINE', '2026-09-10', 1000, 50);
        $this->stockOutForSale($records, $sepSaleId, $sepItemId, 'STO-BIZ-SEP-DECLINE', 1000);

        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [['message' => ['content' => "Executive Summary\nSales declined and no variance was detected."]]],
            ]),
        ]);
        config($this->aiConfig());

        $this->actingAs($records['admin'])->post(route('admin.reports.business-insight'), [
            'period' => 'month',
            'month' => '2026-09',
            'year' => '2026',
        ])->assertRedirect()->assertSessionHas('businessInsight');

        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $promptText = $request->data()['messages'][1]['content'];

            return str_contains($promptText, '"direction": "decrease"')
                && str_contains($promptText, '"percentage_change": -50')
                && str_contains($promptText, '"variance_count": 0')
                && str_contains($promptText, '"reason_breakdown": []');
        });

        Carbon::setTestNow();
    }

    public function test_zero_data_period_skips_ai_call(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Http::fake();
        config($this->aiConfig());

        $this->actingAs($admin)
            ->post(route('admin.reports.business-insight'), [
                'period' => 'date',
                'date' => '2024-01-01',
                'month' => '2024-01',
                'year' => '2024',
            ])
            ->assertRedirect()
            ->assertSessionHas('businessInsightNotice', 'Insufficient business data for AI insight generation.');

        Http::assertNothingSent();
        Carbon::setTestNow();
    }

    public function test_ai_failure_shows_friendly_fallback(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $records = $this->records();
        $this->businessData($records);
        config($this->aiConfig());

        foreach ([
            Http::response(['unexpected' => true]),
            Http::response(['error' => ['message' => 'provider unavailable']], 503),
        ] as $fakeResponse) {
            Http::fake(['api.groq.com/*' => $fakeResponse]);

            $this->actingAs($records['admin'])
                ->post(route('admin.reports.business-insight'), [
                    'period' => 'month',
                    'month' => '2026-09',
                    'year' => '2026',
                ])
                ->assertRedirect()
                ->assertSessionHas('businessInsightNotice', 'AI insights are temporarily unavailable. System analytics are still available.');
        }

        Carbon::setTestNow();
    }

    public function test_driver_cannot_generate_business_insights(): void
    {
        Http::fake();
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active']);

        $this->actingAs($driver)
            ->post(route('admin.reports.business-insight'), ['period' => 'all'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    /**
     * @return array<string, mixed>
     */
    private function records(): array
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        $salesOfficer = User::factory()->create(['role' => 'sales_officer', 'status' => 'active']);
        $inventoryOfficer = User::factory()->create(['role' => 'inventory_officer', 'status' => 'active']);
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active']);
        $depotId = DB::table('depots')->insertGetId([
            'depot_code' => 'DPT-BIZ',
            'name' => 'Business Depot',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $garageId = DB::table('storage_locations')->insertGetId([
            'location_code' => 'GAR-BIZ',
            'name' => 'Business Garage',
            'type' => 'garage',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-BIZ',
            'name' => 'Business Customer',
            'company_name' => 'Business Customer Co.',
            'email' => 'business@example.com',
            'payment_status' => 'partial',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $dieselId = DB::table('fuel_types')->insertGetId([
            'code' => 'DSL-BIZ',
            'name' => 'AI Diesel',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $e10Id = DB::table('fuel_types')->insertGetId([
            'code' => 'E10-BIZ',
            'name' => 'AI E10',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => 'TRK-BIZ',
            'plate_number' => 'BIZ-1234',
            'capacity_liters' => 10000,
            'truck_type' => 'mixed',
            'status' => 'available',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('admin', 'salesOfficer', 'inventoryOfficer', 'driver', 'depotId', 'garageId', 'customerId', 'dieselId', 'e10Id', 'truckId');
    }

    /**
     * @param  array<string, mixed>  $records
     */
    private function businessData(array $records): void
    {
        $this->saleWithItem($records, 'SLS-BIZ-AUG', '2026-08-10', 1000, 50);
        [$septSaleId] = $this->saleWithItem($records, 'SLS-BIZ-SEP-DIESEL', '2026-09-05', 2000, 55, 'partially_paid');
        $this->paymentForSale($records, $septSaleId, 'PAY-BIZ-SEP', 40000);
        [$mismatchSaleId, $mismatchItemId] = $this->saleWithItem($records, 'SLS-BIZ-SEP-E10', '2026-09-06', 500, 70, 'confirmed', $records['e10Id']);
        $this->stockOutForSale($records, $mismatchSaleId, $mismatchItemId, 'STO-BIZ-SEP-E10', 250, $records['e10Id']);

        DB::table('inventory_movements')->insert([
            $this->inventoryMovement($records, 'MOV-BIZ-DIESEL-IN', $records['dieselId'], 10000),
            $this->inventoryMovement($records, 'MOV-BIZ-E10-IN', $records['e10Id'], 5000),
        ]);

        $this->purchaseItem($records, 'PUR-BIZ-UNLIFTED', $records['dieselId'], 4000);
    }

    /**
     * @param  array<string, mixed>  $records
     * @return array{0: int, 1: int}
     */
    private function saleWithItem(
        array $records,
        string $code,
        string $date,
        float $quantity,
        float $unitPrice,
        string $status = 'confirmed',
        ?int $fuelTypeId = null
    ): array {
        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => $code,
            'customer_id' => $records['customerId'],
            'sale_date' => $date,
            'payment_method' => 'bank_transfer',
            'payment_terms' => $status === 'partially_paid' ? 'installment' : 'cod',
            'status' => $status,
            'created_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $saleItemId = DB::table('sale_items')->insertGetId([
            'sale_id' => $saleId,
            'fuel_type_id' => $fuelTypeId ?? $records['dieselId'],
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
            'status' => $status === 'paid' ? 'clear' : 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$saleId, $saleItemId];
    }

    /**
     * @param  array<string, mixed>  $records
     */
    private function stockOutForSale(array $records, int $saleId, int $saleItemId, string $code, float $quantity, ?int $fuelTypeId = null): void
    {
        DB::table('stock_outs')->insert([
            'stock_out_code' => $code,
            'sale_id' => $saleId,
            'sale_item_id' => $saleItemId,
            'customer_id' => $records['customerId'],
            'fuel_type_id' => $fuelTypeId ?? $records['dieselId'],
            'storage_location_id' => $records['garageId'],
            'quantity_liters' => $quantity,
            'stock_out_at' => '2026-09-06 08:00:00',
            'status' => 'released',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $records
     * @return array<string, mixed>
     */
    private function inventoryMovement(array $records, string $code, int $fuelTypeId, float $quantity): array
    {
        return [
            'movement_code' => $code,
            'storage_location_id' => $records['garageId'],
            'fuel_type_id' => $fuelTypeId,
            'movement_type' => 'beginning',
            'direction' => 'in',
            'quantity_liters' => $quantity,
            'unit_cost' => 50,
            'reference_type' => 'beginning',
            'reference_id' => 0,
            'movement_date' => '2026-09-01 08:00:00',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $records
     */
    private function purchaseItem(array $records, string $code, int $fuelTypeId, float $quantity): void
    {
        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => $code,
            'depot_id' => $records['depotId'],
            'purchase_date' => '2026-09-01',
            'payment_status' => 'paid',
            'status' => 'ordered',
            'created_by' => $records['inventoryOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('purchase_items')->insert([
            'purchase_id' => $purchaseId,
            'fuel_type_id' => $fuelTypeId,
            'quantity_ordered_liters' => $quantity,
            'unit_cost' => 50,
            'line_total' => $quantity * 50,
            'quantity_hauled_liters' => 0,
            'status' => 'unlifted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $records
     */
    private function paymentForSale(array $records, int $saleId, string $code, float $amount): void
    {
        DB::table('payments')->insert([
            'payment_code' => $code,
            'sale_id' => $saleId,
            'payment_schedule_id' => null,
            'payment_date' => '2026-09-06',
            'amount' => $amount,
            'method' => 'bank_transfer',
            'reference_number' => $code.'-REF',
            'received_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function aiConfig(): array
    {
        return [
            'services.ai.provider' => 'groq',
            'services.ai.api_key' => 'test-groq-key',
            'services.ai.model' => 'openai/gpt-oss-20b',
            'services.ai.base_url' => 'https://api.groq.com/openai/v1',
            'services.ai.timeout' => 60,
        ];
    }
}
