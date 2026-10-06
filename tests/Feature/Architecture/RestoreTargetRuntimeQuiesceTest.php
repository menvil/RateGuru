<?php

use Illuminate\Support\Facades\File;

/**
 * Restore Target Data: `restore-target` — quiescing the target's runtime and
 * bringing it back.
 *
 * Maintenance mode, this target's queue program and its scheduler entry are
 * taken down for the swap and put back exactly as they were found — never as
 * assumed — including the half-finished transitions a real host produces, and
 * the Supervisor status observation every one of those decisions rests on.
 * The whole-restore contract is in RestoreTargetTest; the shared harness is in
 * tests/Pest.php.
 */

// =============================================================================
// Runtime quiesce: preserve the ORIGINAL state, never assume it
// =============================================================================

it('brings the target down for the restore and back up afterwards when it was up before', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();

        $result = restoreTargetApply($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        $php = File::get($scratch.'/php.log');
        expect($php)->toContain('artisan down');
        expect($php)->toContain('artisan up');
        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('leaves a target that was already in maintenance in maintenance', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);
        file_put_contents(restoreTargetStorage($scratch).'/framework/down', "{}\n");

        $result = restoreTargetApply($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        expect($result['output'])->toContain('was already in maintenance before this restore');
        expect(File::get($scratch.'/php.log'))->not->toContain('artisan up');
        expect(restoreTargetMaintenanceActive($scratch))->toBeTrue();

        expect(restoreTargetHistory($scratch)[0]['status'])->toBe('completed');
    } finally {
        removeScratchDir($scratch);
    }
});

it('stops a running queue program and starts it again, touching only this target group', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetApply($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        $supervisor = File::get($scratch.'/supervisor.log');
        expect($supervisor)->toContain('stop parity-queue:*');
        expect($supervisor)->toContain('start parity-queue:*');
        expect(restoreTargetQueueState($scratch))->toBe('RUNNING');

        // Never a global Supervisor operation, never another project's group.
        foreach (['stop all', 'start all', 'restart all', 'shutdown', 'reread', 'update'] as $forbidden) {
            expect($supervisor)->not->toContain($forbidden);
        }
    } finally {
        removeScratchDir($scratch);
    }
});

it('leaves a queue program that was already stopped stopped', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);
        file_put_contents($scratch.'/supervisor-state', "STOPPED\n");

        $result = restoreTargetApply($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        expect($result['output'])->toContain('was already fully stopped before this restore');
        expect(File::get($scratch.'/supervisor.log'))->not->toContain('start parity-queue');
        expect(restoreTargetQueueState($scratch))->toBe('STOPPED');
    } finally {
        removeScratchDir($scratch);
    }
});

it('stops a queue group that is only partly running, and leaves it stopped afterwards', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        // One worker RUNNING, one FATAL: not fully running, and emphatically
        // not safe to swap data underneath.
        file_put_contents($scratch.'/supervisor-second-state', "FATAL\n");

        $result = restoreTargetApply($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        expect($result['output'])->toContain('neither fully RUNNING nor fully STOPPED');
        expect(File::get($scratch.'/supervisor.log'))->toContain('stop parity-queue:*');

        // Left stopped, because it was never fully running to begin with —
        // and said out loud rather than silently.
        expect(restoreTargetQueueState($scratch))->toBe('STOPPED');
        expect(trim(File::get($scratch.'/supervisor-second-state')))->toBe('STOPPED');
        expect(File::get($scratch.'/supervisor.log'))->not->toContain('start parity-queue');
    } finally {
        removeScratchDir($scratch);
    }
});

