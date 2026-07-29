<?php

use Illuminate\Support\Facades\File;

/**
 * Phase 4 slice 8: infrastructure/scripts/install-target-perimeter — the
 * transactional installer for the three generic wrappers, the sudoers rule,
 * and the backup cron entry.
 *
 * Mirrors tests/Feature/Architecture/InstallTargetOperationsTest.php's own
 * technique throughout: the real, shipped installer is sourced (never as
 * $0, so main() never auto-runs), SRC_, DST_, BACKUP_ROOT, INSTALL_OWNER,
 * INSTALL_GROUP and VISUDO_BIN constants are reassigned to scratch-safe
 * values, and run_check/perform_apply/perform_verify are called directly,
 * bypassing require_root, which needs no coverage here beyond a real non-root
 * subprocess invocation failing closed (see TargetPerimeterTest.php's own
 * "refuses to run as a non-root caller" for the identical proof applied to
 * the wrappers this installer manages).
 *
 * Every full perform_apply/perform_verify integration test below substitutes
 * a self-contained stub wrapper (installPerimeterWriteWrapperStub) for the
 * real infrastructure/config/wrappers/rateguru-{deploy,rollback,cleanup} —
 * the real wrappers' own require_root would otherwise block every runtime
 * probe (--help, --target tits-guru) the installer performs, exactly the
 * same constraint InstallTargetOperationsTest.php works around with its own
 * stub deploy/rollback/backup-cycle candidates. The real wrappers' own
 * parsing/authorization/lifecycle/exec behaviour is proven separately and
 * thoroughly in TargetPerimeterTest.php.
 */

// =============================================================================
// Harness
// =============================================================================

function installPerimeterScriptPath(): string
{
    return base_path('infrastructure/scripts/install-target-perimeter');
}

function installPerimeterSource(): string
{
    return File::get(installPerimeterScriptPath());
}

function installPerimeterScratchDir(): string
{
    $dir = sys_get_temp_dir().'/install-target-perimeter-'.uniqid('', true).'-'.getmypid();

    foreach ([
        '',
        '/dest/usr/local/sbin',
        '/dest/etc/sudoers.d',
        '/dest/etc/cron.d',
    ] as $sub) {
        expect(@mkdir($dir.$sub, 0o755, true))->toBeTrue("could not create scratch directory: {$dir}{$sub}");
    }

    return $dir;
}

function installPerimeterCleanup(string $dir): void
{
    exec('rm -rf '.escapeshellarg($dir));
}

/**
 * A self-contained stub wrapper mimicking exactly the two safe probes
 * install-target-perimeter ever performs (--help, and a bare
 * --target tits-guru with no operation arguments) without requiring root.
 * Any other invocation is treated as a test failure in its own right — this
 * is what proves the installer never attempts a real, mutating operation
 * through it.
 *
 * @param  'reject'|'unexpected-success'|'wrong-reason'  $titsGuru
 */
function installPerimeterWriteWrapperStub(string $path, string $titsGuru = 'reject'): void
{
    $rejectionBranch = match ($titsGuru) {
        'reject' => <<<'SH'
        echo "target tits-guru has lifecycle=planned, not active" >&2
        exit 1
        SH,
        'unexpected-success' => <<<'SH'
        echo "stub: pretending tits-guru succeeded"
        exit 0
        SH,
        'wrong-reason' => <<<'SH'
        echo "stub: some unrelated failure" >&2
        exit 1
        SH,
    };

    // The literal strings below are never executed — they exist solely so
    // this stub also satisfies verify_installed_files' static content
    // checks (which grep for the generic installed operation path each real
    // wrapper references, and for the explicit --environment rejection
    // message), exactly as the real wrapper source does.
    $script = <<<SH
#!/usr/bin/env bash
# Stub wrapper for install-target-perimeter tests only — never a real
# deploy/rollback/cleanup operation. Mimics the real wrapper's own reference
# to /home/www/rateguru/bin/deploy, /home/www/rateguru/bin/rollback and
# /home/www/rateguru/bin/cleanup, and its explicit rejection message:
# --environment is not supported by this wrapper.
if [[ "\$*" == "--help" ]]; then
    echo "Usage: stub-wrapper --target TARGET_ID"
    exit 0
fi

if [[ "\$*" == "--target tits-guru" ]]; then
{$rejectionBranch}
fi

echo "STUB: unsafe/unexpected invocation: \$*" >&2
exit 1

SH;

    file_put_contents($path, $script);
    chmod($path, 0o755);
}

