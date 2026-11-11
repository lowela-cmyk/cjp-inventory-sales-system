<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\HtmlString;

/**
 * Part 10: "Insights and A.I Reporting" on the dashboard. The AI gets the dashboard totals only (no customer
 * names or contact details) and explains them, including the stock-out vs receivables variance. Without an AI key,
 * or when the provider fails, the page shows a rule-based summary of the same numbers (the reason goes to the server log).
 */
class DashboardInsightService
{
    public const SYSTEM_PROMPT = 'You are a business reporting assistant for CJP Southern Star OPC, a fuel trading company.

Write AI Recommendations for management using only the dashboard figures provided.

Format rules (strict):
- Use these group headings, each on its own line, only when the group has a recommendation: "### Inventory", "### Fuel Lifting", "### Sales", "### Operational Improvements".
- Under each heading write bullet points that start with "- ". One concise, actionable recommendation per bullet (at most 25 words). At most 4 bullets per group.
- Put the key figure or keyword of each bullet in bold with **double asterisks**.
- Return only the final recommendation headings and bullets. Do not include reasoning, analysis, an introduction, a conclusion or tables. Do not repeat points.

Do not invent numbers, transactions, customers, causes or events. Explain the inventory variance (Missing amount and Inventory Variance Rate) when it is not zero. If the data is insufficient for a conclusion, say so in a bullet. Provide decision support only.';

    /** Part 20 #4: the recommendation groups, in display order. */
    public const GROUPS = ['Inventory', 'Fuel Lifting', 'Sales', 'Operational Improvements'];

