@props(['id', 'name', 'placeholder' => '', 'required' => false, 'form' => null, 'autocomplete' => 'new-password'])
{{-- Password field with the same Show/Hide toggle as the sign-in page (handled by [data-password-toggle] in app.js).
     autocomplete="new-password" keeps browsers from filling a saved password into account forms. --}}
<div class="password-input-wrap">
    <input id="{{ $id }}" name="{{ $name }}" type="password" placeholder="{{ $placeholder }}" autocomplete="{{ $autocomplete }}" @if ($form) form="{{ $form }}" @endif @required($required) {{ $attributes }}>
    <button type="button" class="password-toggle-btn" aria-label="Show password" title="Show password" aria-pressed="false" aria-controls="{{ $id }}" data-password-toggle="{{ $id }}">
        <svg class="eye-icon eye-show" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"></path>
            <circle cx="12" cy="12" r="3"></circle>
        </svg>
        <svg class="eye-icon eye-hide" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M9.88 9.88a3 3 0 1 0 4.24 4.24"></path>
            <path d="M10.73 5.08A10.43 10.43 0 0 1 12 5c7 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"></path>
            <path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3 7 10 7a9.74 9.74 0 0 0 5.39-1.61"></path>
            <line x1="2" y1="2" x2="22" y2="22"></line>
        </svg>
    </button>
</div>
