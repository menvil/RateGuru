<?php

use Illuminate\Support\Facades\File;

/**
 * Phase 7.3's own scope guard: what this phase establishes, and — more
 * importantly — everything it deliberately does not begin.
 *
 * 7.3 ends when a live target's DATA can be restored from one exact,
 * fully-verified backup, safely, with an emergency backup taken first and a
 * compensating undo for every live step. The GitHub-facing restore surface is
 * 7.4, Repair Target is 7.5, Recover Host is 7.6/7.7, the rejected durable
 * artifact archive stays rejected, the accepted backup subsystem is untouched,
 * and production stays unprovisioned until Phase 8.
 */

/**
 * Every operational file a rejected architecture could sneak back into.
 *
 * @return list<string>
 */
function p73OperationalFiles(): array
{
    $configFiles = [];

    $tree = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('infrastructure/config'), FilesystemIterator::SKIP_DOTS),
    );

    foreach ($tree as $entry) {
        if ($entry->isFile()) {
            $configFiles[] = $entry->getPathname();
        }
    }

    return array_values(array_filter(array_merge(
        glob(base_path('.github/workflows/*.yml')) ?: [],
        glob(base_path('.github/actions/*/action.yml')) ?: [],
        glob(base_path('infrastructure/scripts/*')) ?: [],
        $configFiles,
    ), 'is_file'));
}

/** The five restore CLIs and the one restore-only library this phase adds. */
function p73NewPrimitives(): array
{
    return [
        'infrastructure/scripts/fetch-backup',
        'infrastructure/scripts/verify-backup',
        'infrastructure/scripts/restore-database',
        'infrastructure/scripts/restore-storage',
        'infrastructure/scripts/restore-target',
        'infrastructure/scripts/restore-common',
    ];
}

/**
 * The revision this branch is measured against: the pull request's own base
 * commit in CI, `origin/develop` locally, or null when neither is available.
 */
function p73BaseRevision(): ?string
{
    $baseSha = getenv('BASE_SHA');

    if (is_string($baseSha) && $baseSha !== '' && p73GitSucceeds(['cat-file', '-e', $baseSha.'^{commit}'])) {
        return $baseSha;
    }

    return p73GitSucceeds(['rev-parse', '--verify', 'origin/develop']) ? 'origin/develop' : null;
}

/**
 * @param  list<string>  $arguments
 */
function p73GitSucceeds(array $arguments): bool
{
    // Every argument is escaped individually: BASE_SHA is an environment
    // value and this runs through a shell.
    $command = 'cd '.escapeshellarg(base_path()).' && git '
        .implode(' ', array_map('escapeshellarg', $arguments))
        .' >/dev/null 2>&1; echo $?';

    return trim((string) shell_exec($command)) === '0';
}

/** @return list<string> */
function p73ChangedFiles(): array
{
    $base = p73BaseRevision();

    $baseline = trim((string) shell_exec(
        'cd '.escapeshellarg(base_path()).' && git diff --name-only '
            .escapeshellarg((string) $base).' HEAD 2>/dev/null'
    ));

    return $baseline === '' ? [] : explode("\n", $baseline);
}

/**
 * The lines this branch adds to, and removes from, one file.
 *
 * `-U0` so the hunks carry no context: every `+` really is an addition. This is
 * what lets a scope guard say "exactly this much changed, and nothing else"
 * about a file the phase deliberately touches, instead of the blunter "this
 * file did not change at all".
 *
 * @return array{added: list<string>, removed: list<string>}
 */
function p73FileDiff(string $path): array
{
    $diff = (string) shell_exec(
        'cd '.escapeshellarg(base_path()).' && git diff -U0 '
            .escapeshellarg((string) p73BaseRevision()).' HEAD -- '.escapeshellarg($path).' 2>/dev/null'
    );

    $added = [];
    $removed = [];

    foreach (explode("\n", $diff) as $line) {
        if (str_starts_with($line, '+++') || str_starts_with($line, '---')) {
            continue;
        }

        if (str_starts_with($line, '+')) {
            $added[] = mb_substr($line, 1);
        } elseif (str_starts_with($line, '-')) {
            $removed[] = mb_substr($line, 1);
        }
    }

    return ['added' => $added, 'removed' => $removed];
}

