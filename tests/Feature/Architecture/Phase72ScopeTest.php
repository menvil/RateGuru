<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * Phase 7.2's own scope guard: what this phase establishes, and — more
 * importantly — the four later phases it deliberately does not begin.
 *
 * 7.2 ends when a clean VPS can be PREPARED and a successful deployment state
 * transition is visible in both observability systems. Restore (7.3), Repair
 * (7.5) and Recover (7.6) come after it, the rejected durable-artifact archive
 * stays rejected, the backup architecture is untouched, and production stays
 * unprovisioned until Phase 8.
 */

/**
 * Every operational file a rejected architecture could sneak back into.
 *
 * PHP's glob has no `**`, so `infrastructure/config` is walked recursively
 * rather than globbed — the committed config tree is two levels deep today and
 * a guard that silently stopped at the first would be worse than no guard.
 *
 * @return list<string>
 */
function p72OperationalFiles(): array
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

/**
 * The revision this branch is measured against: the pull request's own base
 * commit in CI, `origin/develop` locally, or null when neither is available.
 *
 * `actions/checkout` on a pull_request event creates no `origin/develop` ref,
 * so a guard that only looked for that would silently skip in exactly the place
 * it matters most. CI therefore passes the base commit through BASE_SHA and
 * checks out two commits, which is enough for the merge commit's first parent
 * to be present.
 */
function p72BaseRevision(): ?string
{
    $baseSha = getenv('BASE_SHA');

    if (is_string($baseSha) && $baseSha !== '' && p72GitSucceeds(['cat-file', '-e', $baseSha.'^{commit}'])) {
        return $baseSha;
    }

    return p72GitSucceeds(['rev-parse', '--verify', 'origin/develop']) ? 'origin/develop' : null;
}

/**
 * @param  list<string>  $arguments
 */
function p72GitSucceeds(array $arguments): bool
{
    // Every argument is escaped individually. BASE_SHA is an environment value
    // and this runs through a shell, so interpolating it raw would let a
    // metacharacter in it execute in the test runner.
    $command = 'cd '.escapeshellarg(base_path()).' && git '
        .implode(' ', array_map('escapeshellarg', $arguments))
        .' >/dev/null 2>&1; echo $?';

    return trim((string) shell_exec($command)) === '0';
}

// =============================================================================
// What Phase 7.2 adds
// =============================================================================

it('adds exactly the operator-facing workflows Phase 7.2 is meant to add', function () {
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
});

it('adds exactly the shared actions Phase 7.2 is meant to add', function () {
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
});

it('keeps one implementation per operation', function () {
    // Phase 7.1's model, extended by exactly two operations rather than
    // forked: one BUILD, one DEPLOY, one ROLLBACK, one PREPARE, one
    // DEPLOYMENT-RECORDING implementation, with per-environment workflows on
    // top wherever policy differs.
    foreach ([
        'build' => 'build-rateguru',
        'deploy' => 'deploy-rateguru',
        'rollback' => 'rollback-rateguru',
        'prepare' => 'prepare-rateguru-host',
        'observability' => 'record-rateguru-deployment',
    ] as $operation => $action) {
        expect(File::exists(base_path(".github/actions/{$action}/action.yml")))
            ->toBeTrue("the shared {$operation} action is missing");
    }
});

// =============================================================================
// No Restore, Repair or Recover
// =============================================================================