it('still fails closed when a live target says its queue group does not exist', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        // The exact answer a clean-host recovery is allowed to accept, because
        // there the group has simply not been added to the running Supervisor
        // yet. On a LIVE target it means the group vanished from under a
        // running application, and "I cannot see it" is never "it is not
        // running": a live restore fails closed on it, as it always has.
        $result = restoreTargetApply($scratch, [
            'RGTEST_SUPERVISOR_STATUS_STDOUT' => 'parity-queue: ERROR (no such group)',
            'RGTEST_SUPERVISOR_STATUS_RC' => '4',
        ]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('cannot observe the target queue program parity-queue')
            ->toContain('supervisorctl status exited 4')
            ->toContain('no such group');

        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();
        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();

        // The shared observer stays the one implementation, and the recovery's
        // classification of this answer lives in the recovery alone. Asserted
        // on executable lines: restore-common's comments explain exactly why
        // it rejects "no such group", and that prose is the point.
        foreach (['restore-common', 'restore-target'] as $script) {
            $source = executableSourceLines(File::get(base_path('infrastructure/scripts/'.$script)));

            foreach (['recovery_queue_group_not_loaded', 'no such group', 'RC_UNKNOWN_NAME'] as $forbidden) {
                expect(str_contains($source, $forbidden))
                    ->toBeFalse("{$script} must not classify a queue answer the way a clean-host recovery does: {$forbidden}");
            }
        }

        // The observer accepts exactly two statuses, and 4 is not one of them.
        $observer = shellFunctionBody(File::get(base_path('infrastructure/scripts/restore-common')), 'observe_queue_program');

        expect($observer)
            ->toContain('(( status != RESTORE_SUPERVISOR_RC_RUNNING ))')
            ->toContain('(( status != RESTORE_SUPERVISOR_RC_NOT_RUNNING ))');
        expect(File::get(base_path('infrastructure/scripts/restore-common')))
            ->toContain('RESTORE_SUPERVISOR_RC_RUNNING=0')
            ->toContain('RESTORE_SUPERVISOR_RC_NOT_RUNNING=3');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to restore when the target queue program cannot be observed at all', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        // supervisorctl cannot answer: supervisord down, or the program not
        // registered. "Cannot see it" is never "it is not running". Supervisor
        // reports both as exit code 4, distinct from the 3 it uses for a group
        // that is merely stopped.
        $result = restoreTargetApply($scratch, [
            'RGTEST_SUPERVISOR_STATUS_FAILURE' => 'unix:///var/run/supervisor.sock refused connection',
        ]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('cannot observe the target queue program parity-queue');
        expect($result['output'])->toContain('supervisorctl status exited 4');
        expect($result['output'])->toContain('refused connection');

        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();
        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('holds this target cron entry outside cron.d for the restore and restores it exactly', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $before = File::get($scratch.'/cron.d/parity-scheduler');
        $modeBefore = substr(sprintf('%o', fileperms($scratch.'/cron.d/parity-scheduler')), -4);

        // An unrelated project's cron entry, which must never be touched.
        file_put_contents($scratch.'/cron.d/cataloghub-scheduler', "* * * * * root true\n");

        $result = restoreTargetApply($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        expect(restoreTargetSchedulerPresent($scratch))->toBeTrue();
        expect(File::get($scratch.'/cron.d/parity-scheduler'))->toBe($before);
        expect(substr(sprintf('%o', fileperms($scratch.'/cron.d/parity-scheduler')), -4))->toBe($modeBefore);
        expect(is_file($scratch.'/cron.d/cataloghub-scheduler'))->toBeTrue();

        // A running schedule:run is interrupted and waited out — never by
        // stopping the global cron daemon.
        expect(File::get($scratch.'/php.log'))->toContain('artisan schedule:interrupt');
        expect(File::get($scratch.'/pgrep.log'))->toContain('artisan schedule:run');
        expect($result['output'])->not->toContain('systemctl stop cron');
    } finally {
        removeScratchDir($scratch);
    }
});

it('never invents a cron entry that did not exist before', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, ['scheduler' => false]);
        mkdir($scratch.'/cron.d', 0o755, true);

        $result = restoreTargetApply($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        expect($result['output'])->toContain('leaving the scheduler exactly as it was');
        expect(restoreTargetSchedulerPresent($scratch))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to swap data underneath a scheduler process that will not stop', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetApply($scratch, ['RGTEST_PGREP_EXIT' => '0']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('refusing to swap data underneath a writer');

        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();

        // The runtime was put back, including the held cron entry.
        expect(restoreTargetSchedulerPresent($scratch))->toBeTrue();
        expect(restoreTargetQueueState($scratch))->toBe('RUNNING');
        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Partial runtime transitions: held must mean held, and resumed must mean
// resumed, decided from what is ACTUALLY running
// =============================================================================

it('holds a queue whose start took effect but never reached RUNNING', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreTargetHeldForCodeAlignment($scratch);
        restoreTargetAlignCode($scratch);

        // The resume's `supervisorctl start` takes effect — the worker is
        // BACKOFF, not STOPPED — but never reaches RUNNING, so the wait
        // fails AFTER the group has come back to life. The flag that says
        // "this restore stopped it" is still true at that moment, and a hold
        // that trusted the flag would leave a live worker writing to a target
        // it reports as held.
        $result = restoreTargetRun(
            $scratch,
            ['--resume', '--target', 'parity-target', '--operation', $operation],
            ['RGTEST_SUPERVISOR_START_STATE' => 'BACKOFF'],
        );

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('did not reach RUNNING within the wait budget');
        expect($result['output'])->toContain('MANUAL RECOVERY REQUIRED');

        // Held means held: the group was stopped again from its observed
        // state, not skipped because a flag claimed it was already stopped.
        expect(restoreTargetQueueState($scratch))->toBe('STOPPED');
        expect(restoreTargetMaintenanceActive($scratch))->toBeTrue();
        expect(restoreTargetSchedulerPresent($scratch))->toBeFalse();

        expect(restoreTargetHistory($scratch)[1])->toMatchArray(['status' => 'failed-held']);
    } finally {
        removeScratchDir($scratch);
    }
});

it('restores a queue whose stop took effect but never confirmed STOPPED', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        // The quiesce's `supervisorctl stop` takes effect but the group lands
        // in FATAL rather than STOPPED, so the confirmation times out and the
        // "this restore stopped it" flag is never set. The queue was RUNNING
        // before, so the failure path must still bring it back.
        $result = restoreTargetApply($scratch, ['RGTEST_SUPERVISOR_STOP_STATE' => 'FATAL']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('did not reach STOPPED within the wait budget');

        // No live mutation, and the queue that was running before is running
        // again — decided from observed state, not from the unset flag.
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();
        expect(restoreTargetQueueState($scratch))->toBe('RUNNING');
        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
        expect(restoreTargetSchedulerPresent($scratch))->toBeTrue();

        expect(restoreTargetHistory($scratch)[0])->toMatchArray(['status' => 'failed']);
    } finally {
        removeScratchDir($scratch);
    }
});

