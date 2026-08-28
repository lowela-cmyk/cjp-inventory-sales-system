<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->string('alert_code', 30)->unique();
            $table->enum('type', ['inventory', 'purchase', 'haul', 'delivery', 'payment', 'receivable', 'discrepancy'])->index();
            $table->enum('severity', ['info', 'warning', 'critical'])->index();
            $table->string('title');
            $table->text('message');
            $table->string('reference_type', 100)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->enum('status', ['open', 'acknowledged', 'resolved', 'dismissed'])->default('open')->index();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('resolved_at')->nullable()->index();
            $table->timestamps();

            $table->index(['reference_type', 'reference_id']);
        });

        Schema::create('report_runs', function (Blueprint $table) {
            $table->id();
            $table->string('report_code', 30)->unique();
            $table->enum('report_type', ['sales', 'inventory', 'receivables', 'hauling', 'delivery', 'analytics'])->index();
            $table->date('date_from')->nullable()->index();
            $table->date('date_to')->nullable()->index();
            $table->json('parameters')->nullable();
            $table->string('file_path')->nullable();
            $table->foreignId('generated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('ai_insights', function (Blueprint $table) {
            $table->id();
            $table->string('insight_code', 30)->unique();
            $table->foreignId('report_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('summary');
            $table->json('insight_payload')->nullable();
            $table->enum('status', ['draft', 'reviewed', 'archived'])->default('draft')->index();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_insights');
        Schema::dropIfExists('report_runs');
        Schema::dropIfExists('alerts');
    }
};
