<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class AIService
{
    /**
     * @param  string|array<int, array{role?: string, content?: string}>  $messages
     * @return array{ok: bool, text: ?string, error: ?string, status: ?int, provider: string, model: string}
     */
    public function generateText(string|array $messages, array $options = []): array
    {
        $provider = strtolower((string) config('services.ai.provider', 'groq'));
        $model = (string) config('services.ai.model', 'openai/gpt-oss-20b');

        if (! in_array($provider, ['gemini', 'groq'], true)) {
            return $this->failure('Unsupported AI provider configured.', null, $provider, $model);
        }

        $apiKey = (string) config('services.ai.api_key', '');
        if (trim($apiKey) === '') {
            return $this->failure('AI API key is not configured.', null, $provider, $model);
        }

        $baseUrl = rtrim((string) config('services.ai.base_url', $this->defaultBaseUrl($provider)), '/');
        $timeout = max(1, (int) config('services.ai.timeout', 20));
        $url = $provider === 'groq' ? $baseUrl.'/chat/completions' : $baseUrl.'/interactions';

        try {
            $request = Http::timeout($timeout)
                ->acceptJson()
                ->asJson();

            $response = $provider === 'groq'
                ? $request->withToken($apiKey)->post($url, $this->groqPayload($messages, $model, $options))
                : $request->withHeader('x-goog-api-key', $apiKey)->post($url, $this->geminiPayload($messages, $model));
        } catch (ConnectionException) {
            return $this->failure('AI provider connection timed out or the network is unavailable.', null, $provider, $model);
        } catch (Throwable) {
            return $this->failure('AI provider request failed before a response was received.', null, $provider, $model);
        }

        if ($response->failed()) {
            return $this->failure($this->errorForStatus($response->status()), $response->status(), $provider, $model);
        }

        $json = $response->json();
        if (! is_array($json)) {
            return $this->failure('AI provider returned a malformed response.', $response->status(), $provider, $model);
        }

        $text = $provider === 'groq'
            ? $this->extractGroqText($json)
            : $this->extractGeminiText($json);
        if ($text === '') {
            return $this->failure('AI provider returned no usable text.', $response->status(), $provider, $model);
        }

        return [
            'ok' => true,
            'text' => $text,
            'error' => null,
            'status' => $response->status(),
            'provider' => $provider,
            'model' => $model,
        ];
    }

    /**
     * @return array{ok: bool, text: ?string, error: ?string, status: ?int, provider: string, model: string}
     */
    public function testConnection(): array
    {
        return $this->generateText('Reply with: AI connection successful.', [
            'temperature' => 0,
            'max_output_tokens' => 32,
        ]);
    }

    private function defaultBaseUrl(string $provider): string
    {
        return $provider === 'groq'
            ? 'https://api.groq.com/openai/v1'
            : 'https://generativelanguage.googleapis.com/v1';
    }

    /**
     * @param  string|array<int, array{role?: string, content?: string}>  $messages
     * @return array<string, mixed>
     */
    private function geminiPayload(string|array $messages, string $model): array
    {
        $messageList = is_string($messages)
            ? [['role' => 'user', 'content' => $messages]]
            : $messages;

        $input = [];
        $systemInstruction = [];

        foreach ($messageList as $message) {
            $role = strtolower((string) ($message['role'] ?? 'user'));
            $content = trim((string) ($message['content'] ?? ''));

            if ($content === '') {
                continue;
            }

            if ($role === 'system') {
                $systemInstruction[] = $content;

                continue;
            }

            $input[] = strtoupper($role === 'assistant' || $role === 'model' ? 'assistant' : 'user').': '.$content;
        }

        $payload = [
            'model' => $model,
            'input' => implode("\n\n", $input) ?: 'Reply with: AI connection successful.',
        ];

        if ($systemInstruction !== []) {
            $payload['system_instruction'] = implode("\n\n", $systemInstruction);
        }

        return $payload;
    }

    /**
     * @param  string|array<int, array{role?: string, content?: string}>  $messages
     * @return array<string, mixed>
     */
    private function groqPayload(string|array $messages, string $model, array $options): array
    {
        $messageList = is_string($messages)
            ? [['role' => 'user', 'content' => $messages]]
            : $messages;

        $normalizedMessages = collect($messageList)
            ->map(function (array $message): array {
                $role = strtolower((string) ($message['role'] ?? 'user'));
                $content = trim((string) ($message['content'] ?? ''));

                return [
                    'role' => in_array($role, ['system', 'assistant', 'user'], true) ? $role : 'user',
                    'content' => $content,
                ];
            })
            ->filter(fn (array $message): bool => $message['content'] !== '')
            ->values()
            ->all();

        return [
            'model' => $model,
            'messages' => $normalizedMessages ?: [
                ['role' => 'user', 'content' => 'Reply with: AI connection successful.'],
            ],
            'temperature' => (float) ($options['temperature'] ?? 0.2),
            'max_tokens' => (int) ($options['max_output_tokens'] ?? 512),
        ];
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function extractGroqText(array $json): string
    {
        $text = $json['choices'][0]['message']['content'] ?? null;

        return is_string($text) ? trim($text) : '';
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function extractGeminiText(array $json): string
    {
        foreach (['output_text', 'text'] as $key) {
            if (isset($json[$key]) && is_string($json[$key])) {
                return trim($json[$key]);
            }
        }

        if (isset($json['interaction']) && is_array($json['interaction'])) {
            foreach (['output_text', 'text'] as $key) {
                if (isset($json['interaction'][$key]) && is_string($json['interaction'][$key])) {
                    return trim($json['interaction'][$key]);
                }
            }
        }

        if (isset($json['steps']) && is_array($json['steps'])) {
            $stepText = collect($json['steps'])
                ->filter(fn ($step): bool => is_array($step) && ($step['type'] ?? null) === 'model_output')
                ->flatMap(fn ($step): array => is_array($step['content'] ?? null) ? $step['content'] : [])
                ->map(fn ($content): string => is_array($content) && ($content['type'] ?? null) === 'text' ? (string) ($content['text'] ?? '') : '')
                ->filter()
                ->implode("\n");

            if (trim($stepText) !== '') {
                return trim($stepText);
            }
        }

        $parts = $json['candidates'][0]['content']['parts'] ?? [];

        if (! is_array($parts)) {
            return '';
        }

        return trim(collect($parts)
            ->map(fn ($part): string => is_array($part) ? (string) ($part['text'] ?? '') : '')
            ->filter()
            ->implode("\n"));
    }

    private function errorForStatus(int $status): string
    {
        return match (true) {
            $status === 401 || $status === 403 => 'AI API key is invalid or not authorized.',
            $status === 404 => 'AI model or provider endpoint is invalid.',
            $status === 429 => 'AI provider rate limit was reached.',
            $status >= 500 => 'AI provider is currently unavailable.',
            default => 'AI provider returned an error.',
        };
    }

    /**
     * @return array{ok: false, text: null, error: string, status: ?int, provider: string, model: string}
     */
    private function failure(string $error, ?int $status, string $provider, string $model): array
    {
        return [
            'ok' => false,
            'text' => null,
            'error' => $error,
            'status' => $status,
            'provider' => $provider,
            'model' => $model,
        ];
    }
}
