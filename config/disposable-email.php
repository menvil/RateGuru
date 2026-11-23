<?php

// Only what differs from the package's defaults, which are merged under this.
return [
    // A throwaway service hands out subdomains of its domains just as freely.
    'include_subdomains' => true,

    // Read from its file: only a sign-up or an address change needs the list,
    // and the weekly update then takes effect without a stale cached copy.
    'cache' => [
        'enabled' => false,
        'store' => 'default',
        'key' => 'disposable_email:domains',
    ],
];
