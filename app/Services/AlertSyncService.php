<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Part 12 (October 26, 2026): alerts for records that are not settled yet, computed from the data (existing records
 * included, not only new ones). One alert per record and condition (deduplication key "sync:<kind>:<id>"):
 *
 * - Sale with a balance (type receivable): Unpaid / Partially Paid (including Delivered) is a warning; past its due date
 *   it becomes Overdue (critical, unread again). Resolves when fully paid, cancelled or deleted.
 * - Purchase not fully lifted (type purchase, info): liters still in the depot. Resolves when fully lifted or cancelled.
 * - Depot to Client order not on a lift (type delivery, warning). Resolves once it is on a lift, delivered or cancelled.
 * - Order partially fulfilled (type delivery, warning). Resolves when fully delivered or cancelled.
 *
 * A resolved alert keeps its row (status resolved) and comes back (open) if its condition returns. The sync is cheap
 * to call on every page: it first compares a fingerprint of the source tables (and today's date, for overdue) with the
 * last run and does nothing when nothing changed.
 */
class AlertSyncService
{
    private const FINGERPRINT_KEY = 'alerts:sync-fingerprint';

    public function __construct(
        private readonly PurchaseStockService $purchaseStock,
        private readonly LiftAllocationService $liftAllocations
    ) {}

    public function sync(bool $force = false): void
    {
        $fingerprint = $this->fingerprint();
        if (! $force && Cache::get(self::FINGERPRINT_KEY) === $fingerprint) {
            return;
        }

        $lock = Cache::lock('alerts:sync', 30);
        if (! $lock->get()) {
            return;
        }

        try {
            DB::transaction(function (): void {
                $wanted = collect()
                    ->merge($this->saleBalanceAlerts())
                    ->merge($this->purchaseLiftAlerts())
                    ->merge($this->orderAlerts())
                    ->keyBy('deduplication_key');

                $this->apply($wanted);
            });
            Cache::forever(self::FINGERPRINT_KEY, $fingerprint);
        } finally {
            $lock->release();
        }
    }

    /** One small query: changes to sales, payments, purchases, lifts or deliveries (or a new day) trigger a sync. */
    private function fingerprint(): string
    {
        $tables = ['sales', 'sale_items', 'payments', 'receivables', 'purchases', 'purchase_items', 'hauls', 'haul_allocations'];
        $parts = collect($tables)
            ->map(fn (string $table): string => "(SELECT COUNT(*) FROM {$table}), (SELECT MAX(updated_at) FROM {$table})")
            ->implode(', ');

        return md5(CarbonImmutable::today()->toDateString().'|'.json_encode(array_values((array) DB::selectOne("SELECT {$parts}"))));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function saleBalanceAlerts(): Collection
    {
        $totals = DB::table('sale_items')->groupBy('sale_id')->selectRaw('sale_id, COALESCE(SUM(line_total), 0) as total');
        $paid = DB::table('payments')->groupBy('sale_id')->selectRaw('sale_id, COALESCE(SUM(amount), 0) as paid');
        $today = CarbonImmutable::today()->toDateString();

        return DB::table('sales')
            ->join('customers', 'customers.id', '=', 'sales.customer_id')
            ->joinSub($totals, 'totals', 'totals.sale_id', '=', 'sales.id')
            ->leftJoinSub($paid, 'paid', 'paid.sale_id', '=', 'sales.id')
            ->leftJoin('receivables', 'receivables.sale_id', '=', 'sales.id')
            ->whereNull('sales.deleted_at')
            ->whereNotIn('sales.status', ['draft', 'cancelled'])
            ->whereRaw('totals.total - COALESCE(paid.paid, 0) > 0.004')
            ->get(['sales.id', 'sales.sale_code', 'sales.delivery_status', 'customers.name', 'customers.company_name', 'totals.total', 'paid.paid', 'receivables.due_date'])
            ->map(function (object $sale) use ($today): array {
                $paid = round((float) $sale->paid, 2);
                $balance = round((float) $sale->total - $paid, 2);
                $overdue = $sale->due_date && substr((string) $sale->due_date, 0, 10) < $today;
                $state = $overdue ? 'Overdue' : ($paid > 0 ? 'Partially Paid' : 'Unpaid');

                return [
                    'deduplication_key' => 'sync:sale-balance:'.$sale->id,
                    'type' => 'receivable',
                    'severity' => $overdue ? 'critical' : 'warning',
                    'title' => $sale->sale_code.' '.$state,
                    'message' => sprintf('%s | Customer: %s | Total: PHP %s | Paid: PHP %s | Balance: PHP %s | Due date: %s | Delivery: %s',
                        $sale->sale_code, $sale->company_name ?: $sale->name, number_format((float) $sale->total, 2), number_format($paid, 2),
                        number_format($balance, 2), $sale->due_date ? date('n/j/Y', strtotime((string) $sale->due_date)) : 'N/A', ucwords(str_replace('_', ' ', (string) $sale->delivery_status))),
                    'reference_type' => 'sale',
                    'reference_id' => (int) $sale->id,
                ];
            });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function purchaseLiftAlerts(): Collection
    {
        // Lines of purchases that are not cancelled or deleted, with liters still in the depot.
        return $this->purchaseStock->lines()
            ->filter(fn (object $line): bool => (float) $line->remaining_in_depot > 0)
            ->groupBy('purchase_id')
            ->map(function (Collection $group): array {
                $first = $group->first();
                $ordered = (float) $group->sum('quantity_ordered_liters');
                $remaining = (float) $group->sum('remaining_in_depot');

                return [
                    'deduplication_key' => 'sync:purchase-unlifted:'.$first->purchase_id,
                    'type' => 'purchase',
                    'severity' => 'info',
                    'title' => $first->purchase_code.' Not Fully Lifted',
                    'message' => sprintf('%s | Fuel: %s | Ordered: %s L | Still in depot: %s L | Depot: %s',
                        $first->purchase_code, $group->pluck('fuel_name')->unique()->implode(', '), number_format($ordered, 2), number_format($remaining, 2), $first->depot_name),
                    'reference_type' => 'purchase',
                    'reference_id' => (int) $first->purchase_id,
                ];
            })
            ->values();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function orderAlerts(): Collection
    {
        $notOnLift = $this->liftAllocations->directSaleOptions()->map(fn (object $sale): array => [
            'deduplication_key' => 'sync:order-not-on-lift:'.$sale->sale_id,
            'type' => 'delivery',
            'severity' => 'warning',
            'title' => $sale->sale_code.' Not on a Lift',
            'message' => sprintf('%s | Depot to Client order of %s | %s still to deliver | Add it to a lift in Fuel Lifting.',
                $sale->sale_code, $sale->company_name ?: $sale->customer_name, $sale->ordered_label),
            'reference_type' => 'sale',
            'reference_id' => (int) $sale->sale_id,
        ]);

        $partial = DB::table('sales')
            ->join('sale_items', 'sale_items.sale_id', '=', 'sales.id')
            ->whereNull('sales.deleted_at')
            ->whereNotIn('sales.status', ['draft', 'cancelled'])
            ->whereNotIn('sales.delivery_status', ['delivered', 'cancelled'])
            ->groupBy('sales.id', 'sales.sale_code')
            ->havingRaw('SUM(sale_items.fulfilled_quantity_liters) > 0 AND SUM(sale_items.fulfilled_quantity_liters) < SUM(sale_items.quantity_liters)')
            ->selectRaw('sales.id, sales.sale_code, SUM(sale_items.quantity_liters) as ordered, SUM(sale_items.fulfilled_quantity_liters) as fulfilled')
            ->get()
            ->map(fn (object $sale): array => [
                'deduplication_key' => 'sync:order-partial:'.$sale->id,
                'type' => 'delivery',
                'severity' => 'warning',
                'title' => $sale->sale_code.' Partially Delivered',
                'message' => sprintf('%s | Delivered %s L of %s L', $sale->sale_code, number_format((float) $sale->fulfilled, 2), number_format((float) $sale->ordered, 2)),
                'reference_type' => 'sale',
                'reference_id' => (int) $sale->id,
            ]);

        return $notOnLift->merge($partial);
    }

    /**
     * Opens, updates and resolves the computed alerts so they match $wanted.
     *
     * @param  Collection<string, array<string, mixed>>  $wanted
     */
    private function apply(Collection $wanted): void
    {
        $existing = DB::table('alerts')->where('deduplication_key', 'like', 'sync:%')
            ->get(['id', 'deduplication_key', 'severity', 'title', 'message', 'status'])
            ->keyBy('deduplication_key');
        $now = now();

        foreach ($wanted as $key => $alert) {
            $row = $existing->get($key);
            if (! $row) {
                DB::table('alerts')->insertOrIgnore($alert + [
                    'alert_code' => $this->nextCode(),
                    'status' => 'open',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                continue;
            }

            $reopened = $row->status === 'resolved';
            $escalated = $row->severity !== $alert['severity'] && $alert['severity'] === 'critical';
            if ($reopened || $escalated || $row->title !== $alert['title'] || $row->message !== $alert['message'] || $row->severity !== $alert['severity']) {
                DB::table('alerts')->where('id', $row->id)->update([
                    'severity' => $alert['severity'],
                    'title' => $alert['title'],
                    'message' => $alert['message'],
                    'status' => 'open',
                    'resolved_at' => null,
                    'updated_at' => $now,
                ] + ($reopened || $escalated ? ['created_at' => $now] : []));
                // Back (or now overdue): everyone sees it as unread again.
                if ($reopened || $escalated) {
                    DB::table('alert_reads')->where('alert_id', $row->id)->delete();
                }
            }
        }

        $gone = $existing->reject(fn (object $row, string $key): bool => $wanted->has($key) || $row->status === 'resolved')->pluck('id');
        if ($gone->isNotEmpty()) {
            DB::table('alerts')->whereIn('id', $gone)->update(['status' => 'resolved', 'resolved_at' => $now, 'updated_at' => $now]);
        }
    }

    private function nextCode(): string
    {
        do {
            $code = 'ALT-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        } while (DB::table('alerts')->where('alert_code', $code)->exists());

        return $code;
    }
}
