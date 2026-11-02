<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Part 19: deleting an untouched draft sale keeps its change history. The history's sale link becomes empty (null)
 * instead of the history being deleted with the sale (it was ON DELETE CASCADE).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_change_logs', function (Blueprint $table): void {
            $table->dropForeign(['sale_id']);
        });

        Schema::table('sale_change_logs', function (Blueprint $table): void {
            $table->unsignedBigInteger('sale_id')->nullable()->change();
            $table->foreign('sale_id')->references('id')->on('sales')->nullOnDelete();
        });
    }

    /**
     * Restores ON DELETE CASCADE. The column stays nullable so history kept for deleted drafts is not lost.
     */
    public function down(): void
    {
        Schema::table('sale_change_logs', function (Blueprint $table): void {
            $table->dropForeign(['sale_id']);
        });

        Schema::table('sale_change_logs', function (Blueprint $table): void {
            $table->foreign('sale_id')->references('id')->on('sales')->cascadeOnDelete();
        });
    }
};
