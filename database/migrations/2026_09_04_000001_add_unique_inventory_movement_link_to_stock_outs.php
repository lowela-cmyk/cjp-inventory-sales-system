<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_outs', function (Blueprint $table): void {
            $table->unique('inventory_movement_id', 'stock_outs_inventory_movement_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('stock_outs', function (Blueprint $table): void {
            $table->dropUnique('stock_outs_inventory_movement_id_unique');
        });
    }
};
