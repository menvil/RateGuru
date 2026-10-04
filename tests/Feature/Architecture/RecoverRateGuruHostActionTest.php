<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * The reusable host-recovery transport action.
 *
 * It carries trusted tooling from the caller's own `develop` checkout to a
 * replacement machine, runs one fixed argv, parses exactly one machine-readable
 * result and cleans up after itself. Every decision a recovery makes lives in
 * infrastructure/scripts/recover-host; nothing here reimplements, mirrors or
 * second-guesses any of it.
 */
function recoverActionPath(): string
{
    return base_path('.github/actions/recover-rateguru-host/action.yml');
}

function recoverAction(): array
{
    return Yaml::parseFile(recoverActionPath());
}

function recoverActionSource(): string
{
    return File::get(recoverActionPath());
}

/** One step's `run:` body, by step name. */
function recoverActionStep(string $name): string
{
    foreach (recoverAction()['runs']['steps'] as $step) {
        if (($step['name'] ?? '') === $name) {
            return $step['run'] ?? '';
        }
    }

    throw new RuntimeException("no step named {$name}");
}

// =============================================================================
// The input contract
// =============================================================================

it('accepts a closed set of inputs and nothing that could redirect it', function () {
    $inputs = array_keys(recoverAction()['inputs']);
    sort($inputs);

    expect($inputs)->toBe([
        'backup-id',
        'bootstrap-known-hosts',
        'bootstrap-ssh-key',
        'bootstrap-user',
        'deployment-target',
        'environment',
        'mode',
        'operation-id',
        'recovery-host',
        'recovery-port',
    ]);

    // Nothing that could name a command, a filesystem path, a remote, a
    // bucket, a commit, a release or a build. Where a backup lives comes from
    // the registry plus fixed configuration, on the server; which commit the
    // data belongs to comes from that backup's own release.json.
    foreach ([
        'command', 'script', 'path', 'remote', 'bucket', 'source', 'ref', 'branch',
        'tag', 'source-sha', 'release', 'artifact', 'run-migrations', 'args',
        'rclone-config', 'environment-file', 'material-dir',
    ] as $rejected) {
        expect($inputs)->not->toContain($rejected);
    }
});

it('carries no secret material of any kind', function () {
    // Judged as code: the header prose lists, by name, every kind of material
    // this action deliberately does not carry, and a whole-file grep would
    // read that sentence as a violation of itself.
    $source = executableSourceLines(recoverActionSource());

    // The only credential is the privileged bootstrap one. There is no
    // application environment file, no authorized_keys, no rclone config, no
    // Basic Auth material and no TLS key anywhere in it.
    foreach ([
        'APP_KEY',
        'DB_PASSWORD',
        'authorized_keys',
        'htpasswd',
        'rclone.conf',
        'fullchain.pem',
        'privkey.pem',
        'B2_',
        'SENTRY_',
        'NIGHTWATCH_',
    ] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }

    expect(array_keys(recoverAction()['inputs']))->toContain('bootstrap-ssh-key');
});

it('refuses to fall back to the deployment key', function () {
    $source = recoverActionSource();

    expect($source)
        ->not->toContain('DEPLOY_SSH_KEY:')
        ->not->toContain('inputs.ssh-private-key');

    expect(recoverActionStep('Validate fixed caller inputs'))
        ->toContain('has no bootstrap SSH credential')
        ->toContain('must not be widened into a recovery credential');
});

it('holds every operand to its own mode', function () {
    $validation = recoverActionStep('Validate fixed caller inputs');

    expect($validation)
        ->toContain('apply|inspect|resume|verify')
        ->toContain('mode=apply requires backup-id as an exact YYYYMMDD-HHMMSS timestamp')
        ->toContain("There is no 'latest' and no implicit selection.")
        ->toContain('operation-id is not valid with mode=apply')
        ->toContain('requires operation-id in the server')
        ->toContain('backup-id is not valid with mode=${MODE}')
        ->toContain('mode=verify takes neither backup-id nor operation-id');

    // The two closed formats, checked on the runner so a malformed value never
    // reaches an SSH command line.
    expect($validation)
        ->toContain('^[0-9]{8}-[0-9]{6}$')
        ->toContain('^[0-9]{8}-[0-9]{6}-[0-9a-f]{6}$');
});

// =============================================================================
// Transport safety
// =============================================================================

