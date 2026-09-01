<?php

use Illuminate\Support\Facades\File;

/**
 * Phase 7.3: `restore-target` — the whole live data restore, end to end.
 *
 * Every test here runs the REAL orchestrator against the REAL fetch-backup,
 * verify-backup, restore-database and restore-storage it drives — only the
 * host boundary is stubbed (a file-backed fake PostgreSQL, this target's own
 * Supervisor program, Laravel's maintenance mode, the existing backup /
 * restore-test / health-check implementations it reuses). That is what makes
 * "the emergency backup happened before the first live mutation", "the swap
 * was compensated" and "the runtime came back exactly as it was" statements
 * about behaviour rather than about log lines.
 */
function restoreTargetScript(): string
{
    return base_path('infrastructure/scripts/restore-target');
}

/**
 * The full fixture: target tree, fake catalog, cron entry, local backup to
 * restore from, and the emergency-backup template the `backup` stub copies.
 *
 * @return array<string, string>
 */
function restoreTargetFixture(string $scratch, array $options = []): array
{
    p73TargetTree($scratch, [
        'source_sha' => $options['current_source_sha'] ?? P73_SOURCE_SHA,
        'release' => $options['current_release'] ?? P73_RELEASE,
    ]);
    p73FakePostgres($scratch, $options['postgres'] ?? []);
    p73RuntimeStubs($scratch);

    // The backup being restored from, and a byte-identical template the
    // emergency `backup` stub copies into place as the new latest backup.
    p73BuildBackup($scratch.'/backups/parity', '20260115-120000', $options['backup'] ?? []);
    p73BuildBackup($scratch.'/emergency-src', '20260116-090000');
    exec('mv '.escapeshellarg($scratch.'/emergency-src/20260116-090000').' '.escapeshellarg($scratch.'/emergency-template'));

    if (($options['scheduler'] ?? true) === true) {
        mkdir($scratch.'/cron.d', 0o755, true);
        file_put_contents(
            $scratch.'/cron.d/parity-scheduler',
            "* * * * * runtime cd /target/current && php artisan schedule:run\n",
        );
    }

    [$registryPath, $targetsPath] = p73Registry($scratch);

    return ['registry' => $registryPath, 'targets' => $targetsPath];
}

/**
 * @return array{exit: int, output: string}
 */
function restoreTargetRun(string $scratch, array $arguments, array $envOverrides = []): array
{
    [$registryPath, $targetsPath] = p73Registry($scratch);

    $env = p73BaseEnv($scratch, $registryPath, $targetsPath, array_merge(
        p73PostgresEnv($scratch),
        p73RuntimeEnv($scratch),
        [
            'RATEGURU_RESTORE_FETCH_BACKUP_BIN' => p73PatchedScript($scratch, 'fetch-backup'),
            'RATEGURU_RESTORE_VERIFY_BACKUP_BIN' => p73PatchedScript($scratch, 'verify-backup'),
            'RATEGURU_RESTORE_DATABASE_BIN' => p73PatchedScript($scratch, 'restore-database'),
            'RATEGURU_RESTORE_STORAGE_BIN' => p73PatchedScript($scratch, 'restore-storage'),
        ],
        $envOverrides,
    ));

    [$exit, $output] = p73Run(p73PatchedScript($scratch, 'restore-target'), $arguments, $env);

    return ['exit' => $exit, 'output' => $output];
}

function restoreTargetApply(string $scratch, array $envOverrides = []): array
{
    return restoreTargetRun($scratch, [
        '--apply', '--target', 'parity-target', '--source', 'local', '--backup', '20260115-120000',
    ], $envOverrides);
}

/** @return list<array<string, mixed>> */
function restoreTargetHistory(string $scratch): array
{
    $path = $scratch.'/restores/restore-history.jsonl';

    if (! is_file($path)) {
        return [];
    }

    return array_map(
        static fn (string $line): array => json_decode($line, true),
        array_values(array_filter(preg_split('/\R/', trim(File::get($path))))),
    );
}

