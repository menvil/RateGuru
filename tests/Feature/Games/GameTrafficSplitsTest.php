<?php

use App\Actions\Games\ApplyGameTrafficAction;
use App\Actions\Games\EndGameTrafficSplitAction;
use App\Actions\Games\PublishGameVersionAction;
use App\Actions\Games\StartGameAttemptAction;
use App\Enums\GameAudience;
use App\Enums\GameVersionStatus;
use App\Exceptions\Games\CannotManageGameException;
use App\Livewire\Games\PlayGame;
use App\Models\GameAssignment;
use App\Models\GameTrafficPeriod;
use App\Models\GameVersion;
use App\Models\TrafficCampaign;
use App\Models\TrafficSource;
use App\Models\TrafficVisit;
use App\Models\User;
use App\Queries\Games\GameTrafficQuery;
use App\Support\Games\Play\GameVisitor;
use App\Support\Games\Traffic\TrafficScope;
use App\Support\Traffic\Capture\ArrivalToken;
use App\Support\Traffic\Capture\TrafficArrival;
use Livewire\Livewire;

/**
 * Splits for a source (docs/architecture/games.md): a game's anonymous
 * visitors a paid click brought get the split for the click's campaign, else
 * for its source, else the general one; a signed-in player and every unpaid
 * arrival get the general one. A visitor keeps their version while it has a
 * share in any open period of the game — whichever source they come back
 * from — and only the visitors a split sent are in its period, so a version
 * taken out of a split sends only them elsewhere. Without splits nothing
 * changes.
 *
 * The general split gives V1 every visitor; each split gives one version all
 * of its clicks, so which version a visitor gets says which split decided.
 */
beforeEach(function () {
    recordTraffic();
    $this->draft = publishableGameDraft(2);
    $this->v1 = liveGame($this->draft);
    $publish = fn (): GameVersion => app(PublishGameVersionAction::class)->handle($this->draft['admin'], $this->draft['game'], $this->draft['set']->id, $this->draft['flow']->id);
    $this->v2 = $publish();
    $this->v3 = $publish();
    $this->game = $this->draft['game']->fresh();
    $this->exo = TrafficSource::factory()->create(['name' => 'ExoClick tests', 'link_key' => 'exo']);
    $this->pop = TrafficSource::factory()->create(['name' => 'PopAds main', 'link_key' => 'pop']);
    $this->campaign = TrafficCampaign::factory()->create(['source_id' => $this->pop->id, 'external_id' => '881377']);
    $this->otherCampaign = TrafficCampaign::factory()->create(['source_id' => $this->pop->id, 'external_id' => '881204']);
});

/** A paid click of the source — of one of its campaigns, if given — that brought the browser holding the token to the game. */
function paidClickTo(object $test, TrafficSource $source, ?TrafficCampaign $campaign, string $token): TrafficVisit
{
    return TrafficVisit::factory()->create([
        'source_id' => $source->id,
        'campaign_id' => $campaign?->id,
        'visitor_hash' => browserHashOf($token),
        'game_id' => $test->game->id,
    ]);
}

/** The version the browser holding the token is given once the game page it arrived at by the click runs. */
function versionAfterClick(object $test, TrafficSource $source, ?TrafficCampaign $campaign, string $token): int
{
    $page = arrivedAt($test->game->slug, ['visit' => paidClickTo($test, $source, $campaign, $token)->id], $token);
    $drawn = $page->get('versionId');
    $page->call('init');

    expect($page->get('versionId'))->toBe($drawn);

    return (int) GameAssignment::query()->where('visitor_hash', browserHashOf($token))->whereNotNull('active_key')->sole()->version_id;
}

it('gives a paid click the general split while the game has no split for a source', function () {
    expect(versionAfterClick($this, $this->exo, null, 'browserAbrowserAbrowserAbrowserAbrowserA'))->toBe($this->v1->id)
        ->and(GameTrafficPeriod::query()->whereNotNull('source_id')->exists())->toBeFalse();
});

