<?php

// Only what differs from the package's defaults, which are merged under this.
return [
    // A throwaway service hands out subdomains of its domains just as freely.
    'include_subdomains' => true,

    // Registries holding the domains of unrelated people and companies. Listed
    // whole, they would make every domain under them a throwaway one. The
    // throwaway services on them keep their own entries in the list.
    'whitelist' => ['pp.ua', 'it.com', 'za.com', 'msk.ru'],

    // Read from its file: only a sign-up or an address change needs the list,
    // and the weekly update then takes effect without a stale cached copy.
    'cache' => [
        'enabled' => false,
        'store' => 'default',
        'key' => 'disposable_email:domains',
    ],
];
