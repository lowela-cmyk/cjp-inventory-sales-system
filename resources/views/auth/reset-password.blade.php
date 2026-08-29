<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Password | CJP Southern Star</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="login-page">
    <main class="login-shell">
        <section class="login-panel" aria-labelledby="reset-title">
            <div class="login-form-wrap">
                <h1 id="reset-title">Enter Code</h1>

                @if (session('status'))
                    <div class="login-success reset-success" role="status">
                        <span class="login-success-mark" aria-hidden="true">&check;</span>
                        <div>
                            <strong>Check your email</strong>
                            <span>{{ session('status') }}</span>
                        </div>
                    </div>
                @endif

                <form class="login-form" aria-label="Reset password with code" method="POST" action="{{ route('password.update') }}">
                    @csrf
                    <div class="login-field">
                        <label for="email">ACCOUNT EMAIL</label>
                        <input id="email" name="email" type="email" placeholder="Registered Email" autocomplete="email" value="{{ old('email') }}" required>
                    </div>

                    <div class="login-field">
                        <label for="code">RESET CODE</label>
                        <input id="code" name="code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" placeholder="6-Digit Code" autocomplete="one-time-code" value="{{ old('code') }}" required>
                    </div>

                    <div class="login-field">
                        <label for="password">NEW PASSWORD</label>
                        <input id="password" name="password" type="password" placeholder="New Password" autocomplete="new-password" required>
                    </div>

                    <div class="login-field">
                        <label for="password_confirmation">CONFIRM PASSWORD</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" placeholder="Confirm Password" autocomplete="new-password" required>
                    </div>

                    @if ($errors->any())
                        <p class="login-error">{{ $errors->first() }}</p>
                    @endif

                    <button class="login-submit auth-action-submit" type="submit">UPDATE PASSWORD</button>
                </form>

                <p class="login-register">Need a new code? <a href="{{ route('password.request') }}">Send Again.</a></p>
            </div>
        </section>

        <section class="login-brand-panel" aria-label="CJP Southern Star">
            <div class="login-brand-content">
                <img src="{{ asset('images/cjp-logo.png') }}" alt="CJP Southern Star logo">
                <h2>CJP SOUTHERN STAR</h2>
                <p>Inventory and Sales Monitoring System</p>
            </div>
        </section>
    </main>
</body>
</html>
