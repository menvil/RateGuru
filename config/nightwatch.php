<?php

use App\Support\Deployment\DeploymentMetadata;

/*
 * A key present with an empty value is this repository's convention for "not
 * configured for this target" — it is what .env.example and both environment
 * templates ship. `env()` returns '' for those, not null, so the helpers below
 * normalize blank to unset rather than letting '' become false/0.0/'' and mean
 * something the operator never wrote. Local variables are not part of the
 * returned array, so none of this interferes with `artisan config:cache`.
 */
$nightwatchBool = static function (string $key, bool $default): bool {
    $value = env($key);

    if ($value === null || $value === '') {
        return $default;
    }

    return filter_var($value, FILTER_VALIDATE_BOOL);
};

$nightwatchFloat = static function (string $key, float $default): float {
    $value = env($key);

    if ($value === null || $value === '') {
        return $default;
    }

    return (float) $value;
};

$nightwatchString = static function (string $key, ?string $default = null): ?string {
    $value = env($key);

    if (! is_string($value) || $value === '') {
        return $default;
    }

    return $value;
};

/**
 * Laravel Nightwatch configuration file.
 *
 * Published from laravel/nightwatch and kept close to the vendor default on
 * purpose, so a package upgrade diffs cleanly. Every line RateGuru deliberately
 * departs from that default is marked "RateGuru:" with the reason.
 *
 * Publishing the whole file — rather than relying on the provider's
 * mergeConfigFrom — is load-bearing: `artisan config:cache` skips
 * mergeConfigFrom entirely, so anything not written here would silently fall
 * back to the vendor defaults inside a cached, production-like release. That
 * includes `enabled`, whose vendor default is `true`.
 *
 * @see https://nightwatch.laravel.com/docs
 * @see infrastructure/runbooks/nightwatch-evaluation.md
 */
