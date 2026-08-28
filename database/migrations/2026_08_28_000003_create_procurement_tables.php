<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('purchase_code', 30)->unique();
            $table->foreignId('depot_id')->constrained()->restrictOnDelete();
            $table->date('purchase_date')->index();
            $table->string('receipt_reference')->nullable();
            $table->enum('payment_status', ['paid', 'partial', 'unpaid'])->default('unpaid')->index();
            $table->enum('status', ['draft', 'ordered', 'partially_hauled', 'hauled', 'cancelled'])
                ->default('ordered')
                ->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('fuel_type_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_ordered_liters', 14, 2);
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('line_total', 14, 2);
            $table->decimal('quantity_hauled_liters', 14, 2)->default(0);
            $table->enum('status', ['unlifted', 'partial', 'lifted'])->default('unlifted')->index();
            $table->timestamps();

            $table->index(['purchase_id', 'fuel_type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
    }
};
