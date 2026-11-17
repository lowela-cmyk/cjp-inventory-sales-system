<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;

final class TableSort
{
    /** Only literal, server-owned columns can be used in ORDER BY. Always end on a unique key. */
    public static function apply(Builder $query, array $columns, string $default, string $unique, string $defaultDirection = 'desc'): void
    {
        $key = request()->query('sort', $default);
        $key = is_string($key) && isset($columns[$key]) ? $key : $default;
        $direction = request()->query('direction', $defaultDirection);
        $direction = in_array($direction, ['asc', 'desc'], true) ? $direction : $defaultDirection;
        $query->reorder()->orderBy($columns[$key], $direction);
        if ($columns[$key] !== $unique) $query->orderBy($unique, $direction);
    }
}
