<?php

namespace App\Console\Commands;

use App\Services\HistoricalInventoryRepairService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairHistoricalInventory extends Command
{
    protected $signature = 'inventory:repair-historical
        {path : Source workbook path}
        {--dry-run : Analyze and plan without writing records}
        {--apply : Apply the planned reconstruction}
        {--allow-qa : Required when applying to the isolated repair QA database}
        {--allow-production : Required when applying to a non-test database}';

    protected $description = 'Plan or apply source-faithful stock-out and garage inventory reconstruction';

    private const QA_DATABASE = 'cjp_inventory_repair_qa';

    public function handle(HistoricalInventoryRepairService $repair): int
    {
        // The target database is checked before the workbook is opened or read.
        $database = DB::connection()->getDatabaseName();
        $dryRun = (bool) $this->option('dry-run') || ! $this->option('apply');
        $isQa = $database === self::QA_DATABASE;
        if (! $dryRun) {
            // --allow-qa authorizes the isolated QA database and nothing else, test databases included.
            if ($this->option('allow-qa') && ! $isQa) {
                $this->error('Refusing to write reconstructed inventory records to '.$database.': --allow-qa only authorizes '.self::QA_DATABASE.'.');
                return self::FAILURE;
            }
            $isTest = str_ends_with($database, '_test') || str_ends_with($database, '_test_mariadb');
            if (! $this->option('allow-production') && ! ($isQa && $this->option('allow-qa')) && ! $isTest) {
                $this->error('Refusing to write reconstructed inventory records to '.$database.' without '.($isQa ? '--allow-qa' : '--allow-production').'.');
                return self::FAILURE;
            }
        }
        if (! is_file($this->argument('path'))) {
            $this->error('Workbook was not found.');
            return self::FAILURE;
        }

        $result = $repair->repair((string) $this->argument('path'), (int) DB::table('users')->orderBy('id')->value('id'), $dryRun);
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return empty($result['stock_out_unresolved']) && empty($result['garage_unresolved']) ? self::SUCCESS : self::INVALID;
    }
}
