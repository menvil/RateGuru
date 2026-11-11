<?php

use App\Actions\Games\ApplyGameTrafficAction;
use App\Actions\Games\ChangeGameFlowAction;
use App\Actions\Games\ClaimGuestGameAttemptsAction;
use App\Actions\Profile\AnonymizeUserAccountAction;
use App\Enums\GameAccountOutcome;
use App\Enums\GameAudience;
use App\Enums\GameEventType;
use App\Enums\GameFlowStepType;
use App\Enums\GameStatus;
use App\Livewire\Games\PlayGame;
use App\Models\Game;
use App\Models\GameAttempt;
use App\Models\GameEmailCapture;
use App\Models\GameEvent;
use App\Models\GameFlow;
use App\Models\GameFlowStep;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\Games\Flow\GameFlowTemplate;
use App\Support\Games\Play\GameSessionAttempts;
use Illuminate\Support\Facades\Mail;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->draft = publishableGameDraft(1);
});

/** The draft played through the Email Before Result flow, made live. */
function emailFlowGame(array $draft, bool $required = false): void
{
    $flow = app(ChangeGameFlowAction::class)->create($draft['admin'], $draft['game'], 'Email first', GameFlowTemplate::EmailBeforeResult);

    if ($required) {
        $step = GameFlowStep::query()->where('flow_id', $flow->id)->where('type', 'email_capture')->sole();
        $step->forceFill(['settings' => [...$step->settings, 'required' => true]])->save();
    }

    $draft['flow'] = $flow;
    liveGame($draft);
}

/**
 * The flow's steps replaced by fresh ones of these types, in this order; a
 * Next Game step leads to the game given.
 *
 * @param  list<GameFlowStepType>  $types
 */
function replaceFlowSteps(GameFlow $flow, array $types, ?Game $nextGame = null): void
{
    $flow->steps()->delete();

    foreach ($types as $position => $type) {
        $step = GameFlowStep::factory()->type($type)->create(['flow_id' => $flow->id, 'position' => $position]);

        if ($type === GameFlowStepType::NextGame) {
            $step->forceFill(['settings' => ['next_game_id' => $nextGame?->id]])->save();
        }
    }
}

/** The one-round game played by a guest's browser to the step after its result. */
function playPastResult(string $slug, string $token = 'pastpastpastpastpastpastpastpastpastpast'): Testable
{
    return Livewire::withCookie((string) config('games.visitor_cookie.name'), $token)
        ->test(PlayGame::class, ['slug' => $slug])
        ->call('init')->call('continue')->call('choose', 0)->call('next')
        ->assertSee('data-testid="game-step-full_result"', false)
        ->call('continue');
}

function playToEmail(string $slug): Testable
{
    return Livewire::withCookie((string) config('games.visitor_cookie.name'), str_repeat('e', 40))
        ->test(PlayGame::class, ['slug' => $slug])
        ->call('init')->call('continue')->call('choose', 0)->call('next')
        ->assertSee('You matched 1 of 1')
        ->call('continue');
}

it('keeps an email with an unticked consent and moves on, sending nothing', function () {
    emailFlowGame($this->draft);
    Mail::fake();

    playToEmail($this->draft['game']->slug)
        ->assertSee('Leave your email to stay in touch.')
        ->set('email', 'Player@Example.com')
        ->call('submitEmail')
        ->assertHasNoErrors()
        ->assertSee('data-testid="game-step-full_result"', false);

    $capture = GameEmailCapture::query()->sole();

    expect($capture->email)->toBe('player@example.com')
        ->and($capture->marketing_consent)->toBeFalse()
        ->and($capture->consent_label)->toBe('Tell me about new games and news')
        ->and(GameEvent::query()->where('type', GameEventType::EmailCaptured)->count())->toBe(1);

    Mail::assertNothingSent();
});

it('refuses an address that is not one', function () {
    emailFlowGame($this->draft);

    playToEmail($this->draft['game']->slug)->set('email', 'not-an-email')->call('submitEmail')->assertHasErrors(['email']);

    expect(GameEmailCapture::query()->exists())->toBeFalse();
});

it('lets an optional email be skipped, and a required one not', function () {
    emailFlowGame($this->draft);
    playToEmail($this->draft['game']->slug)->call('continue')->assertSee('data-testid="game-step-full_result"', false);

    expect(GameEmailCapture::query()->exists())->toBeFalse();
});

it('holds a required email step until the email is left', function () {
    emailFlowGame($this->draft, required: true);

    playToEmail($this->draft['game']->slug)->call('continue')->assertHasErrors(['email'])->assertSee('Before your result');
});

it('skips the email step for a signed-in player, and the sign-up step too', function () {
    emailFlowGame($this->draft);
    $this->actingAs(User::factory()->create());

    Livewire::test(PlayGame::class, ['slug' => $this->draft['game']->slug])
        ->call('init')->call('continue')->call('choose', 0)->call('next')
        ->call('continue')
        ->assertSee('data-testid="game-step-full_result"', false)
        ->assertDontSee('data-testid="game-signup"', false)
        ->assertDontSee('Create free account');
});

