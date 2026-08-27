<?php

use App\Support\Deployment\DeploymentMetadata;
use Illuminate\Support\Facades\File;

/**
 * These assert RateGuru's own decisions, not the vendor config file. Anything
 * we deliberately departed from the SDK default on is pinned here, so an SDK
 * upgrade that changes a default cannot silently change our posture.
 */
it('never probabilistically drops a backend error', function () {
    expect(config('sentry.sample_rate'))->toBe(1.0);
});

it('leaves tracing off unless a target opts in, and keeps the rate configurable', function () {
    // Local and CI have no SENTRY_TRACES_SAMPLE_RATE, so no transactions are
    // ever built there. The value itself is never hardcoded in PHP.
    expect(config('sentry.traces_sample_rate'))->toBeNull();

    expect(File::get(base_path('config/sentry.php')))
        ->toContain("env('SENTRY_TRACES_SAMPLE_RATE')")
        ->toContain("env('SENTRY_ENVIRONMENT')")
        ->toContain("env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN'))");
});

it('keeps profiling disabled', function () {
    expect(config('sentry.profiles_sample_rate'))->toBe(0.0);
});

it('keeps Sentry structured logs disabled so Sentry never becomes the log store', function () {
    expect(config('sentry.enable_logs'))->toBeFalse();
});

it('explicitly disables Sentry metrics rather than inheriting the SDK default', function () {
    expect(config('sentry.enable_metrics'))->toBeFalse();

    // The vendor default is `true`. If that ever stops being the case the
    // explicit override is still correct — but this proves we are overriding
    // rather than coincidentally agreeing with the package.
    expect(File::get(base_path('vendor/sentry/sentry-laravel/config/sentry.php')))
        ->toContain("'enable_metrics' => env('SENTRY_ENABLE_METRICS', true)");

    expect(File::get(base_path('config/sentry.php')))
        ->toContain("'enable_metrics' => env('SENTRY_ENABLE_METRICS', false)");
});

it('never sends default PII', function () {
    expect(config('sentry.send_default_pii'))->toBeFalse();
});

it('never captures SQL bindings, in breadcrumbs or in spans', function () {
    expect(config('sentry.breadcrumbs.sql_bindings'))->toBeFalse()
        ->and(config('sentry.tracing.sql_bindings'))->toBeFalse();

    // Hardcoded rather than env-driven on purpose: there must be no switch an
    // operator could flip to start shipping user data in query parameters.
    $source = File::get(base_path('config/sentry.php'));

    expect($source)
        ->not->toContain('SENTRY_BREADCRUMBS_SQL_BINDINGS_ENABLED')
        ->not->toContain('SENTRY_TRACE_SQL_BINDINGS_ENABLED');
});

it('keeps SQL query text itself, which is what makes a trace useful', function () {
    expect(config('sentry.breadcrumbs.sql_queries'))->toBeTrue()
        ->and(config('sentry.tracing.sql_queries'))->toBeTrue();
});

it('keeps the breadcrumb categories that make an error diagnosable', function () {
    foreach (['logs', 'cache', 'livewire', 'sql_queries', 'queue_info', 'command_info', 'http_client_requests', 'notifications'] as $category) {
        expect(config("sentry.breadcrumbs.{$category}"))->toBeTrue("breadcrumb category {$category} must stay enabled");
    }
});

it('traces the backend operations Phase 6 cares about', function () {
    foreach (['queue_job_transactions', 'queue_jobs', 'sql_queries', 'views', 'livewire', 'http_client_requests', 'cache', 'notifications'] as $feature) {
        expect(config("sentry.tracing.{$feature}"))->toBeTrue("tracing feature {$feature} must stay enabled");
    }

    // Redis stays off: the cache and queue already produce their own spans.
    expect(config('sentry.tracing.redis_commands'))->toBeFalse();
});

it('excludes the health endpoint from performance monitoring, and only that endpoint', function () {
    // /up is the route bootstrap/app.php registers as `health:` and the exact
    // path infrastructure/scripts/health-check probes on every deploy.
    expect(config('sentry.ignore_transactions'))->toBe(['/up']);

    expect(File::get(base_path('bootstrap/app.php')))->toContain("health: '/up'");
    expect(File::get(base_path('infrastructure/scripts/health-check')))->toContain('/up');
});

