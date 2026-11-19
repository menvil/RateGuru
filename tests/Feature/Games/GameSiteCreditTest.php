<?php

use App\Enums\GameEventType;
use App\Enums\GameFlowStepType;
use App\Livewire\Games\PlayGame;
use App\Livewire\Posts\PostShow;
use App\Livewire\Posts\PostVoting;
use App\Livewire\Voting\RatingVoting;
use App\Models\GameAttempt;
use App\Models\GameEvent;
use App\Models\GameRoundOption;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->draft = publishableGameDraft(1);
    $this->group = RatingGroup::factory()->create(['key' => 'cuteness']);
    $this->option = RatingOption::factory()->for($this->group, 'group')->create();
    $this->token = str_repeat('s', 40);
});

/**
 * The one-round game played by a guest's browser to its Go to Post step and
 * on to the post of their pick, which it returns.
 */
function playOnToPost(array $draft, string $token, string $goal, array $settings = []): Post
{
    $draft['flow']->forceFill(['goal' => $goal])->save();
    replaceFlowSteps($draft['flow'], [GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::GoToPost], settings: ['go_to_post' => $settings]);
    liveGame($draft);

    Livewire::withCookie((string) config('games.visitor_cookie.name'), $token)
        ->test(PlayGame::class, ['slug' => $draft['game']->slug])
        ->call('init')->call('choose', 0)->call('next')->call('continue')
        ->call('leave', 'step');

    return Post::query()->findOrFail(GameRoundOption::query()->where('position', 0)->sole()->post_id);
}

it('credits a vote on the post the game sent the visitor to, once, after they sign up', function () {
    $post = playOnToPost($this->draft, $this->token, 'post_voted');
    $member = User::factory()->create();

    Livewire::withCookie((string) config('games.visitor_cookie.name'), $this->token)->actingAs($member)
        ->test(PostVoting::class, ['postId' => $post->id])
        ->call('vote', 'up')
        ->call('vote', 'down');

    expect(GameEvent::query()->where('type', GameEventType::PostVoted)->count())->toBe(1)
        ->and(GameAttempt::query()->sole()->goal_reached_at)->not->toBeNull();
});

it('credits nothing for a vote on another post, or after 30 minutes', function () {
    $post = playOnToPost($this->draft, $this->token, 'post_voted');
    $other = Post::factory()->published()->create();
    $member = User::factory()->create();

    Livewire::withCookie((string) config('games.visitor_cookie.name'), $this->token)->actingAs($member)
        ->test(PostVoting::class, ['postId' => $other->id])
        ->call('vote', 'up');

    $this->travel(31)->minutes();

    Livewire::withCookie((string) config('games.visitor_cookie.name'), $this->token)->actingAs($member)
        ->test(PostVoting::class, ['postId' => $post->id])
        ->call('vote', 'up');

    expect(GameEvent::query()->where('type', GameEventType::PostVoted)->count())->toBe(0)
        ->and(GameAttempt::query()->sole()->goal_reached_at)->toBeNull();
});

it('credits an answer to a rating group the post asked for, and no other', function () {
    $other = RatingGroup::factory()->create(['key' => 'style']);
    $otherOption = RatingOption::factory()->for($other, 'group')->create();
    $post = playOnToPost($this->draft, $this->token, 'post_rated', ['ask_groups' => 'cuteness']);
    $member = User::factory()->create();

    Livewire::withCookie((string) config('games.visitor_cookie.name'), $this->token)->actingAs($member)
        ->test(RatingVoting::class, ['post' => $post, 'groupKey' => 'style'])
        ->call('vote', $otherOption->id);

    expect(GameEvent::query()->where('type', GameEventType::PostRated)->count())->toBe(0);

    Livewire::withCookie((string) config('games.visitor_cookie.name'), $this->token)->actingAs($member)
        ->test(RatingVoting::class, ['post' => $post, 'groupKey' => 'cuteness'])
        ->call('vote', $this->option->id);

    expect(GameEvent::query()->where('type', GameEventType::PostRated)->count())->toBe(1)
        ->and(GameAttempt::query()->sole()->goal_reached_at)->not->toBeNull();
});

it('credits three votes in the feed after the game sent the visitor there', function () {
    $this->draft['flow']->forceFill(['goal' => 'feed_voted'])->save();
    replaceFlowSteps($this->draft['flow'], [GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::Feed]);
    liveGame($this->draft);
    $member = User::factory()->create();

    Livewire::actingAs($member)
        ->test(PlayGame::class, ['slug' => $this->draft['game']->slug])
        ->call('init')->call('choose', 0)->call('next')->call('continue')
        ->call('leave', 'step');

    foreach (Post::factory()->published()->count(3)->create() as $index => $post) {
        Livewire::actingAs($member)->test(PostVoting::class, ['postId' => $post->id])->call('vote', 'up');

        expect(GameEvent::query()->where('type', GameEventType::FeedVoted)->count())->toBe($index === 2 ? 1 : 0);
    }

    expect(GameAttempt::query()->sole()->goal_reached_at)->not->toBeNull();
});

it('highlights on the post what the game asked for, and nothing without it', function () {
    $post = Post::factory()->published()->create();

    Livewire::withQueryParams(['vote' => '1', 'ask' => 'cuteness,Bad Key'])
        ->test(PostShow::class, ['post' => $post])
        ->assertSet('askVote', true)
        ->assertSet('askGroups', ['cuteness'])
        ->assertSee('data-testid="post-show-ask-cuteness"', false);

    Livewire::withQueryParams([])->test(PostShow::class, ['post' => $post])
        ->assertSet('askVote', false)
        ->assertDontSee('rg-post-ask', false);
});
