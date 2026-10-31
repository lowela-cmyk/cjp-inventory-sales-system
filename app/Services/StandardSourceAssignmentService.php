<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StandardSourceAssignmentService
{
    private const OPEN_STATUSES = ['confirmed', 'partially_paid', 'paid', 'unpaid'];

    public function __construct(
        private readonly SaleConfirmationService $saleConfirmation,
        private readonly TruckAvailabilityService $truckAvailability
    ) {}

    public function options(int $saleId): array
    {
        $sale = $this->sale($saleId);
        if (! $sale || $sale->delivery_type !== 'standard' || in_array($sale->delivery_status, ['delivered', 'cancelled'], true)) {
            return ['scheduled' => [], 'truck' => [], 'garage' => []];
        }

        $items = DB::table('sale_items')->where('sale_id', $saleId)->get(['fuel_type_id', 'quantity_liters', 'fulfilled_quantity_liters']);
        $fuelIds = $items->pluck('fuel_type_id')->map(fn (mixed $id): int => (int) $id)->unique();
        if ($fuelIds->count() !== 1) {
            return ['scheduled' => [], 'truck' => [], 'garage' => []];
        }
        $fuelId = (int) $fuelIds->first();
        $needed = round((float) $items->sum(fn (object $item): float => (float) $item->quantity_liters - (float) $item->fulfilled_quantity_liters), 2);

        return [
            'scheduled' => $this->scheduledOptions($fuelId, $needed),
            'truck' => $this->truckOptions($fuelId, $needed),
            'garage' => $this->garageOptions($fuelId, $needed),
        ];
    }

    public function assign(int $saleId, string $sourceType, int $sourceId, int $userId): void
    {
        DB::transaction(function () use ($saleId, $sourceType, $sourceId, $userId): void {
            $sale = DB::table('sales')->where('id', $saleId)->whereNull('deleted_at')->lockForUpdate()->first();
            if (! $sale || ! in_array($sale->status, self::OPEN_STATUSES, true) || in_array($sale->delivery_status, ['delivered', 'cancelled'], true)) {
                throw ValidationException::withMessages(['source' => 'Only an open, undelivered Standard order can be assigned a source.']);
            }
            if ($sale->delivery_type !== 'standard') {
                throw ValidationException::withMessages(['source' => 'This order already has a source assigned.']);
            }

            $items = DB::table('sale_items')->where('sale_id', $saleId)->lockForUpdate()->get();
            $fuelIds = $items->pluck('fuel_type_id')->map(fn (mixed $id): int => (int) $id)->unique();
            if ($fuelIds->count() !== 1) {
                throw ValidationException::withMessages(['source' => 'This order must contain one fuel type before a source can be assigned.']);
            }
            $needed = round((float) $items->sum(fn (object $item): float => (float) $item->quantity_liters - (float) $item->fulfilled_quantity_liters), 2);
            $source = $this->resolveSource($sourceType, $sourceId, (int) $fuelIds->first(), $needed);

            foreach ($items as $item) {
                $updates = ['updated_at' => now()];
                if ($sourceType === 'garage_lot') {
                    $updates['purchase_item_id'] = $source->purchase_item_id;
                    $updates['garage_stock_id'] = $source->id;
                } else {
                    $updates['purchase_item_id'] = $source->purchase_item_id;
                }
                DB::table('sale_items')->where('id', $item->id)->update($updates);
            }

            if (in_array($sourceType, ['scheduled_lift', 'truck'], true)) {
                DB::table('haul_allocations')->insert([
                    'haul_id' => (int) $source->haul_id,
                    'fuel_type_id' => (int) $fuelIds->first(),
                    'destination_type' => 'customer',
                    'customer_id' => (int) $sale->customer_id,
                    'sale_id' => $saleId,
                    'quantity_liters' => $needed,
                    'allocated_at' => now(),
                    'status' => 'planned',
                    'receive_on_completion' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $toType = $sourceType === 'garage_lot' ? 'garage_to_client' : 'depot_to_client';
            DB::table('sales')->where('id', $saleId)->update([
                'delivery_type' => $toType,
                'updated_by' => $userId,
                'updated_at' => now(),
            ]);

            $this->recordChange($saleId, $toType, $sourceType, $source->haul_id ?? null, $source->garage_stock_id ?? null, $source->source_label, $userId);

            if ($sourceType === 'truck') {
                $haul = DB::table('hauls')->where('id', $source->haul_id)->first(['truck_id', 'driver_user_id']);
                DB::table('sales')->where('id', $saleId)->update([
                    'delivery_truck_id' => $haul->truck_id,
                    'delivery_driver_user_id' => $haul->driver_user_id,
                    'delivery_date' => now(),
                    'updated_at' => now(),
                ]);
                $this->saleConfirmation->updateDeliveryStatus($saleId, 'delivered', $userId);
                $this->truckAvailability->synchronizeStatus((int) $haul->truck_id);
            }
        });
    }

    public function convertForHaul(int $saleId, int $haulId, int $userId): void
    {
        $sale = DB::table('sales')->where('id', $saleId)->whereNull('deleted_at')->lockForUpdate()->first();
        if (! $sale || $sale->delivery_type !== 'standard') {
            return;
        }
        if (! in_array($sale->status, self::OPEN_STATUSES, true) || in_array($sale->delivery_status, ['delivered', 'cancelled'], true)) {
            throw ValidationException::withMessages(['sale_id' => 'Only an open Standard order can be added to a lift.']);
        }
        $haul = DB::table('hauls')->where('id', $haulId)->whereNull('deleted_at')->lockForUpdate()->first(['id', 'haul_code', 'purchase_item_id', 'fuel_type_id', 'quantity_liters', 'status']);
        if (! $haul) {
            throw ValidationException::withMessages(['sale_id' => 'The selected lift no longer exists.']);
        }
        $items = DB::table('sale_items')->where('sale_id', $saleId)->lockForUpdate()->get();
        $needed = round((float) $items->sum(fn (object $item): float => (float) $item->quantity_liters - (float) $item->fulfilled_quantity_liters), 2);
        $fuelIds = $items->pluck('fuel_type_id')->map(fn (mixed $id): int => (int) $id)->unique();
        if ($fuelIds->count() !== 1 || (int) $fuelIds->first() !== (int) $haul->fuel_type_id) {
            throw ValidationException::withMessages(['sale_id' => 'The lift fuel does not match this Standard order.']);
        }
        $used = (float) DB::table('haul_allocations')
            ->where('haul_id', $haulId)
            ->where('status', '!=', 'cancelled')
            ->where(fn ($query) => $query->whereNull('sale_id')->orWhere('sale_id', '!=', $saleId))
            ->sum('quantity_liters');
        if (round((float) $haul->quantity_liters - $used, 2) < $needed) {
            throw ValidationException::withMessages(['sale_id' => 'The selected lift does not have enough room for this order.']);
        }
        foreach ($items as $item) {
            DB::table('sale_items')->where('id', $item->id)->update(['purchase_item_id' => $haul->purchase_item_id, 'updated_at' => now()]);
        }
        DB::table('sales')->where('id', $saleId)->update(['delivery_type' => 'depot_to_client', 'updated_by' => $userId, 'updated_at' => now()]);
        $this->recordChange($saleId, 'depot_to_client', 'scheduled_lift', $haulId, null, $haul->haul_code, $userId);
    }

    private function resolveSource(string $sourceType, int $sourceId, int $fuelId, float $needed): object
    {
        if ($sourceType === 'scheduled_lift') {
            $source = $this->scheduledSource($sourceId, $fuelId, $needed);
            if (! $source) {
                throw ValidationException::withMessages(['source' => 'That Scheduled lift does not carry enough of this fuel.']);
            }
            return $source;
        }
        if ($sourceType === 'truck') {
            $source = $this->truckSource($sourceId, $fuelId, $needed);
            if (! $source) {
                throw ValidationException::withMessages(['source' => 'That truck does not have enough of this fuel left.']);
            }
            return $source;
        }
        if ($sourceType === 'garage_lot') {
            $source = app(GarageStockService::class)->lots(['with_empty' => true, 'fuel_type_id' => $fuelId], null, [$sourceId], true)->get($sourceId);
            if (! $source || (float) $source->available < $needed) {
                throw ValidationException::withMessages(['source' => 'That garage lot does not have enough of this fuel available.']);
            }
            $source->purchase_item_id = (int) $source->purchase_item_id;
            $source->garage_stock_id = (int) $source->id;
            $source->source_label = $source->tank_name.' / '.$source->lot_code;
            return $source;
        }
        throw ValidationException::withMessages(['source' => 'Select a valid source.']);
    }

    private function scheduledOptions(int $fuelId, float $needed): array
    {
        return $this->liftOptions($fuelId, 'scheduled', $needed)->map(fn (object $row): array => [
            'id' => (int) $row->haul_id,
            'label' => $row->haul_code.' · '.number_format($row->room, 2).' L available',
        ])->all();
    }

    private function truckOptions(int $fuelId, float $needed): array
    {
        return $this->liftOptions($fuelId, ['lifted', 'completed'], $needed)->map(fn (object $row): array => [
            'id' => (int) $row->haul_id,
            'label' => $row->haul_code.' · '.number_format($row->room, 2).' L left on truck',
        ])->all();
    }

    private function garageOptions(int $fuelId, float $needed): array
    {
        return app(GarageStockService::class)->saleOptions([], $fuelId)->filter(fn (object $lot): bool => (float) $lot->available >= $needed)->map(fn (object $lot): array => [
            'id' => (int) $lot->id,
            'label' => $lot->tank_name.' / '.$lot->lot_code.' · '.number_format((float) $lot->available, 2).' L available',
        ])->all();
    }

    private function liftOptions(int $fuelId, string|array $statuses, float $needed): Collection
    {
        $rows = DB::table('hauls')
            ->leftJoin('haul_allocations', function ($join): void {
                $join->on('haul_allocations.haul_id', '=', 'hauls.id')->where('haul_allocations.status', '!=', 'cancelled');
            })
            ->whereNull('hauls.deleted_at')->whereIn('hauls.status', (array) $statuses)->where('hauls.fuel_type_id', $fuelId)
            ->groupBy('hauls.id', 'hauls.haul_code', 'hauls.quantity_liters')
            ->selectRaw('hauls.id as haul_id, hauls.haul_code, hauls.quantity_liters - COALESCE(SUM(haul_allocations.quantity_liters), 0) as room')
            ->get();

        return $rows->filter(fn (object $row): bool => (float) $row->room >= $needed)->values();
    }

    private function scheduledSource(int $haulId, int $fuelId, float $needed, bool $allowLifted = false): ?object
    {
        $statuses = $allowLifted ? ['scheduled', 'lifted', 'completed'] : ['scheduled'];
        return $this->sourceFromHaul($haulId, $fuelId, $needed, $statuses);
    }

    private function truckSource(int $haulId, int $fuelId, float $needed, bool $allowScheduled = false): ?object
    {
        return $this->sourceFromHaul($haulId, $fuelId, $needed, $allowScheduled ? ['scheduled', 'lifted', 'completed'] : ['lifted', 'completed']);
    }

    private function sourceFromHaul(int $haulId, int $fuelId, float $needed, array $statuses): ?object
    {
        $row = DB::table('hauls')->where('hauls.id', $haulId)->whereNull('hauls.deleted_at')->whereIn('hauls.status', $statuses)->where('hauls.fuel_type_id', $fuelId)->first(['hauls.id as haul_id', 'hauls.haul_code', 'hauls.purchase_item_id', 'hauls.quantity_liters']);
        if (! $row) return null;
        $usedQuery = DB::table('haul_allocations')->where('haul_id', $haulId)->where('status', '!=', 'cancelled');
        $used = (float) $usedQuery->sum('quantity_liters');
        $row->room = round((float) $row->quantity_liters - $used, 2);
        if ($row->room < $needed) return null;
        $row->source_label = $row->haul_code;
        return $row;
    }

    private function recordChange(int $saleId, string $toType, string $sourceType, ?int $haulId, ?int $garageStockId, string $sourceLabel, int $userId): void
    {
        DB::table('sale_source_changes')->insert([
            'sale_id' => $saleId,
            'from_delivery_type' => 'standard',
            'to_delivery_type' => $toType,
            'source_type' => $sourceType,
            'haul_id' => $haulId,
            'garage_stock_id' => $garageStockId,
            'source_label' => $sourceLabel,
            'changed_by' => $userId,
            'changed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function sale(int $saleId): ?object
    {
        return DB::table('sales')->where('id', $saleId)->whereNull('deleted_at')->first();
    }
}
