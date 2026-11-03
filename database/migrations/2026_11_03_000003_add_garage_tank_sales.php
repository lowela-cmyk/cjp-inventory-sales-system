<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Part 18 add-on (November 3, 2026): fuel in a garage tank is mixed, so Garage to Client lines (and F1 portions from the
 * garage) sell from a TANK, at the tank's weighted average cost, instead of from one garage lot.
 *
 * - sale_items.garage_tank_id / f1_garage_tank_id: the tank a line's own fuel / its F1 portion sells from. Such a
 *   line has no purchase_item_id / garage_stock_id of its own (its liters may come from several purchases).
 * - stock_out_draws: a tank release is one stock-out (source garage, the tank, cost = the tank's average); its draws
 *   are the liters it took from each lot in the tank (proportional to what each lot holds), each with its purchase
 *   line and the lot's cost, so every purchase still adds up.
 * - sale_source_changes.storage_location_id: the tank an Assign Source -> Garage chose.
 *
 * Nothing existing is changed: lot-based garage sales and their releases stay as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite (tests) gets plain indexed columns on existing tables: adding a foreign key there rebuilds the table
        // and drops the integrity triggers on sale_items (same as the October 22 and 29 migrations).
        $sqlite = DB::getDriverName() === 'sqlite';
        Schema::table('sale_items', function (Blueprint $table) use ($sqlite): void {
            foreach (['garage_tank_id' => 'f1_garage_stock_id', 'f1_garage_tank_id' => 'garage_tank_id'] as $column => $after) {
                $sqlite
                    ? $table->unsignedBigInteger($column)->nullable()->index()
                    : $table->foreignId($column)->nullable()->after($after)->constrained('storage_locations')->nullOnDelete();
            }
        });

        Schema::create('stock_out_draws', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_out_id')->constrained('stock_outs')->cascadeOnDelete();
            $table->foreignId('garage_stock_id')->constrained('garage_stocks')->restrictOnDelete();
            $table->foreignId('purchase_item_id')->constrained('purchase_items')->restrictOnDelete();
            $table->decimal('liters', 14, 2);
            $table->decimal('unit_cost', 14, 2);
            $table->timestamps();
        });

        Schema::table('sale_source_changes', function (Blueprint $table) use ($sqlite): void {
            $sqlite
                ? $table->unsignedBigInteger('storage_location_id')->nullable()->index()
                : $table->foreignId('storage_location_id')->nullable()->after('garage_stock_id')->constrained('storage_locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        Schema::table('sale_source_changes', function (Blueprint $table) use ($sqlite): void {
            $sqlite ? $table->dropColumn('storage_location_id') : $table->dropConstrainedForeignId('storage_location_id');
        });
        Schema::dropIfExists('stock_out_draws');
        Schema::table('sale_items', function (Blueprint $table) use ($sqlite): void {
            foreach (['f1_garage_tank_id', 'garage_tank_id'] as $column) {
                $sqlite ? $table->dropColumn($column) : $table->dropConstrainedForeignId($column);
            }
        });
    }
};
