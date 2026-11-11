<?php

use App\Actions\Games\ApplyGameTrafficAction;
use App\Actions\Games\ChangeGameStatusAction;
use App\Actions\Games\ChangeGameVersionAction;
use App\Actions\Games\PublishGameVersionAction;
use App\Actions\Games\SaveContentSetAction;
use App\Data\Games\ContentSetDraft;
use App\Enums\GameAudience;
use App\Enums\GameStatus;
use App\Enums\GameVersionStatus;
use App\Exceptions\Games\CannotManageGameException;
use App\Models\GameAssignment;
use App\Models\GameContentSet;
use App\Models\GameRoundOption;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\GameVersionPost;
use App\Models\Post;
use App\Queries\Games\GameTrafficQuery;

function publish(array $draft, ?int $takeOver = null): GameVersion
{
    return app(PublishGameVersionAction::class)->handle($draft['admin'], $draft['game'], $draft['set']->id, $draft['flow']->id, $takeOver);
}

it('freezes the set, the crowd\'s votes and the flow into a numbered version without traffic', function () {
    $draft = publishableGameDraft(3);

    $version = publish($draft);

    expect($version->number)->toBe(1)
        ->and($version->status)->toBe(GameVersionStatus::Active)
        ->and($version->round_count)->toBe(3)
        ->and($version->max_score)->toBe(3)
        ->and($version->read()->round(0)->option(0)->upvotes)->toBe(900)
        ->and($version->read()->round(0)->answer)->toBe(0)
        ->and(GameVersionPost::query()->where('version_id', $version->id)->count())->toBe(6)
        ->and($draft['game']->fresh()->first_published_at)->not->toBeNull()
        ->and(GameTrafficPeriod::query()->exists())->toBeFalse();
});

it('keeps a published version as it was when the site\'s votes and the draft change', function () {
    $draft = publishableGameDraft(1);
    $version = publish($draft);

    $post = GameRoundOption::query()->where('position', 0)->first()->post;
    $post->forceFill(['upvotes_count' => 1, 'downvotes_count' => 999])->save();

    $data = ContentSetDraft::of(GameContentSet::query()->with(['rounds.options', 'profiles'])->find($draft['set']->id));
    $rounds = $data->rounds;
    $rounds[0]['question'] = 'Changed after publishing';
    app(SaveContentSetAction::class)->handle($draft['admin'], $draft['set'], new ContentSetDraft($data->scoring, $rounds, $data->profiles), $draft['set']->fresh()->lock_version);

    $fresh = GameVersion::query()->find($version->id);

    expect($fresh->read()->round(0)->question->english())->toBe('Which one would you rather see in your feed?')
        ->and($fresh->read()->round(0)->option(0)->upvotes)->toBe(900)
        ->and($fresh->read()->round(0)->answer)->toBe(0);
});

it('never lets a published version change but its status', function () {
    $version = publish(publishableGameDraft(1));

    $version->forceFill(['snapshot' => ['schema' => 1]])->save();
})->throws(LogicException::class, 'A published game version is immutable; only its status changes.');

it('numbers versions after the last ever published, never reusing one', function () {
    $draft = publishableGameDraft(1);

    expect(publish($draft)->number)->toBe(1)
        ->and(publish($draft)->number)->toBe(2);

    GameVersion::query()->where('number', 2)->delete();

    expect(publish($draft)->number)->toBe(3);
});

it('refuses to publish what is not ready, saying why', function () {
    $draft = publishableGameDraft(1);
    GameRoundOption::query()->where('position', 1)->first()->post->forceFill(['status' => 'hidden'])->save();

    publish($draft);
})->throws(CannotManageGameException::class, 'This version can’t be published yet: 1 of 1 rounds need attention in Content.');

it('gives the new version another version\'s traffic when asked', function () {
    $draft = publishableGameDraft(1);
    $v1 = publish($draft);
    $v2 = publish($draft);
    app(ApplyGameTrafficAction::class)->handle($draft['admin'], $draft['game'], GameAudience::Anonymous, [$v1->id => 80, $v2->id => 20]);

    $v3 = publish($draft, takeOver: $v1->id);

    $current = GameTrafficPeriod::query()->where('audience', 'anonymous')->whereNull('ended_at')->sole();

    expect($current->shares())->toBe([$v2->id => 20, $v3->id => 80]);
});

