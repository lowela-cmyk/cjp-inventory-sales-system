<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SaleCorrectionService
{
    /**
     * Untouched drafts may be removed. Every other active sale must be voided
     * so its financial and inventory history remains traceable.
     * Part 17: a Delivered sale was already deducted from stock (Stock Out), so it can't be cancelled (null).
     */
    public function correctionMode(int $saleId): ?string
    {
        $sale = DB::table('sales')
            ->where('id', $saleId)
            ->whereNull('deleted_at')
            ->first(['id', 'status', 'delivery_status']);

        if (! $sale || $sale->status === 'cancelled' || $sale->delivery_status === 'delivered') {
            return null;
        }

        return $sale->status === 'draft' && ! $this->hasDependentActivity($saleId)
            ? 'delete'
            : 'cancel';
    }

    /** Batch display hints only; correct() still rechecks dependencies under locks before any mutation. */
    public function correctionModes(array $saleIds): array
    {
        $sales = DB::table('sales')->whereIn('id', $saleIds)->whereNull('deleted_at')->get(['id', 'status', 'delivery_status']);
        $draftIds = $sales->where('status', 'draft')->pluck('id');
        $dependent = collect();
        if ($draftIds->isNotEmpty()) {
            foreach (['payments', 'payment_schedules', 'stock_outs', 'haul_allocations'] as $table) $dependent = $dependent->merge(DB::table($table)->whereIn('sale_id', $draftIds)->pluck('sale_id'));
            $dependent = $dependent->merge(DB::table('sale_items')->whereIn('sale_id', $draftIds)->where('fulfilled_quantity_liters', '>', 0)->pluck('sale_id'))
                ->merge(DB::table('receivables')->whereIn('sale_id', $draftIds)->where(fn ($q) => $q->where('status', '!=', 'unpaid')->orWhereNotNull('last_follow_up_at')->orWhereNotNull('notes'))->pluck('sale_id'));
        }
        $set = array_fill_keys($dependent->all(), true);
        return $sales->mapWithKeys(fn ($s) => [$s->id => $s->status === 'cancelled' || $s->delivery_status === 'delivered' ? null : ($s->status === 'draft' && ! isset($set[$s->id]) ? 'delete' : 'cancel')])->all();
    }

    /**
     * @return 'deleted'|'cancelled'
     */
    public function correct(int $saleId, int $userId, ?string $reason = null): string
    {
        return DB::transaction(function () use ($saleId, $userId, $reason): string {
            $sale = DB::table('sales')
                ->where('id', $saleId)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first(['id', 'sale_code', 'status', 'delivery_status']);

            if (! $sale) {
                throw new \RuntimeException('Sale record not found.');
            }

            if ($sale->status === 'cancelled') {
                throw new \RuntimeException('This sale is already cancelled.');
            }

            if ($sale->delivery_status === 'delivered') {
                throw new \RuntimeException($sale->sale_code.' is Delivered: its fuel was already deducted from stock (Stock Out), so it cannot be cancelled.');
            }

            if ($sale->status === 'draft' && ! $this->hasDependentActivity($saleId, true)) {
                DB::table('receivables')->where('sale_id', $saleId)->delete();
                DB::table('payment_schedules')->where('sale_id', $saleId)->delete();
                DB::table('sale_items')->where('sale_id', $saleId)->delete();
                DB::table('sales')->where('id', $saleId)->delete();

                return 'deleted';
            }

            DB::table('stock_outs')
                ->where('sale_id', $saleId)
                ->where('status', '!=', 'cancelled')
                ->update([
                    'status' => 'cancelled',
                    'updated_at' => now(),
                ]);

            DB::table('haul_allocations')
                ->where('sale_id', $saleId)
                ->where('status', '!=', 'cancelled')
                ->update([
                    'status' => 'cancelled',
                    'updated_at' => now(),
                ]);

            DB::table('sale_items')
                ->where('sale_id', $saleId)
                ->update([
                    'fulfilled_quantity_liters' => 0,
                    'updated_at' => now(),
                ]);

            $auditReason = trim((string) $reason);
            if ($auditReason === '') {
                $auditReason = 'Order correction';
            }

            DB::table('sales')
                ->where('id', $saleId)
                ->update([
                    'status' => 'cancelled',
                    'delivery_status' => 'cancelled',
                    'delivery_status_updated_at' => now(),
                    'delivery_status_updated_by' => $userId,
                    'cancelled_at' => now(),
                    'cancelled_by' => $userId,
                    'cancellation_reason' => $auditReason,
                    'updated_at' => now(),
                ]);

            $receivable = DB::table('receivables')
                ->where('sale_id', $saleId)
                ->lockForUpdate()
                ->first(['id', 'notes']);
            $auditNote = 'Sale cancelled for correction; payments and linked transaction history were preserved.';

            if ($receivable) {
                $notes = trim(implode("\n", array_filter([
                    trim((string) $receivable->notes),
                    $auditNote,
                ])));

                DB::table('receivables')
                    ->where('id', $receivable->id)
                    ->update([
                        'status' => 'unpaid',
                        'notes' => $notes,
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('receivables')->insert([
                    'sale_id' => $saleId,
                    'status' => 'unpaid',
                    'notes' => $auditNote,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return 'cancelled';
        });
    }

    private function hasDependentActivity(int $saleId, bool $lock = false): bool
    {
        $tables = ['payments', 'payment_schedules', 'stock_outs', 'haul_allocations'];

        foreach ($tables as $table) {
            $query = DB::table($table)->where('sale_id', $saleId);
            if ($lock) {
                $query->lockForUpdate();
            }
            if ($query->exists()) {
                return true;
            }
        }

        $fulfilled = DB::table('sale_items')
            ->where('sale_id', $saleId)
            ->where('fulfilled_quantity_liters', '>', 0);
        if ($lock) {
            $fulfilled->lockForUpdate();
        }
        if ($fulfilled->exists()) {
            return true;
        }

        $receivableActivity = DB::table('receivables')
            ->where('sale_id', $saleId)
            ->where(function ($query): void {
                $query->where('status', '!=', 'unpaid')
                    ->orWhereNotNull('last_follow_up_at')
                    ->orWhereNotNull('notes');
            });
        if ($lock) {
            $receivableActivity->lockForUpdate();
        }

        return $receivableActivity->exists();
    }
}
