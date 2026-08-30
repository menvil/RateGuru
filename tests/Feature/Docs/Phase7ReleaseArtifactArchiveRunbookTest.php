<?php

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

/**
 * Phase 7.1 documentation is operational, not decorative: after total VPS
 * loss, an operator has to be able to find which release was running, retrieve
 * its exact bytes and prove they are the right ones from this one page.
 *
 * These assert the facts that would silently rot — the bucket, the remote
 * path, the credential names, the script names and the pipeline ordering —
 * against the real files, workflows and scripts they describe.
 */
function releaseArchiveRunbook(): string
{
    return File::get(base_path('infrastructure/runbooks/release-artifact-archive.md'));
}

it('lives inside the existing runbook structure', function () {
    expect(File::exists(base_path('infrastructure/runbooks/release-artifact-archive.md')))->toBeTrue();

    // No parallel docs hierarchy: it sits with the other runbooks and is
    // reachable from the infrastructure index and the roadmap.
    expect(File::get(base_path('infrastructure/README.md')))
        ->toContain('runbooks/release-artifact-archive.md');

    expect(File::get(base_path('infrastructure/ROADMAP.md')))
        ->toContain('runbooks/release-artifact-archive.md');
});

it('documents the exact storage identity the code actually uses', function () {
    $runbook = releaseArchiveRunbook();
    $library = File::get(base_path('infrastructure/scripts/release-artifact-common'));

    expect($runbook)
        ->toContain('rateguru-release-artifacts')
        ->toContain('rateguru/artifacts/<release-id>/')
        ->toContain('rateguru-artifacts-b2')
        ->toContain('rateguru-<release-id>.tar.gz')
        ->toContain('rateguru-<release-id>.tar.gz.sha256')
        ->toContain('release.json');

    // Every one of those is the value the shipped code carries, not a value
    // that drifted in prose.
    expect($library)
        ->toContain('RELEASE_ARTIFACT_BUCKET_DEFAULT="rateguru-release-artifacts"')
        ->toContain('RELEASE_ARTIFACT_REMOTE_DEFAULT="rateguru-artifacts-b2"')
        ->toContain('RELEASE_ARTIFACT_PROJECT="rateguru"');

    // The separation from the backup namespace is stated, and true.
    expect($runbook)->toContain('rateguru-database-backups');
    expect(File::get(base_path('infrastructure/scripts/offsite-backup')))
        ->toContain('BUCKET_DEFAULT="rateguru-database-backups"')
        ->not->toContain('rateguru-release-artifacts');
});

it('names scripts that exist, are executable, and are manifested', function () {
    $runbook = releaseArchiveRunbook();
    $manifest = requiredCliManifestNames();

    foreach ([
        'archive-release-artifact',
        'fetch-release-artifact',
        'fetch-verified-rclone',
    ] as $script) {
        expect($runbook)->toContain("infrastructure/scripts/{$script}");
        expect(is_file(base_path("infrastructure/scripts/{$script}")))->toBeTrue("the runbook names {$script}, which does not exist");
        expect(is_executable(base_path("infrastructure/scripts/{$script}")))->toBeTrue("{$script} is documented as runnable but is not executable");
        expect($manifest)->toContain($script);
    }

    expect($runbook)->toContain('infrastructure/scripts/release-artifact-common');
    expect(is_file(base_path('infrastructure/scripts/release-artifact-common')))->toBeTrue();
});

it('documents the credential model with the exact GitHub names the workflow reads', function () {
    $runbook = releaseArchiveRunbook();
    $workflow = File::get(base_path('.github/workflows/deploy-staging.yml'));

    foreach ([
        'B2_ARTIFACT_KEY_ID',
        'B2_ARTIFACT_APPLICATION_KEY',
        'B2_ARTIFACT_BUCKET',
    ] as $name) {
        expect($runbook)->toContain($name);
        expect($workflow)->toContain($name);
    }

    expect($runbook)
        ->toContain('restricted to the')
        ->toContain('0600')
        ->toContain('RUNNER_TEMP')
        ->toContain('always()');

    // And the repository practises it: no credential value is committed
    // anywhere, and a generated rclone configuration cannot be.
    expect($runbook)->not->toMatch('/B2_ARTIFACT_(KEY_ID|APPLICATION_KEY)\s*=\s*\S/');
    expect(File::get(base_path('.gitignore')))->toContain('rclone.conf');
});

