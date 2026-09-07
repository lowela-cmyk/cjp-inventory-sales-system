<?php

namespace Database\Seeders;

use App\Services\GarageTankService;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        foreach ([
            ['code' => 'ADO', 'name' => 'Automotive Diesel Oil'],
            ['code' => 'RGP', 'name' => 'Regular Gasoline'],
            ['code' => 'P95', 'name' => 'Premium Gasoline 95'],
            ['code' => 'KRS', 'name' => 'Kerosene Fuel'],
        ] as $fuelType) {
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
}
