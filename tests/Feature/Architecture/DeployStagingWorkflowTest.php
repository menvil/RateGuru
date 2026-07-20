<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

it('deploys successful develop builds and manually selected refs to staging', function () {
    $path = base_path('.github/workflows/deploy-staging.yml');

    expect(File::exists($path))->toBeTrue();

    $source = File::get($path);
    $workflow = Yaml::parse($source);
    $resolveSteps = collect(data_get($workflow, 'jobs.resolve.steps'))->keyBy('name');
    $buildSteps = collect(data_get($workflow, 'jobs.build.steps'))->keyBy('name');
    $deploySteps = collect(data_get($workflow, 'jobs.deploy.steps'))->keyBy('name');
    $externalActions = $buildSteps
        ->merge($deploySteps)
        ->pluck('uses')
        ->filter(fn (mixed $uses): bool => is_string($uses) && ! str_starts_with($uses, './'));

    expect($workflow)
        ->toBeArray()
        ->and(data_get($workflow, 'name'))->toBe('Deploy to staging')
        ->and(data_get($workflow, 'on.workflow_run.workflows'))->toBe(['CI'])
        ->and(data_get($workflow, 'on.workflow_run.types'))->toBe(['completed'])
        ->and(data_get($workflow, 'on.workflow_run.branches'))->toBe(['develop'])
        ->and(data_get($workflow, 'on.workflow_dispatch.inputs.ref.default'))->toBe('develop')
        ->and(data_get($workflow, 'on.workflow_dispatch.inputs.ref.required'))->toBeTrue()
        ->and(data_get($workflow, 'on.workflow_dispatch.inputs.run-migrations.default'))->toBeFalse()
        ->and(data_get($workflow, 'on.workflow_dispatch.inputs.run-migrations.type'))->toBe('boolean')
        ->and(data_get($workflow, 'permissions.contents'))->toBe('read')
        ->and(data_get($workflow, 'concurrency.group'))->toBe('rateguru-staging-deployment')
        ->and(data_get($workflow, 'concurrency.cancel-in-progress'))->toBeFalse()
        ->and(data_get($workflow, 'jobs.build.needs'))->toBe('resolve')
        ->and(data_get($workflow, 'jobs.deploy.needs'))->toBe(['resolve', 'build'])
        ->and(data_get($workflow, 'jobs.deploy.environment'))->toBe('staging');

    expect(data_get($resolveSteps->get('Resolve exact source revision'), 'env.WORKFLOW_RUN_SHA'))
        ->toBe('${{ github.event.workflow_run.head_sha }}')
        ->and(data_get($resolveSteps->get('Resolve exact source revision'), 'env.DISPATCH_REF'))
        ->toBe('${{ inputs.ref }}')
        ->and(data_get($resolveSteps->get('Resolve exact source revision'), 'run'))
        ->not->toContain('${{');

    expect(data_get($buildSteps->get('Checkout requested ref'), 'uses'))
        ->toMatch('/^actions\/checkout@[0-9a-f]{40}$/')
        ->and(data_get($buildSteps->get('Checkout requested ref'), 'with.ref'))
        ->toBe('${{ needs.resolve.outputs.checkout_ref }}')
        ->and(data_get($buildSteps->get('Setup Node'), 'uses'))
        ->toMatch('/^actions\/setup-node@[0-9a-f]{40}$/')
        ->and(data_get($buildSteps->get('Upload immutable release artifact'), 'uses'))
        ->toMatch('/^actions\/upload-artifact@[0-9a-f]{40}$/')
        ->and(data_get($deploySteps->get('Checkout deployment action'), 'with.ref'))
        ->toBe('develop')
        ->and(data_get($deploySteps->get('Download immutable release artifact'), 'uses'))
        ->toMatch('/^actions\/download-artifact@[0-9a-f]{40}$/')
        ->and(data_get($deploySteps->get('Deploy to staging'), 'uses'))
        ->toBe('./.github/actions/deploy-rateguru')
        ->and(data_get($deploySteps->get('Deploy to staging'), 'with.run-migrations'))
        ->toBe('${{ needs.resolve.outputs.run_migrations }}');

    expect(data_get($buildSteps->get('Checkout requested ref'), 'with.persist-credentials'))
        ->toBeFalse()
        ->and(data_get($deploySteps->get('Checkout deployment action'), 'with.persist-credentials'))
        ->toBeFalse();

    foreach ($externalActions as $uses) {
        expect($uses)->toMatch('/^[^@\s]+@[0-9a-f]{40}$/');
    }

    expect(data_get($buildSteps->get('Build release archive'), 'env.SOURCE_REF'))
        ->toBe('${{ needs.resolve.outputs.source_ref }}')
        ->and(data_get($buildSteps->get('Build release archive'), 'run'))
        ->not->toContain('${{');

    expect($source)
        ->toContain('--classmap-authoritative')
        ->toContain("--exclude='.env.*'")
        ->toContain("--exclude='database/database.sqlite'")
        ->toContain('normalized_source_ref="${SOURCE_REF#refs/tags/}"')
        ->toContain('release_version="${BASH_REMATCH[1]}"')
        ->toContain('release_id="${release_version}-${timestamp}-${short_sha}"')
        ->toContain('--arg source_sha "${source_sha}"')
        ->toContain('sha256sum "${artifact_name}"')
        ->toContain('retention-days: 14')
        ->toContain('release-id: ${{ needs.build.outputs.release-id }}');
});
