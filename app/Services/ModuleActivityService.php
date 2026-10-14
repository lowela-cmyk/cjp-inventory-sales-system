<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sidebar red dots: a module gets a dot when one of its records was created or updated after the
 * user last opened that module. Each module costs one query (the MAX(updated_at) of its tables).
 * Every user starts with all their modules marked as seen (on creation, approval and role change),
 * so nobody gets dots for history they never had a chance to see.
 */
class ModuleActivityService
{
    /**
     * Module => the routes that count as opening it and the tables whose changes it shows.
     *
     * @var array<string, array{routes: array<int, string>, tables: array<int, string>}>
     */
    private const MODULES = [
        'inventory' => [
            'routes' => ['admin.inventory', 'admin.inventory.*', 'inventory-officer.inventory', 'inventory-officer.inventory.*'],
            'tables' => ['purchases', 'purchase_items', 'haul_allocations', 'stock_outs', 'depots'],
        ],
        'ledger' => [
            'routes' => ['admin.ledger', 'admin.ledger.*', 'inventory-officer.ledger', 'inventory-officer.ledger.*', 'dispatch.ledger', 'dispatch.ledger.*'],
            'tables' => ['purchase_items', 'hauls'],
        ],
        'fuel-lifting' => [
            'routes' => ['admin.fuel-lifting', 'admin.fuel-lifting.*', 'dispatch.fuel-lifting', 'dispatch.fuel-lifting.*', 'driver.fuel-lifting', 'driver.fuel-lifting.*'],
            'tables' => ['lifting_schedules', 'hauls', 'trucks'],
        ],
        'sales' => [
            'routes' => ['admin.sales', 'admin.sales.*', 'sales-officer.sales', 'sales-officer.sales.*'],
            'tables' => ['sales', 'payments', 'customers'],
        ],
        'alerts' => [
            'routes' => ['admin.alerts', 'inventory-officer.alerts', 'sales-officer.alerts', 'dispatch.alerts'],
            'tables' => ['alerts'],
        ],
        'account-requests' => [
            'routes' => ['admin.account-requests'],
            'tables' => ['users'],
        ],
        'user-management' => [
            'routes' => ['admin.user-management'],
            'tables' => ['users', 'driver_profiles', 'customers'],
        ],
    ];

    /** Sidebar modules each role can open. */
    public const ROLE_MODULES = [
        'admin' => ['inventory', 'ledger', 'fuel-lifting', 'sales', 'alerts', 'account-requests', 'user-management'],
        'inventory_officer' => ['inventory', 'ledger', 'alerts'],
        'sales_officer' => ['sales', 'alerts'],
        'dispatch_officer' => ['ledger', 'fuel-lifting', 'alerts'],
        'driver' => ['fuel-lifting'],
    ];

    /** Alert types each role sees (matches the role alert pages). */
    private const ROLE_ALERT_TYPES = [
        'inventory_officer' => ['inventory', 'purchase', 'haul', 'discrepancy'],
        'sales_officer' => ['payment', 'receivable'],
        'dispatch_officer' => ['haul', 'delivery'],
    ];

    /** @var array<int, array<int, string>> */
    private array $unseenCache = [];

    public function moduleForRoute(?string $routeName): ?string
    {
        if ($routeName === null) {
            return null;
        }

        foreach (self::MODULES as $module => $definition) {
            if (Str::is($definition['routes'], $routeName)) {
                return $module;
            }
        }

        return null;
    }

    public function markViewed(User $user, string $module): void
    {
        if (! in_array($module, self::ROLE_MODULES[$user->role], true)) {
            return;
        }

        DB::table('user_module_views')->upsert(
            [['user_id' => $user->getKey(), 'module' => $module, 'last_viewed_at' => now()]],
            ['user_id', 'module'],
            ['last_viewed_at']
        );
        unset($this->unseenCache[$user->getKey()]);
    }

    /**
     * Marks every module the user's role can open as seen now (new, approved or re-roled users).
     */
    public function markAllViewed(User $user): void
    {
        $now = now();
        $rows = array_map(fn (string $module): array => ['user_id' => $user->getKey(), 'module' => $module, 'last_viewed_at' => $now], self::ROLE_MODULES[$user->role]);

        DB::table('user_module_views')->upsert($rows, ['user_id', 'module'], ['last_viewed_at']);
        unset($this->unseenCache[$user->getKey()]);
    }

    /**
     * Modules (of those the user's role can open) with records created or updated after the user last
     * opened them. A module without a row yet is recorded as seen now, so it never shows a false dot.
     *
     * @return array<int, string>
     */
    public function unseenModules(User $user): array
    {
        if (isset($this->unseenCache[$user->getKey()])) {
            return $this->unseenCache[$user->getKey()];
        }

        $modules = self::ROLE_MODULES[$user->role];
        $lastViewed = DB::table('user_module_views')
            ->where('user_id', $user->getKey())
            ->pluck('last_viewed_at', 'module');

        $missing = array_values(array_diff($modules, $lastViewed->keys()->all()));
        if ($missing !== []) {
            $now = now();
            DB::table('user_module_views')->insertOrIgnore(array_map(fn (string $module): array => ['user_id' => $user->getKey(), 'module' => $module, 'last_viewed_at' => $now], $missing));
        }

        $unseen = [];
        foreach (array_diff($modules, $missing) as $module) {
            $latest = $this->latestChange($user, $module);

            if ($latest !== null && strtotime($latest) > strtotime((string) $lastViewed[$module])) {
                $unseen[] = $module;
            }
        }

        return $this->unseenCache[$user->getKey()] = $unseen;
    }

    /**
     * Latest created/updated time across the module's tables, scoped to what the user's role sees. One query.
     */
    private function latestChange(User $user, string $module): ?string
    {
        $selects = [];
        $bindings = [];

        foreach (self::MODULES[$module]['tables'] as $index => $table) {
            [$where, $params] = $this->scope($user, $module, $table);
            $selects[] = "(SELECT MAX(updated_at) FROM {$table}{$where}) AS t{$index}";
            array_push($bindings, ...$params);
        }

        $row = (array) DB::selectOne('SELECT '.implode(', ', $selects), $bindings);
        $times = array_filter($row, fn (mixed $value): bool => $value !== null);

        return $times === [] ? null : (string) max($times);
    }

    /**
     * @return array{0: string, 1: array<int, mixed>}
     */
    private function scope(User $user, string $module, string $table): array
    {
        return match (true) {
            // Drivers only see their own lifts.
            $user->role === 'driver' && in_array($table, ['hauls', 'lifting_schedules'], true) => [' WHERE driver_user_id = ?', [$user->getKey()]],
            $user->role === 'driver' && $table === 'trucks' => [' WHERE 1 = 0', []],
            $table === 'alerts' && isset(self::ROLE_ALERT_TYPES[$user->role]) => [
                ' WHERE type IN ('.implode(', ', array_fill(0, count(self::ROLE_ALERT_TYPES[$user->role]), '?')).')',
                self::ROLE_ALERT_TYPES[$user->role],
            ],
            $module === 'account-requests' => [" WHERE approval_status = 'pending'", []],
            default => ['', []],
        };
    }
}
