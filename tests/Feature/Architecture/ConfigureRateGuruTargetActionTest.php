<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * .github/actions/configure-rateguru-target — transport and invocation for the
 * server-side configure-target primitive.
 *
 * The property worth asserting hardest is an absence. This action carries NO
 * material: not an environment file, not authorized_keys, not a database
 * password. The target's shared/.env is canonical on the host — an operator
 * creates it there once, backups carry it as environment.env, and a recovery
 * restores it from the selected backup. GitHub is not a copy of it and is
 * never asked to resend it.
 */
function configureActionPath(): string
{
    return base_path('.github/actions/configure-rateguru-target/action.yml');
}

/** @return array<string, mixed> */
function configureAction(): array
{
    return Yaml::parseFile(configureActionPath());
}

function configureActionExecutable(): string
{
    return executableSourceLines(File::get(configureActionPath()));
}

it('is a valid composite action whose every step names itself and its shell', function () {
    $action = configureAction();

    expect($action['name'])->toBe('Configure RateGuru target');
    expect(data_get($action, 'runs.using'))->toBe('composite');

    foreach (data_get($action, 'runs.steps') as $index => $step) {
        expect(array_key_exists('name', $step))->toBeTrue("step {$index} has no name");
        expect($step['shell'])->toBe('bash', "step {$index} must declare an explicit shell");
    }
});

it('accepts the connection inputs, the deploy credential, and nothing else', function () {
    // deploy-ssh-key is the one input that is not a connection parameter, and
    // it is deliberately not material either: it is never sent anywhere. The
    // runner derives its PUBLIC half and uploads only that, because a
    // provisioned target has no authorized_keys and nothing else installs one.
    expect(array_keys(configureAction()['inputs']))->toBe([
        'deployment-target',
        'environment',
        'bootstrap-host',
        'bootstrap-port',
        'bootstrap-user',
        'bootstrap-ssh-key',
        'bootstrap-known-hosts',
        'deploy-ssh-key',
    ]);
});

it('has no input that could carry the material it causes to be used', function (string $forbidden) {
    // The absence IS the model. An environment file that arrives from GitHub
    // is a second copy of something the host already holds canonically, and
    // two copies of a credential is how they diverge.
    expect(in_array($forbidden, array_keys(configureAction()['inputs']), true))
        ->toBeFalse("configuring must not be able to accept: {$forbidden}");
})->with([
    'laravel-env', 'env', 'env-file', 'environment-file', 'app-key', 'application-key',
    // Not deploy-ssh-key: that one is accepted, used on the runner only, and
    // covered by its own tests below. deploy-authorized-keys stays forbidden —
    // the public key is DERIVED from the credential above, never pasted
    // separately, so that two sources of the same identity cannot diverge.
    'deploy-authorized-keys', 'authorized-keys',
    'db-password', 'database-password', 'database-url',
    'rclone-config', 'tls-certificate', 'tls-key', 'basic-auth', 'mail-password',
    'ref', 'branch', 'tag', 'commit', 'artifact', 'release', 'run-migrations',
]);

it('says in its own prose where the environment file actually lives', function () {
    // Written down because the next person to touch this will be tempted to
    // "fix" the missing input.
    expect(File::get(configureActionPath()))
        ->toContain('CANONICAL on the host')
        ->toContain('never asked to resend it');
});

it('connects with the bootstrap credential and never with the deployment key', function () {
    // The deploy key is now an input, so "it is absent" is no longer the
    // property. The property is what it is USED for: every ssh and scp
    // identity is the bootstrap key, because creating a role and a database
    // needs root and the deploy key reaches only the narrow sudo wrappers.
    $executable = configureActionExecutable();

    expect($executable)
        ->toContain('inputs.bootstrap-ssh-key')
        ->toContain('inputs.bootstrap-known-hosts');

    // Every -i names the bootstrap key path, and nothing else does.
    $identities = preg_match_all('/-i\s+"\$\{([A-Z_]+)\}"/', $executable, $matches);

    expect($identities)->toBeGreaterThanOrEqual(4);
    expect(array_unique($matches[1]))->toBe(['RATEGURU_BOOTSTRAP_SSH_KEY_PATH']);
});