it('uses strict host key checking with no TOFU anywhere', function () {
    $source = recoverActionSource();

    expect(substr_count($source, 'StrictHostKeyChecking=yes'))->toBeGreaterThanOrEqual(4);

    foreach ([
        'StrictHostKeyChecking=no',
        'StrictHostKeyChecking=accept-new',
        'UserKnownHostsFile=/dev/null',
        'ssh-keyscan',
        'PasswordAuthentication=yes',
    ] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }

    // Every strict-checking option is paired with the verified known_hosts
    // file, so no invocation can pin one without the other.
    expect(substr_count($source, 'StrictHostKeyChecking=yes'))
        ->toBe(substr_count($source, 'UserKnownHostsFile="${RATEGURU_BOOTSTRAP_KNOWN_HOSTS_PATH}"'));
});

it('verifies root or passwordless sudo before anything is uploaded', function () {
    $steps = array_column(recoverAction()['runs']['steps'], 'name');

    $access = array_search('Verify privileged access on the replacement host', $steps, true);
    $upload = array_search('Upload the trusted recovery bundle', $steps, true);
    $lifecycle = array_search('Validate the target lifecycle before anything is uploaded', $steps, true);

    expect($access)->toBeLessThan($upload);
    expect($lifecycle)->toBeLessThan($upload);

    expect(recoverActionStep('Verify privileged access on the replacement host'))
        ->toContain('sudo -n true')
        ->toContain('Recovery requires root; nothing was uploaded and nothing was changed.');
});

it('refuses a lifecycle=planned target on the runner, before the credential is used', function () {
    $steps = array_column(recoverAction()['runs']['steps'], 'name');

    expect(array_search('Validate the target lifecycle before anything is uploaded', $steps, true))
        ->toBeLessThan(array_search('Configure bootstrap SSH', $steps, true));

    expect(recoverActionStep('Validate the target lifecycle before anything is uploaded'))
        ->toContain('infrastructure/scripts/targets')
        ->toContain('lifecycle=${lifecycle:-unknown}, not active')
        ->toContain('Recovery is refused before any tooling is uploaded');
});

it('packages only infrastructure, never the application', function () {
    $package = recoverActionStep('Package trusted recovery bundle');

    expect($package)
        ->toContain('--directory "${GITHUB_WORKSPACE}"')
        ->toContain('infrastructure/scripts/recover-host')
        ->toContain('infrastructure/scripts/prepare-host');

    // One tar operand, and it is `infrastructure`.
    expect(preg_match('/^\s+infrastructure$/m', $package))->toBe(1);

    foreach (['app/', 'vendor', 'composer', 'npm', 'artisan', 'public/'] as $forbidden) {
        expect($package)->not->toContain($forbidden);
    }
});

it('runs recover-host from a root-only bundle with a fixed argv', function () {
    $run = recoverActionStep('Run the server-side recovery');

    // A Bash array, quoted element-wise — never an interpolated shell string.
    expect($run)
        ->toContain('remote_command=(')
        ->toContain('"${RATEGURU_REMOTE_ROOT}/infrastructure/scripts/recover-host"')
        ->toContain('"${remote_command[@]@Q}"');

    // Only the closed flag set, and only the operand its mode allows.
    expect($run)
        ->toContain('"--${MODE}"')
        ->toContain('--target "${DEPLOYMENT_TARGET}"')
        ->toContain('--backup "${BACKUP_ID}"')
        ->toContain('--operation "${OPERATION_ID}"');

    foreach (['eval ', 'bash -c "$', '--source', '--release', '--artifact'] as $forbidden) {
        expect($run)->not->toContain($forbidden);
    }

    // The bundle is installed root-only before anything runs from it.
    expect(recoverActionStep('Upload the trusted recovery bundle'))
        ->toContain('install -d -m 0700 -o root -g root')
        ->toContain('chown -R root:root');
});

