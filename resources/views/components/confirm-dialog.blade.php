{{-- One confirmation dialog per page, used by every data-changing form (resources/js/confirm.js fills it).
     Desktop: a centered dialog. Phones: a bottom sheet. Esc / Back / the safe button cancel; focus starts on the safe
     button. The confirm button turns into a spinner while the form is being sent. --}}
<div class="confirm-dialog" data-confirm-dialog hidden>
    <div class="confirm-scrim" data-cd-cancel></div>
    <section class="confirm-panel" role="dialog" aria-modal="true" aria-labelledby="cjp-confirm-title" aria-describedby="cjp-confirm-body">
        <header class="confirm-head">
            <span class="confirm-icon" aria-hidden="true"></span>
            <h2 id="cjp-confirm-title" data-cd-title>Confirm</h2>
        </header>
        <div class="confirm-body" id="cjp-confirm-body" data-cd-body></div>
        <div class="confirm-typed" data-cd-typed hidden>
            <label for="cjp-confirm-typed-input">Type <strong data-cd-typed-value></strong> to confirm</label>
            <input id="cjp-confirm-typed-input" type="text" autocomplete="off" autocapitalize="characters" spellcheck="false" data-cd-typed-input>
        </div>
        <footer class="confirm-actions">
            <button class="btn btn-secondary confirm-cancel" type="button" data-cd-cancel><span data-cd-cancel-label>Cancel</span></button>
            <button class="btn btn-primary confirm-ok" type="button" data-cd-ok>
                <span class="confirm-spinner" aria-hidden="true"></span>
                <span data-cd-ok-label>Confirm</span>
            </button>
        </footer>
    </section>
</div>
