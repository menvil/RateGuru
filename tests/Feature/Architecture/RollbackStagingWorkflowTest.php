<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

it('rolls back staging manually, through the fixed target-aware wrapper only', function () {
    $path = base_path('.github/workflows/rollback-staging.yml');

    expect(File::exists($path))->toBeTrue();

    $source = File::get($path);
    $workflow = Yaml::parse($source);
    $steps = collect(data_get($workflow, 'jobs.rollback.steps'));
    $stepsByName = $steps->keyBy('name');

    // Manual-only: workflow_dispatch is the one and only trigger.
    expect($workflow)->toBeArray()
        ->and(data_get($workflow, 'name'))->toBe('Rollback staging')
        ->and(array_keys($workflow['on']))->toBe(['workflow_dispatch'])
        ->and(data_get($workflow, 'on.workflow_dispatch.inputs.mode.type'))->toBe('choice')
        ->and(data_get($workflow, 'on.workflow_dispatch.inputs.mode.options'))->toBe(['previous', 'release'])
        ->and(data_get($workflow, 'on.workflow_dispatch.inputs.mode.default'))->toBe('previous')
        ->and(data_get($workflow, 'on.workflow_dispatch.inputs.mode.required'))->toBeTrue()
        ->and(data_get($workflow, 'on.workflow_dispatch.inputs.release-id.type'))->toBe('string')
        ->and(data_get($workflow, 'on.workflow_dispatch.inputs.release-id.required'))->toBeFalse();

    // Minimal permissions, the staging environment boundary, one job.
    expect($workflow['permissions'])->toBe(['contents' => 'read'])
        ->and(array_keys($workflow['jobs']))->toBe(['rollback'])
        ->and(data_get($workflow, 'jobs.rollback.environment'))->toBe('staging')
        ->and(data_get($workflow, 'jobs.rollback.runs-on'))->toBe('ubuntu-latest');

    // Identical concurrency domain as the staging deploy workflow: a rollback
    // queues behind (and is never cancelled by) a staging deploy.
    $deployWorkflow = Yaml::parse(File::get(base_path('.github/workflows/deploy-staging.yml')));
    expect($workflow['concurrency'])->toBe($deployWorkflow['concurrency'])
        ->and(data_get($workflow, 'concurrency.group'))->toBe('rateguru-staging-deployment')
        ->and(data_get($workflow, 'concurrency.cancel-in-progress'))->toBeFalse();

    // Input validation fails invalid mode/release-id combinations before any
    // SSH configuration or connection step runs.
    $names = $steps->pluck('name')->all();
    expect(array_search('Validate inputs', $names, true))
        ->toBeLessThan(array_search('Configure SSH', $names, true))
        ->and(array_search('Configure SSH', $names, true))
        ->toBeLessThan(array_search('Roll back via target-aware wrapper', $names, true));

    $validate = $stepsByName->get('Validate inputs');
    expect(data_get($validate, 'env.MODE'))->toBe('${{ inputs.mode }}')
        ->and(data_get($validate, 'env.RELEASE_ID'))->toBe('${{ inputs.release-id }}')
        ->and(data_get($validate, 'env.DEPLOY_HOST'))->toBe('${{ vars.DEPLOY_HOST }}')
        ->and(data_get($validate, 'run'))
        ->toContain('case "${MODE}"')
        ->toContain('release-id must be empty when mode=previous')
        ->toContain('release-id is required when mode=release')
        ->toContain('is not configured for the staging environment')
        ->toContain('exit 1');

    // SSH material comes only from the existing staging secrets, written with
    // the exact hardening pattern the deploy action uses.
    $configureSsh = $stepsByName->get('Configure SSH');
    expect(data_get($configureSsh, 'env.SSH_PRIVATE_KEY'))->toBe('${{ secrets.DEPLOY_SSH_KEY }}')
        ->and(data_get($configureSsh, 'env.KNOWN_HOSTS'))->toBe('${{ secrets.DEPLOY_KNOWN_HOSTS }}')
        ->and(data_get($configureSsh, 'run'))
        ->toContain('install -m 0600 /dev/null')
        ->toContain('ssh-keygen -y');

    // The remote command is the fixed wrapper with the fixed target, built as
    // a Bash array and safely quoted — never eval, never a string-built
    // command line, never a concatenation of user input.
    $rollback = $stepsByName->get('Roll back via target-aware wrapper');
    expect(data_get($rollback, 'env.DEPLOY_HOST'))->toBe('${{ vars.DEPLOY_HOST }}')
        ->and(data_get($rollback, 'env.DEPLOY_PORT'))->toBe('${{ vars.DEPLOY_PORT }}')
        ->and(data_get($rollback, 'env.DEPLOY_USER'))->toBe('${{ vars.DEPLOY_USER }}')
        ->and(data_get($rollback, 'run'))
        ->toContain('sudo -n /usr/local/sbin/rateguru-rollback')
        ->toContain('--target staging-main')
        ->toContain('remote_command+=(--previous)')
        ->toContain('remote_command+=(--release "${RELEASE_ID}")')
        ->toContain('"${remote_command[@]@Q}"')
        ->toContain('-o BatchMode=yes')
        ->toContain('-o IdentitiesOnly=yes')
        ->toContain('-o ConnectTimeout=')
        ->toContain('-o StrictHostKeyChecking=yes')
        ->toContain('-o UserKnownHostsFile=');

    // Every run script takes workflow inputs through env, never through
    // direct `${{ }}` interpolation into the script body.
    foreach ($steps as $step) {
        expect(data_get($step, 'run'))->not->toContain('${{');
    }

    // The step summary reports what happened — on failed runs too — with
    // the result derived from the real job status, and without echoing any
    // secret.
    $summary = $stepsByName->get('Write summary');
    expect(data_get($summary, 'if'))->toBe('always()')
        ->and(data_get($summary, 'env.JOB_STATUS'))->toBe('${{ job.status }}')
        ->and(data_get($summary, 'run'))
        ->toContain('GITHUB_STEP_SUMMARY')
        ->toContain('Target: staging-main')
        ->toContain('Result: ${JOB_STATUS}');

    // No forbidden constructs anywhere in the workflow: no eval, no bash -c,
    // no disabled host-key checking, no root SSH, no legacy --environment
    // selector, no production target.
    expect($source)
        ->not->toMatch('/\beval\b/')
        ->not->toContain('bash -c')
        ->not->toContain('StrictHostKeyChecking=no')
        ->not->toContain('StrictHostKeyChecking=accept-new')
        ->not->toContain('root@')
        ->not->toContain('--environment')
        ->not->toContain('tits-guru');

    // Only the existing staging deployment vars/secrets are referenced —
    // this workflow introduces no new repository variable or secret.
    preg_match_all('/\$\{\{\s*vars\.([A-Z_]+)\s*\}\}/', $source, $varMatches);
    preg_match_all('/\$\{\{\s*secrets\.([A-Z_]+)\s*\}\}/', $source, $secretMatches);
    expect(array_values(array_unique($varMatches[1])))
        ->toEqualCanonicalizing(['DEPLOY_HOST', 'DEPLOY_PORT', 'DEPLOY_USER'])
        ->and(array_values(array_unique($secretMatches[1])))
        ->toEqualCanonicalizing(['DEPLOY_SSH_KEY', 'DEPLOY_KNOWN_HOSTS']);
});
