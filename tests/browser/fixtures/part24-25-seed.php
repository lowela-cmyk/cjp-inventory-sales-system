<?php

// Usage (QA copy only, never the real database):
//   QA_DB=cjp_qa_... QA_IDS_OUT=/path/ids.json DB_DATABASE=cjp_qa_... php artisan tinker --execute="require 'tests/browser/fixtures/part24-25-seed.php';"

// Part 24 / 25 browser fixtures (part24-fixes, record-sales-modal, part25-sales), on the QA copy named by QA_DB.
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

$http = new class(app())
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
    if (($errors && $errors->any()) || $response->getStatusCode() >= 400) {
        throw new RuntimeException($what.' failed: '.json_encode($errors?->getMessages()).' HTTP '.$response->getStatusCode());
    }
};

$password = 'Part24-QA-Only!';
$user = function (string $role) use ($password): User {
    $email = 'p24qa-'.str_replace('_', '-', $role).'@example.test';
    $u = User::where('email', $email)->first() ?? User::create(['name' => 'P24QA '.ucwords(str_replace('_', ' ', $role)), 'email' => $email, 'password' => Hash::make($password), 'role' => $role]);
    $u->forceFill(['role' => $role, 'status' => 'active', 'approval_status' => 'approved', 'password' => Hash::make($password)])->save();

    return $u;
};
$admin = $user('admin');
$inventory = $user('inventory_officer');
$sales = $user('sales_officer');
$dispatch = $user('dispatch_officer');
$driver = $user('driver');
DB::table('driver_profiles')->updateOrInsert(['user_id' => $driver->id], ['driver_code' => 'DRV-P24QA', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);

$depot = (int) DB::table('depots')->where('status', 'active')->orderBy('id')->value('id');
$customer = (int) DB::table('customers')->where('status', 'active')->orderBy('id')->value('id');
$fuel = fn (string $code): int => (int) DB::table('fuel_types')->where('code', $code)->value('id');
$truck = fn (string $name, string $code, float $cap): int => (int) DB::table('trucks')->insertGetId(['truck_code' => $code, 'name' => $name, 'plate_number' => $code, 'capacity_liters' => $cap, 'truck_type' => 'hauling', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);
// Exactly four trucks named "QA P25QA…" (Part 25 #1 counts them); the receipt lift uses its own truck.
$smallTruck = $truck('QA P25QA Small', 'P25QA-1K', 1000);
$bigTruck = $truck('QA P25QA Big', 'P25QA-30K', 30000);
$liftTruck = $truck('QA P25QA Lift', 'P25QA-100K', 100000);
$truck('QA P25QA Spare', 'P25QA-50K', 50000);
$haulTruck = $truck('QA P24 Receipt Truck', 'P24QA-HAUL', 30000);

$codes = [];
$purchase = function (string $code, string $fuelCode, float $liters) use ($http, $inventory, $depot, $fuel, $must, &$codes): int {
    $http->actingAs($inventory);
    $must($http->post(route('inventory-officer.inventory.purchases.store'), [
        'idempotency_key' => (string) Str::uuid(), 'purchase_date' => '2026-11-21', 'depot_id' => $depot, 'fuel_type_id' => $fuel($fuelCode),
        'quantity_ordered_liters' => $liters, 'unit_cost' => 58, 'payment_status' => 'unpaid', 'payment_mode' => 'cash',
    ]), 'purchase '.$code);
    $item = (int) DB::table('purchase_items')->orderByDesc('id')->value('id');
    DB::table('purchases')->where('id', DB::table('purchase_items')->where('id', $item)->value('purchase_id'))->update(['purchase_code' => $code]);
    $codes[(string) $item] = $code;

    return $item;
};
$lift = function (int $item, float $liters, int $truckId) use ($http, $dispatch, $driver, $must): int {
    $http->actingAs($dispatch);
    $must($http->post(route('dispatch.fuel-lifting.hauls.store'), [
        'idempotency_key' => (string) Str::uuid(), 'form_context' => 'schedule-lift', 'items' => [['purchase_item_id' => $item, 'quantity_liters' => $liters, 'atl_number' => 'ATL-P24QA']],
        'deliveries' => [], 'driver_user_id' => $driver->id, 'truck_id' => $truckId, 'scheduled_at' => '2026-11-21 08:00:00', 'dr_number' => 'DR-P24QA',
    ]), 'lift');
    $haul = (int) DB::table('hauls')->whereNotNull('lifting_schedule_id')->orderByDesc('id')->value('id');
    $http->actingAs($driver);
    foreach (['in_transit', 'lifted'] as $status) {
        $must($http->patch(route('driver.fuel-lifting.hauls.status', $haul), ['idempotency_key' => (string) Str::uuid(), 'lifting_status' => $status]), 'lift '.$status);
    }

    return $haul;
};
$sale = function (array $fields) use ($http, $sales, $customer, $must): int {
    $http->actingAs($sales);
    $must($http->post(route('sales-officer.sales.store'), $fields + [
        'idempotency_key' => (string) Str::uuid(), 'form_context' => 'record-sale', 'customer_id' => $customer, 'sale_date' => '2026-11-21',
        'payment_method' => 'cash_on_delivery', 'payment_terms' => 'installment',
    ]), 'sale');

    return (int) DB::table('sales')->orderByDesc('id')->value('id');
};

// dslA: 40,000 L, 37,000 L promised to a Standard order (Part 24 #3 adds a 3,000 L Depot to Client line: fully reserved).
$dslA = $purchase('PUR-DSL-P24A', 'DSL', 40000);
$sale(['delivery_type' => 'standard', 'items' => [['purchase_item_id' => $dslA, 'quantity_liters' => 37000, 'unit_price' => 62]]]);
// dslB: 100,000 L with a 10,000 L Lifted lift for the receipt upload (Part 24 #4) and the ledger (Part 24 #4).
$dslB = $purchase('PUR-DSL-P24B', 'DSL', 100000);
$dslHaul = $lift($dslB, 10000, $haulTruck);
$dslC = $purchase('PUR-DSL-P24C', 'DSL', 40000);
// prem: 40,000 L; 7,000 L lifted onto the big truck, 5,000 L unloaded into Premium Tank 1, 2,000 L stay on the truck.
$prem = $purchase('PUR-PREM-P24', 'PREM', 40000);
$premHaul = $lift($prem, 7000, $bigTruck);
$tank = (int) DB::table('storage_locations')->where('type', 'garage')->where('status', 'active')->where('fuel_type_id', $fuel('PREM'))->where('tank_number', 1)->value('id');
if (! $tank) {
    throw new RuntimeException('No active Premium Tank 1 on the QA copy.');
}
$http->actingAs($admin);
$must($http->post(route('admin.fuel-lifting.hauls.unload-to-garage', $premHaul), ['unload' => [$premHaul => [$tank => 5000]]]), 'unload');
// A pending Garage to Client order (Part 24 #2 delivery status dropdown).
$garageSale = $sale(['delivery_type' => 'garage_to_client', 'items' => [['purchase_item_id' => 'tank:'.$tank, 'quantity_liters' => 500, 'unit_price' => 70]],
    'delivery_truck_id' => $bigTruck, 'delivery_driver_user_id' => $driver->id, 'delivery_date' => '2026-11-22 10:00']);
// PUR-DSL-002: 100,000 L, nothing lifted (Part 25).
$pur002 = $purchase('PUR-DSL-002', 'DSL', 100000);

$ids = [
    'customer' => $customer, 'smallTruck' => $smallTruck, 'bigTruck' => $bigTruck, 'liftTruck' => $liftTruck,
    'dslA' => $dslA, 'dslB' => $dslB, 'dslC' => $dslC, 'prem' => $prem, 'pur002' => $pur002,
    'dslHaul' => $dslHaul, 'dslHaulCode' => DB::table('hauls')->where('id', $dslHaul)->value('haul_code'),
    'garageSale' => $garageSale, 'garageSaleCode' => DB::table('sales')->where('id', $garageSale)->value('sale_code'),
    'driverId' => $driver->id, 'codes' => $codes,
];
file_put_contents((string) (getenv('QA_IDS_OUT') ?: sys_get_temp_dir().'/'.basename(__FILE__, '.php').'-ids.json'), json_encode($ids, JSON_PRETTY_PRINT));
echo json_encode($ids).PHP_EOL;
