<?php

use Illuminate\Support\Facades\File;

/**
 * Recover Host: what a recovery leaves behind when it fails.
 *
 * Executes the real shipped infrastructure/scripts/recover-host against the
 * same simulated replacement host RecoverHostTest uses, and makes it fail at
 * the moments its terminal handler has a decision to make: inside the runtime
 * hold, after a compensated activation, while the guard is being written, and
 * while a resume is putting the runtime back or taking it down again. Each
 * failure is injected through the host — a cron.d that stops accepting the
 * scheduler entry, a guard path that cannot be written, a stubbed tool that
 * fails — never through a modified copy of the script.
 *
 * The contract under test is the handler's, stated in recover-host itself: a
 * failure that left nothing canonical replaced and the runtime back is
 * `failed` and lets the target go; anything it cannot put back keeps the
 * target held, keeps the guard, and says MANUAL RECOVERY REQUIRED; and a guard
 * that cannot be written stops the run before the step it was meant to cover.
 */

/** The newest record in the recovery journal: the outcome of the last run. */
function lastRecoveryRecord(string $scratch): array
{
    $lines = array_filter(preg_split('/\R/', File::get($scratch.'/recoveries/recovery-history.jsonl')));

    return json_decode((string) end($lines), true);
}

/** Where a recovery keeps the scheduler cron entry it moved out of cron.d. */
function recoveryHeldSchedulerEntry(string $scratch, string $operation): string
{
    return $scratch.'/run/recoveries/parity-target/'.$operation.'/scheduler-hold/parity-scheduler';
}

/**
 * The scheduler cron entry stops being restorable once the hold has moved it
 * out: every later write into cron.d fails, the way a full or read-only
 * /etc would refuse it. Riding on the `supervisorctl stop` call puts the
 * moment exactly after the entry has been held and before anything can try to
 * put it back.
 *
 * @return array<string, string>
 */
function cronDirectoryClosesAfterTheHold(string $scratch): array
{
    return [
        'RATEGURU_RESTORE_SUPERVISORCTL_BIN' => executableWithHook(
            $scratch.'/bin/supervisorctl',
            'stop',
            'chmod 0555 '.escapeshellarg($scratch.'/cron.d'),
        ),
    ];
}

// =============================================================================
// A failure inside the runtime hold, before anything canonical is replaced
// =============================================================================

