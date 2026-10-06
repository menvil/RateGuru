<?php

use Illuminate\Support\Facades\File;

/**
 * The data operations' side of the host preparation interlock:
 * restore-common's assert_no_host_preparation_running, run for real through
 * both operation families that call it.
 *
 * Prepare Host holds prepare-host-<namespace>.lock for its whole run while it
 * reconverges the target's Supervisor program and scheduler cron entry — the
 * very things a held restore or recovery keeps aside — and replaces the
 * operational bundle these scripts run from. A restore or a recovery takes its
 * own lock first and then checks that one, so whichever starts second refuses.
 * The ordering itself is asserted in RecoverHostScopeTest; this file proves
 * the refusal changes nothing, and that a lock file a finished preparation left
 * behind is not mistaken for a running one.
 *
 * The preparation is played by this test process holding the lock with
 * flock(2), the same call `flock -n` makes, so no background holder has to be
 * started and waited for.
 */

/**
 * Runs $body while this process holds the preparation lock for the parity
 * backup namespace, and leaves the lock file behind afterwards, exactly as a
 * finished Prepare Host does.
 */
function whilePreparingParityHost(string $scratch, Closure $body): mixed
{
    @mkdir($scratch.'/run', 0o700, true);
    $handle = fopen($scratch.'/run/prepare-host-parity.lock', 'c');
    expect(flock($handle, LOCK_EX | LOCK_NB))->toBeTrue('could not take the preparation lock');

    try {
        return $body();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

it('refuses to resume a recovery while Prepare Host is converging the host, and resumes once it has finished', function () {
    $scratch = restoreScratchDir();

    try {
        $applied = recoveryApplied($scratch);
        $operation = recoveryOperationIdIn($applied['output']);
        deployRecoveredRelease($scratch);

        $guard = recoveryGuard($scratch);
        $state = recoveryOperationState($scratch, $operation);
        $resume = fn (): array => recoverHostRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);

        $refused = whilePreparingParityHost($scratch, $resume);

        expect($refused['exit'])->not->toBe(0);
        expect($refused['output'])
            ->toContain('Prepare Host is converging this host for backup namespace parity — a recovery is refused while it holds')
            ->toContain('nothing was changed')
            ->not->toContain('step: load operation state');

        // Still exactly the held recovery it was: nothing started, nothing put
        // back, nothing re-labelled.
        expect(recoveryGuard($scratch))->toBe($guard);
        expect(recoveryOperationState($scratch, $operation))->toBe($state);
        expect(File::exists($scratch.'/cron.d/parity-scheduler'))->toBeFalse();
        expect(File::get($scratch.'/supervisor.log'))->not->toContain('supervisorctl start');

        // The preparation finished and left its lock file: a free lock is a
        // finished preparation, and the same resume now completes.
        $resumed = $resume();

        expect($resumed['exit'])->toBe(0, $resumed['output']);
        expect($resumed['output'])->toContain('HOST RECOVERY COMPLETE: parity-target');
        expect(recoveryGuard($scratch))->toBeNull();
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to resume a restore while Prepare Host is converging the host, and resumes once it has finished', function () {
    $scratch = restoreScratchDir();

    try {
        $operation = restoreTargetHeldForCodeAlignment($scratch);
        restoreTargetAlignCode($scratch);

        $guard = File::get(restoreGuardFile($scratch));
        $resume = fn (): array => restoreTargetRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);

        $refused = whilePreparingParityHost($scratch, $resume);

        expect($refused['exit'])->not->toBe(0);
        expect($refused['output'])
            ->toContain('Prepare Host is converging this host for backup namespace parity — a restore is refused while it holds')
            ->toContain('nothing was changed')
            ->not->toContain('step: load operation state');

        // Still held, exactly as the restore left it.
        expect(File::get(restoreGuardFile($scratch)))->toBe($guard);
        expect(restoreTargetMaintenanceActive($scratch))->toBeTrue();
        expect(restoreTargetQueueState($scratch))->toBe('STOPPED');
        expect(restoreTargetSchedulerPresent($scratch))->toBeFalse();
        expect(restoreTargetHistory($scratch))->toHaveCount(1);

        $resumed = $resume();

        expect($resumed['exit'])->toBe(0, $resumed['output']);
        expect(restoreTargetHistory($scratch)[1])->toMatchArray(['status' => 'resumed']);
        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
        expect(is_file(restoreGuardFile($scratch)))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});
