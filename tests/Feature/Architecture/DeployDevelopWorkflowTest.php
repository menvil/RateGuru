<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

it('builds and deploys an immutable develop release to staging', function () {
    $path = base_path('.github/workflows/deploy-develop.yml');

    expect(File::exists($path))->toBeTrue();

    $source = File::get($path);
    $workflow = Yaml::parse($source);
    $buildSteps = collect(data_get($workflow, 'jobs.build.steps'))->keyBy('name');
    $deploySteps = collect(data_get($workflow, 'jobs.deploy.steps'))->keyBy('name');

    expect($workflow)
        ->toBeArray()
        ->and(data_get($workflow, 'name'))->toBe('Deploy develop to staging')
        ->and(data_get($workflow, 'on.workflow_dispatch.inputs.ref.default'))->toBe('develop')
        ->and(data_get($workflow, 'on.workflow_dispatch.inputs.ref.required'))->toBeTrue()
        ->and(data_get($workflow, 'permissions.contents'))->toBe('read')
        ->and(data_get($workflow, 'concurrency.group'))->toBe('rateguru-staging-deployment')
        ->and(data_get($workflow, 'concurrency.cancel-in-progress'))->toBeFalse()
        ->and(data_get($workflow, 'jobs.deploy.needs'))->toBe('build')
        ->and(data_get($workflow, 'jobs.deploy.environment'))->toBe('staging');

    expect(data_get($buildSteps->get('Checkout requested ref'), 'uses'))
        ->toBe('actions/checkout@v7')
        ->and(data_get($buildSteps->get('Setup Node'), 'uses'))
        ->toBe('actions/setup-node@v7')
        ->and(data_get($buildSteps->get('Upload immutable release artifact'), 'uses'))
        ->toBe('actions/upload-artifact@v7')
        ->and(data_get($deploySteps->get('Download immutable release artifact'), 'uses'))
        ->toBe('actions/download-artifact@v8')
        ->and(data_get($deploySteps->get('Deploy to staging'), 'uses'))
        ->toBe('./.github/actions/deploy-rateguru');

    expect(data_get($buildSteps->get('Build release archive'), 'env.SOURCE_REF'))
        ->toBe('${{ inputs.ref }}')
        ->and(data_get($buildSteps->get('Build release archive'), 'run'))
        ->not->toContain('${{ inputs.');

    expect($source)
        ->toContain('--classmap-authoritative')
        ->toContain("--exclude='.env.*'")
        ->toContain("--exclude='database/database.sqlite'")
        ->toContain('--arg source_sha "${source_sha}"')
        ->toContain('sha256sum "${artifact_name}"')
        ->toContain('retention-days: 14')
        ->toContain('ref: ${{ needs.build.outputs.source-sha }}')
        ->toContain('release-id: ${{ needs.build.outputs.release-id }}')
        ->toContain('run-migrations: "true"');
});
