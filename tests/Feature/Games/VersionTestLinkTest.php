<?php

use App\Actions\Games\PublishGameVersionAction;
use App\Enums\GameStatus;
use App\Enums\GameVersionStatus;
use App\Filament\Resources\Games\Pages\PreviewGame;
use App\Models\GameAnswer;
use App\Models\GameAssignment;
use App\Models\GameAttempt;
use App\Models\GameEvent;
use App\Models\GameVersion;
use App\Models\ProjectSettings;
use App\Models\TrafficVisit;
use App\Models\User;
use App\Models\UserActivityDay;
use App\Support\Games\Play\VersionTestLink;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/**
 * A test link to a version (VersionTestLink): the game's public page, signed
 * for seven days, playing that version for whoever holds it — the admin
 * preview's way of playing — and recording nothing: no assignment, visit,
 * arrival token or cookie, attempt, answer, event or day of activity, and no
 * share counted from its result. Parameters a channel adds to the link on its
 * way leave it working. An unsigned, tampered or
 * expired link, and one to a version archived or of another game, is the
 * game's ordinary page. The game's Preview offers it for a published version
 * still active.
 */
beforeEach(function () {
    ProjectSettings::factory()->create();
    recordTraffic();
    $this->draft = publishableGameDraft(2);
    $this->v1 = liveGame($this->draft);
    $this->v2 = app(PublishGameVersionAction::class)->handle($this->draft['admin'], $this->draft['game'], $this->draft['set']->id, $this->draft['flow']->id);
    $this->game = $this->draft['game']->fresh();
});

/** What the page and its every request recorded, counted. */
function recordedByGames(): array
{
    return [
        GameAssignment::query()->count(),
        GameAttempt::query()->count(),
        GameAnswer::query()->count(),
        GameEvent::query()->count(),
        TrafficVisit::query()->count(),
        UserActivityDay::query()->count(),
    ];
}

/** The page's Livewire component, sent the calls given as its browser would send them. */
function callPage(TestResponse $page, string $component, array $calls): TestResponse
{
    return test()->withHeaders(['X-Livewire' => '1'])->postJson(Livewire::getUpdateUri(), [
        'components' => [['snapshot' => pageComponentSnapshot($page->getContent(), $component), 'updates' => [], 'calls' => array_map(
            fn (array $call): array => ['path' => '', 'method' => $call[0], 'params' => $call[1] ?? []],
            $calls,
        )]],
    ]);
}

it('plays the version for whoever holds its link, through to the result, recording nothing', function () {
    $before = recordedByGames();
    $page = $this->get(VersionTestLink::url($this->game->slug, (int) $this->v2->id))
        ->assertOk()
        ->assertSee('Preview — V2 — nothing is recorded')
        ->assertDontSee('name="rg-arrival"', false)
        ->assertCookieMissing((string) config('games.visitor_cookie.name'));

    callPage($page, 'games.preview-game', [['init'], ['continue'], ['choose', [0]], ['next'], ['choose', [0]], ['next']])->assertOk();

    expect(recordedByGames())->toBe($before);
});

it('keeps working with parameters a channel adds on the way, and still records nothing', function () {
    $before = recordedByGames();
    $page = $this->get(VersionTestLink::url($this->game->slug, (int) $this->v2->id).'&fbclid=IwAR0abc&utm_source=newsletter&gclid=xyz')
        ->assertOk()
        ->assertSee('Preview — V2 — nothing is recorded')
        ->assertCookieMissing((string) config('games.visitor_cookie.name'));

    callPage($page, 'games.preview-game', [['init'], ['continue'], ['choose', [0]]])->assertOk();

    expect(recordedByGames())->toBe($before);
});

it('shares a result straight to each provider, never through the share counter', function () {
    $page = $this->get(VersionTestLink::url($this->game->slug, (int) $this->v2->id))->assertOk();
    $result = (string) callPage($page, 'games.preview-game', [['init'], ['continue'], ['choose', [0]], ['next'], ['choose', [0]], ['next']])
        ->assertOk()
        ->json('components.0.effects.html');
    preg_match_all('/<a href="([^"]+)"[^>]*data-testid="game-share-([a-z]+)"/', $result, $links, PREG_SET_ORDER);
    $before = recordedByGames();

    expect($links)->not->toBeEmpty()
        ->and($result)->not->toMatch('#/games/'.$this->game->slug.'/(v\d+/[a-z0-9-]+|r/[0-9A-Za-z]{26})/share/#');

    foreach ($links as [, $href, $provider]) {
        expect(parse_url(html_entity_decode($href), PHP_URL_HOST))->not->toBe(parse_url(url('/'), PHP_URL_HOST), $provider);
    }

    expect(recordedByGames())->toBe($before);
});

