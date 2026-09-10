<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * .github/actions/provision-rateguru-target — the reusable composite action
 * that transports trusted tooling to a prepared host and runs the server-side
 * provision-target primitive.
 *
 * It is transport and invocation. Every decision about what provisioning means
 * belongs to infrastructure/scripts/provision-target, and the tests here are
 * about the two things YAML genuinely owns: the credentials and connection
 * policy it uses, and the inputs it refuses to have at all.
 *
 * There is deliberately no operator-facing workflow in this slice, and that
 * absence is asserted rather than assumed: this ships a reusable action and a
 * server primitive, not a button that already changes a production server.
 */
function provisionActionPath(): string
{
    return base_path('.github/actions/provision-rateguru-target/action.yml');
}

function provisionActionSource(): string
{
    return File::get(provisionActionPath());
}

/** @return array<string, mixed> */
function provisionAction(): array
{
    return Yaml::parse(provisionActionSource());
}

/**
 * The same text with comment lines removed.
 *
 * This file explains its own security properties in prose — "never a
 * deployment key", "no TOFU" — so a naive substring search over the whole file
 * would fail on the very sentence that promises the property.
 */
function provisionActionExecutable(): string
{
    return executableSourceLines(provisionActionSource());
}

it('is a valid composite action with a description', function () {
    $action = provisionAction();

    expect($action['name'])->toBe('Provision RateGuru target');
    expect($action['description'])->toBeString()->not->toBeEmpty();
    expect(data_get($action, 'runs.using'))->toBe('composite');
    expect(data_get($action, 'runs.steps'))->toBeArray()->not->toBeEmpty();

    foreach (data_get($action, 'runs.steps') as $index => $step) {
        expect(array_key_exists('name', $step))->toBeTrue("step {$index} has no name");
        expect($step['shell'])->toBe('bash', "step {$index} must declare an explicit shell");
    }
});

it('accepts the connection inputs and nothing that could carry material', function () {
    expect(array_keys(provisionAction()['inputs']))->toBe([
        'deployment-target',
        'environment',
        'bootstrap-host',
        'bootstrap-port',
        'bootstrap-user',
        'bootstrap-ssh-key',
        'bootstrap-known-hosts',
    ]);
});

it('has no input that could deploy, restore, or carry a secret', function (string $forbidden) {
    // The absence IS the design. What provisioning creates is exactly the
    // infrastructure that carries no secret; everything else belongs to a
    // later operation, and nothing here can perform one because nothing here
    // accepts its inputs.
    expect(array_keys(provisionAction()['inputs']))
        ->not->toContain($forbidden, "provisioning must not be able to accept: {$forbidden}");
})->with([
    // deployment
    'ref', 'branch', 'tag', 'commit', 'source-sha', 'release', 'artifact',
    'artifact-name', 'run-migrations', 'migrate',
    // material
    'env', 'env-file', 'environment-file', 'app-key', 'application-key',
    'db-password', 'database-password', 'deploy-ssh-key', 'authorized-keys',
    'rclone-config', 'b2-key-id', 'b2-application-key', 'tls-certificate',
    'tls-key', 'basic-auth', 'htpasswd', 'mail-password', 'dns-token',
    // data
    'backup', 'backup-id', 'restore',
]);

it('uses the bootstrap credential and never falls back to a deployment key', function () {
    $executable = provisionActionExecutable();

    expect($executable)
        ->toContain('inputs.bootstrap-ssh-key')
        ->toContain('inputs.bootstrap-known-hosts')
        // The deploy key is restricted to the narrow sudo wrappers and cannot
        // create infrastructure. Widening it here would quietly change what
        // that key is for.
        ->not->toContain('DEPLOY_SSH_KEY')
        ->not->toContain('deploy-ssh-key');

    expect(provisionActionSource())
        ->toContain('refuses to fall back to a deployment key');
});

it('never relaxes host key checking, and keeps long-running connections alive', function () {
    $executable = provisionActionExecutable();

    // Every ssh/scp invocation carries the same policy. Counting them keeps a
    // future step from being added without it.
    $sshInvocations = substr_count($executable, '-o StrictHostKeyChecking=yes');

    expect($sshInvocations)->toBeGreaterThanOrEqual(6);
    expect(substr_count($executable, '-o BatchMode=yes'))->toBe($sshInvocations);
    expect(substr_count($executable, '-o IdentitiesOnly=yes'))->toBe($sshInvocations);
    expect(substr_count($executable, '-o UserKnownHostsFile='))->toBe($sshInvocations);

    // Provisioning creates accounts, converges directories and validates
    // service configuration; an idle NAT dropping a silent connection
    // mid-run would abandon it half-finished for no reason. scp is the one
    // exception: it is never idle.
    expect(substr_count($executable, '-o ServerAliveInterval=30'))
        ->toBe(substr_count($executable, '-o ServerAliveCountMax=10'));
    expect(substr_count($executable, '-o ServerAliveInterval=30'))->toBeGreaterThanOrEqual(5);

    expect($executable)
        ->not->toContain('StrictHostKeyChecking=no')
        ->not->toContain('StrictHostKeyChecking=accept-new')
        ->not->toContain('UserKnownHostsFile=/dev/null')
        ->not->toContain('ssh-keyscan')
        ->not->toContain('PasswordAuthentication=yes');
});