function restoreTargetStorage(string $scratch): string
{
    return $scratch.'/target/shared/storage';
}

function restoreTargetMaintenanceActive(string $scratch): bool
{
    return is_file(restoreTargetStorage($scratch).'/framework/down');
}

function restoreTargetQueueState(string $scratch): string
{
    return trim(File::get($scratch.'/supervisor-state'));
}

function restoreTargetSchedulerPresent(string $scratch): bool
{
    return is_file($scratch.'/cron.d/parity-scheduler');
}

// =============================================================================
// Selection contract
// =============================================================================

it('requires an exact backup and a source, and offers no latest', function () {
    $scratch = p73Scratch();

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
        expect($noMode['output'])->toContain('exactly one of --apply or --resume is required');

        $bothModes = restoreTargetRun($scratch, ['--apply', '--resume', '--target', 'parity-target']);
        expect($bothModes['exit'])->not->toBe(0);
        expect($bothModes['output'])->toContain('only one of --apply or --resume');
    } finally {
        p73Cleanup($scratch);
    }
});

// =============================================================================
// The aligned happy path
// =============================================================================

it('restores database and storage, resumes the target, and reports an aligned restore', function () {
    $scratch = p73Scratch();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetApply($scratch);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('RESTORE DATA COMPLETE: YES')
            ->toContain('CODE ALIGNMENT: ALIGNED')
            ->toContain('TARGET RESUMED: YES')
            ->toContain('BACKUP SOURCE SHA: '.P73_SOURCE_SHA);

        // The data actually changed.
        $storage = restoreTargetStorage($scratch);
        expect(is_file($storage.'/app/restored-marker.txt'))->toBeTrue();
        expect(is_file($storage.'/app/live-marker.txt'))->toBeFalse();

        // The canonical database is the restored one; the pre-restore copy
        // was dropped at commit and no staging leftovers remain.
        expect(p73Databases($scratch))->toBe(['parity_db']);
        expect(array_values(array_diff(scandir($storage), ['.', '..'])))
            ->toEqualCanonicalizing(['app', 'framework']);

        // The runtime is back exactly as it was.
        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
        expect(restoreTargetQueueState($scratch))->toBe('RUNNING');
        expect(restoreTargetSchedulerPresent($scratch))->toBeTrue();
        expect(File::get($scratch.'/health-check.log'))->toContain('--target parity-target');
    } finally {
        p73Cleanup($scratch);
    }
});

it('never rewrites shared/.env, the current link, the previous link or any server configuration', function () {
    $scratch = p73Scratch();

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
        expect(File::get($scratch.'/target/releases/'.P73_RELEASE.'/release.json'))
            ->toContain(P73_SOURCE_SHA);
    } finally {
        p73Cleanup($scratch);
    }
});

it('writes a restore history record carrying operational identity and no secret', function () {
    $scratch = p73Scratch();

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
            'backup_release' => P73_RELEASE,
            'backup_source_sha' => P73_SOURCE_SHA,
            'current_release_before' => P73_RELEASE,
            'current_source_sha_before' => P73_SOURCE_SHA,
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
        p73Cleanup($scratch);
    }
});

// =============================================================================
// Ordering: everything heavy happens before downtime, and the emergency
// backup happens before the first live mutation
// =============================================================================

it('stages and verifies everything before quiescing, and takes the emergency backup before any live mutation', function () {
    $scratch = p73Scratch();

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
        p73Cleanup($scratch);
    }
});

it('performs no live mutation when the emergency backup fails', function () {
    $scratch = p73Scratch();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetApply($scratch, ['P73_BACKUP_EXIT' => '1']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('no live data was mutated');

        expect(p73Databases($scratch))->toBe(['parity_db']);
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
        p73Cleanup($scratch);
    }
});

it('performs no live mutation when the emergency backup fails its restore test', function () {
    $scratch = p73Scratch();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetApply($scratch, ['P73_RESTORE_TEST_EXIT' => '1']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('failed its restore test');

        expect(p73Databases($scratch))->toBe(['parity_db']);
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();
        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
    } finally {
        p73Cleanup($scratch);
    }
});

