<?php

use Illuminate\Support\Facades\File;

/**
 * Restore Target Data: `restore-target` — the code-alignment hold.
 *
 * A backup's data belongs to the code that wrote it. When the target runs
 * other code, the restore completes the data swap and then HOLDS the runtime:
 * the hold marker, --resume once the deployed code matches, a scheduler that
 * cron starts during the hold, and what an ordinary backup may and may not do
 * while the hold stands. The whole-restore contract is in RestoreTargetTest;
 * the shared harness is in tests/Pest.php.
 */

// =============================================================================
// Code alignment
// =============================================================================

it('completes the data restore but holds the runtime when the code does not match the backup', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $result = restoreTargetApply($scratch);

        // The requested DATA restore succeeded, so this is a success.
        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('RESTORE DATA COMPLETE: YES')
            ->toContain('CODE ALIGNMENT: REQUIRED')
            ->toContain('TARGET RESUMED: NO')
            ->toContain('BACKUP SOURCE SHA: '.FIXTURE_SOURCE_SHA)
            ->toContain('CURRENT SOURCE SHA: '.FIXTURE_OTHER_SOURCE_SHA)
            ->toContain('restore-target --resume --target parity-target --operation');

        // The data IS restored and committed.
        expect(is_file(restoreTargetStorage($scratch).'/app/restored-marker.txt'))->toBeTrue();
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);

        // The runtime is intentionally held.
        expect(restoreTargetMaintenanceActive($scratch))->toBeTrue();
        expect(restoreTargetQueueState($scratch))->toBe('STOPPED');
        expect(restoreTargetSchedulerPresent($scratch))->toBeFalse();

        // No release was switched and no migration was run.
        expect(readlink($scratch.'/target/current'))->toBe($scratch.'/target/releases/'.FIXTURE_OTHER_RELEASE);
        expect(File::get($scratch.'/php.log'))->not->toContain('migrate');
        expect(File::get($scratch.'/health-check.log'))->toBe('', 'a held target is not health checked as if it were serving');

        expect(restoreTargetHistory($scratch)[0])->toMatchArray([
            'status' => 'held',
            'code_alignment' => 'REQUIRED',
            'runtime_resumed' => 'no',
        ]);
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Resume
// =============================================================================