/**
 * One file as this branch has it committed.
 *
 * These guards describe the branch, not the working tree — that is what a diff
 * against the base measures, and what CI reviews. Reading the file from HEAD
 * too keeps both halves of an assertion talking about the same thing, instead
 * of comparing a committed diff against uncommitted edits.
 */
function p73CommittedFile(string $path): string
{
    return (string) shell_exec(
        'cd '.escapeshellarg(base_path()).' && git show HEAD:'.escapeshellarg($path).' 2>/dev/null'
    );
}

// =============================================================================
// What Phase 7.3 adds
// =============================================================================

it('adds exactly the five restore primitives and one restore-only library', function () {
    foreach (p73NewPrimitives() as $path) {
        expect(File::exists(base_path($path)))->toBeTrue("{$path} is missing");
    }

    // And nothing else: infrastructure/scripts is exactly the CLI manifest
    // plus the two sourced libraries.
    $flat = collect(glob(base_path('infrastructure/scripts/*')) ?: [])
        ->filter(static fn (string $path): bool => is_file($path))
        ->map(static fn (string $path): string => basename($path))
        ->sort()
        ->values()
        ->all();

    $expected = [...requiredCliManifestNames(), ...sourcedLibraryNames()];
    sort($expected);

    expect($flat)->toBe($expected);
});

it('keeps one implementation of each restore concern rather than five copies', function () {
    $library = File::get(base_path('infrastructure/scripts/restore-common'));

    // The five kinds of validation that must be identical everywhere live in
    // restore-common, once each.
    foreach ([
        'validate_backup_id()',
        'validate_operation_id()',
        'restore_assert_directory_safe()',
        'restore_assert_backup_file_set()',
        'restore_assert_sha256sums_entries()',
        'restore_assert_storage_archive_safe()',
        'restore_assert_manifest_identity()',
        'restore_assert_recovery_identity()',
        'restore_state_require_phase()',
        'restore_remove_operation_path()',
        'restore_remove_storage_sibling()',
    ] as $function) {
        expect(substr_count($library, "\n{$function} {"))->toBe(1, "{$function} must be defined exactly once in restore-common");
    }

    // No CLI redefines any of them.
    foreach (['fetch-backup', 'verify-backup', 'restore-database', 'restore-storage', 'restore-target'] as $cli) {
        $source = File::get(base_path('infrastructure/scripts/'.$cli));

        foreach ([
            'validate_backup_id()',
            'validate_operation_id()',
            'restore_assert_storage_archive_safe()',
            'restore_assert_manifest_identity()',
        ] as $function) {
            expect($source)->not->toContain("\n{$function} {", "{$cli} must not redefine {$function}");
        }
    }
});

it('installs every new primitive through the existing target-operations installer', function () {
    $installer = File::get(base_path('infrastructure/scripts/install-target-operations'));

    foreach (['restore-common', 'fetch-backup', 'verify-backup', 'restore-database', 'restore-storage', 'restore-target'] as $name) {
        expect($installer)->toContain("infrastructure/scripts/{$name}");
        // Destinations compose from the two fixed root constants, so the
        // literal installed path is "${DST_BIN_ROOT}/<name>".
        expect($installer)->toContain('DST_BIN_ROOT}/'.$name.'"');
    }

    expect($installer)->toContain('DST_BIN_ROOT="/home/www/rateguru/bin"');

    // The authoritative counts were updated honestly, not left stale.
    expect($installer)
        ->toContain('twenty-two files')
        ->toContain('all twenty-two source files are present regular files')
        ->toContain('bash -n passed for all twenty source shell scripts')
        ->not->toContain('sixteen files')
        ->not->toContain('all fourteen source');

    // restore-common is installed as a library, never a CLI.
    expect($installer)->toContain('install_regular_file_transactional "${STAGE_DIR}/restore-common" "${DST_RESTORE_COMMON}" "${INSTALL_OWNER}" "${INSTALL_GROUP}" "${COMMON_MODE}"');

    // Only the five executables joined the required-CLI manifest.
    $manifest = requiredCliManifestNames();
    foreach (['fetch-backup', 'verify-backup', 'restore-database', 'restore-storage', 'restore-target'] as $cli) {
        expect($manifest)->toContain($cli);
    }
    expect($manifest)->not->toContain('restore-common');
});