it('performs no live mutation when the emergency backup cannot be unambiguously identified', function (string $ids, string $expected) {
    $scratch = p73Scratch();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetApply($scratch, ['P73_EMERGENCY_BACKUP_IDS' => $ids]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain($expected);
        expect($result['output'])->toContain('no live data was mutated');

        expect(p73Databases($scratch))->toBe(['parity_db']);
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();
    } finally {
        p73Cleanup($scratch);
    }
})->with([
    'two new backups' => ['20260116-090000 20260116-091500', '(2 new backups appeared)'],
    'no new backup' => ['none', '(0 new backups appeared)'],
]);

it('keeps the selected backup usable even though the emergency backup applies retention', function () {
    $scratch = p73Scratch();

    try {
        restoreTargetFixture($scratch);

        // The exact hazard: `backup` applies local retention right after
        // creating a backup, and the operator is restoring an OLD one.
        $backupStub = File::get($scratch.'/bin/backup-stub');
        file_put_contents(
            $scratch.'/bin/backup-stub',
            $backupStub."\nrm -rf \"\${P73_BACKUP_NAMESPACE_ROOT}/20260115-120000\"\n",
        );

        $result = restoreTargetApply($scratch);

        expect($result['exit'])->toBe(0, $result['output']);
        expect(is_dir($scratch.'/backups/parity/20260115-120000'))->toBeFalse('retention removed the source backup');
        expect(is_file(restoreTargetStorage($scratch).'/app/restored-marker.txt'))->toBeTrue('the restore still used it');
    } finally {
        p73Cleanup($scratch);
    }
});

// =============================================================================
// Runtime quiesce: preserve the ORIGINAL state, never assume it
// =============================================================================

it('brings the target down for the restore and back up afterwards when it was up before', function () {
    $scratch = p73Scratch();

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
        p73Cleanup($scratch);
    }
});

it('leaves a target that was already in maintenance in maintenance', function () {
    $scratch = p73Scratch();

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
        p73Cleanup($scratch);
    }
});

it('stops a running queue program and starts it again, touching only this target group', function () {
    $scratch = p73Scratch();

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
        p73Cleanup($scratch);
    }
});

it('leaves a queue program that was already stopped stopped', function () {
    $scratch = p73Scratch();

    try {
        restoreTargetFixture($scratch);
        file_put_contents($scratch.'/supervisor-state', "STOPPED\n");

        $result = restoreTargetApply($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        expect($result['output'])->toContain('was already fully stopped before this restore');
        expect(File::get($scratch.'/supervisor.log'))->not->toContain('start parity-queue');
        expect(restoreTargetQueueState($scratch))->toBe('STOPPED');
    } finally {
        p73Cleanup($scratch);
    }
});

it('stops a queue group that is only partly running, and leaves it stopped afterwards', function () {
    $scratch = p73Scratch();

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
        p73Cleanup($scratch);
    }
});

it('refuses to restore when the target queue program cannot be observed at all', function () {
    $scratch = p73Scratch();

    try {
        restoreTargetFixture($scratch);

        // supervisorctl cannot answer: supervisord down, or the program not
        // registered. "Cannot see it" is never "it is not running".
        file_put_contents($scratch.'/supervisor-state', "UNKNOWN\n");

        $result = restoreTargetApply($scratch);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('cannot observe the target queue program parity-queue');

        expect(p73Databases($scratch))->toBe(['parity_db']);
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();
        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
    } finally {
        p73Cleanup($scratch);
    }
});

it('holds this target cron entry outside cron.d for the restore and restores it exactly', function () {
    $scratch = p73Scratch();

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
        p73Cleanup($scratch);
    }
});