it('gives a paid click its source’s split, and a campaign with a split of its own that one', function () {
    $exo = gameSplit($this->draft, TrafficScope::source($this->exo->id), [$this->v2->id => 100]);
    gameSplit($this->draft, TrafficScope::source($this->pop->id), [$this->v2->id => 100]);
    $campaign = gameSplit($this->draft, TrafficScope::campaign($this->pop->id, $this->campaign->id), [$this->v3->id => 100]);

    expect(versionAfterClick($this, $this->exo, null, 'browserAbrowserAbrowserAbrowserAbrowserA'))->toBe($this->v2->id)
        ->and(versionAfterClick($this, $this->pop, $this->campaign, 'browserBbrowserBbrowserBbrowserBbrowserB'))->toBe($this->v3->id)
        // A campaign without a split of its own gets its source's.
        ->and(versionAfterClick($this, $this->pop, $this->otherCampaign, 'browserCbrowserCbrowserCbrowserCbrowserC'))->toBe($this->v2->id);

    // Each visitor is in the period of the split that gave them their version.
    expect(GameAssignment::query()->where('visitor_hash', browserHashOf('browserAbrowserAbrowserAbrowserAbrowserA'))->sole()->period_id)->toBe($exo->id)
        ->and(GameAssignment::query()->where('visitor_hash', browserHashOf('browserBbrowserBbrowserBbrowserBbrowserB'))->sole()->period_id)->toBe($campaign->id);
});

it('gives an unpaid arrival and a signed-in player the general split, whatever splits the game has', function () {
    gameSplit($this->draft, TrafficScope::source($this->exo->id), [$this->v2->id => 100]);

    arrivedAt($this->game->slug, ['channel' => 'direct', 'slug' => $this->game->slug], 'browserDbrowserDbrowserDbrowserDbrowserD')->call('init');
    expect(GameAssignment::query()->where('visitor_hash', browserHashOf('browserDbrowserDbrowserDbrowserDbrowserD'))->sole()->version_id)->toBe($this->v1->id);

    // Even when a click of the source brought them: splits are for anonymous visitors.
    $player = User::factory()->create();
    $click = paidClickTo($this, $this->exo, null, 'browserEbrowserEbrowserEbrowserEbrowserE');
    app(TrafficArrival::class)->set(ArrivalToken::make(['visit' => $click->id], browserHashOf('browserEbrowserEbrowserEbrowserEbrowserE'), now()->getTimestamp()));
    Livewire::actingAs($player)->withCookie((string) config('games.visitor_cookie.name'), 'browserEbrowserEbrowserEbrowserEbrowserE')
        ->test(PlayGame::class, ['slug' => $this->game->slug])->call('init');

    expect(GameAssignment::query()->where('user_id', $player->id)->sole()->version_id)->toBe($this->v1->id);
});

it('keeps a visitor on their version when they come back from another source', function () {
    gameSplit($this->draft, TrafficScope::source($this->exo->id), [$this->v2->id => 100]);
    gameSplit($this->draft, TrafficScope::campaign($this->pop->id, $this->campaign->id), [$this->v3->id => 100]);
    $token = 'browserAbrowserAbrowserAbrowserAbrowserA';

    expect(versionAfterClick($this, $this->exo, null, $token))->toBe($this->v2->id);
    $first = GameAssignment::query()->sole();

    expect(versionAfterClick($this, $this->pop, $this->campaign, $token))->toBe($this->v2->id);
    arrivedAt($this->game->slug, ['channel' => 'direct', 'slug' => $this->game->slug], $token)->call('init');

    expect(GameAssignment::query()->sole()->id)->toBe($first->id);
});

