<?php

return [
    'allowed_schemes' => ['https'],

    // Bare allowlist, not a "default port per scheme" convenience — a
    // request whose port isn't in this list is rejected outright, whether
    // explicit in the URL or implicit from the scheme. Keeps this pipeline
    // from doubling as a port scanner against internal services.
    'allowed_ports' => [80, 443],

    'timeout_seconds' => 5,
    'connect_timeout_seconds' => 2,
    // A video downloaded by ImportVideoAssetJob, in the background: up to
    // 100 MB needs far longer than a page or a picture.
    'video_timeout_seconds' => 120,

    // A video taken from a site's page with yt-dlp. Only the sites named
    // here, each through its own extractor alone (--use-extractors): never
    // yt-dlp's generic extractor, which would open any address a link gave
    // it. Adding a site is a line here; which sites, the project's call.
    'ytdlp' => [
        'binary' => 'yt-dlp',
        // The probe runs while the author waits, in a web worker: short, one
        // per author, two on the host at once (UploadPostForm::pageVideo).
        'probe_timeout_seconds' => 15,
        'probe_slots' => 2,
        'download_timeout_seconds' => 240,
        'sites' => [
            'redgifs.com' => 'RedGifs',
            'youporn.com' => 'YouPorn',
            'coub.com' => 'Coub',
            'instagram.com' => 'Instagram',
        ],
        // The sites whose pages may hold a photo instead: when yt-dlp finds
        // no video there, the page's photo is taken. On the others every page
        // is a video, so finding none is a failure, said as one — never a
        // poster frame posted in its place.
        'photo_fallback' => ['Instagram'],
    ],
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
