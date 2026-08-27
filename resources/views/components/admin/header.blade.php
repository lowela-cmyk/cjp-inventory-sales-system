@props([
    'title' => 'Admin',
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
            <div class="profile-name">CJ Pilar</div>
            <div class="profile-role">Admin</div>
        </div>
    </div>
</header>
