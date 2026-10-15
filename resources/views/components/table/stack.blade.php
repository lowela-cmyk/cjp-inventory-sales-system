@props(['lines' => []])

{{-- Several closely related values in one table cell, one per line. Each line exports as its own CSV column
     ([data-export-part]); its label comes from CSS (data-label), so exports hold values only. --}}
<span {{ $attributes->class(['cell-stack']) }}>
    @foreach ($lines as $line)
        <span @class(['cell-line', $line['class'] ?? null]) data-export-part @if (! empty($line['label'])) data-label="{{ $line['label'] }}" @endif>{{ $line['value'] }}</span>
    @endforeach
</span>
