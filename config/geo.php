<?php

/*
 * The local IP location database (docs/architecture/geo.md). Plain values,
 * never read from the environment: where the files live, where each edition
 * is fetched from and how a fetched file is checked are the same on every
 * installation.
 */
return [

    // Where the databases live: under the shared storage that survives every
    // deploy, outside the public disk and outside the nightly backup. Each
    // edition has a directory of its own, and its files sit one level below
    // that, so after the first run nothing changes at the two levels the
    // host's repair checks.
    'directory' => storage_path('geoip'),

    // Seconds: making a connection, reading a small metadata answer, and
    // downloading a database.
    'timeouts' => [
        'connect' => 10,
        'metadata' => 15,
        'download' => 300,
    ],

    // How often an installed database is checked for a newer build, and how
    // long to wait after a failed attempt — the first, second, third and every
    // later failure in a row.
    'check_every_hours' => 24,
    'retry_after_minutes' => [15, 60, 180, 360],

    // Failures in a row after which the administrator is warned.
    'alert_after_failures' => 3,

    // How long the database a new build replaced is kept to roll back to.
    'previous_kept_days' => 7,

    // Addresses whose country every real build knows; a fetched database must
    // place at least probes_required of them correctly before it serves.
    'probes' => [
        '8.8.8.8' => 'US',
        '81.2.69.142' => 'GB',
        '95.173.136.70' => 'RU',
        '2a00:1450:4001:80b::200e' => 'DE',
    ],
    'probes_required' => 3,

    'editions' => [

        // MaxMind GeoLite2 City, republished on npm by the WP Statistics team
        // every Tuesday and Friday. MaxMind's licence asks for a build no
        // older than 30 days, so an older one is taken out of service.
        'geolite2_city' => [
            'package' => 'geolite2-city',
            'file' => 'GeoLite2-City.mmdb.gz',
            'database_types' => ['GeoLite2-City'],
            'max_archive_bytes' => 64 * 1024 * 1024,
            'min_database_bytes' => 16 * 1024 * 1024,
            'max_database_bytes' => 192 * 1024 * 1024,
            'max_build_age_days' => 30,
            'stale_after_days' => 14,
            'sources' => ['npm', 'jsdelivr'],
        ],

        // DB-IP City Lite, published on the first of every month.
        'dbip_city_lite' => [
            'package' => 'dbip-city-lite',
            'file' => 'dbip-city-lite.mmdb.gz',
            'database_types' => ['DBIP-City-Lite', 'DBIP-Location (compat=City)'],
            'max_archive_bytes' => 128 * 1024 * 1024,
            'min_database_bytes' => 32 * 1024 * 1024,
            'max_database_bytes' => 384 * 1024 * 1024,
            'max_build_age_days' => null,
            'stale_after_days' => 45,
            'sources' => ['dbip', 'npm', 'jsdelivr'],
        ],

        // DB-IP ASN Lite: the network (ASN) an address belongs to, published
        // on the first of every month, only by DB-IP. It places no one, so it
        // is held to network probes of its own: an address's ASN, not its
        // country.
        'dbip_asn_lite' => [
            'package' => 'dbip-asn-lite',
            'file' => 'dbip-asn-lite.mmdb.gz',
            'database_types' => ['DBIP-ASN-Lite (compat=GeoLite2-ASN)', 'DBIP-ASN-Lite'],
            'max_archive_bytes' => 32 * 1024 * 1024,
            'min_database_bytes' => 4 * 1024 * 1024,
            'max_database_bytes' => 96 * 1024 * 1024,
            'max_build_age_days' => null,
            'stale_after_days' => 45,
            'sources' => ['dbip'],
            'probes' => [
                '8.8.8.8' => 15169,
                '1.1.1.1' => 13335,
                '9.9.9.9' => 19281,
                '2001:4860:4860::8888' => 15169,
            ],
            'probes_required' => 3,
        ],

    ],

    // Lists that sort the networks the ASN database names, kept under
    // storage/geoip/networks the same way the databases are: checked on their
    // own schedule, a fetched list checked before it replaces the one in
    // service, the one in service kept whenever that fails.
    'network_lists' => [

        // Networks that are not people's home or mobile connections: hosting,
        // cloud and VPN providers, as X4BNet's curated ASN lists name them
        // (MIT). A visit from one of them is flagged; it is never refused.
        'hosting' => [
            'urls' => [
                'https://raw.githubusercontent.com/X4BNet/lists_vpn/main/input/datacenter/ASN.txt',
                'https://raw.githubusercontent.com/X4BNet/lists_vpn/main/input/vpn/ASN.txt',
            ],
            'check_every_hours' => 24,
            'max_bytes' => 2 * 1024 * 1024,
            'min_entries' => 500,
        ],

        // The addresses iCloud Private Relay sends people's traffic from,
        // through networks the hosting list names (Akamai, Cloudflare,
        // Fastly): a visit from one is a person behind Apple's relay, not a
        // hosted machine. Apple publishes them as CIDR ranges.
        'relay' => [
            'urls' => ['https://mask-api.icloud.com/egress-ip-ranges.csv'],
            'check_every_hours' => 168,
            'max_bytes' => 64 * 1024 * 1024,
            'min_entries' => 100,
        ],

    ],

];