it('never invents a cron entry that did not exist before', function () {
    $scratch = p73Scratch();

    try {
        restoreTargetFixture($scratch, ['scheduler' => false]);
        mkdir($scratch.'/cron.d', 0o755, true);

        $result = restoreTargetApply($scratch);
        expect($result['exit'])->toBe(0, $result['output']);

        expect($result['output'])->toContain('leaving the scheduler exactly as it was');
        expect(restoreTargetSchedulerPresent($scratch))->toBeFalse();
    } finally {
        p73Cleanup($scratch);
    }
});

it('refuses to swap data underneath a scheduler process that will not stop', function () {
    $scratch = p73Scratch();

    try {
        restoreTargetFixture($scratch);

        $result = restoreTargetApply($scratch, ['P73_PGREP_EXIT' => '0']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('refusing to swap data underneath a writer');

        expect(p73Databases($scratch))->toBe(['parity_db']);
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();

        // The runtime was put back, including the held cron entry.
        expect(restoreTargetSchedulerPresent($scratch))->toBeTrue();
        expect(restoreTargetQueueState($scratch))->toBe('RUNNING');
        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
    } finally {
        p73Cleanup($scratch);
    }
});

// =============================================================================
// Compensation
// =============================================================================

it('compensates a failed storage activation, restoring both the database and the storage tree', function () {
    $scratch = p73Scratch();

    try {
        restoreTargetFixture($scratch);

        // The staged tree disappears between the database activation and the
        // storage activation: the swap fails after the database was already
        // switched, which is exactly the state compensation exists for.
        $storageBin = p73PatchedScript($scratch, 'restore-storage');
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
        expect(p73Databases($scratch))->toBe(['parity_db']);
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
        p73Cleanup($scratch);
    }
});

it('compensates a database activation that failed between its two renames', function () {
    $scratch = p73Scratch();

    try {
        restoreTargetFixture($scratch);

        // The very first rename fails, so activation never reaches the phase
        // update. Compensation must still be allowed there — refusing it
        // would leave a fully recoverable target held.
        $result = restoreTargetApply($scratch, ['P73_RENAME_FAIL_TO_PREFIX' => 'rateguru_pre_']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('could not rename parity_db aside');
        expect($result['output'])->toContain('nothing to undo');

        // The live database survived, and the connection barrier a failed
        // activation left behind was lifted.
        expect(p73Databases($scratch))->toBe(['parity_db']);
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
        p73Cleanup($scratch);
    }
});

it('compensates a failed final verification and does not mask the original error', function () {
    $scratch = p73Scratch();

    try {
        restoreTargetFixture($scratch);

        // A storage tree whose mode normalization is undone after the swap:
        // final verification refuses it, and both halves compensate.
        $storageBin = p73PatchedScript($scratch, 'restore-storage');
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

        expect(p73Databases($scratch))->toBe(['parity_db']);
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();

        expect(restoreTargetHistory($scratch)[0])->toMatchArray([
            'status' => 'failed-recovered',
            'compensation_status' => 'complete',
            'failed_step' => 'verify restored data',
        ]);
    } finally {
        p73Cleanup($scratch);
    }
});

it('holds the target and demands manual recovery when compensation itself cannot complete', function () {
    $scratch = p73Scratch();

    try {
        restoreTargetFixture($scratch);

        // The swap completes, then the staging parent disappears and the
        // live tree's mode is broken: final verification refuses the result,
        // and the storage half can no longer be moved back out of the way —
        // compensation is genuinely impossible, not merely unnecessary.
        $storageBin = p73PatchedScript($scratch, 'restore-storage');
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
        p73Cleanup($scratch);
    }
});

// =============================================================================
// Code alignment
// =============================================================================

it('completes the data restore but holds the runtime when the code does not match the backup', function () {
    $scratch = p73Scratch();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => P73_OTHER_RELEASE,
            'current_source_sha' => P73_OTHER_SOURCE_SHA,
        ]);

        $result = restoreTargetApply($scratch);

        // The requested DATA restore succeeded, so this is a success.
        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('RESTORE DATA COMPLETE: YES')
            ->toContain('CODE ALIGNMENT: REQUIRED')
            ->toContain('TARGET RESUMED: NO')
            ->toContain('BACKUP SOURCE SHA: '.P73_SOURCE_SHA)
            ->toContain('CURRENT SOURCE SHA: '.P73_OTHER_SOURCE_SHA)
            ->toContain('restore-target --resume --target parity-target --operation');

        // The data IS restored and committed.
        expect(is_file(restoreTargetStorage($scratch).'/app/restored-marker.txt'))->toBeTrue();
        expect(p73Databases($scratch))->toBe(['parity_db']);

        // The runtime is intentionally held.
        expect(restoreTargetMaintenanceActive($scratch))->toBeTrue();
        expect(restoreTargetQueueState($scratch))->toBe('STOPPED');
        expect(restoreTargetSchedulerPresent($scratch))->toBeFalse();

        // No release was switched and no migration was run.
        expect(readlink($scratch.'/target/current'))->toBe($scratch.'/target/releases/'.P73_OTHER_RELEASE);
        expect(File::get($scratch.'/php.log'))->not->toContain('migrate');
        expect(File::get($scratch.'/health-check.log'))->toBe('', 'a held target is not health checked as if it were serving');

        expect(restoreTargetHistory($scratch)[0])->toMatchArray([
            'status' => 'held',
            'code_alignment' => 'REQUIRED',
            'runtime_resumed' => 'no',
        ]);
    } finally {
        p73Cleanup($scratch);
    }
});

