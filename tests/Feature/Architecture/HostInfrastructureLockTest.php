<?php

use Illuminate\Support\Facades\File;

/**
 * One physical machine, several logical targets, and several operations that
 * mutate what those targets SHARE: the host roots, the shared namespace they
 * sit in, the base services, the operational bundle.
 *
 * Every lock this repository had before was keyed by backup namespace or by
 * application root, which is the wrong key for that. "Prepare staging-main"
 * and "Provision tits-guru" hold different namespace locks and then converge
 * the same machine in parallel. GitHub concurrency groups do not close it
 * either: two GitHub Environments can address one host, so the authoritative
 * answer has to live where the mutation happens.
 *
 * Two modes on that one file. The host-level operations take it EXCLUSIVELY,
 * so they run alone; every target operation — deploy, rollback, cleanup,
 * restore, recovery, the backup family — holds it SHARED for its whole run, so
 * no host-level operation converges the machine underneath one, while two
 * targets' operations still run side by side. The behaviour is proved here by
 * running real operations at the same time, and against Prepare Host in
 * PrepareHostOrchestratorTest.
 */
function hostLockScripts(): array
{
    return ['prepare-host', 'provision-target', 'configure-target', 'repair-target'];
}

function hostLockSource(string $script): string
{
    return File::get(base_path("infrastructure/scripts/{$script}"));
}

it('gives the machine one lock that is keyed by nothing', function () {
    // Keyed by nothing is the property. A lock keyed by namespace or target
    // would let two operations on one machine hold two different files and
    // both proceed, which is exactly the state this closes.
    $common = hostLockSource('common');

    expect($common)
        ->toContain('HOST_INFRASTRUCTURE_LOCK_NAME="host-infrastructure.lock"')
        ->toContain('acquire_host_infrastructure_lock');

    // No interpolation in the name: the moment it carries a namespace or a
    // target it stops being one lock per machine.
    expect($common)->not->toMatch('/host-infrastructure-\$\{/');
});

it('is taken by every operation that mutates shared host infrastructure', function (string $script, string $evidence) {
    // str_contains, not toContain: Pest reads a second argument to toContain
    // as ANOTHER NEEDLE, so the message would silently become a requirement.
    expect(str_contains(hostLockSource($script), $evidence))
        ->toBeTrue("{$script} mutates shared host infrastructure and must claim the machine");
})->with([
    // The three that source `common` take it through the shared helper, so
    // there is one implementation of what claiming a machine means.
    'provision-target' => ['provision-target', 'acquire_host_infrastructure_lock'],
    'configure-target' => ['configure-target', 'acquire_host_infrastructure_lock'],
    'repair-target' => ['repair-target', 'acquire_host_infrastructure_lock'],
    // prepare-host cannot source `common` — it runs before the operational
    // bundle exists — so it opens the same path itself. The shared thing is
    // the file NAME, not the function, and that is what is asserted of it.
    'prepare-host' => ['prepare-host', 'host-infrastructure.lock'],
]);

it('never waits, so a blocked run says so instead of looking like a hang', function (string $script) {
    // flock -n, always. A run that blocked silently would be cancelled halfway
    // through somebody else's mutation.
    $source = executableSourceLines(hostLockSource($script));

    expect($source)->toMatch('/flock -n /', "{$script} must refuse rather than wait for the host lock");
})->with(['common', 'prepare-host']);

it('names what is holding the machine, and says nothing was changed', function () {
    foreach (['common', 'prepare-host'] as $script) {
        expect(hostLockSource($script))
            ->toContain("another operation is already mutating this host's shared infrastructure");
    }

    // And the refusal names the path, so an operator can see who holds it.
    expect(hostLockSource('common'))->toContain('${lock_path}');
});

