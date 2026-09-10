<?php

use Illuminate\Support\Facades\File;

/**
 * Recover Host: rebuilding one lost target onto a prepared replacement machine.
 *
 * Executes the real shipped infrastructure/scripts/recover-host end to end
 * against a file-backed fake PostgreSQL, a fake offsite remote, a fake
 * Supervisor and the REAL fetch-backup/verify-backup/restore-database/
 * restore-storage primitives. That is what makes these tests real rather than
 * rigged: the staged swap, the guard, the retained pre-recovery copies and the
 * compensation are all observable across separate script invocations, so
 * "nothing canonical was replaced" and "the prepared state came back" are
 * checked against catalog and filesystem state, not against log lines.
 */
function recoverHostScript(): string
{
    return base_path('infrastructure/scripts/recover-host');
}

/**
 * @return array{exit: int, output: string}
 */
function recoverHostRun(string $scratch, array $arguments, array $envOverrides = []): array
{
    [$registryPath, $targetsPath] = parityRegistryFixture($scratch);

    $env = infraScriptEnv($scratch, $registryPath, $targetsPath, array_merge(
        fakePostgresEnv($scratch),
        targetRuntimeEnv($scratch),
        recoveryEnv($scratch),
        $envOverrides,
    ));

    [$exit, $output] = runInfraScript(patchedInfraScript($scratch, 'recover-host'), $arguments, $env);

    return ['exit' => $exit, 'output' => $output];
}

/**
 * A prepared, EMPTY replacement host with one exact offsite backup waiting for
 * it: the only starting state a recovery accepts.
 */
function recoveryFixture(string $scratch, array $options = []): void
{
    preparedTargetTreeFixture($scratch, $options);
    installFakePostgres($scratch, $options);
    installTargetRuntimeStubs($scratch);
    installFakePrepareHost($scratch);
    offsiteRcloneStub($scratch);

    file_put_contents($scratch.'/rclone.conf', "[rateguru-b2]\ntype = b2\n");
    touch($scratch.'/rclone.log');

    @mkdir($scratch.'/cron.d', 0o755, true);
    file_put_contents($scratch.'/cron.d/parity-scheduler', "* * * * * root true\n");

    // A prepared host's queue program is registered but has no application to
    // run: the committed program keeps autostart=true and crash-loops until a
    // deployment exists. FATAL is what that looks like, and it is not RUNNING.
    file_put_contents($scratch.'/supervisor-state', ($options['queue_state'] ?? 'FATAL')."\n");

    // Prepare Host installs the target's Supervisor program configuration and
    // validates it; whether its GROUP is loaded into the running Supervisor is
    // a separate question, and on a freshly prepared host it is not
    // ('queue_group_absent'), because activation is deferred until a release
    // exists.
    @mkdir($scratch.'/supervisor-conf.d', 0o755, true);

    if (($options['queue_config'] ?? true) === true) {
        file_put_contents($scratch.'/supervisor-conf.d/parity-queue.conf', "[program:parity-queue]\nautostart=true\n");
    }

    if (($options['queue_group_absent'] ?? false) === true) {
        touch($scratch.'/supervisor-group-absent');
    }

    // The prepared database exists and is EMPTY.
    @mkdir($scratch.'/pg/tables', 0o755, true);
    @mkdir($scratch.'/pg/migrations', 0o755, true);
    file_put_contents($scratch.'/pg/tables/parity_db', ($options['prepared_tables'] ?? 0)."\n");
    file_put_contents($scratch.'/pg/migrations/parity_db', "0\n");

    recoveryOffsiteBackupFixture($scratch, $options['backup'] ?? '20260115-023000', $options['backup_options'] ?? []);
}

/** Runs a full --apply and returns the operation ID it generated. */
function recoveryApply(string $scratch, array $envOverrides = [], string $backupId = '20260115-023000'): array
{
    $result = recoverHostRun($scratch, [
        '--apply', '--target', 'parity-target', '--backup', $backupId,
    ], $envOverrides);

    return $result;
}

function recoveryOperationIdIn(string $output): string
{
    expect(preg_match('/RATEGURU_RECOVER_RESULT=(\{.*\})/', $output, $matches))
        ->toBe(1, "no machine-readable recovery result in:\n".$output);

    return json_decode($matches[1], true)['operation'];
}

// =============================================================================
// Preconditions: the prepared, EMPTY contract
// =============================================================================

it('refuses a planned target before it reads a backup, a workspace or a database', function (string $mode) {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $arguments = ['--'.$mode, '--target', 'planned-target'];

        if ($mode === 'check' || $mode === 'apply') {
            $arguments[] = '--backup';
            $arguments[] = '20260115-023000';
        } elseif ($mode !== 'verify') {
            $arguments[] = '--operation';
            $arguments[] = '20260115-041233-9be21c';
        }

        $result = recoverHostRun($scratch, $arguments);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('lifecycle=planned');

        expect(File::exists($scratch.'/run/recoveries'))->toBeFalse();
        expect(File::get($scratch.'/rclone.log'))->toBe('');
        expect(File::get($scratch.'/prepare-host.log'))->toBe('');
    } finally {
        removeScratchDir($scratch);
    }
})->with(['check', 'apply', 'inspect', 'resume', 'verify']);

it('requires root for every mode, before anything else', function (string $mode) {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        // The UNPATCHED script: its own require_root is the production gate.
        [$registryPath, $targetsPath] = parityRegistryFixture($scratch);
        $env = infraScriptEnv($scratch, $registryPath, $targetsPath, recoveryEnv($scratch));
        unset($env['RGTEST_BYPASS_ROOT']);

        [$exit, $output] = runInfraScript(recoverHostScript(), [
            '--'.$mode, '--target', 'parity-target', '--backup', '20260115-023000',
        ], $env);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('must be executed as root');
    } finally {
        removeScratchDir($scratch);
    }
})->with(['check', 'apply'])->skip(fn (): bool => getmyuid() === 0, 'the root gate cannot be observed as root');

it('refuses a deployed target and names the operation that actually applies', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        // Give the prepared host a release and a current link: it is deployed.
        mkdir($scratch.'/target/releases/'.FIXTURE_RELEASE, 0o755, true);
        symlink($scratch.'/target/releases/'.FIXTURE_RELEASE, $scratch.'/target/current');

        $result = recoverHostRun($scratch, ['--check', '--target', 'parity-target', '--backup', '20260115-023000']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('this target is deployed, so it is not a lost host being rebuilt')
            ->toContain('Restore Target Data')
            ->toContain('Repair Target');

        expect(File::exists($scratch.'/run/recoveries'))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses a stray previous link and a releases tree that already holds a release', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);
        mkdir($scratch.'/target/releases/'.FIXTURE_RELEASE, 0o755, true);
        symlink($scratch.'/target/releases/'.FIXTURE_RELEASE, $scratch.'/target/previous');

        $result = recoverHostRun($scratch, ['--check', '--target', 'parity-target', '--backup', '20260115-023000']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('but no current')
            ->toContain('already holds release directories');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses a database that already holds tables, and never drops or truncates it', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch, ['prepared_tables' => 12]);

        $result = recoverHostRun($scratch, ['--check', '--target', 'parity-target', '--backup', '20260115-023000']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('already holds 12 tables in the public schema')
            ->toContain('never drops, truncates or overwrites data it did not put there');

        expect(File::get($scratch.'/dropdb.log'))->toBe('');
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses a storage tree that already holds application data, and never removes it', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);
        mkdir($scratch.'/target/shared/storage/app/public', 0o755, true);
        file_put_contents($scratch.'/target/shared/storage/app/somebody-elses.txt', "data\n");

        $result = recoverHostRun($scratch, ['--check', '--target', 'parity-target', '--backup', '20260115-023000']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('never deletes data it did not put there');

        expect(File::exists($scratch.'/target/shared/storage/app/somebody-elses.txt'))->toBeTrue();
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses a host whose Prepare Host verification does not pass', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $result = recoverHostRun($scratch, ['--check', '--target', 'parity-target', '--backup', '20260115-023000'], [
            'RGTEST_PREPARE_HOST_EXIT' => '1',
        ]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('this machine is not a prepared host, and a recovery never prepares one');

        expect(File::get($scratch.'/prepare-host.log'))
            ->toContain('prepare-host --verify --target parity-target');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses a running queue program, and an unobservable one', function (array $env, string $expected) {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch, ['queue_state' => 'RUNNING']);

        $result = recoverHostRun($scratch, ['--check', '--target', 'parity-target', '--backup', '20260115-023000'], $env);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain($expected);
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    'a live worker' => [[], 'reports RUNNING'],
    'an unreachable supervisord' => [
        ['RGTEST_SUPERVISOR_STATUS_FAILURE' => 'unix:///var/run/supervisor.sock refused connection'],
        'refusing to recover without knowing whether a worker is running',
    ],
    'a permission failure' => [
        ['RGTEST_SUPERVISOR_STATUS_FAILURE' => 'error: <class \'PermissionError\'>, [Errno 13] Permission denied'],
        'refusing to recover without knowing whether a worker is running',
    ],
    'an answer about another program' => [
        ['RGTEST_SUPERVISOR_STATUS_STDOUT' => 'other-project-queue: ERROR (no such group)', 'RGTEST_SUPERVISOR_STATUS_RC' => '4'],
        'refusing to recover without knowing whether a worker is running',
    ],
    'more than the one diagnosis' => [
        [
            'RGTEST_SUPERVISOR_STATUS_STDOUT' => "parity-queue: ERROR (no such group)\nparity-queue:parity-queue_00   RUNNING   pid 1, uptime 0:01:00",
            'RGTEST_SUPERVISOR_STATUS_RC' => '4',
        ],
        'refusing to recover without knowing whether a worker is running',
    ],
]);

it('judges a loaded queue group by the state its processes are in', function (string $state, bool $recoverable) {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch, ['queue_state' => $state]);

        $result = recoverHostRun($scratch, ['--check', '--target', 'parity-target', '--backup', '20260115-023000']);

        if ($recoverable) {
            expect($result['exit'])->toBe(0, $result['output']);
            expect($result['output'])->toContain('RECOVERABLE: YES');

            return;
        }

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain("reports {$state}")
            ->toContain('a live worker means this is not the empty replacement host a recovery is for');
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    'RUNNING' => ['RUNNING', false],
    'STARTING' => ['STARTING', false],
    'STOPPING' => ['STOPPING', false],
    'STOPPED' => ['STOPPED', true],
    // A prepared host's worker crash-loops: autostart=true with no
    // application to run. Not serving, and not a reason to refuse.
    'FATAL' => ['FATAL', true],
]);

// =============================================================================
// The PRE_DEPLOY Supervisor state
//
// install-bootstrap-services installs and validates the target's queue program
// and DEFERS `supervisorctl update` until a release exists, so a prepared,
// never-deployed host answers "no such group" about its own queue. That is the
// normal state of the machine a clean-host recovery is for, and it holds the
// target more strongly than STOPPED does — but only when it is proven to be
// exactly that state.
// =============================================================================

it('accepts a queue program that is configured and not yet loaded into the running Supervisor', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch, ['queue_group_absent' => true]);

        $result = recoverHostRun($scratch, ['--check', '--target', 'parity-target', '--backup', '20260115-023000']);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('configured on this host and not yet loaded into the running Supervisor')
            ->toContain('in which no worker can be running')
            ->toContain('RECOVERABLE: YES');

        // Read-only: the check asked Supervisor for this target's own status —
        // once through the shared observer and once more to classify what it
        // could not read — and for nothing else. No stop, start, reread or
        // update, and no question about another program.
        expect(File::get($scratch.'/supervisor.log'))->toBe(str_repeat("supervisorctl status parity-queue:*\n", 2));
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses a queue program it cannot see for any other reason, and one on a machine that is not a prepared host', function (array $options, array $env, string $expected) {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch, $options);

        $result = recoverHostRun($scratch, ['--check', '--target', 'parity-target', '--backup', '20260115-023000'], $env);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain($expected);
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    // Exit 4 alone proves nothing: supervisorctl uses it for "the name matched
    // nothing" AND for an upcheck it could not complete.
    'exit 4 because supervisord could not be reached' => [
        ['queue_group_absent' => true],
        ['RGTEST_SUPERVISOR_GROUP_ABSENT' => '', 'RGTEST_SUPERVISOR_STATUS_FAILURE' => 'unix:///var/run/supervisor.sock refused connection'],
        'refusing to recover without knowing whether a worker is running',
    ],
    // A group nothing on this machine ever configured is not a deferred
    // activation; it is a machine that was never prepared for this target.
    'no such group, and no installed program configuration' => [
        ['queue_group_absent' => true, 'queue_config' => false],
        [],
        'refusing to recover without knowing whether a worker is running',
    ],
    // The whole licence for accepting it is that Prepare Host proved the
    // configuration is installed, parses and belongs to a prepared machine.
    'no such group, on a machine prepare-host --verify refuses' => [
        ['queue_group_absent' => true],
        ['RGTEST_PREPARE_HOST_EXIT' => '1'],
        'this machine is not a prepared host',
    ],
]);

