<?php

namespace Tests\Concerns;

use App\Services\StockLotService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stock lots for tests (Part 8 follow-up: every new sale line must name a Stock Source).
 *
 * - stockLot() records a purchase whose lift was received into a garage tank, i.e. one stock-in lot,
 *   and returns the lot (inventory movement) id.
 * - withStockLots() gives each new sale line that names a valid fuel a lot: the oldest existing lot of
 *   that fuel with enough available stock, or a new one. Lines a test makes invalid on purpose
 *   (unknown fuel, bad quantity) are left alone so their validation errors still show.
 *
 * TestCase applies withStockLots() to every POST to Record Sales; tests that check the Stock Source
 * rule itself call withoutStockLots().
 */
trait CreatesStockLots
{
    protected bool $attachStockLots = true;

    protected function withoutStockLots(): static
    {
        $this->attachStockLots = false;

        return $this;
    }

    protected function stockLot(int $fuelTypeId, float $liters = 100000, float $unitCost = 50.0): int
    {
        $now = now();
        $suffix = Str::upper(Str::random(6));
        $tankId = DB::table('storage_locations')->where('type', 'garage')->where('status', 'active')->where('fuel_type_id', $fuelTypeId)->orderBy('tank_number')->value('id')
            ?? DB::table('storage_locations')->insertGetId([
                'fuel_type_id' => $fuelTypeId, 'tank_number' => 1, 'location_code' => 'TST-TANK-'.$suffix, 'name' => 'Test Tank '.$suffix,
                'type' => 'garage', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
            ]);
        $depotId = DB::table('depots')->insertGetId(['depot_code' => 'TST-DEP-'.$suffix, 'name' => 'Test Depot '.$suffix, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $truckId = DB::table('trucks')->insertGetId(['truck_code' => 'TST-TRK-'.$suffix, 'capacity_liters' => max($liters, 1), 'truck_type' => 'hauling', 'status' => 'available', 'created_at' => $now, 'updated_at' => $now]);
        $driverId = DB::table('users')->insertGetId([
            'name' => 'Test Driver '.$suffix, 'email' => 'test-driver-'.Str::lower($suffix).'@example.test', 'role' => 'driver',
            'status' => 'active', 'approval_status' => 'approved', 'password' => bcrypt('password'), 'created_at' => $now, 'updated_at' => $now,
        ]);

        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => 'PUR-TST-'.$suffix, 'depot_id' => $depotId, 'purchase_date' => $now->toDateString(),
            'payment_status' => 'paid', 'status' => 'hauled', 'workflow_status' => 'completed', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $itemId = DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchaseId, 'fuel_type_id' => $fuelTypeId, 'quantity_ordered_liters' => $liters, 'unit_cost' => $unitCost,
            'line_total' => round($liters * $unitCost, 2), 'quantity_hauled_liters' => $liters, 'status' => 'lifted', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $haulId = DB::table('hauls')->insertGetId([
            'haul_code' => 'LFT-TST-'.$suffix, 'purchase_id' => $purchaseId, 'purchase_item_id' => $itemId, 'depot_id' => $depotId, 'fuel_type_id' => $fuelTypeId,
            'truck_id' => $truckId, 'driver_user_id' => $driverId, 'scheduled_at' => $now, 'hauled_at' => $now, 'quantity_liters' => $liters,
            'status' => 'completed', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $allocationId = DB::table('haul_allocations')->insertGetId([
            'haul_id' => $haulId, 'fuel_type_id' => $fuelTypeId, 'destination_type' => 'garage', 'storage_location_id' => $tankId,
            'quantity_liters' => $liters, 'allocated_at' => $now, 'status' => 'received', 'receive_on_completion' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return (int) DB::table('inventory_movements')->insertGetId([
            'movement_code' => 'MOV-TST-'.$suffix, 'storage_location_id' => $tankId, 'fuel_type_id' => $fuelTypeId, 'movement_type' => 'stock_in',
            'direction' => 'in', 'quantity_liters' => $liters, 'unit_cost' => $unitCost, 'reference_type' => 'haul_allocation',
            'reference_id' => $allocationId, 'movement_date' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload  a Record Sales payload (items[] or the single-line fuel_type_id/quantity_liters/unit_price form)
     * @return array<string, mixed>
     */
    protected function withStockLots(array $payload): array
    {
        // The single-line form becomes items[0] only when it is a sellable line; invalid ones keep their shape.
        if (! isset($payload['items']) && isset($payload['fuel_type_id']) && $this->sellableFuel($payload['fuel_type_id'])
            && is_numeric($payload['quantity_liters'] ?? null) && (float) $payload['quantity_liters'] > 0) {
            $payload['items'] = [[
                'fuel_type_id' => $payload['fuel_type_id'],
                'quantity_liters' => $payload['quantity_liters'] ?? null,
                'unit_price' => $payload['unit_price'] ?? null,
            ]];
            unset($payload['fuel_type_id'], $payload['quantity_liters'], $payload['unit_price']);
        }

        if (! is_array($payload['items'] ?? null)) {
            return $payload;
        }

        $promised = [];
        foreach ($payload['items'] as $index => $item) {
            if (! is_array($item) || ! empty($item['stock_lot_id']) || ! $this->sellableFuel($item['fuel_type_id'] ?? null) || ! is_numeric($item['quantity_liters'] ?? null) || (float) $item['quantity_liters'] <= 0) {
                continue;
            }

            $fuelTypeId = (int) $item['fuel_type_id'];
            $liters = (float) $item['quantity_liters'];
            // The test's own stock comes first (oldest first); lots this helper made are only a fallback.
            $lot = app(StockLotService::class)->lots()
                ->where('fuel_type_id', $fuelTypeId)
                ->sortBy([
                    fn (object $a, object $b): int => str_starts_with((string) $a->purchase_code, 'PUR-TST-') <=> str_starts_with((string) $b->purchase_code, 'PUR-TST-'),
                    ['movement_date', 'asc'],
                    ['id', 'asc'],
                ])
                ->first(fn (object $lot): bool => $lot->available - ($promised[(int) $lot->id] ?? 0) >= $liters);
            $lotId = $lot ? (int) $lot->id : $this->stockLot($fuelTypeId, max($liters, 100000));
            $promised[$lotId] = ($promised[$lotId] ?? 0) + $liters;
            $payload['items'][$index]['stock_lot_id'] = $lotId;
        }

        return $payload;
    }

    /**
     * An Edit payload as the Edit form sends it: each existing line resubmits its own lot (a lot-based
     * line shows a Stock Source select). New lines (beyond the sale's existing ones) are left alone.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function withExistingStockLots(int $saleId, array $payload): array
    {
        $existing = DB::table('sale_items')->where('sale_id', $saleId)->orderBy('id')->pluck('stock_in_movement_id')->values();
        if (! isset($payload['items']) && isset($payload['fuel_type_id']) && ($existing[0] ?? null)) {
            $payload['items'] = [['fuel_type_id' => $payload['fuel_type_id'], 'quantity_liters' => $payload['quantity_liters'] ?? null, 'unit_price' => $payload['unit_price'] ?? null]];
            unset($payload['fuel_type_id'], $payload['quantity_liters'], $payload['unit_price']);
        }
        foreach ($payload['items'] ?? [] as $index => $item) {
            if (is_array($item) && empty($item['stock_lot_id']) && ($existing[$index] ?? null)) {
                $payload['items'][$index]['stock_lot_id'] = (int) $existing[$index];
            }
        }

        return $payload;
    }

    private function sellableFuel(mixed $fuelTypeId): bool
    {
        // Same rule as ApprovedFuelType: an active fuel with an approved code.
        return is_numeric($fuelTypeId) && DB::table('fuel_types')->where('id', (int) $fuelTypeId)->where('status', 'active')
            ->whereIn('code', array_keys(config('fuels.approved', ['F1' => true, 'UNL' => true, 'DSL' => true, 'PREM' => true])))->exists();
    }
}
