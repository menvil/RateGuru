<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * The staging deployment workflow after Phase 7.1: manual-only, staging
 * source/release policy of its own, and nothing else — every mechanical build
 * step now lives in the shared .github/actions/build-rateguru action
 * (BuildRateGuruActionTest owns that contract).
 */
beforeEach(function () {
    $path = base_path('.github/workflows/deploy-staging.yml');

    expect(File::exists($path))->toBeTrue();

    $this->source = File::get($path);
    $this->workflow = Yaml::parse($this->source);
    $this->resolveSteps = collect(data_get($this->workflow, 'jobs.resolve.steps'))->keyBy('name');
    $this->buildSteps = collect(data_get($this->workflow, 'jobs.build.steps'))->keyBy('name');
    $this->deploySteps = collect(data_get($this->workflow, 'jobs.deploy.steps'))->keyBy('name');
});

it('deploys manually selected refs to staging', function () {
    $externalActions = $this->buildSteps
        ->merge($this->deploySteps)
        ->pluck('uses')
        ->filter(fn (mixed $uses): bool => is_string($uses) && ! str_starts_with($uses, './'));

    expect($this->workflow)
        ->toBeArray()
        ->and(data_get($this->workflow, 'name'))->toBe('Deploy to staging')
        ->and(data_get($this->workflow, 'on.workflow_run'))->toBeNull()
        ->and(data_get($this->workflow, 'on.workflow_dispatch.inputs.ref.default'))->toBe('develop')
        ->and(data_get($this->workflow, 'on.workflow_dispatch.inputs.ref.required'))->toBeTrue()
        ->and(data_get($this->workflow, 'on.workflow_dispatch.inputs.run-migrations.default'))->toBeFalse()
        ->and(data_get($this->workflow, 'on.workflow_dispatch.inputs.run-migrations.type'))->toBe('boolean')
        ->and(data_get($this->workflow, 'permissions.contents'))->toBe('read')
        ->and(data_get($this->workflow, 'concurrency.group'))->toBe('rateguru-staging-deployment')
        ->and(data_get($this->workflow, 'concurrency.cancel-in-progress'))->toBeFalse()
        ->and(data_get($this->workflow, 'jobs.build.needs'))->toBe('resolve')
        ->and(data_get($this->workflow, 'jobs.deploy.needs'))->toBe(['resolve', 'build'])
        ->and(data_get($this->workflow, 'jobs.deploy.environment'))->toBe('staging');

    expect(data_get($this->resolveSteps->get('Resolve exact source revision'), 'env.DISPATCH_REF'))
        ->toBe('${{ inputs.ref }}')
        ->and(data_get($this->resolveSteps->get('Resolve exact source revision'), 'run'))
        ->not->toContain('${{');

    expect(data_get($this->buildSteps->get('Checkout requested ref'), 'uses'))
        ->toMatch('/^actions\/checkout@[0-9a-f]{40}$/')
        ->and(data_get($this->buildSteps->get('Checkout requested ref'), 'with.ref'))
        ->toBe('${{ needs.resolve.outputs.checkout_ref }}')
        ->and(data_get($this->deploySteps->get('Checkout deployment action'), 'with.ref'))
        ->toBe('develop')
        ->and(data_get($this->deploySteps->get('Download immutable release artifact'), 'uses'))
        ->toMatch('/^actions\/download-artifact@[0-9a-f]{40}$/')
        ->and(data_get($this->deploySteps->get('Deploy to staging'), 'uses'))
        ->toBe('./.github/actions/deploy-rateguru')
        ->and(data_get($this->deploySteps->get('Deploy to staging'), 'with.run-migrations'))
        ->toBe('${{ needs.resolve.outputs.run_migrations }}')
        ->and($this->deploySteps->has('Verify social preview as external crawler'))
        ->toBeFalse();

    expect(data_get($this->buildSteps->get('Checkout requested ref'), 'with.persist-credentials'))
        ->toBeFalse()
        ->and(data_get($this->deploySteps->get('Checkout deployment action'), 'with.persist-credentials'))
        ->toBeFalse();

    foreach ($externalActions as $uses) {
        expect($uses)->toMatch('/^[^@\s]+@[0-9a-f]{40}$/');
    }

    expect($this->source)
        ->not->toMatch('/^  workflow_run:/m')
        ->toContain('release-id: ${{ needs.build.outputs.release-id }}');
});