it('gives a new version only to the visitors a split sent, once their version is taken out of it', function () {
    gameSplit($this->draft, TrafficScope::source($this->exo->id), [$this->v2->id => 100]);
    $paid = 'browserAbrowserAbrowserAbrowserAbrowserA';
    $unpaid = 'browserBbrowserBbrowserBbrowserBbrowserB';

    expect(versionAfterClick($this, $this->exo, null, $paid))->toBe($this->v2->id);
    arrivedAt($this->game->slug, ['channel' => 'direct', 'slug' => $this->game->slug], $unpaid)->call('init');
    $everyone = GameAssignment::query()->where('visitor_hash', browserHashOf($unpaid))->sole();

    // The split now gives V3: it had a visitor, so its own period ends and another starts.
    $next = gameSplit($this->draft, TrafficScope::source($this->exo->id), [$this->v3->id => 100]);

    expect(GameTrafficPeriod::query()->where('source_id', $this->exo->id)->count())->toBe(2)
        ->and(versionAfterClick($this, $this->exo, null, $paid))->toBe($this->v3->id)
        ->and(GameAssignment::query()->where('visitor_hash', browserHashOf($paid))->whereNotNull('active_key')->sole()->period_id)->toBe($next->id);

    arrivedAt($this->game->slug, ['channel' => 'direct', 'slug' => $this->game->slug], $unpaid)->call('init');

    expect(GameAssignment::query()->where('visitor_hash', browserHashOf($unpaid))->sole()->id)->toBe($everyone->id)
        ->and($everyone->fresh()->ended_at)->toBeNull();
});

it('sends a split’s clicks on to the next split that covers them while none of its versions can be played', function () {
    gameSplit($this->draft, TrafficScope::source($this->pop->id), [$this->v2->id => 100]);
    gameSplit($this->draft, TrafficScope::campaign($this->pop->id, $this->campaign->id), [$this->v3->id => 100]);
    $this->v3->forceFill(['status' => GameVersionStatus::Retired])->save();

    expect(versionAfterClick($this, $this->pop, $this->campaign, 'browserAbrowserAbrowserAbrowserAbrowserA'))->toBe($this->v2->id);

    $this->v2->forceFill(['status' => GameVersionStatus::Retired])->save();

    expect(versionAfterClick($this, $this->pop, $this->campaign, 'browserBbrowserBbrowserBbrowserBbrowserB'))->toBe($this->v1->id);
});

it('starts the attempt of a visitor a paid click brought on the split’s version, and of another game’s click on the general one', function () {
    gameSplit($this->draft, TrafficScope::source($this->exo->id), [$this->v2->id => 100]);
    $token = 'browserAbrowserAbrowserAbrowserAbrowserA';
    $visitor = new GameVisitor(GameAudience::Anonymous, null, browserHashOf($token), browserHashOf($token));
    $click = paidClickTo($this, $this->exo, null, $token);

    expect(app(StartGameAttemptAction::class)->handle($this->game, $visitor, 'en', visitId: $click->id)->version_id)->toBe($this->v2->id);

    $other = 'browserBbrowserBbrowserBbrowserBbrowserB';
    $elsewhere = TrafficVisit::factory()->create(['source_id' => $this->exo->id, 'visitor_hash' => browserHashOf($other), 'game_id' => null]);
    $visitor = new GameVisitor(GameAudience::Anonymous, null, browserHashOf($other), browserHashOf($other));

    expect(app(StartGameAttemptAction::class)->handle($this->game, $visitor, 'en', visitId: $elsewhere->id)->version_id)->toBe($this->v1->id);
});

it('gives a version published to take another’s place its share in each split too', function () {
    gameSplit($this->draft, TrafficScope::source($this->exo->id), [$this->v2->id => 60, $this->v3->id => 40]);

    $v4 = app(PublishGameVersionAction::class)->handle($this->draft['admin'], $this->game, $this->draft['set']->id, $this->draft['flow']->id, $this->v2->id);

    expect(app(GameTrafficQuery::class)->currentPeriod((int) $this->game->id, GameAudience::Anonymous, TrafficScope::source($this->exo->id))->shares())
        ->toBe([$this->v3->id => 40, $v4->id => 60])
        // The general split never gave V2 a share: it stays as it was.
        ->and(app(GameTrafficQuery::class)->currentPeriod((int) $this->game->id, GameAudience::Anonymous)->shares())->toBe([$this->v1->id => 100]);
});

