<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * .github/actions/configure-rateguru-target — transport and invocation for the
 * server-side configure-target primitive.
 *
 * Exactly one thing reaches the host: the target's deploy PUBLIC key, derived
 * here from the deployment credential GitHub already holds. Provisioning
 * deliberately left the deploy user without an authorized_keys and nothing else
 * installs one, so without this a configured target stays unreachable.
 *
 * The property worth asserting hardest is still an absence, and it is now two:
 * the deployment PRIVATE key never leaves the runner, and the environment file
 * is never sent at all. The target's shared/.env is canonical on the host — an
 * operator creates it there once, backups carry it as environment.env, and a
 * recovery restores it from the selected backup. GitHub is not a copy of it and
 * is never asked to resend it.
 */
function configureActionStepScript(string $name): string
{
    foreach (data_get(configureAction(), 'runs.steps') as $step) {
        if (($step['name'] ?? '') === $name) {
            return $step['run'];
        }
    }

    throw new RuntimeException("no step named {$name}");
}
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
        // Optional, and the one input that may carry a secret to the host: the
        // target's DKIM private key. Covered by its own tests below.
        'mail-dkim-private-key',
    ]);

    expect(configureAction()['inputs']['mail-dkim-private-key']['required'])->toBeFalse();
    expect(configureAction()['inputs']['mail-dkim-private-key']['default'])->toBe('');
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

    // Deliberately the LITERAL 'main', not trustedToolingRef(): this is an
    // independent witness. Every assertion in ProductionControlPlaneTest derives
    // its expectation from that one classification map, so a map entry flipped to
    // 'develop' alongside the YAML would take all of them with it. This guard
    // does not move.
    $checkout = collect($workflow['jobs']['configure']['steps'])
        ->first(fn (array $s): bool => str_starts_with($s['uses'] ?? '', 'actions/checkout@'));

    expect($checkout['with']['ref'])->toBe('main');
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

// =============================================================================
// The key derivation, executed rather than read
// =============================================================================
//
// Every other assertion here matches the action's source text, which cannot
// catch a step that is written correctly and behaves wrongly. This one runs the
// real staging step against a real generated key.

/** @return array{0: int, 1: string, 2: string} */
function configureRunStagingStep(string $scratch, string $keyMaterial): array
{
    $runnerTemp = $scratch.'/runner';
    $githubEnv = $scratch.'/github-env';

    @mkdir($runnerTemp, 0o700, true);
    touch($githubEnv);

    $script = $scratch.'/stage.sh';
    file_put_contents($script, configureActionStepScript('Stage the deploy public key'));

    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(['bash', $script], $descriptors, $pipes, null, [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
        'RUNNER_TEMP' => $runnerTemp,
        'GITHUB_ENV' => $githubEnv,
        'DEPLOY_SSH_KEY' => $keyMaterial,
    ]);

    expect($process)->not->toBeFalse();

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output, $runnerTemp];
}

it('derives the real public half of the deployment key and destroys the private one', function () {
    $scratch = makeScratchDir('configure-action', [''], 0o700);

    try {
        // A real key, so the derivation is checked against ssh-keygen's own
        // answer rather than against a shape this test invented.
        exec('ssh-keygen -t ed25519 -N "" -C deploy@test -f '.escapeshellarg($scratch.'/k').' 2>&1', $ignored, $generated);
        expect($generated)->toBe(0, 'could not generate a test key');

        [$exit, $output, $runnerTemp] = configureRunStagingStep($scratch, (string) file_get_contents($scratch.'/k'));

        expect($exit)->toBe(0, $output);

        $staged = $runnerTemp.'/rateguru-configure-material/deploy-authorized-keys';

        // Byte-for-byte the key ssh-keygen itself wrote, on type and key body.
        $derived = preg_split('/\s+/', trim((string) file_get_contents($staged)));
        $real = preg_split('/\s+/', trim((string) file_get_contents($scratch.'/k.pub')));

        expect([$derived[0], $derived[1]])->toBe([$real[0], $real[1]]);

        // Nothing else was staged, and the private key is gone.
        expect(scandir($runnerTemp.'/rateguru-configure-material'))->toBe(['.', '..', 'deploy-authorized-keys']);
        expect(file_exists($runnerTemp.'/rateguru_configure_deploy_key'))->toBeFalse();
        expect(substr(sprintf('%o', fileperms($staged)), -3))->toBe('600');
    } finally {
        removeScratchDir($scratch);
    }
});

