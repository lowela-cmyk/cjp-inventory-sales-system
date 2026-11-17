<?php

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->bootstrap();
$db = Illuminate\Support\Facades\DB::connection();
if ($db->getDatabaseName() !== 'cjp_inventory_repair_qa') {
    throw new RuntimeException('Navigation benchmarks require cjp_inventory_repair_qa.');
}
config(['session.driver' => 'array', 'cache.default' => 'array']);
$user = App\Models\User::where('role', 'admin')->firstOrFail();
$paths = ['/admin/dashboard', '/admin/inventory', '/admin/inventory/stock-in', '/admin/inventory/stock-out', '/admin/inventory/depots', '/admin/inventory/garage', '/admin/ledger', '/admin/ledger/transactions', '/admin/fuel-lifting', '/admin/fuel-lifting/hauled', '/admin/fuel-lifting/trucks', '/admin/fuel-lifting/garage', '/admin/sales', '/admin/sales/customers', '/admin/imports'];
$metrics = [];
if (isset($argv[1])) $paths = [$argv[1]];
foreach ($paths as $path) {
    $app->instance('request', Illuminate\Http\Request::create($path));
    Illuminate\Support\Facades\Auth::setUser($user);
    $db->beginTransaction();
    $db->flushQueryLog();
    $db->enableQueryLog();
    $started = hrtime(true);
    $response = $kernel->handle(Illuminate\Http\Request::create($path));
    $queries = $db->getQueryLog();
    $metrics[] = ['path' => $path, 'status' => $response->getStatusCode(), 'ms' => round((hrtime(true) - $started) / 1e6, 1), 'queries' => count($queries), 'sql_ms' => round(array_sum(array_column($queries, 'time')), 1), 'bytes' => strlen($response->getContent())];
    fwrite(STDERR, json_encode(end($metrics)).PHP_EOL);
    $db->disableQueryLog();
    $db->rollBack();
}
echo json_encode(['peak_mb' => round(memory_get_peak_usage(true) / 1048576, 1), 'pages' => $metrics], JSON_PRETTY_PRINT).PHP_EOL;
