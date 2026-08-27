<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * The GitHub side of release correlation: one shared composite action, called
 * only after a deployment has already succeeded, never able to fail a healthy
 * deployment, and never holding a Sentry credential anywhere near a server.
 */
function sentryReleaseAction(): array
{
    return Yaml::parse(File::get(base_path('.github/actions/sentry-release/action.yml')));
}

/** @return array<string, array{workflow: string, job: string, step: array}> */
function sentryReleaseCallSites(): array
{
    $callSites = [];

    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $path) {
        $workflow = Yaml::parse(File::get($path));

        foreach ((array) data_get($workflow, 'jobs', []) as $jobName => $job) {
            foreach ((array) data_get($job, 'steps', []) as $step) {
                if (data_get($step, 'uses') !== './.github/actions/sentry-release') {
                    continue;
                }

                $callSites[basename($path).":{$jobName}"] = [
                    'workflow' => basename($path),
                    'job' => $jobName,
                    'step' => $step,
                ];
            }
        }
    }

    return $callSites;
}

it('pins the official Sentry release action by immutable commit SHA, like every other third-party action', function () {
    $steps = collect(data_get(sentryReleaseAction(), 'runs.steps'))->keyBy('name');

    $uses = data_get($steps->get('Create Sentry release and deployment marker'), 'uses');

    expect($uses)->toMatch('/^getsentry\/action-release@[0-9a-f]{40}$/');

    // The same pinning convention every existing workflow already follows.
    $source = File::get(base_path('.github/actions/sentry-release/action.yml'));

    expect($source)->toContain('getsentry/action-release@ff07929a6537bac57790c3451cf4d364aca38528 # v3.7.0');

    foreach ($steps as $step) {
        $stepUses = data_get($step, 'uses');

        if (is_string($stepUses) && ! str_starts_with($stepUses, './')) {
            expect($stepUses)->toMatch('/^[^@\s]+@[0-9a-f]{40}$/');
        }
    }
});

it('never fails a healthy deployment when Sentry is unavailable', function () {
    $steps = collect(data_get(sentryReleaseAction(), 'runs.steps'))->keyBy('name');

    $sentryStep = $steps->get('Create Sentry release and deployment marker');

    // The whole point: an unreachable sentry.io is an observability gap, not a
    // reason to fail the job — and never a reason to roll the application back.
    expect(data_get($sentryStep, 'continue-on-error'))->toBeTrue();

    // ...but the outcome is surfaced explicitly rather than swallowed.
    $reportStep = $steps->get('Report observability outcome');

    expect(data_get($reportStep, 'run'))
        ->toContain('observability registration FAILED')
        ->toContain('recorded=')
        ->toContain('GITHUB_STEP_SUMMARY');
});

it('degrades to a no-op when an environment has no Sentry credentials yet', function () {
    $steps = collect(data_get(sentryReleaseAction(), 'runs.steps'))->keyBy('name');

    expect(data_get($steps->get('Create Sentry release and deployment marker'), 'if'))
        ->toBe("\${{ steps.preflight.outputs.configured == 'true' }}");

    expect(data_get($steps->get('Validate observability inputs'), 'run'))
        ->toContain('configured=false')
        ->toContain('skipping release registration');
});

it('rejects a release ID no RateGuru deployment could have produced, before any network call', function () {
    $steps = collect(data_get(sentryReleaseAction(), 'runs.steps'))->keyBy('name');
    $run = data_get($steps->get('Validate observability inputs'), 'run');

    // Byte-identical to the expression the deploy action validates against, so
    // a Sentry release and a RateGuru release can never diverge in shape.
    $releaseRegex = "release_regex='^v[0-9]+\\.[0-9]+\\.[0-9]+-[0-9]{8}-[0-9]{6}-[0-9a-f]{7,40}\$'";

    expect($run)->toContain($releaseRegex);
    expect(File::get(base_path('.github/actions/deploy-rateguru/action.yml')))->toContain($releaseRegex);

    // Environment is the class only — a brand may never become an environment.
    expect($run)->toContain('environment must be staging or production');
});

it('takes no untrusted expression into a shell, and no secret onto a command line', function () {
    $action = sentryReleaseAction();

    foreach (data_get($action, 'runs.steps') as $step) {
        if (! isset($step['run'])) {
            continue;
        }

        expect(data_get($step, 'shell'))->toBe('bash');

        // Inputs reach bash through env:, never interpolated into the script.
        expect($step['run'])->not->toContain('${{ inputs.');
    }

    $source = File::get(base_path('.github/actions/sentry-release/action.yml'));

    // The auth token is only ever an env var on the official action's step —
    // never an argument, which would expose it in a process list.
    expect($source)
        ->toContain('SENTRY_AUTH_TOKEN: ${{ inputs.sentry-auth-token }}')
        ->not->toContain('--auth-token')
        ->not->toContain('echo "${SENTRY_AUTH_TOKEN')
        ->not->toContain('SENTRY_AUTH_TOKEN}"');
});