it('parses exactly one machine-readable result, and checks it describes this run', function () {
    $run = recoverActionStep('Run the server-side recovery');

    // Counted, never `grep -m1`: the primitive's contract is exactly one
    // result per terminal success, and accepting two silently is the shape a
    // bug in that contract would take.
    expect($run)
        ->toContain("grep -c '^RATEGURU_RECOVER_RESULT='")
        ->toContain('Expected exactly one RATEGURU_RECOVER_RESULT line');

    expect(executableSourceLines($run))->not->toContain('grep -m1');

    // The statuses each mode may report, so a run that succeeds about a
    // different state is not passed on as a success. `inspect` alone has two,
    // because a recovery has two safe stages and an operator whose runner died
    // between them needs to be told which side they are on.
    expect($run)
        ->toContain("apply)   expected_statuses='[\"awaiting-code\"]'")
        ->toContain("inspect) expected_statuses='[\"awaiting-code\",\"ready-to-resume\"]'")
        ->toContain("resume)  expected_statuses='[\"completed\"]'")
        ->toContain("verify)  expected_statuses='[\"verified\"]'")
        ->toContain('.target == $target');
});

it('exposes typed outputs drawn only from that result', function () {
    $outputs = array_keys(recoverAction()['outputs']);
    sort($outputs);

    expect($outputs)->toBe([
        'backup',
        'backup-release',
        'current-release',
        'data-restored',
        'failure-cause',
        'health',
        'offsite-writes',
        'operation',
        'previous',
        'queue',
        'required-source-sha',
        'scheduler',
        'source-sha',
        'status',
    ]);

    foreach (recoverAction()['outputs'] as $output) {
        expect($output['value'])->toStartWith('${{ steps.recover.outputs');
    }
});

it('removes the key material when the bootstrap key turns out to be unusable', function () {
    // The key is validated BEFORE its path is exported, so the always() cleanup
    // at the end of the action has nothing to remove on that path — it reads
    // the two RATEGURU_BOOTSTRAP_*_PATH variables, and neither was set. The
    // failing branch therefore removes both files itself, from the locals it
    // still has, rather than leaving a private key on the runner.
    $configure = recoverActionStep('Configure bootstrap SSH');

    expect($configure)->toContain('rm -f "${key_path}" "${known_hosts_path}"');

    $validation = mb_strpos($configure, 'ssh-keygen -y -f');
    $removal = mb_strpos($configure, 'rm -f "${key_path}"');
    $export = mb_strpos($configure, 'RATEGURU_BOOTSTRAP_SSH_KEY_PATH=');

    expect($validation)->not->toBeFalse()
        ->and($removal)->not->toBeFalse()
        ->and($export)->not->toBeFalse();

    // Validated, then cleaned up on failure, and only then exported.
    expect($validation)->toBeLessThan($removal);
    expect($removal)->toBeLessThan($export);

    // And the always() cleanup stays safe when nothing was exported: it reads
    // both paths with a default, so an unset variable removes nothing.
    expect(recoverActionStep('Remove temporary local files'))
        ->toContain('"${RATEGURU_BOOTSTRAP_SSH_KEY_PATH:-}"')
        ->toContain('"${RATEGURU_BOOTSTRAP_KNOWN_HOSTS_PATH:-}"');
});

it('removes its local and remote temporary files on success and on failure', function () {
    $steps = recoverAction()['runs']['steps'];

    $cleanups = array_values(array_filter(
        $steps,
        static fn (array $step): bool => str_starts_with($step['name'] ?? '', 'Remove '),
    ));

    expect($cleanups)->toHaveCount(2);

    foreach ($cleanups as $step) {
        expect($step['if'] ?? '')->toBe('${{ always() }}');
    }

    expect(recoverActionStep('Remove the remote recovery bundle'))
        ->toContain('rm -rf %q && rm -rf %q')
        ->toContain('never masks the real outcome');

    expect(recoverActionStep('Remove temporary local files'))
        ->toContain('RATEGURU_BUNDLE_PATH')
        ->toContain('RATEGURU_BOOTSTRAP_SSH_KEY_PATH')
        ->toContain('RATEGURU_BOOTSTRAP_KNOWN_HOSTS_PATH');
});

// =============================================================================
// It is transport, not policy
// =============================================================================

it('contains no recovery business logic', function () {
    $source = recoverActionSource();

    // Every decision belongs to the server primitive. Nothing here reads a
    // guard, a state document, a backup or a database.
    foreach ([
        'recovery-guard',
        'state.json',
        'psql',
        'pg_restore',
        'createdb',
        'dropdb',
        'sha256sum',
        'supervisorctl',
        'cron.d',
        'shared/.env',
        'storage-app.tar.gz',
        'emergency',
    ] as $forbidden) {
        expect($source)->not->toContain($forbidden, "the transport action must never: {$forbidden}");
    }
});

