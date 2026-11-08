@props(['value', 'liters' => null])

{{-- One stat-card figure in the shared unit format: money as "PHP 5,391,234", volumes as "717,791 L" (never KL), counts
     as "1,234". Below 1024px the short form shows instead ("PHP 5.39M", "717.8K L") so the figure never wraps; the
     full value is always in the tooltip. Pass $liters (a raw number) when the source string is rounded, e.g. "717.79 KL".
     Display only: the value itself comes unchanged from the controller / service. --}}
@php
    $raw = trim((string) $value);
    $full = $raw;
    $short = $raw;

    $compact = function (float $number, int $decimals): string {
        $abs = abs($number);
        foreach ([[1e9, 'B'], [1e6, 'M'], [1e3, 'K']] as [$size, $suffix]) {
            if ($abs >= $size) {
                return rtrim(rtrim(number_format($number / $size, $decimals, '.', ''), '0'), '.').$suffix;
            }
        }

        return number_format($number, $abs == floor($abs) ? 0 : 2);
    };
    $toNumber = fn (string $text): float => (float) str_replace(',', '', $text);

    if ($liters !== null || preg_match('/^(-?[\d,]+(?:\.\d+)?)\s*(KL|L)$/i', $raw, $match)) {
        $amount = $liters !== null ? (float) $liters : $toNumber($match[1]) * (strtoupper($match[2]) === 'KL' ? 1000 : 1);
        $full = number_format($amount, abs($amount - round($amount)) >= 0.005 ? 2 : 0).' L';
        $short = $compact($amount, 1).' L';
    } elseif (preg_match('/^(-?)PHP\s?(-?[\d,]+(?:\.\d+)?)$/', $raw, $match)) {
        $amount = $toNumber($match[2]) * ($match[1] === '-' ? -1 : 1);
        $short = ($amount < 0 ? '-' : '').'PHP '.$compact(abs($amount), 2);
    } elseif (preg_match('/^-?[\d,]+(?:\.\d+)?$/', $raw) && abs($toNumber($raw)) >= 100000) {
        // Plain counts fit as they are; only very large ones are shortened.
        $short = $compact($toNumber($raw), 1);
    }
@endphp

<strong {{ $attributes->class(['stat-value']) }} title="{{ $full }}">
    @if ($short === $full)
        {{ $full }}
    @else
        <span class="stat-value-full">{{ $full }}</span><span class="stat-value-short">{{ $short }}</span>
    @endif
</strong>
