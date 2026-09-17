<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SaleConfirmationService
{
    public function __construct(
        private readonly StockOutReleaseService $stockOutRelease
    ) {}

    /**
     * Confirms a sale and initiates stock-out release or preparation for its items.
     *
     * @throws \RuntimeException
     */
    public function confirm(int $saleId, int $userId): void
    {
        $sale = DB::table('sales')
            ->where('id', $saleId)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first();

        if (! $sale) {
            throw new \RuntimeException('Sale record not found.');
        }

        if (! in_array($sale->status, ['draft', 'confirmed'], true)) {
            throw new \RuntimeException('Only draft or newly created sales can be confirmed.');
        }

        if ($sale->status !== 'confirmed') {
            DB::table('sales')
                ->where('id', $saleId)
                ->update([
                    'status' => 'confirmed',
                    'updated_at' => now(),
                ]);
        }

        $items = DB::table('sale_items')
            ->where('sale_id', $saleId)
            ->lockForUpdate()
            ->get();

        $stockOutAt = ($sale->sale_date ?: now()->toDateString()).' 12:00:00';

        foreach ($items as $item) {
            $remaining = round((float) $item->quantity_liters - (float) $item->fulfilled_quantity_liters, 2);
            if ($remaining <= 0) {
                continue;
            }

            $stockOutError = $this->stockOutRelease->releaseSaleItemFromGarage(
                (int) $item->id,
                $remaining,
                $stockOutAt,
                $userId,
                null,
                'Automatic stock-out from sale '.$sale->sale_code
            );

            if ($stockOutError) {
                throw new \RuntimeException($stockOutError);
            }
        }

        $this->reconcile($saleId);
    }

    /**
     * Reconciles the authoritative sale and receivable status based on actual item totals and payments.
     *
     * @return array{sale_status: string, receivable_status: string}
     */
    public function reconcile(int $saleId): array
    {
        $sale = DB::table('sales')
            ->where('id', $saleId)
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first();

        if (! $sale) {
            throw new \RuntimeException('Sale record not found.');
        }

        $saleTotal = round((float) DB::table('sale_items')
            ->where('sale_id', $saleId)
            ->sum('line_total'), 2);

        $paidTotal = round((float) DB::table('payments')
            ->where('sale_id', $saleId)
            ->sum('amount'), 2);

        $receivable = DB::table('receivables')
            ->where('sale_id', $saleId)
            ->lockForUpdate()
            ->first();

        $dueDate = $receivable?->due_date;
        $isOverdue = $dueDate && strtotime((string) $dueDate) < strtotime(now()->toDateString());

        if ($sale->status === 'cancelled') {
            $saleStatus = 'cancelled';
            $receivableStatus = $paidTotal > 0 ? ($paidTotal >= $saleTotal ? 'clear' : 'partial') : 'clear';
        } elseif ($sale->status === 'draft') {
            $saleStatus = 'draft';
            $receivableStatus = 'pending';
        } elseif ($saleTotal > 0 && $paidTotal >= $saleTotal) {
            $saleStatus = 'paid';
            $receivableStatus = 'clear';
        } elseif ($paidTotal > 0) {
            $saleStatus = 'partially_paid';
            $receivableStatus = $isOverdue ? 'overdue' : 'partial';
        } else {
            $saleStatus = $sale->status === 'unpaid' ? 'unpaid' : 'confirmed';
            $receivableStatus = $isOverdue ? 'overdue' : 'pending';
        }

        DB::table('sales')
            ->where('id', $saleId)
            ->update([
                'status' => $saleStatus,
                'updated_at' => now(),
            ]);

        DB::table('receivables')->updateOrInsert(
            ['sale_id' => $saleId],
            [
                'status' => $receivableStatus,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return [
            'sale_status' => $saleStatus,
            'receivable_status' => $receivableStatus,
        ];
    }
}