it('is taken only in a mutating mode, so a read-only child cannot deadlock its parent', function () {
    // configure-target asks provision-target --verify a question WHILE holding
    // the machine. flock treats independently opened descriptors independently
    // even inside one process tree, so a child that re-acquired what its
    // parent holds would wedge against its own orchestrator.
    foreach (['provision-target', 'configure-target', 'repair-target'] as $script) {
        $source = hostLockSource($script);

        // Exactly one acquisition, which is what makes the behavioural proof
        // sufficient: there is no second call site a read-only path could reach
        // instead. Position in the file proves nothing here and is deliberately
        // not asserted — in two of these three scripts the acquisition is
        // written ABOVE perform_apply and reached only from inside it, which is
        // ordinary Bash and says nothing about which mode runs it.
        //
        // That the read-only modes genuinely do not take it is proved where it
        // can be: by running them against a held lock, in ConfigureTargetTest.
        expect(mb_strpos($source, 'acquire_host_infrastructure_lock'))
            ->not->toBeFalse("{$script} must claim the machine");

        expect(substr_count($source, 'acquire_host_infrastructure_lock'))
            ->toBe(1, "{$script} must claim the machine in exactly one place");
    }

    // And configure-target's structural probe really is the read-only mode.
    expect(hostLockSource('configure-target'))
        ->toContain('"${PROVISION_BIN}" --verify --target "${TARGET_ID}"');
});

it('is never taken by an installer a locking orchestrator invokes', function (string $installer) {
    // The other half of one-owner-per-lock: a child must not acquire what its
    // parent holds.
    expect(str_contains(hostLockSource($installer), 'host-infrastructure.lock'))
        ->toBeFalse("{$installer} is invoked by an orchestrator that already holds the machine");
})->with([
    'install-bootstrap-host-layout',
    'install-bootstrap-services',
    'install-target-operations',
    'install-target-prerequisites',
    'install-target-database',
    'install-target-perimeter',
    'bootstrap-host',
]);

it('is taken shared by every target-only mutator, before its own target or namespace lock', function (string $script, string $evidence, ?string $ownLock) {
    // The other mode. Each of these changes or reads one target through the
    // shared services and the installed bundle, so it holds the machine
    // SHARED for its whole run: a host-level operation refuses while it runs,
    // and it refuses while one does — but two of them never exclude each other
    // through this lock, so independent targets still deploy, restore and back
    // up side by side.
    $source = executableSourceLines(hostLockSource($script));

    $shared = mb_strpos($source, $evidence);

    expect($shared)->not->toBeFalse("{$script} must hold the machine shared");

    if ($ownLock === null) {
        // The evidence carries the order itself: acquire_deployment_lock
        // shares the machine first (asserted of common below), and the
        // restore and recovery entry points take it on the line right above
        // their own lock.
        return;
    }

    $own = mb_strpos($source, $ownLock);

    expect($own)->not->toBeFalse("{$script} must still take its own lock");
    expect($shared)->toBeLessThan($own, "{$script} must take the machine before its own lock");
})->with([
    // Through acquire_deployment_lock in common, which shares the machine first.
    'deploy' => ['deploy', 'acquire_deployment_lock "${TARGET_ROOT}"', null],
    'rollback' => ['rollback', 'acquire_deployment_lock "${TARGET_ROOT}"', null],
    'cleanup' => ['cleanup', 'acquire_deployment_lock "${TARGET_ROOT}"', null],
    'record-nightwatch-deployment' => ['record-nightwatch-deployment', 'acquire_deployment_lock "${TARGET_ROOT}"', null],
    // Directly, ahead of their own namespace locks.
    'restore-target --apply' => ['restore-target', "share_host_infrastructure_lock \"restore-target --apply\"\n    acquire_restore_operation_lock\n", null],
    'restore-target --resume' => ['restore-target', "share_host_infrastructure_lock \"restore-target --resume\"\n    acquire_restore_operation_lock\n", null],
    'recover-host --apply' => ['recover-host', "share_host_infrastructure_lock \"recover-host --apply\"\n    acquire_recovery_operation_lock\n", null],
    'recover-host --resume' => ['recover-host', "share_host_infrastructure_lock \"recover-host --resume\"\n    acquire_recovery_operation_lock\n", null],
    'backup' => ['backup', 'share_host_infrastructure_lock "backup"', 'exec 9>"${LOCK_FILE}"'],
    'backup-cycle' => ['backup-cycle', 'share_host_infrastructure_lock "backup-cycle"', 'exec 9>"${LOCK_FILE}"'],
    'restore-test' => ['restore-test', 'share_host_infrastructure_lock "restore-test"', 'exec 9>"${LOCK_FILE}"'],
    'offsite-backup' => ['offsite-backup', 'share_host_infrastructure_lock "offsite-backup"', 'exec 9>"${LOCK_FILE}"'],
    'offsite-retention' => ['offsite-retention', 'share_host_infrastructure_lock "offsite-retention --apply"', 'exec 9>"${LOCK_FILE}"'],
    'offsite-restore-test' => ['offsite-restore-test', 'share_host_infrastructure_lock "offsite-restore-test"', 'exec 9>"${LOCK_FILE}"'],
    'fetch-backup' => ['fetch-backup', 'share_host_infrastructure_lock "fetch-backup"', 'exec 203>'],
]);

