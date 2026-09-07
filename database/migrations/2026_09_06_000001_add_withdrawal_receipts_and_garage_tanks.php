<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hauls', function (Blueprint $table) {
            $table->string('withdrawal_receipt_path')->nullable()->after('source_location');
            $table->text('withdrawal_receipt_notes')->nullable()->after('withdrawal_receipt_path');
            $table->timestamp('withdrawal_receipt_uploaded_at')->nullable()->after('withdrawal_receipt_notes');
        });

        Schema::table('storage_locations', function (Blueprint $table) {
            $table->foreignId('fuel_type_id')->nullable()->after('id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('tank_number')->nullable()->after('fuel_type_id');
            $table->index(['fuel_type_id', 'tank_number']);
        });

        $fuelTypes = DB::table('fuel_types')
            ->where('status', 'active')
            ->orderBy('id')
            ->get(['id', 'name']);

        foreach ($fuelTypes as $fuelType) {
            $existingTankNumbers = DB::table('storage_locations')
                ->where('type', 'garage')
                ->where('fuel_type_id', $fuelType->id)
                ->whereIn('tank_number', [1, 2])
                ->pluck('tank_number')
                ->map(fn (mixed $number): int => (int) $number)
                ->all();

            foreach ([1, 2] as $tankNumber) {
                if (in_array($tankNumber, $existingTankNumbers, true)) {
                    continue;
                }

                DB::table('storage_locations')->insert([
                    'location_code' => $this->nextLocationCode($fuelType->id, $tankNumber),
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

    public function down(): void
    {
        Schema::table('storage_locations', function (Blueprint $table) {
            $table->dropIndex(['fuel_type_id', 'tank_number']);
            $table->dropConstrainedForeignId('fuel_type_id');
            $table->dropColumn('tank_number');
        });

        Schema::table('hauls', function (Blueprint $table) {
            $table->dropColumn([
                'withdrawal_receipt_path',
                'withdrawal_receipt_notes',
                'withdrawal_receipt_uploaded_at',
            ]);
        });
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
};
