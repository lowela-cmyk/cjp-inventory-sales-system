<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hauls', function (Blueprint $table) {
            $table->id();
            $table->string('haul_code', 30)->unique();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('depot_id')->constrained()->restrictOnDelete();
            $table->foreignId('fuel_type_id')->constrained()->restrictOnDelete();
            $table->foreignId('truck_id')->constrained()->restrictOnDelete();
            $table->foreignId('driver_user_id')->constrained('users')->restrictOnDelete();
            $table->string('dr_number', 100)->nullable()->index();
            $table->dateTime('scheduled_at')->index();
            $table->dateTime('hauled_at')->nullable()->index();
            $table->string('source_location')->nullable();
            $table->decimal('quantity_liters', 14, 2);
            $table->enum('status', ['scheduled', 'in_transit', 'lifted', 'completed', 'cancelled'])
                ->default('scheduled')
                ->index();
            $table->timestamps();
        });

        Schema::create('haul_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('haul_id')->constrained()->restrictOnDelete();
            $table->foreignId('fuel_type_id')->constrained()->restrictOnDelete();
            $table->enum('destination_type', ['garage', 'customer'])->index();
            $table->foreignId('storage_location_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('quantity_liters', 14, 2);
            $table->dateTime('allocated_at')->nullable()->index();
            $table->enum('status', ['planned', 'delivered', 'received', 'cancelled'])->default('planned')->index();
            $table->timestamps();

            $table->index(['haul_id', 'destination_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('haul_allocations');
        Schema::dropIfExists('hauls');
    }
};
