<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class PurchaseWorkflowService
{
    public function __construct(private readonly WorkflowAlertService $alerts) {}

    public function transition(int $purchaseId, string $newStatus, ?int $userId = null, ?int $haulId = null): void
    {
        $purchase = DB::table('purchases')->where('id', $purchaseId)->lockForUpdate()->first(['id', 'workflow_status']);

        if (! $purchase || $purchase->workflow_status === $newStatus) {
            return;
        }

        DB::table('purchases')->where('id', $purchaseId)->update([
            'workflow_status' => $newStatus,
            'updated_at' => now(),
        ]);

        DB::table('purchase_status_histories')->insert([
            'purchase_id' => $purchaseId,
            'previous_status' => $purchase->workflow_status,
            'new_status' => $newStatus,
            'changed_by' => $userId,
            'changed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->alerts->purchase($purchaseId, $newStatus, $userId, $haulId);
    }

    public function synchronize(int $purchaseId, ?int $userId = null, ?int $haulId = null): void
    {
        $purchase = DB::table('purchases')->where('id', $purchaseId)->lockForUpdate()->first(['id', 'status']);
        if (! $purchase) {
            return;
        }

        if ($purchase->status === 'cancelled') {
            $this->transition($purchaseId, 'cancelled', $userId, $haulId);

            return;
        }

        $hauls = DB::table('hauls')->where('purchase_id', $purchaseId)->where('status', '!=', 'cancelled')->get(['status', 'quantity_liters']);
        $ordered = (float) DB::table('purchase_items')->where('purchase_id', $purchaseId)->sum('quantity_ordered_liters');
        $lifted = (float) $hauls->whereIn('status', ['lifted', 'completed'])->sum('quantity_liters');
        $completed = (float) $hauls->where('status', 'completed')->sum('quantity_liters');

        $newStatus = match (true) {
            $hauls->contains('status', 'in_transit') => 'in_transit',
            $lifted > 0 && round($lifted, 2) < round($ordered, 2) => 'partially_lifted',
            $completed > 0 && round($completed, 2) >= round($ordered, 2) => 'completed',
            $lifted > 0 && round($lifted, 2) >= round($ordered, 2) => 'in_transit',
            $hauls->isNotEmpty() => 'scheduled',
            default => 'pending',
        };

        $this->transition($purchaseId, $newStatus, $userId, $haulId);
    }
}
