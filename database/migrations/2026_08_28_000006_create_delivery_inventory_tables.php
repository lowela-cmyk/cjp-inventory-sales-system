<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_code', 30)->unique();
            $table->foreignId('sale_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('sale_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('fuel_type_id')->constrained()->restrictOnDelete();
            $table->enum('source_type', ['depot', 'garage'])->index();
            $table->foreignId('depot_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('storage_location_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('haul_allocation_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('truck_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('driver_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('scheduled_at')->nullable()->index();
            $table->dateTime('delivered_at')->nullable()->index();
            $table->decimal('scheduled_quantity_liters', 14, 2)->nullable();
            $table->decimal('actual_quantity_liters', 14, 2)->nullable();
            $table->enum('status', ['scheduled', 'in_transit', 'delivered', 'cancelled', 'incomplete'])
                ->default('scheduled')
                ->index();
            $table->timestamps();
        });

        Schema::create('stock_outs', function (Blueprint $table) {
            $table->id();
            $table->string('stock_out_code', 30)->unique();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('fuel_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('storage_location_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('quantity_liters', 14, 2);
            $table->dateTime('stock_out_at')->index();
            $table->enum('status', ['prepared', 'released', 'cancelled'])->default('prepared')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->string('movement_code', 30)->unique();
            $table->foreignId('storage_location_id')->constrained()->restrictOnDelete();
            $table->foreignId('fuel_type_id')->constrained()->restrictOnDelete();
            $table->enum('movement_type', ['beginning', 'stock_in', 'stock_out', 'adjustment'])->index();
            $table->enum('direction', ['in', 'out'])->index();
            $table->decimal('quantity_liters', 14, 2);
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->string('reference_type', 100);
            $table->unsignedBigInteger('reference_id');
            $table->dateTime('movement_date')->index();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['reference_type', 'reference_id']);
            $table->index(['storage_location_id', 'fuel_type_id']);
        });

        Schema::table('stock_outs', function (Blueprint $table) {
            $table->foreignId('inventory_movement_id')
                ->nullable()
                ->after('delivery_id')
                ->constrained('inventory_movements')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_outs');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('deliveries');
    }
};