it('re-holds a cron entry that was moved back before its metadata could be restored', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreTargetHeldForCodeAlignment($scratch);
        restoreTargetAlignCode($scratch);

        // The resume moves the cron entry back into /etc/cron.d and then
        // fails on the ownership step. The entry is live again at that
        // moment while the "still held" flag is untouched — the exact shape
        // that used to leave a scheduled writer running against a target
        // reported as held.
        $sabotaged = $scratch.'/sabotaged-restore-target';
        file_put_contents($sabotaged, str_replace(
            '    chown "${owner}" "${SCHEDULER_FILE}" || {',
            '    false || {',
            File::get(patchedInfraScript($scratch, 'restore-target')),
        ));
        chmod($sabotaged, 0o755);

        [$registryPath, $targetsPath] = parityRegistryFixture($scratch);

        $env = infraScriptEnv($scratch, $registryPath, $targetsPath, array_merge(
            fakePostgresEnv($scratch),
            targetRuntimeEnv($scratch),
            [
                'RATEGURU_RESTORE_FETCH_BACKUP_BIN' => patchedInfraScript($scratch, 'fetch-backup'),
                'RATEGURU_RESTORE_VERIFY_BACKUP_BIN' => patchedInfraScript($scratch, 'verify-backup'),
                'RATEGURU_RESTORE_DATABASE_BIN' => patchedInfraScript($scratch, 'restore-database'),
                'RATEGURU_RESTORE_STORAGE_BIN' => patchedInfraScript($scratch, 'restore-storage'),
            ],
        ));

        [$exit, $output] = runInfraScript($sabotaged, ['--resume', '--target', 'parity-target', '--operation', $operation], $env);

        expect($exit)->not->toBe(0);
        expect($output)->toContain('could not restore the scheduler cron entry ownership');
        expect($output)->toContain('MANUAL RECOVERY REQUIRED');

        // The entry that had already gone back is taken out again.
        expect(restoreTargetSchedulerPresent($scratch))->toBeFalse();
        expect(restoreTargetQueueState($scratch))->toBe('STOPPED');
        expect(restoreTargetMaintenanceActive($scratch))->toBeTrue();
    } finally {
        removeScratchDir($scratch);
    }
});

