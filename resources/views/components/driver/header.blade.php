@props([
    'title' => 'Fuel Lifting Operations',
    'subtitle' => 'INVENTORY AND SALES MANAGEMENT SYSTEM',
    'driverName' => 'Manuel Ligaya',
])

<header class="admin-header">
    <div>
        <h1>{{ $title }}</h1>
        <p>{{ $subtitle }}</p>
    </div>
    <div class="header-profile">
        <div class="profile-mark" aria-hidden="true"></div>
        <div>
            <div class="profile-name">{{ $driverName }}</div>
            <div class="profile-role">Driver</div>
        </div>
    </div>
</header>
