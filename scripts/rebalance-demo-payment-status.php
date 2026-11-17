<?php
// Approved demonstration-data adjustment, Variant B (29 Paid / 20 Unpaid / 41 Partially Paid), using voids.
//
//   DB_DATABASE=<allowed database> php scripts/rebalance-demo-payment-status.php [--apply] [--allow-production]
//
// Without --apply it is a dry run (everything is rolled back). It touches augmented 2026 sales only (SO-298..3000):
// - 196 open sales of 28 customers get a labelled settling payment (PAY-DEMO-S#####) for their balance;
// - 20 customers keep exactly one open sale, whose single imported payment is VOIDED through PaymentVoidService
//   (the row is kept, with who/when/why), and their other open sales are settled;
// - every touched sale is reconciled by SaleConfirmationService, and 3 sale_change_logs rows are written per sale.
// The plan must match the approved Variant B manifest exactly (same sales, actions, amounts and dates), or it stops.
// It never deletes a payment, never changes an existing payment's amount/date/code, and never touches 2020-2025 or
// the 297 original September sales.
if (PHP_SAPI !== 'cli' && ! defined('CJP_TRUSTED_DEPLOY_RUNNER')) exit(1);
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\PaymentVoidService;
use App\Services\SaleConfirmationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Production remains double-gated even after being explicitly allow-listed: --allow-production is also required.
const ALLOWED_DATABASES = [
    'cjp_payment_void_qa_20261115',
    'cjp_variant_b_restore_qa_20261115_222633',
    'cjp_inventory_sales_db',
    'u605447851_cjpdb',
];
const PRODUCTION_DATABASES = ['cjp_inventory_sales_db', 'u605447851_cjpdb'];
const BATCH = 'DEMO-REBALANCE-20261115';
const ORIGINAL_LAST_SO = 297;
const UNPAID_TARGET = 20;
const APPROVED_MANIFEST = 'outputs/payment-status-rebalance-cjp_payment_status_strict_qa_20261115-strict-applied-20261115_215320.json';
const APPROVED_MANIFEST_SHA256 = 'e6175cb9bc90f5d4d139e5bda339885d9a243bed1025ca42816df760aba6e6c2';
const TODAY = '2026-11-15';

$db = DB::connection()->getDatabaseName();
if (! in_array($db, ALLOWED_DATABASES, true)) throw new RuntimeException('Refusing to run on database: '.$db);
$apply = in_array('--apply', $argv, true);
if (in_array($db, PRODUCTION_DATABASES, true) && ! in_array('--allow-production', $argv, true)) throw new RuntimeException('Production requires --allow-production');
if (hash_file('sha256', base_path(APPROVED_MANIFEST)) !== APPROVED_MANIFEST_SHA256) throw new RuntimeException('The approved manifest changed');
$approved = json_decode(file_get_contents(base_path(APPROVED_MANIFEST)), true, flags: JSON_THROW_ON_ERROR);
foreach (['voided_at', 'voided_by', 'void_reason'] as $column) if (! Schema::hasColumn('payments', $column)) throw new RuntimeException('Void support is not migrated on '.$db);