it('implements no Restore operation', function () {
    // restore-test remains what it has always been: a read-only proof that a
    // backup can be restored into a throwaway scratch database. Turning it
    // into a live restore was Phase 7.3, and nothing 7.2 added does it.
    //
    // Phase 7.3 landed the SERVER primitives (restore-target and friends);
    // their own scope guard is Phase73ScopeTest. What stays out of both
    // phases — the GitHub-facing restore surface — is Phase 7.4, and is
    // asserted here because 7.2 is the phase that owns the workflow/action
    // inventory.
    expect(File::exists(base_path('infrastructure/scripts/restore-test')))->toBeTrue();

    foreach ([
        'infrastructure/scripts/restore',
        '.github/workflows/restore-staging.yml',
        '.github/workflows/restore-production.yml',
        '.github/actions/restore-rateguru/action.yml',
    ] as $path) {
        expect(File::exists(base_path($path)))->toBeFalse("{$path} belongs to Phase 7.4, not 7.2 or 7.3");
    }

    // And nothing added in 7.2 restores anything.
    foreach ([
        'infrastructure/scripts/prepare-host',
        'infrastructure/scripts/install-target-prerequisites',
        'infrastructure/scripts/install-target-database',
    ] as $script) {
        $source = File::get(base_path($script));

        expect($source)->not->toContain('pg_restore');
        expect($source)->not->toContain('database.dump');
        expect($source)->not->toContain('storage-app.tar.gz');
    }
});

it('implements no Repair operation', function () {
    foreach ([
        'infrastructure/scripts/repair-target',
        '.github/workflows/repair-staging.yml',
        '.github/actions/repair-rateguru/action.yml',
    ] as $path) {
        expect(File::exists(base_path($path)))->toBeFalse("{$path} belongs to Phase 7.5, not 7.2");
    }

    // Drift is reported and fails closed; it is never reconciled. That
    // distinction is what keeps Repair a separate phase.
    expect(File::get(base_path('infrastructure/scripts/install-target-database')))
        ->toContain('resolve the mismatch manually');
    expect(File::get(base_path('infrastructure/scripts/install-target-prerequisites')))
        ->toContain('refusing to overwrite');
});

it('implements no Recover operation', function () {
    foreach ([
        'infrastructure/scripts/recover-host',
        '.github/workflows/recover-staging-host.yml',
        '.github/workflows/recover-production-host.yml',
        '.github/actions/recover-rateguru-host/action.yml',
    ] as $path) {
        expect(File::exists(base_path($path)))->toBeFalse("{$path} belongs to Phase 7.6/7.7, not 7.2");
    }

    // Recovery rebuilds an application from the source SHA a backup carries.
    // Preparation never builds anything.
    expect(File::get(base_path('.github/actions/prepare-rateguru-host/action.yml')))
        ->not->toContain('build-rateguru');
});

// =============================================================================
// No durable artifact archive, no backup redesign
// =============================================================================

