{{--
    Part 26 #5: the withdrawal receipt picked for upload, shown before it is sent (app.js receiptPreview): an image as
    a thumbnail, a PDF in a small document frame. Remove clears the choice; choosing again replaces it.
--}}
<div class="withdrawal-preview" data-withdrawal-preview hidden>
    <img alt="" data-preview-image hidden>
    <iframe class="withdrawal-preview-pdf" title="Selected receipt (PDF)" data-preview-pdf hidden></iframe>
    <div class="withdrawal-preview-meta">
        <span data-preview-name></span>
        <button class="btn btn-small btn-secondary" type="button" data-preview-remove>Remove</button>
    </div>
</div>
<p class="withdrawal-upload-message" role="status" aria-live="polite" data-upload-message hidden></p>