it('resumes a held target once the deployed code carries the backup source_sha', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreTargetHeldForCodeAlignment($scratch);
        restoreTargetAlignCode($scratch);

        $result = restoreTargetRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('CODE ALIGNMENT: ALIGNED')
            ->toContain('TARGET RESUMED: YES');

        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
        expect(restoreTargetQueueState($scratch))->toBe('RUNNING');
        expect(restoreTargetSchedulerPresent($scratch))->toBeTrue();
        expect(File::get($scratch.'/health-check.log'))->toContain('--target parity-target');

        $history = restoreTargetHistory($scratch);
        expect($history)->toHaveCount(2);
        expect($history[1])->toMatchArray(['status' => 'resumed', 'runtime_resumed' => 'yes']);

        // The completed operation's workspace is cleaned up.
        expect(is_dir($scratch.'/run/restores/parity-target/'.$operation))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to resume while the code still does not match, and leaves the target held', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreTargetHeldForCodeAlignment($scratch);

        $result = restoreTargetRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('still does not carry the backup');
        expect($result['output'])->toContain('the target stays held');

        expect(restoreTargetMaintenanceActive($scratch))->toBeTrue();
        expect(restoreTargetQueueState($scratch))->toBe('STOPPED');
        expect(restoreTargetSchedulerPresent($scratch))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to resume an unknown operation, another target operation, or one that is not held', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $unknown = restoreTargetRun($scratch, ['--resume', '--target', 'parity-target', '--operation', '20260101-000000-ffffff']);
        expect($unknown['exit'])->not->toBe(0);
        expect($unknown['output'])->toContain('restore operation workspace does not exist');

        $operation = restoreTargetHeldOperation($scratch);
        $state = restoreOperationState($scratch.'/run/restores/parity-target/'.$operation);

        $state['target'] = 'someone-else';
        file_put_contents($scratch.'/run/restores/parity-target/'.$operation.'/state.json', json_encode($state));

        $wrongTarget = restoreTargetRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);
        expect($wrongTarget['exit'])->not->toBe(0);
        expect($wrongTarget['output'])->toContain('belongs to target someone-else');

        $state['target'] = 'parity-target';
        $state['status'] = 'completed';
        file_put_contents($scratch.'/run/restores/parity-target/'.$operation.'/state.json', json_encode($state));

        $notHeld = restoreTargetRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);
        expect($notHeld['exit'])->not->toBe(0);
        expect($notHeld['output'])->toContain('--resume only applies to an operation whose data restore completed');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses a target whose releases root is a symlink, on both apply and resume', function () {
    // A symlinked releases root plus a current pointing inside it resolves to
    // a self-consistent pair — both readlink -f to the same foreign parent —
    // so the containment check alone would accept a release tree this target
    // does not own, and the whole code-alignment decision is made against
    // that identity. deploy, rollback and cleanup all hold the same line.
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        exec('mv '.escapeshellarg($scratch.'/target/releases').' '.escapeshellarg($scratch.'/foreign-releases'));
        symlink($scratch.'/foreign-releases', $scratch.'/target/releases');

        $result = restoreTargetApply($scratch);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('releases root must be a real directory, not a symlink');

        expect(is_dir($scratch.'/run/restores'))->toBeFalse('nothing may be staged for an uncontained target');
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }

    $scratch = restoreScratchDir();

    try {
        $operation = restoreTargetHeldForCodeAlignment($scratch);
        restoreTargetAlignCode($scratch);

        // The aligning deploy is genuine, but the releases root has been
        // replaced with a link to a foreign tree: resume must refuse it too,
        // and the target must stay held.
        exec('mv '.escapeshellarg($scratch.'/target/releases').' '.escapeshellarg($scratch.'/foreign-releases'));
        symlink($scratch.'/foreign-releases', $scratch.'/target/releases');

        $result = restoreTargetRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('releases root must be a real directory, not a symlink');

        expect(restoreTargetMaintenanceActive($scratch))->toBeTrue();
        expect(restoreTargetQueueState($scratch))->toBe('STOPPED');
        expect(restoreTargetSchedulerPresent($scratch))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to resume when current is malformed or resolves outside the releases tree', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreTargetHeldForCodeAlignment($scratch);

        // current points outside releases/ — a broken deployment, never
        // something a restore reasons about.
        mkdir($scratch.'/rogue-release', 0o755, true);
        file_put_contents($scratch.'/rogue-release/artisan', "<?php\n");
        file_put_contents(
            $scratch.'/rogue-release/release.json',
            json_encode(['release' => FIXTURE_RELEASE, 'source_sha' => FIXTURE_SOURCE_SHA]),
        );
        unlink($scratch.'/target/current');
        symlink($scratch.'/rogue-release', $scratch.'/target/current');

        $result = restoreTargetRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('carries no usable release/source_sha');
        expect(restoreTargetMaintenanceActive($scratch))->toBeTrue();
    } finally {
        removeScratchDir($scratch);
    }
});

