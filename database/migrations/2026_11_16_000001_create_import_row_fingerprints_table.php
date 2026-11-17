<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_row_fingerprints', function (Blueprint $table): void {
            $table->string('fingerprint', 64)->primary();
            $table->foreignId('import_batch_id')->constrained('import_batches');
            $table->string('category', 40);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_row_fingerprints');
    }
};
