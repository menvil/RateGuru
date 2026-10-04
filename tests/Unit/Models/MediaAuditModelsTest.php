<?php

use App\Enums\MediaAuditIssueSeverity;
use App\Enums\MediaAuditIssueType;
use App\Enums\MediaAuditRunStatus;
use App\Models\MediaAsset;
use App\Models\MediaAuditIssue;
use App\Models\MediaAuditRun;
use App\Models\MediaVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('casts MediaAuditRun status and timestamps', function () {
    $run = MediaAuditRun::factory()->create();

    expect($run->status)->toBeInstanceOf(MediaAuditRunStatus::class)
        ->and($run->started_at)->toBeInstanceOf(Carbon::class)
        ->and($run->completed_at)->toBeInstanceOf(Carbon::class);
});

it('has many issues', function () {
    $run = MediaAuditRun::factory()->create();
    MediaAuditIssue::factory()->create(['media_audit_run_id' => $run->id]);
    MediaAuditIssue::factory()->create(['media_audit_run_id' => $run->id]);

    expect($run->issues)->toHaveCount(2);
});

it('deleting a run cascade-deletes its issues', function () {
    $run = MediaAuditRun::factory()->create();
    $issue = MediaAuditIssue::factory()->create(['media_audit_run_id' => $run->id]);

    $run->delete();

    expect(MediaAuditIssue::find($issue->id))->toBeNull();
});

it('casts MediaAuditIssue issue_type, severity, and context', function () {
    $issue = MediaAuditIssue::factory()->ofType(MediaAuditIssueType::MissingMasterFile)->create([
        'context' => ['foo' => 'bar'],
    ]);

    expect($issue->issue_type)->toBe(MediaAuditIssueType::MissingMasterFile)
        ->and($issue->severity)->toBe(MediaAuditIssueSeverity::Critical)
        ->and($issue->context)->toBe(['foo' => 'bar']);
});

it('belongs to a run', function () {
    $run = MediaAuditRun::factory()->create();
    $issue = MediaAuditIssue::factory()->create(['media_audit_run_id' => $run->id]);

    expect($issue->run)->toBeInstanceOf(MediaAuditRun::class)
        ->and($issue->run->id)->toBe($run->id);
});

it('resolves its asset and variant relations for convenience, without a real foreign key constraint', function () {
    $asset = MediaAsset::factory()->create();
    $variant = MediaVariant::factory()->create(['media_asset_id' => $asset->id]);
    $issue = MediaAuditIssue::factory()->create([
        'media_asset_id' => $asset->id,
        'media_variant_id' => $variant->id,
    ]);

    expect($issue->asset->id)->toBe($asset->id)
        ->and($issue->variant->id)->toBe($variant->id);
});

it('resolves a null asset/variant relation when the referenced row no longer exists, since there is no hard foreign key', function () {
    $issue = MediaAuditIssue::factory()->create([
        'media_asset_id' => 999_999,
        'media_variant_id' => 999_999,
    ]);

    expect($issue->asset)->toBeNull()
        ->and($issue->variant)->toBeNull();
});

it('every issue type maps to exactly one severity', function () {
    // Keyed by every case's own value, not just a subset — a newly added
    // enum case has no entry here and fails toHaveKey() below until this
    // map is explicitly updated to cover it, rather than silently passing
    // because the loop just never visited it.
    $expectedSeverityByType = [
        MediaAuditIssueType::MissingMasterFile->value => MediaAuditIssueSeverity::Critical,
        MediaAuditIssueType::MissingVariantFile->value => MediaAuditIssueSeverity::Warning,
        MediaAuditIssueType::ActiveUnreferencedAsset->value => MediaAuditIssueSeverity::Warning,
        MediaAuditIssueType::PhysicalOrphanCandidate->value => MediaAuditIssueSeverity::Warning,
        MediaAuditIssueType::FailedGenerationJob->value => MediaAuditIssueSeverity::Warning,
        MediaAuditIssueType::PurgeableAsset->value => MediaAuditIssueSeverity::Info,
    ];

    foreach (MediaAuditIssueType::cases() as $case) {
        expect($expectedSeverityByType)->toHaveKey($case->value);
        expect($case->severity())->toBe($expectedSeverityByType[$case->value]);
    }

    expect(MediaAuditIssueType::cases())->toHaveCount(count($expectedSeverityByType));
});
