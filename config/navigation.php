<?php

/*
|--------------------------------------------------------------------------
| Navigation (one source for the sidebar and the bottom nav)
|--------------------------------------------------------------------------
| Per layout: the pages in sidebar order. The bottom nav (phones, tablets and touch screens up to 1366px) shows the
| entries marked 'bar' in 'bar' order; when a role has more than five pages, only four go on the bar and the rest go
| into the "More" sheet. 'module' drives the red "new updates" dot; 'short' is the bottom-nav label; 'icon' is one of
| the icons in resources/views/components/nav/icon.blade.php. Display only: routes and access rules are unchanged.
*/

return [
    'admin' => [
        'label' => 'Admin navigation',
        'nav_class' => null,
        'items' => [
            ['route' => 'admin.dashboard', 'label' => 'Dashboard', 'short' => 'Dashboard', 'icon' => 'dashboard', 'bar' => 1],
            ['route' => 'admin.inventory', 'module' => 'inventory', 'label' => 'Inventory', 'short' => 'Inventory', 'icon' => 'inventory', 'bar' => 2],
            ['route' => 'admin.ledger', 'module' => 'ledger', 'label' => 'Ledger', 'short' => 'Ledger', 'icon' => 'ledger'],
            ['route' => 'admin.fuel-lifting', 'module' => 'fuel-lifting', 'label' => 'Fuel Lifting', 'short' => 'Lifting', 'icon' => 'truck', 'bar' => 4],
            ['route' => 'admin.sales', 'module' => 'sales', 'label' => 'Sales', 'short' => 'Sales', 'icon' => 'sales', 'bar' => 3],
            ['route' => 'admin.alerts', 'module' => 'alerts', 'label' => 'Alerts', 'short' => 'Alerts', 'icon' => 'alerts', 'alerts' => true],
            ['route' => 'admin.user-management', 'module' => 'user-management', 'label' => 'User Management', 'short' => 'Users', 'icon' => 'users'],
        ],
    ],

    'inventory-officer' => [
        'label' => 'Inventory officer navigation',
        'nav_class' => 'inventory-officer-nav',
        'items' => [
            ['route' => 'inventory-officer.inventory', 'module' => 'inventory', 'label' => 'Inventory', 'short' => 'Inventory', 'icon' => 'inventory', 'bar' => 1],
            ['route' => 'inventory-officer.ledger', 'module' => 'ledger', 'label' => 'Ledger', 'short' => 'Ledger', 'icon' => 'ledger', 'bar' => 2],
            ['route' => 'inventory-officer.alerts', 'module' => 'alerts', 'label' => 'Alerts', 'short' => 'Alerts', 'icon' => 'alerts', 'alerts' => true, 'bar' => 3],
        ],
    ],

    'sales-officer' => [
        'label' => 'Sales officer navigation',
        'nav_class' => 'sales-officer-nav',
        'items' => [
            ['route' => 'sales-officer.sales', 'module' => 'sales', 'label' => 'Sales', 'short' => 'Sales', 'icon' => 'sales', 'bar' => 1],
            ['route' => 'sales-officer.alerts', 'module' => 'alerts', 'label' => 'Alerts', 'short' => 'Alerts', 'icon' => 'alerts', 'alerts' => true, 'bar' => 2],
        ],
    ],

    'dispatch' => [
        'label' => 'Dispatch navigation',
        'nav_class' => 'dispatch-nav',
        'items' => [
            ['route' => 'dispatch.ledger', 'module' => 'ledger', 'label' => 'Ledger', 'short' => 'Ledger', 'icon' => 'ledger', 'bar' => 2],
            ['route' => 'dispatch.fuel-lifting', 'module' => 'fuel-lifting', 'label' => 'Fuel Lifting', 'short' => 'Lifting', 'icon' => 'truck', 'bar' => 1],
            ['route' => 'dispatch.alerts', 'module' => 'alerts', 'label' => 'Alerts', 'short' => 'Alerts', 'icon' => 'alerts', 'alerts' => true, 'bar' => 3],
        ],
    ],

    'driver' => [
        'label' => 'Driver navigation',
        'nav_class' => 'driver-nav',
        'items' => [
            ['route' => 'driver.fuel-lifting', 'module' => 'fuel-lifting', 'label' => 'Fuel Lifting', 'short' => 'Lifting', 'icon' => 'truck', 'bar' => 1],
        ],
    ],
];
