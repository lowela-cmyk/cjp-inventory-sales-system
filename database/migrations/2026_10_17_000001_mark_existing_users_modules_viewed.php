<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Data migration: every existing user starts with every module their role can open marked as seen now,
 * so the first login after the red-dot feature shows no dots for old records. Only records created or
 * updated after this point raise a dot. The module list is copied here so this migration stays fixed
 * even if ModuleActivityService::ROLE_MODULES changes later.
 */
return new class extends Migration
{
    private const ROLE_MODULES = [
        'admin' => ['inventory', 'ledger', 'fuel-lifting', 'sales', 'alerts', 'account-requests', 'user-management'],
        'inventory_officer' => ['inventory', 'ledger', 'alerts'],
        'sales_officer' => ['sales', 'alerts'],
        'dispatch_officer' => ['ledger', 'fuel-lifting', 'alerts'],
        'driver' => ['fuel-lifting'],
    ];

    public function up(): void
    {
        $now = now();

        DB::table('users')->orderBy('id')->select(['id', 'role'])->chunk(200, function ($users) use ($now): void {
            $rows = [];
            foreach ($users as $user) {
                foreach (self::ROLE_MODULES[$user->role] ?? [] as $module) {
                    $rows[] = ['user_id' => $user->id, 'module' => $module, 'last_viewed_at' => $now];
                }
            }

            if ($rows !== []) {
                DB::table('user_module_views')->upsert($rows, ['user_id', 'module'], ['last_viewed_at']);
            }
        });
    }

    public function down(): void
    {
        // Seen-times cannot be told apart from real visits afterwards; nothing to undo.
    }
};
