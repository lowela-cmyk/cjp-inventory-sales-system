<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Part 12 (October 26, 2026): fuel left on a truck after its lift. While a lift's truck still holds fuel the truck is
 * "Loaded" (not Available); more customer orders can be delivered from it, or the rest is returned to the depot.
 * A return moves the leftover from the lift line back to its purchase: the line's quantity_liters goes down by the
 * returned liters and returned_liters / returned_at / returned_by record it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hauls', function (Blueprint $table): void {
            $table->decimal('returned_liters', 14, 2)->default(0)->after('quantity_liters');
            $table->timestamp('returned_at')->nullable()->after('returned_liters');
            $table->foreignId('returned_by')->nullable()->after('returned_at')->constrained('users')->nullOnDelete();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE trucks MODIFY status ENUM('available','assigned','in_use','loaded','maintenance','inactive') NOT NULL DEFAULT 'available'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::table('trucks')->where('status', 'loaded')->update(['status' => 'available']);
            DB::statement("ALTER TABLE trucks MODIFY status ENUM('available','assigned','in_use','maintenance','inactive') NOT NULL DEFAULT 'available'");
        }

        Schema::table('hauls', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('returned_by');
            $table->dropColumn(['returned_liters', 'returned_at']);
        });
    }
};
