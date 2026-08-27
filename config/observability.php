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

    // Sentry is a real, installed integration now and is configured entirely in
    // config/sentry.php — it is deliberately absent from this list, so there is
    // no second place that answers "is Sentry configured?". These two remain
    // placeholders for vendors RateGuru does not install.
    'external_vendors' => [
        'datadog_agent_host' => env('DD_AGENT_HOST'),
        'nightwatch_token' => env('NIGHTWATCH_TOKEN'),
    ],

];
