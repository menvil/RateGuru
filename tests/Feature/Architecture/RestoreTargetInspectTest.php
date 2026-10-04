<?php

use Illuminate\Support\Facades\File;

/**
 * Restore Target Data: `restore-target` — the machine-readable result and the
 * read-only --inspect mode.
 *
 * The result line is what the GitHub restore action branches on; --inspect is
 * how it looks at a held operation without resuming it. Both are proven to
 * change nothing, by snapshotting the whole observable state of a held target.
 * The whole-restore contract is in RestoreTargetTest; the shared harness is in
 * tests/Pest.php.
 */

// =============================================================================
// the controlled code alignment: the machine-readable result contract
// =============================================================================
//
// The human summary above is for operators and stays exactly as it was. This
// is the line the GitHub restore action branches on — whether the target came
// back or is waiting for its code, and which commit it is waiting for — and
// the properties asserted here are precisely the ones a consumer relies on:
// exactly one object, every field present, and no secret anywhere in it.

/** The single machine-readable result line a terminal restore printed. */
function restoreTargetResult(string $output): array
{
    preg_match_all('/^RATEGURU_RESTORE_RESULT=(.*)$/m', $output, $matches);

    expect($matches[1])->toHaveCount(1, "expected exactly one machine-readable result in:\n".$output);

    $decoded = json_decode(trim($matches[1][0]), true);

    expect($decoded)->toBeArray("machine-readable result is not a JSON object: {$matches[1][0]}");

    return $decoded;
}

/** @return array<string, string> */
function restoreTargetGuard(string $scratch): array
{
    return json_decode(File::get(restoreGuardFile($scratch)), true);
}

/** @param  array<string, string|null>  $overrides */
function restoreTargetPatchGuard(string $scratch, array $overrides): void
{
    $guard = array_filter(
        array_merge(restoreTargetGuard($scratch), $overrides),
        static fn ($value): bool => $value !== null,
    );

    file_put_contents(restoreGuardFile($scratch), json_encode($guard, JSON_PRETTY_PRINT));
}

function restoreTargetStatePath(string $scratch, string $operation): string
{
    return $scratch.'/run/restores/parity-target/'.$operation.'/state.json';
}

/** @param  array<string, string|null>  $overrides */
function restoreTargetPatchState(string $scratch, string $operation, array $overrides): void
{
    $path = restoreTargetStatePath($scratch, $operation);

    $state = array_filter(
        array_merge(json_decode(File::get($path), true), $overrides),
        static fn ($value): bool => $value !== null,
    );

    file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT));
}

function restoreTargetInspect(string $scratch, string $operation, array $envOverrides = []): array
{
    return restoreTargetRun(
        $scratch,
        ['--inspect', '--target', 'parity-target', '--operation', $operation],
        $envOverrides,
    );
}

/**
 * A held target's complete observable state, so a read-only mode can be proven
 * to have changed none of it rather than merely asserted to be read-only.
 *
 * @return array<string, mixed>
 */
function restoreTargetHeldSnapshot(string $scratch, string $operation): array
{
    return [
        'maintenance' => restoreTargetMaintenanceActive($scratch),
        'queue' => restoreTargetQueueState($scratch),
        'scheduler' => restoreTargetSchedulerPresent($scratch),
        'current' => readlink($scratch.'/target/current'),
        'databases' => fakePostgresDatabases($scratch),
        'guard' => File::get(restoreGuardFile($scratch)),
        'state' => File::get(restoreTargetStatePath($scratch, $operation)),
        'storage' => is_file(restoreTargetStorage($scratch).'/app/restored-marker.txt'),
        'history' => restoreTargetHistory($scratch),
        'health_checks' => File::get($scratch.'/health-check.log'),
    ];
}

