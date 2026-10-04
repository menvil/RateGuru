<?php

use App\Enums\MediaAuditRunStatus;
use App\Enums\MediaHealthStatus;
use App\Models\MediaAuditRun;
use App\Services\Media\MediaHealthResolver;
use Tests\TestCase;

uses(TestCase::class);

it('resolves unknown when there is no completed run at all', function () {
    expect(app(MediaHealthResolver::class)->resolve(null))->toBe(MediaHealthStatus::Unknown);
});

it('resolves unknown for a run that has not completed', function () {
    $run = MediaAuditRun::factory()->running()->make();

    expect(app(MediaHealthResolver::class)->resolve($run))->toBe(MediaHealthStatus::Unknown);
});

it('resolves unknown for a failed run', function () {
    $run = MediaAuditRun::factory()->failed()->make();

    expect(app(MediaHealthResolver::class)->resolve($run))->toBe(MediaHealthStatus::Unknown);
});

it('resolves critical when a completed run has any missing masters, regardless of other counters', function () {
    $run = MediaAuditRun::factory()->make([
        'status' => MediaAuditRunStatus::Completed,
        'missing_masters' => 1,
    ]);

    expect(app(MediaHealthResolver::class)->resolve($run))->toBe(MediaHealthStatus::Critical);
});

it('resolves warning when there are no missing masters but a warning-severity counter is nonzero', function () {
    foreach (['missing_variant_files', 'active_unreferenced_assets', 'physical_orphan_candidates', 'failed_media_jobs'] as $counter) {
        $run = MediaAuditRun::factory()->make([
            'status' => MediaAuditRunStatus::Completed,
            'missing_masters' => 0,
            $counter => 1,
        ]);

        expect(app(MediaHealthResolver::class)->resolve($run))->toBe(MediaHealthStatus::Warning);
    }
});

it('resolves healthy for a clean completed run even with purgeable assets present', function () {
    $run = MediaAuditRun::factory()->make([
        'status' => MediaAuditRunStatus::Completed,
        'missing_masters' => 0,
        'missing_variant_files' => 0,
        'active_unreferenced_assets' => 0,
        'physical_orphan_candidates' => 0,
        'failed_media_jobs' => 0,
        'purgeable_assets' => 5,
    ]);

    expect(app(MediaHealthResolver::class)->resolve($run))->toBe(MediaHealthStatus::Healthy);
});