it('recovers a host whose queue group was never loaded, and loads it when the recovery resumes', function () {
    $scratch = restoreScratchDir();

    try {
        // Exactly the machine the first real clean-host recovery met: Prepare
        // Host succeeded, and the queue program's group is not in the running
        // Supervisor because there was no release to activate it for.
        recoveryFixture($scratch, ['queue_group_absent' => true]);

        $applied = recoveryApply($scratch);

        expect($applied['exit'])->toBe(0, $applied['output']);
        expect($applied['output'])->toContain('there is no worker to stop');

        $operation = recoveryOperationIdIn($applied['output']);

        // Nothing was stopped, because there was nothing to stop.
        expect(File::get($scratch.'/supervisor.log'))->not->toContain('supervisorctl stop');
        expect(recoveryGuard($scratch))->toMatchArray(['status' => 'awaiting-code']);

        // The authoritative hold proof the controlled recovery deployment runs
        // reports the host as held, rather than refusing to describe it.
        $inspected = recoverHostRun($scratch, ['--inspect', '--target', 'parity-target', '--operation', $operation]);

        expect($inspected['exit'])->toBe(0, $inspected['output']);
        expect($inspected['output'])
            ->toContain('no worker can be running in a group Supervisor does not know')
            ->toContain('"queue":"stopped"');

        deployRecoveredRelease($scratch);

        $resumed = recoverHostRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);

        expect($resumed['exit'])->toBe(0, $resumed['output']);
        expect($resumed['output'])
            ->toContain('adding it from its installed configuration')
            ->toContain('"status":"completed"')
            ->toContain('"queue":"running"');

        // The two commands an ordinary first deployment runs, and no
        // configuration of its own: reread parses, update adds exactly this
        // target's program, and autostart brings it up.
        $supervisor = File::get($scratch.'/supervisor.log');

        expect($supervisor)
            ->toContain('supervisorctl reread')
            ->toContain('supervisorctl update parity-queue');
        expect(str_contains($supervisor, 'supervisorctl update all'))->toBeFalse('the resume updates exactly this target\'s program');

        expect(recoveryGuard($scratch))->toBeNull();
        expect(File::exists($scratch.'/cron.d/parity-scheduler'))->toBeTrue();
    } finally {
        removeScratchDir($scratch);
    }
});

it('starts a queue group that update added without starting', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch, ['queue_group_absent' => true]);

        $applied = recoveryApply($scratch);
        $operation = recoveryOperationIdIn($applied['output']);

        deployRecoveredRelease($scratch);

        // `update` added the group and left it STOPPED (autostart=false, or a
        // program that exits immediately): the explicit start still runs.
        $resumed = recoverHostRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation], [
            'RGTEST_SUPERVISOR_UPDATE_STATE' => 'STOPPED',
        ]);

        expect($resumed['exit'])->toBe(0, $resumed['output']);
        expect(File::get($scratch.'/supervisor.log'))
            ->toContain('supervisorctl update parity-queue')
            ->toContain('supervisorctl start parity-queue:*');
        expect($resumed['output'])->toContain('"queue":"running"');
    } finally {
        removeScratchDir($scratch);
    }
});

it('stays held when the queue group cannot be added at all', function (array $env, string $expected) {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch, ['queue_group_absent' => true]);

        $applied = recoveryApply($scratch);
        $operation = recoveryOperationIdIn($applied['output']);

        deployRecoveredRelease($scratch);

        $resumed = recoverHostRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation], $env);

        expect($resumed['exit'])->not->toBe(0);
        expect($resumed['output'])
            ->toContain($expected)
            ->toContain('the target stays held');

        // Held means held: the guard stands, and says the resume failed with
        // the target still held rather than pretending the recovery finished.
        expect(recoveryGuard($scratch))->toMatchArray(['status' => 'failed-held']);
        expect(File::exists($scratch.'/run/recoveries/parity-target/'.$operation))->toBeTrue();
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    'a configuration that no longer parses' => [
        ['RGTEST_SUPERVISOR_REREAD_EXIT' => '1'],
        'supervisorctl reread failed',
    ],
    'an update supervisord refuses' => [
        ['RGTEST_SUPERVISOR_UPDATE_EXIT' => '1'],
        'could not be added to the running Supervisor',
    ],
]);

it('names the trusted bundle when this copy of recover-host has no prepare-host beside it', function (string $mode) {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $arguments = ['--'.$mode, '--target', 'parity-target', '--backup', '20260115-023000'];

        // The installed operational bundle deliberately carries no bootstrap
        // tooling, which is exactly what an operator following a failed run
        // reaches for first.
        $result = recoverHostRun($scratch, $arguments, [
            'RATEGURU_RECOVER_PREPARE_HOST_BIN' => $scratch.'/bin/no-prepare-host-here',
        ]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('RECOVERY ACTION REQUIRED')
            ->toContain('Cause: this copy of recover-host has no prepare-host beside it')
            ->toContain('the installed operational bundle deliberately carries no bootstrap tooling')
            ->toContain('sudo <checkout>/infrastructure/scripts/recover-host --'.$mode.' --target parity-target --backup 20260115-023000')
            ->toContain('do not copy prepare-host into the operational bundle, and do not take one out of a release')
            ->toContain('--inspect, --resume and --verify')
            ->toContain('Runbook: infrastructure/runbooks/clean-host-recovery.md')
            ->toContain('Nothing was read, created or changed');

        // It is not reported as one host problem among others: no amount of
        // fixing the machine makes this copy able to answer.
        expect(str_contains($result['output'], 'RECOVERABLE:'))->toBeFalse('the check could not run at all');
        expect(File::exists($scratch.'/run/recoveries'))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
})->with(['check', 'apply']);

it('refuses a target held by a restore, and a target already being recovered', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        mkdir($scratch.'/run/restores/parity-target', 0o700, true);
        file_put_contents(
            $scratch.'/run/restores/parity-target/restore-guard',
            json_encode(['operation' => '20260115-120000-abc123', 'target' => 'parity-target', 'status' => 'held']),
        );

        $result = recoverHostRun($scratch, ['--check', '--target', 'parity-target', '--backup', '20260115-023000']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('a restore owns this target\'s data right now');
    } finally {
        removeScratchDir($scratch);
    }

    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        mkdir($scratch.'/run/recoveries/parity-target', 0o700, true);
        file_put_contents(
            $scratch.'/run/recoveries/parity-target/recovery-guard',
            json_encode(['operation' => '20260115-041233-9be21c', 'target' => 'parity-target', 'status' => 'awaiting-code']),
        );

        $result = recoverHostRun($scratch, ['--apply', '--target', 'parity-target', '--backup', '20260115-023000']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('is already being recovered by operation 20260115-041233-9be21c')
            ->toContain('rather than starting a second one');
    } finally {
        removeScratchDir($scratch);
    }
});

