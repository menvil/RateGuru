<?php

use Illuminate\Support\Facades\File;

/**
 * Recover Host: the preconditions of a recovery.
 *
 * Executes the real shipped infrastructure/scripts/recover-host against a
 * file-backed fake PostgreSQL, a fake offsite remote, a fake Supervisor and
 * the REAL backup primitives, exactly as RecoverHostTest does, and proves
 * what a recovery establishes before it changes anything: the prepared,
 * EMPTY contract it demands of the target, the PRE_DEPLOY Supervisor state a
 * never-deployed host is in and how that state is judged, the strictly
 * read-only --check, the exact offsite-only backup selection, the recovery
 * material a backup must carry, and the .env equality rule. The apply itself
 * is in RecoverHostTest, finishing a recovery in RecoverHostResumeTest; the
 * harness they share lives in tests/Pest.php.
 */

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
})->with(['check', 'apply'])->skip(fn (): bool => testProcessIsRoot(), 'the root gate cannot be observed as root');

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
        $applied = recoveryApplied($scratch, ['queue_group_absent' => true]);
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
        $applied = recoveryApplied($scratch, ['queue_group_absent' => true]);
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
            expect(str_contains($line, $mutation))
                ->toBeFalse("environment.env must only be compared: {$line}");
        }
    }
});
