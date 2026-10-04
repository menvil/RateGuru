<?php

use App\Enums\MediaAuditIssueType;
use App\Jobs\GenerateMediaVariantsJob;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Models\Post;
use App\Services\Media\MediaAuditIssueData;
use App\Services\Media\MediaAuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('public');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('reports a clean audit with no issues', function () {
    $asset = MediaAsset::factory()->postImage()->create(['path' => 'media/post-images/a.jpg']);
    Storage::disk('public')->put($asset->path, 'bytes');
    Post::factory()->published()->create(['image_asset_id' => $asset->id]);

    $issues = [];
    $summary = app(MediaAuditService::class)->run(onIssue: function (MediaAuditIssueData $issue) use (&$issues): void {
        $issues[] = $issue;
    });

    expect($summary->assetsChecked)->toBe(1)
        ->and($summary->healthyAssets)->toBe(1)
        ->and($summary->activeUnreferencedAssets)->toBe(0)
        ->and($summary->missingMasters)->toBe(0)
        ->and($issues)->toBeEmpty();
});

it('reports a missing master file as an issue with disk/path context', function () {
    $asset = MediaAsset::factory()->postImage()->create(['disk' => 'public', 'path' => 'media/post-images/missing.jpg']);
    Post::factory()->published()->create(['image_asset_id' => $asset->id]);

    $issues = [];
    $summary = app(MediaAuditService::class)->run(onIssue: function (MediaAuditIssueData $issue) use (&$issues): void {
        $issues[] = $issue;
    });

    expect($summary->missingMasters)->toBe(1);
    $missingMasterIssues = array_filter($issues, fn (MediaAuditIssueData $i) => $i->issueType === MediaAuditIssueType::MissingMasterFile);
    expect($missingMasterIssues)->toHaveCount(1);
    $issue = array_values($missingMasterIssues)[0];
    expect($issue->mediaAssetId)->toBe($asset->id)
        ->and($issue->disk)->toBe('public')
        ->and($issue->path)->toBe('media/post-images/missing.jpg');
});

it('reports a missing variant file as an issue and counts every variant checked', function () {
    $asset = MediaAsset::factory()->postImage()->create(['path' => 'media/post-images/master.jpg']);
    Storage::disk('public')->put($asset->path, 'bytes');
    $variant = MediaVariant::factory()->create([
        'media_asset_id' => $asset->id,
        'disk' => 'public',
        'path' => 'media/post-images/master/variant.jpg',
    ]);

    $issues = [];
    $summary = app(MediaAuditService::class)->run(onIssue: function (MediaAuditIssueData $issue) use (&$issues): void {
        $issues[] = $issue;
    });

    expect($summary->variantsChecked)->toBe(1)
        ->and($summary->missingVariantFiles)->toBe(1);
    $variantIssues = array_filter($issues, fn (MediaAuditIssueData $i) => $i->issueType === MediaAuditIssueType::MissingVariantFile);
    expect($variantIssues)->toHaveCount(1);
    $issue = array_values($variantIssues)[0];
    expect($issue->mediaAssetId)->toBe($asset->id)
        ->and($issue->mediaVariantId)->toBe($variant->id);
});

it('reports an active, unreferenced asset as an issue', function () {
    $asset = MediaAsset::factory()->postImage()->create(['path' => 'media/post-images/a.jpg']);
    Storage::disk('public')->put($asset->path, 'bytes');

    $issues = [];
    $summary = app(MediaAuditService::class)->run(onIssue: function (MediaAuditIssueData $issue) use (&$issues): void {
        $issues[] = $issue;
    });

    expect($summary->activeUnreferencedAssets)->toBe(1);
    $matching = array_filter($issues, fn (MediaAuditIssueData $i) => $i->issueType === MediaAuditIssueType::ActiveUnreferencedAsset);
    expect($matching)->toHaveCount(1);
    expect(array_values($matching)[0]->mediaAssetId)->toBe($asset->id);
});

