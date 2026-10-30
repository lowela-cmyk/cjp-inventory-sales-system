<?php

use App\Services\RecordCodeService;
use App\Support\F1Split;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Part 15 #6 (October 30, 2026): renumbers every existing purchase, sale and lift into PREFIX-FUEL-NUMBER, in date order
 * per fuel (PUR-PREM-001, SLS-PREM-001, LFT-PREM-001 with haul lines LFT-PREM-001-01 ...).
 *
 * - Fuel: a purchase's line; a sale's first line (its main fuel; F1 portions don't count); a lift's purchases (the main
 *   fuel, F1 riding along as the blend). A lift row without a schedule (older single lifts) is numbered as a lift.
 * - Records link to each other by id, so only the code columns change, plus text that quotes codes (alert texts and
 *   links, AI insights, report runs, receipt remarks).
 * - Every old -> new pair is kept in code_renumbering (search by old code; down() restores the old codes from it).
 * - One transaction; codes go through temporary values first, so the unique indexes never see a clash.
 */
return new class extends Migration
{
    /** Text columns that may quote codes: table => columns (missing ones are skipped). */
    private const TEXT_COLUMNS = [
        'alerts' => ['title', 'message', 'action_url', 'deduplication_key'],
        'ai_insights' => ['title', 'summary', 'insight_payload'],
        'report_runs' => ['parameters', 'title', 'summary'],
        'stock_receipts' => ['remarks'],
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $fuelCodes = DB::table('fuel_types')->pluck('code', 'id')->map(fn (mixed $code): string => strtoupper((string) $code));
            $f1 = F1Split::f1FuelId();
            $numbers = [];
            $next = function (string $prefix, ?int $fuelTypeId) use (&$numbers, $fuelCodes): string {
                $fuel = $fuelCodes[$fuelTypeId] ?? 'GEN';
                $numbers[$prefix][$fuel] = ($numbers[$prefix][$fuel] ?? 0) + 1;

                return RecordCodeService::format($prefix, $fuel, $numbers[$prefix][$fuel]);
            };
            $changes = [];

            // Purchases.
            $purchaseFuel = DB::table('purchase_items')->orderBy('id')->get(['purchase_id', 'fuel_type_id'])->groupBy('purchase_id')->map(fn (Collection $items): int => (int) $items->first()->fuel_type_id);
            foreach (DB::table('purchases')->orderBy('purchase_date')->orderBy('created_at')->orderBy('id')->get(['id', 'purchase_code']) as $purchase) {
                if (isset($purchaseFuel[$purchase->id])) {
                    $changes[] = ['purchase', 'purchases', 'purchase_code', (int) $purchase->id, $purchase->purchase_code, $next(RecordCodeService::PURCHASE, $purchaseFuel[$purchase->id])];
                }
            }

            // Sales.
            $saleFuel = DB::table('sale_items')->orderBy('id')->get(['sale_id', 'fuel_type_id'])->groupBy('sale_id')->map(fn (Collection $items): int => (int) $items->first()->fuel_type_id);
            foreach (DB::table('sales')->orderBy('sale_date')->orderBy('created_at')->orderBy('id')->get(['id', 'sale_code']) as $sale) {
                if (isset($saleFuel[$sale->id])) {
                    $changes[] = ['sale', 'sales', 'sale_code', (int) $sale->id, $sale->sale_code, $next(RecordCodeService::SALE, $saleFuel[$sale->id])];
                }
            }

            // Lifts: schedules with their haul lines, and single lifts without a schedule, by date.
            $hauls = DB::table('hauls')->orderBy('id')->get(['id', 'lifting_schedule_id', 'haul_code', 'fuel_type_id', 'scheduled_at', 'created_at']);
            $liftFuel = fn (Collection $lines): ?int => ($lines->first(fn (object $haul): bool => (int) $haul->fuel_type_id !== $f1) ?? $lines->first())?->fuel_type_id;
            $lifts = DB::table('lifting_schedules')->get(['id', 'schedule_code', 'scheduled_at', 'created_at'])
                ->map(fn (object $schedule): object => (object) ['kind' => 'schedule', 'row' => $schedule, 'hauls' => $hauls->where('lifting_schedule_id', $schedule->id)->values()])
                ->merge($hauls->whereNull('lifting_schedule_id')->map(fn (object $haul): object => (object) ['kind' => 'haul', 'row' => $haul, 'hauls' => collect([$haul])]))
                ->sortBy(fn (object $lift): string => ($lift->row->scheduled_at ?? '').'|'.($lift->row->created_at ?? '').'|'.str_pad((string) $lift->row->id, 12, '0', STR_PAD_LEFT).'|'.$lift->kind)
                ->values();
            foreach ($lifts as $lift) {
                $fuelTypeId = $liftFuel($lift->hauls);
                if ($fuelTypeId === null) {
                    continue;
                }
                $code = $next(RecordCodeService::LIFT, (int) $fuelTypeId);
                if ($lift->kind === 'haul') {
                    $changes[] = ['haul', 'hauls', 'haul_code', (int) $lift->row->id, $lift->row->haul_code, $code];

                    continue;
                }
                $changes[] = ['lift', 'lifting_schedules', 'schedule_code', (int) $lift->row->id, $lift->row->schedule_code, $code];
                foreach ($lift->hauls as $index => $haul) {
                    $changes[] = ['haul', 'hauls', 'haul_code', (int) $haul->id, $haul->haul_code, $code.'-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)];
                }
            }

            $changes = array_values(array_filter($changes, fn (array $change): bool => $change[4] !== $change[5]));
            $this->writeCodes($changes, fn (array $change): string => $change[5]);
            $this->replaceText(collect($changes)->mapWithKeys(fn (array $change): array => [$change[4] => $change[5]])->all());

            $now = now();
            foreach (array_chunk($changes, 500) as $chunk) {
                DB::table('code_renumbering')->insert(array_map(fn (array $change): array => [
                    'entity' => $change[0], 'record_id' => $change[3], 'old_code' => $change[4], 'new_code' => $change[5], 'reason' => 'renumber', 'created_at' => $now,
                ], $chunk));
            }
            foreach ($numbers as $prefix => $byFuel) {
                foreach ($byFuel as $fuel => $last) {
                    DB::table('code_sequences')->updateOrInsert(['prefix' => $prefix, 'fuel_code' => $fuel], ['last_number' => $last, 'created_at' => $now, 'updated_at' => $now]);
                }
            }
        });

        // Synced alerts rebuild their texts from the new codes on the next page load.
        Cache::forget('alerts:sync-fingerprint');
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $tables = ['purchase' => ['purchases', 'purchase_code'], 'sale' => ['sales', 'sale_code'], 'lift' => ['lifting_schedules', 'schedule_code'], 'haul' => ['hauls', 'haul_code']];
            $rows = DB::table('code_renumbering')->where('reason', 'renumber')->orderBy('id')->get();
            $changes = $rows->map(function (object $row) use ($tables): array {
                [$table, $column] = $tables[$row->entity];
                // The record's current code (a later fuel change may have replaced the renumbered one).
                $current = (string) DB::table($table)->where('id', $row->record_id)->value($column);

                return [$row->entity, $table, $column, (int) $row->record_id, $current, $row->old_code];
            })->all();

            $this->writeCodes($changes, fn (array $change): string => $change[5]);
            $this->replaceText(collect($changes)->mapWithKeys(fn (array $change): array => [$change[4] => $change[5]])->all());

            DB::table('code_renumbering')->where('reason', 'renumber')->delete();
            DB::table('code_sequences')->delete();
        });

        Cache::forget('alerts:sync-fingerprint');
    }

    /**
     * Sets each record's code: first to a temporary value, then to its target, so no unique index sees two equal codes.
     *
     * @param  array<int, array{0: string, 1: string, 2: string, 3: int, 4: ?string, 5: string}>  $changes
     */
    private function writeCodes(array $changes, Closure $target): void
    {
        foreach ($changes as $change) {
            DB::table($change[1])->where('id', $change[3])->update([$change[2] => 'TMPRN-'.$change[0][0].$change[3]]);
        }
        foreach ($changes as $change) {
            DB::table($change[1])->where('id', $change[3])->update([$change[2] => $target($change)]);
        }
    }

    /**
     * Replaces codes quoted in text columns (longest code first, so LFT-…-01 is not cut by LFT-…).
     *
     * @param  array<string, string>  $map  old code => new code
     */
    private function replaceText(array $map): void
    {
        $map = array_filter($map, fn (mixed $new, mixed $old): bool => (string) $old !== '' && (string) $old !== (string) $new, ARRAY_FILTER_USE_BOTH);
        if ($map === []) {
            return;
        }

        foreach (self::TEXT_COLUMNS as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            $columns = array_values(array_filter($columns, fn (string $column): bool => Schema::hasColumn($table, $column)));
            if ($columns === []) {
                continue;
            }

            DB::table($table)
                ->where(function ($query) use ($columns): void {
                    foreach ($columns as $column) {
                        foreach (['PUR-', 'SLS-', 'LFT-', 'SCH-'] as $prefix) {
                            $query->orWhere($column, 'like', '%'.$prefix.'%');
                        }
                    }
                })
                ->orderBy('id')
                ->get(['id', ...$columns])
                ->each(function (object $row) use ($table, $columns, $map): void {
                    $values = [];
                    foreach ($columns as $column) {
                        if ($row->{$column} !== null && ($replaced = strtr((string) $row->{$column}, $map)) !== (string) $row->{$column}) {
                            $values[$column] = $replaced;
                        }
                    }
                    if ($values !== []) {
                        DB::table($table)->where('id', $row->id)->update($values);
                    }
                });
        }
    }
};
