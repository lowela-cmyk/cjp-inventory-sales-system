@php
    $messages = [];

    if (session('status') && session('toast_context') === 'logout') {
        $messages[] = [
            'type' => session('toast_type', 'success'),
            'title' => session('toast_title', 'CJP Dispatch'),
            'message' => session('status'),
        ];
    }
@endphp

@if ($messages !== [])
    <div class="toast-stack" aria-live="polite" aria-atomic="true">
        @foreach ($messages as $toast)
            <div class="cjp-toast cjp-toast-{{ $toast['type'] }}" role="{{ $toast['type'] === 'error' ? 'alert' : 'status' }}">
                <span class="cjp-toast-icon" aria-hidden="true">{{ $toast['type'] === 'error' ? '!' : ($toast['type'] === 'warning' ? 'i' : 'CJP') }}</span>
                <div>
                    <strong>{{ $toast['title'] }}</strong>
                    <span>{{ $toast['message'] }}</span>
                </div>
                <button class="toast-dismiss" type="button" data-toast-dismiss aria-label="Dismiss notification">x</button>
            </div>
        @endforeach
    </div>
@endif
