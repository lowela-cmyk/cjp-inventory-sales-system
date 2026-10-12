<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login | CJP Southern Star</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="login-page">
    <main class="login-shell">
        <section class="login-panel" aria-labelledby="login-title">
            <div class="login-form-wrap">
                <h1 id="login-title">Log In</h1>

                <form class="login-form" aria-label="Sign in form" method="POST" action="{{ route('login.store') }}">
                    @csrf
                    <div class="login-field">
                        <label for="username">USERNAME</label>
                        <input id="username" name="username" type="text" placeholder="Username" autocomplete="username" value="{{ old('username') }}" required>
                    </div>

                    <div class="login-field">
                        <label for="password">PASSWORD</label>
                        <div class="password-input-wrap">
                            <input id="password" name="password" type="password" placeholder="Password" autocomplete="current-password" required>
                            <button type="button" class="password-toggle-btn" aria-label="Show password" title="Show password" aria-pressed="false" data-password-toggle="password">
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
                    </div>

                    @if ($errors->any())
                        <p class="login-error">{{ $errors->first() }}</p>
                    @endif

                    @if (session('status') && session('toast_context') !== 'logout')
                        <p class="login-notice" role="status">{{ session('status') }}</p>
                    @endif

                    <button class="login-submit" type="submit">LOG IN</button>
                </form>

                <p class="login-forgot"><a href="{{ route('password.request') }}">Forgot Password?</a></p>

                <p class="login-register">Don&rsquo;t have an account? <a href="{{ route('register') }}">Click Here.</a></p>
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
    <x-toast-stack context="logout" />
</body>
</html>
