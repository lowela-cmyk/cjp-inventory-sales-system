<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->enum('delivery_status', ['pending', 'out_for_delivery', 'delivered', 'cancelled'])
                ->default('pending')
                ->index()
                ->after('status');
            $table->dateTime('delivered_at')->nullable()->after('delivery_status');
            $table->dateTime('delivery_status_updated_at')->nullable()->after('delivered_at');
            $table->foreignId('delivery_status_updated_by')->nullable()->after('delivery_status_updated_at')->constrained('users')->nullOnDelete();
        });

        DB::table('sales')
            ->where('status', 'cancelled')
            ->update(['delivery_status' => 'cancelled']);

        DB::table('sales')
            ->where('status', '!=', 'cancelled')
            ->where(function ($query): void {
                $query->whereExists(function ($stockOuts): void {
                    $stockOuts->selectRaw('1')
                        ->from('stock_outs')
                        ->whereColumn('stock_outs.sale_id', 'sales.id')
                        ->where('stock_outs.status', 'released');
                })->orWhereExists(function ($items): void {
                    $items->selectRaw('1')
                        ->from('sale_items')
                        ->whereColumn('sale_items.sale_id', 'sales.id')
                        ->where('sale_items.fulfilled_quantity_liters', '>', 0);
                });
            })
            ->update([
                'delivery_status' => 'delivered',
                'delivered_at' => DB::raw('updated_at'),
                'delivery_status_updated_at' => DB::raw('updated_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table): void {
            $table->dropForeign(['delivery_status_updated_by']);
            $table->dropIndex(['delivery_status']);
            $table->dropColumn([
                'delivery_status',
                'delivered_at',
                'delivery_status_updated_at',
                'delivery_status_updated_by',
            ]);
        });
    }
};