// =============================================================================
// No GitHub restore surface — that is Phase 7.4
// =============================================================================

it('adds no GitHub restore workflow, action or composite step', function () {
    $workflows = collect(glob(base_path('.github/workflows/*.yml')) ?: [])
        ->map(static fn (string $path): string => basename($path))
        ->sort()
        ->values()
        ->all();

    expect($workflows)->toBe([
        'ci.yml',
        'coverage.yml',
        'deploy-staging.yml',
        'label-review-bot-prs.yml',
        'prepare-production-host.yml',
        'prepare-staging-host.yml',
        'release.yml',
        'rollback-production.yml',
        'rollback-staging.yml',
    ]);

    $actions = collect(glob(base_path('.github/actions/*'), GLOB_ONLYDIR) ?: [])
        ->map(static fn (string $path): string => basename($path))
        ->sort()
        ->values()
        ->all();

    expect($actions)->toBe([
        'build-rateguru',
        'deploy-rateguru',
        'prepare-rateguru-host',
        'record-rateguru-deployment',
        'rollback-rateguru',
        'sentry-release',
    ]);

    // No workflow or action mentions a restore primitive: 7.3 is entirely
    // server-side.
    foreach (array_merge(
        glob(base_path('.github/workflows/*.yml')) ?: [],
        glob(base_path('.github/actions/*/action.yml')) ?: [],
    ) as $path) {
        $source = File::get($path);

        foreach (['restore-target', 'fetch-backup', 'verify-backup', 'restore-database', 'restore-storage'] as $primitive) {
            expect($source)->not->toContain($primitive, basename(dirname($path)).'/'.basename($path)." must not drive {$primitive}");
        }
    }
});

it('adds no sudo wrapper and no sudoers grant for restore', function () {
    $wrappers = collect(glob(base_path('infrastructure/config/wrappers/*')) ?: [])
        ->map(static fn (string $path): string => basename($path))
        ->sort()
        ->values()
        ->all();

    expect($wrappers)->toBe([
        'rateguru-cleanup',
        'rateguru-deploy',
        'rateguru-nightwatch-deployment',
        'rateguru-rollback',
    ]);

    $sudoers = collect(glob(base_path('infrastructure/config/sudoers/*')) ?: [])
        ->map(static fn (string $path): string => basename($path))
        ->sort()
        ->values()
        ->all();

    expect($sudoers)->toBe(['rateguru-deploy', 'rateguru-nightwatch-deployment']);

    foreach (array_merge(
        glob(base_path('infrastructure/config/wrappers/*')) ?: [],
        glob(base_path('infrastructure/config/sudoers/*')) ?: [],
    ) as $path) {
        $source = File::get($path);

        foreach (['restore-target', 'fetch-backup', 'verify-backup', 'restore-database', 'restore-storage'] as $primitive) {
            expect($source)->not->toContain($primitive);
        }
    }

    // Restore is a root-only server operation an operator runs directly —
    // the restricted deploy channel gains nothing.
    expect(File::get(base_path('infrastructure/scripts/install-target-perimeter')))
        ->not->toContain('restore-target');
});

// =============================================================================
// No Repair, no Recover, no production activation
// =============================================================================

it('implements no Repair Target and no Recover Host', function () {
    foreach ([
        'infrastructure/scripts/repair-target',
        'infrastructure/scripts/recover-host',
        '.github/workflows/repair-staging.yml',
        '.github/workflows/recover-staging-host.yml',
        '.github/workflows/recover-production-host.yml',
        '.github/actions/repair-rateguru/action.yml',
        '.github/actions/recover-rateguru-host/action.yml',
    ] as $path) {
        expect(File::exists(base_path($path)))->toBeFalse("{$path} belongs to a later phase, not 7.3");
    }

    // Recovery rebuilds an application from the backup's source_sha. Restore
    // reads that commit to DECIDE alignment, and never builds anything.
    $restore = File::get(base_path('infrastructure/scripts/restore-target'));

    foreach (['composer', 'npm ', 'node ', 'vite', 'build-rateguru', 'git clone', 'git checkout'] as $forbidden) {
        expect($restore)->not->toContain($forbidden, "restore-target must never build: {$forbidden}");
    }
});

