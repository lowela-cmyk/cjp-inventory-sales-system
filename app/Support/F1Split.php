<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Part 9: an Unleaded or Premium sale line can be delivered partly as F1. F1 is the approved fuel type with
 * code "F1" (config/fuels.php), stocked in its own garage tanks. The F1 portion is f1_percentage of the
 * ordered liters; the line's own fuel covers the rest. Price and line total are not affected.
 */
final class F1Split
{
    /** F1 Percentage options offered in Record Sales. */
    public const PERCENTAGES = [10, 15, 17];

    /** Fuel codes whose sale lines may carry an F1 portion. */
    public const ELIGIBLE_CODES = ['UNL', 'PREM'];

    public const F1_CODE = 'F1';

    public static function f1FuelId(): ?int
    {
        $id = DB::table('fuel_types')->where('code', self::F1_CODE)->value('id');

        return $id === null ? null : (int) $id;
    }

    /** @return array<int, int> ids of the fuels that may carry an F1 portion */
    public static function eligibleFuelIds(): array
    {
        return DB::table('fuel_types')->whereIn('code', self::ELIGIBLE_CODES)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
    }

    /**
     * Liters of each portion: [main, f1]. They always add up to the ordered quantity.
     *
     * @return array{0: float, 1: float}
     */
    public static function split(float $quantity, float $percentage): array
    {
        $f1 = round($quantity * $percentage / 100, 2);

        return [round($quantity - $f1, 2), $f1];
    }
}
