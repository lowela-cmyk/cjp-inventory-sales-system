<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A voided payment stays in the table (and in the payment history) but no total, balance, status or
 * collection figure counts it. Who voided it, when and why are kept on the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->timestamp('voided_at')->nullable()->after('received_by')->index();
            $table->foreignId('voided_by')->nullable()->after('voided_at')->constrained('users')->nullOnDelete();
            $table->string('void_reason', 500)->nullable()->after('voided_by');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropIndex(['voided_at']);
            $table->dropColumn(['voided_at', 'void_reason']);
        });
    }
};