it('activates no production target and changes no DNS', function () {
    $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true);

    expect($registry['targets']['tits-guru']['lifecycle'])->toBe('planned');

    foreach (p73OperationalFiles() as $path) {
        $source = File::get($path);

        foreach (['cloudflare', 'route53', 'dns_record', 'certbot --force-renewal'] as $forbidden) {
            expect($source)->not->toContain($forbidden);
        }
    }
});

// =============================================================================
// No durable artifact archive, no backup redesign
// =============================================================================

it('adds no durable release-artifact archive and no artifact bucket', function () {
    foreach (p73OperationalFiles() as $path) {
        $source = File::get($path);

        foreach ([
            'rateguru-release-artifacts',
            'B2_ARTIFACT_',
            'ARTIFACT_BUCKET',
            'artifact_retention',
        ] as $rejected) {
            expect($source)->not->toContain($rejected);
        }
    }
});

it('leaves the accepted backup subsystem and its manifest schema untouched', function () {
    $changed = p73ChangedFiles();

    // toContain is variadic in Pest, so a second "message" argument is read as
    // another needle and the negation passes on any file — the diagnostic
    // belongs in a comment, not in the call.
    foreach ([
        'infrastructure/scripts/backup-cycle',
        'infrastructure/scripts/offsite-backup',
        'infrastructure/scripts/offsite-retention',
        'infrastructure/scripts/offsite-restore-test',
        'infrastructure/scripts/restore-test',
        'infrastructure/config/cron/rateguru-backups',
        'infrastructure/config/supervisor/rateguru-staging-queue.conf',
        'infrastructure/config/cron/rateguru-staging-scheduler',
    ] as $untouched) {
        expect($changed)->not->toContain($untouched);
    }
})->skip(fn (): bool => p73BaseRevision() === null,
    'requires the PR base SHA or an origin/develop reference');

it('adds exactly one fail-closed guard to backup, and changes nothing else in it', function () {
    // `backup` is the one file in the backup subsystem Phase 7.3 touches, and
    // it may only gain a refusal. A target held after a restore has data
    // belonging to a different commit than current/release.json names, and a
    // backup taken there would label it with that commit — so backup refuses
    // before creating anything. Removing a line, or adding any second
    // behaviour, would be a backup redesign wearing a guard's clothes.
    $diff = p73FileDiff('infrastructure/scripts/backup');

    expect($diff['removed'])->toBe([], 'Phase 7.3 may add a guard to backup, never remove or rewrite a line of it');

    $addedCode = array_values(array_filter(
        array_map('trim', $diff['added']),
        static fn (string $line): bool => $line !== '' && ! str_starts_with($line, '#'),
    ));

    expect($addedCode)->toBe(['assert_no_restore_hold "${TARGET_ID}" "${RUN_ROOT}" "a backup"']);

    // And it is a refusal, not a step: it runs before perform_backup.
    $backup = p73CommittedFile('infrastructure/scripts/backup');

    expect(mb_strpos($backup, 'assert_no_restore_hold'))
        ->toBeLessThan(mb_strpos($backup, "\n    perform_backup\n"));
})->skip(fn (): bool => p73BaseRevision() === null,
    'requires the PR base SHA or an origin/develop reference');

it('reads the existing backup format and defines no second one', function () {
    $library = File::get(base_path('infrastructure/scripts/restore-common'));

    // The exact seven files backup produces, named once, in the order backup
    // itself writes them.
    expect($library)->toContain("RESTORE_BACKUP_CHECKSUMMED_FILES=(\n    database.dump\n    storage-app.tar.gz\n    environment.env\n    release.json\n    server-configuration.tar.gz\n    manifest.json\n)");

    // Manifest classification is the shared one from common, not a second
    // incompatible implementation.
    expect($library)->toContain('manifest_schema_classify "${manifest_path}"');
    expect($library)->not->toContain('manifest_schema_version | type');

    // The emergency backup is the existing backup implementation, and it is
    // verified by the existing restore-test.
    $restore = File::get(base_path('infrastructure/scripts/restore-target'));
    expect($restore)
        ->toContain('"${RESTORE_BACKUP_BIN}" --target "${TARGET_ID}"')
        ->toContain('"${RESTORE_RESTORE_TEST_BIN}" --target "${TARGET_ID}"');
});