it('adds no durable release-artifact archive', function () {
    foreach (p72OperationalFiles() as $path) {
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

    // GitHub artifacts stay what they are: temporary CI/deployment transport.
    $deployStaging = Yaml::parse(File::get(base_path('.github/workflows/deploy-staging.yml')));
    $build = collect($deployStaging['jobs']['build']['steps'])
        ->firstWhere('uses', './.github/actions/build-rateguru');

    expect($build)->not->toBeNull('the staging build step is missing');
    expect($build['with']['artifact-retention-days'])->toBe('3');
});

it('leaves the backup architecture and its manifest schema untouched', function () {
    $base = p72BaseRevision();

    // A plain two-tree diff, not a three-dot range: computing a merge base
    // needs history a shallow CI clone does not have, and on a pull_request
    // checkout HEAD already contains the base merged in, so the two are
    // equivalent here.
    $baseline = trim((string) shell_exec(
        'cd '.escapeshellarg(base_path()).' && git diff --name-only '
            .escapeshellarg($base).' HEAD 2>/dev/null'
    ));

    $changed = $baseline === '' ? [] : explode("\n", $baseline);

    foreach ([
        'infrastructure/scripts/backup',
        'infrastructure/scripts/backup-cycle',
        'infrastructure/scripts/offsite-backup',
        'infrastructure/scripts/offsite-retention',
        'infrastructure/scripts/offsite-restore-test',
        'infrastructure/scripts/restore-test',
        'infrastructure/config/cron/rateguru-backups',
    ] as $untouched) {
        expect($changed)->not->toContain($untouched);
    }
})->skip(fn (): bool => p72BaseRevision() === null,
    'requires the PR base SHA or an origin/develop reference');

it('keeps release.json exactly as it is', function () {
    // release.release stays the operator/history identity, release.source_sha
    // stays what a future Recover Host will rebuild from. Phase 7.2 reads both
    // and adds nothing.
    $metadata = File::get(base_path('app/Support/Deployment/DeploymentMetadata.php'));

    expect($metadata)->toContain("\$decoded['release']");
    expect($metadata)->toContain("\$decoded['source_sha']");

    $build = File::get(base_path('.github/actions/build-rateguru/action.yml'));
    expect($build)->not->toContain('backup_namespace');
    expect($build)->not->toContain('artifact_archive');
});

// =============================================================================
// Nothing is activated, nothing unrelated is managed
// =============================================================================

it('activates no production target', function () {
    $registry = json_decode(File::get(base_path('infrastructure/config/deployment-targets.json')), true);

    expect($registry['targets']['tits-guru']['lifecycle'])->toBe('planned');

    // The production perimeter, sudoers and Nightwatch allowlist all stay
    // closed to tits-guru.
    expect(File::get(base_path('infrastructure/config/sudoers/rateguru-deploy')))
        ->not->toContain('deploy-rateguru-tits-guru');
    expect(File::get(base_path('infrastructure/config/sudoers/rateguru-nightwatch-deployment')))
        ->not->toContain('deploy-rateguru-tits-guru');
    expect(File::get(base_path('infrastructure/scripts/common')))
        ->toContain("staging-main) printf 'rateguru-staging-nightwatch\\n' ;;");
});

it('manages no unrelated project on the same host', function () {
    foreach ([
        'infrastructure/scripts/prepare-host',
        'infrastructure/scripts/install-target-prerequisites',
        'infrastructure/scripts/install-target-database',
        'infrastructure/scripts/record-nightwatch-deployment',
        '.github/actions/prepare-rateguru-host/action.yml',
    ] as $path) {
        $source = File::get(base_path($path));

        foreach (['CatalogHub', 'cataloghub', 'Polymarket', 'polymarket'] as $foreign) {
            expect($source)->not->toContain($foreign);
        }

        // No sweeping operation over a shared directory: every path acted on
        // is resolved from the registry or from a committed RateGuru vhost.
        expect($source)->not->toMatch('#rm\s+-rf\s+/(etc|home|var|opt)(/\S*)?\s*$#m');
        expect($source)->not->toMatch('#for\s+\w+\s+in\s+/etc/nginx/sites-enabled/\*#');
        expect($source)->not->toMatch('#psql.*FROM\s+pg_database\s*;#');
    }
});

it('does not weaken the Phase 5 or Phase 7.1 contracts', function () {
    // Every Phase 5 primitive still exists and still owns what it owned.
    foreach ([
        'bootstrap-host',
        'bootstrap-host-preflight',
        'install-bootstrap-runtime',
        'install-bootstrap-host-layout',
        'install-bootstrap-services',
        'install-target-operations',
        'install-target-perimeter',
        'install-public-storage-access',
    ] as $script) {
        expect(File::exists(base_path('infrastructure/scripts/'.$script)))->toBeTrue();
    }

    // bootstrap-host keeps its own three slices and its own preflight; nothing
    // in Phase 7.2 reached into it.
    $bootstrapHost = File::get(base_path('infrastructure/scripts/bootstrap-host'));
    expect($bootstrapHost)->toContain('SLICE_IDS=(5.2 5.3 5.4)');
    expect($bootstrapHost)->not->toContain('prepare-host');
    expect($bootstrapHost)->not->toContain('install-target-database');
    expect($bootstrapHost)->not->toContain('install-target-prerequisites');

    // And the deploy transport is exactly what Phase 4 established.
    expect(File::get(base_path('.github/actions/deploy-rateguru/action.yml')))
        ->toContain('rateguru-deploy');
});
