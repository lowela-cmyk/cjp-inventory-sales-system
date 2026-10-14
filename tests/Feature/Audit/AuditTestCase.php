<?php

namespace Tests\Feature\Audit;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Shared fixtures for the audit regression tests. Each test in this folder reproduces a
 * CRITICAL or HIGH finding from AUDIT_REPORT.md and is expected to FAIL until that finding is fixed.
 * Run them with: php artisan test --group=audit
 */
abstract class AuditTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    protected function actors(): array
    {
        $make = fn (string $role, array $extra = []): User => User::factory()->create(array_merge([
            'role' => $role, 'status' => 'active', 'approval_status' => 'approved',
        ], $extra));

        $driver = $make('driver');
        DB::table('driver_profiles')->insert(['user_id' => $driver->id, 'driver_code' => 'DRV-AUD', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]);

        return [
            'admin' => $make('admin'),
            'inventory' => $make('inventory_officer'),
            'sales' => $make('sales_officer'),
            'dispatch' => $make('dispatch_officer'),
            'driver' => $driver,
            'depot' => DB::table('depots')->insertGetId(['depot_code' => 'DEP-AUD', 'name' => 'Audit Terminal', 'address' => 'Audit Road', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]),
            'fuel' => (int) DB::table('fuel_types')->where('code', 'DSL')->value('id'),
            'truck' => DB::table('trucks')->insertGetId(['truck_code' => 'TRK-AUD', 'capacity_liters' => 30000, 'truck_type' => 'hauling', 'status' => 'available', 'created_at' => now(), 'updated_at' => now()]),
            'customer' => DB::table('customers')->insertGetId(['customer_code' => 'CUS-AUD', 'name' => 'Audit Customer', 'company_name' => 'Audit Co.', 'payment_status' => 'clear', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]),
        ];
    }

    protected function uuid(): string
    {
        return (string) Str::uuid();
    }
}