it('treats both guards at once as a hard failure with no continuation path', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        foreach (['restores' => 'restore-guard', 'recoveries' => 'recovery-guard'] as $namespace => $name) {
            mkdir($scratch.'/run/'.$namespace.'/parity-target', 0o700, true);
            file_put_contents(
                $scratch.'/run/'.$namespace.'/parity-target/'.$name,
                json_encode(['operation' => '20260115-041233-9be21c', 'target' => 'parity-target', 'status' => 'held']),
            );
        }

        $result = recoverHostRun($scratch, ['--check', '--target', 'parity-target', '--backup', '20260115-023000']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('BOTH a restore guard and a recovery guard')
            ->toContain('resolve it by hand');
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// --check is strictly read-only
// =============================================================================

it('creates no lock, workspace, guard, staging directory or database in check mode', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $result = recoverHostRun($scratch, ['--check', '--target', 'parity-target', '--backup', '20260115-023000']);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])->toContain('RECOVERABLE: YES');

        // Nothing at all under the run root, and nothing downloaded.
        expect(File::exists($scratch.'/run'))->toBeFalse('--check must create no lock file and no run root');
        expect(File::exists($scratch.'/recoveries'))->toBeFalse();
        expect(File::get($scratch.'/rclone.log'))->toBe('', '--check must download nothing');
        expect(File::exists($scratch.'/target/shared/storage/app'))->toBeFalse();
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(File::get($scratch.'/createdb.log'))->toBe('');
        expect(File::get($scratch.'/supervisor.log'))->not->toContain('supervisorctl stop');

        // No machine-readable result: --check is a read-only report, not a
        // terminal outcome a workflow branches on.
        expect($result['output'])->not->toContain('RATEGURU_RECOVER_RESULT=');
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Backup selection: offsite only, exact, no fallback
// =============================================================================

it('offers no source selector, no latest and no local fallback', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $withSource = recoverHostRun($scratch, [
            '--apply', '--target', 'parity-target', '--source', 'local', '--backup', '20260115-023000',
        ]);
        expect($withSource['exit'])->not->toBe(0);
        expect($withSource['output'])->toContain('unknown argument: --source');

        $withoutBackup = recoverHostRun($scratch, ['--apply', '--target', 'parity-target']);
        expect($withoutBackup['exit'])->not->toBe(0);
        expect($withoutBackup['output'])->toContain("there is no 'latest'");

        $malformed = recoverHostRun($scratch, ['--apply', '--target', 'parity-target', '--backup', 'latest']);
        expect($malformed['exit'])->not->toBe(0);
        expect($malformed['output'])->toContain('invalid backup ID');

        $impossible = recoverHostRun($scratch, ['--apply', '--target', 'parity-target', '--backup', '20261399-023000']);
        expect($impossible['exit'])->not->toBe(0);
        expect($impossible['output'])->toContain('not a real UTC timestamp');
    } finally {
        removeScratchDir($scratch);
    }

    // And the source is fixed in the script's CODE — the prose above it says
    // there is no selector, which is exactly the sentence a whole-file grep
    // would read as one.
    $source = executableSourceLines(File::get(recoverHostScript()));

    expect(preg_match_all('/--source \w+/', $source, $matches))->toBeGreaterThan(0);
    expect(array_values(array_unique($matches[0])))->toBe(['--source offsite']);
});

it('drives the existing fetch-backup and verify-backup rather than downloading anything itself', function () {
    $source = File::get(recoverHostScript());

    // No second implementation of any backup mechanic.
    foreach ([
        'rclone',
        'sha256sum',
        'pg_restore',
        'tar -xzf',
        'manifest.json',
        'SHA256SUMS',
    ] as $forbidden) {
        expect(executableSourceLines($source))->not->toContain($forbidden);
    }

    expect($source)
        ->toContain('"${RESTORE_FETCH_BACKUP_BIN}"')
        ->toContain('"${RESTORE_VERIFY_BACKUP_BIN}"')
        ->toContain('"${RESTORE_DATABASE_BIN}"')
        ->toContain('"${RESTORE_STORAGE_BIN}"')
        ->toContain('--for-restore');
});

it('refuses a backup whose release.json carries no full 40-character source_sha', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch, ['backup_options' => [
            'release_json' => ['project' => 'rateguru', 'release' => FIXTURE_RELEASE, 'source_sha' => 'abc1234'],
        ]]);

        $result = recoveryApply($scratch);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('a recovery rebuilds an exact commit and will not guess one');

        // Refused before anything canonical was replaced.
        expect(recoveryGuard($scratch))->toBeNull();
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses a backup whose checksums do not verify, before any activation', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch, ['backup_options' => ['corrupt_after_checksum' => true]]);

        $result = recoveryApply($scratch);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('failed SHA-256 verification');

        expect(recoveryGuard($scratch))->toBeNull();
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(File::exists($scratch.'/target/shared/storage/app'))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses a backup that belongs to another target, before any activation', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch, ['backup_options' => [
            'manifest' => backupManifestFixture(['manifest_schema_version' => 3, 'target' => 'somebody-else']),
        ]]);

        $result = recoveryApply($scratch);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('backup manifest target mismatch');

        expect(recoveryGuard($scratch))->toBeNull();
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Only a backup that carries its recovery material can recover a clean host
// =============================================================================

it('refuses a backup written before the recovery material existed, before staging any data', function () {
    $scratch = restoreScratchDir();

    try {
        // A perfectly valid schema 2 backup: restorable onto a live target,
        // and useless for a clean host, because the material Prepare Host
        // needs is not in it.
        recoveryFixture($scratch, ['backup_options' => ['schema' => 2]]);

        $result = recoveryApply($scratch);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('step: require a clean-host-recovery-capable backup')
            ->toContain('backup 20260115-023000 is not clean-host-recovery-capable: its manifest schema is 2, and a host recovery requires schema 3')
            ->toContain('No data was staged or activated')
            // The operator is told what to do, in the one shared format.
            ->toContain('RECOVERY ACTION REQUIRED')
            ->toContain('Cause: backup 20260115-023000 is a schema 2 backup, written before the recovery material joined the format')
            ->toContain('Then: re-run "Recover staging host" with mode=start and that backup\'s exact timestamp')
            ->toContain('Runbook: infrastructure/runbooks/clean-host-recovery.md')
            // No fallback to hand-supplied material is offered, anywhere.
            ->not->toContain('PREPARE_')
            ->not->toContain('--material-dir');

        // The refusal came after the download and before any mutation.
        $staged = mb_strpos($result['output'], 'step: stage backup');
        $required = mb_strpos($result['output'], 'step: require a clean-host-recovery-capable backup');

        expect($staged)->not->toBeFalse();
        expect($required)->not->toBeFalse();
        expect($staged)->toBeLessThan($required);
        expect($result['output'])->not->toContain('step: restore database');

        expect(recoveryGuard($scratch))->toBeNull();
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(trim(File::get($scratch.'/pg/tables/parity_db')))->toBe('0');
        expect(File::exists($scratch.'/target/shared/storage/app'))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('records the backup schema it accepted, in the state, the history and every report', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        expect($applied['exit'])->toBe(0, $applied['output']);
        $operation = recoveryOperationIdIn($applied['output']);

        expect($applied['output'])
            ->toContain('manifest schema 3')
            ->toContain('BACKUP SCHEMA: 3');

        expect(recoveryOperationState($scratch, $operation)['backup_schema'])->toBe('3');

        $inspected = recoverHostRun($scratch, ['--inspect', '--target', 'parity-target', '--operation', $operation]);
        expect($inspected['output'])->toContain('BACKUP SCHEMA: 3');

        $records = array_map(
            static fn (string $line): array => json_decode($line, true),
            array_filter(preg_split('/\R/', File::get($scratch.'/recoveries/recovery-history.jsonl'))),
        );
        expect(end($records))->toMatchArray(['backup_schema' => '3']);
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// The offsite-write hold: a recovered machine never writes into the namespace
// =============================================================================

it('holds the offsite writers before it downloads anything, and reports the hold everywhere', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $checked = recoverHostRun($scratch, ['--check', '--target', 'parity-target', '--backup', '20260115-023000']);
        expect($checked['exit'])->toBe(0, $checked['output']);
        expect($checked['output'])->toContain('OFFSITE WRITES: not yet held');
        expect(File::exists($scratch.'/run/offsite-write-hold'))->toBeFalse('--check places no hold');

        $result = recoveryApply($scratch);
        expect($result['exit'])->toBe(0, $result['output']);
        $operation = recoveryOperationIdIn($result['output']);

        // Placed right after the guard, before the first byte is downloaded.
        $guardStep = mb_strpos($result['output'], 'step: write recovery guard');
        $holdStep = mb_strpos($result['output'], 'step: hold offsite writes');
        $stageStep = mb_strpos($result['output'], 'step: stage backup');

        expect($guardStep)->not->toBeFalse();
        expect($holdStep)->not->toBeFalse();
        expect($stageStep)->not->toBeFalse();
        expect($guardStep)->toBeLessThan($holdStep);
        expect($holdStep)->toBeLessThan($stageStep);

        $hold = json_decode(File::get($scratch.'/run/offsite-write-hold'), true);
        expect($hold)->toMatchArray([
            'hold' => 'offsite-writes',
            'reason' => 'host-recovery',
            'target' => 'parity-target',
            'backup' => '20260115-023000',
            'created_by' => 'recover-host --apply',
        ]);
        expect(substr(sprintf('%o', fileperms($scratch.'/run/offsite-write-hold')), -4))->toBe('0600');

        // Reported by the apply, carried by the guard, the state, the history
        // and the machine-readable result.
        expect($result['output'])->toContain('OFFSITE WRITES: HELD');
        expect(recoveryGuard($scratch))->toMatchArray(['offsite_writes' => 'held']);
        expect(recoveryOperationState($scratch, $operation))->toMatchArray(['offsite_writes' => 'held']);

        preg_match('/RATEGURU_RECOVER_RESULT=(\{.*\})/', $result['output'], $matches);
        expect(json_decode($matches[1], true))->toMatchArray(['offsite_writes' => 'held']);

        $records = array_map(
            static fn (string $line): array => json_decode($line, true),
            array_filter(preg_split('/\R/', File::get($scratch.'/recoveries/recovery-history.jsonl'))),
        );
        expect(end($records))->toMatchArray(['offsite_writes' => 'held']);

        // The target's own scheduler entry is held aside exactly as before:
        // the fence changes nothing about what a recovery does to the runtime.
        expect(File::exists($scratch.'/cron.d/parity-scheduler'))->toBeFalse('the target scheduler is held aside as before');
    } finally {
        removeScratchDir($scratch);
    }
});

it('keeps a hold the preparation already placed, and never rewrites it', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        mkdir($scratch.'/run', 0o700, true);
        $planted = json_encode([
            'hold' => 'offsite-writes',
            'reason' => 'host-recovery',
            'target' => 'parity-target',
            'backup' => '20260115-023000',
            'created_by' => 'prepare-host --recovery-backup',
            'created_at' => '2026-01-15T03:00:00Z',
        ]);
        file_put_contents($scratch.'/run/offsite-write-hold', $planted);

        $result = recoveryApply($scratch);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])->toContain('offsite writes: HELD (already, by prepare-host --recovery-backup)');
        expect(File::get($scratch.'/run/offsite-write-hold'))->toBe($planted);
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to inspect, resume or verify a machine whose hold has gone', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        expect($applied['exit'])->toBe(0, $applied['output']);
        $operation = recoveryOperationIdIn($applied['output']);

        $marker = $scratch.'/run/offsite-write-hold';
        $document = File::get($marker);
        unlink($marker);

        // Read-only, so it never writes one: it refuses, and names the way
        // forward rather than leaving the operator without one.
        $inspected = recoverHostRun($scratch, ['--inspect', '--target', 'parity-target', '--operation', $operation]);
        expect($inspected['exit'])->not->toBe(0);
        expect($inspected['output'])
            ->toContain('RECOVERY ACTION REQUIRED')
            ->toContain('never release the hold to make a mode pass')
            ->toContain('the offsite-write hold is missing')
            ->toContain('recover-host --resume runs')
            ->toContain('re-place it by hand as root');

        deployRecoveredRelease($scratch);

        // The mutating stage re-establishes the fence before it starts a
        // single service, then finishes — and the hold stays.
        $resumed = recoverHostRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);
        expect($resumed['exit'])->toBe(0, $resumed['output']);
        expect($resumed['output'])
            ->toContain('step: hold offsite writes')
            ->toContain('offsite writes: HELD by recover-host --resume')
            ->toContain('OFFSITE WRITES: HELD');
        expect(File::exists($marker))->toBeTrue('a completed recovery never releases the hold');
        expect(json_decode(File::get($marker), true))->toMatchArray([
            'hold' => 'offsite-writes',
            'target' => 'parity-target',
            'backup' => '20260115-023000',
            'created_by' => 'recover-host --resume',
        ]);
        expect(substr(sprintf('%o', fileperms($marker)), -4))->toBe('0600');

        // And the original document is never rewritten when it is there: the
        // apply's own hold survives a resume untouched.
        expect($document)->not->toBe(File::get($marker));

        preg_match('/RATEGURU_RECOVER_RESULT=(\{.*\})/', $resumed['output'], $matches);
        expect(json_decode($matches[1], true))->toMatchArray(['status' => 'completed', 'offsite_writes' => 'held']);

        $verified = recoverHostRun($scratch, ['--verify', '--target', 'parity-target']);
        expect($verified['exit'])->toBe(0, $verified['output']);
        expect($verified['output'])->toContain('OFFSITE WRITES: HELD');

        unlink($marker);

        $verified = recoverHostRun($scratch, ['--verify', '--target', 'parity-target']);
        expect($verified['exit'])->not->toBe(0);
        expect($verified['output'])
            ->toContain('RECOVERY ACTION REQUIRED')
            ->toContain('Runbook: infrastructure/runbooks/clean-host-recovery.md')
            ->toContain('the offsite-write hold is missing')
            ->toContain('re-place it by hand as root');
    } finally {
        removeScratchDir($scratch);
    }
});

