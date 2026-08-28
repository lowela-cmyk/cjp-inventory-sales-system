<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('sale_code', 30)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->date('sale_date')->index();
            $table->enum('payment_method', ['cash_on_delivery', 'cheque', 'advance_payment', 'bank_transfer'])->nullable()->index();
            $table->enum('payment_terms', ['cod', 'installment', 'advance'])->default('cod')->index();
            $table->enum('status', ['draft', 'confirmed', 'partially_paid', 'paid', 'unpaid', 'cancelled'])
                ->default('confirmed')
                ->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('fuel_type_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity_liters', 14, 2);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('line_total', 14, 2);
            $table->decimal('fulfilled_quantity_liters', 14, 2)->default(0);
            $table->timestamps();

            $table->index(['sale_id', 'fuel_type_id']);
        });

        Schema::create('payment_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->date('due_date')->index();
            $table->decimal('amount_due', 14, 2);
            $table->enum('status', ['pending', 'partial', 'paid', 'overdue'])->default('pending')->index();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_code', 30)->unique();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->date('payment_date')->index();
            $table->decimal('amount', 14, 2);
            $table->enum('method', ['cash_on_delivery', 'cheque', 'advance_payment', 'bank_transfer'])->index();
            $table->string('reference_number', 100)->nullable()->index();
            $table->text('remarks')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('receivables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->unique()->constrained()->restrictOnDelete();
            $table->date('due_date')->nullable()->index();
            $table->enum('status', ['clear', 'pending', 'partial', 'unpaid', 'overdue'])->default('pending')->index();
            $table->dateTime('last_follow_up_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receivables');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('payment_schedules');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