it('does not call a resume successful when the target stays down despite artisan up', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreTargetHeldForCodeAlignment($scratch);
        restoreTargetAlignCode($scratch);

        // `artisan up` exits 0 and the target is still down: reporting the
        // command's exit status as the outcome would call this a resume.
        $result = restoreTargetRun(
            $scratch,
            ['--resume', '--target', 'parity-target', '--operation', $operation],
            ['RGTEST_ARTISAN_UP_INEFFECTIVE' => '1'],
        );

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('still in maintenance after artisan up');
        expect(restoreTargetMaintenanceActive($scratch))->toBeTrue();
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// The scheduler barrier is fail-closed
// =============================================================================

it('refuses to restore when the scheduler cannot be observed', function (array $env, string $expected) {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetApply($scratch, $env);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain($expected);

        // The connection barrier only covers PostgreSQL; the storage swap has
        // no equivalent, so an unprovable scheduler stops the restore before
        // anything live is touched.
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();

        // And the runtime is put back.
        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
        expect(restoreTargetQueueState($scratch))->toBe('RUNNING');
        expect(restoreTargetSchedulerPresent($scratch))->toBeTrue();
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    // pgrep exit 2 is a usage error, 3 a fatal one: the observation is broken,
    // which is never the same as "no process matched".
    'a broken pgrep' => [['RGTEST_PGREP_EXIT' => '2'], 'cannot observe'],
    'no pgrep at all' => [['RATEGURU_RESTORE_PGREP_BIN' => '/definitely/not/pgrep'], 'is unavailable'],
]);

// =============================================================================
// Supervisor status observation
//
// Real behaviour observed on staging during the first live Restore Target Data restore:
// `supervisorctl status <group>:*` returns EXIT CODE 3 while correctly printing
// the process as STOPPED. Treating every non-zero exit as "cannot observe"
// made the quiesce wait out its whole budget and abort the restore, even though
// the queue had stopped exactly as asked.
//
// The codes come from the pinned supervisor 4.2.1 the host runs
// (supervisorctl.py LSBStatusExitStatuses, states.py STOPPED_STATES):
//   0  every matched process is running-ish
//   3  at least one is STOPPED, EXITED, FATAL or UNKNOWN
//   4  upcheck() failed, or the name matched nothing
// =============================================================================

/** The supervisorctl calls a run made, in order. */
function restoreTargetSupervisorLog(string $scratch): array
{
    $path = $scratch.'/supervisor.log';

    if (! is_file($path)) {
        return [];
    }

    return array_values(array_filter(explode("\n", trim((string) file_get_contents($path)))));
}

it('accepts a STOPPED group reported with exit code 3, and does not wait out the budget', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        // Exactly the staging sequence: RUNNING (rc 0) before the quiesce, then
        // STOPPED (rc 3) after `supervisorctl stop`. The stub derives both exit
        // codes the way supervisor does, so this fails against the old
        // `|| return 1` on any non-zero status.
        $result = restoreTargetApply($scratch);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])->not->toContain('did not reach STOPPED within the wait budget');

        // The quiesce moved on rather than looping: it stopped the group and
        // continued to the scheduler and the emergency backup.
        $steps = $result['output'];
        expect($steps)->toContain('queue program parity-queue STOPPED');
        expect(mb_strpos($steps, 'step: quiesce target'))
            ->toBeLessThan(mb_strpos($steps, 'step: emergency pre-restore backup'));

        // And the whole restore completed, which it could not have done while
        // a correctly stopped queue was being read as unobservable.
        expect($result['output'])->toContain('restore completed and the target is serving again');
    } finally {
        removeScratchDir($scratch);
    }
});

