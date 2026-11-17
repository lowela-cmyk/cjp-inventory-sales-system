<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Evidence only. Candidates are never treated as approved allocations or posted movements. */
final class InventoryReconciliationReportService
{
    public function report(): array
    {
        $items = DB::table('sale_items')->get()->keyBy('id');
        $sales = DB::table('sales')->whereNull('deleted_at')->get()->keyBy('id');
        $releases = DB::table('stock_outs')->where('status', 'released')->get();
        $byItem = $releases->groupBy('sale_item_id');
        $byPurchase = PurchaseStockService::releasesByPurchaseQuery()->get()->groupBy('purchase_item_id');
        $hauls = DB::table('hauls')->whereNull('deleted_at')->get()->groupBy('purchase_item_id');
        $allocations = DB::table('haul_allocations')->where('status', '!=', 'cancelled')->get()->groupBy('haul_id');
        $trucks = DB::table('trucks')->pluck('truck_code', 'id');
        $lines = app(PurchaseStockService::class)->lines();
        $deliveryProblems = [];
        foreach ($items as $item) {
            $sale = $sales->get($item->sale_id);
            if (! $sale || $sale->delivery_status !== 'delivered' || $sale->status === 'cancelled') continue;
            $actual = (float) $byItem->get($item->id, collect())->sum('quantity_liters');
            if (abs((float) $item->quantity_liters - $actual) <= .005) continue;
            $line = $lines->get($item->purchase_item_id);
            $candidates = $line ? $hauls->get($line->id, collect()) : collect();
            $deliveryProblems[] = [
                'sale_id' => $sale->id, 'sale_code' => $sale->sale_code, 'sales_order_number' => $sale->sales_order_number,
                'sale_item_id' => $item->id, 'sale_date' => $sale->sale_date, 'fuel_type_id' => $item->fuel_type_id,
                'delivery_type' => $sale->delivery_type, 'delivery_status' => $sale->delivery_status,
                'ordered_liters' => (float) $item->quantity_liters, 'stored_fulfilled_liters' => (float) $item->fulfilled_quantity_liters,
                'released_liters' => $actual, 'unreconciled_liters' => round((float) $item->quantity_liters - $actual, 2),
                'source_purchase_item_id' => $item->purchase_item_id, 'source_purchase_code' => $line?->purchase_code,
                'linked_stock_out_ids' => $byItem->get($item->id, collect())->pluck('id')->all(),
                'delivery_truck_id' => $sale->delivery_truck_id, 'delivery_truck_code' => $trucks[$sale->delivery_truck_id] ?? null,
                'candidate_haul_ids' => $candidates->pluck('id')->all(),
                'classification' => $item->purchase_item_id ? 'Historical delivery without sufficient release evidence' : 'Historical delivery with missing source attribution and release evidence',
                'proposed_action' => 'Review original delivery receipt, source purchase/withdrawal and truck trip. Link only an evidenced existing movement, or submit an approved historical correction. No release or quantity change is inferred.',
                'executable_correction' => false, 'requires_approval' => true, 'financial_changes_proposed' => 0,
            ];
        }
        $inventoryProblems = [];
        foreach ($lines as $line) {
            if (! $line->requires_reconciliation) continue;
            $lifts = $hauls->get($line->id, collect());
            $releaseRows = $byPurchase->get($line->id, collect());
            $candidateDepot = round($line->ordered - $line->lifted, 2);
            $candidateTruck = round($line->lifted - $line->released - $line->in_garage, 2);
            $arithmeticValid = $candidateDepot >= 0 && $candidateTruck >= 0 && abs($candidateDepot + $candidateTruck + $line->in_garage + $line->released - $line->ordered) <= .005;
            $inventoryProblems[] = [
                'purchase_id' => $line->purchase_id, 'purchase_item_id' => $line->id, 'purchase_code' => $line->purchase_code,
                'fuel_type_id' => $line->fuel_type_id, 'purchased_liters' => $line->ordered,
                'hauled_including_scheduled_liters' => $line->lifted, 'released_liters' => $line->released,
                'returned_liters' => (float) $lifts->sum('returned_liters'),
                'depot_liters' => $line->remaining_in_depot, 'raw_on_truck_liters' => $line->on_truck,
                'garage_liters' => $line->in_garage, 'book_on_hand_liters' => $line->on_hand,
                'conservation_difference_liters' => $line->conservation_difference_liters,
                'haul_records' => $lifts->map(fn ($h) => [
                    'haul_id' => $h->id, 'code' => $h->haul_code, 'schedule_id' => $h->lifting_schedule_id,
                    'truck_id' => $h->truck_id, 'truck_code' => $trucks[$h->truck_id] ?? null,
                    'quantity_liters' => (float) $h->quantity_liters, 'status' => $h->status,
                    'existing_allocation_ids' => $allocations->get($h->id, collect())->pluck('id')->all(),
                ])->values()->all(),
                'existing_stock_out_ids' => $releaseRows->pluck('stock_out_id')->unique()->values()->all(),
                'classification' => $line->lifted > $line->ordered + .005 ? 'Source quantity conflict; no safe quantity correction' : 'Historical releases and lifts lack corroborated allocation links',
                'proposed_action' => 'Confirm the actual haul and release documents before proposing relationship links. Preserve all original quantities, payments, costs and dates. Do not assign a truck by FIFO inference.',
                'conditional_validation' => [
                    'assumption' => 'Only if documents prove these existing releases left these existing lifts, with no additional unloads/returns beyond the listed records.',
                    'depot_liters' => $candidateDepot, 'truck_liters' => $candidateTruck,
                    'quantity_conservation_passes_under_assumption' => $arithmeticValid,
                    'existing_purchase_and_release_ids_resolve' => true,
                    'specific_release_to_haul_relationship_proven' => false,
                ],
                'executable_correction' => false, 'requires_approval' => true, 'financial_changes_proposed' => 0,
            ];
        }

        return [
            'database' => DB::connection()->getDatabaseName(), 'generated_at' => now()->toIso8601String(),
            'read_only' => true, 'applied_changes' => 0,
            'summary' => ['delivery_items' => count($deliveryProblems), 'delivery_gap_liters' => array_sum(array_column($deliveryProblems, 'unreconciled_liters')), 'purchase_lines' => count($inventoryProblems)],
            'delivery_reconciliation' => $deliveryProblems, 'inventory_reconciliation' => $inventoryProblems,
            'approval_rule' => 'Every row is evidence for review, not an executable correction. Approval alone does not establish missing source evidence. The quantity-conflicted record must remain unchanged until authoritative documents resolve it.',
        ];
    }
}