it('keeps the hold the apply placed when a resume finds it in place', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        expect($applied['exit'])->toBe(0, $applied['output']);
        $operation = recoveryOperationIdIn($applied['output']);

        $marker = $scratch.'/run/offsite-write-hold';
        $document = File::get($marker);

        deployRecoveredRelease($scratch);

        $resumed = recoverHostRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);
        expect($resumed['exit'])->toBe(0, $resumed['output']);
        expect($resumed['output'])->toContain('offsite writes: HELD (already, by recover-host --apply)');
        expect(File::get($marker))->toBe($document);
    } finally {
        removeScratchDir($scratch);
    }
});

it('never releases the offsite-write hold in any code path', function () {
    $source = executableSourceLines(File::get(recoverHostScript()));

    // Judged function by function: within a function that assigns a hold
    // path to a local, every later use of that local is a use of the hold —
    // so a removal through `rm "${marker}"` is caught — while the same local
    // name in another function (the guard's own marker) is judged by what it
    // holds there.
    preg_match_all('/^(\w+)\(\) \{\n(.*?)^\}/ms', $source, $functions, PREG_SET_ORDER);

    expect($functions)->not->toBeEmpty();

    $assigningFunctions = 0;

    foreach ($functions as [, $name, $body]) {
        preg_match_all('/(\w+)="?\$\(offsite_write_hold_file\b/', $body, $assigned);
        $holdVariables = array_values(array_unique($assigned[1]));

        if ($holdVariables !== []) {
            $assigningFunctions++;
        }

        foreach (preg_split('/\R/', $body) as $line) {
            $mentionsHold = str_contains($line, 'offsite_write_hold') || str_contains($line, 'offsite-write-hold');

            foreach ($holdVariables as $variable) {
                if (preg_match('/\$\{?'.preg_quote($variable, '/').'\b/', $line) === 1) {
                    $mentionsHold = true;
                }
            }

            if (! $mentionsHold) {
                continue;
            }

            // The only file operations allowed on a hold are creating it and
            // moving its own temporary file into place.
            expect(preg_match('/\brm\b/', $line) === 1 && ! str_contains($line, '.tmp'))->toBeFalse("{$name} must never remove the hold: {$line}");
            expect(preg_match('/\bmv\b/', $line) === 1 && ! str_contains($line, '.tmp'))->toBeFalse("{$name} must never move the hold away: {$line}");
            expect(preg_match('/\bunlink\b/', $line))->toBe(0, "{$name} must never unlink the hold: {$line}");
        }
    }

    expect($assigningFunctions)->toBeGreaterThan(0, 'no function assigns the hold path — the scan would be judging nothing');

    // The hold is composed by common, the one place the path is stated.
    expect($source)->toContain('offsite_write_hold_file "${RUN_ROOT}"')
        ->not->toContain("'offsite-write-hold'")
        ->not->toContain('"offsite-write-hold"');
});

// =============================================================================
// The .env equality rule
// =============================================================================

it('fails closed before any activation when the prepared environment differs from the backup', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch, ['backup_options' => [
            'environment' => preparedEnvironmentContents()."# a different environment\n",
        ]]);

        $result = recoveryApply($scratch);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('environment material: MISMATCH')
            ->toContain('Fix the external material the GitHub Environment supplies to Prepare Host');

        // Nothing was activated, and the prepared environment was not rewritten.
        expect(recoveryGuard($scratch))->toBeNull();
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(File::get($scratch.'/target/shared/.env'))->toBe(preparedEnvironmentContents());
    } finally {
        removeScratchDir($scratch);
    }
});

it('never prints the content, digest or size of either environment file', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch, ['backup_options' => [
            'environment' => "APP_ENV=staging\nDB_PASSWORD=a-completely-different-secret\n",
        ]]);

        $result = recoveryApply($scratch);

        expect($result['exit'])->not->toBe(0);

        foreach (['s3cr3t-not-logged', 'a-completely-different-secret', 'DB_PASSWORD'] as $secret) {
            expect($result['output'])->not->toContain($secret);
        }

        // No digest of either file, and no byte-length comparison. The only
        // long hex string a recovery ever prints is the backup's own
        // source_sha, which is public build identity and the whole point of
        // the operation — so it is excluded by name rather than by weakening
        // the rule.
        $hexRun = preg_replace('/\b'.FIXTURE_SOURCE_SHA.'\b/', '', $result['output']);

        expect($hexRun)->not->toMatch('/\b[0-9a-f]{32,}\b/');
        expect($result['output'])->not->toContain('bytes differ');
        expect($result['output'])->not->toContain('differ: byte');
    } finally {
        removeScratchDir($scratch);
    }

    // Structurally: the comparison is cmp -s, which prints nothing at all.
    expect(File::get(recoverHostScript()))
        ->toContain('cmp -s "${backup_env}" "${SHARED_ENV}"')
        ->not->toContain('sha256sum "${SHARED_ENV}"')
        ->not->toContain('diff ');
});

it('never applies environment.env or server-configuration.tar.gz', function () {
    $source = executableSourceLines(File::get(recoverHostScript()));

    // environment.env is READ, once, for the comparison — and never extracted,
    // installed or copied.
    expect(substr_count($source, 'environment.env'))->toBe(2);
    expect($source)->not->toContain('server-configuration.tar.gz');

    foreach (preg_split('/\R/', $source) as $line) {
        if (! str_contains($line, 'environment.env')) {
            continue;
        }

        foreach (['tar ', 'install ', 'cp ', 'mv ', '>'] as $mutation) {
            expect($line)->not->toContain($mutation, "environment.env must only be compared: {$line}");
        }
    }
});

// =============================================================================
// A successful --apply
// =============================================================================

it('restores the data and leaves the host deliberately not serving, awaiting code', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $result = recoveryApply($scratch);

        expect($result['exit'])->toBe(0, $result['output']);

        $operation = recoveryOperationIdIn($result['output']);

        expect($result['output'])
            ->toContain('DATA RESTORED: YES')
            ->toContain('CODE DEPLOYED: NO')
            ->toContain('TARGET SERVING: NO')
            ->toContain('RECOVERY STATUS: AWAITING CODE');

        // The guard goes down before the download and the preconditions are
        // re-read under the deployment lock, so this run met its own guard
        // half way through — and correctly did not refuse itself.
        expect($result['output'])->not->toContain('is already being recovered by operation');

        // Exactly one machine-readable result, carrying identity only.
        expect(substr_count($result['output'], 'RATEGURU_RECOVER_RESULT='))->toBe(1);

        preg_match('/RATEGURU_RECOVER_RESULT=(\{.*\})/', $result['output'], $matches);
        $payload = json_decode($matches[1], true);

        expect($payload)->toMatchArray([
            'status' => 'awaiting-code',
            'operation' => $operation,
            'target' => 'parity-target',
            'environment' => 'staging',
            'backup' => '20260115-023000',
            'backup_release' => FIXTURE_RELEASE,
            'required_source_sha' => FIXTURE_SOURCE_SHA,
            'data_restored' => true,
        ]);

        // The guard says awaiting-code and carries the exact required commit.
        expect(recoveryGuard($scratch))->toMatchArray([
            'operation' => $operation,
            'target' => 'parity-target',
            'backup' => '20260115-023000',
            'required_source_sha' => FIXTURE_SOURCE_SHA,
            'status' => 'awaiting-code',
        ]);

        // The data is canonical, and the pre-recovery copies are RETAINED.
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db', preRestoreDatabaseName($operation)]);
        expect(trim(File::get($scratch.'/pg/tables/parity_db')))->toBe('42');
        expect(File::exists($scratch.'/target/shared/storage/app/restored-marker.txt'))->toBeTrue();
        expect(File::exists($scratch.'/target/shared/storage/.pre-restore-app-'.$operation))->toBeTrue();

        // The runtime is held, target-scoped, with no maintenance mode at all.
        expect(File::exists($scratch.'/cron.d/parity-scheduler'))->toBeFalse();
        expect(File::get($scratch.'/supervisor.log'))->toContain('supervisorctl stop parity-queue:*');
        expect(trim(File::get($scratch.'/supervisor-state')))->toBe('STOPPED');
        expect(File::get($scratch.'/php.log'))->toBe('', 'a PRE_DEPLOY target has no artisan to run');
        expect(File::get($scratch.'/health-check.log'))->toBe('', '--apply never health-checks a host with no code');

        // No release pointers were invented.
        expect(File::exists($scratch.'/target/current'))->toBeFalse();
        expect(File::exists($scratch.'/target/previous'))->toBeFalse();

        // No emergency backup, and no restore-test of an empty prepared state.
        expect(File::get($scratch.'/backup.log'))->toBe('');
        expect(File::get($scratch.'/restore-test.log'))->toBe('');

        $state = recoveryOperationState($scratch, $operation);
        expect($state)->toMatchArray([
            'operation_kind' => 'host-recovery',
            'status' => 'awaiting-code',
            'phase' => 'awaiting-code',
            'source' => 'offsite',
        ]);

        // And the history journal recorded it, identity only.
        $history = json_decode(trim(File::get($scratch.'/recoveries/recovery-history.jsonl')), true);
        expect($history)->toMatchArray([
            'status' => 'awaiting-code',
            'operation' => $operation,
            'target' => 'parity-target',
            'backup' => '20260115-023000',
            'required_source_sha' => FIXTURE_SOURCE_SHA,
            'data_restored' => true,
        ]);
        expect(File::get($scratch.'/recoveries/recovery-history.jsonl'))->not->toContain('s3cr3t');
    } finally {
        removeScratchDir($scratch);
    }
});

