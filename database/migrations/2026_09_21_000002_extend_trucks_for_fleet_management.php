<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trucks', function (Blueprint $table): void {
            $table->string('name', 100)->nullable()->after('plate_number');
            $table->string('description', 255)->nullable()->after('name');
        });

        DB::table('trucks')->whereNull('name')->orderBy('id')->eachById(function (object $truck): void {
            DB::table('trucks')->where('id', $truck->id)->update([
                'name' => $truck->name ?: $truck->truck_code,
            ]);
        }, 100, 'id', 'id');

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE trucks MODIFY status ENUM('available','assigned','in_use','maintenance','inactive') NOT NULL DEFAULT 'available'");
        } else {
            Schema::table('trucks', function (Blueprint $table): void {
                $table->string('status', 20)->default('available')->change();
            });
        }
    }

    public function down(): void
    {
        DB::table('trucks')->where('status', 'in_use')->update(['status' => 'assigned']);

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE trucks MODIFY status ENUM('available','assigned','maintenance','inactive') NOT NULL DEFAULT 'available'");
        }

        Schema::table('trucks', function (Blueprint $table): void {
            $table->dropColumn(['name', 'description']);
        });
    }
};
