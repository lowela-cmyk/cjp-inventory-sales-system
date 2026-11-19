<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historical payments imported from source records that state the amount and date but not the method are stored with
 * method "not_recorded" (shown as "Not recorded (historical)") instead of being presented as a verified method.
 * It is never offered for new payments: payment forms validate against the existing methods only.
 */
return new class extends Migration
{
    private const BEFORE = ['cash_on_delivery', 'cheque', 'advance_payment', 'bank_transfer'];

    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->enum('method', [...self::BEFORE, 'not_recorded'])->change();
        });
    }

    public function down(): void
    {
        if (DB::table('payments')->where('method', 'not_recorded')->exists()) {
            throw new RuntimeException('Payments still use the "not_recorded" method; they must be resolved before this migration can be rolled back.');
        }

        Schema::table('payments', function (Blueprint $table): void {
            $table->enum('method', self::BEFORE)->change();
        });
    }
};
