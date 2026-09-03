<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DashboardSummaryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AIFeatureTestingTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_ai_reports_use_sanitized_laravel_analytics_payloads_without_extra_calls(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $records = $this->records();
        $this->aiDataset($records);
        $this->configureAi();

        Http::fake([
            'api.groq.com/*' => Http::sequence()
                ->push($this->groqPayload('Revenue Summary. Use PHP 145,000 sales, PHP 40,000 collected, PHP 105,000 receivables.'))
                ->push($this->groqPayload('Sales Trend Summary. September sales total PHP 145,000 after August PHP 50,000.'))
                ->push($this->groqPayload('Inventory Variance Summary. Two records require verification, quantity difference is -2,250 L.'))
                ->push($this->groqPayload('Executive Summary. Business data shows PHP 145,000 sales and 15,000 L current stock.')),
        ]);

        $this->actingAs($records['admin'])
            ->post(route('admin.reports.revenue-insight'), [
                'period' => 'month',
                'month' => '2026-09',
                'year' => '2026',
            ])
            ->assertRedirect()
            ->assertSessionHas('revenueInsight');

        $this->actingAs($records['admin'])
            ->post(route('admin.reports.sales-trend-summary'), [
                'period' => 'month',
                'month' => '2026-09',
                'year' => '2026',
            ])
            ->assertRedirect()
            ->assertSessionHas('salesTrendSummary');

        $this->actingAs($records['admin'])
            ->post(route('admin.dashboard.inventory-variance-explanation'), [
                'variance_date_from' => '2026-09-01',
                'variance_date_to' => '2026-09-30',
            ])
            ->assertRedirect()
            ->assertSessionHas('inventoryVarianceExplanation');

        $this->actingAs($records['admin'])
            ->post(route('admin.reports.business-insight'), [
                'period' => 'month',
                'month' => '2026-09',
                'year' => '2026',
            ])
            ->assertRedirect()
            ->assertSessionHas('businessInsight');

        Http::assertSentCount(4);

        $requests = Http::recorded()->map(fn (array $record) => $record[0]);
        $requestPrompts = $requests
            ->map(fn ($request): string => collect($request->data()['messages'] ?? [])
                ->pluck('content')
                ->implode("\n"));
        $promptText = $requests
            ->flatMap(fn ($request): array => collect($request->data()['messages'] ?? [])
                ->pluck('content')
                ->all())
            ->implode("\n");

        /** @var DashboardSummaryService $summary */
        $summary = app(DashboardSummaryService::class);
        $variance = $summary->inventoryVarianceMonitoring(['date_from' => '2026-09-01', 'date_to' => '2026-09-30']);

        $this->assertSame(145000.0, $summary->salesTrend('month', 2026)['values'][8]);
        $this->assertSame(195000.0, $summary->totalSalesRevenue());
        $this->assertSame(40000.0, $summary->collectedRevenue());
        $this->assertSame(155000.0, $summary->outstandingReceivables());
        $this->assertSame(15000.0, $summary->totalInventoryLiters());
        $this->assertSame(2, $variance['summary']['variance_count']);
        $this->assertSame(-2250.0, $variance['summary']['quantity_variance_liters']);

        $this->assertStringContainsString('"total_valid_sales": 145000', $promptText);
        $this->assertStringContainsString('"collected_revenue": 40000', $promptText);
        $this->assertStringContainsString('"outstanding_receivables": 105000', $promptText);
        $this->assertStringContainsString('"current_stock_liters": 15000', $promptText);
        $this->assertStringContainsString('"variance_count": 2', $promptText);
        $this->assertStringContainsString('"quantity_difference_liters": -2250', $promptText);
        $this->assertStringContainsString('Do not invent', $promptText);
        $this->assertStringNotContainsString('AI Secret Customer', $promptText);
        $this->assertStringNotContainsString('secret-ai@example.com', $promptText);
        $this->assertStringNotContainsString('gsk_live_secret', $promptText);
        $this->assertStringNotContainsString('Authorization', $promptText);
        $this->assertStringNotContainsString('SLS-AI-SENSITIVE', $requestPrompts[0]);
        $this->assertStringNotContainsString('SLS-AI-SENSITIVE', $requestPrompts[1]);
        $this->assertStringContainsString('SLS-AI-SENSITIVE', $requestPrompts[2]);
        $this->assertStringNotContainsString('SLS-AI-SENSITIVE', $requestPrompts[3]);

        foreach ($requests as $request) {
            $this->assertSame(0.2, $request->data()['temperature']);
            $this->assertLessThanOrEqual(520, $request->data()['max_tokens']);
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer test-groq-key'));
        }

        Carbon::setTestNow();
    }

    public function test_ai_no_data_rbac_and_provider_failures_do_not_break_dashboard_analytics(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $records = $this->records();
        $this->aiDataset($records);
        $this->configureAi();
        Http::fake();

        $this->actingAs($records['driver'])
            ->post(route('admin.reports.business-insight'), ['period' => 'all'])
            ->assertForbidden();

        $this->actingAs($records['admin'])
            ->post(route('admin.reports.revenue-insight'), [
                'period' => 'date',
                'date' => '2024-01-01',
                'month' => '2024-01',
                'year' => '2024',
            ])
            ->assertRedirect()
            ->assertSessionHas('revenueInsightNotice', 'Insufficient revenue data for analysis.');

        Http::assertNothingSent();

        foreach ([
            'missing key' => ['', null, 'AI service is currently unavailable.'],
            'rate limit' => ['test-groq-key', Http::response(['error' => ['message' => 'limit']], 429), 'AI insights are temporarily unavailable. System analytics are still available.'],
            'provider error' => ['test-groq-key', Http::response(['error' => ['message' => 'down']], 503), 'AI insights are temporarily unavailable. System analytics are still available.'],
            'malformed response' => ['test-groq-key', Http::response(['unexpected' => true]), 'AI insights are temporarily unavailable. System analytics are still available.'],
            'empty response' => ['test-groq-key', $this->groqResponse('   '), 'AI insights are temporarily unavailable. System analytics are still available.'],
        ] as $case) {
            [$key, $fakeResponse, $notice] = $case;
            Config::set('services.ai.api_key', $key);
            Http::fake($fakeResponse ? ['api.groq.com/*' => $fakeResponse] : []);

            $this->actingAs($records['admin'])
                ->post(route('admin.reports.revenue-insight'), [
                    'period' => 'month',
                    'month' => '2026-09',
                    'year' => '2026',
                ])
                ->assertRedirect()
                ->assertSessionHas('revenueInsightNotice', $notice);

            $this->actingAs($records['admin'])
                ->get(route('admin.dashboard'))
                ->assertOk()
                ->assertSee('PHP 195,000')
                ->assertSee('15 KL')
                ->assertDontSee('test-groq-key', false)
                ->assertDontSee('gsk_live_secret', false);
        }

        Carbon::setTestNow();
    }

    public function test_ai_output_is_escaped_and_does_not_replace_authoritative_dashboard_values(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $records = $this->records();
        $this->aiDataset($records);
        $this->configureAi();
        Http::fake([
            'api.groq.com/*' => $this->groqResponse("Revenue Summary\n<script>alert('x')</script>\nUnsupported number: PHP 999,999"),
        ]);

        $this->actingAs($records['admin'])
            ->post(route('admin.reports.revenue-insight'), [
                'period' => 'month',
                'month' => '2026-09',
                'year' => '2026',
            ])
            ->assertRedirect();

        $this->followingRedirects()
            ->actingAs($records['admin'])
            ->get(route('admin.reports', ['period' => 'month', 'month' => '2026-09', 'year' => '2026']))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;', false)
            ->assertDontSee("<script>alert('x')</script>", false)
            ->assertSee('Unsupported number: PHP 999,999')
            ->assertSee('PHP 145,000')
            ->assertDontSee('gsk_live_secret', false);

        $this->actingAs($records['admin'])
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('PHP 195,000')
            ->assertSee('15 KL')
            ->assertDontSee('PHP 999,999');

        Carbon::setTestNow();
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
            'depot_code' => 'DEP-AI-FEATURE',
            'name' => 'AI Feature Depot',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $garageId = DB::table('storage_locations')->insertGetId([
            'location_code' => 'GAR-AI-FEATURE',
            'name' => 'AI Feature Garage',
            'type' => 'garage',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $dieselId = DB::table('fuel_types')->insertGetId([
            'code' => 'DSL-AI-FEATURE',
            'name' => 'AI Feature Diesel',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $e10Id = DB::table('fuel_types')->insertGetId([
            'code' => 'E10-AI-FEATURE',
            'name' => 'AI Feature E10',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-AI-FEATURE',
            'name' => 'AI Secret Customer',
            'company_name' => 'AI Secret Customer Co.',
            'email' => 'secret-ai@example.com',
            'payment_status' => 'partial',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('admin', 'salesOfficer', 'inventoryOfficer', 'driver', 'depotId', 'garageId', 'dieselId', 'e10Id', 'customerId');
    }

    /**
     * @param array<string, mixed> $records
     */
    private function aiDataset(array $records): void
    {
        $this->sale($records, 'SLS-AI-AUGUST', '2026-08-10', 1000, 50);
        $septSaleId = $this->sale($records, 'SLS-AI-SENSITIVE', '2026-09-05', 2000, 55, 'partially_paid');
        $this->payment($records, $septSaleId, 'PAY-AI-FEATURE', 40000);
        [$mismatchSaleId, $mismatchItemId] = $this->saleWithItem($records, 'SLS-AI-MISMATCH', '2026-09-06', 500, 70, 'confirmed', $records['e10Id']);
        $this->stockOut($records, $mismatchSaleId, $mismatchItemId, 'STO-AI-MISMATCH', 250, $records['e10Id']);

        DB::table('inventory_movements')->insert([
            $this->movement($records, 'MOV-AI-DIESEL-IN', $records['dieselId'], 10000),
            $this->movement($records, 'MOV-AI-E10-IN', $records['e10Id'], 5000),
        ]);

        $this->purchase($records, 'PUR-AI-UNLIFTED', 4000, $records['dieselId']);
    }

    /**
     * @param array<string, mixed> $records
     */
    private function sale(array $records, string $code, string $date, float $quantity, float $unitPrice, string $status = 'confirmed'): int
    {
        [$saleId] = $this->saleWithItem($records, $code, $date, $quantity, $unitPrice, $status);

        return $saleId;
    }

    /**
     * @param array<string, mixed> $records
     * @return array{0: int, 1: int}
     */
    private function saleWithItem(array $records, string $code, string $date, float $quantity, float $unitPrice, string $status = 'confirmed', ?int $fuelTypeId = null): array
    {
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
            'status' => $status === 'partially_paid' ? 'partial' : 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$saleId, $saleItemId];
    }

    /**
     * @param array<string, mixed> $records
     */
    private function payment(array $records, int $saleId, string $code, float $amount): void
    {
        DB::table('payments')->insert([
            'payment_code' => $code,
            'sale_id' => $saleId,
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
     * @param array<string, mixed> $records
     */
    private function stockOut(array $records, int $saleId, int $saleItemId, string $code, float $quantity, int $fuelTypeId): void
    {
        DB::table('stock_outs')->insert([
            'stock_out_code' => $code,
            'sale_id' => $saleId,
            'sale_item_id' => $saleItemId,
            'customer_id' => $records['customerId'],
            'fuel_type_id' => $fuelTypeId,
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
     * @param array<string, mixed> $records
     * @return array<string, mixed>
     */
    private function movement(array $records, string $code, int $fuelTypeId, float $quantity): array
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
     * @param array<string, mixed> $records
     */
    private function purchase(array $records, string $code, float $quantity, int $fuelTypeId): void
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

    private function configureAi(): void
    {
        Config::set('services.ai.provider', 'groq');
        Config::set('services.ai.api_key', 'test-groq-key');
        Config::set('services.ai.model', 'openai/gpt-oss-20b');
        Config::set('services.ai.base_url', 'https://api.groq.com/openai/v1');
        Config::set('services.ai.timeout', 20);
    }

    /**
     * @return array<string, mixed>
     */
    private function groqPayload(string $text): array
    {
        return [
            'choices' => [[
                'message' => ['content' => $text],
            ]],
        ];
    }

    /**
     * @return \Illuminate\Http\Client\Response
     */
    private function groqResponse(string $text)
    {
        return Http::response($this->groqPayload($text));
    }
}
