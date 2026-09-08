@php
    $messages = [];

    if (session('status')) {
        $type = session('toast_type', 'success');
        $status = (string) session('status');
        $statusLower = strtolower($status);

        $messages[] = [
            'type' => $type,
            'title' => session('toast_title', match ($type) {
                'warning' => str_contains($statusLower, 'pending') ? 'Account pending' : (str_contains($statusLower, 'rejected') ? 'Account rejected' : 'Please confirm'),
                'error' => 'Action needed',
                default => match (true) {
                    str_contains($statusLower, 'welcome') => 'Login success',
                    str_contains($statusLower, 'approved') => 'Account approved',
                    str_contains($statusLower, 'updated') => 'Successful update',
                    str_contains($statusLower, 'deactivated') || str_contains($statusLower, 'cancelled') || str_contains($statusLower, 'deleted') => 'Successful delete',
                    str_contains($statusLower, 'created') || str_contains($statusLower, 'recorded') || str_contains($statusLower, 'scheduled') || str_contains($statusLower, 'uploaded') => 'Successful save',
                    default => 'Success',
                },
            }),
            'message' => $status,
        ];
    }

    if ($errors->any()) {
        $error = (string) $errors->first();
        $errorLower = strtolower($error);

        $messages[] = [
            'type' => 'error',
            'title' => match (true) {
                str_contains($errorLower, 'pending') => 'Account pending',
                str_contains($errorLower, 'rejected') => 'Account rejected',
                default => 'Action needed',
            },
            'message' => $error,
        ];
    }
@endphp

@if ($messages !== [])
    <div class="toast-stack" aria-live="polite" aria-atomic="true">
        @foreach ($messages as $toast)
            <div class="cjp-toast cjp-toast-{{ $toast['type'] }}" role="{{ $toast['type'] === 'error' ? 'alert' : 'status' }}">
                <span class="cjp-toast-logo" aria-hidden="true">
                    <img src="{{ asset('images/cjp-logo.png') }}" alt="">
                </span>
                <div>
                    <span class="cjp-toast-status-icon" aria-hidden="true">{{ $toast['type'] === 'error' ? '!' : ($toast['type'] === 'warning' ? 'i' : '✓') }}</span>
                    <strong>{{ $toast['title'] }}</strong>
                    <span>{{ $toast['message'] }}</span>
                </div>
                <button class="toast-dismiss" type="button" data-toast-dismiss aria-label="Dismiss notification">x</button>
            </div>
        @endforeach
    </div>
@endif

<div class="modal-backdrop system-confirm-backdrop" id="system-confirm-modal" aria-hidden="true">
    <div class="admin-modal system-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="system-confirm-title" aria-describedby="system-confirm-message">
        <div class="modal-titlebar system-confirm-titlebar">
            <span class="modal-brand-logo" aria-hidden="true">
                <img src="{{ asset('images/cjp-logo.png') }}" alt="">
            </span>
            <h2 id="system-confirm-title">Confirm Action</h2>
            <button class="modal-close" type="button" data-modal-close aria-label="Close modal"></button>
        </div>
        <div class="modal-body system-confirm-body">
            <div class="system-confirm-status" aria-hidden="true">!</div>
            <p id="system-confirm-message">Are you sure you want to continue?</p>
            <div class="modal-actions system-confirm-actions">
                <button class="btn btn-pill btn-secondary" type="button" data-modal-close>Cancel</button>
                <button class="btn btn-pill btn-danger" type="button" data-confirm-accept>Continue</button>
            </div>
        </div>
    </div>
</div>