it('does not resume a target whose health check fails, and holds it instead', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreTargetHeldForCodeAlignment($scratch);
        restoreTargetAlignCode($scratch);

        $result = restoreTargetRun(
            $scratch,
            ['--resume', '--target', 'parity-target', '--operation', $operation],
            ['RGTEST_HEALTH_CHECK_EXIT' => '1'],
        );

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('health check failed after resume');
        expect($result['output'])->toContain('MANUAL RECOVERY REQUIRED');

        expect(restoreTargetMaintenanceActive($scratch))->toBeTrue();
        expect(restoreTargetQueueState($scratch))->toBe('STOPPED');

        // Held means held: the scheduler cron entry the resume had already
        // put back is taken out of /etc/cron.d again, so nothing writes to
        // the database while an operator investigates.
        expect(restoreTargetSchedulerPresent($scratch))->toBeFalse();

        expect(restoreTargetHistory($scratch)[1])->toMatchArray(['status' => 'failed-held']);
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// A hold has to be a hold, including against a scheduler that started DURING
// the operation
// =============================================================================

it('interrupts and proves the absence of a scheduler that cron started after the resume', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreTargetHeldForCodeAlignment($scratch);
        restoreTargetAlignCode($scratch);

        // The window this closes: --resume puts the cron entry back and leaves
        // maintenance, cron fires a schedule:run, and only THEN does the
        // health check fail. Re-holding the cron entry stops the next run; it
        // does nothing about the one already writing to PostgreSQL and
        // storage.
        file_put_contents($scratch.'/pgrep.log', '');
        file_put_contents($scratch.'/php.log', '');

        $result = restoreTargetRun(
            $scratch,
            ['--resume', '--target', 'parity-target', '--operation', $operation],
            ['RGTEST_HEALTH_CHECK_EXIT' => '1', 'RGTEST_PGREP_EXIT' => '0'],
        );

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('health check failed after resume');

        // The hold asked the running scheduler to stop...
        expect(File::get($scratch.'/php.log'))->toContain('schedule:interrupt');

        // ...then actually looked for it, rather than assuming the cron move
        // was enough.
        $pgrep = File::get($scratch.'/pgrep.log');
        expect($pgrep)->toContain('artisan schedule:run');
        expect(substr_count($pgrep, 'artisan schedule:run'))->toBe(3, 'the hold must spend its whole observation budget');

        // And it says out loud that the hold is not proven, rather than
        // reporting a clean-looking held target with a writer still running.
        expect($result['output'])->toContain('a scheduler process for');
        expect($result['output'])->toContain('still running after the interrupt budget');
        expect($result['output'])->toContain('WARNING: one or more writers could NOT be proven stopped');

        expect(restoreTargetSchedulerPresent($scratch))->toBeFalse();
        expect(restoreTargetHistory($scratch)[1])->toMatchArray(['status' => 'failed-held']);

        // And the data is still the backup's while current serves something
        // else, so the hold marker stays and backups stay refused.
        expect(is_file($scratch.'/run/restores/parity-target/restore-guard'))->toBeTrue();
    } finally {
        removeScratchDir($scratch);
    }
});

it('reports a clean hold when no scheduler process remains', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreTargetHeldForCodeAlignment($scratch);
        restoreTargetAlignCode($scratch);

        // Same failure, but nothing is running: the hold is proven, and says so
        // without the warning.
        $result = restoreTargetRun(
            $scratch,
            ['--resume', '--target', 'parity-target', '--operation', $operation],
            ['RGTEST_HEALTH_CHECK_EXIT' => '1'],
        );

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('no scheduler process is running against this target');
        expect($result['output'])->not->toContain('WARNING: one or more writers could NOT be proven stopped');
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// The restore hold marker: a held target's data does not match its code, and
// an ordinary backup would label it with that code anyway
// =============================================================================

/**
 * Runs the REAL backup script against the same scratch host, patched only for
 * root exactly like every other Restore Target Data subject.
 *
 * The assertions below are about the restore-hold guard, not about the backup
 * pipeline: `backup` is refused before it creates anything, or it gets past the
 * guard and reaches its first real step. What happens after that belongs to
 * BackupTest, which owns the full pipeline and its database fakes.
 *
 * @return array{exit: int, output: string, blocked: bool, reached_backup: bool}
 */
function restoreHoldRunBackup(string $scratch): array
{
    [$registryPath, $targetsPath] = parityRegistryFixture($scratch);

    [$exit, $output] = runInfraScript(
        patchedInfraScript($scratch, 'backup'),
        ['--target', 'parity-target'],
        infraScriptEnv($scratch, $registryPath, $targetsPath, fakePostgresEnv($scratch)),
    );

    return [
        'exit' => $exit,
        'output' => $output,
        'blocked' => str_contains($output, 'is held after restore operation'),
        'reached_backup' => str_contains($output, 'Backing up PostgreSQL database'),
    ];
}

it('writes the guard before the first live mutation, as a prerequisite of it', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetApply($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        // The ordering is the whole protection: a guard written after the
        // activation cannot cover the activation.
        $steps = $result['output'];

        expect(mb_strpos($steps, 'step: emergency pre-restore backup'))
            ->toBeLessThan(mb_strpos($steps, 'step: write restore guard'));
        expect(mb_strpos($steps, 'step: write restore guard'))
            ->toBeLessThan(mb_strpos($steps, 'step: activate database'));
        expect($steps)->toContain('status=in-progress');
    } finally {
        removeScratchDir($scratch);
    }
});

