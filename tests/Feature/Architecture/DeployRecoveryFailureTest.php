<?php

/**
 * What deploy does when its own recovery goes wrong.
 *
 * A failed deployment is recovered by deploy's exit handler: it points
 * `current` and `previous` back where they were, reloads PHP-FPM, stops a
 * queue worker this deployment started (or re-signals one it restarted),
 * records the failure and removes the failed release. DeployTest proves that
 * path when every step of it works. This file proves what happens when a step
 * of it does not: the deployment still fails with its own status, the operator
 * is told which part of the recovery did not happen, the history still records
 * the terminal status, and nothing is deleted that `current` or a worker that
 * could not be stopped may still be using. A failed release is removed only
 * once recovery has fully succeeded.
 *
 * Every failure is injected into the real script at the moment it would happen
 * on a host: through the deployment stubs DeployTest uses (tests/Pest.php), a
 * health check that changes the host before it fails, or a PATH-shadowed
 * coreutil that refuses exactly one operation and passes everything else
 * through to the real binary.
 */

/**
 * One deployment of $releaseId onto $fixture's target, whose post-switch health
 * check is $healthCheck: the body of a bash script that ends in the check's own
 * exit status. The health check runs after `current` has been switched and
 * before anything else, so a body that changes the host and then fails is how a
 * test sets up the state recovery will meet.
 *
 * @return array{exit: int, output: string}
 */
function deployRecoveryDeploy(string $scratch, array $fixture, string $releaseId, string $healthCheck): array
{
    deployOpsInstallCoreStubs($scratch);
    [$registryPath, $targetsPath] = deployOpsParityRegistry($scratch, $fixture);

    $verifyCliLog = $scratch.'/verify-cli-'.uniqid('', true).'.log';
    touch($verifyCliLog);

    $healthCheckStub = writeExecutable(
        $scratch.'/bin/health-check-'.uniqid('', true),
        "#!/usr/bin/env bash\n".$healthCheck."\n",
    );

    [$exit, $output] = deployOpsRunHarness(
        $scratch,
        "parse_deploy_args --target parity-target --release {$releaseId} --artifact {$fixture['artifact']}\nresolve_target\nperform_deploy",
        deployOpsBaseEnv($scratch, [
            'RATEGURU_DEPLOYMENT_CONF_FILE' => deployOpsDeploymentConfForFixture($scratch),
            'RATEGURU_TARGET_REGISTRY_FILE' => $registryPath,
            'RATEGURU_TARGETS_CLI' => $targetsPath,
            'RATEGURU_HEALTH_CHECK_BIN' => $healthCheckStub,
            'RATEGURU_VERIFY_REQUIRED_CLIS_BIN' => deployOpsVerifyRequiredClisStub($scratch, $verifyCliLog),
        ]),
    );

    return ['exit' => $exit, 'output' => $output];
}

/**
 * Shadows coreutil $tool on the scratch PATH with one that refuses — the way
 * a read-only or full filesystem refuses — any invocation naming a path that
 * ends in $suffix, and runs the real $tool for everything else. Recovery's
 * scratch links are `<link>.recovery`, so a suffix picks out exactly the step
 * under test without disturbing the deployment that leads up to it.
 */
function deployRecoveryRefuse(string $scratch, string $tool, string $suffix): void
{
    $real = trim((string) shell_exec('command -v '.escapeshellarg($tool)));
    expect($real)->not->toBe('', "no real {$tool} binary found");

    writeExecutable($scratch.'/bin/'.$tool, "#!/usr/bin/env bash\n"
        ."for arg in \"\$@\"; do\n"
        .'    if [[ "${arg}" == *'.escapeshellarg($suffix)." ]]; then\n"
        .'        echo '.escapeshellarg($tool.': ').'"${arg}"'.escapeshellarg(': Read-only file system')." >&2\n"
        ."        exit 1\n"
        ."    fi\n"
        ."done\n"
        .'exec '.escapeshellarg($real)." \"\$@\"\n");
}

/** The last history record a deployment wrote. */
function deployRecoveryLastHistory(string $root): array
{
    $history = deployOpsHistory($root);
    expect($history)->not->toBe([], 'the deployment wrote no history at all');

    return end($history);
}

// =============================================================================
// Release links that cannot be put back
// =============================================================================

