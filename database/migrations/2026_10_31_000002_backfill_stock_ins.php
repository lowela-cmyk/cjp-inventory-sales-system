<?php

use App\Services\StockInService;
use Illuminate\Database\Migrations\Migration;

/**
 * October 31, 2026: gives every existing purchase line an up-to-date stock-in (missing rows created, cancelled ones
 * voided, rows of purchases changed outside Purchases refreshed). The same repair now runs automatically whenever
 * stock-in records are read; this applies it once to existing data. Nothing to undo: the stock-ins mirror the purchases.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(StockInService::class)->repair();
    }

    public function down(): void
    {
        //
    }
};