return [

    // RateGuru: the vendor default is `true` — installing the package would
    // otherwise switch telemetry on for every developer checkout and every CI
    // run the moment composer install finished. Nightwatch is an opt-in
    // evaluation: a target enables it explicitly in its own .env, and nowhere
    // else. phpunit.xml pins NIGHTWATCH_ENABLED=false on top of this so the
    // test suite can never transmit, whatever a developer's .env says.
    'enabled' => $nightwatchBool('NIGHTWATCH_ENABLED', false),

    // The environment token. A runtime secret: it lives only in the target's
    // shared .env, never in the repository, never in the target registry,
    // never in a Supervisor config, and is never printed by any RateGuru
    // command. One token per deployment target — never shared between targets.
    'token' => $nightwatchString('NIGHTWATCH_TOKEN'),

    // RateGuru: the canonical immutable RateGuru release ID, read out of the
    // artifact's own release.json — exactly the value Sentry reports, from
    // exactly the same source, so a Nightwatch event and a Sentry event can
    // never disagree about what the server is running.
    //
    // Deliberately NOT the vendor default, which chains NIGHTWATCH_DEPLOY ->
    // LARAVEL_CLOUD_DEPLOY_UUID -> FORGE_DEPLOY_COMMIT -> VAPOR_COMMIT_HASH.
    // NIGHTWATCH_DEPLOY in a shared .env would have to be rewritten on every
    // deployment and would drift the moment someone forgot; the Cloud/Forge/
    // Vapor variables describe platforms RateGuru does not run on. And it is
    // deliberately not a Git call: a deployed release is an immutable
    // directory with no .git in it.
    //
    // Missing or malformed metadata resolves to null, so Nightwatch records
    // "no deploy" rather than a fabricated one.
    'deployment' => DeploymentMetadata::fromBasePath(dirname(__DIR__))->release(),

    'server' => $nightwatchString('NIGHTWATCH_SERVER', (string) gethostname()),

    // RateGuru: left at the vendor default. The snippets are RateGuru's own
    // source code, which carries no user data, and they are the difference
    // between a stack frame and a diagnosis. Note this is *source*, not
    // variables: no locals or arguments are captured.
    'capture_exception_source_code' => $nightwatchBool('NIGHTWATCH_CAPTURE_EXCEPTION_SOURCE_CODE', true),

    // RateGuru: false on every target, and the same answer Sentry gives. Even
    // though Nightwatch only serializes a payload for a 500 response and
    // redacts the fields below, RateGuru request bodies carry post
    // descriptions, comment text, moderation notes, contact-form messages,
    // profile fields and pasted import URLs — none of which we have a reason
    // to place in a third-party service.
    'capture_request_payload' => $nightwatchBool('NIGHTWATCH_CAPTURE_REQUEST_PAYLOAD', false),

    // RateGuru: the vendor list plus RateGuru's own redaction keys
    // (config/observability.php) and the auth fields Breeze posts. Defence in
    // depth only — payload capture above is off, so nothing here should ever
    // be reached.
    'redact_payload_fields' => explode(',', (string) env(
        'NIGHTWATCH_REDACT_PAYLOAD_FIELDS',
        '_token,password,password_confirmation,current_password,token,remember_token,authorization,cookie,email,url',
    )),

    // RateGuru: the vendor list, plus X-CSRF-TOKEN (the header Laravel accepts
    // alongside X-XSRF-TOKEN), plus the forwarding headers. The forwarding
    // headers matter: neutralising Request::$ip (see NightwatchPrivacy) is not
    // enough on its own, because a proxied request carries the same precise
    // client address again in X-Forwarded-For / X-Real-IP and Nightwatch
    // serialises the whole header bag. Referer is included because RateGuru
    // puts the feed search term in the query string, so a referrer is a
    // second, indirect copy of exactly what the URL redaction removes.
    'redact_headers' => explode(',', (string) env(
        'NIGHTWATCH_REDACT_HEADERS',
        'Authorization,Cookie,Proxy-Authorization,X-XSRF-TOKEN,X-CSRF-TOKEN,X-Forwarded-For,X-Real-IP,Forwarded,CF-Connecting-IP,True-Client-IP,Referer',
    )),

    'sampling' => [
        // RateGuru: the vendor default is 1.0. Nightwatch counts every observed
        // event, not every request, so an unsampled request costs a request
        // plus its queries, cache events and jobs. 0.10 is the documented
        // steady state; a target that wants a different rate says so in its
        // own .env, and the controlled acceptance window raises it to 1.0
        // there rather than here.
        'requests' => $nightwatchFloat('NIGHTWATCH_REQUEST_SAMPLE_RATE', 0.10),

        // Commands are low-volume and each one is interesting.
        'commands' => $nightwatchFloat('NIGHTWATCH_COMMAND_SAMPLE_RATE', 1.0),

        // Exceptions are never probabilistically dropped — the same policy
        // config/sentry.php applies to error events.
        'exceptions' => $nightwatchFloat('NIGHTWATCH_EXCEPTION_SAMPLE_RATE', 1.0),

        'scheduled_tasks' => $nightwatchFloat('NIGHTWATCH_SCHEDULED_TASK_SAMPLE_RATE', 1.0),
    ],

    'filtering' => [
        // RateGuru: cache events are captured, but only for the key shapes
        // App\Support\Observability\NightwatchPrivacy recognises — every other
        // key is rejected before transmission. The reason that allowlist
        // exists rather than a blocklist is concrete: Laravel's RateLimiter
        // does not hash the keys it is handed, and the login throttle key is
        // literally "<email>|<ip>".
        'ignore_cache_events' => $nightwatchBool('NIGHTWATCH_IGNORE_CACHE_EVENTS', false),

        // RateGuru: mail is off. Nightwatch's Mail record carries recipient
        // addresses and the subject line, and RateGuru's only mailable is the
        // contact form — sender name, sender address and a user-written
        // subject. Revisit with the production-mail phase, not here.
        'ignore_mail' => $nightwatchBool('NIGHTWATCH_IGNORE_MAIL', true),

        // RateGuru: notifications are off. The Notification record identifies
        // the recipient and channel; RateGuru's notification payloads also
        // carry display names, usernames and post titles.
        'ignore_notifications' => $nightwatchBool('NIGHTWATCH_IGNORE_NOTIFICATIONS', true),

        // RateGuru: captured, with the query string stripped from every URL
        // (see NightwatchPrivacy). The only outbound requests RateGuru makes
        // are user-pasted import URLs and the redirect hops they resolve to,
        // whose query strings routinely carry share tokens and signed CDN
        // parameters — but the host and path are exactly the diagnostic value
        // an import failure needs.
        'ignore_outgoing_requests' => $nightwatchBool('NIGHTWATCH_IGNORE_OUTGOING_REQUESTS', false),

        // RateGuru: captured. Laravel hands Nightwatch the parameterized SQL
        // (QueryExecuted::$sql), and the bindings — which is where every value
        // RateGuru would care about protecting lives — are not part of the
        // record at all. Proven by a sentinel test rather than assumed.
        'ignore_queries' => $nightwatchBool('NIGHTWATCH_IGNORE_QUERIES', false),

        // RateGuru: the vendor default chains to LOG_LEVEL, which is `debug`.
        // The `nightwatch` log channel is deliberately NOT part of RateGuru's
        // log stack (see config/logging.php), so nothing reaches this today —
        // but if a target ever adds it, `warning` is the level it starts at,
        // never `debug`.
        'log_level' => $nightwatchString('NIGHTWATCH_LOG_LEVEL', 'warning'),
    ],

    'ingest' => [
        // The local agent's loopback endpoint. This single value is both where
        // the application sends events and — via `artisan nightwatch:agent`'s
        // --listen-on default — where the agent listens, so it must never name
        // a routable address. infrastructure/scripts/install-nightwatch-agent
        // refuses to install or verify an agent whose resolved bind address is
        // not loopback.
        'uri' => $nightwatchString('NIGHTWATCH_INGEST_URI', '127.0.0.1:2407'),

        'timeout' => $nightwatchFloat('NIGHTWATCH_INGEST_TIMEOUT', 0.5),

        'connection_timeout' => $nightwatchFloat('NIGHTWATCH_INGEST_CONNECTION_TIMEOUT', 0.5),

        'event_buffer' => (int) env('NIGHTWATCH_INGEST_EVENT_BUFFER', 500),
    ],

];