it('updates a period nobody was assigned in, and starts a new one otherwise', function () {
    $draft = publishableGameDraft(1);
    $v1 = publish($draft);
    $v2 = publish($draft);
    $apply = fn (array $shares) => app(ApplyGameTrafficAction::class)->handle($draft['admin'], $draft['game'], GameAudience::Anonymous, $shares);

    expect($apply([$v1->id => 100]))->toBeTrue();
    expect($apply([$v1->id => 50, $v2->id => 50]))->toBeFalse()
        ->and(GameTrafficPeriod::query()->count())->toBe(1);

    $period = GameTrafficPeriod::query()->sole();
    GameAssignment::query()->create(['game_id' => $draft['game']->id, 'audience' => 'anonymous', 'visitor_hash' => str_repeat('a', 64), 'active_key' => 'v:a', 'version_id' => $v1->id, 'period_id' => $period->id, 'assigned_at' => now(), 'last_seen_at' => now()]);

    expect($apply([$v1->id => 80, $v2->id => 20]))->toBeTrue()
        ->and($period->fresh()->ended_at)->not->toBeNull()
        ->and(GameTrafficPeriod::query()->whereNull('ended_at')->sole()->shares())->toBe([$v1->id => 80, $v2->id => 20]);
});

it('applies only whole shares of active versions adding up to 100', function () {
    $draft = publishableGameDraft(1);
    $v1 = publish($draft);
    $retired = publish($draft);
    app(ChangeGameVersionAction::class)->retire($draft['admin'], $retired);

    expect(fn () => app(ApplyGameTrafficAction::class)->handle($draft['admin'], $draft['game'], GameAudience::Anonymous, [$v1->id => 90]))
        ->toThrow(CannotManageGameException::class, 'Shares add up to 90%. Make them 100% to apply.');
    expect(fn () => app(ApplyGameTrafficAction::class)->handle($draft['admin'], $draft['game'], GameAudience::Anonymous, [$v1->id => 50, $retired->id => 50]))
        ->toThrow(CannotManageGameException::class, 'Only active versions of this game can have a share.');
});

it('archives a version only once it has no share', function () {
    $draft = publishableGameDraft(1);
    $version = publish($draft);
    app(ApplyGameTrafficAction::class)->handle($draft['admin'], $draft['game'], GameAudience::Authenticated, [$version->id => 100]);

    expect(fn () => app(ChangeGameVersionAction::class)->retire($draft['admin'], $version))
        ->toThrow(CannotManageGameException::class, 'This version still has a share of traffic. Remove it in Traffic first.');

    app(ApplyGameTrafficAction::class)->handle($draft['admin'], $draft['game'], GameAudience::Authenticated, []);
    app(ChangeGameVersionAction::class)->retire($draft['admin'], $version);

    expect($version->fresh()->status)->toBe(GameVersionStatus::Retired);
});

it('keeps a live game from losing all its traffic', function () {
    $draft = publishableGameDraft(1);
    $version = publish($draft);
    app(ApplyGameTrafficAction::class)->handle($draft['admin'], $draft['game'], GameAudience::Anonymous, [$version->id => 100]);
    app(ChangeGameStatusAction::class)->handle($draft['admin'], $draft['game']->fresh(), GameStatus::Live);

    app(ApplyGameTrafficAction::class)->handle($draft['admin'], $draft['game']->fresh(), GameAudience::Anonymous, []);
})->throws(CannotManageGameException::class, 'A live game needs traffic in at least one audience. Pause the game first.');

it('counts a version unplayable once a post it shows leaves the site', function () {
    $draft = publishableGameDraft(1);
    $version = publish($draft);
    Post::query()->find($version->read()->postIds()[0])->delete();

    expect(app(GameTrafficQuery::class)->unplayableVersionIds([$version->id]))->toBe([$version->id]);
});
