<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\RevenueInsightService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RevenueInsightsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_generate_revenue_insight_from_prepared_system_values(): void
    {
        Carbon::setTestNow('2026-09-02 10:00:00');
        $records = $this->records();
        $saleId = $this->sale($records, 'SLS-REV-AI', '2026-09-02', 100000, 'partially_paid', 'installment');
        $scheduleId = DB::table('payment_schedules')->insertGetId([
            'sale_id' => $saleId,
            'due_date' => '2026-09-30',
            'amount_due' => 100000,
            'status' => 'partial',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->payment($records, $saleId, 40000, $scheduleId);

        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => "Revenue Summary\nActual sales were PHP 100,000.\n\nKey Observation\nCollections reached PHP 40,000.\n\nReceivables/Collection Observation\nOutstanding receivables are PHP 60,000.\n\nManagement Consideration\nReview collection follow-up.",
                    ],
                ]],
            ]),
        ]);
        config($this->aiConfig());

        $response = $this->actingAs($records['admin'])->post(route('admin.reports.revenue-insight'), [
            'period' => 'date',
            'date' => '2026-09-02',
            'month' => '2026-09',
            'year' => '2026',
        ]);

        $response->assertRedirect(route('admin.reports', [
            'period' => 'date',
            'date' => '2026-09-02',
            'start_date' => null,
            'end_date' => null,
            'month' => '2026-09',
            'year' => '2026',
        ]));
        $response->assertSessionHas('revenueInsight');

        $this->followingRedirects()
            ->actingAs($records['admin'])
            ->get(route('admin.reports', ['period' => 'date', 'date' => '2026-09-02', 'month' => '2026-09', 'year' => '2026']))
            ->assertOk()
            ->assertSee('Revenue AI Insight')
            ->assertSee('Revenue Summary')
            ->assertSee('AI-assisted business insight based on system records');

        Http::assertSent(function ($request): bool {
            $body = $request->data();
            $promptText = $body['messages'][0]['content']."\n".$body['messages'][1]['content'];

            return $request->url() === 'https://api.groq.com/openai/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-groq-key')
                && $body['model'] === 'openai/gpt-oss-20b'
                && $body['max_tokens'] === 450
                && str_contains($promptText, RevenueInsightService::SYSTEM_PROMPT)
                && str_contains($promptText, '"total_valid_sales": 100000')
                && str_contains($promptText, '"collected_revenue": 40000')
                && str_contains($promptText, '"outstanding_receivables": 60000')
                && str_contains($promptText, '"previous_period_comparison"')
                && str_contains($promptText, '"current_period_sales": 100000')
                && str_contains($promptText, '"previous_period_sales": 0')
                && str_contains($promptText, 'previous period had zero sales')
                && ! str_contains($promptText, 'Revenue Insight Customer')
                && ! str_contains($promptText, 'revenue@example.com')
                && ! array_key_exists('key', $body);
        });

        Carbon::setTestNow();
    }

    public function test_zero_revenue_period_skips_ai_call(): void
    {
        Carbon::setTestNow('2026-09-02 10:00:00');
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);
        Http::fake();
        config($this->aiConfig());

        $this->actingAs($admin)
            ->post(route('admin.reports.revenue-insight'), [
                'period' => 'date',
                'date' => '2024-01-01',
                'month' => '2024-01',
                'year' => '2024',
            ])
            ->assertRedirect()
            ->assertSessionHas('revenueInsightNotice', 'Insufficient revenue data for analysis.');

        Http::assertNothingSent();
        Carbon::setTestNow();
    }

    public function test_api_rate_limit_shows_usage_limit_message(): void
    {
        Carbon::setTestNow('2026-09-02 10:00:00');
        $records = $this->records();
        $saleId = $this->sale($records, 'SLS-REV-FAIL', '2026-09-02', 50000);
        $this->payment($records, $saleId, 10000);
        config($this->aiConfig());

        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'rate limit detail']], 429)]);

        $this->actingAs($records['admin'])
            ->post(route('admin.reports.revenue-insight'), [
                'period' => 'date',
                'date' => '2026-09-02',
                'month' => '2026-09',
                'year' => '2026',
            ])
            ->assertRedirect()
            ->assertSessionHas('revenueInsightNotice', 'AI service usage limit reached. Please try again later.');

        Carbon::setTestNow();
    }

    public function test_malformed_ai_response_shows_friendly_fallback(): void
    {
        Carbon::setTestNow('2026-09-02 10:00:00');
        $records = $this->records();
        $saleId = $this->sale($records, 'SLS-REV-MALFORMED', '2026-09-02', 50000);
        $this->payment($records, $saleId, 10000);
        config($this->aiConfig());

        Http::fake(['api.groq.com/*' => Http::response(['unexpected' => true])]);

        $this->actingAs($records['admin'])
            ->post(route('admin.reports.revenue-insight'), [
                'period' => 'date',
                'date' => '2026-09-02',
                'month' => '2026-09',
                'year' => '2026',
            ])
            ->assertRedirect()
            ->assertSessionHas('revenueInsightNotice', 'AI insights are temporarily unavailable. System analytics are still available.');

        Carbon::setTestNow();
    }

    public function test_ai_output_with_script_like_text_is_escaped_in_reports(): void
    {
        Carbon::setTestNow('2026-09-02 10:00:00');
        $records = $this->records();
        $saleId = $this->sale($records, 'SLS-REV-HTML', '2026-09-02', 50000);
        $this->payment($records, $saleId, 10000);
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => "Revenue Summary\n<script>alert('x')</script>",
                    ],
                ]],
            ]),
        ]);
        config($this->aiConfig());

        $this->actingAs($records['admin'])
            ->post(route('admin.reports.revenue-insight'), [
                'period' => 'date',
                'date' => '2026-09-02',
                'month' => '2026-09',
                'year' => '2026',
            ])
            ->assertRedirect();

        $this->followingRedirects()
            ->actingAs($records['admin'])
            ->get(route('admin.reports', ['period' => 'date', 'date' => '2026-09-02', 'month' => '2026-09', 'year' => '2026']))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;', false)
            ->assertDontSee("<script>alert('x')</script>", false);

        Carbon::setTestNow();
    }

    public function test_driver_cannot_generate_revenue_insight(): void
    {
        Http::fake();
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active']);

        $this->actingAs($driver)
            ->post(route('admin.reports.revenue-insight'), ['period' => 'all'])
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
            'customer_code' => 'CUS-REV-AI',
            'name' => 'Revenue Insight Customer',
            'company_name' => 'Revenue Insight Co.',
            'email' => 'revenue@example.com',
            'payment_status' => 'partial',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $fuelTypeId = DB::table('fuel_types')->insertGetId([
            'code' => 'DSL-REV-AI',
            'name' => 'Revenue Diesel',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('admin', 'salesOfficer', 'customerId', 'fuelTypeId');
    }

    /**
     * @param  array<string, mixed>  $records
     */
    private function sale(array $records, string $code, string $date, float $amount, string $status = 'confirmed', string $terms = 'cod'): int
    {
        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => $code,
            'customer_id' => $records['customerId'],
            'sale_date' => $date,
            'payment_method' => 'bank_transfer',
            'payment_terms' => $terms,
            'status' => $status,
            'created_by' => $records['salesOfficer']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sale_items')->insert([
            'sale_id' => $saleId,
            'fuel_type_id' => $records['fuelTypeId'],
            'quantity_liters' => 1000,
            'unit_price' => $amount / 1000,
            'line_total' => $amount,
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

        return $saleId;
    }

    /**
     * @param  array<string, mixed>  $records
     */
    private function payment(array $records, int $saleId, float $amount, ?int $scheduleId = null): void
    {
        DB::table('payments')->insert([
            'payment_code' => 'PAY-REV-'.$saleId.'-'.$amount,
            'sale_id' => $saleId,
            'payment_schedule_id' => $scheduleId,
            'payment_date' => '2026-09-02',
            'amount' => $amount,
            'method' => 'bank_transfer',
            'reference_number' => 'PAY-REV-REF',
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
