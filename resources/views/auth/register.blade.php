<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign Up | CJP Southern Star</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="login-page">
    <main class="login-shell register-shell">
        <section class="login-panel register-panel" aria-labelledby="register-title">
            <div class="login-form-wrap register-form-wrap">
                <h1 id="register-title">Sign Up</h1>

                <form class="login-form register-form" aria-label="Sign up form" method="POST" action="{{ route('register.store') }}">
                    @csrf
                    <div class="login-field">
                        <label for="full_name">FULL NAME</label>
                        <input id="full_name" name="full_name" type="text" placeholder="Full Name" autocomplete="name" value="{{ old('full_name') }}" required>
                    </div>

                    <div class="login-field">
                        <label for="email">EMAIL</label>
                        <input id="email" name="email" type="email" placeholder="Email" autocomplete="email" value="{{ old('email') }}" required>
                    </div>

                    <div class="login-field">
                        <label for="contact_number">CONTACT NUMBER</label>
                        <input id="contact_number" name="contact_number" type="tel" placeholder="Contact Number" autocomplete="tel" value="{{ old('contact_number') }}">
                    </div>

                    <div class="login-field">
                        <label for="register_role">ROLE</label>
                        <select id="register_role" name="role" required>
                            <option value="" @selected(! old('role')) disabled>Role</option>
                            <option value="admin" @selected(old('role') === 'admin')>Admin</option>
                            <option value="inventory_officer" @selected(old('role') === 'inventory_officer')>Inventory Officer</option>
                            <option value="sales_officer" @selected(old('role') === 'sales_officer')>Sales Officer</option>
                            <option value="dispatch_officer" @selected(old('role') === 'dispatch_officer')>Dispatch Officer</option>
                            <option value="driver" @selected(old('role') === 'driver')>Driver</option>
                        </select>
                    </div>

                    <div class="login-field">
                        <label for="register_password">PASSWORD</label>
                        <input id="register_password" name="password" type="password" placeholder="Password" autocomplete="new-password" required>
                    </div>

                    <div class="login-field">
                        <label for="password_confirmation">CONFIRM PASSWORD</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" placeholder="Confirm Password" autocomplete="new-password" required>
                    </div>

                    @if ($errors->any())
                        <p class="login-error">{{ $errors->first() }}</p>
                    @endif

                    <button class="login-submit register-submit" type="submit">SIGN UP</button>
                </form>

                <p class="login-register register-login">Already have an account? <a href="{{ route('login') }}">Log In.</a></p>
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
