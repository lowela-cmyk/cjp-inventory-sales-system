<?php

use App\Services\AIService;
use App\Services\OperationalDataRepairService;
use App\Services\WorkflowSmokeTestService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ai:test-connection', function (AIService $ai): int {
    $result = $ai->testConnection();

    if ($result['ok']) {
        $this->info('AI connection successful.');

        return 0;
    }

    $this->error('AI connection failed: '.$result['error']);

    return 1;
})->purpose('Test the configured AI provider without exposing credentials');

Artisan::command('cjp:workflow-smoke-test {--persist-staging : Persist generated staging records instead of rolling them back}', function (WorkflowSmokeTestService $smokeTest): int {
    $persist = (bool) $this->option('persist-staging');

    if ($persist && app()->environment('production')) {
        $this->error('Refusing to persist staging smoke-test records while APP_ENV=production.');

        return 1;
    }

    $result = $smokeTest->run(rollback: ! $persist);

    foreach ($result['checks'] as $label => $value) {
        $this->line($label.': '.(is_scalar($value) ? (string) $value : json_encode($value)));
    }

    if (! $result['ok']) {
        $this->error('Workflow smoke test failed: '.$result['message']);

        return 1;
    }

    $this->info('Workflow smoke test passed: '.$result['message']);

    return 0;
})->purpose('Run a rollback-safe database transactional smoke test for purchase-to-payment workflow (directly inserts records; not an HTTP/UI acceptance test)');

