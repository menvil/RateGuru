<?php

use App\Enums\GameAudience;
use App\Enums\GameEventType;
use App\Enums\GameFlowStepType;
use App\Enums\GameOnwardKind;
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
use App\Models\TrafficVisit;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
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

it('credits a sign-in form opened on the post the game sent the browser to, to the latest play that went there, once, with visits not recorded', function () {
    recordTraffic(capture: false);
    $post = playOnToPost($this->draft, $this->token, 'post_voted');
    $sent = GameAttempt::query()->sole();
    $later = fn (array $attributes): GameAttempt => GameAttempt::factory()->completed()->create(['version_id' => $sent->version_id, 'visitor_hash' => $sent->visitor_hash, ...$attributes]);
    // The browser's play sent to the post earlier, its plays sent to the feed and to another post since, and another browser's sent to the post now: none of them.
    $later(['onward_kind' => GameOnwardKind::Post, 'onward_post_id' => $post->id, 'onward_at' => now()->subMinutes(10)]);
    $later(['onward_kind' => GameOnwardKind::Feed, 'onward_at' => now()->addSecond()]);
    $later(['onward_kind' => GameOnwardKind::Post, 'onward_post_id' => Post::factory()->published()->create()->id, 'onward_at' => now()->addSecond()]);
    $later(['visitor_hash' => browserHashOf(str_repeat('o', 40)), 'onward_kind' => GameOnwardKind::Post, 'onward_post_id' => $post->id, 'onward_at' => now()->addSecond()]);

    signInFormOpened($this->token, $post->id);
    signInFormOpened($this->token, $post->id);

    expect(GameEvent::query()->where('type', GameEventType::PostSignInForm)->pluck('attempt_id')->all())->toBe([$sent->id])
        ->and(GameEvent::query()->where('type', GameEventType::PostSignInForm)->sole()->audience)->toBe(GameAudience::Anonymous)
        // A form is no goal of the flow's.
        ->and($sent->fresh()->goal_reached_at)->toBeNull();
});

it('credits no sign-in form to a browser no play sent to a post within 30 minutes, and still answers 204', function () {
    $post = playOnToPost($this->draft, $this->token, 'post_voted');
    recordTraffic();

    // A browser that never played, and this one 31 minutes after going on.
    signInFormOpened(str_repeat('n', 40), $post->id);
    $this->travel(31)->minutes();
    signInFormOpened($this->token, $post->id);

    expect(GameEvent::query()->where('type', GameEventType::PostSignInForm)->exists())->toBeFalse();
});

it('credits no sign-in form opened anywhere but on the post the game sent the browser to, which its page names', function () {
    $post = playOnToPost($this->draft, $this->token, 'post_voted');
    $other = Post::factory()->published()->create();

    // On another post the visitor moved on to, and on a page that is no post's — the feed, the sign-in and sign-up pages.
    signInFormOpened($this->token, $other->id);
    signInFormOpened($this->token);

    expect(GameEvent::query()->where('type', GameEventType::PostSignInForm)->exists())->toBeFalse();
    Livewire::test(PostShow::class, ['post' => $post])->assertSee('data-rg-post="'.$post->id.'"', false);
    $this->get(route('login'))->assertOk()->assertSee('data-rg-traffic-signal="signup_form"', false)->assertDontSee('data-rg-post', false);
});

it('answers 204 and still marks the visit when crediting the form to the game fails, and reports the failure', function () {
    Exceptions::fake();
    $post = playOnToPost($this->draft, $this->token, 'post_voted');
    // Visits recorded from here on: the paid click holding the browser takes the form.
    recordTraffic();
    $visit = TrafficVisit::factory()->create(['visitor_hash' => browserHashOf($this->token)]);
    DB::listen(function (QueryExecuted $query): void {
        if (str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'onward_kind') && str_contains($query->sql, 'visitor_hash')) {
            throw new RuntimeException('The plays cannot be read.');
        }
    });

    signInFormOpened($this->token, $post->id);

    expect(GameEvent::query()->where('type', GameEventType::PostSignInForm)->exists())->toBeFalse()
        ->and($visit->fresh()->signup_form_at)->not->toBeNull();
    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'The plays cannot be read.');
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
