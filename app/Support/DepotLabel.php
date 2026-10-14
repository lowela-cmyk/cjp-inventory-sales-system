<?php

namespace App\Support;

/**
 * Builds the single display label for a pickup depot: "Name <em dash> Address".
 *
 * The separator is written as a Unicode escape so this source file stays plain ASCII and the
 * label cannot be corrupted by editors or tools that re-encode files (UTF-8 read as Windows-1252).
 */
final class DepotLabel
{
    public const SEPARATOR = " \u{2014} ";

    public static function make(?string $name, ?string $address): string
    {
        $name = trim((string) $name);
        $address = trim((string) $address);

        if ($address === '') {
            return $name;
        }

        return $name === '' ? $address : $name.self::SEPARATOR.$address;
    }
}