// =============================================================================
// Resume
// =============================================================================

/** Runs an apply that ends held, and returns its operation ID. */
function restoreTargetHeldOperation(string $scratch): string
{
    $result = restoreTargetApply($scratch);
    expect($result['exit'])->toBe(0, $result['output']);
    expect($result['output'])->toContain('CODE ALIGNMENT: REQUIRED');

    $operations = p73OperationIds($result['output']);
    expect($operations)->toHaveCount(1);

    return $operations[0];
}

/** Deploys the aligned release, the way a Phase 7.4 alignment deploy would. */
function restoreTargetAlignCode(string $scratch): void
{
    $aligned = $scratch.'/target/releases/'.P73_RELEASE;
    mkdir($aligned, 0o755, true);
    file_put_contents($aligned.'/artisan', "<?php\n");
    file_put_contents(
        $aligned.'/release.json',
        json_encode(['project' => 'rateguru', 'release' => P73_RELEASE, 'source_sha' => P73_SOURCE_SHA]),
    );

    unlink($scratch.'/target/current');
    symlink($aligned, $scratch.'/target/current');
}

it('resumes a held target once the deployed code carries the backup source_sha', function () {
    $scratch = p73Scratch();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => P73_OTHER_RELEASE,
            'current_source_sha' => P73_OTHER_SOURCE_SHA,
        ]);

        $operation = restoreTargetHeldOperation($scratch);
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
        p73Cleanup($scratch);
    }
});

it('refuses to resume while the code still does not match, and leaves the target held', function () {
    $scratch = p73Scratch();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => P73_OTHER_RELEASE,
            'current_source_sha' => P73_OTHER_SOURCE_SHA,
        ]);

        $operation = restoreTargetHeldOperation($scratch);

        $result = restoreTargetRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('still does not carry the backup');
        expect($result['output'])->toContain('the target stays held');

        expect(restoreTargetMaintenanceActive($scratch))->toBeTrue();
        expect(restoreTargetQueueState($scratch))->toBe('STOPPED');
        expect(restoreTargetSchedulerPresent($scratch))->toBeFalse();
    } finally {
        p73Cleanup($scratch);
    }
});

