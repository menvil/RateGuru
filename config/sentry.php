<?php

use App\Support\Deployment\DeploymentMetadata;

/*
 * A key present with an empty value is this repository's convention for "not
 * configured for this target" — it is what .env.example and both environment
 * templates ship. `env()` returns '' for those, not null, so every option
 * below normalizes blank to unset itself rather than relying on the vendor's
 * `=== null` checks.
 *
 * This is not cosmetic. A blank SENTRY_TRACES_SAMPLE_RATE would otherwise
 * become (float) '' = 0.0, and a non-null rate makes Options::isTracingEnabled()
 * true: the SDK would start, populate and then discard a transaction on every
 * single request — the exact overhead "tracing off unless a target opts in" is
 * meant to avoid. Local variables are not part of the returned array, so none
 * of this interferes with `artisan config:cache`.
 */
$sentryFloat = static function (string $key, ?float $default): ?float {
    $value = env($key);

    if ($value === null || $value === '') {
        return $default;
    }

    return (float) $value;
};

$sentryString = static function (string $key, mixed $fallback = null): ?string {
    $value = env($key, $fallback);

    if (! is_string($value) || $value === '') {
        return null;
    }

    return $value;
};

/**
 * Sentry Laravel SDK configuration file.
 *
 * Published from sentry/sentry-laravel and kept close to the vendor default on
 * purpose, so an SDK upgrade diffs cleanly. Every line RateGuru deliberately
 * departs from that default is marked "RateGuru:" with the reason.
 *
 * @see https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/
 * @see infrastructure/runbooks/sentry-observability.md
 */
