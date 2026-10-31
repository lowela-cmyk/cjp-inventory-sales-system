<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_source_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->string('from_delivery_type', 40);
            $table->string('to_delivery_type', 40);
            $table->string('source_type', 40);
            $table->foreignId('haul_id')->nullable()->constrained('hauls')->nullOnDelete();
            $table->foreignId('garage_stock_id')->nullable()->constrained('garage_stocks')->nullOnDelete();
            $table->string('source_label', 160);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('changed_at');
            $table->timestamps();

            $table->index(['sale_id', 'changed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_source_changes');
    }
};
