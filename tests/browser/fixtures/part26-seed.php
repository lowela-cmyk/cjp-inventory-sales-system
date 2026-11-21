<?php

// Usage (QA copy only, never the real database):
//   QA_DB=cjp_qa_... QA_IDS_OUT=/path/ids.json DB_DATABASE=cjp_qa_... php artisan tinker --execute="require 'tests/browser/fixtures/part26-seed.php';"

// Part 26 QA seed (tests/browser/part26-fixes.mjs): drives the app's own routes in-process on the QA copy named by QA_DB.
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$db = DB::connection()->getDatabaseName();
// The QA copy to seed: QA_DB must name it, contain _qa_, and be the connection's database (never the real one).
$expected = (string) getenv('QA_DB');
if ($expected === '' || ! str_contains($expected, '_qa_') || $db !== $expected || $db === 'cjp_inventory_sales_db') {
    throw new RuntimeException('Refusing to seed: connected to '.$db);
}

$app = app();
$http = new class($app)
{
    use Illuminate\Foundation\Testing\Concerns\InteractsWithAuthentication;
    use Illuminate\Foundation\Testing\Concerns\InteractsWithSession;
    use Illuminate\Foundation\Testing\Concerns\MakesHttpRequests;

    public $app;

    public function __construct($app)
    {
        $this->app = $app;
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }
};
$must = function ($response, string $what) {
    $errors = session('errors');
    if ($errors && $errors->any()) {
        throw new RuntimeException($what.' failed: '.json_encode($errors->getMessages()));
    }
    if ($response->getStatusCode() >= 400) {
        throw new RuntimeException($what.' failed with HTTP '.$response->getStatusCode());
    }
    session()->forget('errors');
};

$password = 'Part26-QA-Only!';
$user = function (string $role) use ($password): User {
    $email = 'p26qa-'.str_replace('_', '-', $role).'@example.test';
    $u = User::where('email', $email)->first() ?? User::create(['name' => 'P26QA '.ucwords(str_replace('_', ' ', $role)), 'email' => $email, 'password' => Hash::make($password), 'role' => $role]);
    $u->forceFill(['role' => $role, 'status' => 'active', 'approval_status' => 'approved', 'password' => Hash::make($password)])->save();

    return $u;
};
$admin = $user('admin');
$inventory = $user('inventory_officer');
$sales = $user('sales_officer');
$dispatch = $user('dispatch_officer');
$driver = $user('driver');
DB::table('driver_profiles')->updateOrInsert(['user_id' => $driver->id], ['driver_code' => 'DRV-P26QA', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);

$depot = (int) DB::table('depots')->where('status', 'active')->orderBy('id')->value('id');
$customer = (int) DB::table('customers')->where('status', 'active')->orderBy('id')->value('id');
$diesel = (int) DB::table('fuel_types')->where('code', 'DSL')->value('id');
$truck = fn (string $code, float $cap): int => (int) (DB::table('trucks')->where('truck_code', $code)->value('id')
    ?: DB::table('trucks')->insertGetId(['truck_code' => $code, 'name' => 'QA '.$code, 'plate_number' => $code, 'capacity_liters' => $cap, 'truck_type' => 'hauling', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]));
$liftTruck = $truck('P26QA-100K', 100000);
$secondTruck = $truck('P26QA-50K', 50000);

$purchase = function (string $code, float $liters) use ($http, $inventory, $depot, $diesel, $must): int {
    $http->actingAs($inventory);
    $must($http->post(route('inventory-officer.inventory.purchases.store'), [
        'idempotency_key' => (string) Str::uuid(), 'purchase_date' => '2026-11-21', 'depot_id' => $depot, 'fuel_type_id' => $diesel,
        'quantity_ordered_liters' => $liters, 'unit_cost' => 58, 'payment_status' => 'unpaid', 'payment_mode' => 'cash',
    ]), 'purchase '.$code);
    $item = (int) DB::table('purchase_items')->orderByDesc('id')->value('id');
    DB::table('purchases')->where('id', DB::table('purchase_items')->where('id', $item)->value('purchase_id'))->update(['purchase_code' => $code]);

    return $item;
};
$lift = function (int $item, float $liters, int $truckId, array $saleIds = []) use ($http, $dispatch, $driver, $must): int {
    $http->actingAs($dispatch);
    $must($http->post(route('dispatch.fuel-lifting.hauls.store'), [
        'idempotency_key' => (string) Str::uuid(), 'form_context' => 'schedule-lift', 'items' => [['purchase_item_id' => $item, 'quantity_liters' => $liters, 'atl_number' => 'ATL-P26QA']],
        'deliveries' => array_map(fn (int $id): array => ['sale_id' => $id], $saleIds),
        'driver_user_id' => $driver->id, 'truck_id' => $truckId, 'scheduled_at' => '2026-11-21 08:00:00', 'dr_number' => 'DR-P26QA',
    ]), 'lift');
    $haul = (int) DB::table('hauls')->whereNotNull('lifting_schedule_id')->orderByDesc('id')->value('id');
    $http->actingAs($driver);
    foreach (['in_transit', 'lifted'] as $status) {
        $must($http->patch(route('driver.fuel-lifting.hauls.status', $haul), ['idempotency_key' => (string) Str::uuid(), 'lifting_status' => $status]), 'lift status '.$status);
    }

    return $haul;
};

// PUR-DSL-002: 100,000 L, all of it lifted onto one truck (nothing left in the depot). The browser sells 40,000 L of it.
$pur002 = $purchase('PUR-DSL-002', 100000);
$haul002 = $lift($pur002, 100000, $liftTruck);

// PUR-DSL-003: 50,000 L; a Standard order of 5,000 L, then 20,000 L lifted onto a second truck; the order is delivered
// from that truck (Standard -> Depot to Client source change: a long Stock Out source).
$pur003 = $purchase('PUR-DSL-003', 50000);
$http->actingAs($sales);
$must($http->post(route('sales-officer.sales.store'), [
    'idempotency_key' => (string) Str::uuid(), 'form_context' => 'record-sale', 'delivery_type' => 'standard', 'customer_id' => $customer, 'sale_date' => '2026-11-21',
    'items' => [['purchase_item_id' => $pur003, 'quantity_liters' => 5000, 'unit_price' => 66]], 'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment',
]), 'standard sale');
$standardSale = (int) DB::table('sales')->orderByDesc('id')->value('id');
$haul003 = $lift($pur003, 20000, $secondTruck);
$http->actingAs($dispatch);
$must($http->post(route('dispatch.fuel-lifting.hauls.truck-deliveries.store', $haul003), ['sale_id' => $standardSale]), 'deliver standard from truck');

$ids = compact('pur002', 'pur003', 'haul002', 'haul003', 'liftTruck', 'secondTruck', 'standardSale', 'customer', 'depot');
file_put_contents((string) (getenv('QA_IDS_OUT') ?: sys_get_temp_dir().'/'.basename(__FILE__, '.php').'-ids.json'), json_encode($ids, JSON_PRETTY_PRINT));
echo json_encode($ids).PHP_EOL;