it('classifies every state the observation can legitimately report', function (
    string $first,
    ?string $second,
    bool $running,
    bool $fullyStopped,
) {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);
        [$registryPath, $targetsPath] = parityRegistryFixture($scratch);

        file_put_contents($scratch.'/supervisor-state', $first."\n");

        if ($second !== null) {
            file_put_contents($scratch.'/supervisor-second-state', $second."\n");
        }

        [$exit, $output] = runInfraHarness(
            $scratch,
            patchedInfraScript($scratch, 'restore-target'),
            <<<'BASH'
                SUPERVISOR_PROGRAM=parity-queue

                if observe_queue_program; then
                    echo "OBSERVED: ${QUEUE_PROCESS_STATES[*]}"
                else
                    echo "OBSERVATION FAILED: ${QUEUE_OBSERVATION_ERROR}"
                fi

                queue_program_running && echo "RUNNING: yes" || echo "RUNNING: no"
                queue_program_fully_stopped && echo "STOPPED: yes" || echo "STOPPED: no"
                BASH,
            infraScriptEnv($scratch, $registryPath, $targetsPath, array_merge(
                fakePostgresEnv($scratch),
                targetRuntimeEnv($scratch),
            )),
        );

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('OBSERVED: '.trim($first.' '.($second ?? '')));
        expect($output)->toContain('RUNNING: '.($running ? 'yes' : 'no'));
        expect($output)->toContain('STOPPED: '.($fullyStopped ? 'yes' : 'no'));
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    // A: rc 0, RUNNING -> valid, fully running.
    'all running' => ['RUNNING', null, true, false],
    // B: rc 3, STOPPED -> valid, fully stopped. The staging case.
    'all stopped' => ['STOPPED', null, false, true],
    // C: rc 3, FATAL is a real Supervisor state -> valid, neither.
    'fatal' => ['FATAL', null, false, false],
    'exited' => ['EXITED', null, false, false],
    'starting' => ['STARTING', null, false, false],
    'backoff' => ['BACKOFF', null, false, false],
    'stopping' => ['STOPPING', null, false, false],
    // UNKNOWN is a process state, not an observation failure.
    'unknown state' => ['UNKNOWN', null, false, false],
    // D: mixed groups settle as neither, whichever way round.
    'stopped and running' => ['STOPPED', 'RUNNING', false, false],
    'running and stopped' => ['RUNNING', 'STOPPED', false, false],
    'stopped and fatal' => ['STOPPED', 'FATAL', false, false],
    'stopped and starting' => ['STOPPED', 'STARTING', false, false],
    'both running' => ['RUNNING', 'RUNNING', true, false],
    'both stopped' => ['STOPPED', 'STOPPED', false, true],
]);

