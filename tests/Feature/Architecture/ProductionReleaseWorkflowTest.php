<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

it('promotes one main-backed tag artifact through staging to production', function () {
    $path = base_path('.github/workflows/release.yml');

    expect(File::exists($path))->toBeTrue();

    $source = File::get($path);
    $workflow = Yaml::parse($source);
    $validateSteps = collect(data_get($workflow, 'jobs.validate.steps'))->keyBy('name');
    $buildSteps = collect(data_get($workflow, 'jobs.build.steps'))->keyBy('name');
    $stagingSteps = collect(data_get($workflow, 'jobs.deploy-staging.steps'))->keyBy('name');
    $productionSteps = collect(data_get($workflow, 'jobs.deploy-production.steps'))->keyBy('name');
    $externalActions = $validateSteps
        ->merge($buildSteps)
        ->merge($stagingSteps)
        ->merge($productionSteps)
        ->pluck('uses')
        ->filter(fn (mixed $uses): bool => is_string($uses) && ! str_starts_with($uses, './'));

    expect($workflow)
        ->toBeArray()
        ->and(data_get($workflow, 'name'))->toBe('Release to production')
        ->and(data_get($workflow, 'on.push.tags'))->toBe(['v*'])
        ->and(data_get($workflow, 'permissions.contents'))->toBe('read')
        ->and(data_get($workflow, 'concurrency.group'))->toBe('rateguru-production-release')
        ->and(data_get($workflow, 'concurrency.cancel-in-progress'))->toBeFalse()
        ->and(data_get($workflow, 'jobs.build.needs'))->toBe('validate')
        ->and(data_get($workflow, 'jobs.deploy-staging.needs'))->toBe(['validate', 'build'])
        ->and(data_get($workflow, 'jobs.deploy-staging.environment'))->toBe('staging')
        ->and(data_get($workflow, 'jobs.deploy-production.needs'))->toBe(['validate', 'build', 'deploy-staging'])
        ->and(data_get($workflow, 'jobs.deploy-production.environment'))->toBe('production');

    expect(data_get($validateSteps->get('Checkout production tag'), 'with.persist-credentials'))
        ->toBeFalse()
        ->and(data_get($validateSteps->get('Validate tag and main ancestry'), 'env.SOURCE_TAG'))
        ->toBe('${{ github.ref_name }}')
        ->and(data_get($validateSteps->get('Validate tag and main ancestry'), 'run'))
        ->not->toContain('${{');

    expect(data_get($buildSteps->get('Checkout exact release commit'), 'with.ref'))
        ->toBe('${{ needs.validate.outputs.source-sha }}')
        ->and(data_get($buildSteps->get('Checkout exact release commit'), 'with.persist-credentials'))
        ->toBeFalse()
        ->and(data_get($buildSteps->get('Upload immutable production artifact'), 'with.retention-days'))
        ->toBe(90);

    foreach ([$stagingSteps, $productionSteps] as $deploymentSteps) {
        expect(data_get($deploymentSteps->get('Checkout trusted deployment action'), 'with.ref'))
            ->toBe('${{ needs.validate.outputs.source-sha }}')
            ->and(data_get($deploymentSteps->get('Checkout trusted deployment action'), 'with.persist-credentials'))
            ->toBeFalse();
    }

    expect(data_get($stagingSteps->get('Deploy release artifact to staging'), 'uses'))
        ->toBe('./.github/actions/deploy-rateguru')
        ->and(data_get($stagingSteps->get('Deploy release artifact to staging'), 'with.release-id'))
        ->toBe('${{ needs.validate.outputs.release-id }}')
        ->and(data_get($productionSteps->get('Deploy verified artifact to production'), 'uses'))
        ->toBe('./.github/actions/deploy-rateguru')
        ->and(data_get($productionSteps->get('Deploy verified artifact to production'), 'with.release-id'))
        ->toBe('${{ needs.validate.outputs.release-id }}')
        ->and(data_get($stagingSteps->get('Download immutable production artifact'), 'with.name'))
        ->toBe('${{ needs.validate.outputs.workflow-artifact-name }}')
        ->and(data_get($productionSteps->get('Download the same verified artifact'), 'with.name'))
        ->toBe('${{ needs.validate.outputs.workflow-artifact-name }}');

    foreach ($externalActions as $uses) {
        expect($uses)->toMatch('/^[^@\s]+@[0-9a-f]{40}$/');
    }

    expect($source)
        ->toContain("tag_regex='^v([0-9]+)\\.([0-9]+)\\.([0-9]+)")
        ->toContain('git merge-base \\')
        ->toContain('--is-ancestor \\')
        ->toContain('release_id="${version}-${timestamp}-${short_sha}"')
        ->toContain('--argjson targets \'["staging", "production"]\'')
        ->toContain('--classmap-authoritative')
        ->toContain("--exclude='.env.*'")
        ->toContain("--exclude='database/database.sqlite'")
        ->toContain('sha256sum "${ARTIFACT_NAME}"')
        ->toContain('run-migrations: "true"');
});