it('shares the machine inside acquire_deployment_lock, ahead of the deployment lock itself', function () {
    $common = hostLockSource('common');

    preg_match('/^acquire_deployment_lock\(\) \{\n(.*?)\n\}/ms', $common, $match);

    expect($match)->not->toBe([], 'common must define acquire_deployment_lock');

    $shared = mb_strpos($match[1], 'share_host_infrastructure_lock');
    $deployment = mb_strpos($match[1], 'exec 200>');

    expect($shared)->not->toBeFalse();
    expect($deployment)->not->toBeFalse();
    expect($shared)->toBeLessThan($deployment);
});

it('leaves the read-only restore and recovery modes off the machine lock', function () {
    // deploy asks restore-target --inspect and recover-host --inspect a
    // question while holding the machine itself, and recover-host asks
    // prepare-host --verify one. Only --apply and --resume take it.
    foreach (['restore-target', 'recover-host'] as $script) {
        $source = hostLockSource($script);

        expect(substr_count($source, 'share_host_infrastructure_lock'))
            ->toBe(2, "{$script} must hold the machine in --apply and --resume only");

        preg_match('/^perform_inspect\(\) \{\n(.*?)\n\}/ms', $source, $inspect);

        expect($inspect)->not->toBe([]);
        expect($inspect[1])->not->toContain('share_host_infrastructure_lock');
    }
});

it('takes the machine before the target, so two operations cannot wedge', function () {
    // repair-target holds both. Opposite orders in two runs is how a deadlock
    // is built, so the order is asserted rather than left to reading.
    $source = hostLockSource('repair-target');

    $machine = mb_strpos($source, 'acquire_host_infrastructure_lock');
    $target = mb_strpos($source, 'acquire_deployment_lock "${TARGET_ROOT}"');

    expect($machine)->not->toBeFalse();
    expect($target)->not->toBeFalse();
    expect($machine)->toBeLessThan($target, 'the machine lock must be taken before the target lock');
});

it('keeps the restore and recovery interlocks exactly as they were', function () {
    // This work added a lock; it must not have moved one. The prepare-host /
    // restore interlock is what stops a restore starting under a preparation,
    // and it is keyed by namespace on purpose.
    expect(hostLockSource('prepare-host'))
        ->toContain('prepare-host-${namespace}.lock');

    expect(File::get(base_path('infrastructure/scripts/restore-common')))
        ->toContain('assert_no_host_preparation_running');
});

// =============================================================================
// Behaviour: real operations at the same time
// =============================================================================

/**
 * Runs $body after sourcing the real `common`, with the machine lock rooted in
 * $machine. For the properties of the two modes themselves — the operations
 * that rely on them are run for real below and in PrepareHostOrchestratorTest.
 *
 * @return array{0: int, 1: string}
 */