it('refuses to resume an unknown operation, another target operation, or one that is not held', function () {
    $scratch = p73Scratch();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => P73_OTHER_RELEASE,
            'current_source_sha' => P73_OTHER_SOURCE_SHA,
        ]);

        $unknown = restoreTargetRun($scratch, ['--resume', '--target', 'parity-target', '--operation', '20260101-000000-ffffff']);
        expect($unknown['exit'])->not->toBe(0);
        expect($unknown['output'])->toContain('restore operation workspace does not exist');

        $operation = restoreTargetHeldOperation($scratch);
        $state = p73State($scratch.'/run/restores/parity-target/'.$operation);

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
        p73Cleanup($scratch);
    }
});

it('refuses to resume when current is malformed or resolves outside the releases tree', function () {
    $scratch = p73Scratch();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => P73_OTHER_RELEASE,
            'current_source_sha' => P73_OTHER_SOURCE_SHA,
        ]);

        $operation = restoreTargetHeldOperation($scratch);

        // current points outside releases/ — a broken deployment, never
        // something a restore reasons about.
        mkdir($scratch.'/rogue-release', 0o755, true);
        file_put_contents($scratch.'/rogue-release/artisan', "<?php\n");
        file_put_contents(
            $scratch.'/rogue-release/release.json',
            json_encode(['release' => P73_RELEASE, 'source_sha' => P73_SOURCE_SHA]),
        );
        unlink($scratch.'/target/current');
        symlink($scratch.'/rogue-release', $scratch.'/target/current');

        $result = restoreTargetRun($scratch, ['--resume', '--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('carries no usable release/source_sha');
        expect(restoreTargetMaintenanceActive($scratch))->toBeTrue();
    } finally {
        p73Cleanup($scratch);
    }
});

it('does not resume a target whose health check fails, and holds it instead', function () {
    $scratch = p73Scratch();

    try {
        restoreTargetFixture($scratch, [
            'current_release' => P73_OTHER_RELEASE,
            'current_source_sha' => P73_OTHER_SOURCE_SHA,
        ]);

        $operation = restoreTargetHeldOperation($scratch);
        restoreTargetAlignCode($scratch);

        $result = restoreTargetRun(
            $scratch,
            ['--resume', '--target', 'parity-target', '--operation', $operation],
            ['P73_HEALTH_CHECK_EXIT' => '1'],
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
        p73Cleanup($scratch);
    }
});

// =============================================================================
// Locking, lifecycle and preconditions
// =============================================================================

it('serializes against another restore for the same backup namespace', function () {
    $scratch = p73Scratch();

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
        p73Cleanup($scratch);
    }
});

it('serializes against a deploy, rollback or cleanup through the existing target deployment lock', function () {
    $scratch = p73Scratch();

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
        p73Cleanup($scratch);
    }

    // It is the EXISTING lock, not a new incompatible one.
    expect(File::get(base_path('infrastructure/scripts/restore-target')))
        ->toContain('acquire_deployment_lock "${TARGET_ROOT}"');
});

it('rejects a planned target before creating a workspace, quiescing anything or downloading a backup', function () {
    $scratch = p73Scratch();

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
        p73Cleanup($scratch);
    }
});

it('refuses to restore a target with no deployed release', function () {
    $scratch = p73Scratch();

    try {
        restoreTargetFixture($scratch);
        unlink($scratch.'/target/current');

        $result = restoreTargetApply($scratch);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('a live data restore requires a deployed target');
        expect(is_dir($scratch.'/run/restores'))->toBeFalse();
    } finally {
        p73Cleanup($scratch);
    }
});

it('refuses a backup that cannot identify the code its data belongs to', function () {
    $scratch = p73Scratch();

    try {
        restoreTargetFixture($scratch, [
            'backup' => ['release_json' => "{}\n", 'manifest' => p73Manifest(['release' => 'unknown'])],
        ]);

        $result = restoreTargetApply($scratch);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('carries no release');

        // Nothing was quiesced and nothing live was touched.
        expect(restoreTargetMaintenanceActive($scratch))->toBeFalse();
        expect(is_file(restoreTargetStorage($scratch).'/app/live-marker.txt'))->toBeTrue();
        expect(p73Databases($scratch))->toBe(['parity_db']);
    } finally {
        p73Cleanup($scratch);
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