it('describes the pipeline ordering the workflow actually enforces', function () {
    $runbook = releaseArchiveRunbook();
    $workflow = Yaml::parse(File::get(base_path('.github/workflows/deploy-staging.yml')));

    expect($runbook)
        ->toContain('resolve  →  build  →  archive  →  deploy  →  observability')
        ->toContain('hard precondition');

    expect(data_get($workflow, 'jobs.archive.needs'))->toBe('build');
    expect(data_get($workflow, 'jobs.deploy.needs'))->toContain('archive');

    // The 3-day GitHub retention the runbook quotes is the real one.
    expect($runbook)->toContain('3 days');
    expect(data_get(
        collect(data_get($workflow, 'jobs.build.steps'))->keyBy('name')->get('Upload immutable release artifact'),
        'with.retention-days',
    ))->toBe(3);
});

it('states the immutability and idempotency contract the script implements', function () {
    $runbook = releaseArchiveRunbook();

    expect($runbook)
        ->toContain('idempotent no-op')
        ->toContain('HARD FAIL')
        ->toContain('No delete. No replace. No mutation.')
        ->toContain('rclone copy --immutable --check-first --checksum');

    expect(File::get(base_path('infrastructure/scripts/archive-release-artifact')))
        ->toContain('--immutable')
        ->toContain('--check-first')
        ->toContain('--checksum');
});

it('gives an operator acceptance procedure that can actually be followed', function () {
    $runbook = releaseArchiveRunbook();

    expect($runbook)
        ->toContain('## Operator acceptance')
        // The retrieval command, with the flags the script really accepts.
        ->toContain('--release <release-id>')
        ->toContain('--destination /tmp/rateguru-recovery')
        ->toContain('--rclone-config')
        ->toContain('sha256sum -c')
        ->toContain('current/release.json');

    // Every flag the acceptance steps use is one the scripts document.
    $fetchUsage = File::get(base_path('infrastructure/scripts/fetch-release-artifact'));

    foreach (['--release', '--destination', '--rclone-config', '--rclone-bin', '--report'] as $flag) {
        expect($fetchUsage)->toContain($flag);
    }
});

it('records what Phase 7.1 deliberately does not do', function () {
    $runbook = releaseArchiveRunbook();

    expect($runbook)
        ->toContain('## What this does not do')
        ->toContain('retention, deletion or garbage collection')
        ->toContain('Phase 7.2')
        ->toContain('rollback-staging.yml` already exists and is not Phase 7 work');

    // The roadmap says the same thing, so the two cannot drift apart.
    expect(File::get(base_path('infrastructure/ROADMAP.md')))
        ->toContain('**Rollback already exists and is not Phase 7 work.**')
        ->toContain('7.1 Durable immutable release artifact archive — implemented');
});

it('keeps the roadmap describing all nine Phase 7 slices', function () {
    $roadmap = File::get(base_path('infrastructure/ROADMAP.md'));

    foreach ([
        '7.1 Durable immutable release artifact archive',
        '7.2 Backup ↔ exact release / recovery-point mapping',
        '7.3 Restore Target Data on an existing host',
        '7.4 GitHub Restore Target Data operator workflow',
        '7.5 Repair Target',
        '7.6 Clean-host recovery orchestration / clean-host drill',
        '7.7 Application-level recovery verification',
        '7.8 GitHub Recover Host workflow / disposable-host rehearsal',
        '7.9 Full timed DR drill, RPO/RTO and final production recovery',
    ] as $slice) {
        expect($roadmap)->toContain($slice);
    }

    // The rehearsal gate that used to be numbered 7.5 moved with the
    // renumbering, rather than being left pointing at "Repair Target".
    expect($roadmap)
        ->toContain('**Phase 7.6 + 7.9 — "Can we recover after complete server/data loss?"**');

    // 7.1 landed, but the phase is not the current one: Phase 6 stays the
    // single current phase until its manual slices close, and the roadmap's
    // one-current-phase invariant is preserved.
    expect($roadmap)
        ->toMatch('/^\|\s*7\s*\|[^|]+\|\s*⏳ planned \(7\.1 implemented\)\s*\|$/m')
        ->toContain('## 7. Disaster recovery and release rehearsal — planned (7.1 implemented)')
        ->and(substr_count($roadmap, '🚧 current'))->toBe(1);
});
