@props(['status' => 'Pending'])

@php
    $slug = strtolower(str_replace([' ', '/'], '-', $status));
@endphp

<span class="status-badge status-{{ $slug }}">{{ $status }}</span>
