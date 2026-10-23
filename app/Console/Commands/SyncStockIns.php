<?php

namespace App\Console\Commands;

use App\Services\StockInService;
use Illuminate\Console\Command;

/**
 * Stock-In revisions (October 23, 2026): gives purchases written outside Purchases (imports, manual SQL) their
 * stock-in and voids those of cancelled or deleted purchases. Safe to run any number of times.
 */
class SyncStockIns extends Command
{
    protected $signature = 'stock-in:sync';

    protected $description = 'Create missing stock-in records for purchases and void those of cancelled purchases';

    public function handle(StockInService $stockIns): int
    {
        $result = $stockIns->syncAll();

        $this->info('Created '.$result['created'].' stock-in record(s); voided '.$result['voided'].'.');

        return self::SUCCESS;
    }
}
