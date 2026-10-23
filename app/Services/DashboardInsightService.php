<?php

namespace App\Services;

/**
 * Part 10: "Insights and A.I Reporting" on the dashboard. The AI gets the dashboard totals only (no customer
 * names or contact details) and explains them, including the stock-out vs receivables variance. Without an AI key,
 * or when the provider fails, the page shows a rule-based summary of the same numbers with a note saying so.
 */
class DashboardInsightService
{
    public const SYSTEM_PROMPT = 'You are a business reporting assistant for CJP Southern Star OPC, a fuel trading company.

Write concise management-level insights using only the dashboard figures provided.

Do not invent numbers, transactions, customers, causes or events. Clearly separate facts from recommendations.

Explain the inventory variance (Missing amount and Inventory Variance Rate) when it is not zero.

If the data is insufficient for a conclusion, say so. Provide decision support only.';

    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly AIService $ai,
    ) {}

    /**
     * @param  array<string, mixed>  $inventoryFilters
     * @param  array<string, mixed>  $salesFilters
     * @return array{source: string, text: string, note: ?string}
     */
    public function generate(array $inventoryFilters = [], array $salesFilters = []): array
    {
        $payload = $this->payload($this->dashboard->data($inventoryFilters, $salesFilters));

        if ($this->isEmpty($payload)) {
            return ['source' => 'rules', 'text' => 'No data yet: there are no purchases, stock or sales to summarize for the selected filters.', 'note' => null];
        }

        $result = $this->ai->generateText([
            ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
            ['role' => 'user', 'content' => "Generate the dashboard insight report from these figures.\n\n".json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)],
        ], ['temperature' => 0.2, 'max_output_tokens' => 520]);

        if ($result['ok']) {
            return ['source' => 'ai', 'text' => (string) $result['text'], 'note' => null];
        }

        $note = $result['error_type'] === 'configuration_error'
            ? 'AI is not configured (set AI_API_KEY in .env), so this is a rule-based summary of the dashboard numbers.'
            : 'The AI provider could not be used ('.$result['message'].'), so this is a rule-based summary of the dashboard numbers.';

        return ['source' => 'rules', 'text' => $this->ruleBasedSummary($payload), 'note' => $note];
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
                'receivables_billed' => $data['revenue']['margin']['receivables'],
                'missing' => $data['revenue']['margin']['missing'],
                'inventory_variance_rate_percent' => $data['revenue']['margin']['variance_rate'],
            ],
            'sales_trend' => $sales['totals'],
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
        $lines[] = 'Stock Out vs Receivables (delivered sales): stock out '.$money($margin['stock_out_value']).' vs billed '.$money($margin['receivables_billed'])
            .'; Missing '.$money($margin['missing']).' (Inventory Variance Rate '.$percent($margin['inventory_variance_rate_percent']).').'
            .($margin['missing'] > 0 ? ' Check delivered sales whose released liters are less than billed.' : ($margin['missing'] < 0 ? ' More fuel was released than billed: check the releases of delivered sales.' : ''));
        $lines[] = 'Sales: '.number_format($sales['sales']).' sale(s), '.$money($sales['collected']).' collected, '.$money($sales['unpaid']).' unpaid ('.number_format($sales['unpaid_customers']).' customer(s) owing, '
            .number_format($sales['paid_customers']).' fully paid). Gross margin '.$money($sales['profit']).' on '.$kl($sales['delivered_liters']).' delivered.';
        $lines[] = 'Demand ('.$peak['year'].'): highest month '.($peak['month_with_highest_sales'] ?? 'none yet').'; historically strongest month '.($peak['expected_month_with_most_demand_yoy'] ?? 'not enough history')
            .'; expected change next month '.$percent($peak['expected_increase_rate_next_month_percent']).'.';

        return implode("\n", $lines);
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
            && (float) $payload['stock_out_vs_receivables_delivered_sales']['receivables_billed'] <= 0
            && array_sum(array_filter($payload['expected_sales_revenue_monthly']['actual'], fn (?float $value): bool => $value !== null)) <= 0;
    }
}
