<?php

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Runs the command for real and reads its whole output, so the DSN assertions
 * below cover everything it prints rather than one expected line.
 */
function observabilityHealthOutput(): string
{
    $output = new BufferedOutput;

    Artisan::call('rateguru:observability:health', [], $output);

    return $output->fetch();
}

it('runs observability health command', function () {
    $this->artisan('rateguru:observability:health')
        ->assertExitCode(0);
});

it('shows request id config status', function () {
    $this->artisan('rateguru:observability:health')
        ->expectsOutputToContain('X-Request-Id')
        ->assertExitCode(0);
});

it('shows redaction status', function () {
    $this->artisan('rateguru:observability:health')
        ->expectsOutputToContain('redaction')
        ->assertExitCode(0);
});

it('reports the deployment identity a Sentry event would carry', function () {
    config([
        'deployment.target' => 'staging-main',
        'deployment.release' => 'v0.0.0-20260826-120211-ca7d1c7',
        'deployment.commit' => 'ca7d1c75d0f0f5b6c9a1e2d3f4a5b6c7d8e9f0a1',
        'deployment.metadata_state' => 'present',
    ]);

    expect(observabilityHealthOutput())
        ->toContain('staging-main')
        ->toContain('v0.0.0-20260826-120211-ca7d1c7')
        ->toContain('ca7d1c75d0f0f5b6c9a1e2d3f4a5b6c7d8e9f0a1')
        ->toContain('release.json: present');
});

it('warns instead of guessing when the release metadata is unusable', function () {
    config([
        'deployment.target' => 'staging-main',
        'deployment.release' => null,
        'deployment.commit' => null,
        'deployment.metadata_state' => 'malformed',
    ]);

    $output = observabilityHealthOutput();

    expect($output)
        ->toContain('release.json: malformed')
        ->toContain('Release metadata is malformed')
        ->toContain('(unknown)');
});

it('reports the Sentry posture an operator needs to verify after a deploy', function () {
    config([
        'sentry.dsn' => 'https://publickey@o1.ingest.sentry.invalid/1',
        'sentry.environment' => 'staging',
        'sentry.traces_sample_rate' => 1.0,
    ]);

    expect(observabilityHealthOutput())
        ->toContain('Enabled: yes')
        ->toContain('Environment: staging')
        ->toContain('Error sample rate: 1')
        ->toContain('Traces sample rate: 1')
        ->toContain('Profiles sample rate: 0')
        ->toContain('Send default PII: disabled')
        ->toContain('Structured logs: disabled')
        ->toContain('Metrics: disabled')
        ->toContain('SQL bindings (breadcrumbs): disabled')
        ->toContain('SQL bindings (tracing): disabled')
        ->toContain('Ignored transactions: /up');
});

it('reports whether the SDK actually attached its HTTP instrumentation', function () {
    // Sample rates describe intent; this describes what the running
    // application will really do. Without a DSN at boot the Sentry providers
    // register no middleware, so a target can report "Traces sample rate: 1"
    // and still emit no transactions at all — the exact gap that made a
    // missing-traces report impossible to diagnose from configuration alone.
    $output = observabilityHealthOutput();

    expect($output)
        ->toContain('HTTP tracing middleware:')
        ->toContain('Request context middleware:')
        ->toContain('Event flush middleware:');
});

it('warns when a trace sample rate is configured but nothing will trace', function () {
    config(['sentry.traces_sample_rate' => 1.0]);

    // The suite boots without a DSN, so the providers registered no middleware.
    expect(observabilityHealthOutput())
        ->toContain('NOT REGISTERED')
        ->toContain('no HTTP transactions will be produced');
});

it('never prints the DSN, in whole or in part', function () {
    // This command is meant to be run by an operator on a live target, so it
    // must be safe to paste its output anywhere.
    config(['sentry.dsn' => 'https://s3cr3tpublickey@o987654.ingest.sentry.invalid/1234567']);

    $output = observabilityHealthOutput();

    foreach (['s3cr3tpublickey', 'o987654', 'ingest.sentry.invalid', '1234567', 'https://'] as $forbidden) {
        expect(str_contains($output, $forbidden))->toBeFalse("health output leaked {$forbidden}");
    }

    expect($output)->toContain('Enabled: yes');
});

it('says plainly when Sentry is not configured', function () {
    config(['sentry.dsn' => null]);

    expect(observabilityHealthOutput())
        ->toContain('Enabled: no (no DSN configured)');
});

it('reads Sentry status from the Sentry config, with no second source of truth', function () {
    // config/observability.php used to carry its own sentry_dsn placeholder.
    // Two answers to "is Sentry configured?" is one too many.
    expect(config('observability.external_vendors'))->not->toHaveKey('sentry_dsn');

    expect(file_get_contents(base_path('config/observability.php')))
        ->not->toContain('SENTRY_LARAVEL_DSN')
        ->not->toContain('sentry_dsn');
});