it('returns a host whose hold could not be confirmed to the prepared state, scheduler entry included', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);
        $entry = File::get($scratch.'/cron.d/parity-scheduler');
        $mode = fileperms($scratch.'/cron.d/parity-scheduler');

        // The queue stop takes effect but lands in FATAL instead of STOPPED, so
        // the run fails inside the hold: the scheduler entry already moved out
        // of cron.d, the backup staged, nothing canonical replaced yet.
        $result = recoveryApply($scratch, ['RGTEST_SUPERVISOR_STOP_STATE' => 'FATAL']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('did not reach STOPPED within the wait budget')
            ->not->toContain('MANUAL RECOVERY REQUIRED');

        // The entry the hold took is back, byte for byte and with its mode:
        // a prepared host runs its own scheduler.
        expect(File::get($scratch.'/cron.d/parity-scheduler'))->toBe($entry);
        expect(fileperms($scratch.'/cron.d/parity-scheduler'))->toBe($mode);

        // Nothing staged is left behind, and the prepared database is still the
        // canonical, empty one.
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(trim(File::get($scratch.'/pg/tables/parity_db')))->toBe('0');
        expect(glob($scratch.'/target/shared/storage/.restore-*') ?: [])->toBe([]);

        // The storage baseline this run created is gone again: a prepared host
        // has no shared/storage/app until its first deployment.
        expect(file_exists($scratch.'/target/shared/storage/app'))->toBeFalse();

        // The queue was not running before, so it is not started now.
        expect(File::get($scratch.'/supervisor.log'))->not->toContain('supervisorctl start');

        // Back to PRE_DEPLOY, so nothing owns the target any more.
        expect(recoveryGuard($scratch))->toBeNull();
        expect(lastRecoveryRecord($scratch))->toMatchArray([
            'status' => 'failed',
            'failed_step' => 'hold the target runtime',
            'data_restored' => false,
        ]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('keeps the host held and owned when its scheduler entry cannot be put back after a failure', function (array $failure, string $failedStep, string $compensation) {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);
        $entry = File::get($scratch.'/cron.d/parity-scheduler');

        $result = recoveryApply($scratch, $failure + cronDirectoryClosesAfterTheHold($scratch));
        $operation = lastRecoveryRecord($scratch)['operation'];

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('ERROR: could not restore the target scheduler cron entry')
            ->toContain('MANUAL RECOVERY REQUIRED')
            ->toContain('# failed step: '.$failedStep)
            ->toContain('# compensation: '.$compensation);

        // Not put back, and not lost either: the entry is exactly where the
        // hold put it, for whoever finishes this by hand.
        expect(File::exists($scratch.'/cron.d/parity-scheduler'))->toBeFalse();
        expect(File::get(recoveryHeldSchedulerEntry($scratch, $operation)))->toBe($entry);

        // The prepared database is canonical either way — nothing was activated,
        // or the activation was compensated — but a host whose runtime is not
        // what Prepare Host left is not a prepared host. The guard stays, and
        // says what happened rather than what was attempted.
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(recoveryGuard($scratch))->toMatchArray([
            'operation' => $operation,
            'status' => 'failed-held',
        ]);
        expect(lastRecoveryRecord($scratch))->toMatchArray([
            'status' => 'failed-held',
            'failed_step' => $failedStep,
            'compensation_status' => $compensation,
        ]);
        expect(File::get($scratch.'/supervisor.log'))->not->toContain('supervisorctl start');

        // And a failed-held recovery is not one a resume may finish: it is
        // refused, and nothing it found is changed.
        deployRecoveredRelease($scratch);
        $resumed = recoverHostRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);

        expect($resumed['exit'])->not->toBe(0);
        expect($resumed['output'])->toContain("with status 'failed-held': a recovery failed leaving data that may already be canonical");
        expect(recoveryGuard($scratch))->toMatchArray(['status' => 'failed-held']);
        expect(File::get(recoveryHeldSchedulerEntry($scratch, $operation)))->toBe($entry);
    } finally {
        @chmod($scratch.'/cron.d', 0o755);
        removeScratchDir($scratch);
    }
})->with([
    'inside the hold' => [['RGTEST_SUPERVISOR_STOP_STATE' => 'FATAL'], 'hold the target runtime', 'not-required'],
    // The first activation rename fails, so compensation has nothing to undo
    // and completes: everything but the scheduler entry is back.
    'after a compensated activation' => [['RGTEST_RENAME_FAIL_TO_PREFIX' => 'rateguru_pre_'], 'activate database', 'complete'],
]);

it('says the scheduler entry is not held when putting it back left it in cron.d', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        // Putting the entry back moves it into cron.d and then restores its
        // owner. Recorded as root's, that owner cannot be restored by this
        // unprivileged test, so the move happens and the chown fails: the
        // entry ends up in cron.d, which is not where a held entry is.
        $ownedByRoot = 'for state in '.escapeshellarg($scratch.'/run/recoveries/parity-target').'/*/state.json; do '
            .'jq \'.scheduler_owner = "0:0"\' "$state" > "$state.next" && mv "$state.next" "$state"; done';

        $result = recoveryApply($scratch, [
            'RGTEST_SUPERVISOR_STOP_STATE' => 'FATAL',
            'RATEGURU_RESTORE_SUPERVISORCTL_BIN' => executableWithHook($scratch.'/bin/supervisorctl', 'stop', $ownedByRoot),
        ]);
        $operation = lastRecoveryRecord($scratch)['operation'];
        $inCron = $scratch.'/cron.d/parity-scheduler';
        $held = recoveryHeldSchedulerEntry($scratch, $operation);

        expect($result['exit'])->not->toBe(0);
        expect(File::exists($inCron))->toBeTrue();
        expect($result['output'])
            ->toContain('ERROR: could not restore the scheduler cron entry ownership')
            ->toContain('MANUAL RECOVERY REQUIRED')
            ->toContain("# but its scheduler cron entry is NOT held: it is in {$inCron}.")
            ->toContain("#   mv {$inCron} {$held}")
            ->not->toContain('is held out of');

        // The command it prints is the one that puts the operation's state
        // back: the entry where this operation keeps it, and cron.d without it.
        exec("mv {$inCron} {$held} 2>&1", $moveOutput, $moveExit);
        expect($moveExit)->toBe(0, implode("\n", $moveOutput));
        expect(File::exists($inCron))->toBeFalse()
            ->and(File::exists($held))->toBeTrue();
    } finally {
        removeScratchDir($scratch);
    }
})->skip(fn (): bool => testProcessIsRoot(), 'root can give the entry back to root, so the chown this case needs to fail would succeed');

// =============================================================================
// A guard that cannot be written stops the step it was meant to cover
// =============================================================================