it('fails on the runner, before any upload, when the deployment key is unusable', function () {
    // ssh-keygen -y authenticates the credential as a key. A malformed or
    // passphrase-protected one must stop here, where nothing has been uploaded
    // and no remote command has run.
    $scratch = makeScratchDir('configure-action', [''], 0o700);

    try {
        [$exit, $output, $runnerTemp] = configureRunStagingStep($scratch, 'this is not an SSH private key');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('Nothing was uploaded and nothing was changed');

        // The staging directory is torn down, so a later step cannot find a
        // half-written file and upload it.
        expect(is_dir($runnerTemp.'/rateguru-configure-material'))->toBeFalse();
        expect(file_exists($runnerTemp.'/rateguru_configure_deploy_key'))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// The optional DKIM private key, executed rather than read
// =============================================================================

/** @return array{0: int, 1: string, 2: string, 3: string} exit, output, material dir, GITHUB_ENV contents */
function configureRunDkimStep(string $scratch, string $keyMaterial): array
{
    $material = $scratch.'/runner/rateguru-configure-material';
    @mkdir($material, 0o700, true);
    file_put_contents($material.'/deploy-authorized-keys', "ssh-ed25519 AAAA deploy\n");

    $githubEnv = $scratch.'/github-env';
    touch($githubEnv);

    $script = $scratch.'/stage-dkim.sh';
    file_put_contents($script, configureActionStepScript('Stage the DKIM private key'));

    $process = proc_open(['bash', $script], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
        'GITHUB_WORKSPACE' => base_path(),
        'GITHUB_ENV' => $githubEnv,
        'RATEGURU_MATERIAL_DIR' => $material,
        'MAIL_DKIM_PRIVATE_KEY' => $keyMaterial,
        'DEPLOYMENT_TARGET' => 'tits-guru',
    ]);

    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    return [proc_close($process), $output, $material, (string) file_get_contents($githubEnv)];
}

it('stages nothing when no DKIM key was supplied, and Configure is exactly what it was', function () {
    $scratch = makeScratchDir('configure-dkim', [''], 0o700);

    try {
        [$exit, $output, $material, $env] = configureRunDkimStep($scratch, '');

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('No DKIM private key was supplied: nothing is staged');
        expect(scandir($material))->toBe(['.', '..', 'deploy-authorized-keys']);
        expect($env)->not->toContain('RATEGURU_DKIM_KEY_STAGED');
    } finally {
        removeScratchDir($scratch);
    }
});

it('stages a usable DKIM key as exactly mail-dkim-private-key, root-only, and never prints it', function () {
    $scratch = makeScratchDir('configure-dkim', [''], 0o700);

    try {
        $key = (string) file_get_contents(mailIdentityKey('rsa2048'));

        [$exit, $output, $material, $env] = configureRunDkimStep($scratch, $key);

        expect($exit)->toBe(0, $output);
        expect(scandir($material))->toBe(['.', '..', 'deploy-authorized-keys', 'mail-dkim-private-key']);
        expect(file_get_contents($material.'/mail-dkim-private-key'))->toBe($key);
        expect(substr(sprintf('%o', fileperms($material.'/mail-dkim-private-key')), -3))->toBe('600');
        expect($env)->toBe("RATEGURU_DKIM_KEY_STAGED=true\n");

        expectNoKeyMaterial($output.$env, mailIdentityKey('rsa2048'));

        // The same key with its trailing newline lost — as a secret store may
        // keep it — stages to the identical bytes, so a re-run converges on the
        // host rather than conflicting with what it installed.
        removeScratchDir($scratch.'/runner');
        removeScratchDir($scratch.'/github-env');
        [$again, $againOutput, $againMaterial] = configureRunDkimStep($scratch, rtrim($key, "\n"));
        expect($again)->toBe(0, $againOutput);
        expect(file_get_contents($againMaterial.'/mail-dkim-private-key'))->toBe($key);
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses an unusable DKIM key on the runner, before any upload, and deletes it', function (string $kind, string $reason) {
    $scratch = makeScratchDir('configure-dkim', [''], 0o700);

    try {
        [$exit, $output, $material, $env] = configureRunDkimStep($scratch, (string) file_get_contents(mailIdentityKey($kind)));

        expect($exit)->not->toBe(0);
        expect($output)
            ->toContain($reason)
            ->toContain('Nothing was uploaded and nothing was changed');
        expect(scandir($material))->toBe(['.', '..', 'deploy-authorized-keys']);
        expect($env)->not->toContain('RATEGURU_DKIM_KEY_STAGED');

        expectNoKeyMaterial($output, mailIdentityKey($kind));
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    'a passphrase-protected key' => ['encrypted', 'it is passphrase-protected'],
    'RSA below 2048 bits' => ['rsa1024', 'below the reviewed minimum of 2048 bits'],
    'a public key' => ['public', 'it is a public key, not a private key'],
    'not a key' => ['junk', 'it is not PEM'],
]);

it('uploads the DKIM key only when one was staged, by its exact name, and removes every copy', function () {
    $upload = configureActionStepScript('Upload the trusted configuration bundle');

    expect($upload)
        ->toContain('if [[ "${RATEGURU_DKIM_KEY_STAGED:-false}" == true ]]; then')
        ->toContain('"${RATEGURU_MATERIAL_DIR}/mail-dkim-private-key" \\')
        ->toContain(':${staging_dir}/material/mail-dkim-private-key"')
        ->not->toContain('"${RATEGURU_MATERIAL_DIR}"/*')
        ->not->toContain('${RATEGURU_MATERIAL_DIR}/*');

    // The material lands root-only before anything reads it, and the staging
    // copy in the bootstrap user's home is removed in the same command.
    expect($upload)
        ->toContain('chmod -R go-rwx %q/material')
        ->toContain('rm -rf %q');

    // Whatever happens: the runner's material directory, and the remote root
    // and staging directory, are removed.
    expect(configureActionStepScript('Remove temporary local files'))->toContain('rm -rf "${RATEGURU_MATERIAL_DIR:-}"');
    expect(configureActionStepScript('Remove the remote configuration bundle'))
        ->toContain('rm -rf %q && rm -rf %q');

    // And the key is never an output of the action.
    expect(array_keys(configureAction()['outputs']))->toBe(['configured', 'lifecycle']);
    expect(configureActionExecutable())
        ->not->toContain('echo "${MAIL_DKIM_PRIVATE_KEY}')
        ->not->toContain('GITHUB_OUTPUT}" <<<"${MAIL_DKIM_PRIVATE_KEY}');
});

it('passes the optional MAIL_DKIM_PRIVATE_KEY secret through, and nowhere else', function () {
    $workflow = Yaml::parseFile(base_path('.github/workflows/configure-tits-guru.yml'));

    $step = collect($workflow['jobs']['configure']['steps'])
        ->firstWhere('uses', './.github/actions/configure-rateguru-target');

    expect($step['with']['mail-dkim-private-key'])->toBe('${{ secrets.MAIL_DKIM_PRIVATE_KEY }}');

    // Its only other mention is whether it is set — a boolean for the summary.
    $source = File::get(base_path('.github/workflows/configure-tits-guru.yml'));
    preg_match_all('/secrets\.MAIL_DKIM_PRIVATE_KEY[^}]*/', $source, $uses);
    expect($uses[0])->toBe(['secrets.MAIL_DKIM_PRIVATE_KEY ', "secrets.MAIL_DKIM_PRIVATE_KEY != '' "]);

    expect(array_keys($workflow['jobs']['configure']['outputs'] ?? []))->toBe([]);

    // The deploy private key is still only ever used on the runner.
    expect(configureActionExecutable())->toContain('ssh-keygen -y -f "${private_path}" > "${material_dir}/deploy-authorized-keys"');
});

// =============================================================================
// The public DNS publication plan
// =============================================================================

/**
 * Runs the DNS-plan step with ssh stubbed: the host answers $plan with
 * $planStatus. Whether the target has an identity is the real mail-identity's
 * answer from this repository, unless $identityAnswer stands in for it.
 *
 * @return array{0: int, 1: string, 2: string, 3: string} exit, output, step summary, ssh arguments
 */
function configureRunDnsPlanStep(string $plan, int $planStatus = 0, string $target = 'tits-guru', ?string $identityAnswer = null, int $identityStatus = 0, ?string $workspace = null): array
{
    $scratch = makeScratchDir('configure-dns-plan', ['/bin', '/workspace/infrastructure/scripts'], 0o700);

    file_put_contents($scratch.'/remote-output', $plan);
    file_put_contents($scratch.'/bin/ssh', "#!/bin/bash\nprintf '%s ' \"\$@\" >> \"\${STUB_SSH_ARGS}\"\ncat \"\${STUB_REMOTE_OUTPUT}\"\nexit {$planStatus}\n");
    chmod($scratch.'/bin/ssh', 0o755);

    if ($identityAnswer !== null) {
        file_put_contents($scratch.'/workspace/infrastructure/scripts/mail-identity', "#!/bin/bash\nprintf '%s' ".escapeshellarg($identityAnswer)."\nexit {$identityStatus}\n");
        chmod($scratch.'/workspace/infrastructure/scripts/mail-identity', 0o755);
    }

    touch($scratch.'/summary');
    file_put_contents($scratch.'/step.sh', configureActionStepScript('Report the public DNS publication plan'));

    try {
        $process = proc_open(['bash', $scratch.'/step.sh'], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, null, [
            'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
            'HOME' => $scratch,
            'GITHUB_WORKSPACE' => $identityAnswer === null ? ($workspace ?? base_path()) : $scratch.'/workspace',
            'STUB_REMOTE_OUTPUT' => $scratch.'/remote-output',
            'STUB_SSH_ARGS' => $scratch.'/ssh-args',
            'GITHUB_STEP_SUMMARY' => $scratch.'/summary',
            'RATEGURU_PRIVILEGED_PREFIX' => 'sudo -n',
            'RATEGURU_REMOTE_ROOT' => '/root/rateguru-configure-1-1',
            'RATEGURU_BOOTSTRAP_SSH_KEY_PATH' => $scratch.'/key',
            'RATEGURU_BOOTSTRAP_KNOWN_HOSTS_PATH' => $scratch.'/known',
            'BOOTSTRAP_HOST' => 'host.example',
            'BOOTSTRAP_PORT' => '22',
            'BOOTSTRAP_USER' => 'ops',
            'DEPLOYMENT_TARGET' => $target,
        ]);

        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $status = proc_close($process);

        return [$status, $output, (string) file_get_contents($scratch.'/summary'), (string) @file_get_contents($scratch.'/ssh-args')];
    } finally {
        removeScratchDir($scratch);
    }
}

/**
 * The plan the real mail-identity prints for tits-guru on a host with (or
 * without) its installed key, and with (or without) a route to the Internet.
 *
 * @return array{0: string, 1: ?string} the JSON plan, the installed key
 */
function configureRealDnsPlan(bool $withKey, bool $withAddress = true, ?array $policy = null): array
{
    $scratch = mailIdentityScratch();

    try {
        $key = $withKey ? mailIdentityInstallKey($scratch, 'tits-guru', 'rg1') : null;
        $files = $policy === null ? [] : mailIdentityFixtureConfig($scratch.'/config', ['demo-shop' => false, 'policy' => $policy]);
        $run = mailIdentityRun(['show-dns', '--target', 'tits-guru', '--json', ...$files], mailIdentityDnsHost($scratch, [], $withAddress ? '203.0.113.10' : null));

        expect($run['status'])->toBe(0, $run['stderr']);

        return [$run['stdout'], $key === null ? null : (string) file_get_contents($key)];
    } finally {
        removeScratchDir($scratch);
    }
}

/** A refusal of the step: it failed, said why, and said not to publish. */
function expectDnsPlanRefused(array $run, string $reason): void
{
    [$exit, $output, $summary] = $run;

    expect($exit)->toBe(1, $output)
        ->and($output)
        ->toContain($reason)
        ->toContain('the target itself was configured, but the reviewed public mail DNS plan could not be established; do not publish DNS from this run.')
        ->not->toContain('Publish these records')
        ->and($summary)
        ->toContain('do not publish DNS from this run')
        ->not->toContain('| TXT |');
}

it('reports the DNS plan from the same uploaded bundle, after the target verified and before that bundle is removed', function () {
    $names = collect(data_get(configureAction(), 'runs.steps'))->pluck('name')->values()->all();

    $verify = array_search('Verify the configured target', $names, true);
    $plan = array_search('Report the public DNS publication plan', $names, true);
    $cleanup = array_search('Remove the remote configuration bundle', $names, true);

    expect($plan)->toBeGreaterThan($verify);
    expect($plan)->toBeLessThan($cleanup);

    $step = collect(data_get(configureAction(), 'runs.steps'))->firstWhere('name', 'Report the public DNS publication plan');
    expect($step)->not->toHaveKey('if', 'the plan is only reported once the target verified');

    expect(configureActionStepScript('Report the public DNS publication plan'))
        ->toContain('"${GITHUB_WORKSPACE}/infrastructure/scripts/mail-identity"')
        ->toContain('dkim-key --target "${DEPLOYMENT_TARGET}"')
        ->toContain('"${RATEGURU_REMOTE_ROOT}/infrastructure/scripts/mail-identity"')
        ->toContain('show-dns')
        ->toContain('--json')
        ->not->toContain('verify-dns')
        ->not->toContain('readiness');
});

it('takes every target and DNS value from mail-identity, and changes nothing on the host', function () {
    $script = configureActionStepScript('Report the public DNS publication plan');

    // Which target has an identity is the contract's answer, never this step's;
    // and no reviewed DNS value is restated here.
    foreach (['tits-guru', 'tits.guru', 'mta1', '_domainkey', '_dmarc', 'v=spf1', 'DKIM1', 'DMARC1', 'rg1'] as $value) {
        expect($script)->not->toContain($value);
    }

    // Read-only: an installed key is never removed or replaced, so running
    // Configure again with the same key changes nothing.
    foreach (['rm ', 'install ', 'mv ', 'mail-dkim-private-key', 'install-target-prerequisites'] as $mutation) {
        expect($script)->not->toContain($mutation);
    }
});

it('reports no plan, and Configure succeeds, for a target with no reviewed mail identity', function () {
    // staging-main is in the registry and has no identity in mail-identity.json.
    [$exit, $output, $summary, $sshArgs] = configureRunDnsPlanStep('{}', 0, 'staging-main');

    expect($exit)->toBe(0, $output)
        ->and($output)->toContain('No DNS publication plan: staging-main has no reviewed mail identity.')
        ->and($summary)->toContain('No plan: `staging-main` has no reviewed mail identity.')->not->toContain('do not publish')
        ->and($sshArgs)->toBe('', 'nothing is asked of the host for a target without an identity');
});

it('defers the plan, and Configure succeeds, while the target is held and its key is not installed', function () {
    // The pre-activation repository, where tits-guru's mail is still held.
    $scratch = makeScratchDir('configure-held-plan');

    try {
        $workspace = mailPreActivationCheckout($scratch);
        [$plan] = configureRealDnsPlan(false, policy: mailPreActivationPolicy());

        expect(json_decode($plan, true)['dkim']['key_status'])->toBe('absent');

        [$exit, $output, $summary] = configureRunDnsPlanStep($plan, workspace: $workspace);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('DNS publication plan DEFERRED — DKIM private key not installed.');
        expect($summary)->toContain('DNS publication plan DEFERRED — DKIM private key not installed.')->not->toContain('| TXT |');
    } finally {
        removeScratchDir($scratch);
    }
});

it('publishes the complete public plan of an installed key into the summary, and nothing private', function () {
    [$plan, $key] = configureRealDnsPlan(true);

    // Twice with the same key: the same plan, the same summary.
    $first = configureRunDnsPlanStep($plan);
    [$exit, $output, $summary, $sshArgs] = configureRunDnsPlanStep($plan);

    expect($exit)->toBe(0, $output)
        ->and([$exit, $output, $summary])->toBe(array_slice($first, 0, 3));
    expect($sshArgs)->toContain('mail-identity')->toContain('show-dns')->toContain('--target')->toContain('tits-guru')->toContain('--json');

    $public = collect(json_decode($plan, true)['records'])->firstWhere('purpose', 'DKIM')['value'];
    expect($public)->toStartWith('v=DKIM1; k=rsa; p=');

    expect($summary)
        ->toContain('## Mail DNS publication plan')
        ->toContain('| A | `mta1.tits.guru` | `203.0.113.10` |')
        ->toContain('| PTR | `203.0.113.10` | `mta1.tits.guru` |')
        ->toContain('| TXT | `rg1._domainkey.tits.guru` | `'.$public.'` |')
        ->toContain('| TXT | `tits.guru` | `v=spf1 ip4:203.0.113.10 -all` |')
        ->toContain('| TXT | `_dmarc.tits.guru` | `v=DMARC1; p=none; adkim=s; aspf=s` |')
        ->not->toContain('do not publish');

    $keyFile = sys_get_temp_dir().'/configure-plan-key-'.bin2hex(random_bytes(4));
    file_put_contents($keyFile, $key);

    try {
        expectNoKeyMaterial($output.$summary, $keyFile);
    } finally {
        unlink($keyFile);
    }
});

it('fails when show-dns fails for a target that has a reviewed identity', function () {
    expectDnsPlanRefused(configureRunDnsPlanStep("ERROR: openssl is required\n", 1), 'mail-identity show-dns exited 1 on the host.');
});

it('fails when whether the target has an identity cannot be told', function () {
    expectDnsPlanRefused(configureRunDnsPlanStep('{}', 0, 'tits-guru', "ERROR: unknown target\n", 1), 'mail-identity could not say whether tits-guru has a reviewed mail identity.');
    expectDnsPlanRefused(configureRunDnsPlanStep('{}', 0, 'tits-guru', "maybe\n"), 'mail-identity gave no key requirement for tits-guru.');
});

it('fails on a plan that is not one', function (string $plan) {
    expectDnsPlanRefused(configureRunDnsPlanStep($plan), 'mail-identity show-dns printed no readable plan.');
})->with([
    'not JSON' => ['the plan'],
    'truncated JSON' => ['{"records": [{"purpose": "A"'],
    'an array' => ['[]'],
    'no records' => ['{"dkim": {"key_status": "usable"}}'],
    'records not a list' => ['{"records": "A", "dkim": {"key_status": "usable"}}'],
    'no key status' => ['{"records": []}'],
    'nothing at all' => [''],
]);

it('fails once the key is required, or present but unusable, and no DKIM record can be derived', function () {
    [$absent] = configureRealDnsPlan(false);

    // Outbound mail is signed with the key, so its absence is no longer deferred.
    expectDnsPlanRefused(
        configureRunDnsPlanStep($absent, 0, 'tits-guru', "required\t/etc/opendkim/keys/tits-guru/rg1.private\t2048\n"),
        'the DKIM private key on the host is absent (required for tits-guru)',
    );

    // The committed policy requests tits-guru's outbound delivery, so the
    // repository's own mail-identity already calls the key required.
    expectDnsPlanRefused(configureRunDnsPlanStep($absent), 'the DKIM private key on the host is absent (required for tits-guru)');

    $unusable = json_decode($absent, true);
    $unusable['dkim']['key_status'] = 'unusable';

    expectDnsPlanRefused(configureRunDnsPlanStep(json_encode($unusable)), 'the DKIM private key on the host is unusable (required for tits-guru)');

    // Unusable key material is refused even while the target is still held.
    $scratch = makeScratchDir('configure-held-plan');

    try {
        expectDnsPlanRefused(
            configureRunDnsPlanStep(json_encode($unusable), workspace: mailPreActivationCheckout($scratch)),
            'the DKIM private key on the host is unusable (deferred for tits-guru)',
        );
    } finally {
        removeScratchDir($scratch);
    }
});

it('fails when the host detected no public IPv4 address', function () {
    [$plan] = configureRealDnsPlan(true, false);

    expect(json_decode($plan, true)['mta']['ipv4'])->toBeNull();

    expectDnsPlanRefused(configureRunDnsPlanStep($plan), 'no public IPv4 address was detected on the host');
});

it('fails on a missing, empty or ambiguous record', function (string $purpose, string $change, string $reason) {
    [$complete] = configureRealDnsPlan(true);
    $plan = json_decode($complete, true);
    $index = collect($plan['records'])->search(fn (array $record): bool => $record['purpose'] === $purpose);

    match ($change) {
        'removed' => array_splice($plan['records'], $index, 1),
        'null value' => $plan['records'][$index]['value'] = null,
        'empty value' => $plan['records'][$index]['value'] = '',
        'null name' => $plan['records'][$index]['name'] = null,
        'duplicated' => $plan['records'][] = $plan['records'][$index],
        'wrong type' => $plan['records'][$index]['type'] = 'CNAME',
    };

    expectDnsPlanRefused(configureRunDnsPlanStep(json_encode($plan)), $reason);
})->with([
    'A removed' => ['A', 'removed', '0 A records where exactly one belongs'],
    'A null value' => ['A', 'null value', 'the A record has no name or no value'],
    'A null name' => ['A', 'null name', 'the A record has no name or no value'],
    'A duplicated' => ['A', 'duplicated', '2 A records where exactly one belongs'],
    'PTR removed' => ['PTR', 'removed', '0 PTR records where exactly one belongs'],
    'PTR null value' => ['PTR', 'null value', 'the PTR record has no name or no value'],
    'PTR null name' => ['PTR', 'null name', 'the PTR record has no name or no value'],
    'DKIM removed' => ['DKIM', 'removed', '0 DKIM records where exactly one belongs'],
    'DKIM null value' => ['DKIM', 'null value', 'the DKIM record has no name or no value'],
    'DKIM empty value' => ['DKIM', 'empty value', 'the DKIM record has no name or no value'],
    'DKIM duplicated' => ['DKIM', 'duplicated', '2 DKIM records where exactly one belongs'],
    'SPF removed' => ['SPF', 'removed', '0 SPF records where exactly one belongs'],
    'SPF null value' => ['SPF', 'null value', 'the SPF record has no name or no value'],
    'SPF duplicated' => ['SPF', 'duplicated', '2 SPF records where exactly one belongs'],
    'DMARC removed' => ['DMARC', 'removed', '0 DMARC records where exactly one belongs'],
    'DMARC null value' => ['DMARC', 'null value', 'the DMARC record has no name or no value'],
    'DMARC wrong type' => ['DMARC', 'wrong type', 'the DMARC record is not of type TXT'],
]);

it('fails when the MTA hostname is missing, or the A and PTR records disagree with it', function () {
    [$complete] = configureRealDnsPlan(true);

    $plan = json_decode($complete, true);
    $plan['mta']['hostname'] = null;
    expectDnsPlanRefused(configureRunDnsPlanStep(json_encode($plan)), 'no MTA hostname');

    $plan = json_decode($complete, true);
    $plan['records'][collect($plan['records'])->search(fn (array $record): bool => $record['purpose'] === 'PTR')]['value'] = 'elsewhere.example';
    expectDnsPlanRefused(configureRunDnsPlanStep(json_encode($plan)), 'the PTR record does not map the host address back to the MTA hostname');

    $plan = json_decode($complete, true);
    $plan['records'][] = ['purpose' => 'MX', 'type' => 'MX', 'name' => 'tits.guru', 'value' => '10 mta1.tits.guru'];
    expectDnsPlanRefused(configureRunDnsPlanStep(json_encode($plan)), '1 records of no known purpose');
});

it('refuses to show anything that looks like key material', function () {
    $pem = (string) file_get_contents(mailIdentityKey('rsa2048'));

    [$exit, $output, $summary] = configureRunDnsPlanStep('{"records": []}'."\n".$pem);

    expect($exit)->not->toBe(0);
    expect($output.$summary)->not->toContain('PRIVATE KEY');
    expectNoKeyMaterial($output.$summary, mailIdentityKey('rsa2048'));

    // Nor on a failing show-dns.
    [$exit, $output, $summary] = configureRunDnsPlanStep($pem, 1);

    expect($exit)->not->toBe(0);
    expectNoKeyMaterial($output.$summary, mailIdentityKey('rsa2048'));
});
