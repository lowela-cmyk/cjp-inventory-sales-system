@props(['status' => 'Pending'])

@php
    $normalized = strtolower(str_replace([' ', '-', '/'], '_', trim((string) $status)));
    $tone = match ($normalized) {
        'pending', 'pending_receipt', 'pending_hauling', 'awaiting_garage_receipt', 'not_allocated', 'open', 'unread', 'draft', 'ordered', 'unlifted', 'incomplete', 'prepared' => 'pending',
        'scheduled' => 'scheduled',
        'in_progress', 'in_transit', 'lifted', 'hauled' => 'progress',
        'partial', 'partially_lifted', 'partially_hauled', 'partially_received', 'partially_paid', 'low_stock' => 'partial',
        'received', 'garage_received', 'garage_received_with_direct', 'completed', 'complete', 'paid', 'active', 'approved', 'clear', 'settled', 'stock_posted', 'read', 'delivered', 'released', 'recorded' => 'success',
        'cancelled', 'failed', 'overdue', 'critical', 'depleted', 'unpaid' => 'danger',
        'available', 'direct_to_client' => 'available',
        'inactive', 'dismissed' => 'inactive',
        default => 'neutral',
    };
@endphp

<span class="status-badge status-tone-{{ $tone }}">{{ $status }}</span>
