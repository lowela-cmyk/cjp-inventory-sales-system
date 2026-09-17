<?php

use App\Services\GarageTankService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $approvedFuels = config('fuels.approved', [
            'F1' => ['code' => 'F1', 'name' => 'F1'],
            'UNL' => ['code' => 'UNL', 'name' => 'UNLEADED'],
            'DSL' => ['code' => 'DSL', 'name' => 'DIESEL'],
            'PREM' => ['code' => 'PREM', 'name' => 'PREMIUM'],
        ]);

        $approvedCodes = array_column($approvedFuels, 'code');

        DB::table('fuel_types')
            ->whereNotIn('code', $approvedCodes)
            ->update([
                'status' => 'inactive',
                'updated_at' => now(),
            ]);

        foreach ($approvedFuels as $fuel) {
            DB::table('fuel_types')->updateOrInsert(
                ['code' => $fuel['code']],
                [
                    'name' => $fuel['name'],
                    'status' => 'active',
                    'updated_at' => now(),
                ]
            );
        }

        app(GarageTankService::class)->ensureForActiveFuelTypes();
    }

    public function down(): void
    {
        // Historical and operational rows rely on fuel_types; down is a no-op to prevent data destruction.
    }
};
