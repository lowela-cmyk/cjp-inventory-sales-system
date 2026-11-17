<?php

namespace App\Console\Commands;

use App\Services\PurchaseWorkflowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Part 23 #15: imported purchases kept workflow_status "pending" although their imported lifts are complete, because the
 * import never synchronized it. This recomputes the status from the existing lifts (PurchaseWorkflowService::synchronize,
 * without alerts). It changes workflow_status and adds a status-history row only: no quantity, payment, lift, delivery
 * or stock-out is created or changed. Purchases under reconciliation hold are skipped.
 *
 * Dry run by default. --apply works on an isolated *_qa / *_test database; the real database also needs
 * --approved-production (take a mysqldump backup and rehearse on a QA copy first, see CLAUDE.md).
 */
class SyncPurchaseWorkflowStatus extends Command
{
    protected $signature = 'inventory:sync-purchase-workflow {--apply : Write the changes} {--approved-production : Required with --apply on a non-QA database} {--report= : Write a per-purchase reconciliation JSON to this path}';

    protected $description = 'Recompute imported purchases\' workflow status from their existing lifts (dry run by default)';

    /** Records left unchanged until their source documents are reviewed (Part 23 reconciliation holds). */
    public const HELD_PURCHASE_CODES = ['PUR-PREM-001', 'PUR-DSL-012'];

    public function handle(PurchaseWorkflowService $workflow): int
    {
        $database = (string) DB::connection()->getDatabaseName();
        $isolated = DB::connection()->getDriverName() === 'sqlite' || preg_match('/(?:_qa|_test|_test_mariadb)$/', $database) === 1;
        $apply = (bool) $this->option('apply');
        if ($apply && ! $isolated && ! $this->option('approved-production')) {
            $this->error("Refusing to write to \"{$database}\": add --approved-production only after a backup, a QA rehearsal and explicit approval.");

            return self::FAILURE;
        }

        $before = DB::table('purchases')->groupBy('workflow_status')->selectRaw('workflow_status, COUNT(*) as n')->pluck('n', 'workflow_status')->all();
        $purchases = DB::table('purchases')->whereNull('deleted_at')->whereNotIn('purchase_code', self::HELD_PURCHASE_CODES)->orderBy('id')->pluck('id');
        $statusBefore = DB::table('purchases')->pluck('workflow_status', 'id')->all();
        $updatedBefore = DB::table('purchases')->pluck('updated_at', 'id')->all();
        $historyBefore = DB::table('purchase_status_histories')->count();
        $historyMaxIdBefore = (int) DB::table('purchase_status_histories')->max('id');
        $guard = $this->protectedTotals();

        DB::beginTransaction();
        try {
            foreach ($purchases as $id) {
                $workflow->synchronize((int) $id, null, null, false);
            }
            $after = DB::table('purchases')->groupBy('workflow_status')->selectRaw('workflow_status, COUNT(*) as n')->pluck('n', 'workflow_status')->all();
            $statusAfter = DB::table('purchases')->pluck('workflow_status', 'id')->all();
            $historyAdded = DB::table('purchase_status_histories')->count() - $historyBefore;
            if ($this->protectedTotals() !== $guard) {
                throw new \RuntimeException('Protected totals changed during the backfill; rolled back.');
            }
            $apply ? DB::commit() : DB::rollBack();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $changed = array_keys(array_filter($statusAfter, fn (?string $status, int $id): bool => ($statusBefore[$id] ?? null) !== $status, ARRAY_FILTER_USE_BOTH));
        $transitions = [];
        foreach ($changed as $id) {
            $key = ($statusBefore[$id] ?? 'none').' -> '.$statusAfter[$id];
            $transitions[$key] = ($transitions[$key] ?? 0) + 1;
        }
        if ($path = $this->option('report')) {
            $ordered = DB::table('purchase_items')->groupBy('purchase_id')->selectRaw('purchase_id, SUM(quantity_ordered_liters) as liters')->pluck('liters', 'purchase_id');
            $lifted = DB::table('hauls')->whereNull('deleted_at')->whereIn('status', ['lifted', 'completed'])->groupBy('purchase_id')->selectRaw('purchase_id, SUM(quantity_liters) as liters')->pluck('liters', 'purchase_id');
            $codes = DB::table('purchases')->pluck('purchase_code', 'id');
            $notCompleted = array_keys(array_filter($statusAfter, fn (?string $status): bool => $status !== 'completed'));
            file_put_contents($path, json_encode([
                'database' => $database, 'mode' => $apply ? 'applied' : 'dry-run', 'generated_at' => now()->toIso8601String(),
                'transitions' => $transitions, 'history_rows_added' => $historyAdded, 'history_max_id_before' => $historyMaxIdBefore,
                // Rollback: set each changed purchase back to its "from" status and from_updated_at, and delete history rows with id > history_max_id_before.
                'protected_totals_unchanged' => $guard,
                'changed' => array_map(fn (int $id): array => ['id' => $id, 'purchase_code' => $codes[$id], 'from' => $statusBefore[$id] ?? null, 'to' => $statusAfter[$id], 'from_updated_at' => $updatedBefore[$id] ?? null, 'ordered_liters' => (float) ($ordered[$id] ?? 0), 'lifted_liters' => (float) ($lifted[$id] ?? 0)], $changed),
                'not_completed' => array_map(fn (int $id): array => ['id' => $id, 'purchase_code' => $codes[$id], 'status' => $statusAfter[$id], 'ordered_liters' => (float) ($ordered[$id] ?? 0), 'lifted_liters' => (float) ($lifted[$id] ?? 0), 'held' => in_array($codes[$id], self::HELD_PURCHASE_CODES, true)], $notCompleted),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        }

        $this->line(json_encode([
            'database' => $database,
            'mode' => $apply ? 'applied' : 'dry-run (rolled back)',
            'purchases_checked' => $purchases->count(),
            'purchases_changed' => count($changed),
            'transitions' => $transitions,
            'history_rows_added' => $historyAdded,
            'held_unchanged' => self::HELD_PURCHASE_CODES,
            'workflow_status_before' => $before,
            'workflow_status_after' => $after,
        ], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    /** Totals the backfill must never change: payments, quantities, lifts, deliveries, stock-outs. */
    private function protectedTotals(): array
    {
        return [
            'payments' => (array) DB::selectOne('SELECT COUNT(*) AS n, COALESCE(SUM(amount), 0) AS amount FROM payments'),
            'purchase_liters' => (string) DB::table('purchase_items')->sum('quantity_ordered_liters'),
            'hauls' => (array) DB::selectOne('SELECT COUNT(*) AS n, COALESCE(SUM(quantity_liters), 0) AS liters FROM hauls'),
            'sale_deliveries' => DB::table('sales')->groupBy('delivery_status')->selectRaw('delivery_status, COUNT(*) as n')->pluck('n', 'delivery_status')->all(),
            'stock_outs' => (array) DB::selectOne('SELECT COUNT(*) AS n, COALESCE(SUM(quantity_liters), 0) AS liters FROM stock_outs'),
        ];
    }
}
