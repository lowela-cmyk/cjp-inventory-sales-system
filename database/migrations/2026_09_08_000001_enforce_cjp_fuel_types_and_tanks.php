<?php

use App\Services\GarageTankService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $fuelTypes = [
            ['code' => 'F1', 'name' => 'F1'],
            ['code' => 'UNL', 'name' => 'Unleaded'],
            ['code' => 'PREM', 'name' => 'Premium'],
            ['code' => 'DSL', 'name' => 'Diesel'],
        ];

        DB::table('fuel_types')
            ->whereNotIn('code', array_column($fuelTypes, 'code'))
            ->update([
                'status' => 'inactive',
                'updated_at' => now(),
            ]);

        foreach ($fuelTypes as $fuelType) {
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

        app(GarageTankService::class)->ensureForActiveFuelTypes();
    }

    public function down(): void
    {
        DB::table('fuel_types')
            ->whereIn('code', ['F1', 'UNL', 'PREM', 'DSL'])
            ->update([
                'status' => 'inactive',
                'updated_at' => now(),
            ]);
    }
};
