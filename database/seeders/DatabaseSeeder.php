<?php

namespace Database\Seeders;

use App\Services\OperationalDataRepairService;
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
            ['code' => 'F1', 'name' => 'F1'],
            ['code' => 'UNL', 'name' => 'UNLEADED'],
            ['code' => 'PREM', 'name' => 'PREMIUM'],
            ['code' => 'DSL', 'name' => 'DIESEL'],
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

        DB::table('fuel_types')
            ->whereNotIn('code', ['F1', 'UNL', 'PREM', 'DSL'])
            ->update([
                'status' => 'inactive',
                'updated_at' => now(),
            ]);

        app(OperationalDataRepairService::class)->seedMasterData();
    }
}
