@props(['status' => 'Pending'])

@php
    $slug = strtolower(str_replace([' ', '/'], '-', $status));
@endphp

<span class="status-badge status-{{ $slug }}" style="color: #0d1424;">{{ $status }}</span>
