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
        // per author, four on the host at once — every page link is asked
        // (ResolveMediaLinkAction).
        'probe_timeout_seconds' => 15,
        'probe_slots' => 4,
        // The daily site check runs off any request, so it waits longer for
        // a slow site than a probe an author is waiting on may.
        'check_timeout_seconds' => 60,
        // How far back through the imports it looks for each site's pages.
        'check_scan_limit' => 5000,
        'download_timeout_seconds' => 240,
        // The sites yt-dlp may take a video from, each with the one extractor
        // it may use there — the project's choice, reviewed site by site.
        'sites' => [
            'redgifs.com' => 'RedGifs',
            'instagram.com' => 'Instagram',
            // Videos and Shorts, on www., m. and music. too. A youtu.be link
            // goes through another extractor, and is not taken yet.
            'youtube.com' => 'Youtube',
        ],
        // Any other page goes to yt-dlp too, with every extractor it has but
        // the generic one (which would take a file from any page at all) —
        // only on a host where yt-dlp runs as its own account behind the
        // firewall. One rule for every site: a video is taken, a page with
        // none (or a site yt-dlp does not know) gives its picture, a site it
        // knows but could not read is refused. X's posts stay with their own
        // reader, which takes their video best.
        'any_site' => true,
        // Hosts never given to yt-dlp that way, the project's call: their
        // links go on as any link. A site above is removed from there instead.
        'blocked_domains' => [],
        // The account yt-dlp runs as where the host has it: the worker's own
        // name and this suffix — one per target — kept off every loopback,
        // private and link-local address by the host's firewall
        // (install-media-fetch-isolation), whose unit keeps the marker while
        // the table is loaded. Where the account does not exist, yt-dlp runs
        // as the worker; where the marker is missing, not at all.
        'isolation_suffix' => '-fetch',
        'isolation_marker' => '/run/rateguru-media-fetch.armed',
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
