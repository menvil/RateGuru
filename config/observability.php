<?php

return [

    'request_id' => [
        'header' => 'X-Request-Id',
        'response_header' => true,
    ],

    'structured_context' => [
        'enabled' => true,
    ],

    'slow_actions' => [
        'enabled' => true,
        'default_threshold_ms' => 500,
        'external_fetch_threshold_ms' => 1000,
    ],

    'redaction' => [
        'enabled' => true,
        'keys' => [
            'password',
            'password_confirmation',
            'token',
            'authorization',
            'cookie',
            'remember_token',
            '_token',
        ],
    ],

    'security_events' => [
        'enabled' => true,
    ],

    // Sentry and Nightwatch are real, installed integrations and are each
    // configured entirely in their own file (config/sentry.php,
    // config/nightwatch.php) — both are deliberately absent from this list, so
    // there is never a second place that answers "is this vendor configured?".
    // What remains is a placeholder for a vendor RateGuru does not install.
    'external_vendors' => [
        'datadog_agent_host' => env('DD_AGENT_HOST'),
    ],

];
