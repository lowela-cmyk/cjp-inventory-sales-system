<?php

use App\Services\GarageTankService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const FUEL_TYPES = [
        ['code' => 'ADO', 'name' => 'Automotive Diesel Oil'],
        ['code' => 'RGP', 'name' => 'Regular Gasoline'],
        ['code' => 'P95', 'name' => 'Premium Gasoline 95'],
        ['code' => 'KRS', 'name' => 'Kerosene Fuel'],
    ];

    public function up(): void
    {
        foreach (self::FUEL_TYPES as $fuelType) {
            DB::table('fuel_types')->updateOrInsert(
                ['code' => $fuelType['code']],
                [
                    'name' => $fuelType['name'],
                    'description' => null,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }

        DB::table('depots')->updateOrInsert(
            ['depot_code' => 'DEP-CJP-MAIN'],
            [
                'name' => 'CJP Main Depot',
                'address' => null,
                'contact_person' => null,
                'phone' => null,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        app(GarageTankService::class)->ensureForActiveFuelTypes();
    }

    public function down(): void
    {
        $fuelCodes = array_column(self::FUEL_TYPES, 'code');
        $fuelIds = DB::table('fuel_types')->whereIn('code', $fuelCodes)->pluck('id')->all();

        DB::table('storage_locations')
            ->where('type', 'garage')
            ->whereIn('fuel_type_id', $fuelIds)
            ->whereIn('tank_number', [1, 2])
            ->delete();

        DB::table('depots')->where('depot_code', 'DEP-CJP-MAIN')->delete();
        DB::table('fuel_types')->whereIn('code', $fuelCodes)->delete();
    }
};
