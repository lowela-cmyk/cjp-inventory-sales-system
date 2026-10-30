<?php

namespace App\Services;

use App\Support\F1Split;
use Illuminate\Support\Facades\DB;

/**
 * Part 15 #6 (October 30, 2026): Purchase, Order and Lift IDs are PREFIX-FUEL-NUMBER, e.g. PUR-PREM-001, SLS-UNL-012,
 * LFT-DSL-003 (haul lines LFT-DSL-003-01). FUEL is the fuel code (PREM, UNL, DSL, F1); NUMBER runs per prefix and fuel,
 * three digits zero-padded and growing past 999. Numbers are never reused, also when a record gets a new ID.
 */
class RecordCodeService
{
    public const PURCHASE = 'PUR';

    public const SALE = 'SLS';

    public const LIFT = 'LFT';

    /** Where each prefix's codes are stored. */
    public const COLUMNS = [
        self::PURCHASE => ['purchases', 'purchase_code'],
        self::SALE => ['sales', 'sale_code'],
        self::LIFT => ['lifting_schedules', 'schedule_code'],
    ];

    /**
     * The next ID for $prefix and the fuel. The sequence row stays locked until the caller's transaction ends, so a
     * second user saving at the same time waits and gets the following number.
     */
    public function next(string $prefix, int $fuelTypeId): string
    {
        $fuel = $this->fuelCode($fuelTypeId);

        return DB::transaction(function () use ($prefix, $fuel): string {
            DB::table('code_sequences')->insertOrIgnore([
                'prefix' => $prefix,
                'fuel_code' => $fuel,
                'last_number' => $this->highestUsed($prefix, $fuel),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $sequence = DB::table('code_sequences')->where('prefix', $prefix)->where('fuel_code', $fuel)->lockForUpdate()->first();
            $number = (int) $sequence->last_number + 1;
            DB::table('code_sequences')->where('id', $sequence->id)->update(['last_number' => $number, 'updated_at' => now()]);

            return self::format($prefix, $fuel, $number);
        });
    }

    /** A lift's fuel: the main fuel of its purchases (F1 rides along as the blend portion), else F1. */
    public function liftFuel(iterable $fuelTypeIds): int
    {
        $ids = collect($fuelTypeIds)->map(fn (mixed $id): int => (int) $id)->values();

        return (int) ($ids->first(fn (int $id): bool => $id !== F1Split::f1FuelId()) ?? $ids->first());
    }

    /** Gives a record a new ID (its fuel changed) and keeps the old one findable. */
    public function recode(string $prefix, string $entity, int $recordId, int $fuelTypeId): string
    {
        [$table, $column] = self::COLUMNS[$prefix];
        $old = (string) DB::table($table)->where('id', $recordId)->value($column);
        $new = $this->next($prefix, $fuelTypeId);
        DB::table($table)->where('id', $recordId)->update([$column => $new, 'updated_at' => now()]);
        $this->remember($entity, $recordId, $old, $new, 'fuel_change');

        if ($prefix === self::LIFT) {
            foreach (DB::table('hauls')->where('lifting_schedule_id', $recordId)->get(['id', 'haul_code']) as $haul) {
                $haulCode = $new.substr((string) $haul->haul_code, strlen($old));
                DB::table('hauls')->where('id', $haul->id)->update(['haul_code' => $haulCode, 'updated_at' => now()]);
                $this->remember('haul', (int) $haul->id, (string) $haul->haul_code, $haulCode, 'fuel_change');
            }
        }

        return $new;
    }

    public function remember(string $entity, int $recordId, string $old, string $new, string $reason): void
    {
        if ($old === '' || $old === $new) {
            return;
        }

        DB::table('code_renumbering')->insert([
            'entity' => $entity,
            'record_id' => $recordId,
            'old_code' => $old,
            'new_code' => $new,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    /**
     * The current code of a record searched by an old code: the exact old code (any letter case), or a fragment of at
     * least 5 characters found in one old code only. A term that matches a current code is never replaced.
     */
    public function currentCodeFor(string $term): ?string
    {
        $term = strtoupper(trim($term));
        if (strlen($term) < 5 || $this->matchesCurrentCode($term)) {
            return null;
        }

        $old = DB::table('code_renumbering')->whereRaw('UPPER(old_code) = ?', [$term])->orderByDesc('id')->value('old_code');
        if ($old === null) {
            $candidates = DB::table('code_renumbering')->whereRaw('UPPER(old_code) LIKE ?', ['%'.$term.'%'])->distinct()->limit(2)->pluck('old_code');
            $old = $candidates->count() === 1 ? $candidates->first() : null;
        }

        // Follow later changes (renumbered, then given a new ID after a fuel change).
        $code = $old;
        for ($hops = 0; $code !== null && $hops < 10; $hops++) {
            $next = DB::table('code_renumbering')->where('old_code', $code)->orderByDesc('id')->value('new_code');
            if ($next === null) {
                break;
            }
            $code = $next;
        }

        return $code === $old ? null : $code;
    }

    public static function format(string $prefix, string $fuel, int $number): string
    {
        return $prefix.'-'.$fuel.'-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT);
    }

    private function fuelCode(int $fuelTypeId): string
    {
        $code = DB::table('fuel_types')->where('id', $fuelTypeId)->value('code');

        return strtoupper((string) ($code ?: 'GEN'));
    }

    /** Highest number already used for the prefix and fuel (a sequence row created late never repeats a code). */
    private function highestUsed(string $prefix, string $fuel): int
    {
        [$table, $column] = self::COLUMNS[$prefix];
        $pattern = $prefix.'-'.$fuel.'-';
        $numbers = DB::table($table)->where($column, 'like', $pattern.'%')->pluck($column)
            ->merge(DB::table('code_renumbering')->where('old_code', 'like', $pattern.'%')->pluck('old_code'))
            ->map(fn (string $code): ?int => preg_match('/^'.preg_quote($pattern, '/').'(\d+)$/', $code, $m) ? (int) $m[1] : null)
            ->filter();

        return (int) ($numbers->max() ?? 0);
    }

    private function matchesCurrentCode(string $term): bool
    {
        foreach ([...array_values(self::COLUMNS), ['hauls', 'haul_code']] as [$table, $column]) {
            if (DB::table($table)->whereRaw("UPPER($column) LIKE ?", ['%'.$term.'%'])->exists()) {
                return true;
            }
        }

        return false;
    }
}