function hostLockCommonRun(string $scratch, string $machine, string $body): array
{
    $harness = $scratch.'/common-harness-'.bin2hex(random_bytes(4)).'.sh';

    file_put_contents($harness, 'source "${RATEGURU_COMMON_FILE}"'."\n".$body."\n");

    return runInfraScript($harness, [], [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_COMMON_FILE' => base_path('infrastructure/scripts/common'),
        'RATEGURU_DEPLOYMENT_CONF_FILE' => deploymentConfFixture($scratch),
        'RATEGURU_HOST_LOCK_ROOT' => $machine,
    ]);
}

it('lets target operations on different targets run at the same time', function () {
    $scratch = makeScratchDir('host-lock', ['', '/machine']);
    $machine = $scratch.'/machine';
    $held = deployOpsScratchDir();
    $other = deployOpsScratchDir();
    $restore = restoreScratchDir();

    try {
        // One target's deployment, held inside its locks.
        $gate = $scratch.'/deploy-gate';
        $deployment = deployOpsFullDeployment($held, envOverrides: [
            'RATEGURU_HOST_LOCK_ROOT' => $machine,
            'RATEGURU_HEALTH_CHECK_BIN' => heldRunGateStub($held.'/bin/health-check-gate', $gate),
        ]);
        $run = heldRunStart(['bash', $deployment['harness']], $deployment['env'], $gate);
        heldRunAwait($run);

        expect(hostInfrastructureLockState($machine))->toBe('shared');

        // Another target's whole deployment runs to the end beside it.
        $second = deployOpsFullDeployment($other, envOverrides: ['RATEGURU_HOST_LOCK_ROOT' => $machine]);
        [$exit, $output] = deployOpsExec($second['harness'], $second['env']);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('parity-target deployed successfully');

        // And a third target's whole restore.
        restoreTargetFixture($restore);
        $result = restoreTargetApply($restore, ['RATEGURU_HOST_LOCK_ROOT' => $machine]);

        expect($result['exit'])->toBe(0, $result['output']);

        // The held deployment was never disturbed.
        [$exit, $output] = heldRunRelease($run);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('parity-target deployed successfully');
        expect(hostInfrastructureLockState($machine))->toBe('free');
    } finally {
        removeScratchDir($restore);
        deployOpsCleanup($other);
        deployOpsCleanup($held);
        removeScratchDir($scratch);
    }
});

it('lets a target operation run another beneath it: a restore and the backup it starts both hold the machine shared', function () {
    $scratch = makeScratchDir('host-lock', ['', '/machine']);
    $machine = $scratch.'/machine';
    $restore = restoreScratchDir();

    try {
        restoreTargetFixture($restore);

        // The emergency backup restore-target runs is a target operation of
        // its own, and takes the machine shared again from a fresh descriptor
        // while its parent holds it — exactly what the real `backup` does.
        $nested = $restore.'/bin/backup-nested';
        file_put_contents($nested, "#!/usr/bin/env bash\n"
            .'source "${RATEGURU_COMMON_FILE}"'."\n"
            .'share_host_infrastructure_lock "the emergency backup"'."\n"
            .'printf "%s\n" "${HOST_INFRASTRUCTURE_LOCK_MODE}" >> '.escapeshellarg($scratch.'/nested.log')."\n"
            .'exec '.escapeshellarg($restore.'/bin/backup-stub').' "$@"'."\n");
        chmod($nested, 0o755);

        $result = restoreTargetApply($restore, [
            'RATEGURU_HOST_LOCK_ROOT' => $machine,
            'RATEGURU_RESTORE_BACKUP_BIN' => $nested,
        ]);

        expect($result['exit'])->toBe(0, $result['output']);
        expect(trim((string) file_get_contents($scratch.'/nested.log')))->toBe('shared');
        expect(hostInfrastructureLockState($machine))->toBe('free');
    } finally {
        removeScratchDir($restore);
        removeScratchDir($scratch);
    }
});

