<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    $path = base_path('.github/workflows/release.yml');

    expect(File::exists($path))->toBeTrue();

    $this->releaseWorkflowSource = File::get($path);
    $this->releaseWorkflow = Yaml::parse($this->releaseWorkflowSource);
    $this->validateSteps = collect(data_get($this->releaseWorkflow, 'jobs.validate.steps'))->keyBy('name');
    $this->buildSteps = collect(data_get($this->releaseWorkflow, 'jobs.build.steps'))->keyBy('name');
    $this->stagingSteps = collect(data_get($this->releaseWorkflow, 'jobs.deploy-staging.steps'))->keyBy('name');
    $this->productionSteps = collect(data_get($this->releaseWorkflow, 'jobs.deploy-production.steps'))->keyBy('name');
});

it('restricts production releases by trigger permissions and concurrency', function () {
    expect($this->releaseWorkflow)
        ->toBeArray()
        ->and(data_get($this->releaseWorkflow, 'name'))->toBe('Release to production')
        ->and(data_get($this->releaseWorkflow, 'on.push.tags'))->toBe(['v*'])
        ->and(data_get($this->releaseWorkflow, 'permissions.contents'))->toBe('read')
        ->and(data_get($this->releaseWorkflow, 'concurrency.group'))->toBe('rateguru-production-release')
        ->and(data_get($this->releaseWorkflow, 'concurrency.cancel-in-progress'))->toBeFalse();
});

it('orders release jobs through staging and production environments', function () {
    expect(data_get($this->releaseWorkflow, 'jobs.build.needs'))->toBe('validate')
        ->and(data_get($this->releaseWorkflow, 'jobs.deploy-staging.needs'))->toBe(['validate', 'build'])
        ->and(data_get($this->releaseWorkflow, 'jobs.deploy-staging.environment'))->toBe('staging')
        ->and(data_get($this->releaseWorkflow, 'jobs.deploy-production.needs'))->toBe(['validate', 'build', 'deploy-staging'])
        ->and(data_get($this->releaseWorkflow, 'jobs.deploy-production.environment'))->toBe('production');
});

it('wires release steps to reuse one immutable artifact', function () {
    expect(data_get($this->validateSteps->get('Checkout production tag'), 'with.persist-credentials'))
        ->toBeFalse()
        ->and(data_get($this->validateSteps->get('Validate tag and main ancestry'), 'env.SOURCE_TAG'))
        ->toBe('${{ github.ref_name }}')
        ->and(data_get($this->validateSteps->get('Validate tag and main ancestry'), 'run'))
        ->not->toContain('${{');

    expect(data_get($this->buildSteps->get('Checkout exact release commit'), 'with.ref'))
        ->toBe('${{ needs.validate.outputs.source-sha }}')
        ->and(data_get($this->buildSteps->get('Checkout exact release commit'), 'with.persist-credentials'))
        ->toBeFalse()
        ->and(data_get($this->buildSteps->get('Setup Node'), 'with.node-version'))
        ->toBe(26)
        ->and(data_get($this->buildSteps->get('Setup Node'), 'with.cache'))
        ->toBeNull()
        ->and(data_get($this->buildSteps->get('Upload immutable production artifact'), 'with.retention-days'))
        ->toBe(90);

    foreach ([$this->stagingSteps, $this->productionSteps] as $deploymentSteps) {
        expect(data_get($deploymentSteps->get('Checkout trusted deployment action'), 'with.ref'))
            ->toBe('${{ needs.validate.outputs.source-sha }}')
            ->and(data_get($deploymentSteps->get('Checkout trusted deployment action'), 'with.persist-credentials'))
            ->toBeFalse();
    }

    expect(data_get($this->stagingSteps->get('Deploy release artifact to staging'), 'uses'))
        ->toBe('./.github/actions/deploy-rateguru')
        ->and(data_get($this->stagingSteps->get('Deploy release artifact to staging'), 'with.release-id'))
        ->toBe('${{ needs.validate.outputs.release-id }}')
        ->and(data_get($this->productionSteps->get('Deploy verified artifact to production'), 'uses'))
        ->toBe('./.github/actions/deploy-rateguru')
        ->and(data_get($this->productionSteps->get('Deploy verified artifact to production'), 'with.release-id'))
        ->toBe('${{ needs.validate.outputs.release-id }}')
        ->and(data_get($this->stagingSteps->get('Download immutable production artifact'), 'with.name'))
        ->toBe('${{ needs.validate.outputs.workflow-artifact-name }}')
        ->and(data_get($this->productionSteps->get('Download the same verified artifact'), 'with.name'))
        ->toBe('${{ needs.validate.outputs.workflow-artifact-name }}');
});

it('pins every external release action to a commit SHA', function () {
    $externalActions = $this->validateSteps
        ->merge($this->buildSteps)
        ->merge($this->stagingSteps)
        ->merge($this->productionSteps)
        ->pluck('uses')
        ->filter(fn (mixed $uses): bool => is_string($uses) && ! str_starts_with($uses, './'));

    foreach ($externalActions as $uses) {
        expect($uses)->toMatch('/^[^@\s]+@[0-9a-f]{40}$/');
    }
});

it('retains required production release script safeguards', function () {
    expect($this->releaseWorkflowSource)
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
