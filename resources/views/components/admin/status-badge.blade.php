@props(['status' => 'Pending'])

@php
    $normalized = strtolower(str_replace([' ', '-', '/'], '_', trim((string) $status)));
    $tone = match ($normalized) {
        'pending', 'pending_receipt', 'open', 'unread', 'draft', 'ordered', 'unlifted' => 'pending',
        'scheduled' => 'scheduled',
        'in_progress', 'in_transit', 'lifted' => 'progress',
        'partial', 'partially_lifted', 'partially_hauled', 'partially_received', 'partially_paid' => 'partial',
        'received', 'completed', 'complete', 'paid', 'active', 'approved', 'clear', 'settled', 'stock_posted', 'read' => 'success',
        'cancelled', 'failed', 'overdue', 'critical', 'depleted' => 'danger',
        'available' => 'available',
        'inactive', 'dismissed' => 'inactive',
        default => 'neutral',
    };
@endphp

<span class="status-badge status-tone-{{ $tone }}">{{ $status }}</span>
