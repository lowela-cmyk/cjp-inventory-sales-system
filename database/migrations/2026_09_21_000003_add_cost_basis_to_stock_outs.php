<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('stock_outs', 'unit_cost')) {
            Schema::table('stock_outs', function (Blueprint $table): void {
                $table->decimal('unit_cost', 12, 2)->nullable()->after('quantity_liters');
            });
        }

        $this->backfillDirectDepotCosts();
        $this->backfillGarageCosts();
    }

    public function down(): void
    {
        if (Schema::hasColumn('stock_outs', 'unit_cost')) {
            Schema::table('stock_outs', function (Blueprint $table): void {
                $table->dropColumn('unit_cost');
            });
        }
    }

    private function backfillDirectDepotCosts(): void
    {
        DB::table('stock_outs')
            ->join('haul_allocations', 'haul_allocations.id', '=', 'stock_outs.haul_allocation_id')
            ->join('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
            ->join('purchase_items', 'purchase_items.id', '=', 'hauls.purchase_item_id')
            ->where('stock_outs.source_type', 'depot')
            ->whereNull('stock_outs.unit_cost')
            ->orderBy('stock_outs.id')
            ->get(['stock_outs.id', 'purchase_items.unit_cost'])
            ->each(fn (object $row) => DB::table('stock_outs')->where('id', $row->id)->update([
                'unit_cost' => $row->unit_cost,
            ]));
    }

    private function backfillGarageCosts(): void
    {
        $cancelledStockOuts = DB::table('stock_outs')->where('status', 'cancelled')->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => true]);
        $cancelledAllocations = DB::table('haul_allocations')->where('status', 'cancelled')->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => true]);

        $groups = DB::table('inventory_movements')
            ->select(['storage_location_id', 'fuel_type_id'])
            ->distinct()
            ->get();

        foreach ($groups as $group) {
            $quantity = 0.0;
            $inventoryValue = 0.0;
            $costKnown = true;

            $movements = DB::table('inventory_movements')
                ->where('storage_location_id', $group->storage_location_id)
                ->where('fuel_type_id', $group->fuel_type_id)
                ->orderBy('movement_date')
                ->orderBy('id')
                ->get(['id', 'direction', 'quantity_liters', 'unit_cost', 'reference_type', 'reference_id']);

            foreach ($movements as $movement) {
                if (($movement->reference_type === 'stock_out' && isset($cancelledStockOuts[(int) $movement->reference_id]))
                    || ($movement->reference_type === 'haul_allocation' && isset($cancelledAllocations[(int) $movement->reference_id]))) {
                    continue;
                }

                $movementQuantity = round((float) $movement->quantity_liters, 2);

                if ($movement->direction === 'in') {
                    $quantity = round($quantity + $movementQuantity, 2);
                    if ($movement->unit_cost === null) {
                        $costKnown = false;
                    } else {
                        $inventoryValue = round($inventoryValue + ($movementQuantity * (float) $movement->unit_cost), 2);
                    }

                    continue;
                }

                $unitCost = $movement->unit_cost === null
                    ? ($costKnown && $quantity > 0 ? round($inventoryValue / $quantity, 2) : null)
                    : round((float) $movement->unit_cost, 2);

                if ($unitCost === null) {
                    $costKnown = false;
                } else {
                    $inventoryValue = round(max(0, $inventoryValue - ($movementQuantity * $unitCost)), 2);
                    if ($movement->unit_cost === null) {
                        DB::table('inventory_movements')->where('id', $movement->id)->update(['unit_cost' => $unitCost]);
                    }
                    if ($movement->reference_type === 'stock_out') {
                        DB::table('stock_outs')->where('id', $movement->reference_id)->whereNull('unit_cost')->update(['unit_cost' => $unitCost]);
                    }
                }

                $quantity = round(max(0, $quantity - $movementQuantity), 2);
            }
        }

        DB::table('stock_outs')
            ->join('inventory_movements', 'inventory_movements.id', '=', 'stock_outs.inventory_movement_id')
            ->whereNull('stock_outs.unit_cost')
            ->whereNotNull('inventory_movements.unit_cost')
            ->get(['stock_outs.id', 'inventory_movements.unit_cost'])
            ->each(fn (object $row) => DB::table('stock_outs')->where('id', $row->id)->update([
                'unit_cost' => $row->unit_cost,
            ]));
    }
};
