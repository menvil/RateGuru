<?php

use App\Enums\GameCrowdState;
use App\Enums\GameRoundMode;
use App\Enums\MediaVariantName;
use App\Enums\PostStatus;
use App\Models\Game;
use App\Models\GameRoundOption;
use App\Models\MediaAsset;
use App\Models\MediaVariant;
use App\Queries\Games\GameDraftQuery;
use App\Support\Games\Drafts\ContentSetReport;
use App\Support\Games\Publishing\GamePublishReadiness;
use App\Support\Games\Publishing\VersionSnapshotBuilder;

function draftReport(array $draft): ContentSetReport
{
    return ContentSetReport::of(app(GameDraftQuery::class)->contentSet($draft['game']->id, $draft['set']->id));
}

it('finds a prepared draft publishable and freezes the crowd as it stands', function () {
    $draft = publishableGameDraft(3);
    $report = draftReport($draft);
    $flow = app(GameDraftQuery::class)->flow($draft['game']->id, $draft['flow']->id);
    $readiness = GamePublishReadiness::of($draft['game'], $report, $flow, Game::query()->pluck('id')->all());

    expect($report->isPublishable())->toBeTrue()
        ->and($readiness->isReady())->toBeTrue()
        ->and($readiness->lines[2]->text)->toBe('Flow is valid: Landing → Game → Full Result.');

    $snapshot = VersionSnapshotBuilder::build($draft['game'], $report, $flow);

    expect($snapshot->roundCount())->toBe(3)
        ->and($snapshot->maxScore())->toBe(3)
        ->and($snapshot->round(0)->answer)->toBe(0)
        ->and($snapshot->round(0)->option(0)->upvotes)->toBe(900)
        ->and($snapshot->round(0)->option(1)->displayShare())->toBe(60.0)
        ->and(array_map(fn ($profile) => $profile->key, $snapshot->profiles))->toBe(['wild-card', 'independent', 'sharp-eye', 'oracle'])
        ->and(count($snapshot->steps))->toBe(3)
        ->and($snapshot->postIds())->toHaveCount(6);
});

it('reads the site as it is now: a hidden post blocks the round', function () {
    $draft = publishableGameDraft(1);
    GameRoundOption::query()->where('position', 0)->first()->post->forceFill(['status' => PostStatus::Hidden])->save();

    $report = draftReport($draft);
    $check = array_values($report->rounds)[0];

    expect($check->issues)->toBe(['Option A: the post is no longer public'])
        ->and($check->crowd->state)->toBe(GameCrowdState::Ineligible)
        ->and($report->isPublishable())->toBeFalse();
});

it('asks for site votes up to the set\'s threshold and gap', function () {
    $draft = publishableGameDraft(1);
    $draft['set']->forceFill(['crowd_min_votes' => 2_000])->save();

    expect(array_values(draftReport($draft)->rounds)[0]->issues)->toBe(['Option A: only 1000 of 2000 site votes']);

    $draft['set']->forceFill(['crowd_min_votes' => 100, 'crowd_min_gap' => 40])->save();

    expect(array_values(draftReport($draft)->rounds)[0]->issues)
        ->toBe(['Too close to call: 90.0% vs 60.0% upvotes, the crowd pick needs a gap of 40 points']);
});

it('sees a picture without its web sizes as still processing', function () {
    $draft = publishableGameDraft(1);
    $option = GameRoundOption::query()->where('position', 1)->first();
    $option->post->imageAsset->variants()->delete();

    expect(array_values(draftReport($draft)->rounds)[0]->issues)->toBe(['Option B image still processing']);
});

it('decides an uploaded round by its correct answer', function () {
    $draft = publishableGameDraft(1);
    $option = GameRoundOption::query()->where('position', 1)->first();
    $asset = MediaAsset::factory()->postImage()->create();
    MediaVariant::factory()->named(MediaVariantName::PostFeed640)->create(['media_asset_id' => $asset->id]);
    $option->forceFill(['source' => 'upload', 'post_id' => null, 'asset_id' => $asset->id])->save();

    $check = array_values(draftReport($draft)->rounds)[0];

    expect($check->mode)->toBe(GameRoundMode::Objective)
        ->and($check->issues)->toBe(['Correct answer not set']);

    $option->forceFill(['is_correct' => true])->save();

    expect(array_values(draftReport($draft)->rounds)[0]->answer)->toBe(1);
});

it('says what keeps a draft from being published', function () {
    $draft = publishableGameDraft(2);
    $draft['set']->profiles()->where('key', 'oracle')->delete();

    $report = draftReport($draft);
    $readiness = GamePublishReadiness::of($draft['game'], $report, app(GameDraftQuery::class)->flow($draft['game']->id, $draft['flow']->id), []);

    expect($readiness->isReady())->toBeFalse()
        ->and($readiness->problems())->toBe(["Result profiles don't cover every result from 0 to 2 matches. Fix them in Content → Results."]);
});

it('refuses to freeze a set that is not publishable', function () {
    $draft = publishableGameDraft(1);
    $draft['set']->profiles()->delete();

    VersionSnapshotBuilder::build($draft['game'], draftReport($draft), $draft['flow']);
})->throws(LogicException::class);