Artisan::command('cjp:readiness-audit {--permissive : Run in permissive mode with warnings instead of failures for missing operational data} {--turnover : Explicitly enforce strict turnover mode}', function (WorkflowSmokeTestService $smokeTest): int {
    $failures = 0;
    $warnings = 0;
    $turnoverMode = ! (bool) $this->option('permissive') || (bool) $this->option('turnover');

    $check = function (string $label, bool $passed, string $detail = '') use (&$failures): void {
        $this->{$passed ? 'info' : 'error'}(($passed ? 'PASS' : 'FAIL').' '.$label.($detail ? ' - '.$detail : ''));

        if (! $passed) {
            $failures++;
        }
    };

    $warn = function (string $label, bool $passed, string $detail = '') use (&$warnings): void {
        $this->{$passed ? 'info' : 'warn'}(($passed ? 'PASS' : 'WARN').' '.$label.($detail ? ' - '.$detail : ''));

        if (! $passed) {
            $warnings++;
        }
    };

    $evaluate = function (string $label, bool $passed, string $detail = '', bool $strictInTurnover = false) use ($check, $warn, $turnoverMode): void {
        if ($strictInTurnover && $turnoverMode) {
            $check($label, $passed, $detail);
        } else {
            $warn($label, $passed, $detail);
        }
    };

    $env = collect(file_exists(base_path('.env')) ? file(base_path('.env'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [])
        ->mapWithKeys(function (string $line): array {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                return [];
            }

            [$key, $value] = explode('=', $line, 2);

            return [$key => trim($value, "\"'")];
        });

    $this->line('Environment');
    $check('APP_ENV is production', $env->get('APP_ENV') === 'production', 'current: '.($env->get('APP_ENV') ?: 'missing'));
    $check('APP_DEBUG is false', $env->get('APP_DEBUG') === 'false', 'current: '.($env->get('APP_DEBUG') ?: 'missing'));
    $check('AI_API_KEY is not stored in .env', trim((string) $env->get('AI_API_KEY', '')) === '');
    $check('Private local disk is not generically served', config('filesystems.disks.local.serve') === false);
    $warn('APP_URL is set to a deployed HTTPS URL', str_starts_with((string) $env->get('APP_URL', ''), 'https://') && ! str_contains((string) $env->get('APP_URL', ''), 'localhost') && ! str_contains((string) $env->get('APP_URL', ''), 'your-domain'), 'current: '.($env->get('APP_URL') ?: 'missing'));
    $warn('Production mailer is configured beyond log/example defaults', ! in_array($env->get('MAIL_MAILER'), ['log', 'array', null], true) && ! str_contains((string) $env->get('MAIL_FROM_ADDRESS', ''), 'example.com'), 'mailer: '.($env->get('MAIL_MAILER') ?: 'missing'));

    $this->line('');
    $this->line('Master Data');

    try {
        $officialFuelNames = collect(config('fuels.approved', [
            'F1' => ['code' => 'F1', 'name' => 'F1'],
            'UNL' => ['code' => 'UNL', 'name' => 'UNLEADED'],
            'DSL' => ['code' => 'DSL', 'name' => 'DIESEL'],
            'PREM' => ['code' => 'PREM', 'name' => 'PREMIUM'],
        ]))->pluck('name')->sort()->values()->all();

        $activeFuelNames = DB::table('fuel_types')
            ->where('status', 'active')
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $check('Active fuel types match official list', $activeFuelNames === $officialFuelNames, 'active: '.implode(', ', $activeFuelNames));

        $tankIssues = DB::table('fuel_types')
            ->leftJoin('storage_locations', function ($join): void {
                $join->on('storage_locations.fuel_type_id', '=', 'fuel_types.id')
                    ->where('storage_locations.type', 'garage')
                    ->where('storage_locations.status', 'active');
            })
            ->where('fuel_types.status', 'active')
            ->groupBy('fuel_types.id', 'fuel_types.name')
            ->havingRaw('COUNT(storage_locations.id) <> 2')
            ->pluck('fuel_types.name')
            ->all();

        $check('Each active fuel type has two active garage tanks', $tankIssues === [], $tankIssues ? 'missing/extra tanks: '.implode(', ', $tankIssues) : '');

        foreach ([
            'depots' => ['label' => 'At least one depot exists', 'strict' => false],
            'trucks' => ['label' => 'At least one truck exists', 'strict' => true],
            'driver_profiles' => ['label' => 'At least one driver profile exists', 'strict' => true],
            'customers' => ['label' => 'At least one customer exists', 'strict' => false],
        ] as $table => $config) {
            $evaluate($config['label'], DB::table($table)->count() > 0, $table.' count: '.DB::table($table)->count(), $config['strict']);
        }

        $this->line('');
        $this->line('Workflow Data');

        foreach ([
            'purchases' => ['label' => 'Purchase workflow has records', 'strict' => true],
            'hauls' => ['label' => 'Fuel lifting workflow has records', 'strict' => true],
            'haul_allocations' => ['label' => 'Haul allocation workflow has records', 'strict' => false],
            'inventory_movements' => ['label' => 'Inventory ledger has movements', 'strict' => true],
            'stock_outs' => ['label' => 'Stock-out workflow has records', 'strict' => true],
            'sales' => ['label' => 'Sales workflow has records', 'strict' => false],
            'payments' => ['label' => 'Payment workflow has records', 'strict' => false],
            'receivables' => ['label' => 'Receivable workflow has records', 'strict' => false],
        ] as $table => $config) {
            $evaluate($config['label'], DB::table($table)->count() > 0, $table.' count: '.DB::table($table)->count(), $config['strict']);
        }

        $this->line('');
        $this->line('Data Integrity');

        $saleWithoutReceivable = DB::table('sales')
            ->leftJoin('receivables', 'receivables.sale_id', '=', 'sales.id')
            ->whereNull('sales.deleted_at')
            ->whereNull('receivables.id')
            ->count();
        $check('All active sales have receivables', $saleWithoutReceivable === 0, 'missing: '.$saleWithoutReceivable);

        $overpaidSales = DB::table('sales')
            ->joinSub(DB::table('sale_items')->selectRaw('sale_id, SUM(line_total) total')->groupBy('sale_id'), 'sale_totals', 'sale_totals.sale_id', '=', 'sales.id')
            ->joinSub(DB::table('payments')->selectRaw('sale_id, SUM(amount) paid')->groupBy('sale_id'), 'payment_totals', 'payment_totals.sale_id', '=', 'sales.id')
            ->whereRaw('payment_totals.paid > sale_totals.total')
            ->count();
        $check('No sales are overpaid', $overpaidSales === 0, 'overpaid: '.$overpaidSales);

        $overFulfilledItems = DB::table('sale_items')
            ->whereColumn('fulfilled_quantity_liters', '>', 'quantity_liters')
            ->count();
        $check('No sale item is over-fulfilled', $overFulfilledItems === 0, 'over-fulfilled: '.$overFulfilledItems);

        $releasedWithoutMovement = DB::table('stock_outs')
            ->where('status', 'released')
            ->where('source_type', 'garage')
            ->whereNull('inventory_movement_id')
            ->count();
        $check('Released garage stock-outs have inventory movements', $releasedWithoutMovement === 0, 'missing movement: '.$releasedWithoutMovement);

        $negativeBalances = DB::query()
            ->fromSub(
                DB::table('inventory_movements')
                    ->selectRaw("storage_location_id, fuel_type_id, SUM(CASE WHEN direction = 'in' THEN quantity_liters ELSE -quantity_liters END) as balance")
                    ->groupBy('storage_location_id', 'fuel_type_id')
                    ->havingRaw('balance < 0'),
                'balances'
            )
            ->count();
        $check('No garage/fuel inventory balance is negative', $negativeBalances === 0, 'negative balances: '.$negativeBalances);

        $this->line('');
        $this->line('Rollback Workflow Smoke Test');

        $smokeResult = $smokeTest->run(rollback: true);
        $check('Purchase to payment workflow completes without persisting staging data', $smokeResult['ok'], $smokeResult['message']);

        if ($smokeResult['ok']) {
            foreach ($smokeResult['checks'] as $label => $value) {
                $this->line('  '.$label.': '.(is_scalar($value) ? (string) $value : json_encode($value)));
            }
        }
    } catch (Throwable $exception) {
        $this->error('FAIL Database audit could not complete - '.$exception->getMessage());
        $failures++;
    }

    $this->line('');
    if ($failures === 0) {
        $this->info($warnings === 0
            ? 'READY FOR TURNOVER CHECKS PASSED'
            : 'READY WITH WARNINGS - '.$warnings.' production/data readiness warning(s) found');
    } else {
        $this->error('NOT READY - '.$failures.' readiness issue(s) found'.($warnings ? ' and '.$warnings.' warning(s)' : ''));
    }

    return $failures === 0 ? 0 : 1;
})->purpose('Run a read-only turnover readiness audit for CJP Southern Star OPC');

Artisan::command('cjp:repair-data', function (OperationalDataRepairService $repairService): int {
    $this->info('Starting operational data repair and acceptance data seeding...');

    try {
        $result = $repairService->run();

        $this->info('1. Legacy Data Repair:');
        $this->line('   Historical sale SLS-000001 reconciled: '.($result['legacy_repaired'] ? 'YES (Fulfilled & Stocked Out)' : 'ALREADY REPAIRED / NOT FOUND'));

        $this->line('');
        $this->info('2. Master Data Status:');
        foreach ($result['master_data'] as $entity => $count) {
            $this->line('   - '.ucwords(str_replace('_', ' ', $entity)).': '.$count);
        }

        $this->line('');
        $this->info('3. Operational Acceptance Workflows (4 Fuels):');
        foreach ($result['acceptance_workflows'] as $fuel => $details) {
            $this->line('   ['.$fuel.'] Purchase: '.$details['purchase_code'].' | Haul: '.$details['haul_code'].' | Sale: '.$details['sale_code'].' | Stock-Out: '.$details['stock_out_code'].' | Payment: '.$details['payment_code'].' (PHP '.number_format($details['total_amount'], 2).')');
        }

        $this->line('');
        $this->info('4. Reconciliation & Integrity Checks:');
        $recon = $result['reconciliation'];
        $this->line('   - Sales missing receivables: '.$recon['sales_missing_receivables']);
        $this->line('   - Overpaid sales: '.$recon['overpaid_sales']);
        $this->line('   - Over-fulfilled sale items: '.$recon['over_fulfilled_items']);
        $this->line('   - Unfulfilled paid sales: '.$recon['unfulfilled_paid_sales']);
        $this->line('   - Released garage stock-outs missing movements: '.$recon['released_without_movement']);
        $this->line('   - Negative inventory balances: '.$recon['negative_inventory_balances']);

        $this->line('');
        $this->info('OPERATIONAL DATA REPAIR AND ACCEPTANCE SEEDING COMPLETE.');

        return 0;
    } catch (Throwable $e) {
        $this->error('Operational repair failed: '.$e->getMessage());

        return 1;
    }
})->purpose('Repair legacy operational data, provision master data, and seed reconciled acceptance workflows for CJP Southern Star OPC');