it('never builds, deploys, prepares, repairs or restores', function () {
    $source = recoverActionSource();

    foreach ([
        'build-rateguru',
        'deploy-rateguru',
        'prepare-rateguru-host',
        'repair-rateguru-target',
        'restore-rateguru',
        'actions/upload-artifact',
        'actions/download-artifact',
    ] as $forbidden) {
        expect($source)->not->toContain($forbidden);
    }

    // And it never runs any other server primitive.
    preg_match_all('#infrastructure/scripts/[a-z-]+#', $source, $matches);
    expect(array_values(array_unique($matches[0])))->toEqualCanonicalizing([
        'infrastructure/scripts/recover-host',
        'infrastructure/scripts/prepare-host',
        'infrastructure/scripts/targets',
    ]);
});

// =============================================================================
// What a failed remote invocation says
// =============================================================================

/** The runner-side environment the server-side recovery step runs in. */
function recoverStepEnv(string $scratch, array $overrides = []): array
{
    return actionStepEnv($scratch, array_merge([
        'RECOVERY_HOST' => '203.0.113.24',
        'RECOVERY_PORT' => '22',
        'BOOTSTRAP_USER' => 'recovery',
        'DEPLOYMENT_TARGET' => 'staging-main',
        'ENVIRONMENT' => 'staging',
        'MODE' => 'apply',
        'BACKUP_ID' => '20260909-113248',
        'OPERATION_ID' => '',
        'RATEGURU_PRIVILEGED_PREFIX' => 'sudo -n',
        'RATEGURU_REMOTE_ROOT' => '/root/.rateguru-recovery-1-1',
        'RATEGURU_BOOTSTRAP_SSH_KEY_PATH' => $scratch.'/key',
        'RATEGURU_BOOTSTRAP_KNOWN_HOSTS_PATH' => $scratch.'/known_hosts',
    ], $overrides));
}

it('always prints what a failed remote recovery said, exits with its status, and parses no result out of it', function () {
    $scratch = restoreScratchDir();

    try {
        // The first real clean-host recovery: the server refused the
        // prepared/EMPTY contract, named the one problem on stdout and the
        // verdict on stderr, and exited 1. The actionable half must reach the
        // operator — losing it to a command substitution under `set -e` is the
        // defect this test exists for.
        $result = runActionStep('.github/actions/recover-rateguru-host/action.yml', 'Run the server-side recovery', recoverStepEnv($scratch, [
            'RGTEST_SSH_STDOUT' => implode("\n", [
                'RECOVERY REFUSED — staging-main is not a prepared, empty replacement host:',
                '  * cannot observe the target queue program rateguru-staging-queue: supervisorctl status exited 4 (supervisorctl said: rateguru-staging-queue: ERROR (no such group))',
            ]),
            'RGTEST_SSH_STDERR' => 'ERROR: staging-main does not satisfy the prepared/EMPTY recovery contract (1 problems above); nothing was created and nothing was changed',
            'RGTEST_SSH_EXIT' => '1',
        ]));

        // The remote status is preserved, never turned into a success.
        expect($result['exit'])->toBe(1);

        // Both halves of the diagnosis are in the log.
        expect($result['output'])
            ->toContain('RECOVERY REFUSED — staging-main is not a prepared, empty replacement host')
            ->toContain('cannot observe the target queue program rateguru-staging-queue')
            ->toContain('ERROR (no such group)')
            ->toContain('does not satisfy the prepared/EMPTY recovery contract');

        // And nothing was parsed out of a failed invocation: the "expected
        // exactly one result line" complaint would be a second, misleading
        // failure on top of the real one.
        foreach (['RATEGURU_RECOVER_RESULT line', 'does not carry a status', 'describes a different target'] as $parsing) {
            expect(str_contains($result['output'], $parsing))
                ->toBeFalse("a failed remote invocation must not be parsed: {$parsing}");
        }

        // The workflow is told which refusal it was, and the summary carries
        // the server's own lines rather than the whole log.
        expect(File::get($scratch.'/github-output'))->toContain('failure-cause=host-not-prepared');
        expect(File::get($scratch.'/github-step-summary'))
            ->toContain('Host recovery — apply refused (host-not-prepared)')
            ->toContain('does not satisfy the prepared/EMPTY recovery contract')
            ->toContain('Operator runbook: infrastructure/runbooks/clean-host-recovery.md');
    } finally {
        removeScratchDir($scratch);
    }
});

