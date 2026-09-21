<?php

use App\Services\GarageTankService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (config('fuels.approved') as $fuel) {
            DB::table('fuel_types')
                ->where('code', $fuel['code'])
                ->update([
                    'name' => $fuel['name'],
                    'status' => 'active',
                    'updated_at' => now(),
                ]);
        }

        app(GarageTankService::class)->ensureForActiveFuelTypes();
    }

    public function down(): void
    {
        // Names are operational master data; keep the official catalog on rollback.
    }
};
