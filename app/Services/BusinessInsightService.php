<?php

namespace App\Services;

use App\Models\User;

class BusinessInsightService
{
    public const SYSTEM_PROMPT = 'You are a business reporting assistant for CJP Southern Star OPC.

Generate concise management-level business insights using only the system data provided.

Do not invent numbers, transactions, customers, causes, predictions, or business events.

Clearly distinguish facts from recommendations.

Prioritize significant issues and opportunities.

If available data is insufficient to support a conclusion, state that clearly.

Do not make automatic business decisions. Provide decision-support insights only.';

    public function __construct(
        private AIDataPreparationService $dataPreparation,
        private AIService $ai,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array{ok: bool, text: string, skipped: bool, error: ?string}
     */
    public function generateForUser(User $user, array $filters = []): array
    {
        $payload = $this->businessPayload(
            $this->dataPreparation->prepareForUser($user, $filters)
        );

        if ($this->hasInsufficientBusinessData($payload)) {
            return [
                'ok' => false,
                'text' => 'Insufficient business data for AI insight generation.',
                'skipped' => true,
                'error' => null,
            ];
        }

        $result = $this->ai->generateText([
            [
                'role' => 'system',
                'content' => self::SYSTEM_PROMPT,
            ],
            [
                'role' => 'user',
                'content' => $this->userPrompt($payload),
            ],
        ], [
            'temperature' => 0.2,
            'max_output_tokens' => 520,
        ]);

        if (! $result['ok']) {
            return [
                'ok' => false,
                'text' => 'AI business insights are temporarily unavailable. Existing analytics remain available.',
                'skipped' => false,
                'error' => $result['error'],
            ];
        }

        return [
            'ok' => true,
            'text' => (string) $result['text'],
            'skipped' => false,
            'error' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $preparedData
     * @return array<string, mixed>
     */
    private function businessPayload(array $preparedData): array
    {
        return [
            'reporting_period' => $preparedData['reporting_period'],
            'revenue' => $preparedData['revenue'],
            'sales_trends' => [
                'reporting_period' => $preparedData['sales_trends']['reporting_period'],
                'year' => $preparedData['sales_trends']['year'],
                'currency' => $preparedData['sales_trends']['currency'],
                'quantity_unit' => $preparedData['sales_trends']['quantity_unit'],
                'series' => $preparedData['sales_trends']['series'],
                'total_sales' => $preparedData['sales_trends']['total_sales'],
                'total_quantity_sold_liters' => $preparedData['sales_trends']['total_quantity_sold_liters'],
                'valid_sales_count' => $preparedData['sales_trends']['valid_sales_count'],
                'previous_period_comparison' => $preparedData['sales_trends']['previous_period_comparison'],
                'peak_period' => $preparedData['sales_trends']['peak_period'],
                'low_period' => $preparedData['sales_trends']['low_period'],
                'fuel_type_breakdown' => $preparedData['sales_trends']['fuel_type_breakdown'],
            ],
            'inventory' => [
                'quantity_unit' => $preparedData['inventory']['quantity_unit'],
                'current_stock_liters' => $preparedData['inventory']['current_stock_liters'],
                'fuel_type_breakdown' => $preparedData['inventory']['fuel_type_breakdown'],
                'recorded_movement_summary_liters' => $preparedData['inventory']['recorded_movement_summary_liters'],
            ],
            'fuel_lifting' => [
                'quantity_unit' => $preparedData['fuel_lifting']['quantity_unit'],
                'summary' => $preparedData['fuel_lifting']['summary'],
                'fuel_breakdown' => $preparedData['fuel_lifting']['fuel_breakdown'],
                'depot_breakdown' => $preparedData['fuel_lifting']['depot_breakdown'],
            ],
            'receivables' => $preparedData['receivables'],
            'inventory_variance' => [
                'quantity_unit' => $preparedData['inventory_variance']['quantity_unit'],
                'summary' => $preparedData['inventory_variance']['summary'],
                'reason_breakdown' => $preparedData['inventory_variance']['reason_breakdown'],
                'affected_fuel_types' => $preparedData['inventory_variance']['affected_fuel_types'],
                'uncertainty_note' => $preparedData['inventory_variance']['uncertainty_note'],
                'payment_status_boundary' => $preparedData['inventory_variance']['payment_status_boundary'],
            ],
            'ai_feature_context' => [
                'revenue_insights' => 'Revenue insight rules are represented by the revenue fields in this payload.',
                'sales_trend_summaries' => 'Sales trend summary rules are represented by the sales_trends fields in this payload.',
                'inventory_variance_explanation' => 'Inventory variance explanation rules are represented by the inventory_variance fields in this payload.',
            ],
            'instructions' => [
                'Use only these system-provided aggregate values.',
                'Do not calculate authoritative business totals, rates, or balances.',
                'Do not claim stock is low or critical unless a supplied threshold supports it.',
                'Keep recommendations advisory and do not suggest automatic record changes.',
                'Use this structure where useful: Executive Summary, Key Business Findings, Areas Requiring Attention, Positive Trends, Recommended Management Actions.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasInsufficientBusinessData(array $payload): bool
    {
        $revenue = $payload['revenue'];
        $salesTrends = $payload['sales_trends'];
        $inventory = $payload['inventory'];
        $fuelLifting = $payload['fuel_lifting']['summary'];
        $receivables = $payload['receivables'];
        $variance = $payload['inventory_variance']['summary'];

        return (float) $revenue['total_valid_sales'] <= 0.0
            && (float) $revenue['collected_revenue'] <= 0.0
            && (float) $revenue['expected_revenue'] <= 0.0
            && (float) $revenue['outstanding_receivables'] <= 0.0
            && (float) $salesTrends['total_sales'] <= 0.0
            && (float) $inventory['current_stock_liters'] <= 0.0
            && (float) $fuelLifting['purchased_liters'] <= 0.0
            && (float) $fuelLifting['lifted_liters'] <= 0.0
            && (float) $fuelLifting['unlifted_liters'] <= 0.0
            && (float) $receivables['total_outstanding'] <= 0.0
            && (int) $variance['transactions_checked'] <= 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function userPrompt(array $payload): string
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        return "Generate concise management-level Business Insights from this prepared system payload.\n\n".$json;
    }
}
