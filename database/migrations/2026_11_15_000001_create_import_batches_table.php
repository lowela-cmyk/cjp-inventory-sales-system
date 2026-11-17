<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('batch_uuid')->unique();
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('file_hash', 64)->index();
            $table->string('file_type', 10);
            $table->enum('status', ['preview', 'confirmed', 'processing', 'completed', 'failed', 'cancelled'])->default('preview')->index();
            $table->json('workbook_summary');
            $table->json('mapping_summary');
            $table->json('validation_summary');
            $table->json('result_summary')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
