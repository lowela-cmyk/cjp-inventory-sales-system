<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Part 7 / audit C01: garage and depot allocations are entered when a lift is scheduled.
 * - storage_locations.capacity_liters: tank size, so Schedule Lift can show free capacity (null = not set).
 * - haul_allocations.receive_on_completion: the scheduler chose this tank, so completing the lift posts
 *   the stock-in automatically. False means the tank is a default that the Inventory Officer confirms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storage_locations', function (Blueprint $table): void {
            $table->decimal('capacity_liters', 14, 2)->nullable()->after('tank_number');
        });

        Schema::table('haul_allocations', function (Blueprint $table): void {
            $table->boolean('receive_on_completion')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('haul_allocations', function (Blueprint $table): void {
            $table->dropColumn('receive_on_completion');
        });

        Schema::table('storage_locations', function (Blueprint $table): void {
            $table->dropColumn('capacity_liters');
        });
    }
};