it('survives an unhandled kill mid-activation, which is the window a trap cannot cover', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        // A SIGKILL runs no EXIT/ERR trap and the kernel drops every flock, so
        // the host's own backup cron — not the Laravel scheduler, and not held
        // by this operation — is free to run against half-restored data. The
        // storage activation kills its own parent to reproduce exactly that.
        $killer = $scratch.'/bin/restore-storage-killer';
        writeExecutable($killer, <<<'BASH'
            #!/bin/bash
            case "$*" in
                *--activate*) kill -9 "${PPID}"; sleep 5 ;;
            esac
            exec "${RGTEST_REAL_RESTORE_STORAGE}" "$@"
            BASH);

        $result = restoreTargetRun(
            $scratch,
            ['--apply', '--target', 'parity-target', '--source', 'local', '--backup', '20260115-120000'],
            [
                'RATEGURU_RESTORE_STORAGE_BIN' => $killer,
                'RGTEST_REAL_RESTORE_STORAGE' => patchedInfraScript($scratch, 'restore-storage'),
            ],
        );

        // Killed, so no terminal handler ran and nothing was reported.
        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->not->toContain('MANUAL RECOVERY REQUIRED');

        // The guard is on disk anyway, because it was written before the first
        // activation rather than by a handler that never got to run.
        expect(is_file(restoreGuardFile($scratch)))->toBeTrue(
            'the guard must survive a kill that no handler can observe');

        expect(json_decode(File::get(restoreGuardFile($scratch)), true))
            ->toMatchArray(['target' => 'parity-target', 'status' => 'in-progress']);

        // And the cron that would otherwise run next is refused.
        $backup = restoreHoldRunBackup($scratch);
        expect($backup['blocked'])->toBeTrue();
        expect($backup['reached_backup'])->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to mutate live data when the guard cannot be written', function () {
    // Two halves, because a filesystem this process owns cannot be made
    // genuinely unwritable to it: the writer reports failure, and the call site
    // turns that failure into a refusal.
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);
        [$registryPath, $targetsPath] = parityRegistryFixture($scratch);

        // A run root whose `restores` component is a regular file: `install -d`
        // cannot create the guard's directory, whoever owns it.
        $brokenRoot = $scratch.'/broken-run';
        mkdir($brokenRoot, 0o700, true);
        file_put_contents($brokenRoot.'/restores', "not a directory\n");

        [$exit, $output] = runInfraHarness(
            $scratch,
            patchedInfraScript($scratch, 'restore-target'),
            <<<'BASH'
                TARGET_ID=parity-target
                OPERATION_ID=20260115-024512-3f9ac1
                BACKUP_SOURCE_SHA=deadbeefdeadbeefdeadbeefdeadbeefdeadbeef
                LABEL=parity-target

                if write_restore_guard in-progress; then
                    echo "UNEXPECTED: the writer reported success"
                    exit 0
                fi

                echo "WRITER REPORTED FAILURE"
                echo "guard written flag: ${RESTORE_GUARD_WRITTEN}"
                BASH,
            infraScriptEnv($scratch, $registryPath, $targetsPath, ['RATEGURU_RUN_ROOT' => $brokenRoot]),
        );

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('WRITER REPORTED FAILURE');
        expect($output)->toContain('the restore guard is NOT in place');
        expect($output)->toContain('guard written flag: false');

        // And the call site refuses rather than continuing: the guard is a
        // prerequisite of the activation, not a best effort beside it.
        $source = File::get(restoreTargetScript());

        expect($source)->toContain(
            "write_restore_guard in-progress \\\n        || fail \"could not write the restore guard",
        );
        expect(mb_strpos($source, 'write_restore_guard in-progress'))
            ->toBeLessThan(mb_strpos($source, 'MUTATION_STAGE=activating'));
    } finally {
        removeScratchDir($scratch);
    }
});

