<?php

namespace App\Services;

use App\Models\ImportBatch;
use App\Support\ActivePayments;
use Illuminate\Support\Facades\DB;

/** Read-only evidence for human review. Never infers mock provenance from dates, names or code prefixes. */
class ImportedTransactionAuditService
{
    public function report(ImportBatch $batch): array
    {
        $kind = $batch->workbook_summary['dataset_kind'] ?? 'unknown';
        $records = $batch->result_summary['records'] ?? [];
        $issues = [];
        $proposals = [];
        foreach ($records as $record) {
            if (($record['table'] ?? '') === 'sales' && isset($record['id'])) {
                $sale = DB::table('sales')->where('id', $record['id'])->first();
                if (! $sale) { $issues[] = ['record' => $record, 'issue' => 'Missing imported sale']; continue; }
                $total = (float) DB::table('sale_items')->where('sale_id', $sale->id)->sum('line_total');
                $paid = (float) ActivePayments::query()->where('sale_id', $sale->id)->sum('amount');
                $quantity = (float) DB::table('sale_items')->where('sale_id', $sale->id)->sum('quantity_liters');
                $released = (float) DB::table('stock_outs')->join('sale_items', 'sale_items.id', '=', 'stock_outs.sale_item_id')->where('sale_items.sale_id', $sale->id)->where('stock_outs.status', 'released')->sum('stock_outs.quantity_liters');
                $evidence = ['record' => $record, 'code' => $sale->sale_code, 'status' => $sale->status, 'delivery_status' => $sale->delivery_status, 'total' => $total, 'active_payments' => $paid, 'balance' => max(0, $total - $paid), 'ordered_liters' => $quantity, 'released_liters' => $released];
                if (! DB::table('customers')->where('id', $sale->customer_id)->exists() || $total <= 0) $issues[] = $evidence + ['issue' => 'Missing customer or billable sale items'];
                if ($sale->status === 'paid' && $paid + .005 < $total) $issues[] = $evidence + ['issue' => 'Paid status has insufficient payment evidence; do not add a payment'];
                if ($sale->delivery_status === 'delivered' && $released + .005 < $quantity) $issues[] = $evidence + ['issue' => 'Delivered status has insufficient stock-out evidence; review source records'];
                if ($kind === 'mock_demo' && ! in_array($sale->status, ['paid', 'cancelled', 'draft'], true) && $total > 0 && abs($paid - $total) < .005) {
                    $proposals[] = $evidence + ['field' => 'status', 'proposed' => 'paid', 'financial_impact' => 'No new payments. Status follows existing active payments.', 'inventory_impact' => 'None. No delivery or stock movement proposed.', 'requires_approval' => true];
                }
                if ($paid < $total && $paid > 0) $issues[] = $evidence + ['issue' => 'Partially paid: retain partial status; no Paid correction proposed'];
            }
            if (($record['table'] ?? '') === 'purchases' && isset($record['id'])) {
                $purchase = DB::table('purchases')->where('id', $record['id'])->first();
                if (! $purchase) { $issues[] = ['record' => $record, 'issue' => 'Missing imported purchase']; continue; }
                foreach (DB::table('purchase_items')->where('purchase_id', $purchase->id)->get() as $item) {
                    $lifted = (float) DB::table('hauls')->where('purchase_item_id', $item->id)->whereNull('deleted_at')->whereIn('status', ['lifted', 'in_transit', 'completed'])->sum('quantity_liters');
                    if ($purchase->status === 'hauled' && $lifted + .005 < (float) $item->quantity_ordered_liters) $issues[] = ['record' => $record, 'code' => $purchase->purchase_code, 'issue' => 'Completed purchase has insufficient lifting evidence', 'ordered_liters' => (float) $item->quantity_ordered_liters, 'lifted_liters' => $lifted];
                }
            }
            if (in_array($record['table'] ?? '', ['hauls', 'lifting_schedules'], true)) {
                $table = $record['table'];
                $row = isset($record['id']) ? DB::table($table)->where('id', $record['id'])->first() : DB::table($table)->where('haul_code', $record['code'])->first();
                if (! $row || ! DB::table('trucks')->where('id', $row->truck_id)->exists() || ! DB::table('depots')->where('id', $row->depot_id)->exists()) $issues[] = ['record' => $record, 'issue' => 'Missing lifting record, truck or depot'];
                if ($row && $table === 'hauls' && ! DB::table('purchase_items')->where('id', $row->purchase_item_id)->where('purchase_id', $row->purchase_id)->exists()) $issues[] = ['record' => $record, 'issue' => 'Lifting purchase relationship does not reconcile'];
            }
        }

        return ['batch_id' => $batch->id, 'filename' => $batch->original_filename, 'dataset_kind' => $kind, 'tracked_records' => count($records), 'provenance_note' => $records === [] ? 'Legacy batch has no exact record provenance. Supply the source dataset and approved mock IDs before proposing corrections.' : 'Only exact records captured by this import are reviewed. Dataset kind is the uploader declaration, not proof of approval.', 'issues' => $issues, 'proposals' => $proposals, 'applied_changes' => 0, 'approval_required' => true];
    }
}
