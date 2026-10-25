<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Part 11 (October 24, 2026): truck types are retired; any truck can lift or deliver. The column stays (with its
 * existing values) so it can be dropped later by decision, but new trucks no longer set it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trucks', function (Blueprint $table): void {
            $table->enum('truck_type', ['hauling', 'delivery', 'mixed'])->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('trucks')->whereNull('truck_type')->update(['truck_type' => 'mixed']);

        Schema::table('trucks', function (Blueprint $table): void {
            $table->enum('truck_type', ['hauling', 'delivery', 'mixed'])->nullable(false)->change();
        });
    }
};
