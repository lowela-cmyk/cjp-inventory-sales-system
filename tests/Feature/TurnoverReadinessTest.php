<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TurnoverReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_example_environment_uses_turnover_safe_defaults(): void
    {
        $env = $this->parseEnvExample();

        $this->assertSame('production', $env['APP_ENV'] ?? null);
        $this->assertSame('false', $env['APP_DEBUG'] ?? null);
        $this->assertSame('warning', $env['LOG_LEVEL'] ?? null);
        $this->assertSame('true', $env['SESSION_ENCRYPT'] ?? null);
        $this->assertSame('true', $env['SESSION_SECURE_COOKIE'] ?? null);
        $this->assertSame('', $env['AI_API_KEY'] ?? null);
        $this->assertNotSame('root', $env['DB_USERNAME'] ?? null);

        $this->assertDoesNotMatchRegularExpression('/\b(?:gsk|sk|AIza)[A-Za-z0-9_\-]{16,}\b/', file_get_contents(base_path('.env.example')));
    }

    public function test_private_local_disk_is_not_exposed_by_generic_storage_route(): void
    {
        $this->assertFalse(config('filesystems.disks.local.serve'));
        $this->assertFalse(Route::has('storage.local'));
        $this->assertFalse(Route::has('storage.local.upload'));
    }

    public function test_workflow_smoke_test_runs_with_rollback_without_persisting_staging_data(): void
    {
        $before = [
            'purchases' => DB::table('purchases')->count(),
            'hauls' => DB::table('hauls')->count(),
            'sales' => DB::table('sales')->count(),
            'stock_outs' => DB::table('stock_outs')->count(),
            'inventory_movements' => DB::table('inventory_movements')->count(),
            'payments' => DB::table('payments')->count(),
        ];

        $this->assertSame(0, Artisan::call('cjp:workflow-smoke-test'));

        $after = [
            'purchases' => DB::table('purchases')->count(),
            'hauls' => DB::table('hauls')->count(),
            'sales' => DB::table('sales')->count(),
            'stock_outs' => DB::table('stock_outs')->count(),
            'inventory_movements' => DB::table('inventory_movements')->count(),
            'payments' => DB::table('payments')->count(),
        ];

        $this->assertSame($before, $after);
        $this->assertStringContainsString('Workflow smoke test passed', Artisan::output());
    }

    public function test_readiness_audit_fails_on_operationally_empty_database(): void
    {
        $exitCode = Artisan::call('cjp:readiness-audit');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('NOT READY', $output);
        $this->assertStringContainsString('FAIL At least one truck exists', $output);
        $this->assertStringContainsString('FAIL Purchase workflow has records', $output);
    }

    /**
     * @return array<string, string>
     */
    private function parseEnvExample(): array
    {
        $values = [];

        foreach (file(base_path('.env.example'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $values[$key] = trim($value, "\"'");
        }

        return $values;
    }
}