it('is called only after a successful, health-checked deployment', function () {
    $callSites = sentryReleaseCallSites();

    expect(array_keys($callSites))->toEqualCanonicalizing([
        'deploy-staging.yml:observability',
        'release.yml:deploy-staging',
        'release.yml:deploy-production',
        'rollback-staging.yml:rollback',
    ]);

    $deployStaging = Yaml::parse(File::get(base_path('.github/workflows/deploy-staging.yml')));

    // A separate job that needs the deploy job: it cannot start unless deploy
    // finished successfully, and deploy only finishes after the server-side
    // health check and the active-release verification both passed.
    expect(data_get($deployStaging, 'jobs.observability.needs'))->toBe(['build', 'deploy'])
        ->and(data_get($deployStaging, 'jobs.observability.environment'))->toBe('staging')
        ->and(data_get($deployStaging, 'jobs.observability.permissions.contents'))->toBe('read');

    $release = Yaml::parse(File::get(base_path('.github/workflows/release.yml')));

    // Inside the deploy jobs, after the deployment action has already run.
    foreach (['deploy-staging' => 'staging', 'deploy-production' => 'production'] as $job => $environment) {
        $steps = collect(data_get($release, "jobs.{$job}.steps"));
        $deployIndex = $steps->search(fn (array $step): bool => data_get($step, 'uses') === './.github/actions/deploy-rateguru');
        $sentryIndex = $steps->search(fn (array $step): bool => data_get($step, 'uses') === './.github/actions/sentry-release');

        expect($deployIndex)->not->toBeFalse("{$job} must still deploy through the deploy action")
            ->and($sentryIndex)->toBeGreaterThan($deployIndex, "the Sentry marker in {$job} must come after the deployment");

        expect(data_get($release, "jobs.{$job}.environment"))->toBe($environment);
    }
});

it('records the canonical release the pipeline built, never a recomputed one', function () {
    $callSites = sentryReleaseCallSites();

    expect(data_get($callSites['deploy-staging.yml:observability']['step'], 'with.release-id'))
        ->toBe('${{ needs.build.outputs.release-id }}')
        ->and(data_get($callSites['deploy-staging.yml:observability']['step'], 'with.commit'))
        ->toBe('${{ needs.build.outputs.source-sha }}');

    foreach (['release.yml:deploy-staging', 'release.yml:deploy-production'] as $site) {
        expect(data_get($callSites[$site]['step'], 'with.release-id'))
            ->toBe('${{ needs.validate.outputs.release-id }}')
            ->and(data_get($callSites[$site]['step'], 'with.commit'))
            ->toBe('${{ needs.validate.outputs.source-sha }}');
    }

    // No workflow may build a second release identifier for Sentry's benefit.
    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $path) {
        expect(File::get($path))->not->toContain('SENTRY_RELEASE=');
    }
});

it('uses the environment class for Sentry, never the deployment target', function () {
    $callSites = sentryReleaseCallSites();

    $environments = collect($callSites)->map(fn (array $site): mixed => data_get($site['step'], 'with.environment'));

    expect($environments->all())->toBe([
        'deploy-staging.yml:observability' => 'staging',
        'release.yml:deploy-staging' => 'staging',
        'release.yml:deploy-production' => 'production',
        'rollback-staging.yml:rollback' => 'staging',
    ]);

    foreach ($callSites as $label => $site) {
        $environment = (string) data_get($site['step'], 'with.environment');

        foreach (['tits-guru', 'staging-main', 'food-guru'] as $target) {
            expect(str_contains($environment, $target))
                ->toBeFalse("{$label} must not turn the brand {$target} into a Sentry environment");
        }
    }
});