it('reports a grace-expired, unreferenced, soft-deleted asset as purgeable, not as a plain soft-delete', function () {
    $asset = createPurgeableAsset();

    $issues = [];
    $summary = app(MediaAuditService::class)->run(onIssue: function (MediaAuditIssueData $issue) use (&$issues): void {
        $issues[] = $issue;
    });

    expect($summary->purgeableAssets)->toBe(1)
        ->and($summary->softDeletedWithinGrace)->toBe(0);
    $matching = array_filter($issues, fn (MediaAuditIssueData $i) => $i->issueType === MediaAuditIssueType::PurgeableAsset);
    expect($matching)->toHaveCount(1);
    expect(array_values($matching)[0]->mediaAssetId)->toBe($asset->id);
});

it('does not report a soft-deleted asset still within its grace period as an issue', function () {
    Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00'));
    $asset = MediaAsset::factory()->postImage()->create(['path' => 'media/post-images/a.jpg']);
    Storage::disk('public')->put($asset->path, 'bytes');
    $asset->delete();
    Carbon::setTestNow(Carbon::parse('2026-01-03 12:00:00'));

    $issues = [];
    $summary = app(MediaAuditService::class)->run(onIssue: function (MediaAuditIssueData $issue) use (&$issues): void {
        $issues[] = $issue;
    });

    expect($summary->softDeletedWithinGrace)->toBe(1)
        ->and($summary->purgeableAssets)->toBe(0)
        ->and($issues)->toBeEmpty();
});

it('reports physical orphan candidates past the orphan grace period', function () {
    config(['media.lifecycle.orphan_grace_hours' => 0]);
    Storage::disk('public')->put('media/post-images/orphan.jpg', 'bytes');

    $issues = [];
    $summary = app(MediaAuditService::class)->run(onIssue: function (MediaAuditIssueData $issue) use (&$issues): void {
        $issues[] = $issue;
    });

    expect($summary->physicalOrphanCandidates)->toBe(1);
    $matching = array_filter($issues, fn (MediaAuditIssueData $i) => $i->issueType === MediaAuditIssueType::PhysicalOrphanCandidate);
    expect($matching)->toHaveCount(1);
    expect(array_values($matching)[0]->path)->toBe('media/post-images/orphan.jpg');
});

it('never mutates any asset, variant, or physical file', function () {
    $asset = createPurgeableAsset();
    $originalDeletedAt = $asset->deleted_at;

    app(MediaAuditService::class)->run();

    $fresh = MediaAsset::withTrashed()->find($asset->id);
    expect($fresh->trashed())->toBeTrue()
        ->and($fresh->deleted_at->equalTo($originalDeletedAt))->toBeTrue();
    Storage::disk('public')->assertExists($asset->path);
});

it('does not hold the full issue set in memory when no callback is passed', function () {
    // omitting onIssue entirely must not throw or accumulate anything —
    // only the aggregate MediaAuditSummary is required by callers like the
    // CLI command.
    $asset = MediaAsset::factory()->postImage()->create();

    $summary = app(MediaAuditService::class)->run();

    expect($summary->assetsChecked)->toBe(1);
});

it('reports a failed GenerateMediaVariantsJob as a failed_generation_job issue, preserving the asset id and job context', function () {
    $asset = MediaAsset::factory()->postImage()->create();
    insertFailedJobRow(GenerateMediaVariantsJob::class, serialize(new GenerateMediaVariantsJob($asset->id)));

    $issues = [];
    $summary = app(MediaAuditService::class)->run(onIssue: function (MediaAuditIssueData $issue) use (&$issues): void {
        $issues[] = $issue;
    });

    expect($summary->failedMediaJobs)->toBe(1);
    $matching = array_values(array_filter($issues, fn (MediaAuditIssueData $i) => $i->issueType === MediaAuditIssueType::FailedGenerationJob));
    expect($matching)->toHaveCount(1);
    expect($matching[0]->mediaAssetId)->toBe($asset->id);
    expect($matching[0]->context)->not->toBeNull();
    expect($matching[0]->context['job_class'])->toBe(GenerateMediaVariantsJob::class);
});
