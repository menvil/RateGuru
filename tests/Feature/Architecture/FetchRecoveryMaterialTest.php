<?php

use Illuminate\Support\Facades\File;

/**
 * fetch-recovery-material: the narrow clean-host helper that turns one exact
 * offsite backup plus a two-file seed directory into the effective material
 * directory a recovery preparation feeds install-target-prerequisites.
 *
 * It downloads the bootstrap subset of the backup — never the data — from the
 * fixed remote, proves the backup is the target's own and carries its
 * recovery material, judges that material through the REAL
 * install-target-prerequisites, and composes: the environment file from the
 * backup's environment.env, every host-scope prerequisite from the backup's
 * recovery-material.tar.gz, and the two seeds. Nothing is chosen, nothing is
 * restored, nothing is activated.
 */
function fetchRecoveryMaterialScript(): string
{
    return base_path('infrastructure/scripts/fetch-recovery-material');
}

/**
 * Everything the helper needs: the real targets CLI and the real
 * prerequisite installer pointed at a scratch checkout, the fake offsite
 * remote, and root's scratch parent inside the test tree.
 *
 * @return array<string, string>
 */
function fetchRecoveryMaterialEnv(string $scratch, array $overrides = [], array $registryOptions = []): array
{
    [$registryPath, $targetsPath] = parityRegistryFixture($scratch, $registryOptions);

    @mkdir($scratch.'/root-home', 0o700, true);
    @mkdir($scratch.'/bin', 0o755, true);
    offsiteRcloneStub($scratch);
    touch($scratch.'/rclone.log');

    return array_merge(recoveryMaterialPrerequisitesEnv($scratch, $registryPath, $targetsPath), [
        'PATH' => $scratch.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin'),
        'HOME' => getenv('HOME') ?: '/tmp',
        'RATEGURU_ALLOW_TEST_OVERRIDES' => 'true',
        'RATEGURU_RECOVERYMATERIAL_EUID' => '0',
        'RATEGURU_RECOVERYMATERIAL_TARGETS_CLI_BIN' => $targetsPath,
        'RATEGURU_RECOVERYMATERIAL_PREREQUISITES_BIN' => base_path('infrastructure/scripts/install-target-prerequisites'),
        'RATEGURU_RECOVERYMATERIAL_SOURCE_REGISTRY' => $registryPath,
        'RATEGURU_RECOVERYMATERIAL_SCRATCH_PARENT' => $scratch.'/root-home',
        'RATEGURU_RECOVERYMATERIAL_ENFORCE_OWNERSHIP' => 'false',
        'RATEGURU_RCLONE_BIN' => $scratch.'/bin/rclone',
        'RGTEST_RCLONE_LOG' => $scratch.'/rclone.log',
        'RGTEST_REMOTE_ROOT' => $scratch.'/remote',
    ], $overrides);
}

/** The seed directory the recovery workflow stages: exactly two files. */
function fetchRecoveryMaterialSeed(string $scratch, ?array $files = null): string
{
    $seed = $scratch.'/seed';
    mkdir($seed, 0o700, true);
    chmod($seed, 0o700);

    foreach ($files ?? [
        'rclone-config' => "[rateguru-b2]\ntype = b2\naccount = recovery-reader\n",
        'deploy-authorized-keys' => "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExampleExampleExampleExampleExampleExampleExam deploy\n",
    ] as $name => $content) {
        file_put_contents($seed.'/'.$name, $content);
        chmod($seed.'/'.$name, 0o600);
    }

    return $seed;
}

function fetchRecoveryMaterialOutputDir(string $scratch): string
{
    $out = $scratch.'/effective';
    mkdir($out, 0o700, true);
    chmod($out, 0o700);

    return $out;
}

/**
 * @return array{exit: int, output: string}
 */
function fetchRecoveryMaterialRun(string $scratch, array $arguments, array $envOverrides = [], array $registryOptions = []): array
{
    [$exit, $output] = runInfraScript(fetchRecoveryMaterialScript(), $arguments, fetchRecoveryMaterialEnv($scratch, $envOverrides, $registryOptions));

    return ['exit' => $exit, 'output' => $output];
}

