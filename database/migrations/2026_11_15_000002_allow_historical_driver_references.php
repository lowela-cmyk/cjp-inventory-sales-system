<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['hauls', 'lifting_schedules'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['driver_user_id']);
            });

            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('driver_user_id')->nullable()->change();
                $table->string('historical_driver_name', 150)->nullable()->after('driver_user_id');
            });

            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreign('driver_user_id')->references('id')->on('users')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['hauls', 'lifting_schedules'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropForeign(['driver_user_id']);
                $table->dropColumn('historical_driver_name');
            });

            Schema::table($tableName, function (Blueprint $table): void {
                $table->foreignId('driver_user_id')->nullable(false)->change();
                $table->foreign('driver_user_id')->references('id')->on('users')->restrictOnDelete();
            });
        }
    }
};
