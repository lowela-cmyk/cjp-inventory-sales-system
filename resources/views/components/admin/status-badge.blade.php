@props(['status' => 'Pending'])

@php
    $normalized = strtolower(str_replace([' ', '-', '/'], '_', trim((string) $status)));
    // "Refund due: ₱X" carries its amount, so match it by prefix.
    $tone = str_starts_with($normalized, 'refund_due') ? 'partial' : match ($normalized) {
        'pending', 'planned', 'pending_receipt', 'no_receipt', 'pending_hauling', 'awaiting_garage_receipt', 'not_allocated', 'open', 'unread', 'draft', 'ordered', 'unlifted', 'incomplete', 'prepared' => 'pending',
        'scheduled', 'confirmed', 'info' => 'scheduled',
        'assigned', 'in_progress', 'in_transit', 'in_use', 'lifted', 'hauled', 'out_for_delivery' => 'progress',
        'partial', 'maintenance', 'warning', 'partially_lifted', 'partially_hauled', 'partially_received', 'partially_paid', 'partially_used', 'low_stock' => 'partial',
        'received', 'garage_received', 'garage_received_with_direct', 'uploaded', 'completed', 'complete', 'paid', 'active', 'approved', 'clear', 'settled', 'stock_posted', 'read', 'delivered', 'released', 'recorded' => 'success',
        'cancelled', 'rejected', 'failed', 'overdue', 'critical', 'depleted', 'unpaid' => 'danger',
        'available', 'direct_to_client' => 'available',
        'inactive', 'dismissed' => 'inactive',
        default => 'neutral',
    };
@endphp

<span class="status-badge status-tone-{{ $tone }}">{{ $status }}</span>
