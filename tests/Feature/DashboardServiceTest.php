<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PART 10 (October 22, 2026): Dashboard and Analytics. Every expected value below is computed by hand from the
 * records seeded in seed() (see its comments).
 */
class DashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-22 10:00:00', self::FIXED_TIMEZONE));
        $this->today = CarbonImmutable::parse('2026-10-22');
    }

    // ----- Section 1: Current Inventory ------------------------------------------------------------------

    public function test_current_inventory_kpis_and_charts_match_the_records(): void
    {
        $this->seed_();
        $inventory = $this->dashboard()->inventory();
        $totals = $inventory['totals'];

        // Stock = stock-in remaining: P1 20,000 - 2,000 (S_A) + P3 5,000 = 23,000 Premium; P2 10,000 - 4,000 (S_C) - 1,500
        // promised to the undelivered S_B (a sale deducts when it is saved) = 4,500 Diesel.
        $this->assertSame(27500.0, $totals['stock_liters']);
        $this->assertSame(['Premium' => 23000.0, 'Unleaded' => 0.0, 'Diesel' => 4500.0, 'F1' => 0.0], $totals['stock_by_fuel']);
        // P1, P2, P3 (P4 is cancelled).
        $this->assertSame(3, $totals['purchases']);
        // Still at the depots: P1 20,000 - 12,000 lifted (its scheduled 3,000 is not lifted yet) - 2,000 delivered straight
        // from the depot (S_A) + P2 0 + P3 5,000 = 11,000 L.
        $this->assertSame(11000.0, $totals['remaining_liters']);
        // Schedules S1, S2, S3; S4 is cancelled.
        $this->assertSame(3, $totals['lifts']);
        // Promised to the undelivered order S_B: 1,500 L / 29,000 L on hand (not delivered yet) = 5.17%.
        $this->assertSame(1500.0, $totals['pending_order_liters']);
        $this->assertSame(5.17, $totals['stock_out_rate']);
        $this->assertSame(['unlifted' => 1, 'partial' => 1, 'lifted' => 1], $totals['lift_status']);
        // Completed = left the depot: 35,000 purchased - 11,000 still there = 24,000 L.
        $this->assertSame(24000.0, $totals['completed_liters']);
        $this->assertSame(11000.0, $totals['uncompleted_liters']);
        $this->assertSame(68.57, $totals['completed_percent']);

        $this->assertSame([
            ['Total Inventory in Stock', '27.50 KL'],
            ['Total Purchases', '3'],
            ['Fuel Type', 'ALL'],
            ['Depot', 'ALL'],
            ['Remaining Balance in Depots', '11.00 KL'],
            ['Total Lifts', '3'],
        ], $inventory['cards']);
        $this->assertSame('5.17%', $inventory['stockOutRateLabel']);
        $this->assertSame(['Premium', 'Unleaded', 'Diesel', 'F1'], $inventory['stockChart']['labels']);
        $this->assertSame(['#E8453C', '#3B9A35', '#E28A22', '#0D1424'], $inventory['stockChart']['datasets'][0]['backgroundColor']);
        $this->assertSame([1, 1, 1], $inventory['liftingChart']['datasets'][0]['data']);
        $this->assertSame(['Completed', 'Uncompleted'], $inventory['purchasedChart']['labels']);
        $this->assertSame(['68.6%', '31.4%'], $inventory['purchasedChart']['sliceLabels']);
        // Axes as on the mockup.
        $this->assertSame('ticks', $inventory['stockChart']['yAxis']);
        $this->assertSame('none', $inventory['liftingChart']['yAxis']);
    }

    public function test_current_inventory_filters_change_the_results(): void
    {
        $ids = $this->seed_();

        // Depot D2: only P3 (unlifted; its 5,000 L are its stock).
        $d2 = $this->dashboard()->inventory(['depot_id' => $ids['d2']]);
        $this->assertSame(1, $d2['totals']['purchases']);
        $this->assertSame(5000.0, $d2['totals']['remaining_liters']);
        $this->assertSame(0, $d2['totals']['lifts']);
        $this->assertSame(5000.0, $d2['totals']['stock_liters']);
        $this->assertSame(0.0, $d2['totals']['stock_out_rate']);
        $this->assertSame('South Depot', $d2['cards'][3][1]);

        // Depot D1: P1 (18,000 L left) + P2 (6,000 L left); S_B is sold from P2.
        $d1 = $this->dashboard()->inventory(['depot_id' => $ids['d1']]);
        $this->assertSame(2, $d1['totals']['purchases']);
        $this->assertSame(6000.0, $d1['totals']['remaining_liters']);
        $this->assertSame(22500.0, $d1['totals']['stock_liters']);
        $this->assertSame(1500.0, $d1['totals']['pending_order_liters']);
        $this->assertSame(['Total Inventory in Stock', '22.50 KL'], $d1['cards'][0]);

        // Fuel Diesel: P2 only, fully lifted; 1,500 / 6,000 = 25%.
        $diesel = $this->dashboard()->inventory(['fuel_type_id' => $ids['fuel']['DSL']]);
        $this->assertSame(1, $diesel['totals']['purchases']);
        $this->assertSame(0.0, $diesel['totals']['remaining_liters']);
        $this->assertSame(1, $diesel['totals']['lifts']);
        $this->assertSame(4500.0, $diesel['totals']['stock_liters']);
        $this->assertSame(25.0, $diesel['totals']['stock_out_rate']);
        $this->assertSame(100.0, $diesel['totals']['completed_percent']);
        $this->assertSame(['Diesel'], $diesel['stockChart']['labels']);
        $this->assertSame('Diesel', $diesel['cards'][2][1]);
        $this->assertSame([['Total Inventory in Stock', '4.50 KL'], ['Total Purchases', '1']], array_slice($diesel['cards'], 0, 2));

        // Purchase ID search.
        $p3 = $this->dashboard()->inventory(['search' => 'DASH-003']);
        $this->assertSame(1, $p3['totals']['purchases']);
        $this->assertSame(['unlifted' => 1, 'partial' => 0, 'lifted' => 0], $p3['totals']['lift_status']);
    }

    // ----- Section 2: Revenue -----------------------------------------------------------------------------

    public function test_expected_sales_revenue_uses_a_three_period_moving_average(): void
    {
        $this->seed_();
        $series = $this->dashboard()->revenue($this->today)['series'];

        // Month: Sep 2026 = 240,000 (S_C); Oct 2026 to date = 237,500 (S_A + S_B); S_D is cancelled.
        $month = $series['month'];
        $this->assertCount(13, $month['labels']);
        $this->assertSame('Nov 2025', $month['labels'][0]);
        $this->assertSame('Oct 2026', $month['labels'][11]);
        $this->assertSame('Nov 2026', $month['labels'][12]);
        $this->assertSame(240000.0, $month['actual'][10]);
        $this->assertSame(237500.0, $month['actual'][11]);
        $this->assertNull($month['actual'][12]);
        // Expected Oct = avg(Jul, Aug, Sep) = 80,000; the next month's forecast is the same three months.
        $this->assertSame(80000.0, $month['expected'][11]);
        $this->assertSame(80000.0, $month['forecast']);
        // Increase Rate: (80,000 - 240,000) / 240,000 = -66.67%.
        $this->assertSame(240000.0, $month['lastComplete']);
        $this->assertSame(-66.67, $month['increaseRate']);

        // Year: 2025 = 50,000 (S_E); forecast = avg(2023, 2024, 2025) = 16,666.67.
        $year = $series['year'];
        $this->assertSame(['2022', '2023', '2024', '2025', '2026', '2027'], $year['labels']);
        $this->assertSame(50000.0, $year['lastComplete']);
        $this->assertSame(16666.67, $year['forecast']);
        $this->assertSame(-66.67, $year['increaseRate']);

        // Week: Oct 19-25 holds nothing; Oct 05-11 holds S_A + S_B.
        $week = $series['week'];
        $this->assertSame('Oct 19', $week['labels'][11]);
        $this->assertSame(237500.0, $week['actual'][9]);
    }

    public function test_stock_out_vs_receivables_counts_delivered_sales_at_sale_price(): void
    {
        $this->seed_();
        $margin = $this->dashboard()->stockOutMargin();

        // Delivered: S_A 140,000 + S_C 240,000 + S_E 50,000 billed = 430,000.
        $this->assertSame(430000.0, $margin['receivables']);
        // Released: 2,000 L x 70 (S_A) + 4,000 L x 60 (S_C); S_E has no release.
        $this->assertSame(380000.0, $margin['stock_out']);
        $this->assertSame(50000.0, $margin['missing']);
        // 50,000 / 430,000 = 11.63%.
        $this->assertSame(11.63, $margin['variance_rate']);
        $this->assertSame('PHP 50,000.00', $margin['missingLabel']);
        $this->assertTrue($margin['chart']['showValues']);
    }

    // ----- Section 3: Sales Trend -------------------------------------------------------------------------

    public function test_sales_trend_kpis_and_charts_match_the_records(): void
    {
        $this->seed_();
        $sales = $this->dashboard()->salesTrend([], $this->today);
        $totals = $sales['totals'];

        // Valid sales: S_A, S_B, S_C, S_E.
        $this->assertSame(4, $totals['sales']);
        // Payments: S_A 140,000 + S_B 50,000 + S_E 50,000.
        $this->assertSame(240000.0, $totals['collected']);
        // Owing: S_B 47,500 + S_C 240,000.
        $this->assertSame(287500.0, $totals['unpaid']);
        // Released 2,000 + 4,000 L.
        $this->assertSame(6000.0, $totals['delivered_liters']);
        // 2,000 x (70 - 50) + 4,000 x (60 - 45).
        $this->assertSame(100000.0, $totals['profit']);
        // Alpha: S_A, S_E paid. Bravo: S_B partial, S_C unpaid.
        $this->assertSame(1, $totals['paid_customers']);
        $this->assertSame(1, $totals['unpaid_customers']);
        $this->assertSame(['paid' => 2, 'unpaid' => 1, 'partial' => 1], $totals['payment_status']);
        // F1 is not shown: none of it was sold.
        $this->assertSame(['Unleaded' => 1000.0, 'Premium' => 2000.0, 'Diesel' => 5500.0], $totals['fuel_sold']);

        $this->assertSame([
            ['Collected Revenue', 'PHP 240,000.00'],
            ['Total Delivered', '6.00 KL'],
            ['Total Sales', '4'],
            ['Total Profit', 'PHP 100,000.00'],
            ['Unpaid Amount', 'PHP 287,500.00'],
            ['Paid Customers', '1'],
            ['Unpaid Customers', '1'],
        ], $sales['cards']);

        $peak = $sales['peak'];
        $this->assertSame(2026, $peak['year']);
        $this->assertSame(4000.0, $peak['monthly'][8]);
        $this->assertSame(3500.0, $peak['monthly'][9]);
        $this->assertSame('September', $peak['highestMonth']);
        // The only past year with sales is 2025 (March).
        $this->assertSame('March', $peak['yoyMonth']);
        // Jul 0, Aug 0, Sep 4,000: forecast 1,333.33 vs 4,000 = -66.67%.
        $this->assertSame(-66.67, $peak['nextMonthRate']);
    }

    public function test_sales_trend_filters_change_the_results(): void
    {
        $ids = $this->seed_();

        $diesel = $this->dashboard()->salesTrend(['fuel_type_id' => $ids['fuel']['DSL']], $this->today)['totals'];
        $this->assertSame(2, $diesel['sales']);
        $this->assertSame(50000.0, $diesel['collected']);
        $this->assertSame(287500.0, $diesel['unpaid']);
        $this->assertSame(4000.0, $diesel['delivered_liters']);
        $this->assertSame(60000.0, $diesel['profit']);
        $this->assertSame(['Diesel' => 5500.0], $diesel['fuel_sold']);

        // The KPI cards follow the filters too (Part 2 revisions, item 6).
        $alphaCards = $this->dashboard()->salesTrend(['customer_id' => $ids['alpha']], $this->today)['cards'];
        $this->assertSame([['Collected Revenue', 'PHP 190,000.00'], ['Total Delivered', '2.00 KL'], ['Total Sales', '2']], array_slice($alphaCards, 0, 3));
        $alpha = $this->dashboard()->salesTrend(['customer_id' => $ids['alpha']], $this->today)['totals'];
        $this->assertSame(2, $alpha['sales']);
        $this->assertSame(190000.0, $alpha['collected']);
        $this->assertSame(0.0, $alpha['unpaid']);
        $this->assertSame(1, $alpha['paid_customers']);
        $this->assertSame(0, $alpha['unpaid_customers']);
        $this->assertSame(40000.0, $alpha['profit']);

        $october = $this->dashboard()->salesTrend(['date_from' => '2026-10-01', 'date_to' => '2026-10-31'], $this->today);
        $this->assertSame(2, $october['totals']['sales']);
        $this->assertSame(190000.0, $october['totals']['collected']);
        $this->assertSame(47500.0, $october['totals']['unpaid']);
        $this->assertSame('October', $october['peak']['highestMonth']);

        $this->assertSame(1, $this->dashboard()->salesTrend(['search' => 'DASH-C'], $this->today)['totals']['sales']);
    }

    public function test_the_sales_date_dropdown_applies_its_period(): void
    {
        $this->seed_();
        $admin = $this->admin();

        // This Month (October 2026): S_A + S_B; S_C is September.
        $this->actingAs($admin)->get(route('admin.dashboard', ['sales_period' => 'this_month']))->assertOk()
            ->assertSeeInOrder(['Total Sales', '2', 'Total Profit'])
            ->assertSee('<option value="this_month" selected>This Month</option>', false);
        // Last Month (September 2026): S_C only.
        $this->actingAs($admin)->get(route('admin.dashboard', ['sales_period' => 'last_month']))->assertOk()
            ->assertSeeInOrder(['Collected Revenue', 'PHP 0.00', 'Total Delivered', '4.00 KL', 'Total Sales', '1']);
        // Last Year: S_E.
        $this->actingAs($admin)->get(route('admin.dashboard', ['sales_period' => 'last_year']))->assertOk()
            ->assertSeeInOrder(['Collected Revenue', 'PHP 50,000.00']);
        $this->actingAs($admin)->from(route('admin.dashboard'))->get(route('admin.dashboard', ['sales_period' => 'decade']))
            ->assertSessionHasErrors('sales_period');
    }

    public function test_a_fuel_filter_splits_sale_payments_by_line_share(): void
    {
        $ids = $this->seed_();
        // Two lines: Premium 70,000 + Diesel 65,000 = 135,000; 67,500 paid.
        $saleId = $this->sale('SLS-DASH-MIX', $ids['alpha'], '2026-10-12', 'confirmed', 'pending', [[$ids['fuel']['PREM'], 1000, 70], [$ids['fuel']['DSL'], 1000, 65]]);
        $this->payment($saleId, 67500);

        $premium = $this->dashboard()->salesTrend(['fuel_type_id' => $ids['fuel']['PREM'], 'search' => 'MIX'], $this->today)['totals'];
        // 67,500 x 70,000 / 135,000.
        $this->assertSame(35000.0, $premium['collected']);
        $this->assertSame(35000.0, $premium['unpaid']);
        $this->assertSame(['partial' => 1], array_filter($premium['payment_status']));
    }

    // ----- Empty data and the page ---------------------------------------------------------------------------

    public function test_empty_data_returns_zeros_and_the_page_shows_no_data_yet(): void
    {
        $data = $this->dashboard()->data([], [], $this->today);

        $this->assertSame(0.0, $data['inventory']['totals']['stock_liters']);
        $this->assertSame(0.0, $data['inventory']['totals']['stock_out_rate']);
        $this->assertTrue($data['inventory']['stockChart']['empty']);
        $this->assertTrue($data['inventory']['purchasedChart']['empty']);
        $this->assertTrue($data['revenue']['series']['month']['empty']);
        $this->assertNull($data['revenue']['series']['month']['increaseRate']);
        $this->assertSame(0.0, $data['revenue']['margin']['variance_rate']);
        $this->assertSame(0, $data['salesTrend']['totals']['sales']);
        $this->assertSame('No sales yet', $data['salesTrend']['peak']['highestMonthLabel']);
        $this->assertSame('Not enough history', $data['salesTrend']['peak']['yoyMonthLabel']);

        $html = $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertOk()->getContent();
        $this->assertStringContainsString('No data yet', $html);
        $this->assertStringNotContainsString('data-dashboard-chart', $html);
        foreach (['Current Inventory', 'Expected Sales Revenue', 'Stock Out V.S Receivables Margin', 'Sales Trend', 'Insights and A.I Reporting', 'Generate Insight and Reports'] as $heading) {
            $this->assertStringContainsString($heading, $html);
        }
    }

    public function test_the_page_shows_the_figures_and_applies_its_filters(): void
    {
        $ids = $this->seed_();
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk()
            ->assertSeeInOrder(['Total Inventory in Stock', '27.50 KL', 'Total Purchases', '3', 'Remaining Balance in Depots', '11.00 KL'])
            ->assertSee('Expected Stock Out Rate:')
            ->assertSee('5.17%')
            ->assertSee('PHP 50,000.00')
            ->assertSee('11.63%')
            ->assertSee('PHP 240,000.00')
            ->assertSee('September')
            ->assertSee('data-dashboard-chart', false)
            ->assertSee('data-revenue-period="week"', false);

        // Each section keeps the other section's filters.
        $html = $this->actingAs($admin)->get(route('admin.dashboard', ['inv_depot_id' => $ids['d2'], 'sales_customer_id' => $ids['alpha']]))->assertOk()->getContent();
        $this->assertStringContainsString('<input type="hidden" name="sales_customer_id" value="'.$ids['alpha'].'">', $html);
        $this->assertStringContainsString('<input type="hidden" name="inv_depot_id" value="'.$ids['d2'].'">', $html);
        $this->assertStringContainsString('PHP 190,000.00', $html);

        // Invalid filters are rejected.
        $this->actingAs($admin)->from(route('admin.dashboard'))
            ->get(route('admin.dashboard', ['sales_date_from' => '2026-10-10', 'sales_date_to' => '2026-10-01']))
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHasErrors('sales_date_to');
        $this->actingAs($admin)->from(route('admin.dashboard'))
            ->get(route('admin.dashboard', ['inv_depot_id' => 999999]))
            ->assertSessionHasErrors('inv_depot_id');
    }

    // ----- Section 4: Insights and A.I Reporting ---------------------------------------------------------------

    public function test_without_an_ai_key_the_insight_is_a_rule_based_summary_with_a_note(): void
    {
        $this->seed_();
        Http::fake();
        Config::set('services.ai.api_key', '');

        $this->actingAs($this->admin())->post(route('admin.dashboard.insight'), ['inv_search' => 'DASH'])
            ->assertRedirect(route('admin.dashboard', ['inv_search' => 'DASH']).'#insights')
            ->assertSessionHas('dashboardInsight.source', 'rules')
            ->assertSessionHas('dashboardInsight.note', 'AI is not configured (set AI_API_KEY in .env), so this is a rule-based summary of the dashboard numbers.');
        Http::assertNothingSent();

        $text = session('dashboardInsight.text');
        $this->assertStringContainsString('Inventory: 27.50 KL in stock (Premium 23.00 KL, Unleaded 0.00 KL, Diesel 4.50 KL, F1 0.00 KL).', $text);
        $this->assertStringContainsString('Missing PHP 50,000.00 (Inventory Variance Rate 11.63%). Check delivered sales whose released liters are less than billed.', $text);
        $this->assertStringContainsString('Gross margin PHP 100,000.00 on 6.00 KL delivered.', $text);

        $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertOk()
            ->assertSee('AI is not configured (set AI_API_KEY in .env)')
            ->assertSee('Rule-based summary of the dashboard totals.');
    }

    public function test_with_an_ai_key_the_ai_gets_totals_only_and_no_customer_details(): void
    {
        $ids = $this->seed_();
        $this->configureAi();
        Http::fake(['api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => 'Executive Summary. Stock is 16 KL; PHP 50,000 of delivered sales is missing.']]]])]);

        $this->actingAs($this->admin())->post(route('admin.dashboard.insight'), ['sales_customer_id' => $ids['alpha']])
            ->assertSessionHas('dashboardInsight.source', 'ai')
            ->assertSessionHas('dashboardInsight.text', 'Executive Summary. Stock is 16 KL; PHP 50,000 of delivered sales is missing.');
        $this->assertNull(session('dashboardInsight.note'));

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $request): bool {
            $body = json_encode($request->data());
            foreach (['Alpha Trading', 'Ana Alpha', 'Bravo Builders', 'Ben Bravo', 'SLS-DASH', 'CUS-A'] as $private) {
                $this->assertStringNotContainsString($private, $body);
            }
            $prompt = collect($request->data()['messages'])->last()['content'];
            $payload = json_decode(substr($prompt, strpos($prompt, '{')), true);
            $this->assertSame('applied', $payload['filters']['sales']['customer_filter']);
            $this->assertEquals(50000, $payload['stock_out_vs_receivables_delivered_sales']['missing']);
            $this->assertEquals(27500, $payload['current_inventory']['stock_liters']);

            return true;
        });

        $this->actingAs($this->admin())->get(route('admin.dashboard'))->assertOk()
            ->assertSee('AI-assisted insight from the dashboard totals.');
    }

    public function test_an_ai_provider_failure_falls_back_to_the_rule_based_summary(): void
    {
        $this->seed_();
        $this->configureAi();
        Http::fake(['api.groq.com/*' => Http::response(['error' => 'down'], 500)]);

        $this->actingAs($this->admin())->post(route('admin.dashboard.insight'))
            ->assertSessionHas('dashboardInsight.source', 'rules');
        $this->assertStringStartsWith('The AI provider could not be used (', session('dashboardInsight.note'));
        $this->assertStringContainsString('Inventory: 27.50 KL in stock', session('dashboardInsight.text'));
    }

    public function test_no_data_skips_the_ai_call_and_other_roles_cannot_generate_insights(): void
    {
        $this->configureAi();
        Http::fake();

        $this->actingAs($this->admin())->post(route('admin.dashboard.insight'))
            ->assertSessionHas('dashboardInsight.text', 'No data yet: there are no purchases, stock or sales to summarize for the selected filters.');
        Http::assertNothingSent();

        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active', 'approval_status' => 'approved']);
        $this->actingAs($driver)->post(route('admin.dashboard.insight'))->assertForbidden();
        $this->actingAs($driver)->get(route('admin.dashboard'))->assertForbidden();
    }

    // ----- Seed ---------------------------------------------------------------------------------------------

    /**
     * Purchases (depot / fuel / ordered L / cost):
     * - P1 D1 Premium 20,000 @ 50: lift H1 12,000 Completed (S1); lift H3 3,000 Scheduled (S3).
     * - P2 D1 Diesel 10,000 @ 45: lift H2 10,000 Lifted (S2), delivering 4,000 to client sale S_C.
     * - P3 D2 Premium 5,000 @ 52: lift H4 Cancelled (S4).
     * - P4 D2 Diesel 9,999, cancelled.
     * Sales (customer / date / status / delivery / line / payment):
     * - S_A Alpha 2026-10-05 paid, delivered: Premium 2,000 @ 70 = 140,000 from P1; released 2,000 from P1; paid 140,000.
     * - S_B Bravo 2026-10-10 confirmed, pending: Diesel 1,500 @ 65 = 97,500 from P2; paid 50,000.
     * - S_C Bravo 2026-09-15 Depot to Client, unpaid, delivered: Diesel 4,000 @ 60 = 240,000 from P2; released by lift H2.
     * - S_D Alpha 2026-10-01 cancelled: Premium 1,000 @ 70.
     * - S_E Alpha 2025-03-10 paid, delivered: Unleaded 1,000 @ 50 = 50,000; no release recorded; paid 50,000.
     *
     * @return array<string, mixed>
     */
    private function seed_(): array
    {
        $fuel = DB::table('fuel_types')->whereIn('code', ['PREM', 'UNL', 'DSL', 'F1'])->pluck('id', 'code')->map(fn (mixed $id): int => (int) $id)->all();
        $now = now();
        $d1 = DB::table('depots')->insertGetId(['depot_code' => 'DEP-N', 'name' => 'North Depot', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $d2 = DB::table('depots')->insertGetId(['depot_code' => 'DEP-S', 'name' => 'South Depot', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $alpha = DB::table('customers')->insertGetId(['customer_code' => 'CUS-A', 'name' => 'Ana Alpha', 'company_name' => 'Alpha Trading', 'payment_status' => 'clear', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $bravo = DB::table('customers')->insertGetId(['customer_code' => 'CUS-B', 'name' => 'Ben Bravo', 'company_name' => 'Bravo Builders', 'payment_status' => 'clear', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $driver = User::factory()->create(['role' => 'driver', 'status' => 'active']);
        $truck = DB::table('trucks')->insertGetId(['truck_code' => 'TRK-D', 'capacity_liters' => 40000, 'truck_type' => 'hauling', 'status' => 'available', 'created_at' => $now, 'updated_at' => $now]);

        $purchase = function (string $code, int $depot, string $fuelCode, float $liters, float $cost, string $status = 'ordered') use ($fuel, $now): int {
            $id = DB::table('purchases')->insertGetId(['purchase_code' => $code, 'depot_id' => $depot, 'purchase_date' => '2026-09-01', 'payment_status' => 'unpaid', 'status' => $status, 'workflow_status' => 'pending', 'created_at' => $now, 'updated_at' => $now]);

            return DB::table('purchase_items')->insertGetId(['purchase_id' => $id, 'fuel_type_id' => $fuel[$fuelCode], 'quantity_ordered_liters' => $liters, 'unit_cost' => $cost, 'line_total' => $liters * $cost, 'created_at' => $now, 'updated_at' => $now]);
        };
        $p1 = $purchase('PUR-DASH-001', $d1, 'PREM', 20000, 50);
        $p2 = $purchase('PUR-DASH-002', $d1, 'DSL', 10000, 45);
        $p3 = $purchase('PUR-DASH-003', $d2, 'PREM', 5000, 52);
        $purchase('PUR-DASH-004', $d2, 'DSL', 9999, 45, 'cancelled');

        $haul = function (string $code, int $itemId, float $liters, string $status) use ($driver, $truck, $now): int {
            $item = DB::table('purchase_items')->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')->where('purchase_items.id', $itemId)->first(['purchase_items.purchase_id', 'purchase_items.fuel_type_id', 'purchases.depot_id']);
            $schedule = DB::table('lifting_schedules')->insertGetId(['schedule_code' => $code, 'depot_id' => $item->depot_id, 'truck_id' => $truck, 'driver_user_id' => $driver->id, 'scheduled_at' => '2026-09-05 08:00:00', 'status' => $status, 'created_at' => $now, 'updated_at' => $now]);

            return DB::table('hauls')->insertGetId(['lifting_schedule_id' => $schedule, 'haul_code' => $code.'-01', 'purchase_id' => $item->purchase_id, 'purchase_item_id' => $itemId, 'depot_id' => $item->depot_id, 'fuel_type_id' => $item->fuel_type_id, 'truck_id' => $truck, 'driver_user_id' => $driver->id, 'scheduled_at' => '2026-09-05 08:00:00', 'quantity_liters' => $liters, 'status' => $status, 'created_at' => $now, 'updated_at' => $now]);
        };
        $h1 = $haul('LFT-S1', $p1, 12000, 'completed');
        $h2 = $haul('LFT-S2', $p2, 10000, 'lifted');
        $haul('LFT-S3', $p1, 3000, 'scheduled');
        $haul('LFT-S4', $p3, 5000, 'cancelled');

        $sA = $this->sale('SLS-DASH-A', $alpha, '2026-10-05', 'paid', 'delivered', [[$fuel['PREM'], 2000, 70, $p1, 2000]]);
        $sB = $this->sale('SLS-DASH-B', $bravo, '2026-10-10', 'confirmed', 'pending', [[$fuel['DSL'], 1500, 65, $p2]]);
        $sC = $this->sale('SLS-DASH-C', $bravo, '2026-09-15', 'unpaid', 'delivered', [[$fuel['DSL'], 4000, 60, $p2, 4000]], 'depot_to_client');
        $this->sale('SLS-DASH-D', $alpha, '2026-10-01', 'cancelled', 'cancelled', [[$fuel['PREM'], 1000, 70]]);
        $sE = $this->sale('SLS-DASH-E', $alpha, '2025-03-10', 'paid', 'delivered', [[$fuel['UNL'], 1000, 50, null, 1000]]);
        $this->payment($sA, 140000);
        $this->payment($sB, 50000);
        $this->payment($sE, 50000);

        // S_A (Standard): 2,000 L delivered straight from P1 at the depot.
        $itemA = (int) DB::table('sale_items')->where('sale_id', $sA)->value('id');
        DB::table('stock_outs')->insert(['stock_out_code' => 'SO-A', 'sale_id' => $sA, 'sale_item_id' => $itemA, 'customer_id' => $alpha, 'fuel_type_id' => $fuel['PREM'], 'portion' => 'main', 'depot_id' => $d1, 'purchase_item_id' => $p1, 'source_type' => 'depot', 'quantity_liters' => 2000, 'unit_cost' => 50, 'stock_out_at' => '2026-10-06 08:00:00', 'status' => 'released', 'created_at' => $now, 'updated_at' => $now]);

        // S_C: 4,000 L released straight from the depot by lift H2.
        $direct = DB::table('haul_allocations')->insertGetId(['haul_id' => $h2, 'fuel_type_id' => $fuel['DSL'], 'destination_type' => 'customer', 'customer_id' => $bravo, 'sale_id' => $sC, 'quantity_liters' => 4000, 'status' => 'delivered', 'receive_on_completion' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('stock_outs')->insert(['stock_out_code' => 'SO-C', 'sale_id' => $sC, 'sale_item_id' => (int) DB::table('sale_items')->where('sale_id', $sC)->value('id'), 'customer_id' => $bravo, 'fuel_type_id' => $fuel['DSL'], 'depot_id' => $d1, 'haul_allocation_id' => $direct, 'purchase_item_id' => $p2, 'portion' => 'main', 'source_type' => 'depot', 'quantity_liters' => 4000, 'unit_cost' => 45, 'stock_out_at' => '2026-09-16 08:00:00', 'status' => 'released', 'created_at' => $now, 'updated_at' => $now]);

        // Purchases written straight to the tables get their stock-in as after an import.
        $this->artisan('stock-in:sync')->assertSuccessful();

        return compact('fuel', 'd1', 'd2', 'alpha', 'bravo');
    }

    /**
     * @param  array<int, array{0: int, 1: float, 2: float, 3?: ?int, 4?: float}>  $lines  fuel, liters, price, purchase line, fulfilled
     */
    private function sale(string $code, int $customerId, string $date, string $status, string $delivery, array $lines, string $type = 'garage_to_client'): int
    {
        $id = DB::table('sales')->insertGetId(['sale_code' => $code, 'customer_id' => $customerId, 'sale_date' => $date, 'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment', 'status' => $status, 'delivery_status' => $delivery, 'delivery_type' => $type, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($lines as $line) {
            DB::table('sale_items')->insert(['sale_id' => $id, 'fuel_type_id' => $line[0], 'purchase_item_id' => $line[3] ?? null, 'quantity_liters' => $line[1], 'unit_price' => $line[2], 'line_total' => $line[1] * $line[2], 'main_quantity_liters' => $line[1], 'fulfilled_quantity_liters' => $line[4] ?? 0, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $id;
    }

    private function payment(int $saleId, float $amount): void
    {
        DB::table('payments')->insert(['payment_code' => 'PAY-'.$saleId.'-'.(int) $amount, 'sale_id' => $saleId, 'payment_date' => '2026-10-15', 'amount' => $amount, 'method' => 'cash_on_delivery', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function configureAi(): void
    {
        Config::set('services.ai.provider', 'groq');
        Config::set('services.ai.api_key', 'test-groq-key');
        Config::set('services.ai.model', 'openai/gpt-oss-20b');
        Config::set('services.ai.base_url', 'https://api.groq.com/openai/v1');
    }

    private function dashboard(): DashboardService
    {
        return app(DashboardService::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'status' => 'active', 'approval_status' => 'approved']);
    }
}
