<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class SaleConfirmationService
{
    public function __construct(
        private readonly StockOutReleaseService $stockOutRelease
    ) {}

    /**
     * Confirms a sale without changing physical inventory. Stock-Out is owned by
     * the separate delivery-status transition below.
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

        $this->reconcile($saleId);
    }

    /**
     * @throws \RuntimeException
     */
    public function updateDeliveryStatus(int $saleId, string $deliveryStatus, int $userId): void
    {
        DB::transaction(function () use ($saleId, $deliveryStatus, $userId): void {
            $sale = DB::table('sales')
                ->where('id', $saleId)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();

            if (! $sale) {
                throw new \RuntimeException('Sale record not found.');
            }

            $current = (string) ($sale->delivery_status ?? 'pending');

            if ($sale->status === 'cancelled' || $current === 'cancelled') {
                throw new \RuntimeException('Cancelled sales are read-only and remain available for audit history.');
            }

            if ($deliveryStatus === 'cancelled') {
                throw new \RuntimeException('Use the Cancel action to preserve and reconcile the sale audit history.');
            }

            if ($current === 'delivered') {
                if ($deliveryStatus === 'delivered') {
                    return;
                }

                throw new \RuntimeException('A delivered sale cannot be moved back because its inventory was already released.');
            }

            if ($deliveryStatus !== 'pending' && $sale->status === 'draft') {
                throw new \RuntimeException('Confirm the sale before starting or completing delivery.');
            }

            if ($deliveryStatus === 'delivered') {
                // Set inside the same transaction so the shared Stock-Out service
                // can enforce Delivered for every caller. Any release failure
                // rolls this update back with the inventory work.
                DB::table('sales')
                    ->where('id', $saleId)
                    ->update([
                        'delivery_status' => 'delivered',
                        'updated_at' => now(),
                    ]);

                // Part 2 revisions: a Depot to Client order is delivered through its lift; a Standard order by its own
                // truck. Either way its liters are deducted from the order's purchases here, once.
                // Part 12: a Standard order can also be delivered from fuel left on a lifted truck; it then has a
                // delivery row on that lift too and is released through it (so the liters count as off the truck).
                $allocation = DB::table('haul_allocations')
                    ->join('hauls', 'hauls.id', '=', 'haul_allocations.haul_id')
                    ->where('haul_allocations.sale_id', $saleId)
                    ->where('haul_allocations.destination_type', 'customer')
                    ->where('haul_allocations.status', '!=', 'cancelled')
                    ->whereNull('hauls.deleted_at')
                    ->whereIn('hauls.status', ['lifted', 'completed'])
                    ->orderBy('haul_allocations.id')
                    ->lockForUpdate()
                    ->first(['haul_allocations.id', 'haul_allocations.haul_id']);
                if ($sale->delivery_type === 'depot_to_client' && ! $allocation) {
                    throw new \RuntimeException('This Depot to Client sale is delivered through its lift. Add it to a lift in Fuel Lifting and complete the lift first.');
                }

                $items = DB::table('sale_items')
                    ->where('sale_id', $saleId)
                    ->lockForUpdate()
                    ->get(['id']);

                if ($items->isEmpty()) {
                    throw new \RuntimeException('The sale has no items available for delivery.');
                }

                foreach ($items as $item) {
                    $error = $this->stockOutRelease->releaseSaleItem((int) $item->id, now()->toDateTimeString(), $userId, $allocation);
                    if ($error) {
                        throw new \RuntimeException($error);
                    }
                }

                if ($allocation) {
                    DB::table('haul_allocations')->where('id', $allocation->id)->update(['status' => 'delivered', 'updated_at' => now()]);
                }
            }

            DB::table('sales')
                ->where('id', $saleId)
                ->update([
                    'delivery_status' => $deliveryStatus,
                    'delivered_at' => $deliveryStatus === 'delivered' ? now() : null,
                    'delivery_status_updated_at' => now(),
                    'delivery_status_updated_by' => $userId,
                    'updated_at' => now(),
                ] + ($deliveryStatus === 'delivered' && $sale->delivery_type !== 'depot_to_client' && ! $sale->delivery_date
                    // A Standard order marked Delivered without a Delivery Date is dated now.
                    ? ['delivery_date' => now()]
                    : []));
        });
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
