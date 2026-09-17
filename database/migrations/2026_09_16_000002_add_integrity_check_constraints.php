<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('
                ALTER TABLE sale_items
                    ADD CONSTRAINT chk_sale_items_qty CHECK (quantity_liters > 0),
                    ADD CONSTRAINT chk_sale_items_price CHECK (unit_price > 0),
                    ADD CONSTRAINT chk_sale_items_total CHECK (line_total >= 0),
                    ADD CONSTRAINT chk_sale_items_fulfilled_nonneg CHECK (fulfilled_quantity_liters >= 0),
                    ADD CONSTRAINT chk_sale_items_fulfilled_lte_qty CHECK (fulfilled_quantity_liters <= quantity_liters)
            ');

            DB::statement('
                ALTER TABLE purchase_items
                    ADD CONSTRAINT chk_purchase_items_qty CHECK (quantity_ordered_liters > 0),
                    ADD CONSTRAINT chk_purchase_items_cost CHECK (unit_cost >= 0),
                    ADD CONSTRAINT chk_purchase_items_total CHECK (line_total >= 0),
                    ADD CONSTRAINT chk_purchase_items_hauled CHECK (quantity_hauled_liters >= 0)
            ');

            DB::statement('
                ALTER TABLE hauls
                    ADD CONSTRAINT chk_hauls_qty CHECK (quantity_liters > 0)
            ');

            DB::statement('
                ALTER TABLE payments
                    ADD CONSTRAINT chk_payments_amount CHECK (amount > 0)
            ');

            DB::statement('
                ALTER TABLE stock_outs
                    ADD CONSTRAINT chk_stock_outs_qty CHECK (quantity_liters > 0),
                    ADD CONSTRAINT chk_stock_outs_released_shape CHECK (
                        status != \'released\' OR (
                            (source_type = \'garage\' AND storage_location_id IS NOT NULL AND haul_allocation_id IS NULL)
                            OR
                            (source_type = \'depot\' AND depot_id IS NOT NULL AND haul_allocation_id IS NOT NULL AND storage_location_id IS NULL)
                        )
                    )
            ');

            DB::statement('
                ALTER TABLE inventory_movements
                    ADD CONSTRAINT chk_inv_movements_qty CHECK (quantity_liters > 0)
            ');

            DB::statement('
                ALTER TABLE haul_allocations
                    ADD CONSTRAINT chk_haul_allocations_qty CHECK (quantity_liters > 0),
                    ADD CONSTRAINT chk_haul_allocations_dest CHECK (
                        (destination_type = \'garage\' AND storage_location_id IS NOT NULL)
                        OR
                        (destination_type = \'customer\' AND customer_id IS NOT NULL)
                    )
            ');
        } elseif ($driver === 'sqlite') {
            DB::unprepared("
                CREATE TRIGGER IF NOT EXISTS trg_chk_sale_items_insert
                BEFORE INSERT ON sale_items
                FOR EACH ROW
                WHEN NEW.quantity_liters <= 0
                  OR NEW.unit_price <= 0
                  OR NEW.line_total < 0
                  OR NEW.fulfilled_quantity_liters < 0
                  OR NEW.fulfilled_quantity_liters > NEW.quantity_liters
                BEGIN
                    SELECT RAISE(ABORT, 'CHECK constraint failed: sale_items constraint violated');
                END;

                CREATE TRIGGER IF NOT EXISTS trg_chk_sale_items_update
                BEFORE UPDATE ON sale_items
                FOR EACH ROW
                WHEN NEW.quantity_liters <= 0
                  OR NEW.unit_price <= 0
                  OR NEW.line_total < 0
                  OR NEW.fulfilled_quantity_liters < 0
                  OR NEW.fulfilled_quantity_liters > NEW.quantity_liters
                BEGIN
                    SELECT RAISE(ABORT, 'CHECK constraint failed: sale_items constraint violated');
                END;

                CREATE TRIGGER IF NOT EXISTS trg_chk_purchase_items_insert
                BEFORE INSERT ON purchase_items
                FOR EACH ROW
                WHEN NEW.quantity_ordered_liters <= 0
                  OR NEW.unit_cost < 0
                  OR NEW.line_total < 0
                  OR NEW.quantity_hauled_liters < 0
                BEGIN
                    SELECT RAISE(ABORT, 'CHECK constraint failed: purchase_items constraint violated');
                END;

                CREATE TRIGGER IF NOT EXISTS trg_chk_purchase_items_update
                BEFORE UPDATE ON purchase_items
                FOR EACH ROW
                WHEN NEW.quantity_ordered_liters <= 0
                  OR NEW.unit_cost < 0
                  OR NEW.line_total < 0
                  OR NEW.quantity_hauled_liters < 0
                BEGIN
                    SELECT RAISE(ABORT, 'CHECK constraint failed: purchase_items constraint violated');
                END;

                CREATE TRIGGER IF NOT EXISTS trg_chk_hauls_insert
                BEFORE INSERT ON hauls
                FOR EACH ROW
                WHEN NEW.quantity_liters <= 0
                BEGIN
                    SELECT RAISE(ABORT, 'CHECK constraint failed: hauls.quantity_liters > 0');
                END;

                CREATE TRIGGER IF NOT EXISTS trg_chk_hauls_update
                BEFORE UPDATE ON hauls
                FOR EACH ROW
                WHEN NEW.quantity_liters <= 0
                BEGIN
                    SELECT RAISE(ABORT, 'CHECK constraint failed: hauls.quantity_liters > 0');
                END;

                CREATE TRIGGER IF NOT EXISTS trg_chk_payments_insert
                BEFORE INSERT ON payments
                FOR EACH ROW
                WHEN NEW.amount <= 0
                BEGIN
                    SELECT RAISE(ABORT, 'CHECK constraint failed: payments.amount > 0');
                END;

                CREATE TRIGGER IF NOT EXISTS trg_chk_payments_update
                BEFORE UPDATE ON payments
                FOR EACH ROW
                WHEN NEW.amount <= 0
                BEGIN
                    SELECT RAISE(ABORT, 'CHECK constraint failed: payments.amount > 0');
                END;

                CREATE TRIGGER IF NOT EXISTS trg_chk_stock_outs_insert
                BEFORE INSERT ON stock_outs
                FOR EACH ROW
                WHEN NEW.quantity_liters <= 0
                  OR (
                      NEW.status = 'released' AND NOT (
                          (NEW.source_type = 'garage' AND NEW.storage_location_id IS NOT NULL AND NEW.haul_allocation_id IS NULL)
                          OR
                          (NEW.source_type = 'depot' AND NEW.depot_id IS NOT NULL AND NEW.haul_allocation_id IS NOT NULL AND NEW.storage_location_id IS NULL)
                      )
                  )
                BEGIN
                    SELECT RAISE(ABORT, 'CHECK constraint failed: stock_outs constraint violated');
                END;

                CREATE TRIGGER IF NOT EXISTS trg_chk_stock_outs_update
                BEFORE UPDATE ON stock_outs
                FOR EACH ROW
                WHEN NEW.quantity_liters <= 0
                  OR (
                      NEW.status = 'released' AND NOT (
                          (NEW.source_type = 'garage' AND NEW.storage_location_id IS NOT NULL AND NEW.haul_allocation_id IS NULL)
                          OR
                          (NEW.source_type = 'depot' AND NEW.depot_id IS NOT NULL AND NEW.haul_allocation_id IS NOT NULL AND NEW.storage_location_id IS NULL)
                      )
                  )
                BEGIN
                    SELECT RAISE(ABORT, 'CHECK constraint failed: stock_outs constraint violated');
                END;

                CREATE TRIGGER IF NOT EXISTS trg_chk_inventory_movements_insert
                BEFORE INSERT ON inventory_movements
                FOR EACH ROW
                WHEN NEW.quantity_liters <= 0
                BEGIN
                    SELECT RAISE(ABORT, 'CHECK constraint failed: inventory_movements.quantity_liters > 0');
                END;

                CREATE TRIGGER IF NOT EXISTS trg_chk_inventory_movements_update
                BEFORE UPDATE ON inventory_movements
                FOR EACH ROW
                WHEN NEW.quantity_liters <= 0
                BEGIN
                    SELECT RAISE(ABORT, 'CHECK constraint failed: inventory_movements.quantity_liters > 0');
                END;

                CREATE TRIGGER IF NOT EXISTS trg_chk_haul_allocations_insert
                BEFORE INSERT ON haul_allocations
                FOR EACH ROW
                WHEN NEW.quantity_liters <= 0
                  OR NOT (
                      (NEW.destination_type = 'garage' AND NEW.storage_location_id IS NOT NULL)
                      OR
                      (NEW.destination_type = 'customer' AND NEW.customer_id IS NOT NULL)
                  )
                BEGIN
                    SELECT RAISE(ABORT, 'CHECK constraint failed: haul_allocations constraint violated');
                END;

                CREATE TRIGGER IF NOT EXISTS trg_chk_haul_allocations_update
                BEFORE UPDATE ON haul_allocations
                FOR EACH ROW
                WHEN NEW.quantity_liters <= 0
                  OR NOT (
                      (NEW.destination_type = 'garage' AND NEW.storage_location_id IS NOT NULL)
                      OR
                      (NEW.destination_type = 'customer' AND NEW.customer_id IS NOT NULL)
                  )
                BEGIN
                    SELECT RAISE(ABORT, 'CHECK constraint failed: haul_allocations constraint violated');
                END;
            ");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE sale_items DROP CHECK chk_sale_items_qty, DROP CHECK chk_sale_items_price, DROP CHECK chk_sale_items_total, DROP CHECK chk_sale_items_fulfilled_nonneg, DROP CHECK chk_sale_items_fulfilled_lte_qty');
            DB::statement('ALTER TABLE purchase_items DROP CHECK chk_purchase_items_qty, DROP CHECK chk_purchase_items_cost, DROP CHECK chk_purchase_items_total, DROP CHECK chk_purchase_items_hauled');
            DB::statement('ALTER TABLE hauls DROP CHECK chk_hauls_qty');
            DB::statement('ALTER TABLE payments DROP CHECK chk_payments_amount');
            DB::statement('ALTER TABLE stock_outs DROP CHECK chk_stock_outs_qty, DROP CHECK chk_stock_outs_released_shape');
            DB::statement('ALTER TABLE inventory_movements DROP CHECK chk_inv_movements_qty');
            DB::statement('ALTER TABLE haul_allocations DROP CHECK chk_haul_allocations_qty, DROP CHECK chk_haul_allocations_dest');
        } elseif ($driver === 'sqlite') {
            DB::unprepared('
                DROP TRIGGER IF EXISTS trg_chk_sale_items_insert;
                DROP TRIGGER IF EXISTS trg_chk_sale_items_update;
                DROP TRIGGER IF EXISTS trg_chk_purchase_items_insert;
                DROP TRIGGER IF EXISTS trg_chk_purchase_items_update;
                DROP TRIGGER IF EXISTS trg_chk_hauls_insert;
                DROP TRIGGER IF EXISTS trg_chk_hauls_update;
                DROP TRIGGER IF EXISTS trg_chk_payments_insert;
                DROP TRIGGER IF EXISTS trg_chk_payments_update;
                DROP TRIGGER IF EXISTS trg_chk_stock_outs_insert;
                DROP TRIGGER IF EXISTS trg_chk_stock_outs_update;
                DROP TRIGGER IF EXISTS trg_chk_inventory_movements_insert;
                DROP TRIGGER IF EXISTS trg_chk_inventory_movements_update;
                DROP TRIGGER IF EXISTS trg_chk_haul_allocations_insert;
                DROP TRIGGER IF EXISTS trg_chk_haul_allocations_update;
            ');
        }
    }
};