    private const MAX_BULLETS_PER_GROUP = 5;

    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly AIService $ai,
    ) {}

    /**
     * Part 20 #4: the dashboard report. The overview sections are always computed from the database (never by the AI);
     * the AI, when configured, writes only the grouped recommendations, which are parsed into bullets. Without an AI
     * key, or when the provider fails or answers in an unusable format, the recommendations are rule-based.
     *
     * @param  array<string, mixed>  $inventoryFilters
     * @param  array<string, mixed>  $salesFilters
     * @return array{source: string, text: string, note: ?string, sections: array<int, array<string, mixed>>, recommendations: array<string, array<int, string>>, report: string}
     */
    public function generate(array $inventoryFilters = [], array $salesFilters = []): array
    {
        $payload = $this->payload($this->dashboard->data($inventoryFilters, $salesFilters));

        if ($this->isEmpty($payload)) {
            $text = 'No data yet: there are no purchases, stock or sales to summarize for the selected filters.';

            return ['source' => 'rules', 'text' => $text, 'note' => null, 'sections' => [], 'recommendations' => [], 'report' => $text];
        }

        $sections = $this->overview($payload);
        $result = $this->ai->generateText([
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user', 'content' => "Generate the grouped AI Recommendations from these dashboard figures.\n\n".json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)],
        ], ['temperature' => 0.2, 'max_output_tokens' => 720, 'reasoning_effort' => 'low']);

        if ($result['ok']) {
            // Part 20 final: a bullet citing a figure that is not in the dashboard data is dropped, never shown.
            $recommendations = $this->withoutUnsupportedFigures($this->parseRecommendations((string) $result['text']), $payload);
            if ($recommendations !== []) {
                return $this->result('ai', (string) $result['text'], $sections, $recommendations);
            }
            Log::warning('Dashboard insight: the AI answer had no usable recommendations; showing the rule-based recommendations.');
        } else {
            // Part 12: why the AI was not used is for the server log only; users just see the summary.
            Log::warning($result['error_type'] === 'configuration_error'
                ? 'Dashboard insight: AI is not configured (set AI_API_KEY in .env); showing the rule-based summary.'
                : 'Dashboard insight: the AI provider could not be used; showing the rule-based summary.', ['error' => $result['message']]);
        }

        return $this->result('rules', $this->ruleBasedSummary($payload), $sections, $this->ruleRecommendations($payload));
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @param  array<string, array<int, string>>  $recommendations
     * @return array{source: string, text: string, note: ?string, sections: array<int, array<string, mixed>>, recommendations: array<string, array<int, string>>, report: string}
     */
    private function result(string $source, string $text, array $sections, array $recommendations): array
    {
        return [
            'source' => $source,
            'text' => $text,
            'note' => null,
            'sections' => $sections,
            'recommendations' => $recommendations,
            'report' => $this->plainReport($sections, $recommendations),
        ];
    }

    /**
     * Totals only: no customer names, sale codes or contact details.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function payload(array $data): array
    {
        $inventory = $data['inventory'];
        $sales = $data['salesTrend'];
        $month = $data['revenue']['series']['month'];

        return [
            'currency' => 'PHP',
            'quantity_unit' => 'liters',
            'filters' => [
                'inventory' => [
                    'fuel_type' => $inventory['cards'][2][1],
                    'depot' => $inventory['cards'][3][1],
                    'purchase_search' => $inventory['filters']['search'] !== '' ? 'applied' : 'none',
                ],
                'sales' => [
                    'date_from' => $sales['filters']['date_from'],
                    'date_to' => $sales['filters']['date_to'],
                    'fuel_type_filter' => $sales['filters']['fuel_type_id'] ? 'applied' : 'none',
                    'customer_filter' => $sales['filters']['customer_id'] ? 'applied' : 'none',
                    'sales_search' => $sales['filters']['search'] !== '' ? 'applied' : 'none',
                ],
            ],
            'current_inventory' => $inventory['totals'],
            'expected_sales_revenue_monthly' => [
                'labels' => $month['labels'],
                'actual' => $month['actual'],
                'expected_3_month_moving_average' => $month['expected'],
                'last_complete_month' => $month['lastComplete'],
                'next_month_forecast' => $month['forecast'],
                'increase_rate_percent' => $month['increaseRate'],
            ],
            'stock_out_vs_receivables_delivered_sales' => [
                'stock_out_value' => $data['revenue']['margin']['stock_out'],
                'receivables_collected' => $data['revenue']['margin']['receivables'],
                'missing' => $data['revenue']['margin']['missing'],
                'inventory_variance_rate_percent' => $data['revenue']['margin']['variance_rate'],
            ],
            'sales_trend' => $sales['totals'],
            // Part 20 #4: live workflow counts across all records (counts only, no names or codes).
            'operations' => $this->operations(),
            'peak_demand' => [
                'year' => $sales['peak']['year'],
                'liters_by_month' => array_combine(['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'], $sales['peak']['monthly']),
                'month_with_highest_sales' => $sales['peak']['highestMonth'],
                'expected_month_with_most_demand_yoy' => $sales['peak']['yoyMonth'],
                'expected_increase_rate_next_month_percent' => $sales['peak']['nextMonthRate'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function ruleBasedSummary(array $payload): string
    {
        $inventory = $payload['current_inventory'];
        $revenue = $payload['expected_sales_revenue_monthly'];
        $margin = $payload['stock_out_vs_receivables_delivered_sales'];
        $sales = $payload['sales_trend'];
        $peak = $payload['peak_demand'];
        $money = fn (float $value): string => $this->dashboard->money($value);
        $kl = fn (float $liters): string => $this->dashboard->kl($liters);
        $percent = fn (?float $value): string => $value === null ? 'N/A' : $this->dashboard->percent($value);

        $byFuel = collect($inventory['stock_by_fuel'])->map(fn (float $liters, string $fuel): string => $fuel.' '.$kl($liters))->implode(', ');
        $lines = [];

        $lines[] = 'Inventory: '.$kl($inventory['stock_liters']).' in stock'.($byFuel !== '' ? ' ('.$byFuel.')' : '').'. '
            .$kl($inventory['remaining_liters']).' is still at the depots across '.number_format($inventory['purchases']).' purchase(s) and '.number_format($inventory['lifts']).' lift(s).';
        $lines[] = 'Expected Stock Out Rate: '.$percent($inventory['stock_out_rate']).' ('.$kl($inventory['pending_order_liters']).' promised to orders not delivered yet).'
            .($inventory['stock_out_rate'] !== null && $inventory['stock_out_rate'] > 100 ? ' Undelivered orders exceed the stock: record more purchases.' : '');
        $lines[] = 'Lifting: '.$inventory['lift_status']['unlifted'].' unlifted, '.$inventory['lift_status']['partial'].' partially lifted and '.$inventory['lift_status']['lifted'].' lifted purchase line(s); '
            .$percent($inventory['completed_percent']).' of purchased liters has left the depot.';
        $lines[] = 'Revenue: last complete month '.$money((float) $revenue['last_complete_month']).'; next month forecast (3-month average) '.$money((float) $revenue['next_month_forecast'])
            .', Increase Rate '.$percent($revenue['increase_rate_percent']).'.';
        $lines[] = 'Stock Out vs Receivables (delivered sales): stock out '.$money($margin['stock_out_value']).' vs collected '.$money($margin['receivables_collected'])
            .'; Missing '.$money($margin['missing']).' (Inventory Variance Rate '.$percent($margin['inventory_variance_rate_percent']).').'
            .($margin['missing'] > 0 ? ' Follow up the unpaid balances of delivered sales.' : ($margin['missing'] < 0 ? ' More was collected than the value stocked out: check the payments of delivered sales.' : ''));
        $lines[] = 'Sales: '.number_format($sales['sales']).' sale(s), '.$money($sales['collected']).' collected, '.$money($sales['unpaid']).' unpaid ('.number_format($sales['unpaid_customers']).' customer(s) owing, '
            .number_format($sales['paid_customers']).' fully paid). Gross margin '.$money($sales['profit']).' on '.$kl($sales['delivered_liters']).' delivered.';
        $lines[] = 'Demand ('.$peak['year'].'): highest month '.($peak['month_with_highest_sales'] ?? 'none yet').'; historically strongest month '.($peak['expected_month_with_most_demand_yoy'] ?? 'not enough history')
            .'; expected change next month '.$percent($peak['expected_increase_rate_next_month_percent']).'.';

        return implode("\n", $lines);
    }

    /**
     * Part 20 #4: counts of the work still open, across all records: lift lines by stage, lifted lines without a
     * withdrawal receipt, orders by delivery stage and receivables past their due date with a balance.
     *
     * @return array<string, int>
     */
    public function operations(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $hauls = fn () => DB::table('hauls')->whereNull('hauls.deleted_at')
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('purchases')->whereColumn('purchases.id', 'hauls.purchase_id')->whereNull('purchases.deleted_at'));
        $openSales = fn () => DB::table('sales')->whereNull('deleted_at')->whereNotIn('status', ['draft', 'cancelled']);
        $totals = DB::table('sale_items')->selectRaw('sale_id, SUM(line_total) as total')->groupBy('sale_id');
        $paid = DB::table('payments')->selectRaw('sale_id, SUM(amount) as paid')->groupBy('sale_id');

        return [
            'lift_lines_scheduled' => $hauls()->where('status', 'scheduled')->count(),
            'lift_lines_in_transit' => $hauls()->where('status', 'in_transit')->count(),
            'lift_lines_lifted_or_completed' => $hauls()->whereIn('status', ['lifted', 'completed'])->count(),
            'lifted_lines_without_withdrawal_receipt' => $hauls()->whereIn('status', ['lifted', 'completed'])->whereNull('withdrawal_receipt_path')->count(),
            'orders_pending_delivery' => $openSales()->where('delivery_status', 'pending')->count(),
            'orders_out_for_delivery' => $openSales()->where('delivery_status', 'out_for_delivery')->count(),
            'overdue_receivables' => DB::table('sales')
                ->join('receivables', 'receivables.sale_id', '=', 'sales.id')
                ->joinSub($totals, 'sale_totals', 'sale_totals.sale_id', '=', 'sales.id')
                ->leftJoinSub($paid, 'sale_paid', 'sale_paid.sale_id', '=', 'sales.id')
                ->whereNull('sales.deleted_at')
                ->whereNotIn('sales.status', ['draft', 'cancelled'])
                ->whereNotNull('receivables.due_date')
                ->whereDate('receivables.due_date', '<', $today->toDateString())
                ->whereRaw('ROUND(sale_totals.total, 2) > ROUND(COALESCE(sale_paid.paid, 0), 2)')
                ->count(),
        ];
    }

    /**
     * Part 20 #4: the dashboard overview: Inventory, Sales and Receivables, Fuel Lifting and Operational Insights,
     * each a short list of labelled figures from the database. "tone" marks a figure that needs attention.
     *
     * @param  array<string, mixed>  $payload
     * @return array<int, array{title: string, caption: ?string, items: array<int, array{label: string, value: string, tone: ?string}>}>
     */
    public function overview(array $payload): array
    {
        $inventory = $payload['current_inventory'];
        $revenue = $payload['expected_sales_revenue_monthly'];
        $margin = $payload['stock_out_vs_receivables_delivered_sales'];
        $sales = $payload['sales_trend'];
        $peak = $payload['peak_demand'];
        $ops = $payload['operations'];
        $money = fn (float $value): string => $this->dashboard->money($value);
        $kl = fn (float $liters): string => $this->dashboard->kl($liters);
        $percent = fn (?float $value): string => $value === null ? 'N/A' : $this->dashboard->percent($value);
        $item = fn (string $label, string $value, bool $attention = false): array => ['label' => $label, 'value' => $value, 'tone' => $attention ? 'attention' : null];
        $stockRate = $inventory['stock_out_rate'];

        return [
            [
                'title' => 'Inventory Overview',
                'caption' => null,
                'items' => array_merge([
                    $item('Total in stock', $kl((float) $inventory['stock_liters'])),
                ], collect($inventory['stock_by_fuel'])->map(fn (float $liters, string $fuel): array => $item($fuel, $kl($liters), $liters <= 0 && $fuel !== 'F1'))->values()->all(), [
                    $item('Promised to undelivered orders', $kl((float) $inventory['pending_order_liters'])),
                    $item('Expected Stock Out Rate', $percent($stockRate), $stockRate === null || $stockRate > 100),
                ]),
            ],
            [
                'title' => 'Sales and Receivables Overview',
                'caption' => null,
                'items' => [
                    $item('Sales', number_format((int) $sales['sales'])),
                    $item('Collected', $money((float) $sales['collected'])),
                    $item('Unpaid', $money((float) $sales['unpaid']), (float) $sales['unpaid'] > 0),
                    $item('Customers owing / fully paid', number_format((int) $sales['unpaid_customers']).' / '.number_format((int) $sales['paid_customers']), (int) $sales['unpaid_customers'] > 0),
                    $item('Gross margin (delivered)', $money((float) $sales['profit']).' on '.$kl((float) $sales['delivered_liters'])),
                    $item('Missing on delivered sales', $money((float) $margin['missing']).' ('.$percent($margin['inventory_variance_rate_percent']).')', (float) $margin['missing'] != 0.0),
                ],
            ],
            [
                'title' => 'Fuel Lifting Overview',
                'caption' => null,
                'items' => [
                    $item('Purchases / lifts', number_format((int) $inventory['purchases']).' / '.number_format((int) $inventory['lifts'])),
                    $item('Still at the depots', $kl((float) $inventory['remaining_liters'])),
                    $item('Purchased liters lifted', $percent((float) $inventory['completed_percent'])),
                    $item('Purchase lines unlifted / partial / lifted', $inventory['lift_status']['unlifted'].' / '.$inventory['lift_status']['partial'].' / '.$inventory['lift_status']['lifted'], (int) $inventory['lift_status']['unlifted'] > 0),
                    $item('Lift lines in transit', number_format($ops['lift_lines_in_transit'])),
                    $item('Lifted lines without receipt', number_format($ops['lifted_lines_without_withdrawal_receipt']), $ops['lifted_lines_without_withdrawal_receipt'] > 0),
                ],
            ],
            [
                'title' => 'Operational Insights',
                'caption' => 'Open work across all records',
                'items' => [
                    $item('Orders pending delivery', number_format($ops['orders_pending_delivery']), $ops['orders_pending_delivery'] > 0),
                    $item('Orders out for delivery', number_format($ops['orders_out_for_delivery'])),
                    $item('Overdue receivables', number_format($ops['overdue_receivables']), $ops['overdue_receivables'] > 0),
                    $item('Last complete month revenue', $money((float) $revenue['last_complete_month'])),
                    $item('Next month forecast', $money((float) $revenue['next_month_forecast']).' ('.$percent($revenue['increase_rate_percent']).')', $revenue['increase_rate_percent'] !== null && $revenue['increase_rate_percent'] < 0),
                    $item('Peak month ('.$peak['year'].')', (string) ($peak['month_with_highest_sales'] ?? 'None yet')),
                ],
            ],
        ];
    }

    /**
     * Part 20 #4: rule-based AI Recommendations: one action per bullet, only when the figures call for it, grouped as
     * self::GROUPS. Key figures are wrapped in **...** (rendered bold, see bulletHtml()).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, array<int, string>>
     */
    public function ruleRecommendations(array $payload): array
    {
        $inventory = $payload['current_inventory'];
        $revenue = $payload['expected_sales_revenue_monthly'];
        $margin = $payload['stock_out_vs_receivables_delivered_sales'];
        $sales = $payload['sales_trend'];
        $peak = $payload['peak_demand'];
        $ops = $payload['operations'];
        $money = fn (float $value): string => $this->dashboard->money($value);
        $kl = fn (float $liters): string => $this->dashboard->kl($liters);
        $percent = fn (?float $value): string => $value === null ? 'N/A' : $this->dashboard->percent($value);
        $count = fn (int $n, string $one, string $many): string => number_format($n).' '.($n === 1 ? $one : $many);
        $groups = array_fill_keys(self::GROUPS, []);

        $rate = $inventory['stock_out_rate'];
        if ($rate === null) {
            $groups['Inventory'][] = 'Open orders need **'.$kl((float) $inventory['pending_order_liters']).'** but **no fuel is on hand**: record purchases before confirming more orders.';
        } elseif ($rate > 100) {
            $groups['Inventory'][] = 'Undelivered orders need **'.$percent($rate).'** of the fuel on hand: **record more purchases** before confirming new orders.';
        } elseif ($rate >= 80) {
            $groups['Inventory'][] = '**'.$percent($rate).'** of the fuel on hand is already promised to orders: plan the **next purchase** now.';
        }
        foreach ($inventory['stock_by_fuel'] as $fuel => $liters) {
            if ($fuel !== 'F1' && (float) $liters <= 0) {
                $groups['Inventory'][] = '**'.$fuel.'** has **no stock**: restock before accepting new '.$fuel.' orders.';
            }
        }

        if ((int) $inventory['lift_status']['unlifted'] > 0) {
            $groups['Fuel Lifting'][] = 'Schedule lifts for **'.$count((int) $inventory['lift_status']['unlifted'], 'unlifted purchase line', 'unlifted purchase lines').'**; **'.$kl((float) $inventory['remaining_liters']).'** is still at the depots.';
        } elseif ((float) $inventory['remaining_liters'] > 0) {
            $groups['Fuel Lifting'][] = 'Lift the remaining **'.$kl((float) $inventory['remaining_liters']).'** still at the depots.';
        }
        if ((int) $inventory['lift_status']['partial'] > 0) {
            $groups['Fuel Lifting'][] = 'Complete the **'.$count((int) $inventory['lift_status']['partial'], 'partially lifted purchase line', 'partially lifted purchase lines').'**.';
        }
        if ($ops['lifted_lines_without_withdrawal_receipt'] > 0) {
            $groups['Fuel Lifting'][] = 'Ask drivers to upload the **withdrawal receipt** for **'.$count($ops['lifted_lines_without_withdrawal_receipt'], 'lifted load', 'lifted loads').'** without one.';
        }
        if ($ops['lift_lines_in_transit'] > 0) {
            $groups['Fuel Lifting'][] = 'Follow up **'.$count($ops['lift_lines_in_transit'], 'lift line', 'lift lines').' in transit** until marked Lifted.';
        }

        if ((float) $margin['missing'] > 0) {
            $groups['Sales'][] = 'Collect the **'.$money((float) $margin['missing']).'** still unpaid on delivered sales (Inventory Variance Rate **'.$percent($margin['inventory_variance_rate_percent']).'**).';
        } elseif ((float) $margin['missing'] < 0) {
            $groups['Sales'][] = 'Collections exceed the stocked-out value by **'.$money(abs((float) $margin['missing'])).'**: **check the payments** of delivered sales.';
        }
        if ((int) $sales['unpaid_customers'] > 0 && (float) $sales['unpaid'] > 0) {
            $groups['Sales'][] = 'Follow up **'.$count((int) $sales['unpaid_customers'], 'customer', 'customers').'** owing **'.$money((float) $sales['unpaid']).'** in total.';
        }
        if ($ops['overdue_receivables'] > 0) {
            $groups['Sales'][] = 'Contact the customers of the **'.$count($ops['overdue_receivables'], 'overdue receivable', 'overdue receivables').'** first.';
        }
        if ($revenue['increase_rate_percent'] !== null && (float) $revenue['increase_rate_percent'] < 0) {
            $groups['Sales'][] = 'Next month\'s revenue forecast is **'.$percent(abs((float) $revenue['increase_rate_percent'])).' lower**: review open quotations and customer demand.';
        }

        if ($ops['orders_pending_delivery'] > 0) {
            $groups['Operational Improvements'][] = 'Move the **'.$count($ops['orders_pending_delivery'], 'pending order', 'pending orders').'** to **Out for Delivery** as their trucks leave.';
        }
        if ($ops['orders_out_for_delivery'] > 0) {
            $groups['Operational Improvements'][] = 'Mark the **'.$count($ops['orders_out_for_delivery'], 'order', 'orders').' out for delivery** as **Delivered** once received, so Stock Out stays current.';
        }
        if (($peak['expected_month_with_most_demand_yoy'] ?? null) !== null) {
            $groups['Operational Improvements'][] = 'Build stock ahead of **'.$peak['expected_month_with_most_demand_yoy'].'**, historically the strongest month.';
        }

        $groups = array_filter($groups);
        if ($groups === []) {
            $groups['Operational Improvements'] = ['No action needed: stock, lifting and receivables are **up to date** for these filters.'];
        }

        return $groups;
    }

    /**
     * Part 20 #4: the AI answer as grouped bullets. Headings map onto self::GROUPS; "- ", "* ", "• " and "1." lines are
     * bullets; any other text is split into sentences, one bullet each, so a paragraph never reaches the page. Repeats
     * are dropped. Returns [] when nothing usable is found.
     *
     * @return array<string, array<int, string>>
     */
    public function parseRecommendations(string $text): array
    {
        $groups = [];
        $seen = [];
        $group = 'Operational Improvements';
        $add = function (string $bullet) use (&$groups, &$seen, &$group): void {
            $bullet = trim(preg_replace('/\s+/u', ' ', strip_tags($bullet)) ?? '');
            $bullet = trim(str_replace(['`', '__'], '', $bullet));
            $key = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $bullet) ?? '');
            if (mb_strlen($key) < 8 || isset($seen[$key]) || count($groups[$group] ?? []) >= self::MAX_BULLETS_PER_GROUP) {
                return;
            }
            $seen[$key] = true;
            $groups[$group][] = mb_strimwidth($bullet, 0, 320, '…');
        };

        $lines = preg_split('/\R/u', $text) ?: [];
        // With group headings, text before the first one is an introduction ("Here is the report."), not advice.
        $hasHeadings = collect($lines)->contains(fn (string $line): bool => $this->recommendationGroup(trim($line)) !== null);
        $inGroup = ! $hasHeadings;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $heading = $this->recommendationGroup($line);
            if ($heading !== null) {
                $group = $heading;
                $inGroup = true;

                continue;
            }
            if (! $inGroup) {
                continue;
            }
            if (preg_match('/^(?:[-*•–]|\d+[.)])\s+(.+)$/u', $line, $match)) {
                $add($match[1]);

                continue;
            }
            // A heading-like line that is not one of the groups ("## Summary") carries no recommendation.
            if (preg_match('/^#{1,6}\s/u', $line)) {
                continue;
            }
            foreach (preg_split('/(?<=[.!?])\s+(?=[\p{Lu}*])/u', $line) ?: [] as $sentence) {
                $add($sentence);
            }
        }

        return collect(self::GROUPS)->filter(fn (string $name): bool => ! empty($groups[$name]))
            ->mapWithKeys(fn (string $name): array => [$name => $groups[$name]])
            ->all();
    }

    /**
     * Part 20 final: drops every AI bullet that cites a figure the dashboard data does not contain, so no invented
     * statistic reaches the page. A figure is supported when it equals (to 2 decimals) a number in the payload, or
     * that number in KL (liters / 1000), or its absolute value. Whole numbers up to 12 (months, "3-month average",
     * small counts) are allowed as they are. Years in the payload (e.g. 2026) are payload numbers too.
     *
     * @param  array<string, array<int, string>>  $groups
     * @param  array<string, mixed>  $payload
     * @return array<string, array<int, string>>
     */
    public function withoutUnsupportedFigures(array $groups, array $payload): array
    {
        $known = [];
        array_walk_recursive($payload, function (mixed $value) use (&$known): void {
            // Database aggregates may arrive as numeric strings.
            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                foreach ([(float) $value, (float) $value / 1000, abs((float) $value), abs((float) $value) / 1000] as $form) {
                    $known[number_format(round($form, 2), 2, '.', '')] = true;
                }
            }
        });
        $supported = function (string $bullet) use ($known): bool {
            preg_match_all('/\d[\d,]*(?:\.\d+)?/', str_replace('**', '', $bullet), $matches);
            foreach ($matches[0] as $figure) {
                $number = (float) str_replace(',', '', $figure);
                $isSmallWhole = ! str_contains($figure, '.') && $number <= 12;
                if (! $isSmallWhole && ! isset($known[number_format(round($number, 2), 2, '.', '')])) {
                    return false;
                }
            }

            return true;
        };

        $kept = [];
        foreach ($groups as $group => $bullets) {
            $valid = array_values(array_filter($bullets, $supported));
            if (count($valid) < count($bullets)) {
                Log::warning('Dashboard insight: dropped AI recommendation(s) citing figures not in the dashboard data.', ['group' => $group, 'dropped' => count($bullets) - count($valid)]);
            }
            if ($valid !== []) {
                $kept[$group] = $valid;
            }
        }

        return $kept;
    }

    private function recommendationGroup(string $line): ?string
    {
        $label = mb_strtolower(trim(preg_replace('/^#{1,6}\s*|[*_:]/u', '', $line) ?? ''));

        return match (true) {
            in_array($label, ['inventory', 'inventory recommendations', 'stock'], true) => 'Inventory',
            in_array($label, ['fuel lifting', 'lifting', 'fuel lifting recommendations'], true) => 'Fuel Lifting',
            in_array($label, ['sales', 'sales and receivables', 'receivables', 'sales recommendations'], true) => 'Sales',
            in_array($label, ['operational improvements', 'operations', 'operational', 'operational insights'], true) => 'Operational Improvements',
            default => null,
        };
    }

    /**
     * Part 20 #4: one bullet as safe HTML. The text is escaped first, so only the **bold** markers become tags.
     */
    public static function bulletHtml(string $bullet): HtmlString
    {
        $html = e($bullet);
        $html = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $html) ?? $html;

        return new HtmlString(str_replace('**', '', $html));
    }

    /**
     * The report as plain text (overview, then the recommendations as "- " bullets), for the PDF export.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @param  array<string, array<int, string>>  $recommendations
     */
    public function plainReport(array $sections, array $recommendations): string
    {
        $lines = [];
        foreach ($sections as $section) {
            $lines[] = $section['title'];
            foreach ($section['items'] as $item) {
                $lines[] = '  '.$item['label'].': '.$item['value'];
            }
            $lines[] = '';
        }
        $lines[] = 'AI Recommendations';
        foreach ($recommendations as $group => $bullets) {
            $lines[] = $group;
            foreach ($bullets as $bullet) {
                $lines[] = '  - '.str_replace('**', '', $bullet);
            }
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isEmpty(array $payload): bool
    {
        $inventory = $payload['current_inventory'];

        return (float) $inventory['stock_liters'] <= 0
            && (int) $inventory['purchases'] === 0
            && (int) $payload['sales_trend']['sales'] === 0
            && (float) $payload['stock_out_vs_receivables_delivered_sales']['stock_out_value'] <= 0
            && array_sum(array_filter($payload['expected_sales_revenue_monthly']['actual'], fn (?float $value): bool => $value !== null)) <= 0;
    }
}