it('covers a host-level operation that takes its own target deployment lock, as repair-target does', function () {
    $scratch = makeScratchDir('host-lock', ['', '/machine', '/target/locks']);

    try {
        [$exit, $output] = hostLockCommonRun($scratch, $scratch.'/machine', <<<'BASH'
            acquire_host_infrastructure_lock "repair-target --apply"
            acquire_deployment_lock "${RATEGURU_HOST_LOCK_ROOT}/../target"
            printf 'mode=%s\n' "${HOST_INFRASTRUCTURE_LOCK_MODE}"
            BASH);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('mode=exclusive');
        expect(hostInfrastructureLockState($scratch.'/machine'))->toBe('free');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses, at once and without waiting, a target operation started beneath a host-level one', function () {
    // No path in this repository does it — an exclusive holder's children are
    // installers and read-only modes, which take neither mode — but if one ever
    // did, flock sees a fresh descriptor as another holder. The cost is a
    // refusal, never a deadlock: every acquisition is non-blocking.
    $scratch = makeScratchDir('host-lock', ['', '/machine']);

    try {
        [$exit, $output] = hostLockCommonRun($scratch, $scratch.'/machine', <<<'BASH'
            acquire_host_infrastructure_lock "the parent"
            started="$(date +%s)"
            status=0
            bash -c 'source "${RATEGURU_COMMON_FILE}"; share_host_infrastructure_lock "the child"' || status=$?
            printf 'child=%s elapsed=%s\n' "${status}" "$(( $(date +%s) - started ))"
            BASH);

        expect($exit)->toBe(0, $output);
        expect($output)
            ->toContain('a host-level operation is changing this host')
            ->toMatch('/child=1 elapsed=[0-2]\n/');
    } finally {
        removeScratchDir($scratch);
    }
});

it('never upgrades a shared hold in place, because flock would release it first', function () {
    $scratch = makeScratchDir('host-lock', ['', '/machine']);

    try {
        [$exit, $output] = hostLockCommonRun($scratch, $scratch.'/machine', <<<'BASH'
            share_host_infrastructure_lock "a target operation"
            share_host_infrastructure_lock "the same one, asking again"
            acquire_host_infrastructure_lock "a host-level step"
            BASH);

        expect($exit)->toBe(1, $output);
        expect($output)->toContain('already holds it shared');
    } finally {
        removeScratchDir($scratch);
    }
});

it('runs host-level operations one at a time, and names what holds the machine when it refuses', function (int $held, string $mode, string $reported) {
    $scratch = makeScratchDir('host-lock', ['', '/machine']);
    $machine = $scratch.'/machine';
    $lock = fopen($machine.'/host-infrastructure.lock', 'c');

    try {
        expect(flock($lock, $held | LOCK_NB))->toBeTrue();

        $call = $mode === 'exclusive' ? 'acquire_host_infrastructure_lock' : 'share_host_infrastructure_lock';

        [$exit, $output] = hostLockCommonRun($scratch, $machine, "{$call} \"the operation under test\"\necho 'MUTATED'");

        expect($exit)->toBe(1, $output);
        expect($output)
            ->toContain($reported)
            ->toContain('the operation under test changed nothing')
            ->not->toContain('MUTATED');

        // The refused run took nothing with it: what is left is the holder's.
        flock($lock, LOCK_UN);
        expect(hostInfrastructureLockState($machine))->toBe('free');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
        removeScratchDir($scratch);
    }
})->with([
    'host-level against host-level' => [LOCK_EX, 'exclusive', "another operation is already mutating this host's shared infrastructure"],
    'host-level against a target operation' => [LOCK_SH, 'exclusive', 'a target operation is running on this host'],
    'a target operation against host-level' => [LOCK_EX, 'shared', 'a host-level operation is changing this host'],
]);
