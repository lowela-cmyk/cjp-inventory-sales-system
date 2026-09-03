<?php

namespace App\Services;

use App\Models\User;

class RevenueInsightService
{
    public const SYSTEM_PROMPT = 'You are a business reporting assistant for CJP Southern Star OPC.

Analyze only the numerical data provided by the system.

Do not invent figures or transactions.

Clearly distinguish actual sales, collected revenue, expected revenue, and outstanding receivables.

If the available data is insufficient for a conclusion, state that clearly.

Provide concise, professional, actionable business insights.';

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
        $payload = $this->revenuePayload(
            $this->dataPreparation->prepareForUser($user, $filters)
        );

        if ($this->hasInsufficientRevenueData($payload['revenue'])) {
            return [
                'ok' => false,
                'text' => 'Insufficient revenue data for analysis.',
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
            'max_output_tokens' => 450,
        ]);

        if (! $result['ok']) {
            return [
                'ok' => false,
                'text' => 'AI revenue insight is temporarily unavailable. Existing reports remain available.',
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
    private function revenuePayload(array $preparedData): array
    {
        return [
            'reporting_period' => $preparedData['reporting_period'],
            'revenue' => $preparedData['revenue'],
            'sales_trend_context' => [
                'reporting_period' => $preparedData['sales_trends']['reporting_period'],
                'year' => $preparedData['sales_trends']['year'],
                'total_sales' => $preparedData['sales_trends']['total_sales'],
                'total_quantity_sold_liters' => $preparedData['sales_trends']['total_quantity_sold_liters'],
                'previous_period_comparison' => $preparedData['sales_trends']['previous_period_comparison'],
            ],
            'receivables_context' => [
                'total_outstanding' => $preparedData['receivables']['total_outstanding'],
                'outstanding_sales_count' => $preparedData['receivables']['outstanding_sales_count'],
                'status_breakdown' => $preparedData['receivables']['status_breakdown'],
            ],
            'instructions' => [
                'Use only these system-provided numbers.',
                'Do not calculate official totals.',
                'Do not mention unavailable comparisons as if they exist.',
                'Use this structure: Revenue Summary, Key Observation, Receivables/Collection Observation, Management Consideration.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $revenue
     */
    private function hasInsufficientRevenueData(array $revenue): bool
    {
        return (float) $revenue['total_valid_sales'] <= 0.0
            && (float) $revenue['collected_revenue'] <= 0.0
            && (float) $revenue['expected_revenue'] <= 0.0
            && (float) $revenue['outstanding_receivables'] <= 0.0;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function userPrompt(array $payload): string
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        return "Generate a concise management-level Revenue Insight from this prepared system payload.\n\n".$json;
    }
}
