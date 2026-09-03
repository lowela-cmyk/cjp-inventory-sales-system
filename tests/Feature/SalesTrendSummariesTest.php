<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AIDataPreparationService;
use App\Services\SalesTrendSummaryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SalesTrendSummariesTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_generate_sales_trend_summary_from_prepared_system_values(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $records = $this->records();
        $this->sale($records, 'SLS-AI-AUG', '2026-08-10', 1000, 50);
        $this->sale($records, 'SLS-AI-SEP-DIESEL', '2026-09-05', 2000, 55);
        $this->sale($records, 'SLS-AI-SEP-E10', '2026-09-06', 500, 70, $records['e10Id']);
        $this->sale($records, 'SLS-AI-CANCELLED', '2026-09-07', 9999, 99, $records['dieselId'], 'cancelled');

        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => "Sales Trend Summary\nSales increased in September.\n\nKey Trend\nSeptember was higher than August.\n\nPeak/Low Period\nSeptember was the peak period.\n\nFuel-Type Observation\nDiesel led fuel-type sales.\n\nManagement Consideration\nReview inventory support for the higher sales period.",
                    ],
                ]],
            ]),
        ]);
        config($this->aiConfig());

        $response = $this->actingAs($records['admin'])->post(route('admin.reports.sales-trend-summary'), [
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
        $response->assertSessionHas('salesTrendSummary');

        $this->followingRedirects()
            ->actingAs($records['admin'])
            ->get(route('admin.reports', ['period' => 'month', 'month' => '2026-09', 'year' => '2026']))
            ->assertOk()
            ->assertSee('Sales Trend AI Summary')
            ->assertSee('Sales Trend Summary')
            ->assertSee('AI-assisted trend summary based on system-calculated Sales Trends');

        Http::assertSent(function ($request): bool {
            $body = $request->data();
            $promptText = $body['messages'][0]['content']."\n".$body['messages'][1]['content'];

            return $request->url() === 'https://api.groq.com/openai/v1/chat/completions'
                && $body['model'] === 'openai/gpt-oss-20b'
                && $body['max_tokens'] === 420
                && str_contains($promptText, SalesTrendSummaryService::SYSTEM_PROMPT)
                && str_contains($promptText, '"sales_total": 50000')
                && str_contains($promptText, '"sales_total": 145000')
                && str_contains($promptText, '"total_sales": 195000')
                && str_contains($promptText, '"quantity_sold_liters": 2500')
                && str_contains($promptText, '"valid_sales_count": 3')
                && str_contains($promptText, '"previous_period_sales": 50000')
                && str_contains($promptText, '"percentage_change": 190')
                && str_contains($promptText, '"fuel_type": "AI Diesel"')
                && str_contains($promptText, '"fuel_type": "AI E10"')
                && ! str_contains($promptText, 'SLS-AI-CANCELLED')
                && ! str_contains($promptText, 'Trend Customer')
                && ! str_contains($promptText, 'trend@example.com')
                && ! array_key_exists('key', $body);
        });

        Carbon::setTestNow();
    }

    public function test_stable_and_decreasing_trends_are_calculated_before_ai(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $records = $this->records();
        $this->sale($records, 'SLS-STABLE-AUG', '2026-08-01', 1000, 50);
        $this->sale($records, 'SLS-STABLE-SEP', '2026-09-01', 1000, 50);

        $payload = app(AIDataPreparationService::class)->prepareForUser($records['admin'], [
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-30',
            'trend_period' => 'month',
            'trend_year' => 2026,
        ]);
        $this->assertSame('stable', $payload['sales_trends']['previous_period_comparison']['direction']);
        $this->assertSame(0.0, $payload['sales_trends']['previous_period_comparison']['percentage_change']);

        $this->sale($records, 'SLS-DECREASE-OCT', '2026-10-01', 500, 50);
        $payload = app(AIDataPreparationService::class)->prepareForUser($records['admin'], [
            'date_from' => '2026-10-01',
            'date_to' => '2026-10-31',
            'trend_period' => 'month',
            'trend_year' => 2026,
        ]);

        $this->assertSame('decrease', $payload['sales_trends']['previous_period_comparison']['direction']);
        $this->assertSame(-50.0, $payload['sales_trends']['previous_period_comparison']['percentage_change']);

        Carbon::setTestNow();
    }

    public function test_no_sales_and_limited_history_skip_ai_call(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $records = $this->records();
        Http::fake();
        config($this->aiConfig());

        $this->actingAs($records['admin'])
            ->post(route('admin.reports.sales-trend-summary'), [
                'period' => 'date',
                'date' => '2026-09-15',
                'month' => '2026-09',
                'year' => '2026',
            ])
            ->assertRedirect()
            ->assertSessionHas('salesTrendSummaryNotice', 'Insufficient sales trend data for analysis.');

        $this->sale($records, 'SLS-LIMITED', '2026-09-15', 1000, 50);

        $this->actingAs($records['admin'])
            ->post(route('admin.reports.sales-trend-summary'), [
                'period' => 'date',
                'date' => '2026-09-15',
                'month' => '2026-09',
                'year' => '2026',
            ])
            ->assertRedirect()
            ->assertSessionHas('salesTrendSummaryNotice', 'Insufficient sales trend data for analysis.');

        Http::assertNothingSent();
        Carbon::setTestNow();
    }

    public function test_ai_failure_shows_friendly_fallback(): void
    {
        Carbon::setTestNow('2026-09-15 10:00:00');
        $records = $this->records();
        $this->sale($records, 'SLS-FAIL-AUG', '2026-08-10', 1000, 50);
        $this->sale($records, 'SLS-FAIL-SEP', '2026-09-10', 1000, 60);
        Http::fake(['api.groq.com/*' => Http::response(['unexpected' => true])]);
        config($this->aiConfig());

        $this->actingAs($records['admin'])
            ->post(route('admin.reports.sales-trend-summary'), [
                'period' => 'month',
                'month' => '2026-09',
                'year' => '2026',
            ])
            ->assertRedirect()
            ->assertSessionHas('salesTrendSummaryNotice', 'AI sales trend summary is temporarily unavailable. Existing Sales Trends reports remain available.');

        Carbon::setTestNow();
    }

    public function test_driver_cannot_generate_sales_trend_summary(): void
    {
        Http::fake();
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active']);

        $this->actingAs($driver)
            ->post(route('admin.reports.sales-trend-summary'), ['period' => 'all'])
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
        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => 'CUS-TREND',
            'name' => 'Trend Customer',
            'company_name' => 'Trend Customer Co.',
            'email' => 'trend@example.com',
            'payment_status' => 'clear',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $dieselId = DB::table('fuel_types')->insertGetId([
            'code' => 'DSL-TREND',
            'name' => 'AI Diesel',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $e10Id = DB::table('fuel_types')->insertGetId([
            'code' => 'E10-TREND',
            'name' => 'AI E10',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('admin', 'salesOfficer', 'customerId', 'dieselId', 'e10Id');
    }

    private function sale(
        array $records,
        string $code,
        string $date,
        float $quantity,
        float $unitPrice,
        ?int $fuelTypeId = null,
        string $status = 'confirmed'
    ): int {
        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => $code,
            'customer_id' => $records['customerId'],
            'sale_date' => $date,
            'payment_method' => 'bank_transfer',
            'payment_terms' => 'cod',
            'status' => $status,
            'created_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sale_items')->insert([
            'sale_id' => $saleId,
            'fuel_type_id' => $fuelTypeId ?? $records['dieselId'],
            'quantity_liters' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $quantity * $unitPrice,
            'fulfilled_quantity_liters' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $saleId;
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
