<?php

namespace App\Console\Commands;

use App\Models\ImportBatch;
use App\Services\ImportedTransactionAuditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditImportedTransactions extends Command
{
    protected $signature = 'imports:audit {batch : Import batch ID}';

    protected $description = 'Preview imported transaction inconsistencies and evidence-backed proposals on an isolated QA database; never writes';

    public function handle(ImportedTransactionAuditService $audit): int
    {
        $database = DB::connection()->getDatabaseName();
        if (DB::connection()->getDriverName() !== 'sqlite' && ! preg_match('/(?:_qa|_test|_test_mariadb)$/', $database)) {
            $this->error('Run this data check on an explicitly named isolated *_qa, *_test or *_test_mariadb database.');
            return self::FAILURE;
        }
        $batch = ImportBatch::find($this->argument('batch'));
        if (! $batch) { $this->error('Import batch not found.'); return self::FAILURE; }
        $this->line(json_encode($audit->report($batch), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
