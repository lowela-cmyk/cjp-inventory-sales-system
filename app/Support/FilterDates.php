<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;

/**
 * Date filters. Part 12 (October 26, 2026): a Date filter is a From / To range of date pickers (no dropdown of days).
 * An old link with a single day (e.g. ?date=2026-10-26) still works and means From = To = that day.
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

    /**
     * Validation rules for a From / To pair (and the old single-day parameter).
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(string $from = 'date_from', string $to = 'date_to', ?string $single = 'date'): array
    {
        return [$from => ['nullable', 'date'], $to => ['nullable', 'date']] + ($single ? [$single => ['nullable', 'date']] : []);
    }

    /**
     * The From / To days of a range filter, as Y-m-d (null when open). A single day from an old link fills both; a
     * reversed range is put in order.
     *
     * @param  array<string, mixed>  $data
     * @return array{from: ?string, to: ?string}
     */
    public static function range(array $data, string $from = 'date_from', string $to = 'date_to', ?string $single = 'date'): array
    {
        $day = fn (mixed $value): ?string => $value ? substr((string) $value, 0, 10) : null;
        $start = $day($data[$from] ?? null);
        $end = $day($data[$to] ?? null);

        if (! $start && ! $end && $single && ! empty($data[$single])) {
            $start = $end = $day($data[$single]);
        }
        if ($start && $end && $start > $end) {
            [$start, $end] = [$end, $start];
        }

        return ['from' => $start, 'to' => $end];
    }

    /**
     * Keeps the rows whose $column falls on or between the From / To days.
     *
     * @param  array{from: ?string, to: ?string}  $range
     */
    public static function apply(Builder $query, string $column, array $range): Builder
    {
        return $query
            ->when($range['from'] ?? null, fn (Builder $query, string $from): Builder => $query->whereDate($column, '>=', $from))
            ->when($range['to'] ?? null, fn (Builder $query, string $to): Builder => $query->whereDate($column, '<=', $to));
    }
}