it('uses the deployment key only to derive a public key, and never uploads it', function () {
    $executable = configureActionExecutable();

    // Read once, by ssh-keygen -y, into the one material file.
    expect($executable)
        ->toContain('ssh-keygen -y -f "${private_path}" > "${material_dir}/deploy-authorized-keys"')
        ->toContain('rm -f "${private_path}"');

    // The private key never appears in anything sent to the host: the only scp
    // of material names the public file explicitly, rather than globbing a
    // directory that could come to hold more than one thing.
    expect($executable)
        ->toContain('"${RATEGURU_MATERIAL_DIR}/deploy-authorized-keys" \\')
        ->not->toContain('"${RATEGURU_MATERIAL_DIR}"/*');

    // And it is removed from the runner on every exit path, successful or not.
    expect($executable)->toContain('rateguru_configure_deploy_key');
});

it('passes the staged material to the server-side operation', function () {
    // Staging a file nothing is told about would install nothing at all.
    expect(configureActionExecutable())
        ->toContain('--material-dir "${RATEGURU_REMOTE_ROOT}/material"');
});

it('never relaxes host key checking, and carries the same policy on every connection', function () {
    $executable = configureActionExecutable();

    $connections = substr_count($executable, '-o StrictHostKeyChecking=yes');

    expect($connections)->toBeGreaterThanOrEqual(4);

    foreach ([
        '-o BatchMode=yes',
        '-o IdentitiesOnly=yes',
        '-o UserKnownHostsFile=',
        '-o ConnectTimeout=15',
        '-o ServerAliveInterval=30',
        '-o ServerAliveCountMax=10',
    ] as $option) {
        expect(substr_count($executable, $option))->toBe($connections, "every connection must carry {$option}");
    }

    expect($executable)
        ->not->toContain('StrictHostKeyChecking=no')
        ->not->toContain('StrictHostKeyChecking=accept-new')
        ->not->toContain('UserKnownHostsFile=/dev/null')
        ->not->toContain('ssh-keyscan')
        ->not->toContain('PasswordAuthentication=yes');
});

it('prints the whole remote transcript when it fails, and reads nothing out of it', function () {
    // The same failure mode a real provisioning run hit: under `set -e` a
    // failing command substitution ends the step before the captured output is
    // printed, and the server-side report is the whole diagnosis.
    $steps = collect(data_get(configureAction(), 'runs.steps'));

    foreach ([
        'Configure the target' => 'configure',
        'Verify the configured target' => 'verification',
    ] as $name => $prefix) {
        $executable = executableSourceLines($steps->firstWhere('name', $name)['run']);

        $disable = mb_strpos($executable, 'set +e');
        $capture = mb_strpos($executable, "{$prefix}_status=\$?");
        $restore = mb_strpos($executable, 'set -e');
        $print = mb_strpos($executable, "printf '%s\\n' \"\${{$prefix}_output}\"");
        $judge = mb_strpos($executable, "if (( {$prefix}_status != 0 )); then");

        foreach (['set +e' => $disable, 'the status capture' => $capture, 'set -e' => $restore, 'the transcript print' => $print, 'the judgement' => $judge] as $what => $position) {
            expect($position)->not->toBeFalse("{$name} is missing {$what}");
        }

        expect($disable)->toBeLessThan($capture, "{$name} must disable -e before the command whose status it captures");
        expect($capture)->toBeLessThan($restore, "{$name} must capture the status before restoring -e");
        expect($restore)->toBeLessThan($print, "{$name} must restore -e before the rest of the step runs");
        expect($print)->toBeLessThan($judge, "{$name} must print the transcript before deciding the step failed");

        expect($executable)->toContain('"${remote_command[@]@Q}" 2>&1');
    }
});

