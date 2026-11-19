<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Auditable register of historical source-data limitations found while importing historical records (for example
 * CJP_DATA.pdf): stock-outs whose lift or garage tank the source does not identify, F1 consumption the source does not
 * attribute to a sale, documented rounding variances, and source rows that could not be linked. It records evidence
 * only; it never changes a quantity, amount or relationship of an operational record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('historical_reconciliation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('import_batch_id')->nullable()->constrained('import_batches')->restrictOnDelete();
            $table->string('source_document', 150);
            $table->string('category', 50);
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('source_reference', 100)->nullable();
            $table->string('source_sheet', 80)->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->decimal('liters', 16, 2)->nullable();
            $table->decimal('amount', 16, 2)->nullable();
            $table->text('detail');
            $table->json('source_values')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('category');
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('historical_reconciliation_items') && DB::table('historical_reconciliation_items')->exists()) {
            throw new RuntimeException('historical_reconciliation_items still holds the historical exception register; export and remove it deliberately before rolling back.');
        }

        Schema::dropIfExists('historical_reconciliation_items');
    }
};
