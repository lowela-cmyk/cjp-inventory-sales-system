@props(['id', 'title', 'wide' => false])

<div class="modal-backdrop" id="{{ $id }}" aria-hidden="true">
    <div class="admin-modal {{ $wide ? 'admin-modal-wide' : '' }}" role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title">
        <div class="modal-titlebar">
            <h2 id="{{ $id }}-title">{{ $title }}</h2>
            <button class="modal-close" type="button" data-modal-close aria-label="Close modal"></button>
        </div>
        <div class="modal-body">
            {{ $slot }}
        </div>
    </div>
</div>
