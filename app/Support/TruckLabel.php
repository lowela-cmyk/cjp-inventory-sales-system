<?php

namespace App\Support;

/**
 * Part 15 (October 30, 2026): how a truck is named everywhere outside the Trucks tab: its name, or its truck code
 * (TRK-002) when it has none. The plate number is shown only in the Trucks tab and the truck's View / Edit modal.
 */
final class TruckLabel
{
    public static function make(?string $name, ?string $truckCode): string
    {
        $name = trim((string) $name);

        return $name !== '' ? $name : trim((string) $truckCode);
    }

    /** SQL ordering that matches the label (name, else code). */
    public static function orderSql(string $table = 'trucks'): string
    {
        return "COALESCE(NULLIF(TRIM({$table}.name), ''), {$table}.truck_code)";
    }
}
