<?php

use Illuminate\Support\Facades\File;

/**
 * Restore Target Data: what a restore leaves behind when it fails.
 *
 * Executes the real shipped infrastructure/scripts/restore-target against the
 * simulated live target RestoreTargetTest uses, and makes it fail at the
 * moments its terminal handler has a decision to make: a guard that cannot be
 * written before the first activation or re-labelled after it, a quiesced or
 * compensated target whose runtime will not come back, and a hold that cannot
 * hold every writer. Each failure is injected through the host — a cron.d that
 * stops accepting the scheduler entry, a guard path that cannot be written, a
 * stubbed tool that fails — never through a modified copy of the script.
 *
 * The contract under test is the handler's, stated in restore-target itself:
 * the guard records whether the live DATA may differ from the code serving
 * it, and is cleared only when it provably does not; the runtime is put back
 * exactly as it was or the target is held; and whatever a hold cannot
 * achieve, it says out loud.
 */

/** Where a restore keeps the scheduler cron entry it moved out of cron.d. */
function restoreTargetHeldSchedulerEntry(string $scratch, string $operation): string
{
    return $scratch.'/run/restores/parity-target/'.$operation.'/scheduler-hold/parity-scheduler';
}

/** The live data the fixture starts with, exactly: nothing was swapped. */
function expectRestoreTargetLiveDataUntouched(string $scratch): void
{
    expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
    expect(trim(File::get($scratch.'/pg/db/parity_db')))->toBe('parity_app t');
    expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();
    expect(is_file(restoreTargetStorage($scratch).'/app/restored-marker.txt'))->toBeFalse();
}

// =============================================================================
// The guard: written before the first live mutation, as a prerequisite of it
// =============================================================================

/*
 * The guard is written through a temporary file beside it. A directory in that
 * place makes the write fail for any owner — the shape of a full or read-only
 * run root, which a process that owns the whole scratch tree cannot otherwise
 * be given.
 */

