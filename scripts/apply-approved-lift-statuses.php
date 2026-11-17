<?php
// One approved correction, frozen to the reviewed proposal. No model observers or stock posting.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$path = base_path('outputs/ledger-status-proposal.json');
$hash = '4d86c080db8733bdfc3b2ef45590fa917f816b729924fd880b8e8ef9a7d79714';
if (hash_file('sha256', $path) !== $hash) throw new RuntimeException('Approved proposal changed');
$proposal = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
$source = '/Users/johnlukereynon/Downloads/CJP_All_Modules_Populated_2020_2026.xlsx';
if (hash_file('sha256', $source) !== $proposal['workbook_sha256']) throw new RuntimeException('Source changed');
$db = DB::connection()->getDatabaseName();
if (! in_array($db, ['cjp_inventory_sales_db', 'cjp_status_correction_qa_20261115'], true)) throw new RuntimeException('Unexpected database');
$apply = in_array('--apply', $argv, true);
$rows = $proposal['safe_changes'];
$ids = array_column($rows, 'haul_id');
$scheduleIds = array_column($rows, 'lifting_schedule_id');
if (count($rows) !== 973 || count(array_unique($ids)) !== 973 || count(array_unique($scheduleIds)) !== 973) throw new RuntimeException('Approved counts differ');
$result = DB::transaction(function () use ($rows, $ids, $scheduleIds, $apply, $db, $hash, $proposal) {
    $hauls = DB::table('hauls')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    $schedules = DB::table('lifting_schedules')->whereIn('id', $scheduleIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    $items = DB::table('purchase_items')->join('purchases','purchases.id','=','purchase_items.purchase_id')->get(['purchase_items.id','purchases.id as purchase_id','purchases.purchase_code','purchase_items.quantity_ordered_liters'])->keyBy('id');
    if ($hauls->count() !== 973 || $schedules->count() !== 973) throw new RuntimeException('Missing approved rows');
    foreach ($rows as $row) {
        $h = $hauls[$row['haul_id']];
        $s = $schedules[$row['lifting_schedule_id']];
        $item = $items[$row['purchase_item_id']] ?? null;
        if (! $item || $item->purchase_code === 'PUR-PREM-001' || $h->purchase_id !== $item->purchase_id || $h->haul_code !== $row['haul_code'] || $h->purchase_item_id !== $row['purchase_item_id'] || $h->lifting_schedule_id !== $s->id || abs((float)$h->quantity_liters-(float)$row['quantity_liters']) > 0.005 || $h->deleted_at || $s->deleted_at || $row['source_status'] !== 'COMPLETED (INFERRED)') throw new RuntimeException('Approved identity/quantity mismatch: '.$row['haul_id']);
        foreach (['depot_id','truck_id','driver_user_id','historical_driver_name','scheduled_at'] as $field) if ($h->$field !== $s->$field) throw new RuntimeException('Schedule dependency mismatch: '.$h->id.' '.$field);
        if (! in_array($h->status, ['scheduled','completed'], true) || $s->status !== $h->status) throw new RuntimeException('Status differs: '.$h->id);
        $other = DB::table('hauls')->where('lifting_schedule_id', $s->id)->whereNotIn('id', $ids)->exists();
        if ($other) throw new RuntimeException('Schedule has an unapproved linked haul');
    }
    $before = ['hauls' => $hauls->values()->all(), 'lifting_schedules' => $schedules->values()->all()];
    if ($apply) {
        // Persist source provenance and original rows before committing any database change.
        $manifest = ['database'=>$db,'proposal_sha256'=>$hash,'source_sha256'=>$proposal['workbook_sha256'],'source_status'=>'COMPLETED (INFERRED)','before'=>$before];
        if (file_put_contents(base_path('outputs/approved-lift-statuses-'.$db.'-'.date('Ymd_His').'.json'), json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) throw new RuntimeException('Cannot save provenance manifest');
    }
    $updated = ['hauls'=>0,'lifting_schedules'=>0];
    foreach (['hauls'=>$ids,'lifting_schedules'=>$scheduleIds] as $table=>$tableIds) {
        if ($apply) foreach (array_chunk($tableIds, 400) as $chunk) $updated[$table] += DB::table($table)->whereIn('id',$chunk)->where('status','scheduled')->update(['status'=>'completed']);
        $after = DB::table($table)->whereIn('id',$tableIds)->orderBy('id')->get()->all();
        foreach ($after as $index=>$record) {
            $expected = (array) $before[$table][$index];
            if ($apply) $expected['status']='completed';
            if ((array)$record !== $expected) throw new RuntimeException('Unexpected column change: '.$table);
        }
    }
    return ['database'=>$db,'apply'=>$apply,'verified_hauls'=>973,'verified_schedules'=>973,'updated'=>$updated,'provenance'=>'COMPLETED (INFERRED)','excluded'=>'PUR-PREM-001'];
});
echo json_encode($result, JSON_PRETTY_PRINT).PHP_EOL;
