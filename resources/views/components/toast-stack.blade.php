@props(['context' => null])

@php
    $messages = [];
    $contextMatches = $context === null || session('toast_context') === $context;

    if (session('status') && $contextMatches) {
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

    // Messages flashed for the toast only (e.g. the login welcome), never rendered as inline page alerts.
    if (session('toast_message') && $context === null) {
        $messages[] = [
            'type' => session('toast_type', 'success'),
            'title' => session('toast_title', 'Success'),
            'message' => (string) session('toast_message'),
        ];
    }

    if ($errors->any() && $context === null) {
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
                    <strong>{{ $toast['title'] }}</strong>
                    <span>{{ $toast['message'] }}</span>
                </div>
                <button class="toast-dismiss" type="button" data-toast-dismiss aria-label="Dismiss notification">x</button>
            </div>
        @endforeach
    </div>
@endif

{{-- Confirmation for data-changing forms (replaces the older generic Confirm Action modal). --}}
<x-confirm-dialog />