it('fails the observation closed on anything that is not a real answer about this group', function (
    array $environment,
    string $expected,
) {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);
        [$registryPath, $targetsPath] = parityRegistryFixture($scratch);

        [$exit, $output] = runInfraHarness(
            $scratch,
            patchedInfraScript($scratch, 'restore-target'),
            <<<'BASH'
                SUPERVISOR_PROGRAM=parity-queue

                if observe_queue_program; then
                    echo "UNEXPECTED: observation succeeded with ${QUEUE_PROCESS_STATES[*]}"
                else
                    echo "OBSERVATION FAILED: ${QUEUE_OBSERVATION_ERROR}"
                fi

                # An unobservable group is never "not running" and never
                # "stopped": both classifiers must refuse it too.
                queue_program_running && echo "RUNNING: yes" || echo "RUNNING: no"
                queue_program_fully_stopped && echo "STOPPED: yes" || echo "STOPPED: no"
                BASH,
            infraScriptEnv($scratch, $registryPath, $targetsPath, array_merge(
                fakePostgresEnv($scratch),
                targetRuntimeEnv($scratch),
                $environment,
            )),
        );

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('OBSERVATION FAILED');
        expect($output)->toContain($expected);
        expect($output)->toContain('RUNNING: no');
        expect($output)->toContain('STOPPED: no');
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    // E: nothing printed at all.
    'empty output' => [
        ['RGTEST_SUPERVISOR_STATUS_STDOUT' => ' ', 'RGTEST_SUPERVISOR_STATUS_RC' => '0'],
        'printed nothing',
    ],
    // F: the group is not registered — Supervisor's own wording and code.
    'no such group' => [
        ['RGTEST_SUPERVISOR_STATUS_STDOUT' => 'parity-queue: ERROR (no such group)', 'RGTEST_SUPERVISOR_STATUS_RC' => '4'],
        'supervisorctl status exited 4',
    ],
    // …and rejected on its output even if the code were acceptable.
    'no such group with an acceptable exit code' => [
        ['RGTEST_SUPERVISOR_STATUS_STDOUT' => 'parity-queue: ERROR (no such group)', 'RGTEST_SUPERVISOR_STATUS_RC' => '0'],
        'is not a Supervisor process state',
    ],
    // G: transport failure.
    'connection refused' => [
        ['RGTEST_SUPERVISOR_STATUS_FAILURE' => 'unix:///var/run/supervisor.sock refused connection'],
        'supervisorctl status exited 4',
    ],
    'supervisord not running' => [
        ['RGTEST_SUPERVISOR_STATUS_FAILURE' => 'unix:///var/run/supervisor.sock no such file', 'RGTEST_SUPERVISOR_STATUS_FAILURE_RC' => '7'],
        'supervisorctl status exited 7',
    ],
    // H: somebody else's program.
    'another program' => [
        ['RGTEST_SUPERVISOR_STATUS_STDOUT' => 'other-queue:other-queue_00       RUNNING   pid 1, uptime 0:00:01', 'RGTEST_SUPERVISOR_STATUS_RC' => '0'],
        'is not part of parity-queue',
    ],
    // I: a state token Supervisor does not have.
    'unrecognized state' => [
        ['RGTEST_SUPERVISOR_STATUS_STDOUT' => 'parity-queue:parity-queue_00     WOBBLING  pid 1', 'RGTEST_SUPERVISOR_STATUS_RC' => '0'],
        'is not a Supervisor process state',
    ],
    'truncated line' => [
        ['RGTEST_SUPERVISOR_STATUS_STDOUT' => 'parity-queue:parity-queue_00', 'RGTEST_SUPERVISOR_STATUS_RC' => '0'],
        'is not a Supervisor process state',
    ],
]);

it('leaves live data untouched when the queue cannot be quiesced', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        // The staging failure mode, forced: the stop lands somewhere other than
        // STOPPED, so the confirmation never comes. What matters is WHERE the
        // restore gives up — before the emergency backup, the restore guard and
        // both activations.
        $result = restoreTargetApply($scratch, ['RGTEST_SUPERVISOR_STOP_STATE' => 'FATAL']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('did not reach STOPPED within the wait budget');

        expect($result['output'])->not->toContain('step: emergency pre-restore backup');
        expect($result['output'])->not->toContain('step: write restore guard');
        expect($result['output'])->not->toContain('step: activate database');
        expect($result['output'])->not->toContain('step: activate storage');

        // Live data exactly as it was, and no guard left behind.
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();
        expect(is_file(restoreGuardFile($scratch)))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});
