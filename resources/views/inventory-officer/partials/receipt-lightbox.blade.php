{{--
    Withdrawal receipt lightbox: only the uploaded image(s), large and centered, with a close button.
    Several receipts get previous / next arrows and thumbnails. Images load from the role-gated
    withdrawal-receipts.show route (private disk), and only when the lightbox is opened.
    Expects: $lightboxId, $purchaseCode, $receipts (each: url, haul_code, uploaded_at, notes).
--}}
<div class="modal-backdrop receipt-lightbox" id="{{ $lightboxId }}" aria-hidden="true" data-receipt-lightbox>
    <div class="receipt-lightbox-dialog" role="dialog" aria-modal="true" aria-label="Withdrawal receipt for {{ $purchaseCode }}">
        <button class="receipt-lightbox-close" type="button" data-modal-close aria-label="Close receipt"></button>
        @foreach ($receipts as $receipt)
            <figure class="receipt-lightbox-slide" data-receipt-slide @if (! $loop->first) hidden @endif>
                <img data-src="{{ $receipt['url'] }}" alt="Withdrawal receipt {{ $receipt['haul_code'] }} for {{ $purchaseCode }}">
                <figcaption>{{ $receipt['haul_code'] }} &middot; {{ $receipt['uploaded_at'] }}@if (count($receipts) > 1) &middot; {{ $loop->iteration }} / {{ count($receipts) }}@endif</figcaption>
            </figure>
        @endforeach
        @if (count($receipts) > 1)
            <button class="receipt-lightbox-nav is-prev" type="button" data-receipt-step="-1" aria-label="Previous receipt">&#8249;</button>
            <button class="receipt-lightbox-nav is-next" type="button" data-receipt-step="1" aria-label="Next receipt">&#8250;</button>
            <div class="receipt-lightbox-thumbs">
                @foreach ($receipts as $receipt)
                    <button type="button" data-receipt-go="{{ $loop->index }}" aria-label="Show receipt {{ $receipt['haul_code'] }}" @class(['is-active' => $loop->first])>
                        <img data-src="{{ $receipt['url'] }}" alt="">
                    </button>
                @endforeach
            </div>
        @endif
    </div>
</div>
