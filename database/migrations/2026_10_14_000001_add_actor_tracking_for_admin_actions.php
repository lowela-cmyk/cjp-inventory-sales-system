<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who last moved a lift's status and who uploaded its withdrawal receipt (driver, dispatch or admin).
        Schema::table('hauls', function (Blueprint $table): void {
            $table->foreignId('status_updated_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->dateTime('status_updated_at')->nullable()->after('status_updated_by');
            $table->foreignId('withdrawal_receipt_uploaded_by')->nullable()->after('withdrawal_receipt_uploaded_at')->constrained('users')->nullOnDelete();
        });

        // One row per state-changing request, so actions done by Admin on behalf of another role are traceable.
        Schema::create('activity_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_role', 50)->nullable();
            $table->string('route_name', 150)->nullable()->index();
            $table->string('method', 10);
            $table->string('path', 255);
            $table->json('route_parameters')->nullable();
            $table->unsignedSmallInteger('status_code');
            $table->boolean('succeeded')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->nullable()->index();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');

        Schema::table('hauls', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('withdrawal_receipt_uploaded_by');
            $table->dropConstrainedForeignId('status_updated_by');
            $table->dropColumn('status_updated_at');
        });
    }
};