it('demands exactly one machine-readable result, and every claim in it', function () {
    $executable = configureActionExecutable();

    expect(substr_count($executable, "grep -c '^RATEGURU_CONFIGURE_RESULT='"))->toBe(1);

    expect($executable)
        ->toContain('Expected exactly one RATEGURU_CONFIGURE_RESULT line')
        ->toContain('.status == "target-configured"')
        ->toContain('.lifecycle == "planned"')
        ->toContain('.environment_class == "production"')
        ->toContain('.deploy_authorization == "not-granted"')
        ->toContain('.application_state == "not-deployed"')
        ->toContain('.public_state == "not-activated"');
});

it('runs no operation on the host but configuring', function (string $forbidden) {
    expect(str_contains(configureActionExecutable(), $forbidden))
        ->toBeFalse("the configuration transport must not invoke: {$forbidden}");
})->with([
    'scripts/deploy', 'scripts/rollback', 'scripts/restore-target', 'scripts/recover-host',
    'scripts/prepare-host', 'scripts/repair-target', 'scripts/backup',
    'scripts/install-target-perimeter', 'scripts/bootstrap-host',
    'rateguru-deploy', 'rateguru-rollback', 'rateguru-restore',
    'artisan', 'certbot', 'psql', 'rclone',
]);

it('removes the uploaded bundle and the local credential whatever happened', function () {
    $steps = collect(data_get(configureAction(), 'runs.steps'));

    $cleanup = ['Remove the remote configuration bundle', 'Remove temporary local files'];

    foreach ($cleanup as $name) {
        $step = $steps->firstWhere('name', $name);

        expect($step)->not->toBeNull("missing cleanup step: {$name}");
        expect($step['if'])->toBe('${{ always() }}', "{$name} must run on failure too");
    }

    // always() runs a step after a FAILURE, not after everything: a step
    // appended below these would be skipped on failure and would then be the
    // one thing left behind on the host the run just failed against.
    expect($steps->slice(-2)->pluck('name')->all())->toBe($cleanup);
});

it('is called by exactly one operator-facing workflow, for exactly one target', function () {
    $callers = collect(glob(base_path('.github/workflows/*.{yml,yaml}'), GLOB_BRACE) ?: [])
        ->filter(fn (string $path): bool => str_contains(File::get($path), 'configure-rateguru-target'))
        ->map(fn (string $path): string => basename($path))
        ->values()
        ->all();

    expect($callers)->toBe(['configure-tits-guru.yml']);

    $workflow = Yaml::parseFile(base_path('.github/workflows/configure-tits-guru.yml'));

    // Manual, no inputs at all: no target dropdown, no ref, no mode, no
    // migration flag. There is nothing to choose.
    expect(array_keys($workflow['on']))->toBe(['workflow_dispatch']);
    expect($workflow['on']['workflow_dispatch'] ?? null)->toBeNull();

    $step = collect($workflow['jobs']['configure']['steps'])
        ->firstWhere('uses', './.github/actions/configure-rateguru-target');

    expect($step['with']['deployment-target'])->toBe('tits-guru');

    // Trusted tooling always comes from develop.
    $checkout = collect($workflow['jobs']['configure']['steps'])
        ->first(fn (array $s): bool => str_starts_with($s['uses'] ?? '', 'actions/checkout@'));

    expect($checkout['with']['ref'])->toBe('develop');
});

it('tells the operator what is still not true', function () {
    // A green run must not read as "production is ready".
    $source = File::get(base_path('.github/workflows/configure-tits-guru.yml'));

    foreach ([
        'NOT enabled',
        'NOT deployed',
        'NOT activated',
        'The next step is **not** \"deploy\"',
    ] as $claim) {
        expect($source)->toContain($claim);
    }
});
