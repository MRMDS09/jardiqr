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

        'organization' => [
            'name' => env('JARDI_DEMO_ORGANIZATION_NAME'),
            'slug' => env('JARDI_DEMO_ORGANIZATION_SLUG'),
            'email' => env('JARDI_DEMO_ORGANIZATION_EMAIL'),
            'phone' => env('JARDI_DEMO_ORGANIZATION_PHONE'),
            'trial_days' => (int) env('JARDI_DEMO_TRIAL_DAYS', 30),
        ],

        'org_admin' => [
            'name' => env('JARDI_DEMO_ORG_ADMIN_NAME'),
            'email' => env('JARDI_DEMO_ORG_ADMIN_EMAIL'),
            'password' => env('JARDI_DEMO_ORG_ADMIN_PASSWORD'),
        ],

        'inventory_agent' => [
            'name' => env('JARDI_DEMO_INVENTORY_AGENT_NAME'),
            'email' => env('JARDI_DEMO_INVENTORY_AGENT_EMAIL'),
            'password' => env('JARDI_DEMO_INVENTORY_AGENT_PASSWORD'),
        ],
    ],
];
