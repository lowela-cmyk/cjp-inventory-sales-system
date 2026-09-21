<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TruckAvailabilityService
{
    public const ACTIVE_HAUL_STATUSES = ['scheduled', 'in_transit', 'lifted'];

    public const MANAGED_STATUSES = ['available', 'maintenance', 'inactive'];

    public function assignableTrucks(): Collection
    {
        return DB::table('trucks')
            ->whereIn('truck_type', ['hauling', 'mixed'])
            ->whereNotIn('status', ['maintenance', 'inactive'])
            ->whereNotExists(fn (Builder $query): Builder => $this->activeHaulSubquery($query))
            ->orderByRaw('COALESCE(plate_number, truck_code)')
            ->get(['id', 'truck_code', 'plate_number', 'name', 'capacity_liters'])
            ->each(fn (object $truck) => $truck->effective_status = 'available');
    }

    public function lockAssignableTruck(
        int $truckId,
        ?int $exceptHaulId = null,
        ?int $exceptLiftingScheduleId = null
    ): ?object {
        $truck = DB::table('trucks')
            ->where('id', $truckId)
            ->whereIn('truck_type', ['hauling', 'mixed'])
            ->whereNotIn('status', ['maintenance', 'inactive'])
            ->lockForUpdate()
            ->first(['id', 'truck_code', 'plate_number', 'name', 'capacity_liters', 'status']);

        if (! $truck || $this->hasActiveAssignment($truckId, $exceptHaulId, $exceptLiftingScheduleId, true)) {
            return null;
        }

        return $truck;
    }

    public function hasActiveAssignment(
        int $truckId,
        ?int $exceptHaulId = null,
        ?int $exceptLiftingScheduleId = null,
        bool $lock = false
    ): bool {
        $query = DB::table('hauls')
            ->where('truck_id', $truckId)
            ->whereIn('status', self::ACTIVE_HAUL_STATUSES)
            ->when($exceptHaulId, fn (Builder $query, int $id): Builder => $query->where('id', '!=', $id))
            ->when($exceptLiftingScheduleId, fn (Builder $query, int $id): Builder => $query->where(function (Builder $query) use ($id): void {
                $query->whereNull('lifting_schedule_id')->orWhere('lifting_schedule_id', '!=', $id);
            }));

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->exists();
    }

    public function effectiveStatus(int $truckId): ?string
    {
        $truck = DB::table('trucks')->where('id', $truckId)->first(['id', 'status']);

        if (! $truck) {
            return null;
        }

        if (in_array($truck->status, ['maintenance', 'inactive'], true)) {
            return $truck->status;
        }

        if (DB::table('hauls')->where('truck_id', $truckId)->whereIn('status', ['in_transit', 'lifted'])->exists()) {
            return 'in_use';
        }

        if (DB::table('hauls')->where('truck_id', $truckId)->where('status', 'scheduled')->exists()) {
            return 'assigned';
        }

        return 'available';
    }

    public function synchronizeStatus(int $truckId): void
    {
        $truck = DB::table('trucks')->where('id', $truckId)->lockForUpdate()->first(['id', 'status']);

        if (! $truck || in_array($truck->status, ['maintenance', 'inactive'], true)) {
            return;
        }

        $status = $this->effectiveStatus($truckId) ?? 'available';

        DB::table('trucks')->where('id', $truckId)->update([
            'status' => $status,
            'updated_at' => now(),
        ]);
    }

    private function activeHaulSubquery(Builder $query): Builder
    {
        return $query->selectRaw('1')
            ->from('hauls')
            ->whereColumn('hauls.truck_id', 'trucks.id')
            ->whereIn('hauls.status', self::ACTIVE_HAUL_STATUSES);
    }
}
