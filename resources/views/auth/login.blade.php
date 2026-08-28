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

                @php
                    // TEMPORARY DEMO LOGIN BYPASS
                    // Replace with real authentication later.
                @endphp
                <form class="login-form" aria-label="Sign in form" data-demo-login-form>
                    <div class="login-field">
                        <label for="username">USERNAME</label>
                        <input id="username" name="username" type="text" placeholder="Username" autocomplete="username">
                    </div>

                    <div class="login-field">
                        <label for="role">ROLE</label>
                        <select id="role" name="role" data-demo-role>
                            <option value="" selected disabled>Role</option>
                            <option value="admin">Admin</option>
                            <option value="dispatch">Dispatch Officer</option>
                            <option value="inventory-officer">Inventory Officer</option>
                            <option value="sales-officer">Sales Officer</option>
                            <option value="driver">Driver</option>
                        </select>
                    </div>

                    <div class="login-field">
                        <label for="password">PASSWORD</label>
                        <input id="password" name="password" type="password" placeholder="Password" autocomplete="current-password">
                    </div>

                    <p class="login-error" data-demo-login-error hidden>Please select a role.</p>

                    <button class="login-submit" type="button" data-demo-login-button>LOG IN</button>
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
