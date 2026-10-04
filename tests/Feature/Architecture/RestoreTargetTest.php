<?php

use Illuminate\Support\Facades\File;

/**
 * Restore Target Data: `restore-target` — the whole live data restore, end to end.
 *
 * Every test here runs the REAL orchestrator against the REAL fetch-backup,
 * verify-backup, restore-database and restore-storage it drives — only the
 * host boundary is stubbed (a file-backed fake PostgreSQL, this target's own
 * Supervisor program, Laravel's maintenance mode, the existing backup /
 * restore-test / health-check implementations it reuses). That is what makes
 * "the emergency backup happened before the first live mutation", "the swap
 * was compensated" and "the runtime came back exactly as it was" statements
 * about behaviour rather than about log lines.
 *
 * This file holds the contract of the restore as a whole: what it selects,
 * the aligned happy path, the order of its steps, compensation, locking and
 * the invariants it never breaks. The runtime quiesce, the code-alignment hold
 * and the read-only --inspect mode are tested in RestoreTargetRuntimeQuiesceTest,
 * RestoreTargetCodeAlignmentHoldTest and RestoreTargetInspectTest; the harness
 * they all share (fixture, run, apply, observers) lives in tests/Pest.php.
 */

// =============================================================================
// Selection contract
// =============================================================================

it('requires an exact backup and a source, and offers no latest', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $noBackup = restoreTargetRun($scratch, ['--apply', '--target', 'parity-target', '--source', 'local']);
        expect($noBackup['exit'])->not->toBe(0);
        expect($noBackup['output'])->toContain("there is no 'latest'");

        $noSource = restoreTargetRun($scratch, ['--apply', '--target', 'parity-target', '--backup', '20260115-120000']);
        expect($noSource['exit'])->not->toBe(0);
        expect($noSource['output'])->toContain('--source is required');

        $noMode = restoreTargetRun($scratch, ['--target', 'parity-target', '--source', 'local', '--backup', '20260115-120000']);
        expect($noMode['exit'])->not->toBe(0);
        expect($noMode['output'])->toContain('exactly one of --apply, --inspect or --resume is required');

        $bothModes = restoreTargetRun($scratch, ['--apply', '--resume', '--target', 'parity-target']);
        expect($bothModes['exit'])->not->toBe(0);
        expect($bothModes['output'])->toContain('only one of --apply, --inspect or --resume may be given');
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// The aligned happy path
// =============================================================================

it('restores database and storage, resumes the target, and reports an aligned restore', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetApply($scratch);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('RESTORE DATA COMPLETE: YES')
            ->toContain('CODE ALIGNMENT: ALIGNED')
            ->toContain('TARGET RESUMED: YES')
            ->toContain('BACKUP SOURCE SHA: '.FIXTURE_SOURCE_SHA);

        // The data actually changed.
        $storage = restoreTargetStorage($scratch);
        expect(is_file($storage.'/app/restored-marker.txt'))->toBeTrue();
        expect(is_file($storage.'/app/live-marker.txt'))->toBeFalse();

        // The canonical database is the restored one; the pre-restore copy
        // was dropped at commit and no staging leftovers remain.
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(array_values(array_diff(scandir($storage), ['.', '..'])))
            ->toEqualCanonicalizing(['app', 'framework']);

        // The runtime is back exactly as it was.
        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
        expect(restoreTargetQueueState($scratch))->toBe('RUNNING');
        expect(restoreTargetSchedulerPresent($scratch))->toBeTrue();
        expect(File::get($scratch.'/health-check.log'))->toContain('--target parity-target');
    } finally {
        removeScratchDir($scratch);
    }
});