it('writes its workspace, guard and history root root-only', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $result = recoveryApply($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        $operation = recoveryOperationIdIn($result['output']);
        $workspace = $scratch.'/run/recoveries/parity-target/'.$operation;

        expect(substr(sprintf('%o', fileperms($workspace)), -4))->toBe('0700');
        expect(substr(sprintf('%o', fileperms($workspace.'/state.json')), -4))->toBe('0600');
        expect(substr(sprintf('%o', fileperms($scratch.'/run/recoveries/parity-target/recovery-guard')), -4))->toBe('0600');
        expect(substr(sprintf('%o', fileperms($scratch.'/recoveries')), -4))->toBe('0700');
        expect(substr(sprintf('%o', fileperms($scratch.'/recoveries/recovery-history.jsonl')), -4))->toBe('0600');

        // A recovery lives in its own namespace, never the restore one.
        expect(File::exists($scratch.'/run/restores/parity-target/'.$operation))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('leaves the prepared canonical state untouched when staging fails', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $result = recoveryApply($scratch, ['RGTEST_PG_RESTORE_EXIT' => '3']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('the live database parity_db was never touched');

        // Nothing canonical replaced, and the guard — which went down before
        // the download — was cleared again, so the host is unowned.
        expect(recoveryGuard($scratch))->toBeNull();
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(trim(File::get($scratch.'/pg/tables/parity_db')))->toBe('0');

        // The runtime is exactly as Prepare Host left it: the hold is taken
        // only once the guard is on disk, immediately before activation.
        expect(File::exists($scratch.'/cron.d/parity-scheduler'))->toBeTrue();
        expect(File::get($scratch.'/supervisor.log'))->not->toContain('supervisorctl stop');
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Activation failure and compensation
// =============================================================================

it('puts the scheduler back when a failure lands after the hold but before any activation', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        // The FIRST catalog statement of activation — the connection barrier —
        // is unreachable, so the run fails with the runtime already held and
        // nothing canonical replaced.
        $result = recoveryApply($scratch, ['RGTEST_RENAME_FAIL_TO_PREFIX' => 'rateguru_pre_']);

        expect($result['exit'])->not->toBe(0);

        // Held, then released: the machine is a prepared PRE_DEPLOY host again.
        expect(File::get($scratch.'/supervisor.log'))->toContain('supervisorctl stop parity-queue:*');
        expect(File::exists($scratch.'/cron.d/parity-scheduler'))->toBeTrue();
        expect(recoveryGuard($scratch))->toBeNull();
    } finally {
        removeScratchDir($scratch);
    }
});

it('returns a failed activation to the prepared PRE_DEPLOY state', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        // The FIRST activation rename fails, so the prepared database is never
        // moved aside: compensation finds nothing to undo, which is the state
        // it must recognise rather than "repair".
        $result = recoveryApply($scratch, ['RGTEST_RENAME_FAIL_TO_PREFIX' => 'rateguru_pre_']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('could not rename parity_db aside')
            ->toContain('database compensation: nothing to undo');

        // The prepared, EMPTY database is canonical, and the staged copy is
        // gone rather than left behind on a replacement host.
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(trim(File::get($scratch.'/pg/tables/parity_db')))->toBe('0');

        // The prepared storage tree is empty again, and the staged one is gone.
        expect(File::exists($scratch.'/target/shared/storage/app/restored-marker.txt'))->toBeFalse();
        expect(glob($scratch.'/target/shared/storage/.restore-*') ?: [])->toBe([]);

        // The guard was cleared and the scheduler put back: the host is a
        // prepared PRE_DEPLOY machine again.
        expect(recoveryGuard($scratch))->toBeNull();
        expect(File::exists($scratch.'/cron.d/parity-scheduler'))->toBeTrue();

        // The queue is left STOPPED — it was never running before, and
        // starting it would invent a state the machine never had.
        expect(File::get($scratch.'/supervisor.log'))->not->toContain('supervisorctl start');

        $history = json_decode(trim(File::get($scratch.'/recoveries/recovery-history.jsonl')), true);
        expect($history['status'])->toBe('failed-recovered');
        expect($history['compensation_status'])->toBe('complete');
    } finally {
        removeScratchDir($scratch);
    }
});

it('tells the truth about rollback material it never created', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        // Every rename INTO the canonical name fails, so the activation cannot
        // finish and compensation cannot undo it either. Nothing was ever
        // successfully moved aside on the storage side, and "NO LONGER
        // AVAILABLE" would be as misleading here as "PRESENT" is after a
        // commit: an operator needs to know there was never anything to keep.
        $result = recoveryApply($scratch, ['RGTEST_RENAME_FAIL_TO_PREFIX' => 'parity_db']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('MANUAL RECOVERY REQUIRED');

        // The database WAS renamed aside before the second rename failed, so
        // that half is genuinely still there; the storage swap never began.
        expect($result['output'])
            ->toContain('pre-recovery database: PRESENT')
            ->toContain('pre-recovery storage : NONE');
    } finally {
        removeScratchDir($scratch);
    }
});

it('holds the host and keeps the guard when compensation cannot complete', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        // Every rename INTO the canonical name fails: the activation cannot
        // finish its second rename, and compensation cannot put the prepared
        // database back either.
        $result = recoveryApply($scratch, ['RGTEST_RENAME_FAIL_TO_PREFIX' => 'parity_db']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('MANUAL RECOVERY REQUIRED')
            ->toContain('pre-recovery database: PRESENT');

        expect(recoveryGuard($scratch))->toMatchArray(['status' => 'failed-held']);
        expect(File::exists($scratch.'/cron.d/parity-scheduler'))->toBeFalse('a held host keeps its scheduler out of cron.d');

        // The retained pre-recovery database still exists: nothing is dropped
        // while a recovery is held.
        expect(File::get($scratch.'/dropdb.log'))->not->toContain('rateguru_pre_');

        $history = json_decode(trim(File::get($scratch.'/recoveries/recovery-history.jsonl')), true);
        expect($history['status'])->toBe('failed-held');
        expect($history['compensation_status'])->toBe('incomplete');
    } finally {
        removeScratchDir($scratch);
    }
});

/** Simulates the controlled recovery deployment: a release, and current. */
function deployRecoveredRelease(string $scratch, string $sourceSha = FIXTURE_SOURCE_SHA, string $release = FIXTURE_RELEASE): void
{
    $root = $scratch.'/target';
    mkdir($root.'/releases/'.$release, 0o755, true);

    file_put_contents(
        $root.'/releases/'.$release.'/release.json',
        json_encode(['project' => 'rateguru', 'release' => $release, 'source_sha' => $sourceSha]),
    );

    symlink($root.'/releases/'.$release, $root.'/current');
}

// =============================================================================
// The guard owns the whole operation, not just its activation
// =============================================================================

it('owns the target from before the download, and lets it go again on failure', function () {
    $scratch = restoreScratchDir();

    try {
        // The backup is unusable, so this run dies during verification — long
        // before anything is staged, let alone activated. The guard has to
        // have existed by then: a recovery that only announces itself at
        // activation leaves its whole staging window open to a Prepare Host
        // apply or an operational-bundle reinstall.
        recoveryFixture($scratch, ['backup_options' => ['corrupt_after_checksum' => true]]);

        $result = recoveryApply($scratch);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('failed SHA-256 verification');

        // Written BEFORE the backup was even staged.
        $guardStep = mb_strpos($result['output'], 'recovery guard written');
        $stageStep = mb_strpos($result['output'], 'step: stage backup');

        expect($guardStep)->not->toBeFalse('the guard was never written');
        expect($stageStep)->not->toBeFalse();
        expect($guardStep)->toBeLessThan($stageStep);

        // Owned while it ran, unowned once it failed with nothing touched.
        expect(recoveryGuard($scratch))->toBeNull();
    } finally {
        removeScratchDir($scratch);
    }
});

it('still refuses a second apply while another operation owns the target', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        mkdir($scratch.'/run/recoveries/parity-target', 0o700, true);
        file_put_contents(
            $scratch.'/run/recoveries/parity-target/recovery-guard',
            json_encode(['operation' => '20260115-041233-9be21c', 'target' => 'parity-target', 'status' => 'in-progress']),
        );

        $result = recoveryApply($scratch);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('is already being recovered by operation 20260115-041233-9be21c');
    } finally {
        removeScratchDir($scratch);
    }
});

it('holds the host when the final proof fails, because the failure handler is still armed', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        // The queue reports STOPPED while it is being held and through the
        // activation, then RUNNING by the time the final proof looks — the
        // shape of "something started the worker during the recovery". It must
        // be a failure the handler sees, not a success that reports oddly.
        $result = recoveryApply($scratch, [
            'RGTEST_SUPERVISOR_FLIP_AFTER_STOP' => '1',
            'RGTEST_SUPERVISOR_FLIP_STATE' => 'RUNNING',
        ]);

        expect($result['exit'])->not->toBe(0);

        // The handler ran: the runtime was re-held, the guard says failed-held,
        // and nothing claimed the recovery finished.
        expect($result['output'])
            ->toContain('MANUAL RECOVERY REQUIRED')
            ->not->toContain('DATA RESTORED: YES')
            ->not->toContain('RATEGURU_RECOVER_RESULT=');

        expect(recoveryGuard($scratch))->toMatchArray(['status' => 'failed-held']);

        $history = json_decode(trim(File::get($scratch.'/recoveries/recovery-history.jsonl')), true);
        expect($history['status'])->toBe('failed-held');
    } finally {
        removeScratchDir($scratch);
    }
});

it('proves the hold while the failure handler is still armed', function () {
    // Structural, because the ordering is the whole property and a passing run
    // cannot show it: the proof must come BEFORE the operation declares itself
    // terminal and disarms its trap, or a failure there would leave the host
    // labelled awaiting-code with nothing re-holding it.
    $pipeline = shellFunctionBody(File::get(recoverHostScript()), 'perform_recovery');

    $proof = mb_strpos($pipeline, 'classify_recovery_stage');
    $terminal = mb_strpos($pipeline, 'RECOVERY_TERMINAL=true');
    $disarm = mb_strpos($pipeline, 'trap - ERR EXIT');

    expect($proof)->not->toBeFalse()
        ->and($terminal)->not->toBeFalse()
        ->and($disarm)->not->toBeFalse();

    expect($proof)->toBeLessThan($terminal);
    expect($terminal)->toBeLessThan($disarm);
});

