<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Carbon;

abstract class TestCase extends BaseTestCase
{
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
}
