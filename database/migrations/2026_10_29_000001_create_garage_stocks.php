<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Part 13 (October 28, 2026): fuel left on a truck is unloaded into a garage tank (two 24,000 L tanks per fuel, the
 * existing storage_locations garage tanks). Each unload is a garage lot tied to its purchase line and to one tank;
 * garage fuel leaves only through Garage to Client sales, whose sale lines (main and F1 portion) name the lot and whose
 * releases deduct from it. Nothing of the old tank ledger (inventory_movements) is used.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('garage_stocks', function (Blueprint $table): void {
            $table->id();
            $table->string('lot_code', 30)->unique();
            $table->foreignId('storage_location_id')->constrained('storage_locations')->restrictOnDelete();
            $table->foreignId('purchase_item_id')->constrained('purchase_items')->restrictOnDelete();
            $table->foreignId('fuel_type_id')->constrained('fuel_types')->restrictOnDelete();
            $table->foreignId('haul_id')->constrained('hauls')->restrictOnDelete();
            // The lift's "garage" row: it takes the liters off the truck.
            $table->foreignId('haul_allocation_id')->constrained('haul_allocations')->restrictOnDelete();
            $table->foreignId('truck_id')->nullable()->constrained('trucks')->nullOnDelete();
            $table->decimal('liters', 14, 2);
            $table->decimal('unit_cost', 14, 2);
            $table->timestamp('unloaded_at');
            $table->foreignId('unloaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['storage_location_id', 'fuel_type_id']);
        });

        // SQLite (tests) gets plain indexed columns: adding a foreign key there rebuilds the table and drops the
        // integrity triggers on sale_items / stock_outs (same as the October 22 migration).
        $sqlite = DB::getDriverName() === 'sqlite';
        Schema::table('stock_outs', function (Blueprint $table) use ($sqlite): void {
            $sqlite
                ? $table->unsignedBigInteger('garage_stock_id')->nullable()->index()
                : $table->foreignId('garage_stock_id')->nullable()->after('haul_allocation_id')->constrained('garage_stocks')->nullOnDelete();
        });

        Schema::table('sale_items', function (Blueprint $table) use ($sqlite): void {
            foreach (['garage_stock_id' => 'purchase_item_id', 'f1_garage_stock_id' => 'f1_purchase_item_id'] as $column => $after) {
                $sqlite
                    ? $table->unsignedBigInteger($column)->nullable()->index()
                    : $table->foreignId($column)->nullable()->after($after)->constrained('garage_stocks')->nullOnDelete();
            }
        });

        // "garage_to_client" now means Garage to Client: SQLite still had it as the column default (MySQL was moved to
        // "standard" on October 22); there are no triggers on sales, so rebuilding the column is safe.
        if ($sqlite) {
            Schema::table('sales', function (Blueprint $table): void {
                $table->string('delivery_type', 30)->default('standard')->change();
            });
        }
    }

    public function down(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        Schema::table('sale_items', function (Blueprint $table) use ($sqlite): void {
            foreach (['f1_garage_stock_id', 'garage_stock_id'] as $column) {
                $sqlite ? $table->dropColumn($column) : $table->dropConstrainedForeignId($column);
            }
        });
        Schema::table('stock_outs', function (Blueprint $table) use ($sqlite): void {
            $sqlite ? $table->dropColumn('garage_stock_id') : $table->dropConstrainedForeignId('garage_stock_id');
        });
        Schema::dropIfExists('garage_stocks');
    }
};
