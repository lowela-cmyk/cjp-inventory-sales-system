<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Part 17 #7: the user who last changed a sale (edit, delivery status, payment, cancel), next to created_by. Existing
 * sales keep NULL and fall back to whoever last changed their delivery status (or "N/A"). No rows are changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('updated_by');
        });
    }
};
