<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The header notification bell (Part 8): unread alerts the user can see on their Alerts page.
 * The bell component runs one COUNT query per page.
 */
class AlertBellService
{
    /** Alerts page and visible alert types per role; null types = every type. Drivers have no Alerts page. */
    public const ROLE_ALERTS = [
        'admin' => ['route' => 'admin.alerts', 'types' => null],
        'inventory_officer' => ['route' => 'inventory-officer.alerts', 'types' => ['inventory', 'purchase', 'haul', 'discrepancy']],
        // Part 12: Sales also sees order alerts (Depot to Client order not on a lift, partially delivered).
        'sales_officer' => ['route' => 'sales-officer.alerts', 'types' => ['payment', 'receivable', 'delivery']],
        'dispatch_officer' => ['route' => 'dispatch.alerts', 'types' => ['haul', 'delivery']],
    ];

    public function hasBell(?User $user): bool
    {
        return $user !== null && isset(self::ROLE_ALERTS[(string) $user->role]);
    }

    public function alertsRoute(User $user): string
    {
        return self::ROLE_ALERTS[(string) $user->role]['route'];
    }

    public function unreadCount(User $user): int
    {
        if (! $this->hasBell($user)) {
            return 0;
        }

        // Part 12: computed alerts are brought up to date first (a no-op unless the data changed).
        app(AlertSyncService::class)->sync();

        return (int) DB::table('alerts')
            ->where('alerts.status', '!=', 'resolved')
            ->when(self::ROLE_ALERTS[$user->role]['types'], fn ($query, array $types) => $query->whereIn('alerts.type', $types))
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('alert_reads')
                ->whereColumn('alert_reads.alert_id', 'alerts.id')
                ->where('alert_reads.user_id', $user->getKey()))
            ->count();
    }

    /** Badge text for a count: hidden at 0 (null), "99+" above 99. */
    public function badge(int $count): ?string
    {
        return match (true) {
            $count <= 0 => null,
            $count > 99 => '99+',
            default => (string) $count,
        };
    }
}
