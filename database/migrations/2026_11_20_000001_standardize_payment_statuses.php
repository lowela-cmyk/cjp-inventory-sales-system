<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();

        // 1. Widen enums / remove old CHECK constraints so standardized values can be written safely.
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE purchases MODIFY COLUMN payment_status ENUM('paid', 'partial', 'unpaid', 'partially_paid') NOT NULL DEFAULT 'unpaid'");
            DB::statement("ALTER TABLE receivables MODIFY COLUMN status ENUM('clear', 'pending', 'partial', 'unpaid', 'overdue', 'paid', 'partially_paid') NOT NULL DEFAULT 'unpaid'");
            DB::statement("ALTER TABLE payment_schedules MODIFY COLUMN status ENUM('pending', 'partial', 'paid', 'overdue', 'unpaid', 'partially_paid') NOT NULL DEFAULT 'unpaid'");
            DB::statement("ALTER TABLE customers MODIFY COLUMN payment_status ENUM('clear', 'pending', 'partial', 'unpaid', 'paid', 'partially_paid') NOT NULL DEFAULT 'paid'");
        } else {
            Schema::table('purchases', function (Blueprint $table): void {
                $table->string('payment_status', 30)->default('unpaid')->change();
            });
            Schema::table('receivables', function (Blueprint $table): void {
                $table->string('status', 30)->default('unpaid')->change();
            });
            Schema::table('payment_schedules', function (Blueprint $table): void {
                $table->string('status', 30)->default('unpaid')->change();
            });
            Schema::table('customers', function (Blueprint $table): void {
                $table->string('payment_status', 30)->default('paid')->change();
            });
        }

        // 2. Map purchases.payment_status ('partial' -> 'partially_paid').
        DB::table('purchases')->where('payment_status', 'partial')->update(['payment_status' => 'partially_paid']);

        $hasVoided = Schema::hasColumn('payments', 'voided_at');
        $activePaymentsWhere = $hasVoided ? 'voided_at IS NULL' : '1 = 1';

        // 3. Map receivables.status based on actual sale totals and active (non-voided) payments.
        $saleTotals = DB::table('sale_items')
            ->groupBy('sale_id')
            ->selectRaw('sale_id, COALESCE(SUM(line_total), 0) as total')
            ->pluck('total', 'sale_id');
        $salePaidTotals = DB::table('payments')
            ->whereRaw($activePaymentsWhere)
            ->groupBy('sale_id')
            ->selectRaw('sale_id, COALESCE(SUM(amount), 0) as paid')
            ->pluck('paid', 'sale_id');
        $saleStatuses = DB::table('sales')->pluck('status', 'id');

        DB::table('receivables')->orderBy('id')->chunkById(500, function ($rows) use ($saleTotals, $salePaidTotals, $saleStatuses): void {
            foreach ($rows as $row) {
                $saleStatus = $saleStatuses[$row->sale_id] ?? null;
                $total = round((float) ($saleTotals[$row->sale_id] ?? 0), 2);
                $paid = round((float) ($salePaidTotals[$row->sale_id] ?? 0), 2);

                $newStatus = match (true) {
                    $saleStatus === 'cancelled' => 'unpaid',
                    $total > 0 && $paid >= $total => 'paid',
                    $paid > 0 => 'partially_paid',
                    default => 'unpaid',
                };

                if ($row->status !== $newStatus) {
                    DB::table('receivables')->where('id', $row->id)->update(['status' => $newStatus]);
                }
            }
        });

        // 4. Map payment_schedules.status based on actual active payments per schedule.
        $schedulePaidTotals = DB::table('payments')
            ->whereNotNull('payment_schedule_id')
            ->whereRaw($activePaymentsWhere)
            ->groupBy('payment_schedule_id')
            ->selectRaw('payment_schedule_id, COALESCE(SUM(amount), 0) as paid')
            ->pluck('paid', 'payment_schedule_id');

        DB::table('payment_schedules')->orderBy('id')->chunkById(500, function ($rows) use ($schedulePaidTotals): void {
            foreach ($rows as $row) {
                $due = round((float) $row->amount_due, 2);
                $paid = round((float) ($schedulePaidTotals[$row->id] ?? 0), 2);

                $newStatus = match (true) {
                    $due > 0 && $paid >= $due => 'paid',
                    $paid > 0 => 'partially_paid',
                    default => 'unpaid',
                };

                if ($row->status !== $newStatus) {
                    DB::table('payment_schedules')->where('id', $row->id)->update(['status' => $newStatus]);
                }
            }
        });

        // 5. Map customers.payment_status based on actual sales/payment balances (or legacy label if no sales exist).
        $salesByCustomer = DB::table('sales')
            ->whereNull('deleted_at')
            ->where('status', '!=', 'cancelled')
            ->get(['id', 'customer_id'])
            ->groupBy('customer_id');

        DB::table('customers')->orderBy('id')->chunkById(500, function ($rows) use ($salesByCustomer, $saleTotals, $salePaidTotals): void {
            foreach ($rows as $row) {
                $customerSales = $salesByCustomer->get($row->id);
                if ($customerSales && $customerSales->isNotEmpty()) {
                    $openCount = 0;
                    $paidOnOpen = 0.0;
                    foreach ($customerSales as $sale) {
                        $total = round((float) ($saleTotals[$sale->id] ?? 0), 2);
                        $paid = round((float) ($salePaidTotals[$sale->id] ?? 0), 2);
                        if ($total > $paid) {
                            $openCount++;
                            $paidOnOpen += $paid;
                        }
                    }
                    $newStatus = $openCount === 0
                        ? 'paid'
                        : (round($paidOnOpen, 2) > 0 ? 'partially_paid' : 'unpaid');
                } else {
                    $newStatus = match ($row->payment_status) {
                        'clear', 'paid' => 'paid',
                        'partial', 'partially_paid' => 'partially_paid',
                        default => 'unpaid',
                    };
                }

                if ($row->payment_status !== $newStatus) {
                    DB::table('customers')->where('id', $row->id)->update(['payment_status' => $newStatus]);
                }
            }
        });

        // 6. Narrow enums to ONLY the three approved statuses: paid, unpaid, partially_paid.
        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement("ALTER TABLE purchases MODIFY COLUMN payment_status ENUM('paid', 'unpaid', 'partially_paid') NOT NULL DEFAULT 'unpaid'");
            DB::statement("ALTER TABLE receivables MODIFY COLUMN status ENUM('paid', 'unpaid', 'partially_paid') NOT NULL DEFAULT 'unpaid'");
            DB::statement("ALTER TABLE payment_schedules MODIFY COLUMN status ENUM('paid', 'unpaid', 'partially_paid') NOT NULL DEFAULT 'unpaid'");
            DB::statement("ALTER TABLE customers MODIFY COLUMN payment_status ENUM('paid', 'unpaid', 'partially_paid') NOT NULL DEFAULT 'paid'");
        } else {
            Schema::table('purchases', function (Blueprint $table): void {
                $table->enum('payment_status', ['paid', 'unpaid', 'partially_paid'])->default('unpaid')->change();
            });
            Schema::table('receivables', function (Blueprint $table): void {
                $table->enum('status', ['paid', 'unpaid', 'partially_paid'])->default('unpaid')->change();
            });
            Schema::table('payment_schedules', function (Blueprint $table): void {
                $table->enum('status', ['paid', 'unpaid', 'partially_paid'])->default('unpaid')->change();
            });
            Schema::table('customers', function (Blueprint $table): void {
                $table->enum('payment_status', ['paid', 'unpaid', 'partially_paid'])->default('paid')->change();
            });
        }
    }

    public function down(): void
    {
        // Non-destructive: keep standardized statuses.
    }
};
