<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * October 31, 2026: fuel received into the garage tanks before Part 13 (lifts with a "garage" allocation, tracked in the
 * retired inventory_movements ledger) has no Part 13 garage lot, so it was in no stock bucket and could not be sold.
 *
 * For each received legacy garage allocation:
 * - its liters still in the garage = received - the old garage releases that took fuel from it (oldest allocation of the
 *   tank first; only releases dated at or after the allocation, and only whole releases that fit, so fuel the tank held
 *   before the lift - e.g. a "beginning" balance - is never charged to the purchase);
 * - those liters become Part 13 garage lots of its purchase, filling the fuel's Tank 1 then Tank 2 up to each tank's
 *   capacity (24,000 L). Liters that don't fit are not converted (no tank is ever overfilled): they are listed in
 *   $skipped, logged as a warning and printed by `php artisan migrate`, and the migration carries on.
 * - the old releases charged to it get its purchase_item_id, so the purchase's stock-in Remaining matches.
 *
 * Lots made here are coded GRG-LEG-<allocation>-<n>; down() removes them and unlinks the releases. Re-running is a no-op.
 */
return new class extends Migration
{
    /** @var array<int, array{purchase_code: string, fuel: string, liters: float}> liters left out because the tanks were full */
    public array $skipped = [];

    public function up(): void
    {
        $this->skipped = [];

        DB::transaction(function (): void {
            $allocations = DB::table('haul_allocations')
                ->join('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
                ->join('purchase_items', 'purchase_items.id', '=', 'hauls.purchase_item_id')
                ->where('haul_allocations.destination_type', 'garage')
                ->where('haul_allocations.status', 'received')
                ->whereNotNull('haul_allocations.storage_location_id')
                ->whereNull('hauls.deleted_at')
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('garage_stocks')->whereColumn('garage_stocks.haul_allocation_id', 'haul_allocations.id'))
                ->orderBy('haul_allocations.allocated_at')
                ->orderBy('haul_allocations.id')
                ->get([
                    'haul_allocations.id', 'haul_allocations.quantity_liters', 'haul_allocations.storage_location_id', 'haul_allocations.allocated_at',
                    'hauls.id as haul_id', 'hauls.truck_id', 'hauls.hauled_at', 'hauls.purchase_item_id', 'hauls.fuel_type_id', 'purchase_items.unit_cost',
                ]);

            // Old garage releases not charged to any purchase yet, per legacy tank, oldest first.
            $releases = DB::table('stock_outs')
                ->where('source_type', 'garage')
                ->where('status', 'released')
                ->whereNull('garage_stock_id')
                ->whereNull('purchase_item_id')
                ->whereIn('storage_location_id', $allocations->pluck('storage_location_id')->unique()->all() ?: [0])
                ->orderBy('stock_out_at')
                ->orderBy('id')
                ->get(['id', 'storage_location_id', 'quantity_liters', 'stock_out_at'])
                ->groupBy('storage_location_id');
            $used = [];
            $now = now();

            foreach ($allocations as $allocation) {
                $left = round((float) $allocation->quantity_liters, 2);
                $receivedAt = (string) ($allocation->allocated_at ?? $allocation->hauled_at);
                foreach ($releases->get($allocation->storage_location_id, collect()) as $release) {
                    $liters = round((float) $release->quantity_liters, 2);
                    if (isset($used[$release->id]) || (string) $release->stock_out_at < $receivedAt || $liters > $left) {
                        continue;
                    }
                    DB::table('stock_outs')->where('id', $release->id)->update(['purchase_item_id' => $allocation->purchase_item_id, 'updated_at' => $now]);
                    $used[$release->id] = true;
                    $left = round($left - $liters, 2);
                }
                if ($left <= 0) {
                    continue;
                }

                // Part 13 tanks of the fuel, Tank 1 first, with their free space.
                $tanks = DB::table('storage_locations')->where('type', 'garage')->where('status', 'active')->where('fuel_type_id', $allocation->fuel_type_id)->orderBy('tank_number')->orderBy('id')->get(['id', 'capacity_liters']);
                $number = 0;
                foreach ($tanks as $tank) {
                    $free = round((float) $tank->capacity_liters - $this->contents((int) $tank->id), 2);
                    $liters = min($left, max(0, $free));
                    if ($liters <= 0) {
                        continue;
                    }
                    DB::table('garage_stocks')->insert([
                        'lot_code' => 'GRG-LEG-'.$allocation->id.'-'.(++$number),
                        'storage_location_id' => $tank->id,
                        'purchase_item_id' => $allocation->purchase_item_id,
                        'fuel_type_id' => $allocation->fuel_type_id,
                        'haul_id' => $allocation->haul_id,
                        'haul_allocation_id' => $allocation->id,
                        'truck_id' => $allocation->truck_id,
                        'liters' => $liters,
                        'unit_cost' => $allocation->unit_cost,
                        'unloaded_at' => $receivedAt,
                        'unloaded_by' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $left = round($left - $liters, 2);
                    if ($left <= 0) {
                        break;
                    }
                }
                if ($left > 0) {
                    $this->skipped[] = [
                        'purchase_code' => (string) DB::table('purchases')->join('purchase_items', 'purchase_items.purchase_id', '=', 'purchases.id')->where('purchase_items.id', $allocation->purchase_item_id)->value('purchases.purchase_code'),
                        'fuel' => (string) DB::table('fuel_types')->where('id', $allocation->fuel_type_id)->value('name'),
                        'liters' => $left,
                    ];
                }
            }
        });

        foreach ($this->skipped as $skip) {
            $message = 'Legacy garage fuel not converted (garage tanks full): '.$skip['purchase_code'].' '.$skip['fuel'].' '.number_format($skip['liters'], 2).' L.';
            Log::warning($message, $skip);
            if (app()->runningInConsole() && ! app()->runningUnitTests()) {
                fwrite(STDOUT, '  '.$message.PHP_EOL);
            }
        }
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $lots = DB::table('garage_stocks')->where('lot_code', 'like', 'GRG-LEG-%');
            $allocationIds = $lots->pluck('haul_allocation_id')->unique()->all();
            $purchaseItemIds = DB::table('garage_stocks')->where('lot_code', 'like', 'GRG-LEG-%')->pluck('purchase_item_id')->unique()->all();
            // Releases this migration charged: old garage releases (no lot, no lift row) of those purchases.
            DB::table('stock_outs')
                ->where('source_type', 'garage')
                ->whereNull('garage_stock_id')
                ->whereNull('haul_allocation_id')
                ->whereIn('purchase_item_id', $purchaseItemIds ?: [0])
                ->whereIn('storage_location_id', DB::table('haul_allocations')->whereIn('id', $allocationIds ?: [0])->pluck('storage_location_id')->all() ?: [0])
                ->update(['purchase_item_id' => null]);
            DB::table('garage_stocks')->where('lot_code', 'like', 'GRG-LEG-%')->delete();
        });
    }

    /** Liters in a Part 13 tank: its lots less what was released from them. */
    private function contents(int $tankId): float
    {
        $lots = (float) DB::table('garage_stocks')->where('storage_location_id', $tankId)->sum('liters');
        $released = (float) DB::table('stock_outs')
            ->join('garage_stocks', 'garage_stocks.id', '=', 'stock_outs.garage_stock_id')
            ->where('garage_stocks.storage_location_id', $tankId)
            ->where('stock_outs.status', 'released')
            ->sum('stock_outs.quantity_liters');

        return round($lots - $released, 2);
    }
};