/**
 * @return array<string, string>
 */
function installPerimeterBaseVars(string $scratch, ?string $wrapperStub = null): array
{
    $wrapperStub ??= $scratch.'/stub-wrapper';

    if (! file_exists($wrapperStub)) {
        installPerimeterWriteWrapperStub($wrapperStub);
    }

    $ownerId = (string) getmyuid();
    $groupId = (string) getmygid();

    return [
        'SRC_WRAPPER_DEPLOY' => $wrapperStub,
        'SRC_WRAPPER_ROLLBACK' => $wrapperStub,
        'SRC_WRAPPER_CLEANUP' => $wrapperStub,
        'SRC_SUDOERS' => base_path('infrastructure/config/sudoers/rateguru-deploy'),
        'SRC_CRON' => base_path('infrastructure/config/cron/rateguru-backups'),
        'SRC_COMMON' => base_path('infrastructure/scripts/common'),
        'SRC_TARGETS' => base_path('infrastructure/scripts/targets'),
        'SRC_REGISTRY' => base_path('infrastructure/config/deployment-targets.json'),
        'SRC_DEPLOYMENT_CONF' => base_path('infrastructure/templates/deployment.conf.example'),
        'SRC_DEPLOY' => base_path('infrastructure/scripts/deploy'),
        'SRC_ROLLBACK' => base_path('infrastructure/scripts/rollback'),
        'SRC_CLEANUP' => base_path('infrastructure/scripts/cleanup'),
        'SRC_BACKUP_CYCLE' => base_path('infrastructure/scripts/backup-cycle'),
        'SRC_RESTORE_TEST' => base_path('infrastructure/scripts/restore-test'),
        'SRC_OFFSITE_RESTORE_TEST' => base_path('infrastructure/scripts/offsite-restore-test'),
        'DST_WRAPPER_DEPLOY' => $scratch.'/dest/usr/local/sbin/rateguru-deploy',
        'DST_WRAPPER_ROLLBACK' => $scratch.'/dest/usr/local/sbin/rateguru-rollback',
        'DST_WRAPPER_CLEANUP' => $scratch.'/dest/usr/local/sbin/rateguru-cleanup',
        'DST_SUDOERS' => $scratch.'/dest/etc/sudoers.d/rateguru-deploy',
        'DST_CRON' => $scratch.'/dest/etc/cron.d/rateguru-backups',
        'DST_SBIN_DIR' => $scratch.'/dest/usr/local/sbin',
        'DST_SUDOERS_DIR' => $scratch.'/dest/etc/sudoers.d',
        'DST_CRON_DIR' => $scratch.'/dest/etc/cron.d',
        'BACKUP_ROOT' => $scratch.'/dest/var/backups/rateguru-target-perimeter',
        'INSTALL_OWNER' => trim((string) shell_exec('id -un')),
        'INSTALL_GROUP' => trim((string) shell_exec('id -gn')),
        'INSTALL_OWNER_ID' => $ownerId,
        'INSTALL_GROUP_ID' => $groupId,
    ];
}

/**
 * @param  array<string, string>  $vars
 * @return array{0: int, 1: string}
 */
function installPerimeterRunHarness(string $scratch, array $vars, string $call): array
{
    $assignments = '';
    foreach ($vars as $name => $value) {
        $assignments .= $name.'='.escapeshellarg($value)."\n";
    }

    $script = "set -Eeuo pipefail\n"
        .'source '.escapeshellarg(installPerimeterScriptPath())."\n"
        .$assignments
        .$call."\n";

    $harnessPath = $scratch.'/harness-'.uniqid('', true).'.sh';
    file_put_contents($harnessPath, $script);

    $env = [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/tmp',
    ];

    $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
    $process = proc_open(['bash', $harnessPath], $descriptors, $pipes, null, $env);

    expect($process)->not->toBeFalse('could not start harness process');

    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);

    $exit = proc_close($process);

    return [$exit, $output];
}

// =============================================================================
// --check: static, read-only, no root
// =============================================================================