// =============================================================================
// The hold is OBSERVED, never asserted
// =============================================================================

it('refuses to report a host as held once its queue is running again', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        expect($applied['exit'])->toBe(0, $applied['output']);
        $operation = recoveryOperationIdIn($applied['output']);

        // Between --apply and the historical build, something started the
        // queue. The hold this recovery depends on is gone.
        file_put_contents($scratch.'/supervisor-state', "RUNNING\n");

        $result = recoverHostRun($scratch, ['--inspect', '--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('is not fully STOPPED')
            ->toContain('the hold this recovery depends on is gone');

        // And it reports no result at all, so nothing downstream can read a
        // hold out of a run that proved the opposite.
        expect($result['output'])->not->toContain('RATEGURU_RECOVER_RESULT=');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to report a host as held once its scheduler is back in cron.d', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        $operation = recoveryOperationIdIn($applied['output']);

        // The cron entry reappeared — a scheduled writer can fire again.
        file_put_contents($scratch.'/cron.d/parity-scheduler', "* * * * * root true\n");

        $result = recoverHostRun($scratch, ['--inspect', '--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('a scheduled writer can fire against this host')
            ->toContain('the hold this recovery depends on is gone');
        expect($result['output'])->not->toContain('RATEGURU_RECOVER_RESULT=');
    } finally {
        removeScratchDir($scratch);
    }
});

it('reports queue and scheduler from what it observed, never from a constant', function () {
    // Structural, because the defect this replaced was invisible at runtime:
    // --inspect used to ASSIGN "stopped" and "held" without looking.
    $source = executableSourceLines(File::get(recoverHostScript()));

    // The two result fields are written in exactly two places, and both are
    // inside the function that just proved them.
    expect(substr_count($source, 'RECOVER_QUEUE="stopped"'))->toBe(1);
    expect(substr_count($source, 'RECOVER_SCHEDULER="held"'))->toBe(1);
    expect(substr_count($source, 'RECOVER_QUEUE="running"'))->toBe(1);
    expect(substr_count($source, 'RECOVER_SCHEDULER="present"'))->toBe(1);

    $held = shellFunctionBody(File::get(recoverHostScript()), 'assert_runtime_still_held');
    $resumed = shellFunctionBody(File::get(recoverHostScript()), 'assert_runtime_resumed');

    expect($held)
        ->toContain('observe_queue_program')
        ->toContain('scheduler_file_present')
        ->toContain('RECOVER_QUEUE="stopped"')
        ->toContain('RECOVER_SCHEDULER="held"');

    expect($resumed)
        ->toContain('observe_queue_program')
        ->toContain('scheduler_file_present')
        ->toContain('RECOVER_QUEUE="running"')
        ->toContain('RECOVER_SCHEDULER="present"');
});

// =============================================================================
// --inspect
// =============================================================================

it('reports what a host is waiting for without changing anything', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        expect($applied['exit'])->toBe(0, $applied['output']);
        $operation = recoveryOperationIdIn($applied['output']);

        $before = File::get($scratch.'/run/recoveries/parity-target/'.$operation.'/state.json');
        $guardBefore = File::get($scratch.'/run/recoveries/parity-target/recovery-guard');

        $result = recoverHostRun($scratch, ['--inspect', '--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('STATUS: AWAITING CODE')
            ->toContain('REQUIRED SOURCE SHA: '.FIXTURE_SOURCE_SHA)
            ->toContain('CURRENT RELEASE: absent')
            ->toContain('nothing was changed');

        expect(substr_count($result['output'], 'RATEGURU_RECOVER_RESULT='))->toBe(1);

        expect(File::get($scratch.'/run/recoveries/parity-target/'.$operation.'/state.json'))->toBe($before);
        expect(File::get($scratch.'/run/recoveries/parity-target/recovery-guard'))->toBe($guardBefore);
        expect(File::exists($scratch.'/cron.d/parity-scheduler'))->toBeFalse();
        expect(File::get($scratch.'/health-check.log'))->toBe('');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to inspect or resume an operation that belongs to a different target or does not exist', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $missing = recoverHostRun($scratch, [
            '--inspect', '--target', 'parity-target', '--operation', '20260115-041233-9be21c',
        ]);

        expect($missing['exit'])->not->toBe(0);
        expect($missing['output'])->toContain('recovery operation workspace does not exist');

        $applied = recoveryApply($scratch);
        $operation = recoveryOperationIdIn($applied['output']);

        // A restore operation's workspace is not a recovery's.
        restoreWorkspaceFixture($scratch, '20260115-120000-abc123');
        $wrongKind = recoverHostRun($scratch, [
            '--inspect', '--target', 'parity-target', '--operation', '20260115-120000-abc123',
        ]);

        expect($wrongKind['exit'])->not->toBe(0);
        expect($wrongKind['output'])->toContain('recovery operation workspace does not exist');

        // And a guard that names a different operation refuses the one asked for.
        $guard = json_decode(File::get($scratch.'/run/recoveries/parity-target/recovery-guard'), true);
        $guard['operation'] = '20260115-999999-aaaaaa';
        file_put_contents(
            $scratch.'/run/recoveries/parity-target/recovery-guard',
            json_encode($guard),
        );

        $mismatched = recoverHostRun($scratch, [
            '--inspect', '--target', 'parity-target', '--operation', $operation,
        ]);

        expect($mismatched['exit'])->not->toBe(0);
        expect($mismatched['output'])->toContain('is being recovered by operation 20260115-999999-aaaaaa');
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// --resume
// =============================================================================

it('finishes the recovery once the exact commit is deployed', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        expect($applied['exit'])->toBe(0, $applied['output']);
        $operation = recoveryOperationIdIn($applied['output']);

        deployRecoveredRelease($scratch);

        $result = recoverHostRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('HOST RECOVERY COMPLETE')
            ->toContain('HEALTH: PASS   QUEUE: RUNNING   SCHEDULER: PRESENT');

        expect(substr_count($result['output'], 'RATEGURU_RECOVER_RESULT='))->toBe(1);

        preg_match('/RATEGURU_RECOVER_RESULT=(\{.*\})/', $result['output'], $matches);
        expect(json_decode($matches[1], true))->toMatchArray([
            'status' => 'completed',
            'operation' => $operation,
            'target' => 'parity-target',
            'backup' => '20260115-023000',
            'current_release' => FIXTURE_RELEASE,
            'source_sha' => FIXTURE_SOURCE_SHA,
            'health' => 'pass',
            'queue' => 'running',
            'scheduler' => 'present',
        ]);

        // Only now are the retained pre-recovery copies committed.
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(File::get($scratch.'/dropdb.log'))->toContain(preRestoreDatabaseName($operation));
        expect(File::exists($scratch.'/target/shared/storage/.pre-restore-app-'.$operation))->toBeFalse();
        expect(File::exists($scratch.'/target/shared/storage/app/restored-marker.txt'))->toBeTrue();

        // Runtime restored, target-scoped.
        expect(File::exists($scratch.'/cron.d/parity-scheduler'))->toBeTrue();
        expect(trim(File::get($scratch.'/supervisor-state')))->toBe('RUNNING');
        expect(File::get($scratch.'/supervisor.log'))->toContain('supervisorctl start parity-queue:*');
        expect(File::get($scratch.'/health-check.log'))->toContain('health-check --target parity-target');

        // Guard cleared, workspace cleaned, history completed.
        expect(recoveryGuard($scratch))->toBeNull();
        expect(File::exists($scratch.'/run/recoveries/parity-target/'.$operation))->toBeFalse();

        $records = array_map(
            static fn (string $line): array => json_decode($line, true),
            array_filter(preg_split('/\R/', File::get($scratch.'/recoveries/recovery-history.jsonl'))),
        );
        expect(end($records))->toMatchArray([
            'status' => 'completed',
            'current_release' => FIXTURE_RELEASE,
            'current_source_sha' => FIXTURE_SOURCE_SHA,
        ]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('tells an operator a recovery is already finished rather than that its workspace is missing', function (string $mode) {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        $operation = recoveryOperationIdIn($applied['output']);

        deployRecoveredRelease($scratch);

        $resumed = recoverHostRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);
        expect($resumed['exit'])->toBe(0, $resumed['output']);

        // A completed recovery removes its own workspace and clears its own
        // guard, so the operation the finished run's summary names is exactly
        // the one an operator is most likely to hand back to continue-held.
        // Read as "workspace does not exist" that says the machine lost its
        // recovery, and the response to THAT is to start a second recovery
        // over a host already serving the right code on the right data.
        $again = recoverHostRun($scratch, [$mode, '--target', 'parity-target', '--operation', $operation]);

        expect($again['exit'])->not->toBe(0);
        expect($again['output'])
            ->toContain('RECOVERY ACTION REQUIRED')
            ->toContain('has already completed on parity-target')
            ->toContain("this host's own recovery journal records it")
            ->toContain('recover-host --verify --target parity-target')
            ->toContain('do not re-run the recovery workflow in either mode')
            ->not->toContain('recovery operation workspace does not exist');

        // It diagnoses; it never continues. Nothing on the host moved.
        expect(recoveryGuard($scratch))->toBeNull()
            ->and(File::exists($scratch.'/run/recoveries/parity-target/'.$operation))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
})->with(['--inspect', '--resume']);

it('reports a completion only for the operation its own journal records', function (string $mode) {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        $operation = recoveryOperationIdIn($applied['output']);

        deployRecoveredRelease($scratch);

        expect(recoverHostRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation])['exit'])
            ->toBe(0);

        // The host is now an ordinary serving target: no recovery guard, a
        // current release, a previous absent. That is what EVERY healthy
        // target looks like every day of its life — so it cannot be what
        // decides that some operation completed here. A typo, an operation
        // from another machine, or one that never existed would otherwise all
        // be announced as finished recoveries on a host that is serving.
        $stranger = '20260115-041233-9be21c';

        expect($stranger)->not->toBe($operation);

        $result = recoverHostRun($scratch, [$mode, '--target', 'parity-target', '--operation', $stranger]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('recovery operation workspace does not exist')
            ->not->toContain('has already completed');

        // And the journal is what the answer came from: the operation that
        // really did complete is still reported as complete.
        expect(recoverHostRun($scratch, [$mode, '--target', 'parity-target', '--operation', $operation])['output'])
            ->toContain('has already completed on parity-target');
    } finally {
        removeScratchDir($scratch);
    }
})->with(['--inspect', '--resume']);

it('still names a missing workspace plainly when the host is not a finished recovery', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        // A prepared, never-recovered host: no guard, nothing serving, and an
        // empty journal. An operation ID that was never here is exactly that
        // and nothing more, and inventing a completed recovery for it would be
        // worse than the plain refusal.
        $unknown = recoverHostRun($scratch, [
            '--inspect', '--target', 'parity-target', '--operation', '20260115-041233-9be21c',
        ]);

        expect($unknown['exit'])->not->toBe(0);
        expect($unknown['output'])
            ->toContain('recovery operation workspace does not exist')
            ->not->toContain('has already completed');
    } finally {
        removeScratchDir($scratch);
    }
});

