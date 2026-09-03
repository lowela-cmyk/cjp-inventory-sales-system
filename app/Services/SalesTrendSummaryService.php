<?php

namespace App\Services;

use App\Models\User;

class SalesTrendSummaryService
{
    public const SYSTEM_PROMPT = 'You are a business reporting assistant for CJP Southern Star OPC.

Analyze only the sales trend data supplied by the system.

Do not invent numbers, transactions, customers, or explanations that are not supported by the provided data.

Clearly explain important increases, decreases, stable periods, peaks, and notable fuel-type patterns.

If the available data is insufficient to identify a meaningful trend, state that clearly.

Keep the summary concise, professional, and useful for management decision-making.';

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
        $payload = $this->salesTrendPayload(
            $this->dataPreparation->prepareForUser($user, $filters)
        );

        if ($this->hasInsufficientTrendData($payload['sales_trends'])) {
            return [
                'ok' => false,
                'text' => 'Insufficient sales trend data for analysis.',
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
            'max_output_tokens' => 420,
        ]);

        if (! $result['ok']) {
            return [
                'ok' => false,
                'text' => $result['message'],
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
    private function salesTrendPayload(array $preparedData): array
    {
        return [
            'reporting_period' => $preparedData['reporting_period'],
            'sales_trends' => $preparedData['sales_trends'],
            'instructions' => [
                'Use only these system-provided sales trend numbers.',
                'Do not calculate authoritative totals or percentages.',
                'Do not mention customers or transactions that are not in this payload.',
                'Use this structure where useful: Sales Trend Summary, Key Trend, Peak/Low Period, Fuel-Type Observation, Management Consideration.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $salesTrends
     */
    private function hasInsufficientTrendData(array $salesTrends): bool
    {
        $seriesWithSales = collect($salesTrends['series'])
            ->filter(fn (array $row): bool => (float) $row['sales_total'] > 0)
            ->count();

        return (float) $salesTrends['total_sales'] <= 0.0
            || $seriesWithSales < 2;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function userPrompt(array $payload): string
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        return "Generate a concise management-level Sales Trend Summary from this prepared system payload.\n\n".$json;
    }
}