it('prints exactly one machine-readable result for an aligned restore', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetApply($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        expect(restoreTargetResult($result['output']))->toBe([
            'status' => 'completed',
            'operation_id' => operationIdsIn($result['output'])[0],
            'target' => 'parity-target',
            'backup' => '20260115-120000',
            'backup_release' => FIXTURE_RELEASE,
            'backup_source_sha' => FIXTURE_SOURCE_SHA,
            'required_source_sha' => FIXTURE_SOURCE_SHA,
            'current_source_sha' => FIXTURE_SOURCE_SHA,
            'code_alignment' => 'ALIGNED',
            'runtime_resumed' => 'yes',
        ]);

        // The human summary is unchanged and still comes first: the machine
        // line is an addition, never a replacement.
        expect(mb_strpos($result['output'], 'RESTORE DATA COMPLETE: YES'))
            ->toBeLessThan(mb_strpos($result['output'], 'RATEGURU_RESTORE_RESULT='));
    } finally {
        removeScratchDir($scratch);
    }
});

it('prints exactly one machine-readable result for a held restore, naming the commit it needs', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $result = restoreTargetApply($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        expect(restoreTargetResult($result['output']))->toBe([
            'status' => 'held',
            'operation_id' => operationIdsIn($result['output'])[0],
            'target' => 'parity-target',
            'backup' => '20260115-120000',
            'backup_release' => FIXTURE_RELEASE,
            'backup_source_sha' => FIXTURE_SOURCE_SHA,
            'required_source_sha' => FIXTURE_SOURCE_SHA,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
            'code_alignment' => 'REQUIRED',
            'runtime_resumed' => 'no',
        ]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('prints exactly one machine-readable result for a resume', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $operation = restoreTargetHeldOperation($scratch);
        restoreTargetAlignCode($scratch);

        $result = restoreTargetRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);
        expect($result['exit'])->toBe(0, $result['output']);

        expect(restoreTargetResult($result['output']))->toBe([
            'status' => 'resumed',
            'operation_id' => $operation,
            'target' => 'parity-target',
            'backup' => '20260115-120000',
            'backup_release' => FIXTURE_RELEASE,
            'backup_source_sha' => FIXTURE_SOURCE_SHA,
            'required_source_sha' => FIXTURE_SOURCE_SHA,
            'current_source_sha' => FIXTURE_SOURCE_SHA,
            'code_alignment' => 'ALIGNED',
            'runtime_resumed' => 'yes',
        ]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('never puts a secret in the machine-readable result', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $result = restoreTargetApply($scratch);
        $decoded = restoreTargetResult($result['output']);
        $encoded = json_encode($decoded);

        // The fixture .env carries this password; nothing derived from it may
        // reach a line a workflow log will publish.
        foreach (['s3cr3t-not-logged', 'DB_PASSWORD', 'PGPASSWORD', 'rclone', 'authorized_keys', $scratch] as $secret) {
            expect($encoded)->not->toContain($secret);
        }

        // And the field set is closed: nothing may be added to it by accident.
        expect(array_keys($decoded))->toBe([
            'status', 'operation_id', 'target', 'backup', 'backup_release',
            'backup_source_sha', 'required_source_sha', 'current_source_sha',
            'code_alignment', 'runtime_resumed',
        ]);
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// the controlled code alignment: --inspect
// =============================================================================

it('reports a held operation and changes absolutely nothing', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $operation = restoreTargetHeldOperation($scratch);
        $before = restoreTargetHeldSnapshot($scratch, $operation);

        $result = restoreTargetInspect($scratch, $operation);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('STATUS: HELD')
            ->toContain('REQUIRED SOURCE SHA: '.FIXTURE_SOURCE_SHA)
            ->toContain('CURRENT SOURCE SHA: '.FIXTURE_OTHER_SOURCE_SHA)
            ->toContain('nothing was changed');

        expect(restoreTargetResult($result['output']))->toBe([
            'status' => 'held',
            'operation_id' => $operation,
            'target' => 'parity-target',
            'backup' => '20260115-120000',
            'backup_release' => FIXTURE_RELEASE,
            'backup_source_sha' => FIXTURE_SOURCE_SHA,
            'required_source_sha' => FIXTURE_SOURCE_SHA,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
            'code_alignment' => 'REQUIRED',
            'runtime_resumed' => 'no',
        ]);

        // Read-only means read-only: not one observable fact about the target,
        // the operation or the journal moved.
        expect(restoreTargetHeldSnapshot($scratch, $operation))->toBe($before);

        // And specifically none of the resume actions ran.
        expect(File::get($scratch.'/php.log'))->not->toContain('artisan up');
        expect(File::get($scratch.'/supervisor.log'))->not->toContain('start');
    } finally {
        removeScratchDir($scratch);
    }
});

it('inspects the same operation repeatedly without ever resuming it', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $operation = restoreTargetHeldOperation($scratch);

        foreach (range(1, 3) as $attempt) {
            $result = restoreTargetInspect($scratch, $operation);
            expect($result['exit'])->toBe(0, "inspect attempt {$attempt}:\n".$result['output']);
        }

        expect(restoreTargetMaintenanceActive($scratch))->toBeTrue();
        expect(restoreTargetQueueState($scratch))->toBe('STOPPED');
        expect(restoreTargetSchedulerPresent($scratch))->toBeFalse();
        expect(is_file(restoreGuardFile($scratch)))->toBeTrue();
        expect(restoreTargetHistory($scratch))->toHaveCount(1);
    } finally {
        removeScratchDir($scratch);
    }
});

it('takes no backup and no source, and requires an operation', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $noOperation = restoreTargetRun($scratch, ['--inspect', '--target', 'parity-target']);
        expect($noOperation['exit'])->not->toBe(0);
        expect($noOperation['output'])->toContain('--inspect requires --operation');

        $withBackup = restoreTargetRun($scratch, [
            '--inspect', '--target', 'parity-target',
            '--operation', '20260115-120000-abcdef', '--backup', '20260115-120000',
        ]);
        expect($withBackup['exit'])->not->toBe(0);
        expect($withBackup['output'])->toContain('--backup is only valid with --apply');

        $withSource = restoreTargetRun($scratch, [
            '--inspect', '--target', 'parity-target',
            '--operation', '20260115-120000-abcdef', '--source', 'offsite',
        ]);
        expect($withSource['exit'])->not->toBe(0);
        expect($withSource['output'])->toContain('--source is only valid with --apply');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to inspect an operation that is not this target own held code alignment', function (
    string $case,
    string $expected,
) {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $operation = restoreTargetHeldOperation($scratch);
        $before = restoreTargetHeldSnapshot($scratch, $operation);
        $requested = $operation;

        switch ($case) {
            case 'unknown-operation':
                $requested = '20200101-000000-abcdef';
                break;

            case 'guard-names-another-operation':
                restoreTargetPatchGuard($scratch, ['operation' => '20200101-000000-abcdef']);
                break;

            case 'guard-names-another-target':
                restoreTargetPatchGuard($scratch, ['target' => 'other-target']);
                break;

            case 'guard-missing':
                unlink(restoreGuardFile($scratch));
                break;

            case 'guard-is-in-progress':
                restoreTargetPatchGuard($scratch, ['status' => 'in-progress']);
                break;

            case 'guard-is-failed-held':
                restoreTargetPatchGuard($scratch, ['status' => 'failed-held']);
                break;

            case 'guard-and-state-disagree-about-the-commit':
                restoreTargetPatchGuard($scratch, ['required_source_sha' => FIXTURE_OTHER_SOURCE_SHA]);
                break;

            case 'abbreviated-required-commit':
                restoreTargetPatchGuard($scratch, ['required_source_sha' => 'a81d7f2']);
                restoreTargetPatchState($scratch, $operation, ['backup_source_sha' => 'a81d7f2']);
                break;

            case 'state-status-is-not-held':
                restoreTargetPatchState($scratch, $operation, ['status' => 'running']);
                break;

            case 'state-is-not-committed':
                restoreTargetPatchState($scratch, $operation, ['phase' => 'quiesced']);
                break;

            case 'state-alignment-is-not-required':
                restoreTargetPatchState($scratch, $operation, ['code_alignment' => 'aligned']);
                break;

            case 'state-names-another-target':
                restoreTargetPatchState($scratch, $operation, ['target' => 'other-target']);
                break;

            case 'state-names-no-backup':
                restoreTargetPatchState($scratch, $operation, ['backup' => null]);
                break;

            case 'state-is-not-an-object':
                file_put_contents(restoreTargetStatePath($scratch, $operation), "not json\n");
                break;
        }

        $result = restoreTargetInspect($scratch, $requested);

        expect($result['exit'])->not->toBe(0, $result['output']);
        expect($result['output'])->toContain($expected);
        expect($result['output'])->not->toContain('RATEGURU_RESTORE_RESULT=');

        // Every refusal leaves the target exactly as held as it was, except
        // for whatever this case deliberately edited itself.
        expect(restoreTargetMaintenanceActive($scratch))->toBe($before['maintenance']);
        expect(restoreTargetQueueState($scratch))->toBe($before['queue']);
        expect(restoreTargetSchedulerPresent($scratch))->toBe($before['scheduler']);
        expect(readlink($scratch.'/target/current'))->toBe($before['current']);
        expect(fakePostgresDatabases($scratch))->toBe($before['databases']);
        expect(restoreTargetHistory($scratch))->toBe($before['history']);
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    ['unknown-operation', 'is held by restore operation'],
    ['guard-names-another-operation', 'is held by restore operation'],
    ['guard-names-another-target', 'belongs to target other-target'],
    ['guard-missing', 'is not held: no restore guard'],
    // The two statuses that are NOT a code-alignment hold get a diagnosis of
    // their own rather than a generic refusal: they need manual recovery,
    // never a build and never a deployment.
    ['guard-is-in-progress', 'It is NOT a code-alignment hold'],
    ['guard-is-failed-held', 'Repair Target is not a way out either'],
    ['guard-and-state-disagree-about-the-commit', 'refusing to align a target whose own restore documents disagree'],
    ['abbreviated-required-commit', 'no full 40-character commit'],
    ['state-status-is-not-held', "has status 'running'"],
    ['state-is-not-committed', "is in phase 'quiesced'"],
    ['state-alignment-is-not-required', "records code_alignment 'aligned'"],
    ['state-names-another-target', 'belongs to target other-target'],
    ['state-names-no-backup', 'records no backup identity'],
    ['state-is-not-an-object', 'is not a JSON object'],
]);

