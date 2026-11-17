<?php
// Read-only comparison of archived provenance manifests against an explicitly named QA copy.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use App\Support\ActivePayments;
$database = DB::connection()->getDatabaseName();
if (! str_ends_with($database, '_qa')) throw new RuntimeException('This review requires an isolated *_qa database.');
DB::statement('SET SESSION TRANSACTION READ ONLY');
DB::beginTransaction();
try {
    $lifts = json_decode(file_get_contents(base_path('outputs/ledger-status-proposal.json')), true, flags: JSON_THROW_ON_ERROR);
    $payments = json_decode(file_get_contents(base_path('outputs/payment-status-rebalance-cjp_inventory_sales_db-variant-b-voids-applied-20261115_223432.json')), true, flags: JSON_THROW_ON_ERROR);
    $haulIds = array_column($lifts['safe_changes'], 'haul_id');
    $saleIds = array_column($payments['changes'], 'sale_id');
    $haulRows = DB::table('hauls')->whereIn('id', $haulIds)->get();
    $saleRows = DB::table('sales')->whereIn('id', $saleIds)->get();
    $totals = DB::table('sale_items')->whereIn('sale_id', $saleIds)->groupBy('sale_id')->selectRaw('sale_id, SUM(line_total) total')->pluck('total', 'sale_id');
    $paid = ActivePayments::query()->whereIn('sale_id', $saleIds)->groupBy('sale_id')->selectRaw('sale_id, SUM(amount) total')->pluck('total', 'sale_id');
    $issues = [];
    foreach ($saleRows as $sale) {
        $balance = round(max(0, (float) ($totals[$sale->id] ?? 0) - (float) ($paid[$sale->id] ?? 0)), 2);
        if ($sale->status === 'paid' && $balance > .005) $issues[] = ['sale_id' => $sale->id, 'issue' => 'Paid status does not reconcile', 'balance' => $balance];
        if (($paid[$sale->id] ?? 0) > 0 && $balance > 0) $issues[] = ['sale_id' => $sale->id, 'issue' => 'Partially paid: no Paid correction permitted', 'balance' => $balance];
    }
    $manifestMismatches = [];
    foreach ($payments['changes'] as $change) {
        $expected = $change['sale_status'][1];
        $current = $saleRows->firstWhere('id', $change['sale_id']);
        if (! $current || $current->sale_code !== $change['sale_code'] || $current->status !== $expected) $manifestMismatches[] = ['sale_id' => $change['sale_id'], 'expected' => $expected, 'current' => $current?->status];
    }
    $missingPurchaseLinks = DB::table('hauls')->leftJoin('purchase_items', 'purchase_items.id', '=', 'hauls.purchase_item_id')->whereIn('hauls.id', $haulIds)->where(fn ($q) => $q->whereNull('purchase_items.id')->orWhereColumn('hauls.purchase_id', '!=', 'purchase_items.purchase_id'))->count();
    $missingScheduleLinks = DB::table('hauls')->leftJoin('lifting_schedules', 'lifting_schedules.id', '=', 'hauls.lifting_schedule_id')->whereIn('hauls.id', $haulIds)->whereNull('lifting_schedules.id')->count();
    $conflicts = [];
    foreach ($lifts['conflicts'] as $conflict) {
        $purchase = DB::table('purchases')->where('purchase_code', $conflict['purchase_code'])->first();
        $conflicts[] = $conflict + ['current_purchased_liters' => $purchase ? (float) DB::table('purchase_items')->where('purchase_id', $purchase->id)->sum('quantity_ordered_liters') : null];
    }
    $paymentFingerprint = function (string $where): array {
        return array_values((array) DB::selectOne("SELECT COUNT(*) n, COALESCE(SUM(p.amount), 0) total, COALESCE(SUM(CRC32(CONCAT_WS('|', p.id, p.payment_code, p.sale_id, COALESCE(p.payment_schedule_id, ''), p.payment_date, p.amount, p.method, COALESCE(p.reference_number, ''), COALESCE(p.remarks, ''), COALESCE(p.received_by, ''), COALESCE(p.created_at, ''), COALESCE(p.updated_at, ''), COALESCE(p.voided_at, ''), COALESCE(p.voided_by, ''), COALESCE(p.void_reason, '')))), 0) crc FROM payments p JOIN sales s ON s.id = p.sale_id WHERE ".$where));
    };
    $result = [
        'database' => $database, 'generated_at' => now()->toIso8601String(), 'read_only' => true,
        'provenance' => ['reviewed_demo_sale_ids' => count($saleIds), 'reviewed_imported_haul_ids' => count($haulIds), 'classification_limit' => 'Only exact IDs and codes in archived manifests are classified. Other records remain unclassified; prior approval is not reused.'],
        'imported_lifts' => ['found' => $haulRows->count(), 'statuses' => $haulRows->countBy('status')->all(), 'missing_purchase_links' => $missingPurchaseLinks, 'missing_schedule_links' => $missingScheduleLinks],
        'demo_sales' => ['found' => $saleRows->count(), 'statuses' => $saleRows->countBy('status')->all(), 'manifest_mismatches' => $manifestMismatches, 'payment_issues' => $issues],
        'archived_quantity_conflicts' => $conflicts,
        'historical_payment_baseline' => ['archived' => $payments['after']['historical_payments'], 'current' => $paymentFingerprint('YEAR(s.sale_date) < 2026')],
        'original_2026_payment_baseline' => ['archived' => $payments['after']['original_payments'], 'current' => $paymentFingerprint("YEAR(s.sale_date) = 2026 AND s.sales_order_number REGEXP '^SO-[0-9]+$' AND CAST(SUBSTRING(s.sales_order_number, 4) AS UNSIGNED) <= 297")],
        'proposed_bulk_changes' => [], 'financial_impact' => 0, 'inventory_impact_liters' => 0,
        'approval_note' => 'No correction is applied. Any future proposal needs current evidence, QA validation and explicit approval.',
    ];
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
} finally { DB::rollBack(); }
