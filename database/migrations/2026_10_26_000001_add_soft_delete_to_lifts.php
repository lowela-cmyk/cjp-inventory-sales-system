<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Part 11 (October 26, 2026): a lift still Scheduled can be deleted. The lift and its hauls are kept (soft delete) with
 * who deleted them; every list and count skips them.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['lifting_schedules', 'hauls'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->softDeletes();
                $table->foreignId('deleted_by')->nullable()->after('deleted_at')->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['lifting_schedules', 'hauls'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropConstrainedForeignId('deleted_by');
                $table->dropSoftDeletes();
            });
        }
    }
};