it('deletes the emails of attempts an account claimed when the account is deleted', function () {
    emailFlowGame($this->draft);
    playToEmail($this->draft['game']->slug)->set('email', 'player@example.com')->call('submitEmail')->assertHasNoErrors();
    expect(GameEmailCapture::query()->count())->toBe(1);
    $user = User::factory()->create();
    GameAttempt::query()->update(['user_id' => $user->id]);

    app(AnonymizeUserAccountAction::class)->execute($user);

    expect(GameEmailCapture::query()->exists())->toBeFalse()
        ->and(GameAttempt::query()->count())->toBe(1);
});

it('takes no email for an attempt an account has claimed meanwhile', function () {
    emailFlowGame($this->draft);
    $page = playToEmail($this->draft['game']->slug);
    GameAttempt::query()->update(['user_id' => User::factory()->create()->id]);

    $page->set('email', 'player@example.com')->call('submitEmail')->assertHasErrors(['email']);

    expect(GameEmailCapture::query()->exists())->toBeFalse();
});

it('passes a guest who signed in on a required email step on, asking no email', function () {
    emailFlowGame($this->draft, required: true);
    playToEmail($this->draft['game']->slug)->assertSee('Before your result');

    // What signing in does: the account signed in, the session's guest attempts claimed.
    $user = User::factory()->create();
    $this->actingAs($user);
    app(ClaimGuestGameAttemptsAction::class)->handle($user, GameSessionAttempts::all(session()->driver()), GameAccountOutcome::SignedIn);

    Livewire::test(PlayGame::class, ['slug' => $this->draft['game']->slug])
        ->assertHasNoErrors()
        ->assertSee('data-testid="game-step-full_result"', false);

    expect(GameEmailCapture::query()->exists())->toBeFalse();
});

it('guards every way on against a second click sent while the first is on its way', function () {
    emailFlowGame($this->draft);

    playToEmail($this->draft['game']->slug)
        ->assertSeeHtml('wire:click="continue" wire:loading.attr="disabled" data-testid="game-email-skip"')
        ->call('continue')->call('continue')
        ->assertSee('data-testid="game-step-registration"', false)
        ->assertSeeHtml('wire:click="continue" wire:loading.attr="disabled" data-testid="game-continue"');

    $shared = publishableGameDraft(1);
    replaceFlowSteps($shared['flow'], [GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::Share, GameFlowStepType::Feed]);
    liveGame($shared);

    playPastResult($shared['game']->slug)
        ->assertSee('data-testid="game-step-share"', false)
        ->assertSeeHtml('wire:click="continue" wire:loading.attr="disabled" data-testid="game-continue"');
});

it('offers the way out on a Share step that ends the flow', function () {
    replaceFlowSteps($this->draft['flow'], [GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::Share]);
    liveGame($this->draft);

    playPastResult($this->draft['game']->slug)
        ->assertSee('data-testid="game-step-share"', false)
        ->assertDontSee('data-testid="game-continue"', false)
        ->assertSee('data-testid="game-feed"', false)
        ->call('leave', 'feed')
        ->assertRedirect(route('feed'));
});

it('offers the next game only to an audience it gives a version to', function () {
    $next = publishableGameDraft(1);
    liveGame($next);
    app(ApplyGameTrafficAction::class)->handle($next['admin'], $next['game'], GameAudience::Anonymous, []);
    replaceFlowSteps($this->draft['flow'], [GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::NextGame], $next['game']);
    liveGame($this->draft);

    // A guest, whom the next game gives nothing to play: their result is the end.
    Livewire::withCookie((string) config('games.visitor_cookie.name'), str_repeat('g', 40))
        ->test(PlayGame::class, ['slug' => $this->draft['game']->slug])
        ->call('init')->call('continue')->call('choose', 0)->call('next')
        ->assertSee('data-testid="game-step-full_result"', false)
        ->assertDontSee('data-testid="game-continue"', false)
        ->assertSee('data-testid="game-feed"', false);

    // Another browser, signed in.
    session()->flush();
    $this->actingAs(User::factory()->create());

    Livewire::test(PlayGame::class, ['slug' => $this->draft['game']->slug])
        ->call('init')->call('continue')->call('choose', 0)->call('next')->call('continue')
        ->assertSee('data-testid="game-step-next_game"', false)
        ->assertSee('data-testid="game-next-game"', false);
});

it('counts leaving for a next game that went away as leaving for the feed, where the visitor is sent', function () {
    $next = publishableGameDraft(1);
    liveGame($next);
    replaceFlowSteps($this->draft['flow'], [GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult, GameFlowStepType::NextGame], $next['game']);
    liveGame($this->draft);

    $page = playPastResult($this->draft['game']->slug)->assertSee('data-testid="game-step-next_game"', false);
    $next['game']->fresh()->forceFill(['status' => GameStatus::Paused])->save();

    $page->call('leave', 'next')->assertRedirect(route('feed'));

    expect(GameEvent::query()->where('type', GameEventType::Exit)->sole()->step_type)->toBe('feed');
});
