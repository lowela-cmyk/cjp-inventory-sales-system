<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AIServiceConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_ai_api_key_is_handled_without_http_request(): void
    {
        Http::fake();
        config([
            'services.ai.provider' => 'groq',
            'services.ai.api_key' => '',
            'services.ai.model' => 'llama-3.3-70b-versatile',
            'services.ai.base_url' => 'https://api.groq.com/openai/v1',
        ]);

        $result = app(AIService::class)->testConnection();

        $this->assertFalse($result['ok']);
        $this->assertSame('AI API key is not configured.', $result['error']);
        Http::assertNothingSent();
    }

    public function test_groq_provider_request_uses_config_and_parses_chat_response(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [
                    [
                        'message' => [
                            'content' => 'AI connection successful.',
                        ],
                    ],
                ],
            ]),
        ]);
        config($this->aiConfig(['api_key' => 'test-groq-key']));

        $result = app(AIService::class)->testConnection();

        $this->assertTrue($result['ok']);
        $this->assertSame('AI connection successful.', $result['text']);
        $this->assertSame('groq', $result['provider']);
        $this->assertSame('openai/gpt-oss-20b', $result['model']);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.groq.com/openai/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-groq-key')
                && $request['model'] === 'openai/gpt-oss-20b'
                && $request['messages'][0]['content'] === 'Reply with: AI connection successful.'
                && ! array_key_exists('key', $request->data());
        });
    }

    public function test_gemini_provider_remains_supported_when_configured(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'steps' => [
                    [
                        'type' => 'model_output',
                        'content' => [
                            ['type' => 'text', 'text' => 'AI connection successful.'],
                        ],
                    ],
                ],
            ]),
        ]);
        config($this->aiConfig([
            'provider' => 'gemini',
            'api_key' => 'test-gemini-key',
            'model' => 'gemini-3.7-flash',
            'base_url' => 'https://generativelanguage.googleapis.com/v1',
        ]));

        $result = app(AIService::class)->testConnection();

        $this->assertTrue($result['ok']);
        $this->assertSame('AI connection successful.', $result['text']);
        $this->assertSame('gemini', $result['provider']);
        $this->assertSame('gemini-3.7-flash', $result['model']);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://generativelanguage.googleapis.com/v1/interactions'
                && $request->header('x-goog-api-key')[0] === 'test-gemini-key'
                && $request['model'] === 'gemini-3.7-flash'
                && str_contains($request['input'], 'Reply with: AI connection successful.')
                && ! array_key_exists('key', $request->data());
        });
    }

    public function test_provider_errors_are_returned_without_crashing(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::sequence()
                ->push(['error' => ['message' => 'Provider detail']], 401)
                ->push(['error' => ['message' => 'Provider detail']], 404)
                ->push(['error' => ['message' => 'Provider detail']], 429)
                ->push(['error' => ['message' => 'Provider detail']], 503),
        ]);
        config($this->aiConfig(['api_key' => 'test-groq-key']));

        foreach ([401 => 'AI API key is invalid or not authorized.', 404 => 'AI model or provider endpoint is invalid.', 429 => 'AI provider rate limit was reached.', 503 => 'AI provider is currently unavailable.'] as $status => $message) {
            $result = app(AIService::class)->generateText('Summarize this harmless test.');

            $this->assertFalse($result['ok']);
            $this->assertSame($message, $result['error']);
            $this->assertSame($status, $result['status']);
        }
    }

    public function test_malformed_ai_response_is_handled_without_crashing(): void
    {
        Http::fake([
            'api.groq.com/*' => Http::response(['unexpected' => true]),
        ]);
        config($this->aiConfig(['api_key' => 'test-groq-key']));

        $result = app(AIService::class)->generateText('Summarize this harmless test.');

        $this->assertFalse($result['ok']);
        $this->assertSame('AI provider returned no usable text.', $result['error']);
    }

    public function test_unsupported_provider_is_reported_as_configuration_error(): void
    {
        Http::fake();
        config($this->aiConfig(['provider' => 'unknown-provider', 'api_key' => 'test-groq-key']));

        $result = app(AIService::class)->generateText('Summarize this harmless test.');

        $this->assertFalse($result['ok']);
        $this->assertSame('Unsupported AI provider configured.', $result['error']);
        Http::assertNothingSent();
    }

    public function test_connection_command_prints_safe_error_without_key(): void
    {
        config($this->aiConfig(['api_key' => '']));

        $this->artisan('ai:test-connection')
            ->expectsOutput('AI connection failed: AI API key is not configured.')
            ->assertExitCode(1);
    }

    public function test_ai_credentials_are_not_exposed_in_admin_dashboard_html(): void
    {
        config($this->aiConfig(['api_key' => 'super-secret-groq-key']));
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $this->actingAs($admin)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('super-secret-groq-key', false)
            ->assertDontSee('x-goog-api-key', false)
            ->assertDontSee('Authorization', false)
            ->assertDontSee('AI_API_KEY', false);
    }

    /**
     * @return array<string, mixed>
     */
    private function aiConfig(array $overrides = []): array
    {
        return [
            'services.ai.provider' => $overrides['provider'] ?? 'groq',
            'services.ai.api_key' => $overrides['api_key'] ?? null,
            'services.ai.model' => $overrides['model'] ?? 'openai/gpt-oss-20b',
            'services.ai.base_url' => $overrides['base_url'] ?? 'https://api.groq.com/openai/v1',
            'services.ai.timeout' => $overrides['timeout'] ?? 20,
        ];
    }
}
