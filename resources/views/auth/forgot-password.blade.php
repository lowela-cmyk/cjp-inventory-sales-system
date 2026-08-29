<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot Password | CJP Southern Star</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="login-page">
    <main class="login-shell">
        <section class="login-panel" aria-labelledby="forgot-title">
            <div class="login-form-wrap">
                <h1 id="forgot-title">Reset Access</h1>

                <form class="login-form" aria-label="Request password reset code" method="POST" action="{{ route('password.email') }}">
                    @csrf
                    <div class="login-field">
                        <label for="email">ACCOUNT EMAIL</label>
                        <input id="email" name="email" type="email" placeholder="Registered Email" autocomplete="email" value="{{ old('email') }}" required>
                    </div>

                    @if ($errors->any())
                        <p class="login-error">{{ $errors->first() }}</p>
                    @endif

                    <button class="login-submit auth-action-submit" type="submit">SEND CODE</button>
                </form>

                <p class="login-register">Remembered it? <a href="{{ route('login') }}">Log In.</a></p>
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