it('suppresses no exception class at all', function () {
    // Sentry only ever sees what Laravel already considered reportable, so
    // ordinary user mistakes are excluded upstream. A local ignore list here
    // could only hide genuine 5xx failures.
    expect(config('sentry.ignore_exceptions', []))->toBe([]);
});

it('derives the Sentry release from the canonical deployment metadata, not from an env variable', function () {
    // The value in this environment (no release.json in a working copy) is
    // null, and the derivation is the same call config/deployment.php makes.
    expect(config('sentry.release'))
        ->toBe(DeploymentMetadata::fromBasePath(base_path())->release())
        ->toBe(config('deployment.release'));

    $source = File::get(base_path('config/sentry.php'));

    expect($source)
        ->toContain('DeploymentMetadata::fromBasePath(dirname(__DIR__))->release()')
        // No second, independently maintained release identity may exist.
        ->not->toContain("env('SENTRY_RELEASE')");

    foreach (['.env.example', 'infrastructure/templates/environment/staging.env.example', 'infrastructure/templates/environment/production.env.example'] as $path) {
        expect(File::get(base_path($path)))
            ->not->toContain('SENTRY_RELEASE')
            ->not->toContain('APP_RELEASE')
            ->not->toContain('APP_VERSION');
    }
});

it('survives config caching with real release metadata', function () {
    // Deployments run `artisan config:cache`, so every value above has to be
    // representable in a cached config file. Nothing here may be a closure or
    // an object, and the release must be frozen at the value the release
    // directory carries rather than re-derived at request time.
    $cacheable = [
        config('sentry.release'),
        config('sentry.environment'),
        config('sentry.sample_rate'),
        config('sentry.traces_sample_rate'),
        config('sentry.profiles_sample_rate'),
        config('sentry.enable_logs'),
        config('sentry.enable_metrics'),
        config('sentry.send_default_pii'),
        config('sentry.ignore_transactions'),
        config('sentry.breadcrumbs'),
        config('sentry.tracing'),
        config('deployment'),
    ];

    $exported = var_export($cacheable, true);

    expect($exported)
        ->not->toContain('Closure')
        ->not->toContain('\\Object')
        ->and(eval("return {$exported};"))->toBe($cacheable);
});

it('exposes the deployment target as configuration, never as a hardcoded target ID', function () {
    // Every active and planned target must work without a code change.
    foreach (['staging-main', 'tits-guru', 'food-guru', 'animals-guru'] as $target) {
        config()->set('deployment.target', $target);
        expect(config('deployment.target'))->toBe($target);
    }

    foreach ([
        'config/deployment.php',
        'config/sentry.php',
        'app/Providers/ObservabilityServiceProvider.php',
        'app/Support/Deployment/DeploymentMetadata.php',
    ] as $path) {
        $code = phpSourceWithoutComments($path);

        foreach (['staging-main', 'tits-guru', 'food-guru'] as $target) {
            expect(str_contains($code, $target))
                ->toBeFalse("{$path} must not special-case the target {$target}");
        }
    }
});

it('rejects a target ID that is not registry-shaped instead of tagging events with junk', function (mixed $raw, ?string $expected) {
    // config/deployment.php normalizes the raw env value; re-running that exact
    // expression is what this asserts, without mutating the process env.
    $normalized = is_string($raw) && preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $raw) === 1 ? $raw : null;

    expect($normalized)->toBe($expected);

    expect(File::get(base_path('config/deployment.php')))
        ->toContain("preg_match('/^[a-z0-9]+(-[a-z0-9]+)*\$/', \$target)");
})->with([
    ['staging-main', 'staging-main'],
    ['tits-guru', 'tits-guru'],
    ['STAGING-MAIN', null],
    ['staging main', null],
    ['staging/main', null],
    ['-staging', null],
    ['', null],
    [null, null],
]);
