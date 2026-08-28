<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'inventory_officer', 'sales_officer', 'dispatch_officer', 'driver'])
                ->default('admin')
                ->after('email')
                ->index();
            $table->string('phone', 30)->nullable()->after('password');
            $table->enum('status', ['active', 'inactive'])->default('active')->after('phone')->index();
            $table->timestamp('last_login_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropIndex(['status']);
            $table->dropColumn(['role', 'phone', 'status', 'last_login_at']);
        });
    }
};
