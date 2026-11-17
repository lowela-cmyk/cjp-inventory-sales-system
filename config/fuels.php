<?php

return [
    // Part 23 #2: smallest quantity that may be ordered from a depot (purchase / stock-in), in liters.
    'min_depot_order_liters' => (float) env('CJP_MIN_DEPOT_ORDER_LITERS', 40000),

    'approved' => [
        'F1' => [
            'code' => 'F1',
            'name' => 'F1',
        ],
        'UNL' => [
            'code' => 'UNL',
            'name' => 'Unleaded',
        ],
        'DSL' => [
            'code' => 'DSL',
            'name' => 'Diesel',
        ],
        'PREM' => [
            'code' => 'PREM',
            'name' => 'Premium',
        ],
    ],
];