it('refuses to report a target as held once the hold itself is gone', function (
    string $case,
    array $env,
    string $expected,
) {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => FIXTURE_OTHER_RELEASE,
            'current_source_sha' => FIXTURE_OTHER_SOURCE_SHA,
        ]);

        $operation = restoreTargetHeldOperation($scratch);

        // Something outside this operation undid part of the hold: someone ran
        // `artisan up`, started the worker, or put the cron entry back. The
        // restore documents still say "held", but the target is no longer
        // safe to deploy an alignment into, and inspect must say so rather
        // than report a hold that is not there.
        switch ($case) {
            case 'maintenance-off':
                unlink(restoreTargetStorage($scratch).'/framework/down');
                break;

            case 'queue-running':
                file_put_contents($scratch.'/supervisor-state', "RUNNING\n");
                break;

            case 'queue-partly-running':
                file_put_contents($scratch.'/supervisor-second-state', "RUNNING\n");
                break;

            case 'scheduler-present':
                file_put_contents($scratch.'/cron.d/parity-scheduler', "* * * * * runtime true\n");
                break;
        }

        $result = restoreTargetInspect($scratch, $operation, $env);

        expect($result['exit'])->not->toBe(0, $result['output']);
        expect($result['output'])->toContain($expected);
        expect($result['output'])->not->toContain('RATEGURU_RESTORE_RESULT=');
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    ['maintenance-off', [], 'is NOT in maintenance mode'],
    ['queue-running', [], 'is not fully STOPPED'],
    ['queue-partly-running', [], 'is not fully STOPPED'],
    ['scheduler-present', [], 'a scheduled writer can fire against this target'],
    ['observation-fails', ['RGTEST_SUPERVISOR_STATUS_FAILURE' => 'supervisord is unreachable'], 'cannot observe the target queue program'],
]);

it('refuses to inspect a planned target before reading anything', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetRun($scratch, [
            '--inspect', '--target', 'planned-target', '--operation', '20260115-120000-abcdef',
        ]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('lifecycle=planned');
    } finally {
        removeScratchDir($scratch);
    }
});