$originalSql = "(YEAR(s.sale_date) = 2026 AND s.sales_order_number REGEXP '^SO-[0-9]+$' AND CAST(SUBSTRING(s.sales_order_number, 4) AS UNSIGNED) <= ".ORIGINAL_LAST_SO.')';
$activePaid = '(SELECT COALESCE(SUM(amount), 0) FROM payments WHERE sale_id = s.id AND voided_at IS NULL)';
$saleTotal = '(SELECT COALESCE(SUM(line_total), 0) FROM sale_items WHERE sale_id = s.id)';
$valid = "s.deleted_at IS NULL AND s.status IN ('confirmed', 'partially_paid', 'paid', 'unpaid')";
// Every stored column of the payments, so any change to an existing row is caught.
$fingerprint = function (string $where, bool $withVoid = true): array {
    $cols = "p.id, p.payment_code, p.sale_id, COALESCE(p.payment_schedule_id, ''), p.payment_date, p.amount, p.method, COALESCE(p.reference_number, ''), COALESCE(p.remarks, ''), COALESCE(p.received_by, ''), COALESCE(p.created_at, '')"
        .($withVoid ? ", COALESCE(p.updated_at, ''), COALESCE(p.voided_at, ''), COALESCE(p.voided_by, ''), COALESCE(p.void_reason, '')" : '');
    $row = DB::selectOne("SELECT COUNT(*) n, COALESCE(SUM(p.amount), 0) total, COALESCE(SUM(CRC32(CONCAT_WS('|', $cols))), 0) crc FROM payments p JOIN sales s ON s.id = p.sale_id WHERE $where");
    return [(int) $row->n, (string) $row->total, (string) $row->crc];
};
$customerCounts = function () use ($activePaid, $saleTotal, $valid): array {
    $row = DB::selectOne("SELECT SUM(open_sales = 0) paid, SUM(open_sales > 0 AND paid_on_open <= 0) unpaid, SUM(open_sales > 0 AND paid_on_open > 0) partial FROM (
        SELECT customer_id, SUM(total > paid) open_sales, SUM(CASE WHEN total > paid THEN paid ELSE 0 END) paid_on_open
        FROM (SELECT s.customer_id, $saleTotal total, $activePaid paid FROM sales s WHERE $valid) per_sale GROUP BY customer_id) per_customer");
    return ['paid' => (int) $row->paid, 'unpaid' => (int) $row->unpaid, 'partial' => (int) $row->partial];
};
$money = function () use ($activePaid, $saleTotal, $valid): array {
    $row = DB::selectOne("SELECT COUNT(*) sales, SUM(total) billed, SUM(paid) collected, SUM(GREATEST(total - paid, 0)) outstanding, SUM(paid > total) overpaid
        FROM (SELECT $saleTotal total, $activePaid paid FROM sales s WHERE $valid) x");
    return ['sales' => (int) $row->sales, 'billed' => (string) $row->billed, 'collected' => (string) $row->collected, 'outstanding' => (string) $row->outstanding, 'overpaid' => (int) $row->overpaid,
        'negative_payment_rows' => (int) DB::table('payments')->where('amount', '<=', 0)->count()];
};
// Collections by payment month (2026), counting active payments only: the Expected Revenue chart's "collected" series.
$monthly = function () use ($valid): array {
    return collect(DB::select("SELECT MONTH(p.payment_date) m, SUM(p.amount) total FROM payments p JOIN sales s ON s.id = p.sale_id WHERE $valid AND p.voided_at IS NULL AND YEAR(p.payment_date) = 2026 GROUP BY m"))
        ->mapWithKeys(fn ($r) => [(int) $r->m => (string) $r->total])->all();
};

DB::beginTransaction();
try {
    // ----- Preconditions. -----
    if (DB::table('payments')->where('reference_number', BATCH)->exists() || DB::table('payments')->where('payment_code', 'like', 'PAY-DEMO-%')->exists()) throw new RuntimeException('Rebalancing was already applied here');
    if (DB::table('payments')->whereNotNull('voided_at')->exists()) throw new RuntimeException('Voided payments already exist here');
    $before = [
        'customers' => $customerCounts(), 'money' => $money(), 'monthly_2026' => $monthly(),
        'historical_payments' => $fingerprint('YEAR(s.sale_date) < 2026'), 'original_payments' => $fingerprint($originalSql),
        'imported_payments_core' => $fingerprint("p.payment_code LIKE 'PAY-IMP-%'", false),
    ];
    if ($before['customers'] !== ['paid' => 1, 'unpaid' => 0, 'partial' => 89]) throw new RuntimeException('Unexpected starting distribution: '.json_encode($before['customers']));
    if ($before['money']['collected'] !== '8006701674.00' || $before['money']['outstanding'] !== '180141536.00') throw new RuntimeException('Unexpected starting totals: '.json_encode($before['money']));
    if ((int) DB::selectOne("SELECT COUNT(*) n FROM sales s WHERE $originalSql")->n !== ORIGINAL_LAST_SO) throw new RuntimeException('Original sale block differs');

    $open = collect(DB::select("SELECT s.id, s.sale_code, s.sales_order_number, s.customer_id, s.sale_date, s.status, $originalSql AS original, r.id receivable_id, r.due_date, r.status receivable_status, r.created_at receivable_created_at,
            $saleTotal total, $activePaid paid, (SELECT COUNT(*) FROM payments WHERE sale_id = s.id) payments
        FROM sales s JOIN receivables r ON r.sale_id = s.id WHERE $valid AND $saleTotal > $activePaid ORDER BY s.id FOR UPDATE"));
    foreach ($open as $sale) {
        if (substr((string) $sale->sale_date, 0, 4) !== '2026' || $sale->status !== 'partially_paid' || $sale->receivable_status !== 'partial' || (int) $sale->payments !== 1 || (float) $sale->paid <= 0) throw new RuntimeException('Open sale is not in the reviewed state: '.$sale->sale_code);
    }
    if ($open->count() !== 609) throw new RuntimeException('Expected 609 open sales, found '.$open->count());

    // ----- Selection (deterministic, augmented-only customers). -----
    $customers = $open->groupBy('customer_id')->map(fn ($sales, $id) => (object) [
        'id' => (int) $id, 'open' => $sales->count(), 'original_open' => $sales->where('original', 1)->count(),
        'balance' => round($sales->sum(fn ($s) => (float) $s->total - (float) $s->paid), 2), 'latest_sale_id' => (int) $sales->max('id'),
    ])->values();
    $augmentedOnly = $customers->where('original_open', 0)->sort(fn ($a, $b) => [$a->open, $a->balance, $a->id] <=> [$b->open, $b->balance, $b->id])->values();
    $unpaidGroup = $augmentedOnly->slice(-UNPAID_TARGET)->values();
    $paidIds = $augmentedOnly->slice(0, $augmentedOnly->count() - UNPAID_TARGET)->pluck('id')->flip();
    $unpaidLatest = $unpaidGroup->pluck('latest_sale_id', 'id');

    $plan = [];
    $settleNo = 0;
    foreach ($open as $sale) {
        $cid = (int) $sale->customer_id;
        $action = isset($paidIds[$cid]) ? 'settle' : (isset($unpaidLatest[$cid]) ? ((int) $sale->id === $unpaidLatest[$cid] ? 'void' : 'settle') : null);
        if ($action === null) continue;
        if ($sale->original) throw new RuntimeException('Variant B must not touch an original sale: '.$sale->sale_code);
        $plan[] = ['action' => $action, 'sale' => $sale, 'code' => $action === 'settle' ? sprintf('PAY-DEMO-S%05d', ++$settleNo) : null];
    }

    // ----- The plan must be the approved Variant B, row for row. -----
    $approvedRows = collect($approved['changes'])->map(fn ($c) => [(int) $c['sale_id'], $c['action'] === 'reverse' ? 'void' : 'settle', number_format(abs((float) $c['amount']), 2, '.', ''), $c['action'] === 'settle' ? $c['payment_code'] : null, $c['action'] === 'settle' ? $c['payment_date'] : null])->sortBy(fn ($r) => $r[0])->values()->all();
    $planRows = collect($plan)->map(function ($p) {
        $balance = round((float) $p['sale']->total - (float) $p['sale']->paid, 2);
        $p['amount'] = $p['action'] === 'settle' ? $balance : round((float) $p['sale']->paid, 2);
        $p['date'] = $p['action'] === 'settle' ? max((string) $p['sale']->sale_date, min((string) $p['sale']->due_date, TODAY)) : null;
        return [(int) $p['sale']->id, $p['action'], number_format($p['amount'], 2, '.', ''), $p['code'], $p['date']];
    })->sortBy(fn ($r) => $r[0])->values()->all();
    if ($approved['mode'] !== 'strict' || count($approvedRows) !== 216 || $planRows !== $approvedRows) throw new RuntimeException('The plan differs from the approved Variant B manifest');
    if (array_count_values(array_column($planRows, 1)) !== ['settle' => 196, 'void' => 20]) throw new RuntimeException('Unexpected action counts');

    $touchedIds = array_map(fn ($p) => (int) $p['sale']->id, $plan);
    $manifest = [
        'database' => $db, 'batch' => BATCH, 'variant' => 'B (voids)', 'applied' => $apply, 'generated_at' => date('c'), 'approved_manifest' => APPROVED_MANIFEST, 'approved_manifest_sha256' => APPROVED_MANIFEST_SHA256,
        'groups' => ['paid' => $paidIds->keys()->all(), 'unpaid' => $unpaidGroup->pluck('id')->all()],
        'before' => $before + ['sales' => DB::table('sales')->whereIn('id', $touchedIds)->orderBy('id')->get()->all(), 'receivables' => DB::table('receivables')->whereIn('sale_id', $touchedIds)->orderBy('id')->get()->all(), 'payments' => DB::table('payments')->whereIn('sale_id', $touchedIds)->orderBy('id')->get()->all()],
    ];

    // ----- Apply. -----
    $adminId = (int) DB::table('users')->where('role', 'admin')->orderBy('id')->value('id');
    $confirmation = app(SaleConfirmationService::class);
    $voids = app(PaymentVoidService::class);
    $now = date('Y-m-d H:i:s');
    $changes = []; $logs = 0;
    $log = function (object $sale, string $field, ?string $old, string $new) use ($adminId, $now, &$logs): void {
        DB::table('sale_change_logs')->insert(['sale_id' => $sale->id, 'sale_code' => $sale->sale_code, 'field' => $field, 'old_value' => $old, 'new_value' => $new.' ['.BATCH.' demonstration data]', 'changed_by' => $adminId, 'created_at' => $now, 'updated_at' => $now]);
        $logs++;
    };
    foreach ($plan as $p) {
        $sale = $p['sale'];
        if ($p['action'] === 'settle') {
            $amount = round((float) $sale->total - (float) $sale->paid, 2);
            $date = max((string) $sale->sale_date, min((string) $sale->due_date, TODAY));
            DB::table('payments')->insert(['payment_code' => $p['code'], 'sale_id' => $sale->id, 'payment_schedule_id' => null, 'payment_date' => $date, 'amount' => $amount, 'method' => 'bank_transfer', 'reference_number' => BATCH,
                'remarks' => 'DEMONSTRATION DATA - settling payment added by the approved payment-status rebalancing of '.TODAY.' (Variant B). Not a real collection.', 'received_by' => null, 'created_at' => $now, 'updated_at' => $now]);
            $statuses = $confirmation->reconcile((int) $sale->id);
            $log($sale, 'payments.'.$p['code'].'.amount', null, number_format($amount, 2, '.', ''));
            $detail = ['payment_code' => $p['code'], 'payment_date' => $date, 'amount' => $amount];
        } else {
            $payment = DB::table('payments')->where('sale_id', $sale->id)->first();
            if (! str_starts_with((string) $payment->payment_code, 'PAY-IMP-')) throw new RuntimeException('Only an imported demonstration payment may be voided');
            $statuses = $voids->void((int) $payment->id, $adminId, 'DEMONSTRATION DATA - voided by the approved payment-status rebalancing of '.TODAY.' (Variant B, '.BATCH.'). The imported demonstration payment is kept; it no longer counts.', (int) $sale->id);
            $logs++; // PaymentVoidService writes the payments.<code>.voided change-log row.
            $detail = ['voided_payment_code' => $payment->payment_code, 'voided_payment_date' => $payment->payment_date, 'voided_amount' => (float) $payment->amount];
        }
        $log($sale, 'status', $sale->status, $statuses['sale_status']);
        $log($sale, 'receivable.status', $sale->receivable_status, $statuses['receivable_status']);
        $changes[] = ['sale_id' => (int) $sale->id, 'sale_code' => $sale->sale_code, 'sales_order_number' => $sale->sales_order_number, 'customer_id' => (int) $sale->customer_id, 'action' => $p['action'],
            'sale_status' => [$sale->status, $statuses['sale_status']], 'receivable_status' => [$sale->receivable_status, $statuses['receivable_status']]] + $detail;
    }

    // ----- Verification inside the transaction: any failure rolls everything back. -----
    $after = [
        'customers' => $customerCounts(), 'money' => $money(), 'monthly_2026' => $monthly(),
        'historical_payments' => $fingerprint('YEAR(s.sale_date) < 2026'), 'original_payments' => $fingerprint($originalSql),
        'imported_payments_core' => $fingerprint("p.payment_code LIKE 'PAY-IMP-%'", false),
    ];
    $fail = function (string $message) { throw new RuntimeException($message); };
    if ($after['customers'] !== ['paid' => 29, 'unpaid' => 20, 'partial' => 41]) $fail('Distribution is '.json_encode($after['customers']));
    if ($after['money']['collected'] !== '8065873674.00' || $after['money']['outstanding'] !== '120969536.00') $fail('Totals are '.json_encode($after['money']));
    if ($after['historical_payments'] !== $before['historical_payments']) $fail('A 2020-2025 payment changed');
    if ($after['original_payments'] !== $before['original_payments']) $fail('An original September payment changed');
    if ($after['imported_payments_core'] !== $before['imported_payments_core']) $fail('An imported payment\'s amount, date, code or other recorded field changed');
    if ($after['money']['negative_payment_rows'] !== 0 || $after['money']['overpaid'] !== 0) $fail('Negative payment rows or overpaid sales');
    if ($after['money']['billed'] !== $before['money']['billed'] || $after['money']['sales'] !== $before['money']['sales']) $fail('Sales totals changed');
    if (bccomp(bcadd($after['money']['collected'], $after['money']['outstanding'], 2), $after['money']['billed'], 2) !== 0) $fail('Billed <> collected + outstanding');
    $voidedIds = DB::table('payments')->whereNotNull('voided_at')->pluck('sale_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
    $plannedVoids = collect($plan)->where('action', 'void')->map(fn ($p) => (int) $p['sale']->id)->sort()->values()->all();
    if ($voidedIds !== $plannedVoids) $fail('Voided payments differ from the plan');
    if (DB::table('payments')->whereNotNull('voided_at')->where(fn ($q) => $q->whereNull('voided_by')->orWhereNull('void_reason'))->exists()) $fail('A void without who/why');
    if (DB::table('payments')->where('payment_code', 'like', 'PAY-DEMO-%')->where(fn ($q) => $q->where('remarks', 'not like', 'DEMONSTRATION DATA%')->orWhere('reference_number', '!=', BATCH))->exists()) $fail('An unlabelled demonstration payment');
    $createdChanged = DB::table('receivables')->whereIn('sale_id', $touchedIds)->get(['sale_id', 'created_at'])->filter(fn ($r) => (string) $r->created_at !== (string) $open->firstWhere('id', $r->sale_id)->receivable_created_at)->count();
    if ($createdChanged !== 0) $fail($createdChanged.' receivable created_at value(s) were overwritten');
    if ($logs !== 648 || DB::table('sale_change_logs')->count() !== 648) $fail('Expected 648 change-log rows, wrote '.$logs);
    // Stored statuses agree with the active payments under the app's own rules (SaleConfirmationService::reconcile).
    $mismatch = DB::selectOne("SELECT COUNT(*) n FROM (SELECT s.status, r.status rs, $saleTotal total, $activePaid paid FROM sales s JOIN receivables r ON r.sale_id = s.id WHERE $valid) x
        WHERE NOT ((paid >= total AND status = 'paid' AND rs = 'clear') OR (paid > 0 AND paid < total AND status = 'partially_paid' AND rs IN ('partial', 'overdue'))
            OR (paid = 0 AND total > 0 AND status IN ('confirmed', 'unpaid') AND rs IN ('pending', 'overdue', 'unpaid')))")->n;
    if ((int) $mismatch !== 0) $fail($mismatch.' sale(s) whose status disagrees with its payments');

    // Monthly collections: what moved, and why.
    $delta = [];
    foreach (range(1, 12) as $m) {
        $settled = collect($changes)->where('action', 'settle')->filter(fn ($c) => (int) substr($c['payment_date'], 5, 2) === $m)->sum('amount');
        $voided = collect($changes)->where('action', 'void')->filter(fn ($c) => (int) substr((string) $c['voided_payment_date'], 5, 2) === $m)->sum('voided_amount');
        $b = (float) ($before['monthly_2026'][$m] ?? 0); $a = (float) ($after['monthly_2026'][$m] ?? 0);
        if (round($a - $b - $settled + $voided, 2) != 0) $fail('Month '.$m.' moved by an unexplained amount');
        if ($b != 0 || $a != 0) $delta[$m] = ['before' => $b, 'after' => $a, 'settling_payments_added' => $settled, 'voided_payments_excluded' => $voided];
    }

    $manifest += ['after' => $after, 'monthly_2026_changes' => $delta, 'change_log_rows' => $logs, 'changes' => $changes];
    $file = base_path('outputs/payment-status-rebalance-'.$db.'-variant-b-voids-'.($apply ? 'applied' : 'dryrun').'-'.date('Ymd_His').'.json');
    if (file_put_contents($file, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('Cannot save the manifest');
    $apply ? DB::commit() : DB::rollBack();
    echo json_encode(['database' => $db, 'applied' => $apply, 'before' => $before['customers'], 'after' => $after['customers'], 'money_before' => $before['money'], 'money_after' => $after['money'],
        'settling_payments_added' => 196, 'payments_voided' => count($voidedIds), 'change_log_rows' => $logs,
        'status_results' => collect($changes)->countBy(fn ($c) => $c['action'].': '.implode('/', [$c['sale_status'][1], $c['receivable_status'][1]]))->all(),
        'monthly_2026_changes' => $delta, 'manifest' => $file], JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $e) {
    DB::rollBack();
    throw $e;
}