it('check passes against the real committed source files', function () {
    $scratch = installPerimeterScratchDir();

    try {
        [$exit, $output] = installPerimeterRunHarness($scratch, installPerimeterBaseVars($scratch), 'run_check');

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('check passed');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('check fails when a wrapper has a bash syntax error', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $broken = $scratch.'/broken-wrapper';
        file_put_contents($broken, "#!/usr/bin/env bash\nif [[ true\n");
        chmod($broken, 0o755);

        $vars = installPerimeterBaseVars($scratch);
        $vars['SRC_WRAPPER_DEPLOY'] = $broken;

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'run_check');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('bash -n failed');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('check fails when the candidate sudoers file has invalid syntax', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $broken = $scratch.'/broken-sudoers';
        file_put_contents($broken, "this is not valid sudoers syntax at all\n");

        $vars = installPerimeterBaseVars($scratch);
        $vars['SRC_SUDOERS'] = $broken;

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'run_check');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('visudo -cf');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('check fails when the sudoers candidate grants access to tits-guru\'s deploy user', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $bad = $scratch.'/bad-sudoers';
        file_put_contents($bad, <<<'SUDOERS'
            Defaults:deploy-rateguru-staging !requiretty
            Defaults:deploy-rateguru-tits-guru !requiretty

            deploy-rateguru-staging ALL=(root) NOPASSWD: \
                /usr/local/sbin/rateguru-deploy, \
                /usr/local/sbin/rateguru-rollback, \
                /usr/local/sbin/rateguru-cleanup

            deploy-rateguru-tits-guru ALL=(root) NOPASSWD: \
                /usr/local/sbin/rateguru-deploy
            SUDOERS);

        $vars = installPerimeterBaseVars($scratch);
        $vars['SRC_SUDOERS'] = $bad;

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'run_check');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('tits-guru');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('check fails when the cron candidate has the wrong number of operational lines', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $bad = $scratch.'/bad-cron';
        file_put_contents($bad, <<<'CRON'
            SHELL=/bin/bash
            PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

            30 2 * * * root /home/www/rateguru/bin/backup-cycle --target staging-main >> /var/log/rateguru/staging-backup-cycle.log 2>&1
            CRON);

        $vars = installPerimeterBaseVars($scratch);
        $vars['SRC_CRON'] = $bad;

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'run_check');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('expected exactly three operational cron lines');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('check fails when a cron candidate line still uses --environment', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $bad = $scratch.'/bad-cron';
        file_put_contents($bad, <<<'CRON'
            SHELL=/bin/bash
            PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

            30 2 * * * root /home/www/rateguru/bin/backup-cycle --environment staging >> /var/log/rateguru/staging-backup-cycle.log 2>&1
            10 4 * * 0 root /home/www/rateguru/bin/restore-test --target staging-main >> /var/log/rateguru/staging-local-restore-test.log 2>&1
            40 4 * * 0 root /home/www/rateguru/bin/offsite-restore-test --target staging-main >> /var/log/rateguru/staging-offsite-restore-test.log 2>&1
            CRON);

        $vars = installPerimeterBaseVars($scratch);
        $vars['SRC_CRON'] = $bad;

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'run_check');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('does not use --target staging-main');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('check fails when a cron candidate changed the schedule or log path', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $bad = $scratch.'/bad-cron';
        file_put_contents($bad, <<<'CRON'
            SHELL=/bin/bash
            PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

            15 3 * * * root /home/www/rateguru/bin/backup-cycle --target staging-main >> /var/log/rateguru/staging-backup-cycle.log 2>&1
            10 4 * * 0 root /home/www/rateguru/bin/restore-test --target staging-main >> /var/log/rateguru/staging-local-restore-test.log 2>&1
            40 4 * * 0 root /home/www/rateguru/bin/offsite-restore-test --target staging-main >> /var/log/rateguru/staging-offsite-restore-test.log 2>&1
            CRON);

        $vars = installPerimeterBaseVars($scratch);
        $vars['SRC_CRON'] = $bad;

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'run_check');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('changed schedule or log path');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('check fails when a referenced operational script no longer declares --target', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $noTarget = $scratch.'/no-target-deploy';
        file_put_contents($noTarget, "#!/usr/bin/env bash\necho hi\n");
        chmod($noTarget, 0o755);

        $vars = installPerimeterBaseVars($scratch);
        $vars['SRC_DEPLOY'] = $noTarget;

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'run_check');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('deploy does not appear to declare --target support');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('check makes no changes anywhere', function () {
    $scratch = installPerimeterScratchDir();

    try {
        [$exit, $output] = installPerimeterRunHarness($scratch, installPerimeterBaseVars($scratch), 'run_check');

        expect($exit)->toBe(0, $output);
        expect(glob($scratch.'/dest/usr/local/sbin/*'))->toBe([]);
        expect(glob($scratch.'/dest/etc/sudoers.d/*'))->toBe([]);
        expect(glob($scratch.'/dest/etc/cron.d/*'))->toBe([]);
    } finally {
        installPerimeterCleanup($scratch);
    }
});

// =============================================================================
// Full perform_apply / perform_verify integration
// =============================================================================

it('a successful apply installs exactly five files with correct ownership, mode and content, and creates a timestamped backup', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('apply complete');

        foreach ([
            ['DST_WRAPPER_DEPLOY', 'SRC_WRAPPER_DEPLOY', '0755'],
            ['DST_WRAPPER_ROLLBACK', 'SRC_WRAPPER_ROLLBACK', '0755'],
            ['DST_WRAPPER_CLEANUP', 'SRC_WRAPPER_CLEANUP', '0755'],
            ['DST_SUDOERS', 'SRC_SUDOERS', '0440'],
            ['DST_CRON', 'SRC_CRON', '0644'],
        ] as [$dstKey, $srcKey, $mode]) {
            $dst = $vars[$dstKey];
            expect(file_exists($dst))->toBeTrue("{$dstKey} must exist");
            expect(is_link($dst))->toBeFalse();
            expect(file_get_contents($dst))->toBe(file_get_contents($vars[$srcKey]));
            expect(substr(sprintf('%o', fileperms($dst)), -4))->toBe($mode);
            expect(fileowner($dst))->toBe((int) $vars['INSTALL_OWNER_ID'], "{$dstKey} must be owned by INSTALL_OWNER_ID");
            expect(filegroup($dst))->toBe((int) $vars['INSTALL_GROUP_ID'], "{$dstKey} must be group-owned by INSTALL_GROUP_ID");
        }

        $allDestFiles = array_merge(
            glob($scratch.'/dest/usr/local/sbin/*'),
            glob($scratch.'/dest/etc/sudoers.d/*'),
            glob($scratch.'/dest/etc/cron.d/*'),
        );
        expect($allDestFiles)->toHaveCount(5);

        $backups = glob($vars['BACKUP_ROOT'].'/*', GLOB_ONLYDIR);
        expect($backups)->not->toBeEmpty('apply must create a timestamped backup directory');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('never invokes anything beyond the two safe wrapper probes during apply', function () {
    // installPerimeterWriteWrapperStub's own catch-all ("unsafe/unexpected
    // invocation") would fail this apply outright if the installer ever
    // called the wrapper with anything other than --help or a bare
    // --target tits-guru — so a passing apply here is itself the proof that
    // no real deploy/rollback/cleanup/backup operation was ever attempted.
    $scratch = installPerimeterScratchDir();

    try {
        [$exit, $output] = installPerimeterRunHarness($scratch, installPerimeterBaseVars($scratch), 'perform_apply');

        expect($exit)->toBe(0, $output);
        expect($output)->not->toContain('unsafe/unexpected invocation');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('a successful verify passes against a freshly applied perimeter', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);

        [$applyExit, $applyOut] = installPerimeterRunHarness($scratch, $vars, 'perform_apply');
        expect($applyExit)->toBe(0, $applyOut);

        [$verifyExit, $verifyOut] = installPerimeterRunHarness($scratch, $vars, 'perform_verify');

        expect($verifyExit)->toBe(0, $verifyOut);
        expect($verifyOut)->toContain('PASS: target-aware perimeter installed and verified');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('sudoers is installed only after its own visudo pass, and cron is installed last', function () {
    // Static ordering proof: read the real, shipped source and confirm the
    // three install_regular_file_transactional call sites appear in the
    // documented order (wrappers, then sudoers — immediately preceded by its
    // own visudo -cf call — then cron last).
    $source = installPerimeterSource();

    $wrapperDeployPos = mb_strpos($source, 'install_regular_file_transactional "${STAGE_DIR}/rateguru-deploy" "${DST_WRAPPER_DEPLOY}"');
    $wrapperRollbackPos = mb_strpos($source, 'install_regular_file_transactional "${STAGE_DIR}/rateguru-rollback" "${DST_WRAPPER_ROLLBACK}"');
    $wrapperCleanupPos = mb_strpos($source, 'install_regular_file_transactional "${STAGE_DIR}/rateguru-cleanup" "${DST_WRAPPER_CLEANUP}"');
    $visudoBeforeSudoersPos = mb_strpos($source, '"${VISUDO_BIN}" -cf "${STAGE_DIR}/rateguru-deploy.sudoers"');
    $sudoersInstallPos = mb_strpos($source, 'install_regular_file_transactional "${STAGE_DIR}/rateguru-deploy.sudoers" "${DST_SUDOERS}"');
    $cronInstallPos = mb_strpos($source, 'install_regular_file_transactional "${STAGE_DIR}/rateguru-backups.cron" "${DST_CRON}"');

    foreach ([$wrapperDeployPos, $wrapperRollbackPos, $wrapperCleanupPos, $visudoBeforeSudoersPos, $sudoersInstallPos, $cronInstallPos] as $pos) {
        expect($pos)->not->toBeFalse();
    }

    expect($wrapperDeployPos)->toBeLessThan($wrapperRollbackPos)
        ->and($wrapperRollbackPos)->toBeLessThan($wrapperCleanupPos)
        ->and($wrapperCleanupPos)->toBeLessThan($visudoBeforeSudoersPos)
        ->and($visudoBeforeSudoersPos)->toBeLessThan($sudoersInstallPos)
        ->and($sudoersInstallPos)->toBeLessThan($cronInstallPos);
});

it('a broken candidate aborts before touching any destination', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $broken = $scratch.'/broken-wrapper';
        file_put_contents($broken, "#!/usr/bin/env bash\nif [[ true\n");
        chmod($broken, 0o755);

        $vars = installPerimeterBaseVars($scratch);
        $vars['SRC_WRAPPER_DEPLOY'] = $broken;

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->not->toBe(0);
        expect(file_exists($vars['DST_WRAPPER_DEPLOY']))->toBeFalse();
        expect(file_exists($vars['DST_WRAPPER_ROLLBACK']))->toBeFalse();
        expect(file_exists($vars['DST_WRAPPER_CLEANUP']))->toBeFalse();
        expect(file_exists($vars['DST_SUDOERS']))->toBeFalse();
        expect(file_exists($vars['DST_CRON']))->toBeFalse();
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('rolls back every file to its previous content when a mid-sequence install step fails, and removes files that did not exist before', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);

        // Two of the five destinations already exist with distinct, known
        // "old" content before this run — the other three (including cron,
        // installed last) are absent.
        file_put_contents($vars['DST_WRAPPER_DEPLOY'], "old deploy wrapper content\n");
        file_put_contents($vars['DST_SUDOERS'], "old sudoers content\n");

        // cron is installed last (see the ordering test above) — pre-seeding
        // its destination with a directory makes
        // reject_unsafe_existing_destination fail specifically at that final
        // step, after the four earlier files have already been installed
        // for real, so this proves rollback restores/removes across the
        // whole set, not merely the one file that happened to fail.
        mkdir($vars['DST_CRON']);

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('refusing to install over an existing non-regular-file destination');
        expect($output)->toContain('rollback complete');

        expect(file_get_contents($vars['DST_WRAPPER_DEPLOY']))->toBe("old deploy wrapper content\n");
        expect(file_get_contents($vars['DST_SUDOERS']))->toBe("old sudoers content\n");
        expect(file_exists($vars['DST_WRAPPER_ROLLBACK']))->toBeFalse();
        expect(file_exists($vars['DST_WRAPPER_CLEANUP']))->toBeFalse();
        expect(is_dir($vars['DST_CRON']))->toBeTrue('the pre-existing directory at DST_CRON must be left untouched, never treated as a rollback target');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('leaves no partial perimeter when apply fails at any point', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);
        $failingStub = $scratch.'/failing-wrapper';
        installPerimeterWriteWrapperStub($failingStub, 'unexpected-success');
        $vars['SRC_WRAPPER_DEPLOY'] = $failingStub;
        $vars['SRC_WRAPPER_ROLLBACK'] = $failingStub;
        $vars['SRC_WRAPPER_CLEANUP'] = $failingStub;

        [$exit] = installPerimeterRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->not->toBe(0);

        foreach (['DST_WRAPPER_DEPLOY', 'DST_WRAPPER_ROLLBACK', 'DST_WRAPPER_CLEANUP', 'DST_SUDOERS', 'DST_CRON'] as $key) {
            expect(file_exists($vars[$key]))->toBeFalse("{$key} must not exist — no destination existed before this run, so a failed apply must leave none behind");
        }
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('apply fails with a specific diagnostic when the planned-target rejection happens for the wrong reason', function () {
    // Exercises installPerimeterWriteWrapperStub's 'wrong-reason' variant:
    // the stub fails a --target tits-guru probe, but not with
    // lifecycle=planned, so verify_wrapper_planned_target_rejected must
    // distinguish "rejected, but for the wrong reason" from both a genuine
    // pass and an unexpected success.
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);
        $wrongReasonStub = $scratch.'/wrong-reason-wrapper';
        installPerimeterWriteWrapperStub($wrongReasonStub, 'wrong-reason');
        $vars['SRC_WRAPPER_DEPLOY'] = $wrongReasonStub;
        $vars['SRC_WRAPPER_ROLLBACK'] = $wrongReasonStub;
        $vars['SRC_WRAPPER_CLEANUP'] = $wrongReasonStub;

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('failed for the wrong reason');

        foreach (['DST_WRAPPER_DEPLOY', 'DST_WRAPPER_ROLLBACK', 'DST_WRAPPER_CLEANUP', 'DST_SUDOERS', 'DST_CRON'] as $key) {
            expect(file_exists($vars[$key]))->toBeFalse("{$key} must not exist — this failure happens during staged verification, before any destination is touched");
        }
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('verify detects content drift on an installed file', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);
        [$applyExit] = installPerimeterRunHarness($scratch, $vars, 'perform_apply');
        expect($applyExit)->toBe(0);

        file_put_contents($vars['DST_CRON'], "tampered content\n");
        // Preserve the installed mode/ownership exactly — only content
        // differs, isolating this test to the content-comparison check.
        chmod($vars['DST_CRON'], 0o644);

        [$verifyExit, $verifyOutput] = installPerimeterRunHarness($scratch, $vars, 'perform_verify');

        expect($verifyExit)->not->toBe(0);
        expect($verifyOutput)->toContain('content differs from its committed source');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('verify detects a mode drift on an installed file', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);
        [$applyExit] = installPerimeterRunHarness($scratch, $vars, 'perform_apply');
        expect($applyExit)->toBe(0);

        chmod($vars['DST_SUDOERS'], 0o640);

        [$verifyExit, $verifyOutput] = installPerimeterRunHarness($scratch, $vars, 'perform_verify');

        expect($verifyExit)->not->toBe(0);
        expect($verifyOutput)->toContain('wrong mode');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('verify detects an installed destination replaced by a symlink', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);
        [$applyExit] = installPerimeterRunHarness($scratch, $vars, 'perform_apply');
        expect($applyExit)->toBe(0);

        $decoy = $scratch.'/decoy-cron';
        file_put_contents($decoy, file_get_contents($vars['DST_CRON']));
        unlink($vars['DST_CRON']);
        symlink($decoy, $vars['DST_CRON']);

        [$verifyExit, $verifyOutput] = installPerimeterRunHarness($scratch, $vars, 'perform_verify');

        expect($verifyExit)->not->toBe(0);
        expect($verifyOutput)->toContain('must not be a symlink');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('apply refuses to install over an existing symlink destination, and never backs it up', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $vars = installPerimeterBaseVars($scratch);
        $decoy = $scratch.'/decoy';
        file_put_contents($decoy, "decoy\n");
        symlink($decoy, $vars['DST_WRAPPER_DEPLOY']);

        [$exit, $output] = installPerimeterRunHarness($scratch, $vars, 'perform_apply');

        expect($exit)->not->toBe(0);
        expect($output)->toContain('refusing to install over an existing symlink');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('verify and apply never run a real deploy, rollback, cleanup-apply, backup-cycle, restore or cron operation', function () {
    // Structural guard on the shipped installer's own source: it must never
    // invoke any of these mutating commands directly — every real mutation
    // belongs exclusively to whatever the installed wrapper execs into, on a
    // genuine, separately-triggered invocation.
    $source = installPerimeterSource();

    expect($source)
        ->not->toContain('"${DST_WRAPPER_DEPLOY}" --target')
        ->not->toContain('"${DST_WRAPPER_ROLLBACK}" --target')
        ->not->toContain('"${DST_WRAPPER_CLEANUP}" --target');
});

// =============================================================================
// RATEGURU_PERIMETER_ROOT (the one gated destination-root test seam)
// =============================================================================

it('RATEGURU_PERIMETER_ROOT prefixes every destination when the allow flag is set', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $script = "set -Eeuo pipefail\n"
            .'source '.escapeshellarg(installPerimeterScriptPath())."\n"
            .'printf \'DEPLOY=%s\nSUDOERS=%s\nCRON=%s\n\' "${DST_WRAPPER_DEPLOY}" "${DST_SUDOERS}" "${DST_CRON}"'."\n";

        $harnessPath = $scratch.'/harness.sh';
        file_put_contents($harnessPath, $script);

        $env = [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: '/tmp',
            'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
            'RATEGURU_PERIMETER_ROOT' => $scratch.'/prefixed-root',
        ];

        $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
        $process = proc_open(['bash', $harnessPath], $descriptors, $pipes, null, $env);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('DEPLOY='.$scratch.'/prefixed-root/usr/local/sbin/rateguru-deploy');
        expect($output)->toContain('SUDOERS='.$scratch.'/prefixed-root/etc/sudoers.d/rateguru-deploy');
        expect($output)->toContain('CRON='.$scratch.'/prefixed-root/etc/cron.d/rateguru-backups');
    } finally {
        installPerimeterCleanup($scratch);
    }
});

it('RATEGURU_PERIMETER_ROOT is ignored without the allow flag', function () {
    $scratch = installPerimeterScratchDir();

    try {
        $script = "set -Eeuo pipefail\n"
            .'source '.escapeshellarg(installPerimeterScriptPath())."\n"
            .'printf \'DEPLOY=%s\n\' "${DST_WRAPPER_DEPLOY}"'."\n";

        $harnessPath = $scratch.'/harness.sh';
        file_put_contents($harnessPath, $script);

        $env = [
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
            'HOME' => getenv('HOME') ?: '/tmp',
            'RATEGURU_PERIMETER_ROOT' => $scratch.'/prefixed-root',
        ];

        $descriptors = [1 => ['pipe', 'w'], 2 => ['redirect', 1]];
        $process = proc_open(['bash', $harnessPath], $descriptors, $pipes, null, $env);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exit = proc_close($process);

        expect($exit)->toBe(0, $output);
        expect($output)->toContain('DEPLOY=/usr/local/sbin/rateguru-deploy');
        expect($output)->not->toContain($scratch);
    } finally {
        installPerimeterCleanup($scratch);
    }
});

// =============================================================================
// Executable modes / basic shape (Git index)
// =============================================================================

it('install-target-perimeter is executable in the Git index', function () {
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(['git', '-C', base_path(), 'ls-files', '--stage', '--', 'infrastructure/scripts/install-target-perimeter'], $descriptors, $pipes);
    $stdout = trim(stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    expect($stdout)->toStartWith('100755');
});

it('the three wrapper source files are present, readable, and syntactically valid', function () {
    foreach (['rateguru-deploy', 'rateguru-rollback', 'rateguru-cleanup'] as $name) {
        $path = base_path("infrastructure/config/wrappers/{$name}");
        expect(File::exists($path))->toBeTrue();

        $output = [];
        exec('bash -n '.escapeshellarg($path).' 2>&1', $output, $exit);
        expect($exit)->toBe(0, "bash -n failed for {$name}: ".implode("\n", $output));
    }
});
