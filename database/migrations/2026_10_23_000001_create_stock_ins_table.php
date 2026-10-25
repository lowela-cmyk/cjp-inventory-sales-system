<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock-In revisions (October 23, 2026): every purchase line gets a stock-in record the moment the purchase is
 * recorded (no lift or tank needed). The record mirrors the purchase (date, fuel, depot, liters, cost) and is voided
 * when the purchase is cancelled or deleted. Liters taken out and remaining are not stored: they come from the sales
 * and releases that name the purchase (PurchaseStockService), so they can never drift from them.
 *
 * Existing purchases are backfilled here; cancelled or deleted ones get a voided stock-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_ins', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_id')->constrained('purchases')->restrictOnDelete();
            $table->foreignId('purchase_item_id')->unique()->constrained('purchase_items')->restrictOnDelete();
            $table->foreignId('fuel_type_id')->constrained('fuel_types')->restrictOnDelete();
            $table->foreignId('depot_id')->constrained('depots')->restrictOnDelete();
            $table->date('stock_in_date')->index();
            $table->decimal('quantity_liters', 14, 2);
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('total_cost', 16, 2);
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now()->toDateTimeString();
        DB::table('stock_ins')->insertUsing(
            ['purchase_id', 'purchase_item_id', 'fuel_type_id', 'depot_id', 'stock_in_date', 'quantity_liters', 'unit_cost', 'total_cost', 'status', 'voided_at', 'created_by', 'created_at', 'updated_at'],
            DB::table('purchase_items')
                ->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
                ->whereNotExists(fn (Builder $query): Builder => $query->selectRaw('1')->from('stock_ins')->whereColumn('stock_ins.purchase_item_id', 'purchase_items.id'))
                ->select(['purchase_items.purchase_id', 'purchase_items.id', 'purchase_items.fuel_type_id', 'purchases.depot_id', 'purchases.purchase_date', 'purchase_items.quantity_ordered_liters', 'purchase_items.unit_cost'])
                ->selectRaw('ROUND(purchase_items.quantity_ordered_liters * purchase_items.unit_cost, 2)')
                ->selectRaw("CASE WHEN purchases.status = 'cancelled' OR purchases.deleted_at IS NOT NULL THEN 'voided' ELSE 'active' END")
                ->selectRaw("CASE WHEN purchases.status = 'cancelled' OR purchases.deleted_at IS NOT NULL THEN COALESCE(purchases.deleted_at, purchases.updated_at, ?) ELSE NULL END", [$now])
                ->addSelect('purchases.created_by')
                ->selectRaw('?, ?', [$now, $now])
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_ins');
    }
};