it('keeps serving the failed release, rather than nothing, when current cannot be pointed back', function (?string $refusingTool) {
    $scratch = deployOpsScratchDir();

    try {
        $first = deployOpsRunFullDeployment($scratch);
        expect($first['exit'])->toBe(0, $first['output']);

        $root = $first['fixture']['root'];
        $original = (string) realpath($root.'/releases/'.$first['releaseId']);
        $failed = 'v1.1.0-20260201-000000-bad1111';
        $healthCheck = 'exit 1';

        if ($refusingTool === null) {
            // Deleted between the switch and the recovery: there is nothing
            // left for current to point back at.
            $healthCheck = 'rm -rf '.escapeshellarg($original)."\nexit 1";
        } else {
            deployRecoveryRefuse($scratch, $refusingTool, '/current.recovery');
        }

        $result = deployRecoveryDeploy($scratch, $first['fixture'], $failed, $healthCheck);

        expect($result['exit'])->not->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('deployment health check failed')
            ->toContain('deployment failed after switching current; restoring prior links')
            ->toContain('WARNING: unable to fully restore deployment symlinks');

        if ($refusingTool === null) {
            expect($result['output'])->toContain('WARNING: original symlink target is unavailable: '.$original);
        }

        // current was never left dangling or missing: it still names the
        // failed release, and that release is still there to be served. A
        // recovery that removed it would turn a failed deployment into an
        // outage.
        expect(is_link($root.'/current'))->toBeTrue('current must not be removed by a recovery that could not replace it');
        expect(realpath($root.'/current'))->toBe(realpath($root.'/releases/'.$failed));
        expect(is_dir($root.'/releases/'.$failed))->toBeTrue('a release current still points at must never be deleted');

        // No half-made recovery link is left behind for the next deployment
        // to trip over.
        expect(glob($root.'/*.recovery'))->toBe([]);
        expect(is_link($root.'/previous'))->toBeFalse('previous must not be created by a failed deployment');

        $last = deployRecoveryLastHistory($root);
        expect($last['event'])->toBe('deployment-finished');
        expect($last['release'])->toBe($failed);
        expect($last['status'])->toBe('failed-health-check');
    } finally {
        deployOpsCleanup($scratch);
    }
})->with([
    'the original release is gone' => [null],
    'the recovery link cannot be created' => ['ln'],
    'the recovery link cannot be moved into place' => ['mv'],
]);

