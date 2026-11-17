<?php

namespace App\Support;

final class TablePagination
{
    public const SIZES = [10, 25, 50, 100];

    /** Derived driver task rows keep their role-scoped financial and allocation calculations. */
    public static function collection(\Illuminate\Support\Collection $rows, string $pageName): \Illuminate\Pagination\LengthAwarePaginator
    {
        $size = self::size();
        $page = \Illuminate\Pagination\Paginator::resolveCurrentPage($pageName);

        return (new \Illuminate\Pagination\LengthAwarePaginator($rows->forPage($page, $size)->values(), $rows->count(), $size, $page, [
            'path' => \Illuminate\Pagination\Paginator::resolveCurrentPath(), 'pageName' => $pageName,
        ]))->withQueryString();
    }

    public static function size(): int
    {
        $value = request()->query('per_page', 25);

        return is_scalar($value) && in_array((string) $value, ['10', '25', '50', '100'], true) ? (int) $value : 25;
    }
}
