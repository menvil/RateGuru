<?php

return [
    'enabled' => env('IMPORT_FROM_URL_ENABLED', true),

    'allowed_schemes' => ['http', 'https'],

    'timeout_seconds' => 5,
    'connect_timeout_seconds' => 2,
    'max_redirects' => 3,

    'max_html_bytes' => 1024 * 1024,
    'max_image_bytes' => 8 * 1024 * 1024,

    'allowed_image_mimes' => [
        'image/jpeg',
        'image/png',
        'image/webp',
    ],

    'providers' => [
        'direct_image' => true,
        'open_graph' => true,
        'facebook' => 'best_effort',
        'instagram' => 'best_effort',
        'x' => 'best_effort',
        'pinterest' => 'best_effort',
    ],
];