it('mutates no live data when its guard cannot be written, and brings the target back exactly as it was', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);
        mkdir($scratch.'/run/restores/parity-target/restore-guard.tmp', 0o700, true);

        $result = restoreTargetApply($scratch);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('ERROR: could not write the restore guard')
            ->toContain('refusing to mutate live data that nothing would then stop an ordinary backup from mislabelling')
            ->not->toContain('step: activate database')
            ->not->toContain('MANUAL RECOVERY REQUIRED');

        expectRestoreTargetLiveDataUntouched($scratch);

        // Failing here is cheap by design: the target was quiesced, and comes
        // back up exactly as it was.
        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
        expect(restoreTargetQueueState($scratch))->toBe('RUNNING');
        expect(restoreTargetSchedulerPresent($scratch))->toBeTrue();

        expect(is_file(restoreGuardFile($scratch)))->toBeFalse();
        expect(restoreTargetHistory($scratch)[0])->toMatchArray([
            'status' => 'failed',
            'failed_step' => 'write restore guard',
        ]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('keeps the guard in place, and says so, when a held restore cannot re-label it', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        // The in-progress guard is written before activation; the re-label to
        // `held`, after the commit, is the write that fails.
        $database = executableWithHook(
            patchedInfraScript($scratch, 'restore-database'),
            '--commit',
            'mkdir '.escapeshellarg(restoreGuardFile($scratch).'.tmp'),
        );

        $result = restoreTargetApply($scratch, ['RATEGURU_RESTORE_DATABASE_BIN' => $database]);
        $operation = restoreTargetHistory($scratch)[0]['operation_id'];

        // The data restore succeeded and the target is held for code alignment:
        // a re-label that did not happen does not turn that into a failure.
        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('CODE ALIGNMENT: REQUIRED')
            ->toContain('WARNING: the restore guard could not be re-labelled; it remains in place and ordinary backups still refuse');

        // It still stands, at in-progress, which blocks an ordinary backup just
        // as firmly as `held` would.
        expect(json_decode(File::get(restoreGuardFile($scratch)), true))->toMatchArray([
            'operation' => $operation,
            'status' => 'in-progress',
            'required_source_sha' => FIXTURE_SOURCE_SHA,
        ]);

        expect(restoreTargetMaintenanceActive($scratch))->toBeTrue();
        expect(restoreTargetQueueState($scratch))->toBe('STOPPED');
        expect(restoreTargetSchedulerPresent($scratch))->toBeFalse();
        expect(restoreTargetHistory($scratch)[0])->toMatchArray(['status' => 'held']);
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// A runtime that will not come back
// =============================================================================

it('holds a quiesced target whose scheduler entry cannot be put back, and touches no live data', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);
        $entry = File::get($scratch.'/cron.d/parity-scheduler');

        // The emergency backup fails with the target quiesced, and by then
        // cron.d no longer accepts the entry the quiesce moved out of it.
        $backup = executableWithHook($scratch.'/bin/backup-stub', null, 'chmod 0555 '.escapeshellarg($scratch.'/cron.d'));

        $result = restoreTargetApply($scratch, [
            'RATEGURU_RESTORE_BACKUP_BIN' => $backup,
            'RGTEST_BACKUP_EXIT' => '1',
        ]);
        $operation = restoreTargetHistory($scratch)[0]['operation_id'];

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('ERROR: could not restore the target scheduler cron entry')
            ->toContain('MANUAL RECOVERY REQUIRED')
            ->toContain('# failed step: emergency pre-restore backup');

        expectRestoreTargetLiveDataUntouched($scratch);

        // Not half up: the queue is not started and the target stays in
        // maintenance, because the scheduler — resumed first — did not come
        // back. The entry is kept where the quiesce put it, not lost.
        expect(restoreTargetMaintenanceActive($scratch))->toBeTrue();
        expect(restoreTargetQueueState($scratch))->toBe('STOPPED');
        expect(restoreTargetSchedulerPresent($scratch))->toBeFalse();
        expect(File::get(restoreTargetHeldSchedulerEntry($scratch, $operation)))->toBe($entry);

        // No guard: it records live data that may not match the code, and no
        // live data was touched — an ordinary backup of this target would be
        // labelled correctly.
        expect(is_file(restoreGuardFile($scratch)))->toBeFalse();
        expect(restoreTargetHistory($scratch)[0])->toMatchArray([
            'status' => 'failed-held',
            'failed_step' => 'emergency pre-restore backup',
            'compensation_status' => 'not-required',
        ]);
    } finally {
        @chmod($scratch.'/cron.d', 0o755);
        removeScratchDir($scratch);
    }
});

