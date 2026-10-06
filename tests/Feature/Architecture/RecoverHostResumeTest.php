<?php

use Illuminate\Support\Facades\File;

/**
 * Recover Host: finishing a recovery once the code is deployed.
 *
 * An apply leaves the host held, deliberately not serving, awaiting the exact
 * commit its data belongs to. --inspect reports what it is waiting for without
 * changing anything; --resume completes the recovery, or refuses while the
 * deployed code, the migration count, the health check, the scheduler or the
 * queue disagree with the data; a recovery survives its runner dying between
 * the deployment and the resume; and --verify judges the finished host. Every
 * test runs the real recover-host against the same fakes as RecoverHostTest,
 * which holds the apply itself; the preconditions are in
 * RecoverHostPreconditionsTest and the shared harness in tests/Pest.php.
 */

// =============================================================================
// --inspect
// =============================================================================

it('reports what a host is waiting for without changing anything', function () {
    $scratch = restoreScratchDir();

    try {
        $applied = recoveryApplied($scratch);
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
        $applied = recoveryApplied($scratch);
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
        [$applied, $resumed] = recoveryResumed($scratch);
        $operation = recoveryOperationIdIn($applied['output']);

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
        [$applied, $resumed] = recoveryResumed($scratch);
        $operation = recoveryOperationIdIn($applied['output']);

        expect($resumed['exit'])->toBe(0);

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
        [$applied, $resumed] = recoveryResumed($scratch);
        $operation = recoveryOperationIdIn($applied['output']);

        expect($resumed['exit'])->toBe(0);

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
            // A DIFFERENT operation, which is the whole point of the case: the
            // id below is the one every query in this loop asks about, so putting
            // it here made the record say "this operation completed on this
            // target" and recover-host was right to report it. The case proves
            // the operation field is part of the match, so the record has to
            // name another one.
            'another operation' => ['status' => 'completed', 'target' => 'parity-target', 'operation' => '20260114-031122-1a2b3c'],
            'an unfinished attempt' => ['status' => 'failed-held', 'target' => 'parity-target', 'operation' => '20260115-041233-9be21c'],
        ] as $case => $overrides) {
            File::put($journal, json_encode([...$completed, ...$overrides])."\n");

            $result = recoverHostRun($scratch, [
                '--inspect', '--target', 'parity-target', '--operation', '20260115-041233-9be21c',
            ]);

            expect(str_contains($result['output'], 'has already completed'))
                ->toBeFalse("{$case} was read as this operation completing here");
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
        $applied = recoveryApplied($scratch);
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
        $applied = recoveryApplied($scratch);
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
        $applied = recoveryApplied($scratch);
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
        $applied = recoveryApplied($scratch);
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
        $applied = recoveryApplied($scratch);
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
        $applied = recoveryApplied($scratch);
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
        $applied = recoveryApplied($scratch);
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
        $applied = recoveryApplied($scratch);
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
// --verify
// =============================================================================

it('verifies a fully recovered host, and reports its absent previous link as a fact', function () {
    $scratch = restoreScratchDir();

    try {
        [$applied, $resumed] = recoveryResumed($scratch);
        $operation = recoveryOperationIdIn($applied['output']);

        expect($resumed['exit'])->toBe(0);

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
        [$applied, $resumed] = recoveryResumed($scratch);
        $operation = recoveryOperationIdIn($applied['output']);

        expect($resumed['exit'])->toBe(0);

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
        $applied = recoveryApplied($scratch);
        expect($applied['exit'])->toBe(0, $applied['output']);

        $result = recoverHostRun($scratch, ['--verify', '--target', 'parity-target']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('still carries a recovery guard');
    } finally {
        removeScratchDir($scratch);
    }
});
