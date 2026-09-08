<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GarageTankService
{
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
                    ->first(['id', 'status']);

                if ($existing) {
                    if ($existing->status !== 'active') {
                        DB::table('storage_locations')
                            ->where('id', $existing->id)
                            ->update([
                                'name' => $fuelType->name.' Garage Tank '.$tankNumber,
                                'status' => 'active',
                                'updated_at' => now(),
                            ]);
                    }

                    continue;
                }

                DB::table('storage_locations')->insert([
                    'location_code' => $this->nextLocationCode((int) $fuelType->id, $tankNumber),
                    'fuel_type_id' => $fuelType->id,
                    'tank_number' => $tankNumber,
                    'name' => $fuelType->name.' Garage Tank '.$tankNumber,
                    'type' => 'garage',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
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
