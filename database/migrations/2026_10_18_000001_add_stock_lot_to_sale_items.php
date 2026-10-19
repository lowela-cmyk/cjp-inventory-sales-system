<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Part 8 #8: a sale line can name the stock lot (incoming inventory movement) it is sold from.
 * Nullable: sales recorded before lots existed keep releasing from pooled garage stock.
 *
 * SQLite gets a plain indexed column: adding a foreign key there rebuilds sale_items, which would
 * drop the integrity triggers created in 2026_09_16_000002.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            if (DB::getDriverName() === 'sqlite') {
                $table->unsignedBigInteger('stock_in_movement_id')->nullable()->index();

                return;
            }

            $table->foreignId('stock_in_movement_id')->nullable()->after('fuel_type_id')
                ->constrained('inventory_movements')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            if (DB::getDriverName() === 'sqlite') {
                $table->dropIndex(['stock_in_movement_id']);
                $table->dropColumn('stock_in_movement_id');

                return;
            }

            $table->dropConstrainedForeignId('stock_in_movement_id');
        });
    }
};