it('refuses a split for logged-in players, without shares, for a campaign of another source, or with nothing playable', function (Closure $apply, string $message) {
    expect(fn () => $apply($this))->toThrow(CannotManageGameException::class, $message);

    expect(GameTrafficPeriod::query()->whereNotNull('source_id')->exists())->toBeFalse();
})->with([
    'logged-in players' => [fn ($test) => app(ApplyGameTrafficAction::class)->handle($test->draft['admin'], $test->game, GameAudience::Authenticated, [$test->v2->id => 100], null, TrafficScope::source($test->exo->id)), 'anonymous visitors only'],
    'no shares' => [fn ($test) => app(ApplyGameTrafficAction::class)->handle($test->draft['admin'], $test->game, GameAudience::Anonymous, [], null, TrafficScope::source($test->exo->id)), 'Give at least one version a share.'],
    'another source’s campaign' => [fn ($test) => app(ApplyGameTrafficAction::class)->handle($test->draft['admin'], $test->game, GameAudience::Anonymous, [$test->v2->id => 100], null, TrafficScope::campaign($test->exo->id, $test->campaign->id)), 'This campaign no longer exists.'],
    'shares short of 100' => [fn ($test) => app(ApplyGameTrafficAction::class)->handle($test->draft['admin'], $test->game, GameAudience::Anonymous, [$test->v2->id => 60], null, TrafficScope::source($test->exo->id)), 'Shares add up to 60%'],
    'an archived version' => [function ($test) {
        $test->v2->forceFill(['status' => GameVersionStatus::Retired])->save();

        app(ApplyGameTrafficAction::class)->handle($test->draft['admin'], $test->game, GameAudience::Anonymous, [$test->v2->id => 100], null, TrafficScope::source($test->exo->id));
    }, 'Only active versions'],
]);

it('applies nothing over a split changed since it was drawn', function () {
    gameSplit($this->draft, TrafficScope::source($this->exo->id), [$this->v2->id => 100]);

    expect(fn () => app(ApplyGameTrafficAction::class)->handle($this->draft['admin'], $this->game, GameAudience::Anonymous, [$this->v3->id => 100], [], TrafficScope::source($this->exo->id)))
        ->toThrow(fn (CannotManageGameException $exception) => expect($exception->isStale())->toBeTrue());

    expect(app(GameTrafficQuery::class)->currentPeriod((int) $this->game->id, GameAudience::Anonymous, TrafficScope::source($this->exo->id))->shares())->toBe([$this->v2->id => 100]);
});

it('ends a split, keeping its period in the history whether it sent visitors or not', function () {
    $used = gameSplit($this->draft, TrafficScope::source($this->exo->id), [$this->v2->id => 100]);
    $unused = gameSplit($this->draft, TrafficScope::source($this->pop->id), [$this->v3->id => 100]);
    versionAfterClick($this, $this->exo, null, 'browserAbrowserAbrowserAbrowserAbrowserA');

    app(EndGameTrafficSplitAction::class)->handle($this->draft['admin'], $this->game, $used->id);
    app(EndGameTrafficSplitAction::class)->handle($this->draft['admin'], $this->game, $unused->id);

    // Never deleted: a visitor being assigned at that moment may be writing into it.
    expect($used->fresh())->ended_at->not->toBeNull()->open_scope->toBeNull()
        ->and($unused->fresh())->ended_at->not->toBeNull()->open_scope->toBeNull()
        ->and($unused->fresh()->shares())->toBe([$this->v3->id => 100])
        // Its visitor keeps V2 nowhere else: the next click of the source gets the general split.
        ->and(versionAfterClick($this, $this->exo, null, 'browserBbrowserBbrowserBbrowserBbrowserB'))->toBe($this->v1->id);

    // The general split is no split, and a split already ended is gone from what can be ended.
    $general = app(GameTrafficQuery::class)->currentPeriod((int) $this->game->id, GameAudience::Anonymous);

    foreach ([$general->id, $used->id] as $periodId) {
        expect(fn () => app(EndGameTrafficSplitAction::class)->handle($this->draft['admin'], $this->game, $periodId))
            ->toThrow(fn (CannotManageGameException $exception) => expect($exception->isStale())->toBeTrue());
    }
});
