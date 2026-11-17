<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesStockLots;

abstract class TestCase extends BaseTestCase
{
    use CreatesStockLots;

    /**
     * Every test runs at a fixed moment, so results never depend on the real date (fixture dates such as
     * due dates stay in the future/past exactly as written). TEST_NOW overrides it for a one-off run.
     */
    public const FIXED_NOW = '2026-10-01 09:00:00';

    public const FIXED_TIMEZONE = 'Asia/Manila';

    protected function setUp(): void
    {
        // Before the app boots, so rows written by migrations and seeders carry the pinned time too.
        Carbon::setTestNow(Carbon::parse(getenv('TEST_NOW') ?: self::FIXED_NOW, self::FIXED_TIMEZONE));

        parent::setUp();
    }

    /**
     * Tests rebuild their database. Checked on the connection Laravel actually resolved (a DB_DATABASE set in the shell
     * reaches $_SERVER and wins over a phpunit force="true"), after the app boots and before any trait such as
     * RefreshDatabase runs (a trait's own beforeRefreshingDatabase() would replace a hook defined here): only SQLite or a
     * dedicated *_test / *_test_mariadb database may ever be refreshed, never the real one.
     *
     * @return array<class-string, class-string>
     */
    protected function setUpTraits()
    {
        self::assertTestDatabase();

        return parent::setUpTraits();
    }

    public static function assertTestDatabase(): void
    {
        $connection = DB::connection();
        $database = (string) $connection->getDatabaseName();
        if ($connection->getDriverName() !== 'sqlite' && ! preg_match('/_test(_mariadb)?$/', $database)) {
            throw new \RuntimeException("Refusing to refresh database \"{$database}\" ({$connection->getDriverName()}) in tests: use phpunit.xml (SQLite) or phpunit.mariadb.xml (cjp_test_mariadb).");
        }
    }

    /**
     * New sales must name a Stock Source: posts to Record Sales get stock lots attached (see CreatesStockLots).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function post($uri, array $data = [], array $headers = []): TestResponse
    {
        if ($this->attachStockLots && in_array(rtrim($uri, '/'), [route('sales-officer.sales.store'), route('admin.sales.store')], true)) {
            $data = $this->withStockLots($data);
        }

        if (in_array(rtrim($uri, '/'), [route('inventory-officer.inventory.purchases.store'), route('admin.inventory.purchases.store')], true)) {
            $data = $this->withPaymentMode($data);
        }

        return parent::post($uri, $data, $headers);
    }

    /**
     * Part 9: purchases need a Payment Mode. Posts written before it existed get Cash; tests that check the rule
     * itself send their own payment_mode (or set $defaultPaymentMode = false).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function withPaymentMode(array $data): array
    {
        return $this->defaultPaymentMode && ! array_key_exists('payment_mode', $data) ? $data + ['payment_mode' => 'cash'] : $data;
    }

    protected bool $defaultPaymentMode = true;

    /**
     * Part 21 #1: only the assigned driver updates a delivery. Sends the driver's Update Delivery for the sale as its
     * assigned driver (Delivery Details, else the driver of its lift). A sale with no driver yet (e.g. a Standard order
     * written straight into the database) is given one first, as Sales would name them in the Delivery Details. The
     * user who was signed in before stays signed in afterwards.
     *
     * @param  array<string, mixed>  $data
     */
    protected function driverDelivery(int $saleId, array $data = ['delivery_status' => 'delivered']): TestResponse
    {
        $previous = auth()->user();
        $driverId = DB::table('sales')->where('id', $saleId)->value('delivery_driver_user_id')
            ?? DB::table('haul_allocations')
                ->join('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
                ->where('haul_allocations.sale_id', $saleId)
                ->where('haul_allocations.destination_type', 'customer')
                ->where('haul_allocations.status', '!=', 'cancelled')
                ->where('hauls.status', '!=', 'cancelled')
                ->whereNull('hauls.deleted_at')
                ->value('hauls.driver_user_id');
        if (! $driverId) {
            $driverId = \App\Models\User::factory()->create(['role' => 'driver', 'status' => 'active', 'approval_status' => 'approved'])->id;
            DB::table('sales')->where('id', $saleId)->update(['delivery_driver_user_id' => $driverId]);
        }

        $response = $this->actingAs(\App\Models\User::findOrFail($driverId))
            ->from(route('driver.fuel-lifting.deliveries'))
            ->patch(route('driver.fuel-lifting.deliveries.status', $saleId), $data);
        if ($previous) {
            $this->actingAs($previous);
        }

        return $response;
    }

    /** The HTML of one tab panel: every tab renders on the page and only the active one is visible. */
    protected function tabPanel(string $html, string $tab): string
    {
        $from = strpos($html, '<section data-tab-panel="'.$tab.'"');
        $this->assertNotFalse($from, 'Missing the '.$tab.' tab panel');

        return substr($html, $from, strpos($html, '</section>', $from) - $from);
    }

    /**
     * Edits resubmit each existing line's lot, as the Edit form does (see CreatesStockLots).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    public function patch($uri, array $data = [], array $headers = []): TestResponse
    {
        if ($this->attachStockLots && (isset($data['items']) || isset($data['fuel_type_id']))) {
            $route = app('router')->getRoutes()->match(Request::create($uri, 'PATCH'));
            if (str_ends_with((string) $route->getName(), '.sales.update')) {
                $data = $this->withExistingStockLots((int) $route->parameter('sale'), $data);
            }
        }

        if (isset($data['purchase_date'])) {
            $route = app('router')->getRoutes()->match(Request::create($uri, 'PATCH'));
            if (str_ends_with((string) $route->getName(), '.inventory.purchases.update')) {
                $data = $this->withPaymentMode($data);
            }
        }

        return parent::patch($uri, $data, $headers);
    }
}
