<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The payments that count. A voided payment stays in the payments table and in the payment history, but every
 * total, balance, status, latest-payment date and collection figure must start from this query instead of
 * DB::table('payments').
 *
 * Until the void migration has run on a database there are no void columns and nothing can be voided, so every
 * payment counts and the queries keep working.
 */
final class ActivePayments
{
    /** @var array<string, bool> per connection + database */
    private static array $supported = [];

    public static function query(): Builder
    {
        $query = DB::table('payments');

        return self::voidSupported() ? $query->whereNull('payments.voided_at') : $query;
    }

    public static function voidSupported(): bool
    {
        $connection = DB::connection();
        $key = $connection->getName().'|'.$connection->getDatabaseName();

        return self::$supported[$key] ??= Schema::hasColumn('payments', 'voided_at');
    }

    /** Tests and migrations that add the columns mid-process call this. */
    public static function forget(): void
    {
        self::$supported = [];
    }
}
