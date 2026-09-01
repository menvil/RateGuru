<?php

use Illuminate\Support\Facades\File;

/**
 * Phase 7.3: `verify-backup` — the gate every destructive restore passes
 * before anything live is touched.
 *
 * Executes the real shipped infrastructure/scripts/verify-backup against real
 * on-disk backup fixtures: real gzip archives, real sha256sum output, real
 * manifests. Nothing here is stubbed except the target registry itself.
 */
function verifyBackupScript(): string
{
    return base_path('infrastructure/scripts/verify-backup');
}

/**
 * Stages a backup directory into a restore operation workspace by hand — no
 * dependency on fetch-backup, so a fixture verify-backup must reject can be
 * staged even when fetch-backup would have refused it first.
 */
function verifyBackupStage(string $scratch, string $backupDir, string $operationId = '20260115-120000-abc123'): string
{
    $workspace = $scratch.'/run/restores/parity-target/'.$operationId;
    mkdir($workspace.'/selected-backup', 0o700, true);
    chmod($workspace, 0o700);

    foreach (scandir($backupDir) as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        copy($backupDir.'/'.$entry, $workspace.'/selected-backup/'.$entry);
    }

    return $operationId;
}

/**
 * @return array{exit: int, output: string, workspace: string}
 */
function verifyBackupRun(string $scratch, array $arguments, array $envOverrides = [], array $registryOptions = []): array
{
    [$registryPath, $targetsPath] = p73Registry($scratch, $registryOptions);

    $env = p73BaseEnv($scratch, $registryPath, $targetsPath, $envOverrides);

    [$exit, $output] = p73Run(p73PatchedScript($scratch, 'verify-backup'), $arguments, $env);

    return ['exit' => $exit, 'output' => $output, 'workspace' => $scratch.'/run/restores/parity-target'];
}

/** Rewrites a staged backup's storage archive and repairs its checksum line. */
function verifyBackupReplaceArchive(string $stagedDir, array $entries): void
{
    p73Archive($stagedDir.'/storage-app.tar.gz', $entries);

    $lines = [];
    foreach (preg_split('/\R/', trim(File::get($stagedDir.'/SHA256SUMS'))) as $line) {
        if ($line === '') {
            continue;
        }

        [, $name] = preg_split('/ {2}/', $line, 2);
        $lines[] = hash_file('sha256', $stagedDir.'/'.$name).'  '.$name;
    }

    file_put_contents($stagedDir.'/SHA256SUMS', implode("\n", $lines)."\n");
}

// =============================================================================
// The happy path
// =============================================================================

