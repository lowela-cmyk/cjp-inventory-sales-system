<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Part 9 (October 20, 2026): every garage tank holds at most 24,000 L (24KL).
 * Existing garage tanks get capacity_liters = 24,000 and new tanks default to it.
 *
 * SQLite keeps the nullable column as is (changing its default rebuilds the table); GarageTankService writes the
 * capacity on new tanks and treats a missing capacity as 24,000 L, so the rule holds on every driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('storage_locations')->where('type', 'garage')->update(['capacity_liters' => 24000, 'updated_at' => now()]);

        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('storage_locations', function (Blueprint $table): void {
                $table->decimal('capacity_liters', 14, 2)->nullable()->default(24000)->change();
            });
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('storage_locations', function (Blueprint $table): void {
                $table->decimal('capacity_liters', 14, 2)->nullable()->default(null)->change();
            });
        }
    }
};
