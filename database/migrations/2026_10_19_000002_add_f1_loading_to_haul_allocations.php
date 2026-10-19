<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Part 9 follow-up: the F1 portion of a Depot to Client order is loaded at the garage onto the same truck as its
 * main fuel. The Depot to Client lift row records the F1 liters and the garage F1 tank they come from; completing
 * the lift deducts them from that tank.
 *
 * SQLite gets a plain indexed column (a foreign key would rebuild haul_allocations and drop its integrity triggers).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('haul_allocations', function (Blueprint $table): void {
            if (DB::getDriverName() === 'sqlite') {
                $table->unsignedBigInteger('f1_storage_location_id')->nullable()->index();
            } else {
                $table->foreignId('f1_storage_location_id')->nullable()->after('sale_id')->constrained('storage_locations')->restrictOnDelete();
            }
            $table->decimal('f1_quantity_liters', 14, 2)->default(0)->after('quantity_liters');
        });
    }

    public function down(): void
    {
        Schema::table('haul_allocations', function (Blueprint $table): void {
            if (DB::getDriverName() === 'sqlite') {
                $table->dropIndex(['f1_storage_location_id']);
                $table->dropColumn('f1_storage_location_id');
            } else {
                $table->dropConstrainedForeignId('f1_storage_location_id');
            }
            $table->dropColumn('f1_quantity_liters');
        });
    }
};