it('refuses a target that is not planned and production before anything is uploaded', function () {
    $action = provisionAction();
    $steps = collect(data_get($action, 'runs.steps'));

    $validation = $steps->firstWhere('name', 'Validate the target lifecycle before anything is uploaded');
    $upload = $steps->search(fn (array $step): bool => $step['name'] === 'Upload the trusted provisioning bundle');
    $validationIndex = $steps->search(fn (array $step): bool => $step['name'] === 'Validate the target lifecycle before anything is uploaded');

    expect($validation)->not->toBeNull();
    expect($validationIndex)->toBeLessThan($upload, 'the lifecycle gate must run before any upload');

    // It asks the repository's own validator rather than reimplementing a
    // lifecycle rule in YAML.
    expect($validation['run'])
        ->toContain('infrastructure/scripts/targets')
        ->toContain('show --target')
        ->toContain('lifecycle')
        ->toContain('environment_class')
        ->toContain('not planned')
        ->toContain('not production');

    // And the environment class is closed to production.
    $inputs = $steps->firstWhere('name', 'Validate fixed caller inputs');
    expect($inputs['run'])->toContain('Provisioning is only ever run for a production target');
});

it('runs check, apply and verify through the server primitive, and reads only its machine-readable line', function () {
    $executable = provisionActionExecutable();

    foreach (['--check', '--apply', '--verify'] as $mode) {
        expect($executable)->toContain($mode);
    }

    expect($executable)
        ->toContain('infrastructure/scripts/provision-target')
        ->toContain('RATEGURU_PROVISION_RESULT=')
        // The expected end state is asserted against the machine-readable
        // result, never against prose in the human report — otherwise a
        // wording change here would be a silent behaviour change.
        ->toContain('.status == "infrastructure-provisioned"')
        ->toContain('.lifecycle == "planned"')
        ->toContain('.application_state == "not-deployed"')
        ->toContain('.public_state == "not-activated"');

    // Exactly one result line per terminal success: counting the matches is
    // what catches a broken contract, where taking the first would not.
    expect(substr_count($executable, "grep -c '^RATEGURU_PROVISION_RESULT='"))->toBe(2);
});

it('never invokes any operation but provisioning on the host', function (string $forbidden) {
    expect(provisionActionExecutable())
        ->not->toContain($forbidden, "the provisioning transport must not invoke: {$forbidden}");
})->with([
    'scripts/deploy', 'scripts/rollback', 'scripts/restore-target',
    'scripts/recover-host', 'scripts/prepare-host', 'scripts/repair-target',
    'scripts/backup', 'scripts/install-target-database',
    'scripts/install-target-prerequisites',
    'rateguru-deploy', 'rateguru-rollback', 'rateguru-restore',
    'artisan', 'certbot', 'psql', 'rclone',
]);

it('removes the uploaded bundle and the local credential whatever happened', function () {
    $steps = collect(data_get(provisionAction(), 'runs.steps'));

    foreach ([
        'Remove the remote provisioning bundle',
        'Remove temporary local files',
    ] as $name) {
        $step = $steps->firstWhere('name', $name);

        expect($step)->not->toBeNull("missing cleanup step: {$name}");
        expect($step['if'])->toBe('${{ always() }}', "{$name} must run on failure too");
    }

    expect(provisionActionExecutable())
        ->toContain('RATEGURU_BOOTSTRAP_SSH_KEY_PATH')
        ->toContain('RATEGURU_BOOTSTRAP_KNOWN_HOSTS_PATH');
});

it('ships no operator-facing provisioning workflow in this slice', function () {
    // The action is reusable and the primitive is complete; a button that
    // already changes a production server is a separate, deliberate decision.
    $workflows = collect(glob(base_path('.github/workflows/*.yml')) ?: [])
        ->filter(fn (string $path): bool => str_contains(File::get($path), 'provision-rateguru-target'))
        ->values()
        ->all();

    expect($workflows)->toBe([], 'no workflow may call the provisioning action yet');

    expect(File::exists(base_path('.github/workflows/provision-production.yml')))->toBeFalse();
    expect(File::exists(base_path('.github/workflows/provision-staging.yml')))->toBeFalse();
});

it('is documented in the runbook it belongs to', function () {
    expect(File::get(base_path('infrastructure/runbooks/provision-target.md')))
        ->toContain('.github/actions/provision-rateguru-target')
        ->toContain('no operator-facing workflow yet');

    expect(File::get(base_path('infrastructure/README.md')))
        ->toContain('runbooks/provision-target.md');
});
