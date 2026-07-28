<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

it('deploys manually selected refs to staging', function () {
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
        ->and(data_get($workflow, 'on.workflow_run'))->toBeNull()
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

    expect(data_get($resolveSteps->get('Resolve exact source revision'), 'env.DISPATCH_REF'))
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
        ->toBe('${{ needs.resolve.outputs.run_migrations }}')
        ->and($deploySteps->has('Verify social preview as external crawler'))
        ->toBeFalse();

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
        ->not->toContain('${{')
        // The executable-bit check must run after rsync stages package_root
        // and before tar freezes it into the archive — too late once the
        // release.json write also happens, but this only needs "after rsync,
        // before tar", so asserting it appears before `tar \` is sufficient.
        ->toContain('infrastructure/scripts/verify-required-clis')
        ->toContain('# --- verify infrastructure CLI executable bits (begin) ---');

    $releaseRun = data_get($buildSteps->get('Build release archive'), 'run');
    expect(mb_strpos($releaseRun, 'rsync \\'))
        ->toBeLessThan(mb_strpos($releaseRun, '# --- verify infrastructure CLI executable bits (begin) ---'))
        ->and(mb_strpos($releaseRun, '# --- verify infrastructure CLI executable bits (end) ---'))
        ->toBeLessThan(mb_strpos($releaseRun, 'tar \\'));

    expect($source)
        ->not->toMatch('/^  workflow_run:/m')
        ->toContain('--classmap-authoritative')
        ->toContain("--exclude='.env.*'")
        ->toContain("--exclude='database/database.sqlite'")
        ->toContain('normalized_source_ref="${SOURCE_REF#refs/tags/}"')
        ->toContain('release_version="${BASH_REMATCH[1]}"')
        ->toContain('release_id="${release_version}-${timestamp}-${short_sha}"')
        ->toContain('--arg source_sha "${source_sha}"')
        ->toContain('sha256sum "${artifact_name}"')
        // Staging artifacts are consumed by the deploy job in the same run;
        // they are kept only briefly for manual re-download afterwards.
        ->toContain('retention-days: 3')
        ->toContain('release-id: ${{ needs.build.outputs.release-id }}');
});

it('delegates the staging artifact build\'s CLI executable-bit check to the shared verify-required-clis, and fails closed when a CLI is not executable', function () {
    // Proves the workflow step is exactly a delegating call to the real,
    // shared infrastructure/scripts/verify-required-clis — the same
    // algorithm deploy itself uses — never a reimplementation, then runs
    // that exact extracted line end to end against a scratch package_root.
    $path = base_path('.github/workflows/deploy-staging.yml');
    $workflow = Yaml::parse(File::get($path));
    $run = data_get(collect(data_get($workflow, 'jobs.build.steps'))->keyBy('name')->get('Build release archive'), 'run');

    expect(preg_match(
        '/# --- verify infrastructure CLI executable bits \(begin\) ---\n(.*?)\n\s*# --- verify infrastructure CLI executable bits \(end\) ---/s',
        $run,
        $matches,
    ))->toBe(1, 'could not locate the executable-bit verification block in deploy-staging.yml');

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

        // Now regress exactly one file, matching the real incident.
        chmod($root.'/infrastructure/scripts/targets', 0o640);

        $output = [];
        $exit = 0;
        exec('bash -c '.escapeshellarg($script).' 2>&1', $output, $exit);
        expect($exit)->not->toBe(0);
        expect(implode("\n", $output))->toContain('required CLI lost executable mode after extraction: targets');
    } finally {
        exec('rm -rf '.escapeshellarg($root));
    }
});