it('classifies each refusal a mode can meet, in every mode', function (string $mode, string $remote, string $cause) {
    $scratch = restoreScratchDir();

    try {
        $result = runActionStep('.github/actions/recover-rateguru-host/action.yml', 'Run the server-side recovery', recoverStepEnv($scratch, [
            'MODE' => $mode,
            'OPERATION_ID' => $mode === 'apply' ? '' : '20260909-120000-abc123',
            'RGTEST_SSH_STDERR' => $remote,
            'RGTEST_SSH_EXIT' => '3',
        ]));

        expect($result['exit'])->toBe(3, 'the remote status is what the step exits with');
        expect($result['output'])->toContain($remote);
        expect(File::get($scratch.'/github-output'))->toContain("failure-cause={$cause}");
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    ['apply', 'ERROR: backup 20260909-113248 is not clean-host-recovery-capable: its manifest schema is 2', 'backup-not-recovery-capable'],
    ['apply', 'ERROR: could not download SHA256SUMS of backup 20260909-113248 from the offsite remote', 'backup-not-found'],
    ['apply', 'ERROR: environment material: MISMATCH — the shared/.env Prepare Host placed', 'environment-mismatch'],
    ['inspect', 'ERROR: the offsite-write hold is missing (/home/www/rateguru/run/offsite-write-hold)', 'offsite-hold-missing'],
    ['inspect', 'ERROR: something nobody has a word for yet', 'inspect-failed'],
    ['resume', 'ERROR: recovery operation 20260909-120000-abc123 has status \'in-progress\', not \'awaiting-code\'', 'awaiting-code'],
    ['resume', 'ERROR: rclone is not available at /usr/local/bin/rclone', 'rclone-config-unavailable'],
    ['verify', 'ERROR: staging-main still carries a recovery guard (/home/www/rateguru/run/recoveries/staging-main/recovery-guard)', 'guard-present'],
    ['verify', 'ERROR: nothing this action has a word for', 'verify-failed'],
]);

it('still reads the machine-readable result of a successful invocation, including the runtime it reports', function () {
    $scratch = restoreScratchDir();

    try {
        $result = runActionStep('.github/actions/recover-rateguru-host/action.yml', 'Run the server-side recovery', recoverStepEnv($scratch, [
            'RGTEST_SSH_STDOUT' => 'step: activate the recovered data'."\n".'RATEGURU_RECOVER_RESULT='.json_encode([
                'status' => 'awaiting-code',
                'target' => 'staging-main',
                'operation' => '20260909-120000-abc123',
                'backup' => '20260909-113248',
                'backup_release' => '20260909-104500-1a2b3c4',
                'required_source_sha' => str_repeat('a', 40),
                'data_restored' => true,
                'current_release' => '',
                'source_sha' => '',
                'health' => 'not-checked',
                'queue' => 'stopped',
                'scheduler' => 'held',
                'offsite_writes' => 'held',
            ]),
            'RGTEST_SSH_EXIT' => '0',
        ]));

        expect($result['exit'])->toBe(0, $result['output']);

        $outputs = File::get($scratch.'/github-output');

        expect($outputs)
            ->toContain('status=awaiting-code')
            ->toContain('operation=20260909-120000-abc123')
            ->toContain('backup=20260909-113248')
            ->toContain('offsite-writes=held')
            ->toContain('queue=stopped')
            ->toContain('scheduler=held');
        expect($outputs)->not->toContain('failure-cause=');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to pass on a final verification that did not report the contract it exists to prove', function (string $missing) {
    $scratch = restoreScratchDir();

    try {
        $result = json_decode(json_encode([
            'status' => 'verified',
            'target' => 'staging-main',
            'operation' => '20260909-120000-abc123',
            'backup' => '20260909-113248',
            'backup_release' => '20260909-104500-1a2b3c4',
            'required_source_sha' => str_repeat('a', 40),
            'data_restored' => true,
            'current_release' => '20260909-104500-1a2b3c4',
            'source_sha' => str_repeat('a', 40),
            'health' => 'pass',
            'queue' => 'running',
            'scheduler' => 'present',
            'offsite_writes' => 'held',
            'previous' => 'absent',
        ]), true);

        unset($result[$missing]);

        $run = runActionStep('.github/actions/recover-rateguru-host/action.yml', 'Run the server-side recovery', recoverStepEnv($scratch, [
            'MODE' => 'verify',
            'BACKUP_ID' => '',
            'OPERATION_ID' => '',
            'RGTEST_SSH_STDOUT' => 'RATEGURU_RECOVER_RESULT='.json_encode($result),
            'RGTEST_SSH_EXIT' => '0',
        ]));

        expect($run['exit'])->not->toBe(0);
        expect($run['output'])->toContain($missing === 'offsite_writes'
            // The offsite-write hold has its own gate, in every mode: no
            // result that says nothing about it is passed on, ever.
            ? 'The machine-readable recovery result does not report the offsite-write hold'
            : "The final verification did not report {$missing}, which is part of the contract it exists to prove");

        // Nothing is passed on: a workflow that received the outputs would
        // otherwise have to invent the missing fact or hide it.
        expect(File::get($scratch.'/github-output'))->not->toContain('status=verified');
    } finally {
        removeScratchDir($scratch);
    }
})->with(['queue', 'scheduler', 'health', 'offsite_writes', 'previous']);

it('passes on a final verification that reports all of it', function () {
    $scratch = restoreScratchDir();

    try {
        $run = runActionStep('.github/actions/recover-rateguru-host/action.yml', 'Run the server-side recovery', recoverStepEnv($scratch, [
            'MODE' => 'verify',
            'BACKUP_ID' => '',
            'OPERATION_ID' => '',
            'RGTEST_SSH_STDOUT' => 'RATEGURU_RECOVER_RESULT='.json_encode([
                'status' => 'verified',
                'target' => 'staging-main',
                'operation' => '20260909-120000-abc123',
                'backup' => '20260909-113248',
                'backup_release' => '20260909-104500-1a2b3c4',
                'required_source_sha' => str_repeat('a', 40),
                'data_restored' => true,
                'current_release' => '20260909-104500-1a2b3c4',
                'source_sha' => str_repeat('a', 40),
                'health' => 'pass',
                'queue' => 'running',
                'scheduler' => 'present',
                'offsite_writes' => 'held',
                'previous' => 'absent',
            ]),
            'RGTEST_SSH_EXIT' => '0',
        ]));

        expect($run['exit'])->toBe(0, $run['output']);
        expect(File::get($scratch.'/github-output'))
            ->toContain('status=verified')
            ->toContain('queue=running')
            ->toContain('scheduler=present')
            ->toContain('health=pass')
            ->toContain('offsite-writes=held')
            ->toContain('previous=absent');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to pass on a verification that says the host has a previous release link', function (string $reported) {
    $scratch = restoreScratchDir();

    try {
        // The server refuses to verify such a host at all, so this result
        // cannot come from a healthy chain — which is exactly why the action
        // will not relay it. `previous` is the one verified field whose VALUE
        // is the contract rather than merely its presence, and everything
        // downstream announces that contract as fact.
        $run = runActionStep('.github/actions/recover-rateguru-host/action.yml', 'Run the server-side recovery', recoverStepEnv($scratch, [
            'MODE' => 'verify',
            'BACKUP_ID' => '',
            'OPERATION_ID' => '',
            'RGTEST_SSH_STDOUT' => 'RATEGURU_RECOVER_RESULT='.json_encode([
                'status' => 'verified',
                'target' => 'staging-main',
                'operation' => '20260909-120000-abc123',
                'backup' => '20260909-113248',
                'backup_release' => '20260909-104500-1a2b3c4',
                'required_source_sha' => str_repeat('a', 40),
                'data_restored' => true,
                'current_release' => '20260909-104500-1a2b3c4',
                'source_sha' => str_repeat('a', 40),
                'health' => 'pass',
                'queue' => 'running',
                'scheduler' => 'present',
                'offsite_writes' => 'held',
                'previous' => $reported,
            ]),
            'RGTEST_SSH_EXIT' => '0',
        ]));

        expect($run['exit'])->not->toBe(0);
        expect($run['output'])->toContain('a recovered host has no previous release link');
        expect(File::get($scratch.'/github-output'))->not->toContain('status=verified');
    } finally {
        removeScratchDir($scratch);
    }
})->with(['present', 'unknown']);
