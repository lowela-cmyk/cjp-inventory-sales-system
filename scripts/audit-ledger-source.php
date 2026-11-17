<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection();
$path = '/Users/johnlukereynon/Downloads/CJP_All_Modules_Populated_2020_2026.xlsx';
$service = app(App\Services\HistoricalInventoryRepairService::class);
$reader = new ReflectionMethod($service, 'sheetRows');
$hauls = Illuminate\Support\Facades\DB::table('hauls')->get()->keyBy('haul_code');
$proposal = [];
foreach ($reader->invoke($service, $path, 'Lifting - Hauled', 'N') as $rowNumber => $row) {
    if (($row['M'] ?? '') !== 'COMPLETED (INFERRED)') continue;
    $haul = $hauls->get($row['A']);
    if (! $haul || abs((float) $haul->quantity_liters - (float) $row['J']) > 0.005) throw new RuntimeException('Source identity or quantity mismatch on row '.$rowNumber);
    $proposal[] = ['source_row' => $rowNumber, 'haul_id' => $haul->id, 'haul_code' => $haul->haul_code, 'lifting_schedule_id' => $haul->lifting_schedule_id, 'purchase_item_id' => $haul->purchase_item_id, 'quantity_liters' => $haul->quantity_liters, 'current_status' => $haul->status, 'proposed_status' => 'completed', 'source_status' => $row['M']];
}
$report = ['database' => $db->getDatabaseName(), 'workbook_sha256' => hash_file('sha256', $path), 'requires_approval' => true, 'hauls' => count($proposal), 'schedules' => count(array_unique(array_column($proposal, 'lifting_schedule_id'))), 'purchase_items' => count(array_unique(array_column($proposal, 'purchase_item_id'))), 'liters' => array_sum(array_column($proposal, 'quantity_liters')), 'changes' => $proposal];
$purchases = Illuminate\Support\Facades\DB::table('purchase_items')->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')->get(['purchase_items.id','purchase_items.quantity_ordered_liters','purchases.purchase_code'])->keyBy('id');
$lifted = Illuminate\Support\Facades\DB::table('hauls')->whereIn('status',['lifted','completed'])->whereNull('deleted_at')->selectRaw('purchase_item_id,SUM(quantity_liters) liters')->groupBy('purchase_item_id')->pluck('liters','purchase_item_id')->all();
foreach ($proposal as $change) $lifted[$change['purchase_item_id']] = ($lifted[$change['purchase_item_id']] ?? 0) + (float) $change['quantity_liters'];
$conflicts = [];
foreach ($lifted as $id => $liters) if ($liters > (float) $purchases[$id]->quantity_ordered_liters) $conflicts[$id] = ['purchase_code' => $purchases[$id]->purchase_code,'purchased_liters' => $purchases[$id]->quantity_ordered_liters,'projected_lifted_liters' => $liters];
$safe = array_values(array_filter($proposal, fn ($row) => ! isset($conflicts[$row['purchase_item_id']])));
$report['conflicts'] = array_values($conflicts);
$report['safe_changes'] = $safe;
$report['safe_hauls'] = count($safe);
$report['safe_schedules'] = count(array_unique(array_column($safe,'lifting_schedule_id')));
file_put_contents(__DIR__.'/../outputs/ledger-status-proposal.json', json_encode($report, JSON_PRETTY_PRINT));
unset($report['changes'], $report['safe_changes']);
echo json_encode($report, JSON_PRETTY_PRINT).PHP_EOL;
