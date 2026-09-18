<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WorkflowAlertService
{
    public function purchase(int $purchaseId, string $event, ?int $actorId = null, ?int $haulId = null): void
    {
        $purchase = DB::table('purchases')
            ->join('depots', 'depots.id', '=', 'purchases.depot_id')
            ->leftJoin('users', 'users.id', '=', 'purchases.created_by')
            ->where('purchases.id', $purchaseId)
            ->first([
                'purchases.id',
                'purchases.purchase_code',
                'purchases.purchase_date',
                'purchases.workflow_status',
                'depots.name as depot_name',
                'users.name as created_by_name',
            ]);

        if (! $purchase) {
            return;
        }

        $items = DB::table('purchase_items')
            ->join('fuel_types', 'fuel_types.id', '=', 'purchase_items.fuel_type_id')
            ->where('purchase_items.purchase_id', $purchaseId)
            ->selectRaw('GROUP_CONCAT(fuel_types.name) as fuel_names, COALESCE(SUM(purchase_items.quantity_ordered_liters), 0) as quantity_liters')
            ->first();
        $deduplicationKey = 'purchase:'.$purchaseId.':'.$event.($haulId ? ':haul:'.$haulId : '');

        if (DB::table('alerts')->where('deduplication_key', $deduplicationKey)->exists()) {
            return;
        }

        $status = str_replace('_', ' ', (string) $purchase->workflow_status);
        $message = sprintf(
            '%s | Fuel: %s | Quantity: %s L | Depot: %s | Purchase date: %s | Status: %s | Created by: %s',
            $purchase->purchase_code,
            $items?->fuel_names ?: 'N/A',
            number_format((float) ($items?->quantity_liters ?? 0), 2),
            $purchase->depot_name,
            $purchase->purchase_date,
            ucwords($status),
            $purchase->created_by_name ?: 'N/A'
        );

        DB::table('alerts')->insertOrIgnore([
            'alert_code' => $this->nextCode(),
            'deduplication_key' => $deduplicationKey,
            'type' => $haulId ? 'haul' : 'purchase',
            'severity' => in_array($event, ['cancelled', 'failed'], true) ? 'critical' : 'info',
            'title' => $purchase->purchase_code.' '.ucwords(str_replace('_', ' ', $event)),
            'message' => $message,
            'reference_type' => $haulId ? 'haul' : 'purchase',
            'reference_id' => $haulId ?: $purchaseId,
            'action_url' => $haulId ? route('admin.fuel-lifting') : route('admin.inventory'),
            'status' => 'open',
            'assigned_to' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function nextCode(): string
    {
        do {
            $code = 'ALT-'.now()->format('ymd').'-'.Str::upper(Str::random(5));
        } while (DB::table('alerts')->where('alert_code', $code)->exists());

        return $code;
    }
}
