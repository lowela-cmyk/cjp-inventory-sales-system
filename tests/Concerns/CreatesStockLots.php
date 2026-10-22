<?php

namespace Tests\Concerns;

use App\Services\PurchaseStockService;
use App\Support\F1Split;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stock for tests (Part 2 Button Revisions, October 22, 2026: stock is kept per PURCHASE and every order line names
 * the purchase it is deducted from).
 *
 * - stockLot() records a purchase with stock (no lift needed) and returns its purchase line id. Its old name is
 *   kept so tests read the same; a "stock_lot_id" a test sends is that purchase line.
 * - withStockLots() gives each new order line that names a valid fuel a purchase: the oldest purchase of that fuel
 *   with enough available liters (the test's own first), or a new one. An F1 portion gets an F1 purchase, and an F1
 *   Price equal to the line price when the test sends none (so totals stay quantity x price). Lines a test makes
 *   invalid on purpose (unknown fuel, bad quantity) are left alone so their validation errors still show.
 *
 * TestCase applies withStockLots() to every POST to Record Sales; tests that check the purchase rule itself call
 * withoutStockLots().
 */
trait CreatesStockLots
{
    protected bool $attachStockLots = true;

    protected function withoutStockLots(): static
    {
        $this->attachStockLots = false;

        return $this;
    }

    /** A purchase of the fuel holding $liters (returns the purchase line id). */
    protected function stockLot(int $fuelTypeId, float $liters = 100000, float $unitCost = 50.0): int
    {
        $now = now();
        $suffix = Str::upper(Str::random(6));
        $depotId = DB::table('depots')->insertGetId(['depot_code' => 'TST-DEP-'.$suffix, 'name' => 'Test Depot '.$suffix, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => 'PUR-TST-'.$suffix, 'depot_id' => $depotId, 'purchase_date' => $now->toDateString(),
            'payment_status' => 'paid', 'status' => 'ordered', 'workflow_status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
        ]);

        return (int) DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchaseId, 'fuel_type_id' => $fuelTypeId, 'quantity_ordered_liters' => $liters, 'unit_cost' => $unitCost,
            'line_total' => round($liters * $unitCost, 2), 'quantity_hauled_liters' => 0, 'status' => 'unlifted', 'created_at' => $now, 'updated_at' => $now,
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
                'f1_percentage' => $payload['f1_percentage'] ?? null,
            ]];
            unset($payload['fuel_type_id'], $payload['quantity_liters'], $payload['unit_price'], $payload['f1_percentage']);
        }

        if (! is_array($payload['items'] ?? null)) {
            return $payload;
        }

        $promised = [];
        $f1FuelId = F1Split::f1FuelId();
        foreach ($payload['items'] as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            if (! empty($item['stock_lot_id'])) {
                $payload['items'][$index]['purchase_item_id'] = (int) $item['stock_lot_id'];
                unset($payload['items'][$index]['stock_lot_id']);
                $item = $payload['items'][$index];
            }
            if (! is_numeric($item['quantity_liters'] ?? null) || (float) $item['quantity_liters'] <= 0) {
                continue;
            }

            $quantity = (float) $item['quantity_liters'];
            $fuelTypeId = ! empty($item['purchase_item_id'])
                ? (int) DB::table('purchase_items')->where('id', $item['purchase_item_id'])->value('fuel_type_id')
                : (int) ($item['fuel_type_id'] ?? 0);
            $percentage = in_array($fuelTypeId, F1Split::eligibleFuelIds(), true) ? (float) ($item['f1_percentage'] ?? 0) : 0.0;
            [$main, $f1] = F1Split::split($quantity, $percentage);

            if (empty($item['purchase_item_id']) && $this->sellableFuel($fuelTypeId)) {
                $payload['items'][$index]['purchase_item_id'] = $this->purchaseFor($fuelTypeId, $main, $promised);
            }

            if ($f1 > 0 && $f1FuelId !== null) {
                if (empty($item['f1_purchase_item_id'])) {
                    $payload['items'][$index]['f1_purchase_item_id'] = $this->purchaseFor($f1FuelId, $f1, $promised);
                }
                if (! isset($item['f1_unit_price'])) {
                    $payload['items'][$index]['f1_unit_price'] = $item['unit_price'] ?? null;
                }
            }
        }

        return $payload;
    }

    /**
     * An Edit payload as the Edit form sends it: each existing line resubmits its own purchases and F1 Price.
     * New lines (beyond the sale's existing ones) are left alone.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function withExistingStockLots(int $saleId, array $payload): array
    {
        $existing = DB::table('sale_items')->where('sale_id', $saleId)->orderBy('id')->get(['purchase_item_id', 'f1_purchase_item_id', 'f1_unit_price', 'fuel_type_id'])->values();
        if (! isset($payload['items']) && isset($payload['fuel_type_id']) && isset($existing[0])) {
            $payload['items'] = [['fuel_type_id' => $payload['fuel_type_id'], 'quantity_liters' => $payload['quantity_liters'] ?? null, 'unit_price' => $payload['unit_price'] ?? null]];
            unset($payload['fuel_type_id'], $payload['quantity_liters'], $payload['unit_price']);
        }
        foreach ($payload['items'] ?? [] as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            if (! empty($item['stock_lot_id'])) {
                $payload['items'][$index]['purchase_item_id'] = (int) $item['stock_lot_id'];
                unset($payload['items'][$index]['stock_lot_id']);

                continue;
            }
            $line = $existing[$index] ?? null;
            if (! $line) {
                continue;
            }
            // A different fuel on an existing line is a new purchase choice the test leaves to the helper.
            if (empty($item['purchase_item_id']) && $line->purchase_item_id && (empty($item['fuel_type_id']) || (int) $item['fuel_type_id'] === (int) $line->fuel_type_id)) {
                $payload['items'][$index]['purchase_item_id'] = (int) $line->purchase_item_id;
            } elseif (empty($item['purchase_item_id']) && ! empty($item['fuel_type_id']) && (int) $item['fuel_type_id'] !== (int) $line->fuel_type_id && $this->sellableFuel($item['fuel_type_id'])) {
                $promised = [];
                $payload['items'][$index]['purchase_item_id'] = $this->purchaseFor((int) $item['fuel_type_id'], (float) ($item['quantity_liters'] ?? 0), $promised);
            }
            if (empty($item['f1_purchase_item_id']) && $line->f1_purchase_item_id) {
                $payload['items'][$index]['f1_purchase_item_id'] = (int) $line->f1_purchase_item_id;
            }
            if (! isset($item['f1_unit_price']) && (float) $line->f1_unit_price > 0) {
                $payload['items'][$index]['f1_unit_price'] = (float) $line->f1_unit_price;
            } elseif (! isset($item['f1_unit_price']) && (float) ($item['f1_percentage'] ?? 0) > 0) {
                $payload['items'][$index]['f1_unit_price'] = $item['unit_price'] ?? null;
            }
            if ((float) ($item['f1_percentage'] ?? 0) > 0 && empty($payload['items'][$index]['f1_purchase_item_id']) && F1Split::f1FuelId() !== null) {
                $promised = [];
                $payload['items'][$index]['f1_purchase_item_id'] = $this->purchaseFor((int) F1Split::f1FuelId(), (float) ($item['quantity_liters'] ?? 0), $promised);
            }
        }

        return $payload;
    }

    /**
     * The oldest purchase of the fuel with $liters available (the test's own purchases before helper ones), or a
     * new helper purchase.
     *
     * @param  array<int, float>  $promised
     */
    private function purchaseFor(int $fuelTypeId, float $liters, array &$promised): int
    {
        $line = app(PurchaseStockService::class)->lines()
            ->where('fuel_type_id', $fuelTypeId)
            ->sortBy([
                fn (object $a, object $b): int => str_starts_with((string) $a->purchase_code, 'PUR-TST-') <=> str_starts_with((string) $b->purchase_code, 'PUR-TST-'),
                ['purchase_date', 'asc'],
                ['id', 'asc'],
            ])
            ->first(fn (object $line): bool => $line->available - ($promised[(int) $line->id] ?? 0) >= $liters);
        $id = $line ? (int) $line->id : $this->stockLot($fuelTypeId, max($liters, 100000));
        $promised[$id] = ($promised[$id] ?? 0) + $liters;

        return $id;
    }

    private function sellableFuel(mixed $fuelTypeId): bool
    {
        // Same rule as ApprovedFuelType: an active fuel with an approved code.
        return is_numeric($fuelTypeId) && DB::table('fuel_types')->where('id', (int) $fuelTypeId)->where('status', 'active')
            ->whereIn('code', array_keys(config('fuels.approved', ['F1' => true, 'UNL' => true, 'DSL' => true, 'PREM' => true])))->exists();
    }
}
