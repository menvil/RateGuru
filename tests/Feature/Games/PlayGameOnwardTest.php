<?php

use App\Enums\GameEventType;
use App\Enums\GameFlowStepType;
use App\Enums\GameOnwardKind;
use App\Livewire\Games\PlayGame;
use App\Livewire\Games\PreviewGame;
use App\Models\Category;
use App\Models\GameAttempt;
use App\Models\GameEvent;
use App\Models\GameRoundOption;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\User;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->draft = publishableGameDraft(1);
});

/** The one-round game, with these steps after it, made live. */
function onwardGame(array $draft, array $types, array $settings = []): void
{
    replaceFlowSteps($draft['flow'], $types, settings: $settings);
    liveGame($draft);
}

/** A guest's browser playing the one-round game to its Full Result. */
function playOnwardGameToResult(string $slug): Testable
{
    return Livewire::withCookie((string) config('games.visitor_cookie.name'), str_repeat('o', 40))
        ->test(PlayGame::class, ['slug' => $slug])
        ->call('init')->call('continue')->call('choose', 0)->call('next')
        ->assertSee('data-testid="game-step-full_result"', false);
}

it('sends the visitor to the post of their last pick, asking for the vote', function () {
    onwardGame($this->draft, [GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::GoToPost]);
    $picked = GameRoundOption::query()->where('position', 0)->sole()->post_id;

    playOnwardGameToResult($this->draft['game']->slug)->call('continue')
        ->assertSee('data-testid="game-step-go_to_post"', false)
        ->assertSee('data-testid="game-onward-post"', false)
        ->call('leave', 'step')
        ->assertRedirect(route('posts.show', ['post' => $picked, 'vote' => 1]));

    $attempt = GameAttempt::query()->sole();

    expect($attempt->onward_kind)->toBe(GameOnwardKind::Post)
        ->and($attempt->onward_post_id)->toBe($picked)
        ->and($attempt->onward_at)->not->toBeNull()
        ->and(GameEvent::query()->where('type', GameEventType::PostOpened)->count())->toBe(1);
});

it('counts going on to the post once, however often the visitor comes back and goes again', function () {
    RatingOption::factory()->for(RatingGroup::factory()->create(['key' => 'cuteness']), 'group')->create();
    onwardGame($this->draft, [GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::GoToPost], ['go_to_post' => ['post_target' => 'fresh', 'ask_vote' => false, 'ask_groups' => 'cuteness']]);
    $fresh = Post::factory()->published()->create(['published_at' => now()->addMinute()]);

    $play = Livewire::withCookie((string) config('games.visitor_cookie.name'), str_repeat('f', 40))
        ->test(PlayGame::class, ['slug' => $this->draft['game']->slug])
        ->call('init')->call('choose', 0)->call('next')->call('continue');

    $play->call('leave', 'step')->assertRedirect(route('posts.show', ['post' => $fresh->id, 'ask' => 'cuteness']));
    $at = GameAttempt::query()->sole()->onward_at;
    $this->travel(5)->minutes();
    $play->call('leave', 'step');

    expect(GameEvent::query()->where('type', GameEventType::PostOpened)->count())->toBe(1)
        ->and(GameAttempt::query()->sole()->onward_at->equalTo($at))->toBeTrue();
});

it('sends the visitor to the feed, counting nothing, when the linked post is hidden', function () {
    $post = Post::factory()->published()->create();
    onwardGame($this->draft, [GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::GoToPost], ['go_to_post' => ['post_target' => 'chosen', 'post_id' => $post->id]]);
    $post->forceFill(['status' => Post::factory()->hidden()->raw()['status']])->save();

    Livewire::withCookie((string) config('games.visitor_cookie.name'), str_repeat('h', 40))
        ->test(PlayGame::class, ['slug' => $this->draft['game']->slug])
        ->call('init')->call('choose', 0)->call('next')->call('continue')
        ->call('leave', 'step')
        ->assertRedirect(route('feed'));

    expect(GameAttempt::query()->sole()->onward_kind)->toBeNull()
        ->and(GameEvent::query()->where('type', GameEventType::PostOpened)->count())->toBe(0);
});

