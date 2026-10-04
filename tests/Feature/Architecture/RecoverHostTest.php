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
 *
 * This file holds the apply: the offsite-write hold, the successful recovery,
 * activation failure and compensation, the guard that owns the whole
 * operation, the hold that is observed rather than asserted, the reversible
 * storage baseline and isolation from everything else on the host. What a
 * recovery refuses before it starts is in RecoverHostPreconditionsTest, and
 * --inspect, --resume and --verify in RecoverHostResumeTest; the harness they
 * all share (script, run, fixture, apply) lives in tests/Pest.php.
 */

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