it('plays it as a logged-in player for a signed-in holder, still recording nothing', function () {
    $this->actingAs($player = User::factory()->create());
    $before = recordedByGames();

    $page = $this->get(VersionTestLink::url($this->game->slug, (int) $this->v1->id))->assertOk()->assertSee('Preview — V1 — nothing is recorded');
    callPage($page, 'games.preview-game', [['init'], ['continue'], ['choose', [0]]])->assertOk();

    expect(recordedByGames())->toBe($before)
        ->and(GameAssignment::query()->where('user_id', $player->id)->exists())->toBeFalse();
});

it('plays a version of a paused game, and none of a game still a draft', function () {
    $link = VersionTestLink::url($this->game->slug, (int) $this->v2->id);
    $this->game->forceFill(['status' => GameStatus::Paused])->save();

    $this->get($link)->assertOk()->assertSee('Preview — V2 — nothing is recorded');

    // A draft is no game anyone plays yet: its link is the ordinary page, which a draft doesn't have.
    $this->game->forceFill(['status' => GameStatus::Draft])->save();

    $this->get($link)->assertNotFound();
});

it('is the ordinary game page for a link unsigned, tampered with, expired, or to a version it may not play', function (Closure $link) {
    $address = $link($this);
    // The ordinary page: the browser given the tracker's cookie, and assigned once the page runs.
    $page = $this->get($address)->assertOk()->assertDontSee('nothing is recorded')->assertCookie((string) config('games.visitor_cookie.name'));

    callPage($page, 'games.play-game', [['init']])->assertOk();

    expect(GameAssignment::query()->count())->toBe(1);
})->with([
    'unsigned' => [fn ($test): string => '/games/'.$test->game->slug.'?v='.$test->v2->id],
    'tampered with' => [fn ($test): string => str_replace('v='.$test->v2->id, 'v='.$test->v1->id, VersionTestLink::url($test->game->slug, (int) $test->v2->id))],
    'expired' => [function ($test): string {
        $address = VersionTestLink::url($test->game->slug, (int) $test->v2->id);
        $test->travel(VersionTestLink::DAYS + 1)->days();

        return $address;
    }],
    'to an archived version' => [function ($test): string {
        $test->v2->forceFill(['status' => GameVersionStatus::Retired])->save();

        return VersionTestLink::url($test->game->slug, (int) $test->v2->id);
    }],
    'to another game’s version' => [function ($test): string {
        $other = publishableGameDraft(1);
        $version = app(PublishGameVersionAction::class)->handle($other['admin'], $other['game'], $other['set']->id, $other['flow']->id);

        return VersionTestLink::url($test->game->slug, (int) $version->id);
    }],
]);

it('offers Copy test link in Preview for a published version still active, and nothing for the draft or an archived one', function () {
    $this->actingAs($this->draft['admin']);
    $page = Livewire::test(PreviewGame::class, ['record' => $this->game->id])->assertDontSee('Copy test link');

    $page->set('source', (string) $this->v2->id)->assertSee('Copy test link')->assertSee('It plays V2 for 7 days and records nothing.');
    $link = (string) $page->viewData('testLink');
    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

    expect(parse_url($link, PHP_URL_PATH))->toBe('/games/'.$this->game->slug)
        ->and($query)->toHaveKeys(['expires', 'signature'])
        ->and($query['v'])->toBe((string) $this->v2->id)
        ->and((int) $query['expires'])->toBe(now()->addDays(7)->getTimestamp());

    GameVersion::query()->whereKey($this->v2->id)->update(['status' => GameVersionStatus::Retired]);
    $page->set('source', (string) $this->v1->id)->set('source', (string) $this->v2->id)->assertDontSee('Copy test link');

    // A game still a draft offers none, whatever its versions.
    $this->game->forceFill(['status' => GameStatus::Draft])->save();
    Livewire::test(PreviewGame::class, ['record' => $this->game->id])->set('source', (string) $this->v1->id)->assertDontSee('Copy test link');
});