/** @return list<string> */
function fetchRecoveryMaterialDefaultArguments(string $scratch): array
{
    return [
        '--target', 'parity-target',
        '--backup', '20260115-023000',
        '--seed-dir', fetchRecoveryMaterialSeed($scratch),
        '--output-dir', fetchRecoveryMaterialOutputDir($scratch),
    ];
}

// =============================================================================
// Shipping
// =============================================================================

it('ships as a clean-host CLI in the manifest, transported per run and never installed', function () {
    expect(File::exists(fetchRecoveryMaterialScript()))->toBeTrue();
    expect(is_executable(fetchRecoveryMaterialScript()))->toBeTrue();
    expect(requiredCliManifestNames())->toContain('fetch-recovery-material');

    // Like prepare-host: it runs on a machine that has no operational bundle
    // yet, so it never sources common and is not part of the bundle.
    $source = File::get(fetchRecoveryMaterialScript());

    expect($source)->not->toContain('/home/www/rateguru/bin/common');
    expect($source)->not->toMatch('/^\s*source\s/m');
    expect(File::get(base_path('infrastructure/scripts/install-target-operations')))
        ->not->toContain('scripts/fetch-recovery-material');
});

// =============================================================================
// The happy path
// =============================================================================

it('downloads only the bootstrap subset of the exact backup from the fixed remote, and composes the effective material', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryOffsiteBackupFixture($scratch);
        $arguments = fetchRecoveryMaterialDefaultArguments($scratch);

        $result = fetchRecoveryMaterialRun($scratch, $arguments);

        expect($result['exit'])->toBe(0, $result['output']);

        // Five objects, by copyto, from the fixed remote path — and never the
        // database dump or the storage archive.
        $calls = array_values(array_filter(preg_split('/\R/', File::get($scratch.'/rclone.log'))));
        expect($calls)->toHaveCount(5);

        $remote = 'rateguru-b2:rateguru-database-backups/rateguru/parity/20260115-023000';
        $downloaded = [];

        foreach ($calls as $call) {
            expect($call)->toStartWith('rclone --config '.$scratch.'/seed/rclone-config copyto '.$remote.'/');
            preg_match('#copyto '.preg_quote($remote, '#').'/([^ ]+) #', $call, $matches);
            $downloaded[] = $matches[1];
        }

        sort($downloaded);
        expect($downloaded)->toBe(['SHA256SUMS', 'environment.env', 'manifest.json', 'recovery-material.tar.gz', 'release.json']);

        // The effective material: the environment file, the seven host-scope
        // names and the two seeds, root-only, 0600 each.
        $out = $scratch.'/effective';
        $present = array_values(array_diff(scandir($out), ['.', '..']));
        sort($present);
        $expected = ['laravel-env', 'deploy-authorized-keys', 'rclone-config', ...recoveryMaterialNames()];
        sort($expected);

        expect($present)->toBe($expected);

        foreach ($present as $name) {
            expect(is_link($out.'/'.$name))->toBeFalse();
            expect(substr(sprintf('%o', fileperms($out.'/'.$name)), -4))->toBe('0600');
        }

        expect(File::get($out.'/laravel-env'))->toBe(preparedEnvironmentContents());
        expect(File::get($out.'/tls-private-key'))->toBe("material-tls-private-key-never-logged\n");
        expect(File::get($out.'/deploy-authorized-keys'))->toBe(File::get($scratch.'/seed/deploy-authorized-keys'));
        expect(File::get($out.'/rclone-config'))->toBe(File::get($scratch.'/seed/rclone-config'));

        // Names and origins are reported; content and digests are not.
        expect($result['output'])
            ->toContain('recovery material staged for parity-target from offsite backup 20260115-023000')
            ->toContain('laravel-env')
            ->toContain('backup environment.env')
            ->toContain('effective material: '.(count(recoveryMaterialNames()) + 3).' files')
            ->not->toContain('never-logged')
            ->not->toContain('recovery-reader')
            ->not->toContain('s3cr3t')
            ->not->toMatch('/[0-9a-f]{64}/');

        // The scratch directory under root's home is gone.
        expect(glob($scratch.'/root-home/*'))->toBe([]);
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// The backup must be the target's own, intact, and recovery-capable
// =============================================================================

it('refuses a backup that carries no recovery material, by name, with no fallback', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryOffsiteBackupFixture($scratch, options: ['schema' => 2]);

        $result = fetchRecoveryMaterialRun($scratch, fetchRecoveryMaterialDefaultArguments($scratch));

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('backup 20260115-023000 is not clean-host-recovery-capable: its manifest schema is 2, and a clean-host recovery requires a format that carries the target\'s recovery material inside the backup (schema 4, or schema 3 for a target whose mail is not signed)')
            ->toContain('there is deliberately no fallback to hand-supplied material')
            ->toContain('Nothing on this host was changed')
            // And the operator is told what to do, in the one shared format.
            ->toContain('RECOVERY ACTION REQUIRED')
            ->toContain('Cause: backup 20260115-023000 is a schema 2 backup, written before the recovery material joined the format')
            ->toContain('Then: re-run "Recover staging host" with mode=start and that backup\'s exact timestamp')
            ->toContain('Runbook: infrastructure/runbooks/clean-host-recovery.md')
            ->not->toContain('PREPARE_');

        expect(array_diff(scandir($scratch.'/effective'), ['.', '..']))->toBe([]);
        expect(glob($scratch.'/root-home/*'))->toBe([]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('refuses a backup whose identity, checksums or release do not hold', function (array $backupOptions, ?callable $tamper, string $expected) {
    $scratch = restoreScratchDir();

    try {
        $backup = recoveryOffsiteBackupFixture($scratch, options: $backupOptions);

        if ($tamper !== null) {
            $tamper($backup);
        }

        $result = fetchRecoveryMaterialRun($scratch, fetchRecoveryMaterialDefaultArguments($scratch));

        expect($result['exit'])->not->toBe(0, "expected a refusal: {$expected}");
        expect($result['output'])->toContain($expected);
        expect(array_diff(scandir($scratch.'/effective'), ['.', '..']))->toBe([]);
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    'another target' => [
        ['manifest' => backupManifestFixture(['manifest_schema_version' => 3, 'target' => 'somebody-else'])],
        null,
        'backup manifest target mismatch',
    ],
    'a tampered environment file' => [
        [],
        function (string $backup): void {
            file_put_contents($backup.'/environment.env', "APP_ENV=tampered\n");
        },
        'failed SHA-256 verification',
    ],
    'a release without its commit' => [
        ['release_json' => ['project' => 'rateguru', 'release' => FIXTURE_RELEASE]],
        null,
        'carries no source_sha',
    ],
    'a manifest that is a string' => [
        ['manifest' => backupManifestFixture(['manifest_schema_version' => '3'])],
        null,
        'not clean-host-recovery-capable',
    ],
]);

it('judges the recovery material through the prerequisite installer before extracting a byte', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryOffsiteBackupFixture($scratch, options: [
            'recovery_material' => recoveryMaterialMembers() + ['rclone-config' => "[b2]\n"],
        ]);

        $result = fetchRecoveryMaterialRun($scratch, fetchRecoveryMaterialDefaultArguments($scratch));

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('not a host-scope prerequisite of parity-target: rclone-config')
            ->toContain('nothing was extracted');

        expect(array_diff(scandir($scratch.'/effective'), ['.', '..']))->toBe([]);
        expect(glob($scratch.'/root-home/*'))->toBe([]);
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// The seed is closed in both directions
// =============================================================================

it('accepts a seed directory holding exactly the two seed files, root-only', function (?array $files, ?int $mode, string $expected) {
    $scratch = restoreScratchDir();

    try {
        recoveryOffsiteBackupFixture($scratch);

        $seed = fetchRecoveryMaterialSeed($scratch, $files);

        if ($mode !== null) {
            chmod($seed, $mode);
        }

        $result = fetchRecoveryMaterialRun($scratch, [
            '--target', 'parity-target', '--backup', '20260115-023000',
            '--seed-dir', $seed, '--output-dir', fetchRecoveryMaterialOutputDir($scratch),
        ]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain($expected);
        expect(File::get($scratch.'/rclone.log'))->toBe('', 'a refused seed downloads nothing');
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    'a hand-supplied environment file beside the seeds' => [
        [
            'rclone-config' => "[rateguru-b2]\n",
            'deploy-authorized-keys' => "ssh-ed25519 AAAA deploy\n",
            'laravel-env' => "APP_ENV=staging\n",
        ],
        null,
        'seed directory holds a file that is not seed material: laravel-env',
    ],
    'a TLS key beside the seeds' => [
        [
            'rclone-config' => "[rateguru-b2]\n",
            'deploy-authorized-keys' => "ssh-ed25519 AAAA deploy\n",
            'tls-private-key' => "key\n",
        ],
        null,
        'seed directory holds a file that is not seed material: tls-private-key',
    ],
    'a missing deploy key' => [
        ['rclone-config' => "[rateguru-b2]\n"],
        null,
        'seed material is missing: deploy-authorized-keys',
    ],
    'a missing offsite credential' => [
        ['deploy-authorized-keys' => "ssh-ed25519 AAAA deploy\n"],
        null,
        'seed material is missing: rclone-config',
    ],
    'a group-readable seed directory' => [
        null,
        0o750,
        '--seed-dir must be mode 0700',
    ],
]);

it('tells the operator which GitHub value a missing seed comes from, in the shared format', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryOffsiteBackupFixture($scratch);

        $seed = fetchRecoveryMaterialSeed($scratch, ['deploy-authorized-keys' => "ssh-ed25519 AAAA deploy\n"]);

        $result = fetchRecoveryMaterialRun($scratch, [
            '--target', 'parity-target', '--backup', '20260115-023000',
            '--seed-dir', $seed, '--output-dir', fetchRecoveryMaterialOutputDir($scratch),
        ]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain('RECOVERY ACTION REQUIRED')
            ->toContain('Cause: seed material rclone-config is missing')
            ->toContain('the recovery workflow seeds exactly rclone-config (RECOVERY_RCLONE_CONFIG) and deploy-authorized-keys (derived from DEPLOY_SSH_KEY)')
            ->toContain('  1. configure RECOVERY_RCLONE_CONFIG and DEPLOY_SSH_KEY in the GitHub Environment')
            ->toContain('  2. never place an rclone configuration or a key on the host by hand')
            ->toContain('Then: re-run "Recover staging host" with mode=start')
            ->toContain('Runbook: infrastructure/runbooks/clean-host-recovery.md')
            ->toContain('ERROR: seed material is missing: rclone-config');
    } finally {
        removeScratchDir($scratch);
    }
});

// =============================================================================
// What it will never do
// =============================================================================

it('takes no source selector, no latest, no remote, no bucket, no path and no commit', function (array $arguments, string $expected) {
    $scratch = restoreScratchDir();

    try {
        recoveryOffsiteBackupFixture($scratch);

        $result = fetchRecoveryMaterialRun($scratch, $arguments);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain($expected);
        expect(File::get($scratch.'/rclone.log'))->toBe('');
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    'no backup at all' => [
        ['--target', 'parity-target', '--seed-dir', '/root/seed', '--output-dir', '/root/out'],
        "a recovery never selects a backup implicitly, and there is no 'latest'",
    ],
    'latest' => [
        ['--target', 'parity-target', '--backup', 'latest', '--seed-dir', '/root/seed', '--output-dir', '/root/out'],
        'invalid backup ID (expected YYYYMMDD-HHMMSS): latest',
    ],
    'a remote' => [
        ['--target', 'parity-target', '--backup', '20260115-023000', '--seed-dir', '/root/seed', '--output-dir', '/root/out', '--remote', 'other:bucket'],
        'unknown argument: --remote',
    ],
    'a bucket' => [
        ['--target', 'parity-target', '--backup', '20260115-023000', '--seed-dir', '/root/seed', '--output-dir', '/root/out', '--bucket', 'other'],
        'unknown argument: --bucket',
    ],
    'a path' => [
        ['--target', 'parity-target', '--backup', '20260115-023000', '--seed-dir', '/root/seed', '--output-dir', '/root/out', '--path', 'rateguru/other'],
        'unknown argument: --path',
    ],
    'a commit' => [
        ['--target', 'parity-target', '--backup', '20260115-023000', '--seed-dir', '/root/seed', '--output-dir', '/root/out', '--source-sha', str_repeat('a', 40)],
        'unknown argument: --source-sha',
    ],
    'a source selector' => [
        ['--target', 'parity-target', '--backup', '20260115-023000', '--seed-dir', '/root/seed', '--output-dir', '/root/out', '--source', 'local'],
        'unknown argument: --source',
    ],
]);

it('requires root, and a lifecycle=active target, before it reads anything', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryOffsiteBackupFixture($scratch);

        $result = fetchRecoveryMaterialRun($scratch, fetchRecoveryMaterialDefaultArguments($scratch), [
            'RATEGURU_RECOVERYMATERIAL_EUID' => '1000',
        ]);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('must run as root');
        expect(File::get($scratch.'/rclone.log'))->toBe('');
    } finally {
        removeScratchDir($scratch);
    }

    $scratch = restoreScratchDir();

    try {
        recoveryOffsiteBackupFixture($scratch);

        $arguments = fetchRecoveryMaterialDefaultArguments($scratch);
        $arguments[1] = 'planned-target';

        $result = fetchRecoveryMaterialRun($scratch, $arguments);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain('not active');
        expect(File::get($scratch.'/rclone.log'))->toBe('');
    } finally {
        removeScratchDir($scratch);
    }
});

it('never restores data, deploys, activates, or touches a service', function () {
    $source = executableSourceLines(File::get(fetchRecoveryMaterialScript()));

    foreach ([
        'pg_restore',
        'psql',
        'createdb',
        'restore-database',
        'restore-storage',
        'restore-target',
        'recover-host',
        'scripts/deploy',
        'deploy-rateguru',
        'rateguru-deploy',
        'supervisorctl',
        'systemctl',
        'crontab',
        'cron.d',
        'ln -s',
        'ln -sfn',
        'shared/.env',
        '/current',
        '/previous',
        ' lsf ',
        ' ls ',
        'copy ',
    ] as $forbidden) {
        // str_contains + toBeFalse rather than not->toContain: toContain is
        // variadic and has no message parameter, so a trailing diagnostic
        // becomes a second needle and the negation then passes on anything.
        expect(str_contains($source, $forbidden))
            ->toBeFalse("fetch-recovery-material must never: {$forbidden}");
    }

    // Exactly one rclone verb, one object at a time — and the fixed subset it
    // fetches is the bootstrap subset: the data files are named only as
    // members of the closed SHA256SUMS set it verifies, never downloaded.
    expect($source)->toContain('copyto')
        ->toContain('BOOTSTRAP_SUBSET=("${MANIFEST_FILE}" release.json environment.env recovery-material.tar.gz SHA256SUMS)')
        ->toContain('download_object "${name}"');

    foreach (['database.dump', 'storage-app.tar.gz', 'server-configuration.tar.gz'] as $data) {
        expect(str_contains($source, 'download_object '.$data) || str_contains($source, 'copyto '.$data))
            ->toBeFalse("fetch-recovery-material must never download {$data}");
    }

    // The remote is composed from the fixed defaults and the target's own
    // namespace, never from an argument.
    expect($source)->toContain('REMOTE_DIRECTORY="${RCLONE_REMOTE}:${RCLONE_BUCKET}/rateguru/${BACKUP_NAMESPACE}/${BACKUP_ID}"');
});

// =============================================================================
// A target whose mail is signed: the DKIM key comes back, proven
// =============================================================================

/** An active production parity-target: its mail goes out signed. */
const FETCH_SIGNED_TARGET = ['environment_class' => 'production'];

it('recovers the DKIM key of a target whose mail is signed out of a schema 4 backup, byte for byte, after proving it against the fingerprint the backup recorded', function () {
    $scratch = restoreScratchDir();

    try {
        $key = mailIdentityKey('rsa2048');
        recoveryOffsiteSignedBackupFixture($scratch, $key);

        $result = fetchRecoveryMaterialRun($scratch, fetchRecoveryMaterialDefaultArguments($scratch), registryOptions: FETCH_SIGNED_TARGET);

        expect($result['exit'])->toBe(0, $result['output']);

        // The recovery scope of a production target, its key included, the
        // environment file and the two seeds — and nothing else.
        $out = $scratch.'/effective';
        $present = array_values(array_diff(scandir($out), ['.', '..']));
        sort($present);
        $expected = ['laravel-env', 'deploy-authorized-keys', 'rclone-config', 'mail-dkim-private-key', ...recoveryMaterialProductionNames()];
        sort($expected);
        expect($present)->toBe($expected);

        // The exact key: the same bytes, and so the same public half the
        // published record names.
        expect(File::get($out.'/mail-dkim-private-key'))->toBe(File::get($key));
        expect(dkimPublicKeySha256($out.'/mail-dkim-private-key'))->toBe(dkimPublicKeySha256($key));
        expect(substr(sprintf('%o', fileperms($out.'/mail-dkim-private-key')), -4))->toBe('0600');

        expect($result['output'])
            ->toContain('manifest: schema 4, project/environment/target/namespace/database identify parity-target')
            ->toContain("DKIM key: OK (usable under parity-target's reviewed policy; the sha256 of its public half is the recorded ".dkimPublicKeySha256($key).')')
            ->toContain('6 recovery-scope files for parity-target');
        expectNoKeyMaterial($result['output'], $key);

        expect(glob($scratch.'/root-home/*'))->toBe([]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('stages nothing when a schema 4 backup\'s DKIM key is not the one it recorded, or not one at all', function (string $case, string $expected) {
    $scratch = restoreScratchDir();

    try {
        $key = mailIdentityKey($case === 'a key the policy refuses' ? 'rsa1024' : 'rsa2048');

        [$manifest, $members] = match ($case) {
            'a manifest recording another key' => [['dkim_public_key_sha256' => dkimPublicKeySha256(mailIdentityKey('rsa3072'))], []],
            'a manifest recording no key' => [['dkim_public_key_sha256' => null], []],
            'a malformed fingerprint' => [['dkim_public_key_sha256' => 'not-a-digest'], []],
            'a key the policy refuses' => [[], []],
            'no key in the archive' => [[], ['mail-dkim-private-key' => null]],
            'a stranger in the archive' => [[], ['laravel-env' => "APP_KEY=smuggled\n"]],
        };

        recoveryOffsiteSignedBackupFixture($scratch, $key, $manifest, $members);

        $result = fetchRecoveryMaterialRun($scratch, fetchRecoveryMaterialDefaultArguments($scratch), registryOptions: FETCH_SIGNED_TARGET);

        expect($result['exit'])->not->toBe(0, $result['output']);
        expect($result['output'])->toContain($expected);

        // Nothing staged, nothing generated in the key's place, nothing left
        // behind, and the key never shown.
        expect(array_diff(scandir($scratch.'/effective'), ['.', '..']))->toBe([]);
        expect(glob($scratch.'/root-home/*'))->toBe([]);
        expectNoKeyMaterial($result['output'], $key);
    } finally {
        removeScratchDir($scratch);
    }
})->with([
    'a manifest recording another key' => ['a manifest recording another key', "the extracted recovery material's mail-dkim-private-key is not the key backup 20260115-023000 recorded"],
    'a manifest recording no key' => ['a manifest recording no key', "the extracted recovery material carries mail-dkim-private-key, but backup 20260115-023000's manifest records no fingerprint for it"],
    'a malformed fingerprint' => ['a malformed fingerprint', "backup 20260115-023000's manifest dkim_public_key_sha256 is not a SHA-256 fingerprint"],
    'a key the policy refuses' => ['a key the policy refuses', "the extracted recovery material's mail-dkim-private-key is not a usable DKIM key under parity-target's reviewed policy"],
    'no key in the archive' => ['no key in the archive', 'recovery material archive is missing the recovery-scope prerequisite mail-dkim-private-key of parity-target'],
    'a stranger in the archive' => ['a stranger in the archive', 'recovery material archive contains a file that is not a recovery-scope prerequisite of parity-target: laravel-env'],
]);

it('refuses a schema 3 backup for a target whose mail is signed, by name, before downloading anything but the manifest', function () {
    $scratch = restoreScratchDir();

    try {
        // What a backup written before the key joined the recovery material
        // holds: a production target's host scope, and no key.
        $members = [];
        foreach (recoveryMaterialProductionNames() as $name) {
            $members[$name] = "material-{$name}-never-logged\n";
        }

        recoveryOffsiteBackupFixture($scratch, options: [
            'manifest' => backupManifestFixture(['manifest_schema_version' => 3, 'environment' => 'production']),
            'recovery_material' => $members,
        ]);

        $result = fetchRecoveryMaterialRun($scratch, fetchRecoveryMaterialDefaultArguments($scratch), registryOptions: FETCH_SIGNED_TARGET);

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])
            ->toContain("backup 20260115-023000 is not clean-host-recovery-capable for parity-target: it is a schema 3 backup, and parity-target's recovery material also needs mail-dkim-private-key, which only a schema 4 backup carries")
            ->toContain('none is ever generated or substituted')
            ->toContain('This backup stays restorable onto a live target through Restore Target Data')
            ->toContain('RECOVERY ACTION REQUIRED')
            ->toContain('Cause: backup 20260115-023000 is a schema 3 backup, written before the DKIM signing key joined the recovery material')
            ->toContain('Then: re-run "Recover production host" with mode=start and that backup\'s exact timestamp');

        // The manifest alone decided it.
        $calls = array_values(array_filter(preg_split('/\R/', File::get($scratch.'/rclone.log'))));
        expect($calls)->toHaveCount(1);
        expect($calls[0])->toContain('/manifest.json ');

        expect(array_diff(scandir($scratch.'/effective'), ['.', '..']))->toBe([]);
        expect(glob($scratch.'/root-home/*'))->toBe([]);
    } finally {
        removeScratchDir($scratch);
    }
});

it('recovers a target whose mail is not signed from a schema 4 backup that records no key, and refuses one that claims a key it cannot carry', function () {
    $scratch = restoreScratchDir();

    try {
        recoveryOffsiteBackupFixture($scratch, options: ['schema' => 4]);

        $result = fetchRecoveryMaterialRun($scratch, fetchRecoveryMaterialDefaultArguments($scratch));

        expect($result['exit'])->toBe(0, $result['output']);
        expect(array_diff(scandir($scratch.'/effective'), ['.', '..']))->not->toContain('mail-dkim-private-key');
        expect($result['output'])->toContain('manifest: schema 4')->not->toContain('DKIM key:');
    } finally {
        removeScratchDir($scratch);
    }

    $scratch = restoreScratchDir();

    try {
        recoveryOffsiteBackupFixture($scratch, options: ['schema' => 4, 'dkim_public_key_sha256' => str_repeat('a', 64)]);

        $result = fetchRecoveryMaterialRun($scratch, fetchRecoveryMaterialDefaultArguments($scratch));

        expect($result['exit'])->not->toBe(0);
        expect($result['output'])->toContain("backup 20260115-023000's manifest records a DKIM key fingerprint, but the extracted recovery material carries no mail-dkim-private-key for parity-target");
        expect(array_diff(scandir($scratch.'/effective'), ['.', '..']))->toBe([]);
    } finally {
        removeScratchDir($scratch);
    }
});