it('keeps a failed first release when the current link it created cannot be removed again', function () {
    $scratch = deployOpsScratchDir();

    try {
        // A first deployment has no prior release, so recovery puts the target
        // back by removing current — and here that removal is refused.
        $fixture = deployOpsBuildFixture($scratch);
        $release = 'v1.0.0-20260101-000000-bad0001';
        deployRecoveryRefuse($scratch, 'rm', '/current');

        $result = deployRecoveryDeploy($scratch, $fixture, $release, 'exit 1');

        expect($result['exit'])->not->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('deployment health check failed')
            ->toContain('WARNING: unable to fully restore deployment symlinks');

        $root = $fixture['root'];

        // The link survived, so the release it names must survive too.
        expect(realpath($root.'/current'))->toBe(realpath($root.'/releases/'.$release));
        expect(is_dir($root.'/releases/'.$release))->toBeTrue('a release current still points at must never be deleted');

        expect(deployRecoveryLastHistory($root)['status'])->toBe('failed-health-check');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('still puts current back when previous cannot be restored, and reports the recovery as incomplete', function () {
    $scratch = deployOpsScratchDir();

    try {
        $first = deployOpsRunFullDeployment($scratch);
        expect($first['exit'])->toBe(0, $first['output']);

        $root = $first['fixture']['root'];
        $second = 'v1.1.0-20260201-000000-aaa2222';
        $failed = 'v1.2.0-20260301-000000-bad3333';

        $result = deployRecoveryDeploy($scratch, $first['fixture'], $second, 'exit 0');
        expect($result['exit'])->toBe(0, $result['output']);
        expect(realpath($root.'/previous'))->toBe(realpath($root.'/releases/'.$first['releaseId']));

        // Each link is restored on its own: a failure on previous must not
        // stop current from going back.
        deployRecoveryRefuse($scratch, 'ln', '/previous.recovery');

        $result = deployRecoveryDeploy($scratch, $first['fixture'], $failed, 'exit 1');

        expect($result['exit'])->not->toBe(0, $result['output']);
        expect($result['output'])->toContain('WARNING: unable to fully restore deployment symlinks');

        expect(realpath($root.'/current'))->toBe(realpath($root.'/releases/'.$second), 'current must be restored even though previous could not be');
        expect(realpath($root.'/previous'))->toBe(realpath($root.'/releases/'.$first['releaseId']), 'the failed deployment never moved previous');
        expect(glob($root.'/*.recovery'))->toBe([]);

        // Recovery was not complete, so the failed release stays for the
        // operator to look at rather than being cleaned away with the evidence.
        expect(is_dir($root.'/releases/'.$failed))->toBeTrue();

        expect(deployRecoveryLastHistory($root)['status'])->toBe('failed-health-check');
    } finally {
        deployOpsCleanup($scratch);
    }
});

// =============================================================================
// Supervisor activation and its recovery
// =============================================================================

it('fails a first deployment whose worker activation supervisor refuses, and stops only a worker it registered', function (string $toggle, string $message, bool $registered) {
    $scratch = deployOpsScratchDir();

    try {
        deployOpsInstallCoreStubs($scratch);
        touch($scratch.'/supervisor-state/'.$toggle);

        $result = deployOpsRunFullDeployment($scratch);

        expect($result['exit'])->not->toBe(0, $result['output']);
        expect($result['output'])->toContain($message);

        $root = $result['fixture']['root'];
        $calls = implode("\n", deployOpsSupervisorctlLog($scratch));

        // A failed reread registered nothing, so there is nothing to stop; once
        // update has been asked for, the program may be registered and running,
        // and it must not be left running against a current that is about to
        // disappear.
        if ($registered) {
            expect($calls)->toContain('supervisorctl update parity-queue')
                ->toContain('supervisorctl stop parity-queue:*');
        } else {
            expect($calls)->not->toContain('supervisorctl update')
                ->not->toContain('supervisorctl stop');
        }

        expect($calls)->not->toContain('supervisorctl start');

        // The rest of the recovery is the ordinary first-deployment one: no
        // current, no release, and the activation failure on record.
        expect(is_link($root.'/current'))->toBeFalse();
        expect(glob($root.'/releases/*'))->toBe([]);

        $last = deployRecoveryLastHistory($root);
        expect($last['event'])->toBe('deployment-finished');
        expect($last['status'])->toBe('failed-supervisor-activation');
    } finally {
        deployOpsCleanup($scratch);
    }
})->with([
    'reread refused' => ['reread-fail', 'supervisorctl reread failed', false],
    'update refused' => ['update-fail', 'supervisorctl update parity-queue failed', true],
]);

it('keeps the failed release on disk when the worker started against it cannot be stopped', function () {
    $scratch = deployOpsScratchDir();

    try {
        // The worker never reaches RUNNING, so the deployment fails — and the
        // worker this deployment started will not stop either.
        deployOpsInstallCoreStubs($scratch);
        touch($scratch.'/supervisor-state/activation-fail');
        touch($scratch.'/supervisor-state/stop-fail');

        $result = deployOpsRunFullDeployment($scratch);

        expect($result['exit'])->not->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('supervisor worker parity-queue did not reach RUNNING')
            ->toContain('WARNING: unable to stop supervisor worker parity-queue during recovery');

        $root = $result['fixture']['root'];

        // current is gone, exactly as for any failed first deployment...
        expect(is_link($root.'/current'))->toBeFalse();

        // ...but a process may still be running inside the release, so the
        // release directory is not deleted out from under it.
        expect(is_dir($root.'/releases/'.$result['releaseId']))->toBeTrue('never delete a release a worker may still be running in');

        expect(deployRecoveryLastHistory($root)['status'])->toBe('failed-supervisor-activation');
    } finally {
        deployOpsCleanup($scratch);
    }
});

it('keeps the failed release when the queue cannot be re-signalled from the restored one', function () {
    $scratch = deployOpsScratchDir();

    try {
        deployOpsInstallCoreStubs($scratch);
        touch($scratch.'/supervisor-state/queue-running');

        $oldRelease = deployOpsReleaseDirWithArtisan($scratch);
        $newRelease = deployOpsReleaseDirWithArtisan($scratch);
        $target = $scratch.'/recovery-target';
        expect(@mkdir($target.'/deployments', 0o755, true))->toBeTrue();
        symlink($newRelease, $target.'/current');

        // The state a deployment leaves when it signalled the running workers
        // to restart and then failed: they may already have rebooted into the
        // new release. Recovery re-signals them from the restored one — and
        // that signal cannot be written either.
        $phpBin = deployOpsFailingQueueRestartPhpBin($scratch);

        $body = <<<BASH
            SUPERVISOR_PROGRAM=parity-queue
            TARGET_ROOT={$target}
            RELEASE_ID=v9.9.9-20260101-000000-abc0000
            RELEASE_ROOT={$newRelease}
            TEMP_RELEASE_ROOT={$scratch}/nonexistent-temp
            CURRENT_LINK={$target}/current
            PREVIOUS_LINK={$target}/previous
            ORIGINAL_CURRENT_PRESENT=true
            ORIGINAL_CURRENT_PATH={$oldRelease}
            ORIGINAL_PREVIOUS_PRESENT=false
            ORIGINAL_PREVIOUS_PATH=""
            ORIGINAL_QUEUE_RUNNING=true
            SUPERVISOR_ACTIVATED_NOW=false
            QUEUE_RESTART_ISSUED=true
            DEPLOYMENT_STARTED=true
            CURRENT_SWITCHED=true
            TERMINAL_HISTORY_WRITTEN=false
            FAILURE_STATUS=failed-queue-restart
            RUNTIME_USER="\$(id -un)"
            PHP_BIN={$phpBin}
            # set +e so the deliberate failure status reaches the handler
            # instead of aborting the harness under set -e.
            set +e
            false
            handle_deployment_exit
            BASH;

        [$exit, $output] = deployOpsRunHarness($scratch, $body, deployOpsBaseEnv($scratch));

        expect($exit)->toBe(1, $output);
        expect($output)
            ->toContain('re-issuing the queue restart signal from the restored release')
            ->toContain('WARNING: unable to re-issue the queue restart signal against the restored release');

        // The links are back on the old release...
        expect(realpath($target.'/current'))->toBe(realpath($oldRelease));

        // ...but workers that booted the failed release were never told to
        // leave it, so it is not deleted underneath them.
        expect(is_dir($newRelease))->toBeTrue('never delete a release a worker may still be running in');

        // Recovery observes the worker; it never stops, starts or restarts it.
        foreach (deployOpsSupervisorctlLog($scratch) as $call) {
            expect(str_starts_with($call, 'supervisorctl status parity-queue:'))
                ->toBeTrue("recovery must not mutate supervisor state here: {$call}");
        }

        $last = deployRecoveryLastHistory($target);
        expect($last['event'])->toBe('deployment-finished');
        expect($last['status'])->toBe('failed-queue-restart');
    } finally {
        deployOpsCleanup($scratch);
    }
});

// =============================================================================
// A failure that cannot be recorded
// =============================================================================

it('still recovers, and keeps its own failure status, when the failed deployment cannot be recorded', function () {
    $scratch = deployOpsScratchDir();

    try {
        $first = deployOpsRunFullDeployment($scratch);
        expect($first['exit'])->toBe(0, $first['output']);

        $root = $first['fixture']['root'];
        $history = $root.'/deployments/history.jsonl';
        $failed = 'v1.1.0-20260201-000000-bad4444';

        // Between the switch and the recovery the history file stops accepting
        // appends: it is moved aside and a directory takes its place, which
        // refuses an append for every user, root included — a read-only file
        // would not.
        $healthCheck = 'mv '.escapeshellarg($history).' '.escapeshellarg($history.'.aside')."\n"
            .'mkdir '.escapeshellarg($history)."\n"
            .'exit 1';

        $result = deployRecoveryDeploy($scratch, $first['fixture'], $failed, $healthCheck);

        // The health check's failure is still what the deployment reports.
        expect($result['exit'])->toBe(1, $result['output']);
        expect($result['output'])
            ->toContain('deployment health check failed')
            ->toContain('WARNING: unable to record failed deployment')
            ->not->toContain('unable to fully restore deployment symlinks');

        // Everything else recovery owns still happened.
        expect(realpath($root.'/current'))->toBe(realpath($root.'/releases/'.$first['releaseId']));
        expect(is_dir($root.'/releases/'.$failed))->toBeFalse('a fully recovered deployment still removes its failed release');

        // The history ends at the failed deployment's start: nothing claims
        // it finished, either way.
        $recorded = array_values(array_filter(
            array_map(
                fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
                array_filter(explode("\n", (string) file_get_contents($history.'.aside'))),
            ),
            fn (array $row): bool => $row['release'] === $failed,
        ));
        expect(array_column($recorded, 'event'))->toBe(['deployment-started']);
    } finally {
        deployOpsCleanup($scratch);
    }
});
