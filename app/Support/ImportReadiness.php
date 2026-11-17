<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

final class ImportReadiness
{
    public static function ready(): bool
    {
        return Schema::hasTable('import_batches') && Schema::hasTable('import_row_fingerprints');
    }

    public static function assertReady(): void
    {
        if (! self::ready()) throw new \RuntimeException('Data Import is unavailable until the administrator completes the required database update. No records were imported.');
    }
}
