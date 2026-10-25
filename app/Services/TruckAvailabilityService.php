<?php

namespace App\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TruckAvailabilityService
{
    /**
     * Lift states that keep a truck busy. "lifted" is the driver's final step (the lift is done), and
     * completed / cancelled lifts never hold a truck, so the truck is free for the next pickup.
     */
    public const ACTIVE_HAUL_STATUSES = ['scheduled', 'in_transit'];

    public const MANAGED_STATUSES = ['available', 'maintenance', 'inactive'];

    /** Every availability a truck can show: the managed statuses plus the ones derived from active lifts. */
    public const DISPLAY_STATUSES = ['available', 'assigned', 'in_use', 'maintenance', 'inactive'];

    /**
     * Next automatic truck code: TRK-NNN after the highest existing TRK number (Part 8 #7).
     * Callers insert with IdempotencyService::retryOnCollision('truck_code', …): the unique index on
     * trucks.truck_code turns a simultaneous create into a retry that picks the following number.
     */
    public function nextTruckCode(): string
    {
        $highest = DB::table('trucks')
            ->where('truck_code', 'like', 'TRK-%')
            ->pluck('truck_code')
            ->map(fn (string $code): int => preg_match('/^TRK-(\d+)$/', $code, $match) ? (int) $match[1] : 0)
            ->max() ?? 0;

        return 'TRK-'.str_pad((string) ($highest + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * The fleet list (Fuel Lifting > Trucks tab) with each truck's live availability and lift counts.
     */
    public function directory(string $search = '', ?string $status = null, int $perPage = 20): LengthAwarePaginator
    {
        $activeLifts = DB::table('hauls')
            ->whereIn('status', self::ACTIVE_HAUL_STATUSES)
            ->selectRaw("truck_id, COUNT(*) as active_lift_count, MAX(CASE WHEN status = 'in_transit' THEN 1 ELSE 0 END) as has_in_use, MIN(scheduled_at) as next_schedule")
            ->groupBy('truck_id');

        $effectiveStatusSql = "CASE WHEN trucks.status IN ('maintenance', 'inactive') THEN trucks.status WHEN COALESCE(active_lifts.has_in_use, 0) > 0 THEN 'in_use' WHEN COALESCE(active_lifts.active_lift_count, 0) > 0 THEN 'assigned' ELSE 'available' END";

        return DB::table('trucks')
            ->leftJoinSub($activeLifts, 'active_lifts', 'active_lifts.truck_id', '=', 'trucks.id')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('trucks.truck_code', 'like', '%'.$search.'%')
                        ->orWhere('trucks.plate_number', 'like', '%'.$search.'%')
                        ->orWhere('trucks.name', 'like', '%'.$search.'%')
                        ->orWhere('trucks.description', 'like', '%'.$search.'%');
                });
            })
            ->when($status, fn (Builder $query, string $status): Builder => $query->whereRaw($effectiveStatusSql.' = ?', [$status]))
            ->orderByRaw('COALESCE(trucks.plate_number, trucks.truck_code)')
            ->select([
                'trucks.*',
                DB::raw('COALESCE(active_lifts.active_lift_count, 0) as active_lift_count'),
                'active_lifts.has_in_use',
                'active_lifts.next_schedule',
                DB::raw($effectiveStatusSql.' as effective_status'),
            ])
            ->paginate($perPage, ['*'], 'trucks_page')
            ->withQueryString();
    }

    public function assignableTrucks(): Collection
    {
        // Part 11: any truck can lift or deliver (truck types are retired); only its status and current lifts count.
        return DB::table('trucks')
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

        if (DB::table('hauls')->where('truck_id', $truckId)->where('status', 'in_transit')->exists()) {
            return 'in_use';
        }

        if (DB::table('hauls')->where('truck_id', $truckId)->where('status', 'scheduled')->exists()) {
            return 'assigned';
        }

        return 'available';
    }

    /**
     * Trucks whose stored status no longer matches their lifts (e.g. still "in use" after the lift was
     * finished). Read-only; synchronizeStatus() fixes each one.
     *
     * @return Collection<int, array{truck_id: int, truck_code: string, stored_status: string, correct_status: string, lifts: string}>
     */
    public function statusDrift(): Collection
    {
        return DB::table('trucks')
            ->whereNotIn('status', ['maintenance', 'inactive'])
            ->orderBy('id')
            ->get(['id', 'truck_code', 'status'])
            ->map(fn (object $truck): array => [
                'truck_id' => (int) $truck->id,
                'truck_code' => (string) $truck->truck_code,
                'stored_status' => (string) $truck->status,
                'correct_status' => (string) $this->effectiveStatus((int) $truck->id),
                'lifts' => DB::table('hauls')->where('truck_id', $truck->id)->whereIn('status', ['scheduled', 'in_transit', 'lifted'])
                    ->get(['haul_code', 'status'])->map(fn (object $haul): string => $haul->haul_code.' ('.$haul->status.')')->implode(', '),
            ])
            ->filter(fn (array $row): bool => $row['stored_status'] !== $row['correct_status'])
            ->values();
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
