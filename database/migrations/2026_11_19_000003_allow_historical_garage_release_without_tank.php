<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historical garage releases whose source names the purchase but not the garage tank are stored with no tank and
 * historical_tank_unknown = 1, instead of an assumed tank. The flag is the only way a released garage row may lack a
 * tank, and it needs the source purchase; every other release keeps the previous rule, and depot rows can never carry
 * the flag. The application never sets it for new transactions.
 */
return new class extends Migration
{
    private const OLD_RULE = "(source_type = 'garage' AND storage_location_id IS NOT NULL AND haul_allocation_id IS NULL)
            OR (source_type = 'depot' AND depot_id IS NOT NULL AND storage_location_id IS NULL AND (haul_allocation_id IS NOT NULL OR purchase_item_id IS NOT NULL))";

    private const NEW_RULE = "(source_type = 'garage' AND haul_allocation_id IS NULL AND ((storage_location_id IS NOT NULL AND historical_tank_unknown = 0)
                OR (storage_location_id IS NULL AND historical_tank_unknown = 1 AND purchase_item_id IS NOT NULL)))
            OR (source_type = 'depot' AND depot_id IS NOT NULL AND storage_location_id IS NULL AND historical_tank_unknown = 0 AND (haul_allocation_id IS NOT NULL OR purchase_item_id IS NOT NULL))";

    public function up(): void
    {
        Schema::table('stock_outs', function (Blueprint $table): void {
            $table->boolean('historical_tank_unknown')->default(false)->after('storage_location_id');
        });
        $this->applyRule(self::NEW_RULE, ['historical_tank_unknown']);
    }

    public function down(): void
    {
        if (DB::table('stock_outs')->where('historical_tank_unknown', true)->exists()) {
            throw new RuntimeException('Historical garage releases without a recorded tank still exist; they must be resolved before this migration can be rolled back.');
        }
        $this->applyRule(self::OLD_RULE, []);
        Schema::table('stock_outs', function (Blueprint $table): void {
            $table->dropColumn('historical_tank_unknown');
        });
    }

    /** @param list<string> $extraColumns */
    private function applyRule(string $rule, array $extraColumns): void
    {
        $driver = DB::getDriverName();
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $exists = DB::table('information_schema.TABLE_CONSTRAINTS')->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', 'stock_outs')->where('CONSTRAINT_NAME', 'chk_stock_outs_released_shape')->exists();
            if ($exists) DB::statement('ALTER TABLE stock_outs DROP CONSTRAINT chk_stock_outs_released_shape');
            DB::statement("ALTER TABLE stock_outs ADD CONSTRAINT chk_stock_outs_released_shape CHECK (status <> 'released' OR ($rule))");
        } elseif ($driver === 'sqlite') {
            // Same shape as 2026_10_22_000001_purchase_based_stock: triggers carry the rule and the positive-quantity check.
            $columns = ['source_type', 'storage_location_id', 'haul_allocation_id', 'depot_id', 'purchase_item_id', ...$extraColumns];
            $newRule = preg_replace_callback('/\b('.implode('|', $columns).')\b/', fn (array $m): string => 'NEW.'.$m[1], $rule);
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
    }
};
