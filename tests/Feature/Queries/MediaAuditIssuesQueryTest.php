<?php

use App\Enums\MediaAuditIssueSeverity;
use App\Enums\MediaAuditIssueType;
use App\Models\MediaAsset;
use App\Models\MediaAuditIssue;
use App\Models\MediaAuditRun;
use App\Queries\Media\MediaAuditIssuesQuery;

/**
 * The issues of one audit, as Media diagnostics lists them: that audit's
 * only, narrowed and searched, the most severe first, in an order that keeps
 * every issue in its place across pages.
 */
function mediaIssuesPage(?MediaAuditRun $run, ?MediaAuditIssueSeverity $severity = null, ?MediaAuditIssueType $type = null, string $search = '', int $page = 1): array
{
    return app(MediaAuditIssuesQuery::class)->paginate($run, $severity, $type, $search, $page)->pluck('id')->all();
}

it('lists the issues of the audit it is given, and none without one', function () {
    $older = MediaAuditRun::factory()->create();
    $latest = MediaAuditRun::factory()->create();
    MediaAuditIssue::factory()->for($older, 'run')->create();
    $issue = MediaAuditIssue::factory()->for($latest, 'run')->create();

    expect(mediaIssuesPage($latest))->toBe([$issue->id])
        ->and(mediaIssuesPage(null))->toBe([]);
});

it('narrows to a severity and a type, counts each, and searches by asset id or path', function () {
    $run = MediaAuditRun::factory()->create();
    $missing = MediaAuditIssue::factory()->for($run, 'run')->ofType(MediaAuditIssueType::MissingMasterFile)->create(['path' => 'masters/2026/09/8f3c2a91e1.jpg']);
    $purgeable = MediaAuditIssue::factory()->for($run, 'run')->ofType(MediaAuditIssueType::PurgeableAsset)->create(['path' => 'masters/2026/08/5c7e19aa02_100%.jpg', 'media_asset_id' => MediaAsset::factory()->create()->id]);

    expect(mediaIssuesPage($run, MediaAuditIssueType::MissingMasterFile->severity()))->toContain($missing->id)
        ->and(mediaIssuesPage($run, type: MediaAuditIssueType::PurgeableAsset))->toBe([$purgeable->id])
        ->and(mediaIssuesPage($run, search: '8F3C2A'))->toBe([$missing->id])
        ->and(mediaIssuesPage($run, search: '_100%'))->toBe([$purgeable->id])
        ->and(mediaIssuesPage($run, search: '#'.$purgeable->media_asset_id))->toBe([$purgeable->id])
        ->and(app(MediaAuditIssuesQuery::class)->counts($run)['type'][MediaAuditIssueType::PurgeableAsset->value])->toBe(1);
});

it('lists the most severe first, then the newest', function () {
    $run = MediaAuditRun::factory()->create();
    $info = MediaAuditIssue::factory()->for($run, 'run')->ofType(MediaAuditIssueType::PurgeableAsset)->create(['created_at' => now()->addMinute()]);
    $olderCritical = MediaAuditIssue::factory()->for($run, 'run')->ofType(MediaAuditIssueType::MissingMasterFile)->create(['created_at' => now()->subMinute()]);
    $warning = MediaAuditIssue::factory()->for($run, 'run')->ofType(MediaAuditIssueType::MissingVariantFile)->create();
    $critical = MediaAuditIssue::factory()->for($run, 'run')->ofType(MediaAuditIssueType::MissingMasterFile)->create();

    expect(mediaIssuesPage($run))->toBe([$critical->id, $olderCritical->id, $warning->id, $info->id]);
});

it('keeps issues recorded together in their place across pages', function () {
    $run = MediaAuditRun::factory()->create();
    $issues = MediaAuditIssue::factory()->count(MediaAuditIssuesQuery::PER_PAGE + 5)->for($run, 'run')->create(['created_at' => now()->subHour()->startOfSecond()]);
    $expected = $issues->pluck('id')->sortDesc()->values()->all();

    $first = mediaIssuesPage($run);
    $second = mediaIssuesPage($run, page: 2);

    expect(array_intersect($first, $second))->toBe([])
        ->and([...$first, ...$second])->toBe($expected);
});