it('clears the guard when an aligned restore comes up healthy', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetApply($scratch);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])->toContain('step: clear restore guard');
        expect(is_file(restoreGuardFile($scratch)))->toBeFalse();

        $backup = restoreHoldRunBackup($scratch);
        expect($backup['blocked'])->toBeFalse();
        expect($backup['reached_backup'])->toBeTrue();
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses an ordinary backup while the target is held, and says which commit it is waiting for', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreTargetHeldForCodeAlignment($scratch);

        // The marker is the part of the hold that outlives the process.
        expect(is_file(restoreGuardFile($scratch)))->toBeTrue();

        $marker = json_decode(File::get(restoreGuardFile($scratch)), true);
        expect($marker)->toMatchArray([
            'operation' => $operation,
            'target' => 'parity-target',
            'required_source_sha' => FIXTURE_SOURCE_SHA,
            'status' => 'held',
        ]);

        // Identity only — nothing an operator has to redact.
        expect(array_keys($marker))->toEqualCanonicalizing([
            'operation', 'target', 'required_source_sha', 'status', 'created_at',
        ]);

        // A backup here would take its DATA from the restored disk and its
        // release identity from current/release.json, which still names the
        // OTHER commit — a backup asserting that this data belongs to code it
        // does not.
        $backup = restoreHoldRunBackup($scratch);

        expect($backup['exit'])->not->toBe(0);
        expect($backup['blocked'])->toBeTrue();
        expect($backup['output'])->toContain(FIXTURE_SOURCE_SHA);
        expect($backup['output'])->toContain('would record the wrong source_sha');

        // Refused before anything was created: it never reached its first real
        // step, and the namespace holds exactly the backups it held before.
        expect($backup['reached_backup'])->toBeFalse();

        $namespace = $scratch.'/backups/parity';
        expect(array_values(array_diff(scandir($namespace), ['.', '..'])))
            ->toBe(['20260115-120000', '20260116-090000']);
    } finally {
        removeScratchDir($scratch);
    }
});

it('clears the hold marker only once a resume has proven the data and the code agree', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreTargetHeldForCodeAlignment($scratch);
        expect(is_file(restoreGuardFile($scratch)))->toBeTrue();
        expect(restoreHoldRunBackup($scratch)['blocked'])->toBeTrue();

        restoreTargetAlignCode($scratch);

        $resumed = restoreTargetRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);

        expect($resumed['exit'])->toBe(0, $resumed['output']);

        // Cleared only after the health check AND the restored-data
        // verification, never merely because the code now matches.
        $steps = $resumed['output'];
        expect(mb_strpos($steps, 'step: verify restored data'))
            ->toBeLessThan(mb_strpos($steps, 'step: clear restore guard'));
        expect(mb_strpos($steps, 'step: health check'))
            ->toBeLessThan(mb_strpos($steps, 'step: clear restore guard'));

        expect(is_file(restoreGuardFile($scratch)))->toBeFalse();

        // And ordinary backups are no longer refused.
        expect(restoreHoldRunBackup($scratch)['blocked'])->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('writes a hold marker when a failure leaves replaced data behind, and none when nothing was touched', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        // Failed before any live mutation: the data still matches the code, so
        // blocking backups would strand the target for no reason.
        $result = restoreTargetApply($scratch, ['RGTEST_BACKUP_EXIT' => '1']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('emergency pre-restore backup failed');

        // The guard is written after the emergency backup and before the first
        // activation, so a failure here leaves none — and must not, since the
        // data still matches the code.
        expect(is_file(restoreGuardFile($scratch)))->toBeFalse();

        $backup = restoreHoldRunBackup($scratch);
        expect($backup['blocked'])->toBeFalse();
        expect($backup['reached_backup'])->toBeTrue();
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to start a second restore on a target that is already held', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreTargetHeldForCodeAlignment($scratch);

        // The second restore would take an emergency "pre-restore" backup of
        // data that does not match its code — and be refused for it, halfway
        // in. It is refused before anything is staged instead.
        $second = restoreTargetApply($scratch);

        expect($second['exit'])->not->toBe(0);
        expect($second['output'])->toContain('check restore hold');
        expect($second['output'])->toContain('is held after restore operation '.$operation);
        expect($second['output'])->not->toContain('stage backup');
    } finally {
        removeScratchDir($scratch);
    }
});
