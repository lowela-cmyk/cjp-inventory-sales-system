<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('stock_outs')) {
            Schema::table('stock_outs', function (Blueprint $table): void {
                if (! Schema::hasColumn('stock_outs', 'source_type')) {
                    $table->enum('source_type', ['garage', 'depot'])
                        ->default('garage')
                        ->after('storage_location_id')
                        ->index();
                }

                if (! Schema::hasColumn('stock_outs', 'depot_id')) {
                    $table->foreignId('depot_id')
                        ->nullable()
                        ->after('source_type')
                        ->constrained()
                        ->restrictOnDelete();
                }

                if (! Schema::hasColumn('stock_outs', 'haul_allocation_id')) {
                    $table->foreignId('haul_allocation_id')
                        ->nullable()
                        ->after('depot_id')
                        ->constrained()
                        ->restrictOnDelete();
                }
            });

            if (Schema::hasColumn('stock_outs', 'storage_location_id')) {
                Schema::table('stock_outs', function (Blueprint $table): void {
                    $table->foreignId('storage_location_id')->nullable()->change();
                });
            }

            if (Schema::hasColumn('stock_outs', 'delivery_id')) {
                Schema::table('stock_outs', function (Blueprint $table): void {
                    $table->dropForeign(['delivery_id']);
                    $table->dropColumn('delivery_id');
                });
            }
        }

        Schema::dropIfExists('deliveries');
    }

    public function down(): void
    {
        if (! Schema::hasTable('deliveries')) {
            Schema::create('deliveries', function (Blueprint $table): void {
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
        }

        if (Schema::hasTable('stock_outs')) {
            if (Schema::hasColumn('stock_outs', 'storage_location_id')) {
                Schema::table('stock_outs', function (Blueprint $table): void {
                    $table->foreignId('storage_location_id')->nullable(false)->change();
                });
            }

            if (! Schema::hasColumn('stock_outs', 'delivery_id')) {
                Schema::table('stock_outs', function (Blueprint $table): void {
                    $table->foreignId('delivery_id')
                        ->nullable()
                        ->after('storage_location_id')
                        ->constrained()
                        ->restrictOnDelete();
                });
            }

            Schema::table('stock_outs', function (Blueprint $table): void {
                if (Schema::hasColumn('stock_outs', 'haul_allocation_id')) {
                    $table->dropForeign(['haul_allocation_id']);
                    $table->dropColumn('haul_allocation_id');
                }

                if (Schema::hasColumn('stock_outs', 'depot_id')) {
                    $table->dropForeign(['depot_id']);
                    $table->dropColumn('depot_id');
                }

                if (Schema::hasColumn('stock_outs', 'source_type')) {
                    $table->dropColumn('source_type');
                }
            });
        }
    }
};
