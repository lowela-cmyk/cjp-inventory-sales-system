<?php

return new class extends \Illuminate\Database\Migrations\Migration
{
    public function up(): void
    {
        \Illuminate\Support\Facades\Schema::table('sale_change_logs', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->string('sale_code', 30)->nullable()->after('sale_id');
            $table->index('sale_code');
        });

        // Keep this portable across SQLite and MariaDB. Rows whose sale was already deleted
        // cannot be reconstructed and intentionally remain nullable.
        \Illuminate\Support\Facades\DB::table('sale_change_logs')
            ->join('sales', 'sales.id', '=', 'sale_change_logs.sale_id')
            ->whereNull('sale_change_logs.sale_code')
            ->select('sale_change_logs.id', 'sales.sale_code')
            ->orderBy('sale_change_logs.id')
            ->get()
            ->each(function (object $row): void {
                \Illuminate\Support\Facades\DB::table('sale_change_logs')->where('id', $row->id)->update(['sale_code' => $row->sale_code]);
            });
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\Schema::table('sale_change_logs', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->dropIndex(['sale_code']);
            $table->dropColumn('sale_code');
        });
    }
};
