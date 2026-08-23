<?php

return [
    'bootstrap_admin' => [
        'enabled' => env('JARDI_BOOTSTRAP_ADMIN_ENABLED', false),
        'name' => env('JARDI_PLATFORM_ADMIN_NAME'),
        'email' => env('JARDI_PLATFORM_ADMIN_EMAIL'),
        'password' => env('JARDI_PLATFORM_ADMIN_PASSWORD'),
    ],

    'demo' => [
        'enabled' => env('JARDI_DEMO_SEED_ENABLED', false),
    ],
];