it('keeps the Sentry auth token in GitHub secrets and out of every server-facing surface', function () {
    $callSites = sentryReleaseCallSites();

    foreach ($callSites as $label => $site) {
        expect(data_get($site['step'], 'with.sentry-auth-token'))
            ->toBe('${{ secrets.SENTRY_AUTH_TOKEN }}', "{$label} must take the token from environment secrets");

        // Org and project are not credentials; they follow the repository's
        // existing convention of vars for non-secret deployment coordinates.
        expect(data_get($site['step'], 'with.sentry-org'))->toBe('${{ vars.SENTRY_ORG }}')
            ->and(data_get($site['step'], 'with.sentry-project'))->toBe('${{ vars.SENTRY_PROJECT }}');
    }

    // Nothing that is ever installed on, copied to, or read by a VPS may so
    // much as mention the auth token.
    $serverFacing = array_merge(
        glob(base_path('infrastructure/scripts/*')) ?: [],
        glob(base_path('infrastructure/templates/environment/*')) ?: [],
        glob(base_path('infrastructure/config/**/*')) ?: [],
        [base_path('infrastructure/config/deployment-targets.json'), base_path('.env.example')],
    );

    foreach ($serverFacing as $path) {
        if (! is_file($path)) {
            continue;
        }

        expect(str_contains(File::get($path), 'SENTRY_AUTH_TOKEN'))
            ->toBeFalse('SENTRY_AUTH_TOKEN must never reach the server: '.str_replace(base_path().'/', '', $path));
    }
});

it('marks a rollback as a new deployment of the same immutable release', function () {
    $rollback = Yaml::parse(File::get(base_path('.github/workflows/rollback-staging.yml')));
    $steps = collect(data_get($rollback, 'jobs.rollback.steps'))->keyBy('name');

    // The release is read back off the server after the rollback succeeded, so
    // mode=previous reports what the target actually landed on.
    $resolve = $steps->get('Resolve the release now serving the target');

    expect(data_get($resolve, 'run'))
        ->toContain("'basename \"\$(readlink -f %q)\"'")
        ->toContain('release_id=${active_release}')
        // A rollback that succeeded must never be failed by observability, so
        // anything that is not a canonical release ID records nothing instead.
        ->toContain('skipping Sentry deployment marker')
        // Same rule for the read-back connection itself: this is a second SSH
        // session made only to fetch a value, and under `set -e` an unguarded
        // assignment would abort the step and fail an already-healthy rollback.
        ->toContain('if ! active_release="$(')
        ->toContain('Could not read the active release back from the target.');

    $marker = $steps->get('Record Sentry deployment marker for the restored release');

    expect(data_get($marker, 'with.release-id'))->toBe('${{ steps.active.outputs.release_id }}')
        ->and(data_get($marker, 'if'))->toBe("\${{ steps.active.outputs.release_id != '' }}");

    // No commit is passed: the old release already carries its own commits and
    // must not be mutated, and no synthetic "rollback" release is ever created.
    expect(data_get($marker, 'with'))->not->toHaveKey('commit');

    expect(File::get(base_path('.github/workflows/rollback-staging.yml')))
        ->not->toContain('release-id: rollback')
        ->not->toContain('rollback-');

    // Rollback ordering: the marker can only run after the wrapper call, which
    // itself only succeeds once the server-side health check passed.
    $names = collect(data_get($rollback, 'jobs.rollback.steps'))->pluck('name')->all();

    expect(array_search('Roll back via target-aware wrapper', $names, true))
        ->toBeLessThan(array_search('Record Sentry deployment marker for the restored release', $names, true));
});

it('leaves the deployment workflows themselves otherwise unchanged', function () {
    // Phase 6 adds observability; it does not redesign deployment. Every
    // deploy-rateguru call site, and the health-check ordering they rely on,
    // must still be exactly what Phase 4 and Phase 5 established.
    $deployStaging = Yaml::parse(File::get(base_path('.github/workflows/deploy-staging.yml')));

    expect(data_get($deployStaging, 'jobs.deploy.needs'))->toBe(['resolve', 'build'])
        ->and(data_get($deployStaging, 'permissions.contents'))->toBe('read')
        ->and(data_get($deployStaging, 'concurrency.group'))->toBe('rateguru-staging-deployment');

    $rollback = Yaml::parse(File::get(base_path('.github/workflows/rollback-staging.yml')));

    expect(data_get($rollback, 'permissions.contents'))->toBe('read')
        ->and(data_get($rollback, 'concurrency.group'))->toBe('rateguru-staging-deployment');

    // No workflow was given wider permissions to make Sentry work.
    foreach (glob(base_path('.github/workflows/*.yml')) ?: [] as $path) {
        $workflow = Yaml::parse(File::get($path));

        expect(data_get($workflow, 'permissions'))->not->toBe('write-all');

        foreach ((array) data_get($workflow, 'jobs', []) as $jobName => $job) {
            expect(data_get($job, 'permissions'))
                ->not->toBe('write-all', "{$path}:{$jobName} must not request write-all");
        }
    }
});