/*
 * The guard is written through a temporary file beside it. A directory in that
 * place makes the write fail for any owner — the shape of a full or read-only
 * run root, which a process that owns the whole scratch tree cannot otherwise
 * be given.
 */

it('refuses to start, before downloading anything, when its guard cannot be written', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);
        mkdir($scratch.'/run/recoveries/parity-target/recovery-guard.tmp', 0o700, true);

        $result = recoveryApply($scratch);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('ERROR: could not write the recovery guard')
            ->toContain('refusing to start a recovery that nothing would then stop an ordinary operation from converging underneath');

        // Nothing was fetched, staged, held or created.
        expect(File::get($scratch.'/rclone.log'))->toBe('');
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(file_exists($scratch.'/target/shared/storage/app'))->toBeFalse();
        expect(File::exists($scratch.'/cron.d/parity-scheduler'))->toBeTrue();
        expect(File::get($scratch.'/supervisor.log'))->not->toContain('supervisorctl stop');

        expect(recoveryGuard($scratch))->toBeNull();
        expect(lastRecoveryRecord($scratch))->toMatchArray([
            'status' => 'failed',
            'failed_step' => 'write recovery guard',
        ]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('activates nothing, and discards what it staged, when the guard cannot record the commit', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryFixture($scratch);

        // The first guard write succeeds; the re-label with the verified commit,
        // the hard prerequisite of activation, is the one that fails.
        $storage = executableWithHook(
            patchedInfraScript($scratch, 'restore-storage'),
            '--stage',
            'mkdir '.escapeshellarg($scratch.'/run/recoveries/parity-target/recovery-guard.tmp'),
        );

        $result = recoveryApply($scratch, ['RATEGURU_RESTORE_STORAGE_BIN' => $storage]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('ERROR: could not write the recovery guard')
            ->toContain('refusing to activate recovered data whose guard cannot say which code it belongs to')
            ->not->toContain('step: activate database');

        // The runtime was never held, the staged database and storage tree are
        // gone, and the baseline this run created is removed again.
        expect(File::exists($scratch.'/cron.d/parity-scheduler'))->toBeTrue();
        expect(File::get($scratch.'/supervisor.log'))->not->toContain('supervisorctl stop');
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(trim(File::get($scratch.'/pg/tables/parity_db')))->toBe('0');
        expect(glob($scratch.'/target/shared/storage/.restore-*') ?: [])->toBe([]);
        expect(file_exists($scratch.'/target/shared/storage/app'))->toBeFalse();

        // The in-progress guard that covered the staging is released with it.
        expect($result['output'])->toContain('recovery guard cleared');
        expect(recoveryGuard($scratch))->toBeNull();
        expect(lastRecoveryRecord($scratch))->toMatchArray([
            'status' => 'failed',
            'failed_step' => 'record the required commit in the recovery guard',
        ]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('keeps the guard it has, and still reports the failure, when it cannot re-label the guard', function () {
    $scratch = restoreScratchDir();

    try {
        $applied = recoveryApplied($scratch);
        $operation = recoveryOperationIdIn($applied['output']);
        deployRecoveredRelease($scratch);

        mkdir($scratch.'/run/recoveries/parity-target/recovery-guard.tmp', 0o700);

        $result = recoverHostRun($scratch, [
            '--resume', '--target', 'parity-target', '--operation', $operation,
        ], ['RGTEST_HEALTH_CHECK_EXIT' => '1']);

        // The handler's own failure is loud and never replaces the diagnosis
        // it is reporting.
        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('health check failed after the recovery deployment')
            ->toContain('ERROR: could not write the recovery guard')
            ->toContain('MANUAL RECOVERY REQUIRED');

        // The guard it could not re-label is still on disk, so every ordinary
        // operation still refuses the target; the runtime is held again.
        expect(recoveryGuard($scratch))->toMatchArray(['operation' => $operation, 'status' => 'awaiting-code']);
        expect(File::exists($scratch.'/cron.d/parity-scheduler'))->toBeFalse();
        expect(trim(File::get($scratch.'/supervisor-state')))->toBe('STOPPED');

        // The operation's own state carries the outcome the guard could not,
        // and a guard and a state that disagree authorize nothing.
        expect(recoveryOperationState($scratch, $operation))->toMatchArray(['status' => 'failed-held']);
        expect(lastRecoveryRecord($scratch))
            ->toMatchArray(['status' => 'failed-held', 'failed_step' => 'health check']);

        $inspected = recoverHostRun($scratch, ['--inspect', '--target', 'parity-target', '--operation', $operation]);

        expect($inspected['exit'])->not->toBe(0);
        expect($inspected['output'])
            ->toContain("has status 'failed-held', not 'awaiting-code'")
            ->not->toContain('RATEGURU_RECOVER_RESULT=');
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// A resume that cannot put the scheduler entry back
// =============================================================================

it('keeps a resuming host held when its scheduler entry cannot be put back', function (Closure $breakTheEntry, string $error) {
    $scratch = restoreScratchDir();

    try {
        $applied = recoveryApplied($scratch);
        $operation = recoveryOperationIdIn($applied['output']);
        deployRecoveredRelease($scratch);

        $breakTheEntry($scratch, recoveryHeldSchedulerEntry($scratch, $operation));

        $result = recoverHostRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain($error)
            ->toContain("could not restore parity-target's scheduler cron entry — the target stays held")
            ->toContain('MANUAL RECOVERY REQUIRED');

        // The scheduler comes back before the queue, so a queue is never
        // started without it, and no entry is invented in its place.
        expect(File::get($scratch.'/supervisor.log'))->not->toContain('supervisorctl start');
        expect(File::exists($scratch.'/cron.d/parity-scheduler'))->toBeFalse();

        // Held, owned, and with its rollback material intact.
        expect(recoveryGuard($scratch))->toMatchArray(['status' => 'failed-held']);
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db', preRestoreDatabaseName($operation)]);
        expect($result['output'])
            ->toContain('pre-recovery database: PRESENT')
            ->toContain('pre-recovery storage : PRESENT');
        expect(lastRecoveryRecord($scratch))
            ->toMatchArray(['status' => 'failed-held', 'failed_step' => 'restore the target scheduler']);
    } finally {
        @chmod($scratch.'/cron.d', 0o755);
        removeScratchDir($scratch);
    }
})->with([
    'cron.d refuses it' => [
        fn (string $scratch, string $held) => chmod($scratch.'/cron.d', 0o555),
        'ERROR: could not restore the target scheduler cron entry',
    ],
    'the held entry is gone' => [
        fn (string $scratch, string $held) => unlink($held),
        'ERROR: neither the held scheduler cron entry',
    ],
]);

// =============================================================================
// A hold that cannot hold everything says which writer it could not stop
// =============================================================================

it('names every writer a failed resume could not hold again, and still holds the rest', function (bool $cronClosesAtHealthCheck, array $env, string $error, array $observed) {
    $scratch = restoreScratchDir();

    try {
        $applied = recoveryApplied($scratch);
        $operation = recoveryOperationIdIn($applied['output']);
        deployRecoveredRelease($scratch);

        // The resume puts the scheduler entry back and starts the queue, then
        // fails its health check: the handler has to take both writers down
        // again from a target that was serving.
        $health = $cronClosesAtHealthCheck
            ? executableWithHook($scratch.'/bin/health-check-stub', null, 'chmod 0555 '.escapeshellarg($scratch.'/cron.d'))
            : $scratch.'/bin/health-check-stub';

        $result = recoverHostRun(
            $scratch,
            ['--resume', '--target', 'parity-target', '--operation', $operation],
            $env + ['RGTEST_HEALTH_CHECK_EXIT' => '1', 'RATEGURU_RESTORE_HEALTH_CHECK_BIN' => $health],
        );

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('health check failed after the recovery deployment')
            ->toContain($error)
            ->toContain('MANUAL RECOVERY REQUIRED')
            ->toContain('# WARNING: one or more writers could NOT be proven stopped.');

        // Each hold is attempted whatever happened to the one before it.
        expect([
            'scheduler in cron.d' => File::exists($scratch.'/cron.d/parity-scheduler'),
            'queue' => trim(File::get($scratch.'/supervisor-state')),
        ])->toBe($observed);

        // Held and owned, with nothing committed.
        expect(recoveryGuard($scratch))->toMatchArray(['status' => 'failed-held']);
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db', preRestoreDatabaseName($operation)]);
        expect(lastRecoveryRecord($scratch))->toMatchArray(['status' => 'failed-held', 'failed_step' => 'health check']);
    } finally {
        @chmod($scratch.'/cron.d', 0o755);
        removeScratchDir($scratch);
    }
})->with([
    'the scheduler entry cannot be moved out again' => [
        true,
        [],
        'ERROR: could not hold ',
        ['scheduler in cron.d' => true, 'queue' => 'STOPPED'],
    ],
    'the queue will not stop' => [
        false,
        ['RGTEST_SUPERVISOR_STOP_STATE' => 'RUNNING'],
        'ERROR: could not confirm parity-queue is STOPPED',
        ['scheduler in cron.d' => false, 'queue' => 'RUNNING'],
    ],
]);
