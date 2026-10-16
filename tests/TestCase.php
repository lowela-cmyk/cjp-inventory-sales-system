<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;
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

        return parent::post($uri, $data, $headers);
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
            $route = app('router')->getRoutes()->match(\Illuminate\Http\Request::create($uri, 'PATCH'));
            if (str_ends_with((string) $route->getName(), '.sales.update')) {
                $data = $this->withExistingStockLots((int) $route->parameter('sale'), $data);
            }
        }

        return parent::patch($uri, $data, $headers);
    }
}