it('reads the journal as evidence, not as a place to find an encouraging word', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        $operation = recoveryOperationIdIn($applied['output']);

        deployRecoveredRelease($scratch);

        expect(recoverHostRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation])['exit'])
            ->toBe(0);

        $journal = $scratch.'/recoveries/recovery-history.jsonl';
        $records = array_values(array_filter(preg_split('/\R/', File::get($journal))));

        // Every field of the match matters, and each is checked against a
        // record that differs in exactly one of them: a completed record for
        // another target, another operation, and a non-completed record for
        // this one. None of the three is this operation finishing here.
        $completed = json_decode((string) end($records), true);

        expect($completed)->toMatchArray(['status' => 'completed', 'target' => 'parity-target', 'operation' => $operation]);

        foreach ([
            'another target' => ['status' => 'completed', 'target' => 'other-target', 'operation' => $operation],
            'another operation' => ['status' => 'completed', 'target' => 'parity-target', 'operation' => '20260115-041233-9be21c'],
            'an unfinished attempt' => ['status' => 'failed-held', 'target' => 'parity-target', 'operation' => '20260115-041233-9be21c'],
        ] as $case => $overrides) {
            File::put($journal, json_encode([...$completed, ...$overrides])."\n");

            $result = recoverHostRun($scratch, [
                '--inspect', '--target', 'parity-target', '--operation', '20260115-041233-9be21c',
            ]);

            expect($result['output'])
                ->not->toContain('has already completed', "{$case} was read as this operation completing here");
        }

        // A journal that cannot be read answers "no", which is the direction
        // that refuses rather than the direction that announces.
        File::put($journal, "not json at all\n");

        expect(recoverHostRun($scratch, ['--inspect', '--target', 'parity-target', '--operation', $operation])['output'])
            ->toContain('recovery operation workspace does not exist')
            ->not->toContain('has already completed');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to resume when the deployed commit is not the one the data belongs to', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        $operation = recoveryOperationIdIn($applied['output']);

        deployRecoveredRelease($scratch, str_repeat('b', 40));

        $result = recoverHostRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('the target stays held, and no runtime was started');

        // Nothing was resumed, nothing was committed, the guard stands.
        expect(recoveryGuard($scratch))->toMatchArray(['status' => 'awaiting-code']);
        expect(File::exists($scratch.'/cron.d/parity-scheduler'))->toBeFalse();
        expect(File::get($scratch.'/supervisor.log'))->not->toContain('supervisorctl start');
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db', preRestoreDatabaseName($operation)]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to resume before any code has been deployed', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        $operation = recoveryOperationIdIn($applied['output']);

        $result = recoverHostRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('still has no current release');

        expect(recoveryGuard($scratch))->toMatchArray(['status' => 'awaiting-code']);
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to resume a host whose previous link was invented', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        $operation = recoveryOperationIdIn($applied['output']);

        deployRecoveredRelease($scratch);
        symlink($scratch.'/target/releases/'.FIXTURE_RELEASE, $scratch.'/target/previous');

        $result = recoverHostRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('a recovery deployment leaves no implicit rollback target');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to resume when the migration count changed, and keeps the host held', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        $operation = recoveryOperationIdIn($applied['output']);

        deployRecoveredRelease($scratch);

        // Something migrated the recovered data — which a recovery never does.
        file_put_contents($scratch.'/pg/migrations/parity_db', "23\n");

        $result = recoverHostRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('something migrated this data, which a recovery never does');

        expect(recoveryGuard($scratch))->toMatchArray(['status' => 'failed-held']);
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db', preRestoreDatabaseName($operation)]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('keeps the host held when the health check fails after code alignment', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        $operation = recoveryOperationIdIn($applied['output']);

        deployRecoveredRelease($scratch);

        $result = recoverHostRun($scratch, [
            '--resume', '--target', 'parity-target', '--operation', $operation,
        ], ['RGTEST_HEALTH_CHECK_EXIT' => '1']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('health check failed after the recovery deployment')
            ->toContain('MANUAL RECOVERY REQUIRED');

        // The guard is NOT cleared, the pre-recovery copies are NOT dropped,
        // and the code is not rolled back to nothing.
        expect(recoveryGuard($scratch))->toMatchArray(['status' => 'failed-held']);
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db', preRestoreDatabaseName($operation)]);
        expect(File::exists($scratch.'/target/current'))->toBeTrue();

        // The mirror image of the post-commit case: this failure lands BEFORE
        // the commit, so there IS rollback material and the report says so.
        expect($result['output'])
            ->toContain('pre-recovery database: PRESENT')
            ->toContain('pre-recovery storage : PRESENT');

        expect(recoveryOperationState($scratch, $operation))->toMatchArray([
            'retained_database' => 'present',
            'retained_storage' => 'present',
        ]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to complete when the scheduler is not actually back', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        $operation = recoveryOperationIdIn($applied['output']);

        deployRecoveredRelease($scratch);

        // The operation's own state says it never held the entry, so
        // release_scheduler_entry is a no-op — and the entry is genuinely
        // gone. A recovery that reported `scheduler: present` here would be
        // claiming something it never looked at.
        $statePath = $scratch.'/run/recoveries/parity-target/'.$operation.'/state.json';
        $state = json_decode(File::get($statePath), true);
        $state['scheduler_held_by_recovery'] = 'false';
        file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT));

        $result = recoverHostRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('a recovered target runs its own scheduler')
            ->toContain('will not commit its retained pre-recovery copies');

        // Held, and the retained pre-recovery copies survive.
        expect(recoveryGuard($scratch))->toMatchArray(['status' => 'failed-held']);
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db', preRestoreDatabaseName($operation)]);
        expect(File::get($scratch.'/dropdb.log'))->not->toContain('rateguru_pre_');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to complete when the queue did not come back', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        $operation = recoveryOperationIdIn($applied['output']);

        deployRecoveredRelease($scratch);

        // supervisorctl start takes effect but the worker lands in BACKOFF.
        $result = recoverHostRun($scratch, [
            '--resume', '--target', 'parity-target', '--operation', $operation,
        ], ['RGTEST_SUPERVISOR_START_STATE' => 'BACKOFF']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('did not reach RUNNING within the wait budget');

        expect(recoveryGuard($scratch))->toMatchArray(['status' => 'failed-held']);
        expect(File::get($scratch.'/dropdb.log'))->not->toContain('rateguru_pre_');
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// A recovery survives its runner dying between the deployment and the resume
// =============================================================================

it('walks both safe recovery stages, and refuses everything that is not one', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        expect($applied['exit'])->toBe(0, $applied['output']);
        $operation = recoveryOperationIdIn($applied['output']);

        $inspect = fn (): array => recoverHostRun(
            $scratch,
            ['--inspect', '--target', 'parity-target', '--operation', $operation],
        );

        // 1. No code yet.
        $awaiting = $inspect();
        expect($awaiting['exit'])->toBe(0, $awaiting['output']);
        expect($awaiting['output'])
            ->toContain('STATUS: AWAITING CODE')
            ->toContain('CURRENT RELEASE: absent');

        preg_match('/RATEGURU_RECOVER_RESULT=(\{.*\})/', $awaiting['output'], $matches);
        expect(json_decode($matches[1], true)['status'])->toBe('awaiting-code');

        // 2. The controlled recovery deployment succeeded and the runner then
        //    died. A legitimate, resumable state — the code is there, the queue
        //    is still stopped, the scheduler is still held, the guard still
        //    exists. An inspection that called this damage would strand exactly
        //    the failure the runbook promises to survive.
        deployRecoveredRelease($scratch);

        $ready = $inspect();
        expect($ready['exit'])->toBe(0, $ready['output']);
        expect($ready['output'])
            ->toContain('STATUS: READY TO RESUME')
            ->toContain('CURRENT RELEASE: '.FIXTURE_RELEASE)
            ->toContain('NEXT: recover-host --resume');

        preg_match('/RATEGURU_RECOVER_RESULT=(\{.*\})/', $ready['output'], $matches);
        expect(json_decode($matches[1], true))->toMatchArray([
            'status' => 'ready-to-resume',
            'operation' => $operation,
            'current_release' => FIXTURE_RELEASE,
            'source_sha' => FIXTURE_SOURCE_SHA,
            'queue' => 'stopped',
            'scheduler' => 'held',
        ]);

        // 3. The runtime half is non-negotiable in BOTH stages: code arriving
        //    is what should happen next, a worker starting is not.
        file_put_contents($scratch.'/supervisor-state', "RUNNING\n");

        $running = $inspect();
        expect($running['exit'])->not->toBe(0);
        expect($running['output'])->toContain('is not fully STOPPED');

        file_put_contents($scratch.'/supervisor-state', "STOPPED\n");

        // 4. And code that is not the code the recovered data belongs to is
        //    neither stage — it is a host serving something nobody asked for.
        unlink($scratch.'/target/current');
        deployRecoveredRelease($scratch, str_repeat('b', 40), 'v9.9.9-20260101-000000-bbbbbbb');

        $wrong = $inspect();
        expect($wrong['exit'])->not->toBe(0);
        expect($wrong['output'])->toContain('the target stays held, and no runtime was started');
        expect($wrong['output'])->not->toContain('RATEGURU_RECOVER_RESULT=');
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// The prepared storage baseline is reversible
// =============================================================================

it('leaves no storage tree behind when a recovery does not complete', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        // A prepared host has shared/storage but no shared/storage/app at all.
        expect(File::exists($scratch.'/target/shared/storage/app'))->toBeFalse();

        $result = recoveryApply($scratch, ['RGTEST_PG_RESTORE_EXIT' => '3']);

        expect($result['exit'])->not->toBe(0);

        // And it is ABSENT again afterwards: the baseline this recovery
        // created is removed, so the host is the PRE_DEPLOY shape it was.
        expect(File::exists($scratch.'/target/shared/storage/app'))->toBeFalse();
        expect(File::exists($scratch.'/target/shared/storage'))->toBeTrue();
        expect($result['output'])->toContain('removed the prepared storage baseline');
    } finally {
        removeScratchDir($scratch);
    }
});

