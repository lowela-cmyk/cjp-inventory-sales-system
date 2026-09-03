<?php

namespace App\Services;

use App\Models\User;

class InventoryVarianceExplanationService
{
    public const SYSTEM_PROMPT = 'You are a business reporting assistant for CJP Southern Star OPC.

Explain only inventory variance already detected and calculated by the system.

Do not invent missing transactions, quantities, financial values, causes, or corrections.

Clearly distinguish confirmed system-detected mismatches from possible explanations.

If the system data does not establish the cause of a variance, state that the transaction requires verification instead of guessing.

Provide concise and professional explanations that help management understand which records should be reviewed.';

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
        $payload = $this->variancePayload(
            $this->dataPreparation->prepareForUser($user, $filters)
        );

        if ($this->hasNoDetectedVariance($payload['inventory_variance'])) {
            return [
                'ok' => false,
                'text' => 'No inventory variance requiring explanation was detected for the selected period.',
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
                'text' => 'AI inventory variance explanation is temporarily unavailable. Existing variance analytics remain available.',
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
    private function variancePayload(array $preparedData): array
    {
        return [
            'reporting_period' => $preparedData['reporting_period'],
            'inventory_variance' => $preparedData['inventory_variance'],
            'instructions' => [
                'Use only these system-provided variance values.',
                'Do not calculate authoritative variance totals, rates, or quantities.',
                'Use requires verification when the system data does not prove the actual cause.',
                'Do not describe unpaid or partially paid valid sales as inventory variance unless listed in sample_variances or reason_breakdown.',
                'Use this structure where useful: Inventory Variance Summary, Main Variance Detected, Affected Area/Fuel Type, Possible Concern, Recommended Verification.',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $variance
     */
    private function hasNoDetectedVariance(array $variance): bool
    {
        return (int) $variance['summary']['variance_count'] <= 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function userPrompt(array $payload): string
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        return "Generate a concise management-level Inventory Variance Explanation from this prepared system payload.\n\n".$json;
    }
}