return [

    // @see https://docs.sentry.io/concepts/key-terms/dsn-explainer/
    'dsn' => $sentryString('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),

    // @see https://spotlightjs.com/
    // 'spotlight' => env('SENTRY_SPOTLIGHT', false),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#logger
    // 'logger' => Sentry\Logger\DebugFileLogger::class, // By default this will log to `storage_path('logs/sentry.log')`

    // RateGuru: the release is the canonical immutable RateGuru release ID
    // read out of the artifact's own release.json (config/deployment.php) —
    // deliberately NOT SENTRY_RELEASE in a shared .env, which would have to be
    // rewritten on every deploy and would drift the moment someone forgot, and
    // deliberately NOT a Git call, because a deployed release has no .git.
    // Missing or malformed metadata resolves to null: Sentry then records no
    // release rather than a fabricated one.
    'release' => DeploymentMetadata::fromBasePath(dirname(__DIR__))->release(),

    // When left empty or `null` the Laravel environment will be used (usually discovered from `APP_ENV` in your `.env`)
    //
    // RateGuru: this is the environment class only — `staging` or `production`.
    // Which brand/target inside that class is serving the request is a separate
    // `deployment_target` tag (see App\Providers\ObservabilityServiceProvider),
    // never a per-brand environment such as `production-tits-guru`.
    'environment' => $sentryString('SENTRY_ENVIRONMENT'),

    // Override the organization ID used for trace continuation checks.
    'org_id' => env('SENTRY_ORG_ID') === null ? null : (int) env('SENTRY_ORG_ID'),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#sample_rate
    //
    // RateGuru: backend errors are never probabilistically dropped. The 1.0
    // default is the vendor default and the value every target runs.
    'sample_rate' => $sentryFloat('SENTRY_SAMPLE_RATE', 1.0),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#traces_sample_rate
    //
    // RateGuru: null (tracing off) unless a target opts in, so local and CI
    // runs never build transactions. staging-main runs 1.0 while its traffic is
    // low; production targets start at 0.10. Traffic assumptions belong in the
    // per-target .env, never in this file.
    'traces_sample_rate' => $sentryFloat('SENTRY_TRACES_SAMPLE_RATE', null),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#profiles_sample_rate
    //
    // RateGuru: profiling stays off in this phase — the default is an explicit
    // 0.0 rather than the vendor's null so the intent is unambiguous.
    'profiles_sample_rate' => $sentryFloat('SENTRY_PROFILES_SAMPLE_RATE', 0.0),

    // Only continue incoming traces when the organization IDs are compatible with this SDK instance.
    'strict_trace_continuation' => env('SENTRY_STRICT_TRACE_CONTINUATION', false),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#enable_logs
    //
    // RateGuru: Sentry is not our log store. storage/logs and the operational
    // logs stay authoritative; Laravel log records still reach Sentry as
    // breadcrumbs on an actual error, which is all we want from them.
    'enable_logs' => env('SENTRY_ENABLE_LOGS', false),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#enable_metrics
    //
    // RateGuru: the vendor default is `true`. Metrics/dashboards are a later
    // observability phase, so this is explicitly flipped off rather than left
    // to the SDK default — an SDK upgrade must not silently switch it on.
    'enable_metrics' => env('SENTRY_ENABLE_METRICS', false),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#log_flush_threshold
    'log_flush_threshold' => env('SENTRY_LOG_FLUSH_THRESHOLD') === null ? null : (int) env('SENTRY_LOG_FLUSH_THRESHOLD'),

    // The minimum log level that will be sent to Sentry as logs using the `sentry_logs` logging channel
    'logs_channel_level' => env('SENTRY_LOG_LEVEL', env('SENTRY_LOGS_LEVEL', env('LOG_LEVEL', 'debug'))),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#send_default_pii
    //
    // RateGuru: false on every target, and never to be flipped. With PII off
    // the SDK does not subscribe to Laravel's auth events at all, so no email,
    // username, IP address, cookie or Authorization header is ever collected.
    // The single identity field we do want — the internal user ID — is added
    // deliberately by App\Providers\ObservabilityServiceProvider.
    'send_default_pii' => env('SENTRY_SEND_DEFAULT_PII', false),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#ignore_exceptions
    //
    // RateGuru: deliberately empty. Sentry only ever sees exceptions Laravel
    // itself considers reportable, and Laravel's own `dontReport` list already
    // covers ordinary user mistakes — 404s, validation, authentication,
    // authorization, CSRF/session expiry and rate limiting never arrive here.
    // Adding broad base classes on top of that would hide real 5xx failures.
    // 'ignore_exceptions' => [],

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#ignore_transactions
    //
    // RateGuru: `/up` (bootstrap/app.php `health:`) is the only health or
    // readiness endpoint this application exposes, and infrastructure/scripts/
    // health-check probes it on every deploy. This drops its *transactions*
    // only — the SDK applies ignore_transactions to transaction events, so a
    // genuine exception raised while serving /up is still reported.
    'ignore_transactions' => [
        // Ignore Laravel's default health URL
        '/up',
    ],

    // Breadcrumb specific configuration
    'breadcrumbs' => [
        // Capture Laravel logs as breadcrumbs
        'logs' => env('SENTRY_BREADCRUMBS_LOGS_ENABLED', true),

        // Capture Laravel cache events (hits, writes etc.) as breadcrumbs
        'cache' => env('SENTRY_BREADCRUMBS_CACHE_ENABLED', true),

        // Capture Livewire components like routes as breadcrumbs
        'livewire' => env('SENTRY_BREADCRUMBS_LIVEWIRE_ENABLED', true),

        // Capture SQL queries as breadcrumbs
        'sql_queries' => env('SENTRY_BREADCRUMBS_SQL_QUERIES_ENABLED', true),

        // Capture SQL query bindings (parameters) in SQL query breadcrumbs
        //
        // RateGuru: hardcoded off, not env-driven. Bindings carry emails,
        // display names, search text, moderation text and tokens; the query
        // text alone is what makes a breadcrumb useful. There is deliberately
        // no switch for an operator to turn this on.
        'sql_bindings' => false,

        // Capture queue job information as breadcrumbs
        'queue_info' => env('SENTRY_BREADCRUMBS_QUEUE_INFO_ENABLED', true),

        // Capture command information as breadcrumbs
        'command_info' => env('SENTRY_BREADCRUMBS_COMMAND_JOBS_ENABLED', true),

        // Capture HTTP client request information as breadcrumbs
        'http_client_requests' => env('SENTRY_BREADCRUMBS_HTTP_CLIENT_REQUESTS_ENABLED', true),

        // Capture send notifications as breadcrumbs
        'notifications' => env('SENTRY_BREADCRUMBS_NOTIFICATIONS_ENABLED', true),
    ],

    // Performance monitoring specific configuration
    'tracing' => [
        // Trace queue jobs as their own transactions (this enables tracing for queue jobs)
        'queue_job_transactions' => env('SENTRY_TRACE_QUEUE_ENABLED', true),

        // Capture queue jobs as spans when executed on the sync driver
        'queue_jobs' => env('SENTRY_TRACE_QUEUE_JOBS_ENABLED', true),

        // Capture SQL queries as spans
        'sql_queries' => env('SENTRY_TRACE_SQL_QUERIES_ENABLED', true),

        // Capture SQL query bindings (parameters) in SQL query spans
        //
        // RateGuru: hardcoded off for the same reason as the breadcrumb
        // setting above — a performance span is not a reason to ship user data.
        'sql_bindings' => false,

        // Capture where the SQL query originated from on the SQL query spans
        'sql_origin' => env('SENTRY_TRACE_SQL_ORIGIN_ENABLED', true),

        // Define a threshold in milliseconds for SQL queries to resolve their origin
        'sql_origin_threshold_ms' => env('SENTRY_TRACE_SQL_ORIGIN_THRESHOLD_MS', 100),

        // Capture views rendered as spans
        'views' => env('SENTRY_TRACE_VIEWS_ENABLED', true),

        // Capture Livewire components as spans
        'livewire' => env('SENTRY_TRACE_LIVEWIRE_ENABLED', true),

        // Capture HTTP client requests as spans
        'http_client_requests' => env('SENTRY_TRACE_HTTP_CLIENT_REQUESTS_ENABLED', true),

        // Capture Laravel cache events (hits, writes etc.) as spans
        'cache' => env('SENTRY_TRACE_CACHE_ENABLED', true),

        // Capture Redis operations as spans (this enables Redis events in Laravel)
        //
        // RateGuru: left at the vendor default (off). Redis backs the cache and
        // the queue here, and both already produce their own spans; turning
        // Redis command events on would mostly add volume, not insight.
        'redis_commands' => env('SENTRY_TRACE_REDIS_COMMANDS', false),

        // Capture where the Redis command originated from on the Redis command spans
        'redis_origin' => env('SENTRY_TRACE_REDIS_ORIGIN_ENABLED', true),

        // Capture send notifications as spans
        'notifications' => env('SENTRY_TRACE_NOTIFICATIONS_ENABLED', true),

        // Enable tracing for requests without a matching route (404's)
        'missing_routes' => env('SENTRY_TRACE_MISSING_ROUTES_ENABLED', false),

        // Configures if the performance trace should continue after the response has been sent to the user until the application terminates
        // This is required to capture any spans that are created after the response has been sent like queue jobs dispatched using `dispatch(...)->afterResponse()` for example
        'continue_after_response' => env('SENTRY_TRACE_CONTINUE_AFTER_RESPONSE', true),

        // Capture AI agent interactions as spans (requires laravel/ai)
        'gen_ai' => env('SENTRY_TRACE_GEN_AI_ENABLED', true),

        // Capture AI invoke_agent spans
        'gen_ai_invoke_agent' => env('SENTRY_TRACE_GEN_AI_INVOKE_AGENT_ENABLED', true),

        // Capture AI chat spans
        'gen_ai_chat' => env('SENTRY_TRACE_GEN_AI_CHAT_ENABLED', true),

        // Capture AI execute_tool spans
        'gen_ai_execute_tool' => env('SENTRY_TRACE_GEN_AI_EXECUTE_TOOL_ENABLED', true),

        // Capture AI embeddings spans
        'gen_ai_embeddings' => env('SENTRY_TRACE_GEN_AI_EMBEDDINGS_ENABLED', true),

        // Enable the tracing integrations supplied by Sentry (recommended)
        'default_integrations' => env('SENTRY_TRACE_DEFAULT_INTEGRATIONS_ENABLED', true),
    ],

];