it('verifies a complete, well-formed backup', function () {
    $scratch = p73Scratch();

    try {
        $backup = p73BuildBackup($scratch.'/source', '20260115-120000');
        $operation = verifyBackupStage($scratch, $backup);

        $result = verifyBackupRun($scratch, ['--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])
            ->toContain('required files: OK')
            ->toContain('SHA256SUMS entry list: OK')
            ->toContain('checksums: OK')
            ->toContain('manifest identity: OK')
            ->toContain('storage archive: OK');
    } finally {
        p73Cleanup($scratch);
    }
});

// =============================================================================
// Checksums and the SHA256SUMS entry list
// =============================================================================

it('fails when a backup file was modified after its checksum was taken', function () {
    $scratch = p73Scratch();

    try {
        $backup = p73BuildBackup($scratch.'/source', '20260115-120000', ['corrupt_after_checksum' => true]);
        $operation = verifyBackupStage($scratch, $backup);

        $result = verifyBackupRun($scratch, ['--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('failed SHA-256 verification');
    } finally {
        p73Cleanup($scratch);
    }
});

it('rejects a SHA256SUMS naming a file outside the backup, before a single checksum is followed', function (string $entry) {
    $scratch = p73Scratch();

    try {
        $backup = p73BuildBackup($scratch.'/source', '20260115-120000', [
            'extra_sha_lines' => [str_repeat('a', 64).'  '.$entry],
        ]);
        $operation = verifyBackupStage($scratch, $backup);

        $result = verifyBackupRun($scratch, ['--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->not->toBe(0, "expected '{$entry}' to be rejected");
        expect($result['output'])
            ->toContain('SHA256SUMS references a file that is not part of a RateGuru backup')
            ->not->toContain('checksums: OK');
    } finally {
        p73Cleanup($scratch);
    }
})->with([
    'absolute path' => '/etc/shadow',
    'parent traversal' => '../../etc/shadow',
    'nested traversal' => 'app/../../etc/shadow',
    'an unrelated extra file' => 'extra-payload.bin',
    'the checksum file itself' => 'SHA256SUMS',
]);

it('rejects a SHA256SUMS that lists the same file twice or omits one', function () {
    $scratch = p73Scratch();

    try {
        $backup = p73BuildBackup($scratch.'/source', '20260115-120000');
        $operation = verifyBackupStage($scratch, $backup);
        $staged = $scratch.'/run/restores/parity-target/'.$operation.'/selected-backup';

        $original = File::get($staged.'/SHA256SUMS');
        file_put_contents($staged.'/SHA256SUMS', $original.hash_file('sha256', $staged.'/database.dump')."  database.dump\n");

        $duplicate = verifyBackupRun($scratch, ['--target', 'parity-target', '--operation', $operation]);
        expect($duplicate['exit'])->not->toBe(0);
        expect($duplicate['output'])->toContain('references database.dump more than once');
    } finally {
        p73Cleanup($scratch);
    }

    $scratch = p73Scratch();

    try {
        $backup = p73BuildBackup($scratch.'/source', '20260115-120000');
        $operation = verifyBackupStage($scratch, $backup);
        $staged = $scratch.'/run/restores/parity-target/'.$operation.'/selected-backup';

        $kept = array_values(array_filter(
            preg_split('/\R/', trim(File::get($staged.'/SHA256SUMS'))),
            fn (string $line): bool => $line !== '' && ! str_ends_with($line, '  environment.env'),
        ));
        file_put_contents($staged.'/SHA256SUMS', implode("\n", $kept)."\n");

        $missing = verifyBackupRun($scratch, ['--target', 'parity-target', '--operation', $operation]);
        expect($missing['exit'])->not->toBe(0);
        expect($missing['output'])->toContain('SHA256SUMS does not cover environment.env');
    } finally {
        p73Cleanup($scratch);
    }
});

it('rejects a malformed SHA256SUMS line', function () {
    $scratch = p73Scratch();

    try {
        $backup = p73BuildBackup($scratch.'/source', '20260115-120000', [
            'extra_sha_lines' => ['not-a-checksum-line'],
        ]);
        $operation = verifyBackupStage($scratch, $backup);

        $result = verifyBackupRun($scratch, ['--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('SHA256SUMS carries a malformed entry');
    } finally {
        p73Cleanup($scratch);
    }
});

// =============================================================================
// Manifest identity — the existing accepted contract, reused
// =============================================================================

it('fails on a manifest that belongs to another target, environment, database or namespace', function (array $manifestOverrides, string $expected) {
    $scratch = p73Scratch();

    try {
        $backup = p73BuildBackup($scratch.'/source', '20260115-120000', [
            'manifest' => p73Manifest($manifestOverrides),
        ]);
        $operation = verifyBackupStage($scratch, $backup);

        $result = verifyBackupRun($scratch, ['--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain($expected);
    } finally {
        p73Cleanup($scratch);
    }
})->with([
    'wrong project' => [['project' => 'other-project'], 'backup manifest project mismatch'],
    'wrong environment' => [['environment' => 'production'], 'backup manifest environment mismatch'],
    'wrong database' => [['database' => 'someone_elses_db'], 'backup manifest database mismatch'],
    'wrong namespace' => [['backup_namespace' => 'tits-guru'], 'backup manifest backup_namespace mismatch'],
    'wrong target' => [['target' => 'staging-main'], 'backup manifest target mismatch'],
    'empty target' => [['target' => ''], 'backup manifest target is an empty string'],
]);

it('rejects an unsupported manifest schema version exactly as the existing backup contract does', function (mixed $schemaVersion) {
    $scratch = p73Scratch();

    try {
        $backup = p73BuildBackup($scratch.'/source', '20260115-120000', [
            'manifest' => p73Manifest(['manifest_schema_version' => $schemaVersion]),
        ]);
        $operation = verifyBackupStage($scratch, $backup);

        $result = verifyBackupRun($scratch, ['--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('unsupported backup manifest schema_version');
    } finally {
        p73Cleanup($scratch);
    }
})->with([
    'a future numeric schema' => 3,
    'schema zero' => 0,
    // The JSON *string* "2" is not the JSON number 2: without type-first
    // classification this silently passed as legacy schema 1.
    'a string that looks like 2' => '2',
    'a boolean' => true,
    'an array' => [[[2]]],
]);

it('still accepts a legacy schema 1 manifest, and a schema 2 manifest predating the target field', function () {
    $scratch = p73Scratch();

    try {
        $legacy = p73BuildBackup($scratch.'/source-legacy', '20260115-120000', [
            'manifest' => [
                'project' => 'rateguru',
                'environment' => 'staging',
                'created_at' => '2025-01-01T00:00:00Z',
                'hostname' => 'legacy-host',
                'database' => 'parity_db',
                'release' => P73_RELEASE,
            ],
        ]);
        $operation = verifyBackupStage($scratch, $legacy, '20260115-120000-aaa111');

        $result = verifyBackupRun($scratch, ['--target', 'parity-target', '--operation', $operation]);
        expect($result['exit'])->toBe(0, $result['output']);
    } finally {
        p73Cleanup($scratch);
    }

    $scratch = p73Scratch();

    try {
        $preCutover = p73BuildBackup($scratch.'/source-precutover', '20260115-120000', [
            'manifest' => p73Manifest(['target' => null]),
        ]);
        $operation = verifyBackupStage($scratch, $preCutover, '20260115-120000-bbb222');

        $result = verifyBackupRun($scratch, ['--target', 'parity-target', '--operation', $operation]);
        expect($result['exit'])->toBe(0, $result['output']);
    } finally {
        p73Cleanup($scratch);
    }
});

// =============================================================================
// Recovery identity — the extra gate a DESTRUCTIVE restore adds
// =============================================================================

it('accepts a backup with no recoverable release identity for an ordinary verification', function () {
    $scratch = p73Scratch();

    try {
        // Exactly what backup writes when a target had no current release:
        // a historical backup that restore-test has always accepted, and
        // that stays acceptable for a non-destructive verification.
        $backup = p73BuildBackup($scratch.'/source', '20260115-120000', [
            'release_json' => "{}\n",
            'manifest' => p73Manifest(['release' => 'unknown']),
        ]);
        $operation = verifyBackupStage($scratch, $backup);

        $result = verifyBackupRun($scratch, ['--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->toBe(0, $result['output']);
    } finally {
        p73Cleanup($scratch);
    }
});

it('refuses the same backup for a destructive restore, because source_sha cannot be determined', function () {
    $scratch = p73Scratch();

    try {
        $backup = p73BuildBackup($scratch.'/source', '20260115-120000', [
            'release_json' => "{}\n",
            'manifest' => p73Manifest(['release' => 'unknown']),
        ]);
        $operation = verifyBackupStage($scratch, $backup);

        $result = verifyBackupRun($scratch, ['--target', 'parity-target', '--operation', $operation, '--for-restore']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('carries no release');
    } finally {
        p73Cleanup($scratch);
    }
});

it('refuses a destructive restore when release.json carries a malformed release or source_sha', function (array $releaseJson, string $expected) {
    $scratch = p73Scratch();

    try {
        $backup = p73BuildBackup($scratch.'/source', '20260115-120000', [
            'release_json' => $releaseJson,
            'manifest' => p73Manifest(['release' => 'unknown']),
        ]);
        $operation = verifyBackupStage($scratch, $backup);

        $result = verifyBackupRun($scratch, ['--target', 'parity-target', '--operation', $operation, '--for-restore']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain($expected);
    } finally {
        p73Cleanup($scratch);
    }
})->with([
    'no source_sha at all' => [
        ['release' => P73_RELEASE],
        'carries no source_sha',
    ],
    'a source_sha that is not a commit' => [
        ['release' => P73_RELEASE, 'source_sha' => 'not-a-sha'],
        'malformed source_sha',
    ],
    'a release that is not a RateGuru release ID' => [
        ['release' => 'HEAD', 'source_sha' => P73_SOURCE_SHA],
        'malformed release',
    ],
]);

it('refuses a destructive restore when the manifest release and release.json disagree', function () {
    $scratch = p73Scratch();

    try {
        $backup = p73BuildBackup($scratch.'/source', '20260115-120000', [
            'manifest' => p73Manifest(['release' => P73_OTHER_RELEASE]),
        ]);
        $operation = verifyBackupStage($scratch, $backup);

        $result = verifyBackupRun($scratch, ['--target', 'parity-target', '--operation', $operation, '--for-restore']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('does not match release.json release');
    } finally {
        p73Cleanup($scratch);
    }
});

it('accepts a manifest release of unknown alongside a fully specified release.json', function () {
    $scratch = p73Scratch();

    try {
        $backup = p73BuildBackup($scratch.'/source', '20260115-120000', [
            'manifest' => p73Manifest(['release' => 'unknown']),
        ]);
        $operation = verifyBackupStage($scratch, $backup);

        $result = verifyBackupRun($scratch, ['--target', 'parity-target', '--operation', $operation, '--for-restore']);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])->toContain('recovery identity: OK');
    } finally {
        p73Cleanup($scratch);
    }
});

it('writes a verified identity document carrying build identity and no secret', function () {
    $scratch = p73Scratch();

    try {
        $backup = p73BuildBackup($scratch.'/source', '20260115-120000');
        $operation = verifyBackupStage($scratch, $backup);

        $result = verifyBackupRun($scratch, ['--target', 'parity-target', '--operation', $operation, '--for-restore']);
        expect($result['exit'])->toBe(0, $result['output']);

        $identityPath = $scratch.'/run/restores/parity-target/'.$operation.'/verified-identity.json';
        expect(is_file($identityPath))->toBeTrue();
        expect(substr(sprintf('%o', fileperms($identityPath)), -4))->toBe('0600');

        $identity = json_decode(File::get($identityPath), true);

        expect($identity)->toMatchArray([
            'target' => 'parity-target',
            'environment' => 'staging',
            'backup_namespace' => 'parity',
            'operation' => $operation,
            'for_restore' => true,
            'release' => P73_RELEASE,
            'source_sha' => P73_SOURCE_SHA,
        ]);

        // Public build identity only: no checksum digest, no environment
        // value, no credential.
        expect(File::get($identityPath))
            ->not->toContain('from-backup-never-applied')
            ->not->toContain('DB_PASSWORD');
        expect(array_keys($identity))->not->toContain('checksum');
    } finally {
        p73Cleanup($scratch);
    }
});

// =============================================================================
// Storage archive safety — before anything is extracted as root
// =============================================================================

it('accepts an archive of ordinary directories and regular files under app/', function () {
    $scratch = p73Scratch();

    try {
        $backup = p73BuildBackup($scratch.'/source', '20260115-120000');
        $operation = verifyBackupStage($scratch, $backup);
        $staged = $scratch.'/run/restores/parity-target/'.$operation.'/selected-backup';

        verifyBackupReplaceArchive($staged, [
            ['name' => 'app', 'type' => 'dir'],
            ['name' => 'app/public', 'type' => 'dir'],
            ['name' => 'app/public/media', 'type' => 'dir'],
            ['name' => 'app/public/media/photo.jpg', 'type' => 'file'],
            ['name' => 'app/private', 'type' => 'dir'],
            ['name' => 'app/private/report.pdf', 'type' => 'file'],
        ]);

        $result = verifyBackupRun($scratch, ['--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->toBe(0, $result['output']);
        expect($result['output'])->toContain('storage archive: OK');
    } finally {
        p73Cleanup($scratch);
    }
});

it('refuses an archive that would escape app/ or create anything but a directory or a regular file', function (array $entries, string $expected) {
    $scratch = p73Scratch();

    try {
        $backup = p73BuildBackup($scratch.'/source', '20260115-120000');
        $operation = verifyBackupStage($scratch, $backup);
        $staged = $scratch.'/run/restores/parity-target/'.$operation.'/selected-backup';

        verifyBackupReplaceArchive($staged, $entries);

        $result = verifyBackupRun($scratch, ['--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->not->toBe(0, 'the archive must be refused: '.$expected);
        expect($result['output'])->toContain($expected);
    } finally {
        p73Cleanup($scratch);
    }
})->with([
    'an absolute path' => [
        [['name' => 'app', 'type' => 'dir'], ['name' => '/etc/cron.d/evil', 'type' => 'file']],
        'contains an absolute path',
    ],
    'a parent traversal' => [
        [['name' => 'app', 'type' => 'dir'], ['name' => '../evil.txt', 'type' => 'file']],
        'contains an entry outside app/',
    ],
    'a traversal that starts inside app/' => [
        [['name' => 'app', 'type' => 'dir'], ['name' => 'app/../../etc/evil', 'type' => 'file']],
        'contains a relative path component',
    ],
    'a sibling root outside app/' => [
        [['name' => 'app', 'type' => 'dir'], ['name' => 'framework/cache/x', 'type' => 'file']],
        'contains an entry outside app/',
    ],
    'a root that merely starts with app' => [
        [['name' => 'app', 'type' => 'dir'], ['name' => 'application/evil', 'type' => 'file']],
        'contains an entry outside app/',
    ],
    'a symbolic link' => [
        [['name' => 'app', 'type' => 'dir'], ['name' => 'app/link', 'type' => 'symlink', 'link' => '/etc/passwd']],
        'contains a symbolic link',
    ],
    'a hard link' => [
        [
            ['name' => 'app', 'type' => 'dir'],
            ['name' => 'app/regular.txt', 'type' => 'file'],
            ['name' => 'app/hard', 'type' => 'hardlink', 'link' => 'app/regular.txt'],
        ],
        'contains a hard link',
    ],
    'a character device' => [
        [['name' => 'app', 'type' => 'dir'], ['name' => 'app/null', 'type' => 'chardev']],
        'contains a device node',
    ],
    'a block device' => [
        [['name' => 'app', 'type' => 'dir'], ['name' => 'app/disk', 'type' => 'blockdev']],
        'contains a device node',
    ],
    'a FIFO' => [
        [['name' => 'app', 'type' => 'dir'], ['name' => 'app/pipe', 'type' => 'fifo']],
        'contains a FIFO',
    ],
]);

it('refuses an unreadable or empty storage archive', function () {
    $scratch = p73Scratch();

    try {
        $backup = p73BuildBackup($scratch.'/source', '20260115-120000', [
            'storage_archive_bytes' => "this is not a gzip archive\n",
        ]);
        $operation = verifyBackupStage($scratch, $backup);

        $result = verifyBackupRun($scratch, ['--target', 'parity-target', '--operation', $operation]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('storage archive is unreadable');
    } finally {
        p73Cleanup($scratch);
    }
});

// =============================================================================
// Workspace and lifecycle
// =============================================================================

it('fails when the operation workspace or the staged backup does not exist', function () {
    $scratch = p73Scratch();

    try {
        $result = verifyBackupRun($scratch, ['--target', 'parity-target', '--operation', '20260115-120000-abc123']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('restore operation workspace does not exist');
    } finally {
        p73Cleanup($scratch);
    }
});

it('rejects a planned target before reading any staged backup', function () {
    $scratch = p73Scratch();

    try {
        $result = verifyBackupRun($scratch, ['--target', 'planned-target', '--operation', '20260115-120000-abc123']);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('lifecycle=planned');
    } finally {
        p73Cleanup($scratch);
    }
});

it('requires root for a real invocation', function () {
    $source = File::get(verifyBackupScript());

    expect($source)->toContain("main() {\n    require_root\n    parse_verify_args");
});