it('holds a target whose data was put back but whose runtime would not come back, and lets the guard go', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        // The first activation rename fails, so compensation has nothing to
        // undo and completes; then the queue's start takes effect but never
        // reaches RUNNING.
        $result = restoreTargetApply($scratch, [
            'RGTEST_RENAME_FAIL_TO_PREFIX' => 'rateguru_pre_',
            'RGTEST_SUPERVISOR_START_STATE' => 'BACKOFF',
        ]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('did not reach RUNNING within the wait budget')
            ->toContain('MANUAL RECOVERY REQUIRED')
            ->toContain('# compensation: complete');

        expectRestoreTargetLiveDataUntouched($scratch);

        // Held, from the state it was actually in: the worker that did start
        // is stopped again, and the entry already put back is moved out again.
        expect(restoreTargetMaintenanceActive($scratch))->toBeTrue();
        expect(restoreTargetQueueState($scratch))->toBe('STOPPED');
        expect(restoreTargetSchedulerPresent($scratch))->toBeFalse();

        // The live data matches the code again, so the guard has nothing left
        // to protect — the hold is the runtime's, not the data's.
        expect(is_file(restoreGuardFile($scratch)))->toBeFalse();
        expect(restoreTargetHistory($scratch)[0])->toMatchArray([
            'status' => 'failed-held',
            'failed_step' => 'activate database',
            'compensation_status' => 'complete',
        ]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('never invents the scheduler entry a resume was meant to put back, and stays held', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreTargetHeldForCodeAlignment($scratch);
        restoreTargetAlignCode($scratch);

        unlink(restoreTargetHeldSchedulerEntry($scratch, $operation));

        $result = restoreTargetRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('ERROR: neither the held scheduler cron entry')
            ->toContain('could not resume the target runtime')
            ->toContain('MANUAL RECOVERY REQUIRED');

        // Nothing came up behind the missing entry, and none was written.
        expect(restoreTargetSchedulerPresent($scratch))->toBeFalse();
        expect(File::get($scratch.'/supervisor.log'))->not->toContain('supervisorctl start');
        expect(restoreTargetQueueState($scratch))->toBe('STOPPED');
        expect(restoreTargetMaintenanceActive($scratch))->toBeTrue();

        expect(json_decode(File::get(restoreGuardFile($scratch)), true))->toMatchArray(['status' => 'failed-held']);
        expect(restoreTargetHistory($scratch)[1])->toMatchArray([
            'status' => 'failed-held',
            'failed_step' => 'resume runtime',
        ]);
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// A hold that cannot hold everything says which writer it could not stop
// =============================================================================

it('names every writer a failed resume could not hold again, and still holds the rest', function (bool $cronClosesAtHealthCheck, array $env, array $errors, array $observed) {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreTargetHeldForCodeAlignment($scratch);
        restoreTargetAlignCode($scratch);

        // The resume brings everything up and then fails its health check, so
        // the handler has to take every writer down again from a live target.
        $health = $cronClosesAtHealthCheck
            ? executableWithHook($scratch.'/bin/health-check-stub', null, 'chmod 0555 '.escapeshellarg($scratch.'/cron.d'))
            : $scratch.'/bin/health-check-stub';

        $result = restoreTargetRun(
            $scratch,
            ['--resume', '--target', 'parity-target', '--operation', $operation],
            $env + ['RGTEST_HEALTH_CHECK_EXIT' => '1', 'RATEGURU_RESTORE_HEALTH_CHECK_BIN' => $health],
        );

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('health check failed after resume')
            ->toContain('MANUAL RECOVERY REQUIRED')
            ->toContain('# WARNING: one or more writers could NOT be proven stopped.');

        foreach ($errors as $error) {
            expect($result['output'])->toContain($error);
        }

        // Each hold is attempted whatever happened to the one before it.
        expect([
            'scheduler in cron.d' => restoreTargetSchedulerPresent($scratch),
            'queue' => restoreTargetQueueState($scratch),
            'maintenance' => restoreTargetMaintenanceActive($scratch),
        ])->toBe($observed);

        expect(json_decode(File::get(restoreGuardFile($scratch)), true))->toMatchArray(['status' => 'failed-held']);
        expect(restoreTargetHistory($scratch)[1])->toMatchArray([
            'status' => 'failed-held',
            'failed_step' => 'health check',
        ]);
    } finally {
        @chmod($scratch.'/cron.d', 0o755);
        removeScratchDir($scratch);
    }
})->with([
    'the scheduler entry cannot be moved out again' => [
        true,
        [],
        ['ERROR: could not hold '],
        ['scheduler in cron.d' => true, 'queue' => 'STOPPED', 'maintenance' => true],
    ],
    'the queue will not stop and maintenance will not start' => [
        false,
        ['RGTEST_SUPERVISOR_STOP_STATE' => 'RUNNING', 'RGTEST_ARTISAN_DOWN_EXIT' => '1'],
        ['ERROR: could not confirm parity-queue is STOPPED', 'ERROR: could not put parity-target into maintenance mode'],
        ['scheduler in cron.d' => false, 'queue' => 'RUNNING', 'maintenance' => false],
    ],
]);
