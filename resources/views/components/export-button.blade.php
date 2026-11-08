@props(['href' => null, 'type' => 'button'])

{{-- Small icon + label Export button that sits in the page heading row. Without $href it exports the visible table
     (data-export-table, see app.js); with $href it is a link to a server export. Extra attributes pass through. --}}
@php($icon = '<svg class="btn-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></svg>')
@if ($href)
    <a {{ $attributes->class(['btn', 'btn-secondary', 'btn-export']) }} href="{{ $href }}">{!! $icon !!}<span data-export-label>Export</span></a>
@elseif ($type === 'submit')
    <button {{ $attributes->class(['btn', 'btn-secondary', 'btn-export']) }} type="submit">{!! $icon !!}<span data-export-label>Export</span></button>
@else
    <button {{ $attributes->class(['btn', 'btn-secondary', 'btn-export']) }} type="button" data-export-table>{!! $icon !!}<span data-export-label>Export</span></button>
@endif
