<?php

use App\Enums\GameAttemptStatus;
use App\Enums\GameAudience;
use App\Enums\GameEventType;
use App\Enums\GameStatus;
use App\Enums\UserStatus;
use App\Models\GameAttempt;
use App\Models\GameEvent;
use App\Models\GameRoundOption;
use App\Models\GameVersion;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\Urls\DisplayHost;
use Illuminate\Support\Str;

beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->draft = publishableGameDraft(1);
    $this->version = liveGame($this->draft);
    $this->slug = $this->draft['game']->slug;
});

it('shows a shared result with its profile and the game to play', function () {
    $this->get("/games/{$this->slug}/v1/oracle")
        ->assertOk()
        ->assertSeeInOrder(['game-profile-description', 'You matched every choice.', 'rg-game__share-card', 'Oracle', 'data-testid="game-result-byline">'.DisplayHost::of(url('/')).'<'], false)
        ->assertSee('Play the game')
        ->assertSee('<meta property="og:title" content="Oracle — '.e($this->draft['game']->title).'">', false);
});

it('never previews a shared result with a post\'s picture', function () {
    $html = $this->get("/games/{$this->slug}/v1/oracle")->getContent();
    $postImages = GameRoundOption::query()->get()->map(fn ($option) => $option->post->imageAsset->path)->all();

    foreach ($postImages as $path) {
        expect($html)->not->toContain($path);
    }

    expect($html)->toContain(config('share.open_graph.fallback_path'));
});

it('finds no result for a version or profile that does not exist', function () {
    $this->get("/games/{$this->slug}/v9/oracle")->assertNotFound();
    $this->get("/games/{$this->slug}/v1/nobody")->assertNotFound();
});

it('still shows a shared result of a game that has ended, as over', function () {
    $this->draft['game']->fresh()->forceFill(['status' => GameStatus::Archived])->save();

    $this->get("/games/{$this->slug}/v1/oracle")->assertOk()->assertSee('This game has ended.')->assertDontSee('Play the game');
});

it('counts a share on its way to the provider', function () {
    $response = $this->get("/games/{$this->slug}/v1/oracle/share/x");

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toStartWith('https://twitter.com/intent/tweet?')
        ->toContain(urlencode(route('games.result', ['slug' => $this->slug, 'version' => 1, 'profile' => 'oracle'])))
        ->and(GameEvent::query()->where('type', GameEventType::ShareClicked)->value('provider'))->toBe('x');
});

/** The version's completed attempt by a player with an account, at its first profile. */
function playedResult(GameVersion $version, User $player): GameAttempt
{
    return GameAttempt::factory()->create([
        'version_id' => $version->id,
        'game_id' => $version->game_id,
        'audience' => GameAudience::Authenticated,
        'user_id' => $player->id,
        'visitor_hash' => null,
        'status' => GameAttemptStatus::Completed,
        'completed_at' => now(),
        'matches' => 1,
        'score' => 1,
        'profile_key' => 'oracle',
    ]);
}

it('shows a player\'s own result with their @name on the card, by the attempt that earned it', function () {
    $attempt = playedResult($this->version, User::factory()->create(['username' => 'ivan']));

    $this->get("/games/{$this->slug}/r/{$attempt->public_id}")
        ->assertOk()
        ->assertSeeInOrder(['You matched every choice.', 'Oracle', 'data-testid="game-result-byline">@ivan<'], false)
        ->assertSee('Play the game');
});

it('opens the profile\'s page instead once the player\'s account is banned or deleted', function (UserStatus $status) {
    $attempt = playedResult($this->version, User::factory()->create(['username' => 'ivan', 'status' => $status]));

    $this->get("/games/{$this->slug}/r/{$attempt->public_id}")->assertRedirect("/games/{$this->slug}/v1/oracle");
})->with([UserStatus::Banned, UserStatus::Deleted]);

it('finds no own result for an attempt not finished, another game\'s, or none at all', function () {
    $player = User::factory()->create();
    $started = playedResult($this->version, $player);
    $started->forceFill(['status' => GameAttemptStatus::Started, 'profile_key' => null])->save();
    $other = playedResult(liveGame(publishableGameDraft(1)), $player);

    $this->get("/games/{$this->slug}/r/{$started->public_id}")->assertNotFound();
    $this->get("/games/{$this->slug}/r/{$other->public_id}")->assertNotFound();
    $this->get("/games/{$this->slug}/r/".Str::ulid())->assertNotFound();
});

it('shares a player\'s own result, counted on its way', function () {
    $attempt = playedResult($this->version, User::factory()->create(['username' => 'ivan']));

    $response = $this->get("/games/{$this->slug}/r/{$attempt->public_id}/share/x");

    expect($response->headers->get('Location'))->toStartWith('https://twitter.com/intent/tweet?')
        ->toContain(urlencode(route('games.player-result', ['slug' => $this->slug, 'attempt' => $attempt->public_id])))
        ->and(GameEvent::query()->where('type', GameEventType::ShareClicked)->value('provider'))->toBe('x');
});