// =============================================================================
// Hard invariants
// =============================================================================

it('runs no migration anywhere in the restore path', function () {
    foreach (p73NewPrimitives() as $path) {
        $source = File::get(base_path($path));

        foreach ([
            'artisan migrate',
            'migrate --force',
            'migrate:fresh',
            'migrate:refresh',
            'migrate:reset',
            'migrate:rollback',
            'db:wipe',
            'schema:dump',
            'DROP SCHEMA',
            'CREATE SCHEMA',
        ] as $forbidden) {
            expect($source)->not->toContain($forbidden, basename($path)." must never run {$forbidden}");
        }
    }

    // And the one artisan surface restore-target does use is a closed set:
    // maintenance mode plus the scheduler barrier, nothing else.
    preg_match_all('/artisan_as_runtime_user (\w[\w:.-]*)/', File::get(base_path('infrastructure/scripts/restore-target')), $matches);

    expect(array_values(array_unique($matches[1])))
        ->toEqualCanonicalizing(['down', 'up', 'schedule:interrupt']);
});

it('never applies environment.env or server-configuration.tar.gz to a live target', function () {
    foreach (p73NewPrimitives() as $path) {
        $source = File::get(base_path($path));

        foreach (preg_split('/\R/', $source) as $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            foreach (['environment.env', 'server-configuration.tar.gz'] as $neverApplied) {
                if (! str_contains($trimmed, $neverApplied)) {
                    continue;
                }

                expect($trimmed)->not->toMatch(
                    '/(^|[;&|]\s*)(cp|install|mv|ln|source|eval)\s/',
                    basename($path)." must never copy, install, move, link or source {$neverApplied}: {$trimmed}",
                );

                expect($trimmed)->not->toMatch(
                    '/(^|[;&|]\s*)tar\s+[^;&|]*-x/',
                    basename($path)." must never extract {$neverApplied}: {$trimmed}",
                );
            }
        }

        // Nothing in the restore path writes the target's own environment
        // file or any /etc path.
        expect($source)->not->toMatch('#>\s*"?\$\{?TARGET_ROOT\}?/shared/\.env#');
        expect($source)->not->toMatch('#tar\s+[^;&|]*-x[^;&|]*-C\s+/etc#');
    }

    // The only file a restore ever applies, besides the database dump, is the
    // storage archive — declared as a closed list.
    expect(File::get(base_path('infrastructure/scripts/restore-common')))
        ->toContain("RESTORE_APPLIED_FILES=(\n    database.dump\n    storage-app.tar.gz\n)")
        ->toContain("RESTORE_NEVER_APPLIED_FILES=(\n    environment.env\n    server-configuration.tar.gz\n)");
});

it('never switches a release, and never touches the current or previous link', function () {
    foreach (p73NewPrimitives() as $path) {
        $source = File::get(base_path($path));

        expect($source)->not->toMatch('/\bln\s+-sfn?\b/', basename($path).' must never create a release link');
        expect($source)->not->toContain('mv -Tf "${CURRENT_LINK}');

        // The links may be READ (that is how alignment is decided) but never
        // written: no redirection into either of them, anywhere.
        expect($source)->not->toMatch('/>\s*"?\$\{(CURRENT|PREVIOUS)_LINK\}/');
    }

    // The links are compared, never written.
    expect(File::get(base_path('infrastructure/scripts/restore-target')))
        ->toContain('the current release symlink changed during the restore — a restore never switches releases');
});

it('adds only the restore guard helpers to the shared library', function () {
    // `common` is sourced by every operational script, so a change to it
    // reaches deploy, rollback, cleanup and backup at once. Phase 7.3 adds the
    // two helpers that let a guarded target refuse an ordinary backup, and must
    // not touch a line of anything that was already there.
    $diff = p73FileDiff('infrastructure/scripts/common');

    expect($diff['removed'])->toBe([], 'Phase 7.3 may add to common, never remove or rewrite a line of it');

    // Every added line belongs to the new section, delimited by its own header
    // and the existing registry terminator.
    $common = p73CommittedFile('infrastructure/scripts/common');
    $start = mb_strpos($common, '# --- restore guard (Phase 7.3) ---');
    $end = mb_strpos($common, '# --- deployment target registry (end) ---');

    expect($start)->not->toBeFalse('the restore guard section is missing from common');
    expect($end)->toBeGreaterThan($start);

    $section = mb_substr($common, $start, $end - $start);

    foreach ($diff['added'] as $line) {
        expect($section)->toContain($line);
    }

    // The section defines exactly the two helpers, and neither mutates.
    expect($section)->toContain('restore_guard_file()');
    expect($section)->toContain('assert_no_restore_hold()');
    expect($section)->not->toMatch('/^\s*(rm|mv|install|touch|chmod|chown)\s/m');
})->skip(fn (): bool => p73BaseRevision() === null,
    'requires the PR base SHA or an origin/develop reference');

