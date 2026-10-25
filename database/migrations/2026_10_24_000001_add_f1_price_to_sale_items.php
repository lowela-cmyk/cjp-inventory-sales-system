<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->decimal('f1_price', 12, 2)->nullable()->after('f1_unit_price');
        });

        // Preserve the price already recorded by the Part 11 implementation.
        DB::table('sale_items')->whereNull('f1_price')->update([
            'f1_price' => DB::raw('NULLIF(f1_unit_price, 0)'),
        ]);
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropColumn('f1_price');
        });
    }
};
