@php
    $messages = [];

    if (session('status')) {
        $messages[] = [
            'type' => session('toast_type', 'success'),
            'title' => session('toast_title', 'CJP Dispatch'),
            'message' => session('status'),
        ];
    }

    if ($errors->any()) {
        $messages[] = [
            'type' => 'error',
            'title' => 'CJP Checkpoint',
            'message' => $errors->first(),
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
            </div>
        @endforeach
    </div>
@endif
