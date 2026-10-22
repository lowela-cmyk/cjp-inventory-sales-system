<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Part 2 Button Revisions (October 22, 2026): tanks and garage allocation are retired. Stock is deducted per
 * PURCHASE: a purchase line's remaining liters are its ordered liters less what deliveries released from it.
 *
 * - sales.delivery_type: "garage_to_client" becomes "standard". Standard orders carry their own Delivery Details
 *   (truck, driver, delivery date); Depot to Client orders are delivered through a lift.
 * - sale_items.purchase_item_id / f1_purchase_item_id: the purchases the line's fuel and its F1 portion come from.
 *   f1_unit_price: F1 has its own price (Line Total = main liters x price + F1 liters x F1 price).
 * - stock_outs.purchase_item_id / portion: the purchase a release was deducted from, and whether it is the line's
 *   own fuel ("main") or its F1 portion ("f1"). A purchase release is a depot release without a tank.
 *
 * Old tank tables and columns stay in place (unused) so history is kept. Existing rows are linked to their purchase
 * where the old data says which one it was (stock-lot lines, lot releases and direct depot releases).
 *
 * SQLite gets plain indexed columns: adding foreign keys there rebuilds the tables and drops the integrity triggers.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';

        Schema::table('sales', function (Blueprint $table): void {
            $table->unsignedBigInteger('delivery_truck_id')->nullable()->after('delivery_status_updated_by')->index();
            $table->unsignedBigInteger('delivery_driver_user_id')->nullable()->after('delivery_truck_id')->index();
            $table->dateTime('delivery_date')->nullable()->after('delivery_driver_user_id');
        });

        Schema::table('sale_items', function (Blueprint $table) use ($sqlite): void {
            foreach (['purchase_item_id', 'f1_purchase_item_id'] as $column) {
                if ($sqlite) {
                    $table->unsignedBigInteger($column)->nullable()->index();
                } else {
                    $table->foreignId($column)->nullable()->after('fuel_type_id')->constrained('purchase_items')->restrictOnDelete();
                }
            }
            $table->decimal('f1_unit_price', 12, 2)->default(0)->after('unit_price');
        });

        Schema::table('stock_outs', function (Blueprint $table) use ($sqlite): void {
            if ($sqlite) {
                $table->unsignedBigInteger('purchase_item_id')->nullable()->index();
            } else {
                $table->foreignId('purchase_item_id')->nullable()->after('haul_allocation_id')->constrained('purchase_items')->restrictOnDelete();
            }
            $table->string('portion', 10)->nullable()->after('fuel_type_id');
        });

        DB::table('sales')->where('delivery_type', 'garage_to_client')->update(['delivery_type' => 'standard']);
        if (! $sqlite) {
            DB::statement("ALTER TABLE sales ALTER delivery_type SET DEFAULT 'standard'");
        }

        $this->linkExistingRows();
        $this->replaceStockOutShapeRule();
    }

    public function down(): void
    {
        $this->restoreStockOutShapeRule();

        DB::table('sales')->where('delivery_type', 'standard')->update(['delivery_type' => 'garage_to_client']);
        $sqlite = DB::getDriverName() === 'sqlite';
        if (! $sqlite) {
            DB::statement("ALTER TABLE sales ALTER delivery_type SET DEFAULT 'garage_to_client'");
        }

        Schema::table('stock_outs', function (Blueprint $table) use ($sqlite): void {
            if ($sqlite) {
                $table->dropIndex(['purchase_item_id']);
                $table->dropColumn('purchase_item_id');
            } else {
                $table->dropConstrainedForeignId('purchase_item_id');
            }
            $table->dropColumn('portion');
        });

        Schema::table('sale_items', function (Blueprint $table) use ($sqlite): void {
            foreach (['purchase_item_id', 'f1_purchase_item_id'] as $column) {
                if ($sqlite) {
                    $table->dropIndex([$column]);
                    $table->dropColumn($column);
                } else {
                    $table->dropConstrainedForeignId($column);
                }
            }
            $table->dropColumn('f1_unit_price');
        });

        Schema::table('sales', function (Blueprint $table): void {
            $table->dropIndex(['delivery_truck_id']);
            $table->dropIndex(['delivery_driver_user_id']);
            $table->dropColumn(['delivery_truck_id', 'delivery_driver_user_id', 'delivery_date']);
        });
    }

    /**
     * Existing lines and releases get the purchase their old record points to.
     */
    private function linkExistingRows(): void
    {
        $f1FuelId = DB::table('fuel_types')->where('code', 'F1')->value('id');

        // A line sold from a stock lot: the lot's stock-in came from a lift of one purchase line.
        $lotPurchase = DB::table('inventory_movements')
            ->join('haul_allocations', 'haul_allocations.id', '=', 'inventory_movements.reference_id')
            ->join('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
            ->where('inventory_movements.reference_type', 'haul_allocation')
            ->pluck('hauls.purchase_item_id', 'inventory_movements.id');
        DB::table('sale_items')->whereNotNull('stock_in_movement_id')->orderBy('id')->get(['id', 'stock_in_movement_id'])
            ->each(function (object $item) use ($lotPurchase): void {
                if ($purchaseItemId = $lotPurchase[$item->stock_in_movement_id] ?? null) {
                    DB::table('sale_items')->where('id', $item->id)->update(['purchase_item_id' => $purchaseItemId]);
                }
            });

        // A Depot to Client line: the purchase of the lift it was released through.
        $directPurchase = DB::table('haul_allocations')
            ->join('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
            ->where('haul_allocations.destination_type', 'customer')
            ->whereNotNull('haul_allocations.sale_id')
            ->where('haul_allocations.status', '!=', 'cancelled')
            ->orderBy('haul_allocations.id')
            ->get(['haul_allocations.id', 'haul_allocations.sale_id', 'hauls.purchase_item_id']);
        foreach ($directPurchase->groupBy('sale_id') as $saleId => $rows) {
            DB::table('sale_items')->where('sale_id', $saleId)->whereNull('purchase_item_id')
                ->where('fuel_type_id', DB::table('purchase_items')->where('id', $rows->first()->purchase_item_id)->value('fuel_type_id'))
                ->update(['purchase_item_id' => $rows->first()->purchase_item_id]);
        }

        // Releases: a direct depot release names its lift; a lot release takes its line's purchase.
        foreach ($directPurchase as $allocation) {
            DB::table('stock_outs')->where('haul_allocation_id', $allocation->id)->whereNull('purchase_item_id')
                ->update(['purchase_item_id' => $allocation->purchase_item_id]);
        }
        DB::table('stock_outs')
            ->join('sale_items', 'sale_items.id', '=', 'stock_outs.sale_item_id')
            ->whereNull('stock_outs.purchase_item_id')
            ->whereNotNull('sale_items.stock_in_movement_id')
            ->whereColumn('stock_outs.fuel_type_id', 'sale_items.fuel_type_id')
            ->orderBy('stock_outs.id')
            ->get(['stock_outs.id', 'sale_items.stock_in_movement_id'])
            ->each(function (object $row) use ($lotPurchase): void {
                if ($purchaseItemId = $lotPurchase[$row->stock_in_movement_id] ?? null) {
                    DB::table('stock_outs')->where('id', $row->id)->update(['purchase_item_id' => $purchaseItemId]);
                }
            });

        // Which part of the line each existing release covers.
        DB::table('stock_outs')->whereNotNull('sale_item_id')->update(['portion' => 'main']);
        if ($f1FuelId !== null) {
            DB::table('stock_outs')
                ->whereNotNull('sale_item_id')
                ->where('fuel_type_id', $f1FuelId)
                ->whereExists(fn ($query) => $query->selectRaw('1')->from('sale_items')
                    ->whereColumn('sale_items.id', 'stock_outs.sale_item_id')
                    ->where('sale_items.fuel_type_id', '!=', $f1FuelId))
                ->update(['portion' => 'f1']);
        }
    }

    /**
     * A released depot row is either a lift release (haul_allocation_id) or a purchase release (purchase_item_id).
     */
    private function replaceStockOutShapeRule(): void
    {
        $driver = DB::getDriverName();
        $rule = "(source_type = 'garage' AND storage_location_id IS NOT NULL AND haul_allocation_id IS NULL)
            OR (source_type = 'depot' AND depot_id IS NOT NULL AND storage_location_id IS NULL AND (haul_allocation_id IS NOT NULL OR purchase_item_id IS NOT NULL))";

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            // MariaDB accepts named CHECK constraints through DROP CONSTRAINT (DROP CHECK is
            // rejected on the server versions used by the production copy).
            $this->dropCheckIfPresent('stock_outs', 'chk_stock_outs_released_shape');
            DB::statement("ALTER TABLE stock_outs ADD CONSTRAINT chk_stock_outs_released_shape CHECK (status <> 'released' OR ($rule))");
        } elseif ($driver === 'sqlite') {
            $this->sqliteStockOutTriggers($rule);
        }
    }

    private function restoreStockOutShapeRule(): void
    {
        $driver = DB::getDriverName();
        $rule = "(source_type = 'garage' AND storage_location_id IS NOT NULL AND haul_allocation_id IS NULL)
            OR (source_type = 'depot' AND depot_id IS NOT NULL AND haul_allocation_id IS NOT NULL AND storage_location_id IS NULL)";

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->dropCheckIfPresent('stock_outs', 'chk_stock_outs_released_shape');
            DB::statement("ALTER TABLE stock_outs ADD CONSTRAINT chk_stock_outs_released_shape CHECK (status <> 'released' OR ($rule))");
        } elseif ($driver === 'sqlite') {
            $this->sqliteStockOutTriggers($rule);
        }
    }

    private function dropCheckIfPresent(string $table, string $constraint): void
    {
        $exists = DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_NAME', $constraint)
            ->where('CONSTRAINT_TYPE', 'CHECK')
            ->exists();

        if ($exists) {
            DB::statement("ALTER TABLE `$table` DROP CONSTRAINT `$constraint`");
        }
    }

    private function sqliteStockOutTriggers(string $rule): void
    {
        $newRule = str_replace(['source_type', 'storage_location_id', 'haul_allocation_id', 'depot_id', 'purchase_item_id'], ['NEW.source_type', 'NEW.storage_location_id', 'NEW.haul_allocation_id', 'NEW.depot_id', 'NEW.purchase_item_id'], $rule);

        DB::unprepared('DROP TRIGGER IF EXISTS trg_chk_stock_outs_insert; DROP TRIGGER IF EXISTS trg_chk_stock_outs_update;');
        foreach (['insert' => 'INSERT', 'update' => 'UPDATE'] as $name => $event) {
            DB::unprepared("
                CREATE TRIGGER trg_chk_stock_outs_$name
                BEFORE $event ON stock_outs
                FOR EACH ROW
                WHEN NEW.quantity_liters <= 0 OR (NEW.status = 'released' AND NOT ($newRule))
                BEGIN
                    SELECT RAISE(ABORT, 'CHECK constraint failed: stock_outs constraint violated');
                END;
            ");
        }
    }
};
