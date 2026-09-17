<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Transactional database workflow smoke test.
 *
 * NOTE: This service directly inserts records into database tables within a transactional
 * block (with automatic rollback by default) to verify schema integrity, calculation
 * consistency, and state relationships across the Purchase -> Hauling -> Stock-In ->
 * Stock-Out -> Sale -> Payment chain.
 *
 * This is a database-level workflow smoke test and is NOT an HTTP/UI acceptance test.
 * Genuine operational acceptance requires authenticated HTTP requests through application controllers.
 */
class WorkflowSmokeTestService
{
    /**
     * @return array{ok: bool, message: string, checks: array<string, mixed>}
     */
    public function run(bool $rollback = true): array
    {
        $lastResult = [
            'ok' => false,
            'message' => 'Workflow smoke test did not run.',
            'checks' => [],
        ];

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $lastResult = $this->attempt($rollback);

            if ($lastResult['ok'] || ! $this->isTransientDatabaseFailure($lastResult['message'])) {
                return $lastResult;
            }

            usleep(150000 * $attempt);
        }

        return $lastResult;
    }

    /**
     * @return array{ok: bool, message: string, checks: array<string, mixed>}
     */
    private function attempt(bool $rollback): array
    {
        $checks = [];

        DB::beginTransaction();

        try {
            $prefix = 'SMOKE-'.now()->format('ymdHis').'-'.Str::upper(Str::random(5));
            $records = $this->createMasterData($prefix);
            $purchase = $this->createPurchase($records, $prefix, 20000.0);
            $haul = $this->createCompletedHaul($records, $purchase, $prefix, 20000.0);
            $allocationId = $this->createGarageAllocation($records, $haul, 20000.0);

            $initialBalance = $this->garageBalance($records);

            $this->recordStockIn($records, $allocationId, 20000.0);
            $this->assertSame(round($initialBalance + 20000.0, 2), $this->garageBalance($records), 'garage balance after stock-in');

            $sale = $this->createSaleWithAutomaticGarageRelease($records, $prefix, 12000.0, 6.25);
            $this->assertSame(round($initialBalance + 8000.0, 2), $this->garageBalance($records), 'garage balance after automatic stock-out');

            $this->recordPayment($records, $sale, 75000.0, $prefix);

            $checks = [
                'purchase_code' => $purchase['code'],
                'haul_code' => $haul['code'],
                'sale_code' => $sale['code'],
                'stock_in_movements' => DB::table('inventory_movements')
                    ->where('reference_type', 'haul_allocation')
                    ->where('reference_id', $allocationId)
                    ->count(),
                'stock_outs' => DB::table('stock_outs')
                    ->where('sale_id', $sale['id'])
                    ->where('status', 'released')
                    ->count(),
                'stock_out_movements' => DB::table('inventory_movements')
                    ->where('reference_type', 'stock_out')
                    ->whereIn('reference_id', DB::table('stock_outs')->where('sale_id', $sale['id'])->pluck('id'))
                    ->count(),
                'garage_balance_liters' => $this->garageBalance($records),
                'sale_total' => $this->saleTotal($sale['id']),
                'paid_total' => $this->paidTotal($sale['id']),
                'receivable_status' => DB::table('receivables')->where('sale_id', $sale['id'])->value('status'),
                'duplicate_inventory_effects' => $this->duplicateInventoryEffects($sale['id']),
                'overpaid_sales' => $this->overpaidSales(),
            ];

            $this->assertSame(1, (int) $checks['stock_in_movements'], 'stock-in movement count');
            $this->assertSame(1, (int) $checks['stock_outs'], 'stock-out count');
            $this->assertSame(1, (int) $checks['stock_out_movements'], 'stock-out movement count');
            $this->assertSame(75000.0, (float) $checks['sale_total'], 'sale total');
            $this->assertSame(75000.0, (float) $checks['paid_total'], 'paid total');
            $this->assertSame('clear', (string) $checks['receivable_status'], 'receivable status');
            $this->assertSame(0, (int) $checks['duplicate_inventory_effects'], 'duplicate inventory effects');
            $this->assertSame(0, (int) $checks['overpaid_sales'], 'overpaid sales');

            if ($rollback) {
                DB::rollBack();
            } else {
                DB::commit();
            }

            return [
                'ok' => true,
                'message' => $rollback
                    ? 'Rollback smoke workflow completed without persisting staging data.'
                    : 'Staging smoke workflow completed and persisted.',
                'checks' => $checks,
            ];
        } catch (Throwable $exception) {
            DB::rollBack();

            return [
                'ok' => false,
                'message' => $exception->getMessage(),
                'checks' => $checks,
            ];
        }
    }

    private function isTransientDatabaseFailure(string $message): bool
    {
        return str_contains($message, 'Deadlock found')
            || str_contains($message, 'Serialization failure')
            || str_contains($message, 'Lock wait timeout');
    }

    /**
     * @return array<string, mixed>
     */
    private function createMasterData(string $prefix): array
    {
        $now = now();
        $password = Hash::make(Str::random(24));

        $adminId = $this->insertUser($prefix.' Admin', 'admin', $prefix, $password);
        $inventoryOfficerId = $this->insertUser($prefix.' Inventory', 'inventory_officer', $prefix, $password);
        $salesOfficerId = $this->insertUser($prefix.' Sales', 'sales_officer', $prefix, $password);
        $driverId = $this->insertUser($prefix.' Driver', 'driver', $prefix, $password);

        $fuelType = DB::table('fuel_types')
            ->where('status', 'active')
            ->where('code', 'DSL')
            ->first(['id']);

        if (! $fuelType) {
            throw new RuntimeException('Active Diesel fuel type is required for smoke testing.');
        }

        $garage = DB::table('storage_locations')
            ->where('status', 'active')
            ->where('type', 'garage')
            ->where('fuel_type_id', $fuelType->id)
            ->orderBy('tank_number')
            ->first(['id']);

        if (! $garage) {
            throw new RuntimeException('An active Diesel garage tank is required for smoke testing.');
        }

        $depotId = DB::table('depots')->insertGetId([
            'depot_code' => $prefix.'-DEP',
            'name' => $prefix.' Depot',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $customerId = DB::table('customers')->insertGetId([
            'customer_code' => $prefix.'-CUS',
            'name' => $prefix.' Customer',
            'company_name' => $prefix.' Customer Co.',
            'payment_status' => 'clear',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $truckId = DB::table('trucks')->insertGetId([
            'truck_code' => $prefix.'-TRK',
            'plate_number' => $prefix.'-PLT',
            'capacity_liters' => 30000,
            'truck_type' => 'mixed',
            'status' => 'available',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('driver_profiles')->insert([
            'user_id' => $driverId,
            'driver_code' => $prefix.'-DRV',
            'license_number' => $prefix.'-LIC',
            'status' => 'available',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'admin_id' => $adminId,
            'inventory_officer_id' => $inventoryOfficerId,
            'sales_officer_id' => $salesOfficerId,
            'driver_id' => $driverId,
            'depot_id' => $depotId,
            'fuel_type_id' => (int) $fuelType->id,
            'garage_id' => (int) $garage->id,
            'customer_id' => $customerId,
            'truck_id' => $truckId,
        ];
    }

    private function insertUser(string $name, string $role, string $prefix, string $password): int
    {
        return DB::table('users')->insertGetId([
            'name' => $name,
            'email' => Str::lower($prefix.'-'.$role).'@smoke.invalid',
            'role' => $role,
            'password' => $password,
            'phone' => null,
            'status' => 'active',
            'approval_status' => 'approved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $records
     * @return array{id: int, item_id: int, code: string}
     */
    private function createPurchase(array $records, string $prefix, float $quantity): array
    {
        $purchaseCode = $prefix.'-PUR';
        $purchaseId = DB::table('purchases')->insertGetId([
            'purchase_code' => $purchaseCode,
            'depot_id' => $records['depot_id'],
            'purchase_date' => now()->toDateString(),
            'payment_status' => 'paid',
            'status' => 'ordered',
            'created_by' => $records['inventory_officer_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $purchaseItemId = DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchaseId,
            'fuel_type_id' => $records['fuel_type_id'],
            'quantity_ordered_liters' => $quantity,
            'unit_cost' => 50,
            'line_total' => round($quantity * 50, 2),
            'quantity_hauled_liters' => 0,
            'status' => 'unlifted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $purchaseId, 'item_id' => $purchaseItemId, 'code' => $purchaseCode];
    }

    /**
     * @param  array<string, mixed>  $records
     * @param  array{id: int, item_id: int, code: string}  $purchase
     * @return array{id: int, code: string}
     */
    private function createCompletedHaul(array $records, array $purchase, string $prefix, float $quantity): array
    {
        $haulCode = $prefix.'-LFT';
        $haulId = DB::table('hauls')->insertGetId([
            'haul_code' => $haulCode,
            'purchase_id' => $purchase['id'],
            'purchase_item_id' => $purchase['item_id'],
            'depot_id' => $records['depot_id'],
            'fuel_type_id' => $records['fuel_type_id'],
            'truck_id' => $records['truck_id'],
            'driver_user_id' => $records['driver_id'],
            'scheduled_at' => now()->subHour(),
            'hauled_at' => now(),
            'source_location' => null,
            'quantity_liters' => $quantity,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('purchase_items')
            ->where('id', $purchase['item_id'])
            ->update([
                'quantity_hauled_liters' => $quantity,
                'status' => 'lifted',
                'updated_at' => now(),
            ]);

        DB::table('purchases')
            ->where('id', $purchase['id'])
            ->update([
                'status' => 'hauled',
                'updated_at' => now(),
            ]);

        return ['id' => $haulId, 'code' => $haulCode];
    }

    /**
     * @param  array<string, mixed>  $records
     * @param  array{id: int, code: string}  $haul
     */
    private function createGarageAllocation(array $records, array $haul, float $quantity): int
    {
        return DB::table('haul_allocations')->insertGetId([
            'haul_id' => $haul['id'],
            'fuel_type_id' => $records['fuel_type_id'],
            'destination_type' => 'garage',
            'storage_location_id' => $records['garage_id'],
            'customer_id' => null,
            'sale_id' => null,
            'quantity_liters' => $quantity,
            'allocated_at' => now(),
            'status' => 'delivered',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $records
     */
    private function recordStockIn(array $records, int $allocationId, float $quantity): void
    {
        DB::table('inventory_movements')->insert([
            'movement_code' => $this->code('MOV'),
            'storage_location_id' => $records['garage_id'],
            'fuel_type_id' => $records['fuel_type_id'],
            'movement_type' => 'stock_in',
            'direction' => 'in',
            'quantity_liters' => $quantity,
            'unit_cost' => 50,
            'reference_type' => 'haul_allocation',
            'reference_id' => $allocationId,
            'movement_date' => now(),
            'remarks' => 'Rollback smoke stock-in',
            'created_by' => $records['inventory_officer_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('haul_allocations')->where('id', $allocationId)->update([
            'status' => 'received',
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $records
     * @return array{id: int, item_id: int, code: string}
     */
    private function createSaleWithAutomaticGarageRelease(array $records, string $prefix, float $quantity, float $unitPrice): array
    {
        $saleCode = $prefix.'-SLS';
        $saleId = DB::table('sales')->insertGetId([
            'sale_code' => $saleCode,
            'sales_order_number' => null,
            'customer_id' => $records['customer_id'],
            'sale_date' => now()->toDateString(),
            'payment_method' => 'bank_transfer',
            'payment_terms' => 'cod',
            'status' => 'confirmed',
            'created_by' => $records['sales_officer_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $saleItemId = DB::table('sale_items')->insertGetId([
            'sale_id' => $saleId,
            'fuel_type_id' => $records['fuel_type_id'],
            'quantity_liters' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => round($quantity * $unitPrice, 2),
            'fulfilled_quantity_liters' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('receivables')->insert([
            'sale_id' => $saleId,
            'due_date' => now()->addDays(15)->toDateString(),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $error = app(StockOutReleaseService::class)->releaseSaleItemFromGarage(
            $saleItemId,
            $quantity,
            now()->toDateTimeString(),
            $records['sales_officer_id'],
            $records['garage_id'],
            'Rollback smoke automatic stock-out'
        );

        if ($error) {
            throw new RuntimeException($error);
        }

        return ['id' => $saleId, 'item_id' => $saleItemId, 'code' => $saleCode];
    }

    /**
     * @param  array<string, mixed>  $records
     * @param  array{id: int, item_id: int, code: string}  $sale
     */
    private function recordPayment(array $records, array $sale, float $amount, string $prefix): void
    {
        DB::table('payments')->insert([
            'payment_code' => $prefix.'-PAY',
            'sale_id' => $sale['id'],
            'payment_schedule_id' => null,
            'payment_date' => now()->toDateString(),
            'amount' => $amount,
            'method' => 'bank_transfer',
            'reference_number' => $prefix.'-REF',
            'remarks' => 'Rollback smoke payment',
            'received_by' => $records['sales_officer_id'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('sales')->where('id', $sale['id'])->update([
            'status' => 'paid',
            'updated_at' => now(),
        ]);

        DB::table('receivables')->where('sale_id', $sale['id'])->update([
            'status' => 'clear',
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $records
     */
    private function garageBalance(array $records): float
    {
        return round((float) DB::table('inventory_movements')
            ->where('storage_location_id', $records['garage_id'])
            ->where('fuel_type_id', $records['fuel_type_id'])
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN quantity_liters ELSE -quantity_liters END), 0) as balance")
            ->value('balance'), 2);
    }

    private function saleTotal(int $saleId): float
    {
        return round((float) DB::table('sale_items')->where('sale_id', $saleId)->sum('line_total'), 2);
    }

    private function paidTotal(int $saleId): float
    {
        return round((float) DB::table('payments')->where('sale_id', $saleId)->sum('amount'), 2);
    }

    private function duplicateInventoryEffects(int $saleId): int
    {
        return DB::query()
            ->fromSub(
                DB::table('stock_outs')
                    ->where('sale_id', $saleId)
                    ->where('status', 'released')
                    ->selectRaw('sale_item_id, source_type, COALESCE(storage_location_id, 0) as location_id, COALESCE(haul_allocation_id, 0) as allocation_id, quantity_liters, stock_out_at, COUNT(*) as duplicates')
                    ->groupBy('sale_item_id', 'source_type', 'location_id', 'allocation_id', 'quantity_liters', 'stock_out_at')
                    ->havingRaw('COUNT(*) > 1'),
                'duplicates'
            )
            ->count();
    }

    private function overpaidSales(): int
    {
        $saleTotals = DB::table('sale_items')
            ->selectRaw('sale_id, COALESCE(SUM(line_total), 0) as total')
            ->groupBy('sale_id');
        $paymentTotals = DB::table('payments')
            ->selectRaw('sale_id, COALESCE(SUM(amount), 0) as paid')
            ->groupBy('sale_id');

        return DB::query()
            ->fromSub(
                DB::table('sales')
                    ->joinSub($saleTotals, 'sale_totals', 'sale_totals.sale_id', '=', 'sales.id')
                    ->leftJoinSub($paymentTotals, 'payment_totals', 'payment_totals.sale_id', '=', 'sales.id')
                    ->where('sales.status', '!=', 'cancelled')
                    ->selectRaw('sales.id, sale_totals.total, COALESCE(payment_totals.paid, 0) as paid'),
                'totals'
            )
            ->whereRaw('paid > total')
            ->count();
    }

    private function code(string $prefix): string
    {
        do {
            $code = $prefix.'-'.now()->format('ymdHis').'-'.Str::upper(Str::random(5));
        } while (DB::table('inventory_movements')->where('movement_code', $code)->exists()
            || DB::table('stock_outs')->where('stock_out_code', $code)->exists());

        return $code;
    }

    private function assertSame(mixed $expected, mixed $actual, string $label): void
    {
        if ($expected !== $actual) {
            throw new RuntimeException('Smoke check failed for '.$label.'. Expected '.var_export($expected, true).', got '.var_export($actual, true).'.');
        }
    }
}
