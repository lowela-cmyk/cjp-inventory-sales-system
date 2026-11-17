<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $indexes = [
        'stock_outs' => ['idx_release_status_purchase' => ['status', 'purchase_item_id'], 'idx_release_status_sale_item' => ['status', 'sale_item_id'], 'idx_release_date_id' => ['stock_out_at', 'id']],
        'hauls' => ['idx_haul_purchase_status' => ['purchase_item_id', 'status', 'deleted_at']],
        'sales' => ['idx_sales_delivery_status_date' => ['delivery_status', 'deleted_at', 'status', 'sale_date']],
        'stock_ins' => ['idx_stockin_date_id' => ['stock_in_date', 'id']],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            foreach ($indexes as $name => $columns) Schema::table($table, fn (Blueprint $blueprint) => $blueprint->index($columns, $name));
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $indexes) {
            foreach ($indexes as $name => $columns) Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropIndex($name));
        }
    }
};
