<?php

namespace App\Console\Commands;

use App\Services\StockInService;
use Illuminate\Console\Command;

/**
 * Stock-In revisions (October 23, 2026): gives purchases written outside Purchases (imports, manual SQL) their
 * stock-in, voids those of cancelled or deleted purchases and refreshes those changed directly. Safe to run any number
 * of times (the same repair also runs automatically before stock-in records are read).
 */
class SyncStockIns extends Command
{
    protected $signature = 'stock-in:sync';

    protected $description = 'Create missing stock-in records, void those of cancelled purchases and refresh out-of-date ones';

    public function handle(StockInService $stockIns): int
    {
        $result = $stockIns->repair();

        $this->info('Created '.$result['created'].' stock-in record(s); voided '.$result['voided'].'; refreshed '.$result['refreshed'].'.');

        return self::SUCCESS;
    }
}
