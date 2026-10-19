<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Part 9 (October 19, 2026).
 * - purchases.payment_mode / bank_name: how the purchase was paid (Bank Transfer / Cash / Cheque) and the bank.
 * - hauls.atl_number: Authority to Load, entered per purchase in Schedule Lift.
 * - sales.delivery_type: Garage to Client (sold from garage stock) or Depot to Client (released through a lift).
 * - sale_items F1 split: f1_percentage of the ordered liters comes from F1 stock, the rest from the line's own
 *   fuel. main_quantity_liters + f1_quantity_liters = quantity_liters. Existing lines have no F1 portion.
 *
 * Every new column is nullable or has a default, so existing records load as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table): void {
            $table->string('payment_mode', 30)->nullable()->after('payment_status');
            $table->string('bank_name', 30)->nullable()->after('payment_mode');
        });

        Schema::table('hauls', function (Blueprint $table): void {
            $table->string('atl_number', 100)->nullable()->after('dr_number');
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->string('delivery_type', 30)->default('garage_to_client')->after('sales_order_number')->index();
        });

        Schema::table('sale_items', function (Blueprint $table): void {
            $table->decimal('f1_percentage', 5, 2)->default(0)->after('unit_price');
            $table->decimal('main_quantity_liters', 14, 2)->default(0)->after('f1_percentage');
            $table->decimal('f1_quantity_liters', 14, 2)->default(0)->after('main_quantity_liters');
        });

        DB::table('sale_items')->update(['main_quantity_liters' => DB::raw('quantity_liters')]);
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table): void {
            $table->dropColumn(['f1_percentage', 'main_quantity_liters', 'f1_quantity_liters']);
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->dropIndex(['delivery_type']);
            $table->dropColumn('delivery_type');
        });

        Schema::table('hauls', function (Blueprint $table): void {
            $table->dropColumn('atl_number');
        });

        Schema::table('purchases', function (Blueprint $table): void {
            $table->dropColumn(['payment_mode', 'bank_name']);
        });
    }
};
