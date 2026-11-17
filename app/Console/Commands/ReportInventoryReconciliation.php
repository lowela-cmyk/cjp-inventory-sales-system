<?php

namespace App\Console\Commands;

use App\Services\InventoryReconciliationReportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ReportInventoryReconciliation extends Command
{
    protected $signature = 'inventory:reconciliation-report';

    protected $description = 'Read-only record-by-record delivery and inventory evidence on an isolated QA/test database';

    public function handle(InventoryReconciliationReportService $reports): int
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite' && ! preg_match('/(?:_qa|_test|_test_mariadb)$/', $connection->getDatabaseName())) {
            $this->error('Use an isolated *_qa, *_test or *_test_mariadb database. Production is refused.');
            return self::FAILURE;
        }
        if ($connection->getDriverName() !== 'sqlite') DB::statement('SET SESSION TRANSACTION READ ONLY');
        DB::beginTransaction();
        try {
            $this->line(json_encode($reports->report(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } finally {
            DB::rollBack();
            if ($connection->getDriverName() !== 'sqlite') DB::statement('SET SESSION TRANSACTION READ WRITE');
        }

        return self::SUCCESS;
    }
}
