<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class GarageTankService
{
    /** Part 9 (October 20): every garage tank holds at most 24,000 L (24KL). */
    public const TANK_CAPACITY_LITERS = 24000.0;

    /** A tank's capacity; a tank without one recorded is a standard 24KL tank. */
    public static function capacity(mixed $capacityLiters): float
    {
        return $capacityLiters === null ? self::TANK_CAPACITY_LITERS : round((float) $capacityLiters, 2);
    }

    /**
     * Current stock of garage tanks (stock-ins less releases; a cancelled release no longer counts).
     *
     * @param  array<int, int>|null  $tankIds  null = every tank
     * @return array<int, float> storage_location_id => liters
     */
    public function balances(?array $tankIds = null): array
    {
        return DB::table('inventory_movements')
            ->when($tankIds !== null, fn ($query) => $query->whereIn('storage_location_id', $tankIds ?: [0]))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('stock_outs')
                ->whereColumn('stock_outs.id', 'inventory_movements.reference_id')
                ->where('inventory_movements.reference_type', 'stock_out')
                ->where('stock_outs.status', 'cancelled'))
            ->selectRaw("storage_location_id, COALESCE(SUM(CASE WHEN direction = 'in' THEN quantity_liters ELSE -quantity_liters END), 0) as balance")
            ->groupBy('storage_location_id')
            ->pluck('balance', 'storage_location_id')
            ->map(fn (mixed $balance): float => round((float) $balance, 2))
            ->all();
    }

    public function ensureForActiveFuelTypes(): void
    {
        $fuelTypes = DB::table('fuel_types')
            ->where('status', 'active')
            ->orderBy('id')
            ->get(['id', 'name']);
        $activeFuelTypeIds = $fuelTypes->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        DB::table('storage_locations')
            ->where('type', 'garage')
            ->where(function ($query) use ($activeFuelTypeIds): void {
                $query->whereNull('fuel_type_id')
                    ->orWhereNotIn('fuel_type_id', $activeFuelTypeIds ?: [0])
                    ->orWhereNotIn('tank_number', [1, 2]);
            })
            ->update([
                'status' => 'inactive',
                'updated_at' => now(),
            ]);

        foreach ($fuelTypes as $fuelType) {
            foreach ([1, 2] as $tankNumber) {
                $existing = DB::table('storage_locations')
                    ->where('type', 'garage')
                    ->where('fuel_type_id', $fuelType->id)
                    ->where('tank_number', $tankNumber)
                    ->orderBy('id')
                    ->get(['id', 'status']);

                if ($existing->isNotEmpty()) {
                    $primary = $existing->first();

                    DB::table('storage_locations')
                        ->where('id', $primary->id)
                        ->update([
                            'name' => $fuelType->name.' Tank '.$tankNumber,
                            'status' => 'active',
                            'updated_at' => now(),
                        ]);

                    if ($existing->count() > 1) {
                        DB::table('storage_locations')
                            ->whereIn('id', $existing->skip(1)->pluck('id'))
                            ->update([
                                'status' => 'inactive',
                                'updated_at' => now(),
                            ]);
                    }

                    continue;
                }

                // Older migrations run this before storage_locations has a capacity column.
                $capacity = Schema::hasColumn('storage_locations', 'capacity_liters') ? ['capacity_liters' => self::TANK_CAPACITY_LITERS] : [];
                DB::table('storage_locations')->insert([
                    'location_code' => $this->nextLocationCode((int) $fuelType->id, $tankNumber),
                    'fuel_type_id' => $fuelType->id,
                    'tank_number' => $tankNumber,
                    'name' => $fuelType->name.' Tank '.$tankNumber,
                    'type' => 'garage',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ] + $capacity);
            }
        }
    }

    private function nextLocationCode(int $fuelTypeId, int $tankNumber): string
    {
        $base = 'GAR-F'.$fuelTypeId.'-T'.$tankNumber;

        if (! DB::table('storage_locations')->where('location_code', $base)->exists()) {
            return $base;
        }

        do {
            $code = $base.'-'.Str::upper(Str::random(4));
        } while (DB::table('storage_locations')->where('location_code', $code)->exists());

        return $code;
    }
}