it('builds through the one shared build implementation, passing only staging policy', function () {
    $build = $this->buildSteps->get('Build immutable release artifact');

    expect(data_get($build, 'uses'))->toBe('./.github/actions/build-rateguru')
        ->and(data_get($build, 'id'))->toBe('build');

    expect(data_get($build, 'with.source-ref'))->toBe('${{ needs.resolve.outputs.source_ref }}')
        ->and(data_get($build, 'with.release-version'))->toBe('${{ needs.resolve.outputs.release_version }}')
        ->and(data_get($build, 'with.workflow-artifact-prefix'))->toBe('rateguru-release')
        // Staging artifacts are consumed by the deploy job in the same run;
        // they are kept only briefly for manual re-download afterwards.
        ->and(data_get($build, 'with.artifact-retention-days'))->toBe('3')
        ->and(data_get($build, 'with.node-cache'))->toBe('npm')
        ->and(data_get($build, 'with.release-metadata'))->toBe('{"environment": "staging"}');

    // Staging never claims to be a validated production build.
    expect(data_get($build, 'with.validate-composer'))->toBeNull()
        ->and(data_get($build, 'with.expected-source-sha'))->toBeNull();

    // The build job re-exports exactly the identity the deploy job consumes,
    // and takes all of it from the shared action rather than recomputing it.
    expect(data_get($this->workflow, 'jobs.build.outputs'))->toBe([
        'source-sha' => '${{ steps.build.outputs.source-sha }}',
        'release-id' => '${{ steps.build.outputs.release-id }}',
        'artifact-name' => '${{ steps.build.outputs.artifact-name }}',
        'workflow-artifact-name' => '${{ steps.build.outputs.workflow-artifact-name }}',
    ]);
});

it('resolves the staging release version itself, because that policy is not the build action\'s', function () {
    $run = data_get($this->resolveSteps->get('Resolve exact source revision'), 'run');

    expect($run)
        ->toContain('normalized_source_ref="${source_ref#refs/tags/}"')
        ->toContain('release_version="v0.0.0"')
        ->toContain('release_version="${BASH_REMATCH[1]}"')
        ->toContain('echo "release_version=${release_version}"');

    expect(data_get($this->workflow, 'jobs.resolve.outputs.release_version'))
        ->toBe('${{ steps.source.outputs.release_version }}');
});

it('keeps run-migrations an explicit operator choice, validated before anything is built', function () {
    $run = data_get($this->resolveSteps->get('Resolve exact source revision'), 'run');

    expect(data_get($this->resolveSteps->get('Resolve exact source revision'), 'env.DISPATCH_RUN_MIGRATIONS'))
        ->toBe('${{ inputs.run-migrations }}');

    expect($run)
        ->toContain('case "${DISPATCH_RUN_MIGRATIONS}"')
        ->toContain('Invalid run-migrations value');
});

it('has no dead workflow_run auto-deploy path left anywhere', function () {
    // The workflow has been workflow_dispatch-only for a long time; the
    // event-name branch that resolved a workflow_run head SHA was unreachable
    // code pretending staging could deploy itself.
    expect(array_keys((array) data_get($this->workflow, 'on')))->toBe(['workflow_dispatch']);

    expect($this->source)
        ->not->toContain('workflow_run')
        ->not->toContain('EVENT_NAME')
        ->not->toContain('WORKFLOW_RUN_SHA')
        ->not->toContain('WORKFLOW_RUN_BRANCH')
        ->not->toContain('head_sha')
        ->not->toContain('head_branch');

    // No job may be gated on an event that can never fire.
    foreach ((array) data_get($this->workflow, 'jobs', []) as $jobName => $job) {
        expect(data_get($job, 'if'))->toBeNull("{$jobName} still carries a trigger-shaped condition");
    }
});

it('never rebuilds the mechanical pipeline that now belongs to the shared build action', function () {
    // Exactly the duplication Phase 7.1 removed: if any of these reappears
    // here, deploy-staging.yml has started forking the build again.
    foreach ([
        'composer install',
        'npm ci',
        'npm run build',
        'rsync',
        'sha256sum',
        'tar \\',
        '"${package_root}/release.json"',
        'verify-required-clis',
        'setup-php',
        'setup-node',
        'upload-artifact',
        'release_id=',
    ] as $mechanic) {
        expect(str_contains($this->source, $mechanic))
            ->toBeFalse("deploy-staging.yml re-implements the shared build step: {$mechanic}");
    }
});
