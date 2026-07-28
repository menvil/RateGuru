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

it('does not gate deployments on an external social preview fixture', function () {
    foreach ([$this->stagingSteps, $this->productionSteps] as $deploymentSteps) {
        expect($deploymentSteps->has('Verify social preview as external crawler'))->toBeFalse();
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
        ->toContain('run-migrations: "true"')
        ->toContain('infrastructure/scripts/verify-required-clis')
        ->toContain('# --- verify infrastructure CLI executable bits (begin) ---');

    $releaseRun = data_get($this->buildSteps->get('Build release archive'), 'run');
    expect(mb_strpos($releaseRun, 'rsync \\'))
        ->toBeLessThan(mb_strpos($releaseRun, '# --- verify infrastructure CLI executable bits (begin) ---'))
        ->and(mb_strpos($releaseRun, '# --- verify infrastructure CLI executable bits (end) ---'))
        ->toBeLessThan(mb_strpos($releaseRun, 'tar \\'));
});

it('delegates the production artifact build\'s CLI executable-bit check to the shared verify-required-clis, and fails closed when a CLI is not executable', function () {
    // Proves the workflow step is exactly a delegating call to the real,
    // shared infrastructure/scripts/verify-required-clis — the same
    // algorithm deploy itself uses — never a reimplementation, then runs
    // that exact extracted line end to end against a scratch package_root.
    $run = data_get($this->buildSteps->get('Build release archive'), 'run');

    expect(preg_match(
        '/# --- verify infrastructure CLI executable bits \(begin\) ---\n(.*?)\n\s*# --- verify infrastructure CLI executable bits \(end\) ---/s',
        $run,
        $matches,
    ))->toBe(1, 'could not locate the executable-bit verification block in release.yml');

    $delegatingLine = trim($matches[1]);
    expect($delegatingLine)->toBe('infrastructure/scripts/verify-required-clis --release-root "${package_root}"');

    $root = releaseCliFixture(requiredCliManifestNames());

    try {
        $script = 'set -Eeuo pipefail'."\n".'cd '.escapeshellarg(base_path())."\n".'package_root='.escapeshellarg($root)."\n".$delegatingLine;

        $output = [];
        $exit = 0;
        exec('bash -c '.escapeshellarg($script).' 2>&1', $output, $exit);
        expect($exit)->toBe(0, "verification rejected a correctly-built package:\n".implode("\n", $output));
        expect(implode("\n", $output))->toContain('verified: every required infrastructure CLI retains its executable mode after release normalization');

        chmod($root.'/infrastructure/scripts/health-check', 0o640);

        $output = [];
        $exit = 0;
        exec('bash -c '.escapeshellarg($script).' 2>&1', $output, $exit);
        expect($exit)->not->toBe(0);
        expect(implode("\n", $output))->toContain('required CLI lost executable mode after extraction: health-check');
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
});
