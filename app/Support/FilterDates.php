<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;

/**
 * System Analysis Important Notes (Part 6): every filter is a dropdown. A Date filter lists the days that actually
 * have records, newest first.
 */
class FilterDates
{
    /** @return array<int, string> distinct Y-m-d days of $column in $query, newest first */
    public static function distinct(Builder $query, string $column, int $limit = 120): array
    {
        return $query->whereNotNull($column)->orderByDesc($column)->limit($limit * 10)->pluck($column)
            ->map(fn (mixed $date): string => substr((string) $date, 0, 10))
            ->unique()->take($limit)->values()->all();
    }
}
