<?php

use App\Actions\Games\ChangeGameFlowAction;
use App\Actions\Profile\AnonymizeUserAccountAction;
use App\Enums\GameEventType;
use App\Livewire\Games\PlayGame;
use App\Models\GameAttempt;
use App\Models\GameEmailCapture;
use App\Models\GameEvent;
use App\Models\GameFlowStep;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\Games\Flow\GameFlowTemplate;
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
    playToEmail($this->draft['game']->slug)->set('email', 'player@example.com')->call('submitEmail');
    $user = User::factory()->create();
    GameAttempt::query()->update(['user_id' => $user->id]);

    app(AnonymizeUserAccountAction::class)->execute($user);

    expect(GameEmailCapture::query()->exists())->toBeFalse()
        ->and(GameAttempt::query()->count())->toBe(1);
});
