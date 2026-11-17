<?php
// CLI-only verification fixture. The cookie is consumed privately by Playwright.
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$expectedDatabase = getenv('CJP_VERIFY_DATABASE') ?: 'cjp_inventory_sales_db';
$actualDatabase = Illuminate\Support\Facades\DB::connection()->getDatabaseName();
if ($actualDatabase !== $expectedDatabase || ($expectedDatabase !== 'cjp_inventory_sales_db' && ! str_contains($expectedDatabase, '_qa_'))) {
    throw new RuntimeException('Wrong database');
}
$baseUrl = getenv('CJP_VERIFY_BASE_URL') ?: 'http://127.0.0.1:8001';
$app->instance('request', Illuminate\Http\Request::create($baseUrl));
$session = $app->make('session')->driver();
$session->start();
$user = App\Models\User::where('role', 'admin')->where('status', 'active')->firstOrFail();
Illuminate\Support\Facades\Auth::login($user);
$session->save();
$name = config('session.cookie');
$value = Illuminate\Cookie\CookieValuePrefix::create($name, $app['encrypter']->getKey()).$session->getId();
echo json_encode(['name' => $name, 'value' => $app['encrypter']->encrypt($value, false), 'domain' => '127.0.0.1', 'path' => '/', 'httpOnly' => true, 'secure' => false, 'sameSite' => 'Lax', 'session_id' => $session->getId()]);
