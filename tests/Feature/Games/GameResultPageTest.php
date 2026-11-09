<?php

use App\Enums\GameEventType;
use App\Enums\GameStatus;
use App\Models\GameEvent;
use App\Models\GameRoundOption;
use App\Models\ProjectSettings;

beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->draft = publishableGameDraft(1);
    $this->version = liveGame($this->draft);
    $this->slug = $this->draft['game']->slug;
});

it('shows a shared result with its profile and the game to play', function () {
    $this->get("/games/{$this->slug}/v1/oracle")
        ->assertOk()
        ->assertSee('Oracle')
        ->assertSee('You matched every choice.')
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
