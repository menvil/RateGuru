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

    'external_vendors' => [
        'sentry_dsn' => env('SENTRY_LARAVEL_DSN'),
        'datadog_agent_host' => env('DD_AGENT_HOST'),
        'nightwatch_token' => env('NIGHTWATCH_TOKEN'),
    ],

];