it('restores from a schema 3 backup exactly as from an older one, and never applies its recovery material', function () {
    $scratch = restoreScratchDir();

    try {
        // Recovery material written under a prerequisite table that has since
        // changed — a name the installer no longer knows, a name it now
        // requires missing. A live restore never applies it, so the database
        // and the storage inside are as restorable as ever, and no
        // prerequisite installer is consulted at all.
        restoreTargetFixture($scratch, ['backup' => [
            'schema' => 3,
            'recovery_material' => [
                'legacy-tls-bundle' => "material-legacy-tls-bundle-never-logged\n",
                'basic-auth' => "material-basic-auth-never-logged\n",
            ],
        ]]);

        $result = restoreTargetApply($scratch);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('backup schema: schema3')
            ->toContain('recovery material: OK (2 top-level regular files, structurally safe; never applied by a live restore)')
            ->toContain('RESTORE DATA COMPLETE: YES')
            ->toContain('TARGET RESUMED: YES');

        expect(is_file(restoreTargetStorage($scratch).'/app/restored-marker.txt'))->toBeTrue();

        // Nothing of the recovery material reached the target or the host:
        // the archive's own content ("material-<name>-never-logged") exists
        // only inside backups and staged workspaces. (The scratch host tree
        // the prerequisite installer judges against holds its own, differently
        // prefixed fixture files and is excluded by name.)
        exec('grep -rl "material-.*-never-logged" '.escapeshellarg($scratch).' --exclude-dir=backups --exclude-dir=run --exclude-dir=emergency-template --exclude-dir=prereq-host 2>&1', $leaks, $grepStatus);

        // grep: 0 = matches (a leak), 1 = none, anything else = the scan
        // itself failed and proved nothing.
        expect(in_array($grepStatus, [0, 1], true))->toBeTrue('the leak scan failed to run: '.implode("\n", $leaks));
        expect($leaks)->toBe([]);

        expect(File::get($scratch.'/target/shared/.env'))->not->toContain('from-backup-never-applied');
        expect(file_exists($scratch.'/etc'))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('never rewrites shared/.env, the current link, the previous link or any server configuration', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $envBefore = File::get($scratch.'/target/shared/.env');
        $currentBefore = readlink($scratch.'/target/current');

        expect(restoreTargetApply($scratch)['exit'])->toBe(0);

        expect(File::get($scratch.'/target/shared/.env'))->toBe($envBefore);
        expect(File::get($scratch.'/target/shared/.env'))->not->toContain('from-backup-never-applied');
        expect(readlink($scratch.'/target/current'))->toBe($currentBefore);
        expect(file_exists($scratch.'/target/previous'))->toBeFalse();

        // The release tree itself is untouched: no code was deployed.
        expect(File::get($scratch.'/target/releases/'.FIXTURE_RELEASE.'/release.json'))
            ->toContain(FIXTURE_SOURCE_SHA);
    } finally {
        removeScratchDir($scratch);
    }
});

it('writes a restore history record carrying operational identity and no secret', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        expect(restoreTargetApply($scratch)['exit'])->toBe(0);

        $history = restoreTargetHistory($scratch);
        expect($history)->toHaveCount(1);

        $record = $history[0];

        expect($record)->toMatchArray([
            'status' => 'completed',
            'target' => 'parity-target',
            'environment' => 'staging',
            'backup_namespace' => 'parity',
            'source' => 'local',
            'backup' => '20260115-120000',
            'backup_release' => FIXTURE_RELEASE,
            'backup_source_sha' => FIXTURE_SOURCE_SHA,
            'current_release_before' => FIXTURE_RELEASE,
            'current_source_sha_before' => FIXTURE_SOURCE_SHA,
            'emergency_backup' => '20260116-090000',
            'code_alignment' => 'ALIGNED',
            'runtime_resumed' => 'yes',
            'failed_step' => null,
        ]);

        expect($record)->toHaveKeys(['operation_id', 'started_at', 'completed_at', 'compensation_status']);

        $raw = File::get($scratch.'/restores/restore-history.jsonl');
        expect($raw)
            ->not->toContain('s3cr3t-not-logged')
            ->not->toContain('DB_PASSWORD')
            ->not->toContain('from-backup-never-applied');

        expect(substr(sprintf('%o', fileperms($scratch.'/restores/restore-history.jsonl')), -4))->toBe('0600');
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Ordering: everything heavy happens before downtime, and the emergency
// backup happens before the first live mutation
// =============================================================================

it('stages and verifies everything before quiescing, and takes the emergency backup before any live mutation', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetApply($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        $output = $result['output'];

        $positions = [];
        foreach ([
            'stage backup',
            'verify backup',
            'stage database',
            'stage storage',
            'quiesce target',
            'emergency pre-restore backup',
            'activate database',
            'activate storage',
            'verify restored data',
            'commit',
        ] as $step) {
            $position = mb_strpos($output, 'step: '.$step);
            expect($position)->not->toBeFalse("step never ran: {$step}");
            $positions[$step] = $position;
        }

        $ordered = array_keys($positions);
        for ($i = 1; $i < count($ordered); $i++) {
            expect($positions[$ordered[$i]])
                ->toBeGreaterThan($positions[$ordered[$i - 1]], "{$ordered[$i]} must run after {$ordered[$i - 1]}");
        }

        // The emergency backup is created AND verified before the first live
        // mutation, and the restore-test that verified it ran against the
        // emergency backup rather than against some older one.
        expect(File::get($scratch.'/restore-test.log'))->toContain('--target parity-target');
        expect($positions['emergency pre-restore backup'])->toBeLessThan($positions['activate database']);
    } finally {
        removeScratchDir($scratch);
    }
});

it('performs no live mutation when the emergency backup fails', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetApply($scratch, ['RGTEST_BACKUP_EXIT' => '1']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('no live data was mutated');

        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();

        // The runtime went back to exactly what it was.
        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
        expect(restoreTargetQueueState($scratch))->toBe('RUNNING');
        expect(restoreTargetSchedulerPresent($scratch))->toBeTrue();

        expect(restoreTargetHistory($scratch)[0])->toMatchArray([
            'status' => 'failed',
            'failed_step' => 'emergency pre-restore backup',
        ]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('performs no live mutation when the emergency backup fails its restore test', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetApply($scratch, ['RGTEST_RESTORE_TEST_EXIT' => '1']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('failed its restore test');

        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();
        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('performs no live mutation when the emergency backup cannot be unambiguously identified', function (string $ids, string $expected) {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetApply($scratch, ['RGTEST_EMERGENCY_BACKUP_IDS' => $ids]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain($expected);
        expect($result['output'])->toContain('no live data was mutated');

        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    'two new backups' => ['20260116-090000 20260116-091500', '(2 new backups appeared)'],
    'no new backup' => ['none', '(0 new backups appeared)'],
]);

it('keeps the selected backup usable even though the emergency backup applies retention', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        // The exact hazard: `backup` applies local retention right after
        // creating a backup, and the operator is restoring an OLD one.
        $backupStub = File::get($scratch.'/bin/backup-stub');
        file_put_contents(
            $scratch.'/bin/backup-stub',
            $backupStub."\nrm -rf \"\${RGTEST_BACKUP_NAMESPACE_ROOT}/20260115-120000\"\n",
        );

        $result = restoreTargetApply($scratch);

        expect($result['exit'])->toBe(0, $result['output']);
        expect(is_dir($scratch.'/backups/parity/20260115-120000'))->toBeFalse('retention removed the source backup');
        expect(is_file(restoreTargetStorage($scratch).'/app/restored-marker.txt'))->toBeTrue('the restore still used it');
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Compensation
// =============================================================================

it('compensates a failed storage activation, restoring both the database and the storage tree', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        // The staged tree disappears between the database activation and the
        // storage activation: the swap fails after the database was already
        // switched, which is exactly the state compensation exists for.
        $storageBin = patchedInfraScript($scratch, 'restore-storage');
        $sabotaged = $scratch.'/sabotaged-restore-storage';
        file_put_contents($sabotaged, str_replace(
            '    log "${LABEL} activating restored storage tree"',
            '    log "${LABEL} activating restored storage tree"'."\n".'    rm -rf "${STAGED_APP}"',
            File::get($storageBin),
        ));
        chmod($sabotaged, 0o755);

        $result = restoreTargetApply($scratch, ['RATEGURU_RESTORE_STORAGE_BIN' => $sabotaged]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('compensating');

        // Both halves are back, and the staged copies were discarded rather
        // than left on the host.
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(trim(File::get($scratch.'/pg/db/parity_db')))->toBe('parity_app t');
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();
        expect(is_file(restoreTargetStorage($scratch).'/app/restored-marker.txt'))->toBeFalse();

        // And the target is serving again.
        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
        expect(restoreTargetQueueState($scratch))->toBe('RUNNING');
        expect(restoreTargetSchedulerPresent($scratch))->toBeTrue();

        expect(restoreTargetHistory($scratch)[0])->toMatchArray([
            'status' => 'failed-recovered',
            'compensation_status' => 'complete',
        ]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('compensates a database activation that failed between its two renames', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        // The very first rename fails, so activation never reaches the phase
        // update. Compensation must still be allowed there — refusing it
        // would leave a fully recoverable target held.
        $result = restoreTargetApply($scratch, ['RGTEST_RENAME_FAIL_TO_PREFIX' => 'rateguru_pre_']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('could not rename parity_db aside');
        expect($result['output'])->toContain('nothing to undo');

        // The live database survived, and the connection barrier a failed
        // activation left behind was lifted.
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(trim(File::get($scratch.'/pg/db/parity_db')))->toBe('parity_app t');
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();

        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
        expect(restoreTargetQueueState($scratch))->toBe('RUNNING');
        expect(restoreTargetSchedulerPresent($scratch))->toBeTrue();

        expect(restoreTargetHistory($scratch)[0])->toMatchArray([
            'status' => 'failed-recovered',
            'compensation_status' => 'complete',
            'failed_step' => 'activate database',
        ]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('compensates a failed final verification and does not mask the original error', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        // A storage tree whose mode normalization is undone after the swap:
        // final verification refuses it, and both halves compensate.
        $storageBin = patchedInfraScript($scratch, 'restore-storage');
        $sabotaged = $scratch.'/sabotaged-verify-restore-storage';
        file_put_contents($sabotaged, str_replace(
            '    restore_state_set "${STATE_FILE}" phase storage-activated',
            '    chmod 0755 "${LIVE_APP}"'."\n".'    restore_state_set "${STATE_FILE}" phase storage-activated',
            File::get($storageBin),
        ));
        chmod($sabotaged, 0o755);

        $result = restoreTargetApply($scratch, ['RATEGURU_RESTORE_STORAGE_BIN' => $sabotaged]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('restored storage tree has mode');
        expect($result['output'])->toContain('expected 2710');
        expect($result['output'])->toContain('compensating');

        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();

        expect(restoreTargetHistory($scratch)[0])->toMatchArray([
            'status' => 'failed-recovered',
            'compensation_status' => 'complete',
            'failed_step' => 'verify restored data',
        ]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('holds the target and demands manual recovery when compensation itself cannot complete', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        // The swap completes, then the staging parent disappears and the
        // live tree's mode is broken: final verification refuses the result,
        // and the storage half can no longer be moved back out of the way —
        // compensation is genuinely impossible, not merely unnecessary.
        $storageBin = patchedInfraScript($scratch, 'restore-storage');
        $sabotaged = $scratch.'/broken-restore-storage';
        file_put_contents($sabotaged, str_replace(
            '    restore_state_set "${STATE_FILE}" phase storage-activated',
            '    rm -rf "${STAGE_PARENT}"'."\n".'    chmod 0755 "${LIVE_APP}"'."\n".'    restore_state_set "${STATE_FILE}" phase storage-activated',
            File::get($storageBin),
        ));
        chmod($sabotaged, 0o755);

        $result = restoreTargetApply($scratch, ['RATEGURU_RESTORE_STORAGE_BIN' => $sabotaged]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('MANUAL RECOVERY REQUIRED')
            ->toContain('The target is intentionally NOT serving traffic.');

        // Held: not resumed, still down, queue still stopped.
        expect(restoreTargetMaintenanceActive($scratch))->toBeTrue();
        expect(restoreTargetQueueState($scratch))->toBe('STOPPED');
        expect(restoreTargetSchedulerPresent($scratch))->toBeFalse();

        expect(restoreTargetHistory($scratch)[0])->toMatchArray([
            'status' => 'failed-held',
            'compensation_status' => 'incomplete',
        ]);
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Locking, lifecycle and preconditions
// =============================================================================

it('serializes against another restore for the same backup namespace', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        mkdir($scratch.'/run', 0o755, true);
        $lockFile = $scratch.'/run/restore-target-parity.lock';
        touch($lockFile);

        // flock -n against a lock another process already holds.
        $holder = proc_open(
            ['flock', '-x', $lockFile, 'sleep', '20'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        usleep(300000);

        try {
            $result = restoreTargetApply($scratch);

            expect($result['exit'])->not->toBe(0);
            expect($result['output'])->toContain('another restore operation is already running');
        } finally {
            proc_terminate($holder);
            proc_close($holder);
        }
    } finally {
        removeScratchDir($scratch);
    }
});

it('serializes against a deploy, rollback or cleanup through the existing target deployment lock', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $lockFile = $scratch.'/target/locks/deployment.lock';
        touch($lockFile);

        $holder = proc_open(
            ['flock', '-x', $lockFile, 'sleep', '20'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        usleep(300000);

        try {
            $result = restoreTargetApply($scratch);

            expect($result['exit'])->not->toBe(0);
            expect($result['output'])->toContain('another deployment operation is already running');

            // Nothing live was touched, and nothing was quiesced.
            expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();
            expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
        } finally {
            proc_terminate($holder);
            proc_close($holder);
        }
    } finally {
        removeScratchDir($scratch);
    }

    // It is the EXISTING lock, not a new incompatible one.
    expect(File::get(base_path('infrastructure/scripts/restore-target')))
        ->toContain('acquire_deployment_lock "${TARGET_ROOT}"');
});

it('rejects a planned target before creating a workspace, quiescing anything or downloading a backup', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetRun($scratch, [
            '--apply', '--target', 'planned-target', '--source', 'local', '--backup', '20260115-120000',
        ]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('lifecycle=planned');

        expect(is_dir($scratch.'/run/restores'))->toBeFalse();
        expect(File::get($scratch.'/supervisor.log'))->toBe('');
        expect(File::get($scratch.'/php.log'))->toBe('');
        expect(File::get($scratch.'/backup.log'))->toBe('');
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses to restore a target with no deployed release', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch);
        unlink($scratch.'/target/current');

        $result = restoreTargetApply($scratch);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('a live data restore requires a deployed target');
        expect(is_dir($scratch.'/run/restores'))->toBeFalse();
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses a backup that cannot identify the code its data belongs to', function () {
    $scratch = restoreScratchDir();

    try {
        restoreTargetFixture($scratch, [
            'backup' => ['release_json' => "{}\n", 'manifest' => backupManifestFixture(['release' => 'unknown'])],
        ]);

        $result = restoreTargetApply($scratch);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('carries no release');

        // Nothing was quiesced and nothing live was touched.
        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();
        expect(fakePostgresDatabases($scratch))->toBe(['parity_db']);
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// Hard invariants
// =============================================================================

it('never runs a migration, a schema reset or a release switch', function () {
    foreach (['restore-target', 'restore-database', 'restore-storage', 'fetch-backup', 'verify-backup', 'restore-common'] as $script) {
        $source = File::get(base_path('infrastructure/scripts/'.$script));

        foreach ([
            'artisan migrate',
            'migrate --force',
            'migrate:fresh',
            'migrate:refresh',
            'migrate:reset',
            'db:wipe',
            'schema:dump',
            'DROP SCHEMA',
        ] as $forbidden) {
            expect($source)->not->toContain($forbidden, "{$script} must never run {$forbidden}");
        }

        // No release switching: the current/previous links are read, never
        // rewritten.
        expect($source)->not->toMatch('/ln\s+-sfn?.*current/');
        expect($source)->not->toContain('mv -Tf');
    }
});

it('never applies environment.env or server-configuration.tar.gz', function () {
    foreach (['restore-target', 'restore-database', 'restore-storage', 'restore-common'] as $script) {
        $source = File::get(base_path('infrastructure/scripts/'.$script));

        foreach (preg_split('/\R/', $source) as $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            // The two files may be NAMED (they are part of the required file
            // set and are checksum-verified) but never extracted, copied,
            // installed or sourced.
            foreach (['environment.env', 'server-configuration.tar.gz'] as $neverApplied) {
                if (! str_contains($trimmed, $neverApplied)) {
                    continue;
                }

                expect($trimmed)->not->toMatch(
                    '/(^|[;&|]\s*)(cp|install|mv|ln|source|eval)\s/',
                    "{$script} must never copy, install, move, link or source {$neverApplied}: {$trimmed}",
                );

                expect($trimmed)->not->toMatch(
                    '/(^|[;&|]\s*)tar\s+[^;&|]*-x/',
                    "{$script} must never extract {$neverApplied}: {$trimmed}",
                );
            }
        }
    }
});

it('stops no global service and touches no unrelated project', function () {
    foreach (['restore-target', 'restore-database', 'restore-storage', 'fetch-backup', 'verify-backup', 'restore-common'] as $script) {
        $source = File::get(base_path('infrastructure/scripts/'.$script));

        foreach ([
            'systemctl stop',
            'systemctl restart',
            'service nginx',
            'supervisorctl stop all',
            'supervisorctl shutdown',
            'pg_ctl',
            'CatalogHub',
            'cataloghub',
            'Polymarket',
            'polymarket',
        ] as $forbidden) {
            expect($source)->not->toContain($forbidden, "{$script} must never contain {$forbidden}");
        }

        expect($source)->not->toMatch('#rm\s+-rf\s+/(etc|home|var|opt|usr)(/\S*)?\s*$#m');
        expect($source)->not->toMatch('#psql.*FROM\s+pg_database\s*;#');
    }
});

it('requires root for a real invocation', function () {
    $source = File::get(restoreTargetScript());

    expect($source)->toContain("main() {\n    require_root\n    parse_restore_target_args");
});
