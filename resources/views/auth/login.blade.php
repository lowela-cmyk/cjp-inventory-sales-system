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
                        <input id="password" name="password" type="password" placeholder="Password" autocomplete="current-password" required>
                    </div>

                    @if ($errors->any())
                        <p class="login-error">{{ $errors->first() }}</p>
                    @endif

                    <button class="login-submit" type="submit">LOG IN</button>
                </form>

                <p class="login-forgot"><a href="{{ route('password.request') }}">Forgot Password?</a></p>

                @if (session('status'))
                    <div class="login-success" role="status">
                        <span class="login-success-mark" aria-hidden="true">&check;</span>
                        <div>
                            <strong>Account ready</strong>
                            <span>{{ session('status') }}</span>
                        </div>
                    </div>
                @endif

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
    <x-toast-stack />
</body>
</html>
