@props([
    'title' => 'Dispatch Officer',
    'subtitle' => 'INVENTORY AND SALES MANAGEMENT SYSTEM',
    'active' => 'ledger',
])

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} | Dispatch Officer</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="admin-body">
    <button class="mobile-menu-button" type="button" data-sidebar-toggle aria-label="Open navigation">
        <span></span>
        <span></span>
        <span></span>
    </button>

    <div class="admin-shell">
        <x-dispatch.sidebar :active="$active" />

        <main class="admin-main">
            <x-dispatch.header :title="$title" :subtitle="$subtitle" />

            <section class="admin-content">
                {{ $slot }}
            </section>
        </main>
    </div>

    <div class="sidebar-scrim" data-sidebar-toggle></div>
    <x-toast-stack />
</body>
</html>
