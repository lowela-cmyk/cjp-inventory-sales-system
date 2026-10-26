@props(['id', 'title', 'wide' => false, 'size' => null, 'showBrand' => true, 'footer' => null, 'resetOnClose' => false])

{{-- Header and optional footer stay fixed; only the body scrolls (see .admin-modal in app.css). --}}
{{-- resetOnClose: a create modal that starts blank again whenever it is closed (see app.js). --}}
<div class="modal-backdrop" id="{{ $id }}" aria-hidden="true" @if ($resetOnClose) data-reset-on-close @endif>
    <div @class(['admin-modal', 'admin-modal-wide' => $wide, 'admin-modal-'.$size => $size]) role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title">
        <div class="modal-titlebar {{ $showBrand ? '' : 'modal-titlebar-without-brand' }}">
            @if ($showBrand)
                <span class="modal-brand-logo" aria-hidden="true">
                    <img src="{{ asset('images/cjp-logo.png') }}" alt="">
                </span>
            @endif
            <h2 id="{{ $id }}-title">{{ $title }}</h2>
            <button class="modal-close" type="button" data-modal-close aria-label="Close modal"></button>
        </div>
        <div class="modal-body">
            {{ $slot }}
        </div>
        @if ($footer)
            <div class="modal-footer">
                {{ $footer }}
            </div>
        @endif
    </div>
</div>
