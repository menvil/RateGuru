<?php

return [
    'enabled' => env('IMPORT_FROM_URL_ENABLED', false),

    'allowed_schemes' => ['https'],

    // Bare allowlist, not a "default port per scheme" convenience — a
    // request whose port isn't in this list is rejected outright, whether
    // explicit in the URL or implicit from the scheme. Keeps this pipeline
    // from doubling as a port scanner against internal services.
    'allowed_ports' => [80, 443],

    'timeout_seconds' => 5,
    'connect_timeout_seconds' => 2,
    'max_redirects' => 3,

    // Bounds how many DNS answers a single A/AAAA lookup is allowed to
    // return before HostResolver stops collecting more — a resolver
    // returning a pathological number of records is itself a signal not to
    // trust the response, independent of what any individual answer is.
    'dns_max_answers' => 16,

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