it('does not weaken deploy, rollback, cleanup or any earlier phase contract', function () {
    $changed = p73ChangedFiles();

    // See the note above on toContain's variadic signature: no message here.
    foreach ([
        'infrastructure/scripts/deploy',
        'infrastructure/scripts/rollback',
        'infrastructure/scripts/cleanup',
        'infrastructure/scripts/targets',
        'infrastructure/scripts/health-check',
        'infrastructure/scripts/status',
        'infrastructure/scripts/prepare-host',
        'infrastructure/scripts/bootstrap-host',
        'infrastructure/config/deployment-targets.json',
        'infrastructure/templates/deployment.conf.example',
    ] as $untouched) {
        expect($changed)->not->toContain($untouched);
    }

    // Deploy gains no restore mode, no backup selector and no hidden
    // migration behaviour: that integration, if it is ever needed, is 7.4.
    $deploy = File::get(base_path('infrastructure/scripts/deploy'));

    foreach (['--restore', '--backup', 'restore-target', 'restore_hold', 'RESTORE_'] as $forbidden) {
        expect($deploy)->not->toContain($forbidden);
    }
})->skip(fn (): bool => p73BaseRevision() === null,
    'requires the PR base SHA or an origin/develop reference');

it('leaves a state and journal contract a later phase can build the alignment deploy on', function () {
    $restore = File::get(base_path('infrastructure/scripts/restore-target'));

    // The held state is machine-readable and names exactly what is required
    // to resume.
    foreach ([
        'status held',
        'code_alignment required',
        'runtime_resumed no',
        'backup_source_sha',
        'restore-target --resume --target %s --operation %s',
    ] as $contract) {
        expect($restore)->toContain($contract);
    }

    // And the journal records every field an operator or a later workflow
    // needs, with no secret among them.
    foreach ([
        'status:', 'operation_id:', 'started_at:', 'completed_at:', 'target:',
        'environment:', 'backup_namespace:', 'source:', 'backup:',
        'backup_release:', 'backup_source_sha:', 'current_release_before:',
        'current_source_sha_before:', 'emergency_backup:', 'code_alignment:',
        'runtime_resumed:', 'failed_step:', 'compensation_status:',
    ] as $field) {
        expect($restore)->toContain($field);
    }

    foreach (['DB_PASSWORD', 'PGPASSWORD', 'rclone.conf', 'authorized_keys'] as $secret) {
        expect($restore)->not->toMatch('/--arg \w+ "\$\{'.preg_quote($secret, '/').'/');
    }
});

it('ships the runbook and points the README and roadmap at it', function () {
    expect(File::exists(base_path('infrastructure/runbooks/restore-target.md')))->toBeTrue();

    $runbook = File::get(base_path('infrastructure/runbooks/restore-target.md'));

    expect($runbook)
        ->toContain('RESTORE TARGET DATA')
        ->toContain('RECOVER HOST')
        ->toContain('--resume')
        ->toContain('emergency')
        ->toContain('code alignment')
        ->toContain('No migrations');

    expect(File::get(base_path('infrastructure/README.md')))
        ->toContain('runbooks/restore-target.md');

    $roadmap = File::get(base_path('infrastructure/ROADMAP.md'));

    expect($roadmap)->toContain('7.3 Restore Target Data — implemented');
    expect($roadmap)->toContain('runbooks/restore-target.md');

    // Implemented, not accepted: CI proves the structure, only a real
    // destructive staging run proves the pipeline.
    expect(preg_replace('/\s+/', ' ', $roadmap))
        ->toContain('implemented, awaiting real staging acceptance')
        ->toContain('this slice is implemented rather than accepted');
});
