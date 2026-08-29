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
                        <label for="role">ROLE</label>
                        <select id="role" name="role" required>
                            <option value="" @selected(! old('role')) disabled>Role</option>
                            <option value="admin" @selected(old('role') === 'admin')>Admin</option>
                            <option value="dispatch_officer" @selected(old('role') === 'dispatch_officer')>Dispatch Officer</option>
                            <option value="inventory_officer" @selected(old('role') === 'inventory_officer')>Inventory Officer</option>
                            <option value="sales_officer" @selected(old('role') === 'sales_officer')>Sales Officer</option>
                            <option value="driver" @selected(old('role') === 'driver')>Driver</option>
                        </select>
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
</body>
</html>