it('returns the storage tree to ABSENT after a fully compensated activation', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $result = recoveryApply($scratch, ['RGTEST_RENAME_FAIL_TO_PREFIX' => 'rateguru_pre_']);

        expect($result['exit'])->not->toBe(0);
        expect(File::exists($scratch.'/target/shared/storage/app'))->toBeFalse();
        expect(recoveryGuard($scratch))->toBeNull();
    } finally {
        removeScratchDir($scratch);
    }
});

it('never removes a storage tree that holds anything', function () {
    // rmdir, never rm -rf: the removal cannot delete data even if every check
    // above it were wrong, because rmdir fails on a non-empty directory.
    $remover = shellFunctionBody(File::get(recoverHostScript()), 'discard_prepared_storage_baseline');

    expect($remover)
        ->toContain('rmdir "${LIVE_APP}"')
        ->not->toContain('rm -rf')
        ->not->toContain('rm -r ');

    // And only ever this target's own shared/storage/app, only when this
    // operation created it.
    expect($remover)
        ->toContain('[[ "${STORAGE_BASELINE_CREATED}" == true ]]')
        ->toContain('[[ "${LIVE_APP}" == "${STORAGE_ROOT}/app" ]]');
});

it('keeps a storage tree the recovery did not create', function () {
    $scratch = restoreScratchDir();

    try {
        // A host whose empty app/public already exists — a legitimate prepared
        // shape this recovery must not claim it created.
        recoveryFixture($scratch);
        mkdir($scratch.'/target/shared/storage/app/public', 0o2750, true);

        $result = recoveryApply($scratch, ['RGTEST_PG_RESTORE_EXIT' => '3']);

        expect($result['exit'])->not->toBe(0);
        expect(File::exists($scratch.'/target/shared/storage/app'))
            ->toBeTrue('a tree this recovery did not create is never removed');
        expect($result['output'])->not->toContain('removed the prepared storage baseline');
    } finally {
        removeScratchDir($scratch);
    }
});

it('keeps the guard until the completed recovery is durably recorded', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        $operation = recoveryOperationIdIn($applied['output']);
        deployRecoveredRelease($scratch);

        // The journal cannot be written — a full disk, a read-only mount, an
        // I/O error. The guard is the last safety barrier on a recovered host,
        // so it must still be standing: clearing it first and then failing here
        // would leave the recovery unfinished, the runtime re-held, the
        // retained pre-recovery copies already dropped, and every ordinary
        // operation free to walk onto the host.
        $blocked = $scratch.'/blocked';
        file_put_contents($blocked, "not a directory\n");

        $result = recoverHostRun($scratch, [
            '--resume', '--target', 'parity-target', '--operation', $operation,
        ], ['RATEGURU_RECOVERY_HISTORY_ROOT' => $blocked.'/recoveries']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('could not append the recovery history record')
            ->toContain('MANUAL RECOVERY REQUIRED');

        // Fail-closed: the guard stands, and it says so.
        expect(recoveryGuard($scratch))->toMatchArray(['status' => 'failed-held']);

        // And the runtime was re-held rather than left serving.
        expect(File::exists($scratch.'/cron.d/parity-scheduler'))->toBeFalse();

        // The report must be TRUE about the rollback material. Both commits ran
        // before this failure, so there is nothing left to go back to — and an
        // operator told otherwise would plan a rollback onto material that no
        // longer exists.
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(File::exists($scratch.'/target/shared/storage/.pre-restore-app-'.$operation))->toBeFalse();

        expect($result['output'])
            ->toContain('pre-recovery database: NO LONGER AVAILABLE')
            ->toContain('pre-recovery storage : NO LONGER AVAILABLE')
            ->not->toContain('were NOT dropped');

        // And durably, for an operator who arrives without the report.
        expect(recoveryOperationState($scratch, $operation))->toMatchArray([
            'retained_database' => 'absent',
            'retained_storage' => 'absent',
        ]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('clears the guard only after every durable record is written', function () {
    // Structural, because the ordering is the property and a passing run cannot
    // show it. The guard's removal is the commit point of the whole operation:
    // anything that fails before it must leave the host fail-closed, so nothing
    // that can fail may come after it.
    $resume = shellFunctionBody(File::get(recoverHostScript()), 'perform_resume');

    $positions = [
        'commit' => mb_strpos($resume, 'step "commit"'),
        'state' => mb_strpos($resume, 'step "record the completed recovery"'),
        'history' => mb_strpos($resume, 'append_recovery_history completed'),
        'clear' => mb_strpos($resume, 'clear_recovery_guard'),
        'terminal' => mb_strpos($resume, 'RECOVERY_TERMINAL=true'),
        'disarm' => mb_strpos($resume, 'trap - ERR EXIT'),
    ];

    foreach ($positions as $name => $position) {
        expect($position)->not->toBeFalse("perform_resume has no {$name} step");
    }

    expect(array_values($positions))
        ->toBe(collect($positions)->sort()->values()->all(), 'the resume commit sequence is out of order');
});

it('refuses to report a successful apply whose guard was not re-labelled', function () {
    // A successful --apply means exactly one thing: state awaiting-code, guard
    // awaiting-code, queue STOPPED, scheduler HELD, current ABSENT. Reporting
    // success with the guard still at in-progress would hand an operator a
    // recovery the controlled deployment then refuses — correctly, and
    // confusingly. It is a hard failure, taken while the handler is armed.
    $pipeline = shellFunctionBody(File::get(recoverHostScript()), 'perform_recovery');

    expect($pipeline)
        ->toContain('write_recovery_guard awaiting-code')
        ->toContain('refusing to report a success the controlled recovery deployment would then refuse');

    // Every guard write in the pipeline is fatal on failure — none is a warning
    // the run then walks past.
    foreach (preg_split('/\R/', $pipeline) as $index => $line) {
        if (! str_contains($line, 'write_recovery_guard')) {
            continue;
        }

        $continuation = preg_split('/\R/', $pipeline)[$index + 1] ?? '';

        // toContain is variadic in Pest, so a second argument would be read as
        // another needle rather than as a message.
        expect(str_contains($continuation, '|| fail'))
            ->toBeTrue("a guard write in perform_recovery is not fatal: {$line}");
    }

    expect(mb_strpos($pipeline, 'write_recovery_guard awaiting-code'))
        ->toBeLessThan(mb_strpos($pipeline, 'RECOVERY_TERMINAL=true'));
});

// =============================================================================
// --verify
// =============================================================================

it('verifies a fully recovered host, and reports its absent previous link as a fact', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        $operation = recoveryOperationIdIn($applied['output']);
        deployRecoveredRelease($scratch);

        expect(recoverHostRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation])['exit'])
            ->toBe(0);

        $result = recoverHostRun($scratch, ['--verify', '--target', 'parity-target']);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('RECOVERED: YES')
            ->toContain('PREVIOUS: absent');

        preg_match('/RATEGURU_RECOVER_RESULT=(\{.*\})/', $result['output'], $matches);
        expect(json_decode($matches[1], true))->toMatchArray([
            'status' => 'verified',
            'target' => 'parity-target',
            'health' => 'pass',
            'queue' => 'running',
            'scheduler' => 'present',
            // Stated, not left to a reader's optimism: everything downstream
            // announces the final contract as fact, so the field it announces
            // has to be in the result it announces it from.
            'previous' => 'absent',
        ]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to verify a recovered host that carries a previous release link', function (string $shape) {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        $operation = recoveryOperationIdIn($applied['output']);
        deployRecoveredRelease($scratch);

        expect(recoverHostRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation])['exit'])
            ->toBe(0);

        // A recovered host has had exactly one deployment and therefore no
        // rollback target. A `previous` means something deployed here after
        // the recovery, an adoption began, or this is not the host the
        // verification thinks it is — and rolling "back" from a recovery to
        // whatever that link names would serve code the recovered data does
        // not belong to.
        $previous = $scratch.'/target/previous';

        match ($shape) {
            // The ordinary case: a real link into the releases tree.
            'a link to a real release' => symlink($scratch.'/target/releases/'.FIXTURE_RELEASE, $previous),
            // The case `-e` alone would miss: it follows the link, finds
            // nothing, and reports the host clean.
            'a broken link' => symlink($scratch.'/target/releases/removed-by-hand', $previous),
            'a directory' => mkdir($previous),
        };

        $result = recoverHostRun($scratch, ['--verify', '--target', 'parity-target']);

        expect($result['exit'])->not->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('carries a previous release link')
            ->not->toContain('RECOVERED: YES');

        // A refusal, not a repair: it says what it found and leaves it there.
        expect(file_exists($previous) || is_link($previous))->toBeTrue('the verification removed what it refused');
    } finally {
        removeScratchDir($scratch);
    }
})->with(['a link to a real release', 'a broken link', 'a directory']);

it('refuses to verify a host that still carries a recovery guard', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        $applied = recoveryApply($scratch);
        expect($applied['exit'])->toBe(0, $applied['output']);

        $result = recoverHostRun($scratch, ['--verify', '--target', 'parity-target']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('still carries a recovery guard');
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Isolation
// =============================================================================

it('never stops a global service, never runs a migration and never rotates a secret', function () {
    $source = executableSourceLines(File::get(recoverHostScript()));

    foreach ([
        'systemctl stop',
        'systemctl restart',
        'service cron',
        'supervisorctl stop all',
        'supervisorctl shutdown',
        'artisan migrate',
        'migrate --force',
        'ALTER ROLE',
        'ALTER USER',
        'DROP SCHEMA',
        'CREATE SCHEMA',
        'PASSWORD',
        'certbot',
        'cloudflare',
        'route53',
    ] as $forbidden) {
        expect($source)->not->toContain($forbidden, "recover-host must never: {$forbidden}");
    }

    // Everything Supervisor-shaped is scoped to this target's own program.
    preg_match_all('/supervisorctl[^\n]*/', $source, $matches);
    foreach ($matches[0] as $line) {
        expect($line)->toContain('${SUPERVISOR_PROGRAM}');
    }
});

it('leaves another active target and every host-global service untouched', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        // A second target's cron entry, and a host-global one.
        file_put_contents($scratch.'/cron.d/other-target-scheduler', "* * * * * root true\n");
        file_put_contents($scratch.'/cron.d/rateguru-backups', "30 2 * * * root true\n");

        $result = recoveryApply($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        expect(File::exists($scratch.'/cron.d/other-target-scheduler'))->toBeTrue();
        expect(File::exists($scratch.'/cron.d/rateguru-backups'))->toBeTrue();

        foreach (preg_split('/\R/', File::get($scratch.'/supervisor.log')) as $line) {
            if (trim($line) === '') {
                continue;
            }

            expect($line)->toContain('parity-queue');
        }
    } finally {
        removeScratchDir($scratch);
    }
});
