<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales', 'sales_order_number')) {
            Schema::table('sales', function (Blueprint $table): void {
                $table->string('sales_order_number', 60)->nullable()->unique()->after('sale_code');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales', 'sales_order_number')) {
            Schema::table('sales', function (Blueprint $table): void {
                $table->dropUnique(['sales_order_number']);
                $table->dropColumn('sales_order_number');
            });
        }
    }
};
