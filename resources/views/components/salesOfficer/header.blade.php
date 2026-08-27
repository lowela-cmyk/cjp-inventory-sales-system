@props([
    'title' => 'Sales Management',
    'subtitle' => 'INVENTORY AND SALES MANAGEMENT SYSTEM',
])

<header class="admin-header">
    <div>
        <h1>{{ $title }}</h1>
        <p>{{ $subtitle }}</p>
    </div>
    <div class="header-profile">
        <div class="profile-mark" aria-hidden="true"></div>
        <div>
            <div class="profile-name">Joel Banta</div>
            <div class="profile-role">Sales Officer</div>
        </div>
    </div>
</header>
