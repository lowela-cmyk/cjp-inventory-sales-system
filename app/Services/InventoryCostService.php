<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class InventoryCostService
{
    /**
     * Return the perpetual weighted-average cost of the usable fuel in one tank.
     * Callers perform releases inside a database transaction; locking the ledger
     * rows prevents two releases from taking the same cost snapshot concurrently.
     */
    public function currentUnitCostForUpdate(int $storageLocationId, int $fuelTypeId): ?float
    {
        $movements = DB::table('inventory_movements')
            ->where('storage_location_id', $storageLocationId)
            ->where('fuel_type_id', $fuelTypeId)
            ->whereNotExists($this->cancelledStockOutExists())
            ->whereNotExists($this->cancelledHaulAllocationExists())
            ->orderBy('movement_date')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['direction', 'quantity_liters', 'unit_cost']);

        $quantity = 0.0;
        $inventoryValue = 0.0;
        $hasUnknownCost = false;

        foreach ($movements as $movement) {
            $movementQuantity = round((float) $movement->quantity_liters, 2);

            if ($movement->direction === 'in') {
                $quantity = round($quantity + $movementQuantity, 2);
                if ($movement->unit_cost === null) {
                    $hasUnknownCost = true;

                    continue;
                }

                $inventoryValue = round($inventoryValue + ($movementQuantity * (float) $movement->unit_cost), 2);

                continue;
            }

            $unitCost = $movement->unit_cost === null
                ? ($quantity > 0 && ! $hasUnknownCost ? $inventoryValue / $quantity : null)
                : (float) $movement->unit_cost;

            if ($unitCost === null) {
                $hasUnknownCost = true;
            } else {
                $inventoryValue = round(max(0, $inventoryValue - ($movementQuantity * $unitCost)), 2);
            }

            $quantity = round(max(0, $quantity - $movementQuantity), 2);
        }

        if ($quantity <= 0 || $hasUnknownCost) {
            return null;
        }

        return round($inventoryValue / $quantity, 2);
    }

    private function cancelledStockOutExists(): \Closure
    {
        return function (Builder $query): void {
            $query->selectRaw('1')
                ->from('stock_outs')
                ->whereColumn('stock_outs.id', 'inventory_movements.reference_id')
                ->where('inventory_movements.reference_type', 'stock_out')
                ->where('stock_outs.status', 'cancelled');
        };
    }

    private function cancelledHaulAllocationExists(): \Closure
    {
        return function (Builder $query): void {
            $query->selectRaw('1')
                ->from('haul_allocations')
                ->whereColumn('haul_allocations.id', 'inventory_movements.reference_id')
                ->where('inventory_movements.reference_type', 'haul_allocation')
                ->where('haul_allocations.status', 'cancelled');
        };
    }
}