it('lets Full Result send the visitor on to the feed, without sign-up or sharing', function () {
    onwardGame($this->draft, [GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult], ['full_result' => ['onward' => 'feed', 'feed_sort' => 'top']]);

    playOnwardGameToResult($this->draft['game']->slug)
        ->assertSee('data-testid="game-onward"', false)
        ->assertDontSee('data-testid="game-signup"', false)
        ->call('leave', 'step')
        ->assertRedirect(route('feed', ['sort' => 'top']));

    expect(GameAttempt::query()->sole()->onward_kind)->toBe(GameOnwardKind::Feed);
});

it('sends the visitor to a category of the feed', function () {
    Category::factory()->create(['slug' => 'cats']);
    onwardGame($this->draft, [GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::GoToPage], ['go_to_page' => ['destination' => 'category', 'category' => 'cats']]);

    Livewire::withCookie((string) config('games.visitor_cookie.name'), str_repeat('c', 40))
        ->test(PlayGame::class, ['slug' => $this->draft['game']->slug])
        ->call('init')->call('choose', 0)->call('next')->call('continue')
        ->call('leave', 'step')
        ->assertRedirect(route('feed', ['category' => ['cats']]));

    expect(GameAttempt::query()->sole()->onward_kind)->toBe(GameOnwardKind::Page);
});

it('opens another site in a tab of its own, counting it without leaving the game', function () {
    onwardGame($this->draft, [GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::GoToPage], ['go_to_page' => ['destination' => 'url', 'url' => 'https://example.com/landing?game={game}', 'new_tab' => true]]);

    Livewire::withCookie((string) config('games.visitor_cookie.name'), str_repeat('u', 40))
        ->test(PlayGame::class, ['slug' => $this->draft['game']->slug])
        ->call('init')->call('choose', 0)->call('next')->call('continue')
        ->assertSee('href="https://example.com/landing?game='.rawurlencode($this->draft['game']->slug).'"', false)
        ->assertSee('target="_blank"', false)
        ->call('leave', 'step', true)
        ->assertNoRedirect();

    expect(GameAttempt::query()->sole()->onward_kind)->toBe(GameOnwardKind::Page);
});

it('sends a visitor who asks to leave from a step that leads nowhere to the feed, counting nothing', function () {
    onwardGame($this->draft, [GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::GoToPost]);

    Livewire::withCookie((string) config('games.visitor_cookie.name'), str_repeat('l', 40))
        ->test(PlayGame::class, ['slug' => $this->draft['game']->slug])
        ->call('init')->call('continue')->call('choose', 0)
        ->call('leave', 'step')
        ->assertRedirect(route('feed'));

    expect(GameAttempt::query()->sole()->onward_kind)->toBeNull();
});

it('records nothing when the preview follows a way on', function () {
    replaceFlowSteps($this->draft['flow'], [GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::GoToPost]);
    $this->actingAs($this->draft['admin']);

    Livewire::withQueryParams(['set' => $this->draft['set']->id, 'flow' => $this->draft['flow']->id])
        ->test(PreviewGame::class, ['game' => $this->draft['game']->id])
        ->call('choose', 0)->call('next')->call('continue')
        ->call('leave', 'step')
        ->assertDispatched('toast', message: 'Preview: here the visitor would go to where this step leads.')
        ->assertNoRedirect();

    expect(GameAttempt::query()->count() + GameEvent::query()->count())->toBe(0);
});

it('sends a signed-in player to a fresh post they have not voted on and did not post', function () {
    onwardGame($this->draft, [GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::GoToPost], ['go_to_post' => ['post_target' => 'fresh']]);
    $player = User::factory()->create();
    $older = Post::factory()->published()->create(['published_at' => now()->subDay()]);
    Post::factory()->published()->create(['user_id' => $player->id, 'published_at' => now()->addMinute()]);

    Livewire::actingAs($player)
        ->test(PlayGame::class, ['slug' => $this->draft['game']->slug])
        ->call('init')->call('choose', 0)->call('next')->call('continue')
        ->call('leave', 'step')
        ->assertRedirect(route('posts.show', ['post' => $older->id, 'vote' => 1]));
});
