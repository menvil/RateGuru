<?php

use App\Actions\Games\ChangeGameFlowAction;
use App\Actions\Games\ClaimGuestGameAttemptsAction;
use App\Enums\GameAccountOutcome;
use App\Enums\GameEventType;
use App\Enums\GameSignupMode;
use App\Livewire\Games\PlayGame;
use App\Livewire\Games\PreviewGame;
use App\Models\GameAttempt;
use App\Models\GameEvent;
use App\Models\GameFlowStep;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\Games\Flow\GameFlowTemplate;
use App\Support\Games\Play\GameSessionAttempts;
use App\Support\Urls\DisplayHost;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/*
 * The sign-up set into Full Result for an anonymous visitor: the email
 * first, or a provider, then a name and one password, posted to the site's
 * own registration and back to the game.
 */

beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->draft = publishableGameDraft(1);
});

function playToResult(string $slug): Testable
{
    return Livewire::withCookie((string) config('games.visitor_cookie.name'), str_repeat('s', 40))
        ->test(PlayGame::class, ['slug' => $slug])
        ->call('init')->call('continue')->call('choose', 0)->call('next')
        ->assertSee('data-testid="game-step-full_result"', false);
}

it('asks an anonymous visitor for the email first, then a name and one password, for the site\'s registration', function () {
    config()->set('services.google.client_id', null);
    config()->set('services.facebook.client_id', null);
    liveGame($this->draft);
    $slug = $this->draft['game']->slug;

    playToResult($slug)
        ->assertSeeHtmlInOrder([
            'action="'.route('register').'"',
            'data-testid="game-signup"',
            'name="_auth_surface" value="embedded"',
            'name="_auth_mode" value="register"',
            'name="_auth_return_to" value="/games/'.$slug.'"',
            'Keep playing',
            'name="email"',
            'Continue with email',
            'Already have an account?',
            'Almost there',
            'name="name"',
            'name="password"',
            'name="password_confirmation"',
            'Create account',
            // Before sharing: an account is what the screen is for.
            'data-testid="game-share"',
        ])
        ->assertDontSee('data-testid="game-signup-google"', false);
});

it('offers the providers the site signs people up with, coming back to the game', function () {
    config()->set('services.google.client_id', 'google-client-id');
    config()->set('services.google.client_secret', 'google-client-secret');
    config()->set('services.facebook.client_id', null);
    liveGame($this->draft);
    $slug = $this->draft['game']->slug;
    $redirect = e(route('auth.social.redirect', ['provider' => 'google', '_auth_surface' => 'embedded', '_auth_mode' => 'register', '_auth_return_to' => '/games/'.$slug]));

    playToResult($slug)
        ->assertSeeHtmlInOrder(['Continue with email', 'href="'.$redirect.'"', 'Continue with Google'])
        ->assertDontSee('Continue with Facebook');
});

it('sets the same sign-up into the Registration step, under its title, with its own button and log-in link', function () {
    config()->set('services.google.client_id', null);
    config()->set('services.facebook.client_id', null);
    $this->draft['flow'] = app(ChangeGameFlowAction::class)->create($this->draft['admin'], $this->draft['game'], 'Email first', GameFlowTemplate::EmailBeforeResult);
    liveGame($this->draft);

    Livewire::withCookie((string) config('games.visitor_cookie.name'), str_repeat('r', 40))
        ->test(PlayGame::class, ['slug' => $this->draft['game']->slug])
        ->call('init')->call('continue')->call('choose', 0)->call('next')
        ->call('continue')->call('continue')
        // Full Result leaves sign-up to the step after it.
        ->assertSee('data-testid="game-step-full_result"', false)
        ->assertDontSee('data-testid="game-signup"', false)
        ->call('continue')
        ->assertSeeHtmlInOrder([
            'data-testid="game-step-registration"',
            'Create your free account',
            'rg-game__signup rg-game__signup--plain',
            'name="email"',
            'Continue with email',
            'Already have an account? Log in',
            'name="password"',
            'Create free account',
            'data-testid="game-continue"',
        ])
        ->assertDontSee('Keep playing');
});

it('asks nothing on the result while its sign-up is off', function () {
    GameFlowStep::query()->where('flow_id', $this->draft['flow']->id)->where('type', 'full_result')->sole()
        ->forceFill(['settings' => ['signup' => GameSignupMode::None->value]])->save();
    liveGame($this->draft);

    playToResult($this->draft['game']->slug)->assertDontSee('data-testid="game-signup"', false);
});

it('never asks a logged-in player to sign up', function () {
    liveGame($this->draft);
    $this->actingAs(User::factory()->create());

    Livewire::test(PlayGame::class, ['slug' => $this->draft['game']->slug])
        ->call('init')->call('continue')->call('choose', 0)->call('next')
        ->assertSee('data-testid="game-step-full_result"', false)
        ->assertDontSee('data-testid="game-signup"', false);
});

it('counts how signing up began, and nothing it does not know', function () {
    liveGame($this->draft);

    playToResult($this->draft['game']->slug)
        ->call('signUpStarted', 'email')
        ->call('signUpStarted', 'google')
        ->call('signUpStarted', 'myspace')
        ->call('signUp', 'login');

    expect(GameEvent::query()->where('type', GameEventType::RegistrationCtaClicked)->orderBy('id')->pluck('provider')->all())
        ->toBe(['email', 'google', 'login']);
});

it('brings a failed sign-up back on the step its error belongs to, what was typed kept but the password', function (array $input, string $step, array $message) {
    User::factory()->create(['email' => 'taken@example.test']);
    $page = route('games.preview', ['game' => $this->draft['game']->id, 'start' => 'result:1', 'visitor' => 'anonymous'], absolute: false);

    $this->post('/register', [
        'name' => 'Ivan',
        'email' => 'ivan@example.test',
        'password' => 'a-long-enough-password',
        ...$input,
        '_auth_surface' => 'embedded',
        '_auth_mode' => 'register',
        '_auth_return_to' => $page,
    ])->assertRedirect($page);

    // Followed straight away, the way a browser does, by the admin the preview is for.
    $html = $this->actingAs($this->draft['admin'])->get($page)->assertOk()->getContent();
    $email = $input['email'] ?? 'ivan@example.test';

    expect($html)->toContain("x-data=\"{ step: '{$step}', email: '{$email}'")
        ->toContain(e(trans($message[0], $message[1])))
        ->toContain('value="'.($input['name'] ?? 'Ivan').'"')
        ->not->toContain('a-long-enough-password')
        ->not->toContain('data-auth-modal-open="true"');
})->with([
    'a taken email, on the email' => [['email' => 'taken@example.test', 'password_confirmation' => 'a-long-enough-password'], 'email', ['validation.unique', ['attribute' => 'email']]],
    'a short password, on the name and password' => [['password' => 'short', 'password_confirmation' => 'short'], 'details', ['validation.min.string', ['attribute' => 'password', 'min' => 8]]],
]);

it('signs nobody up in a preview', function () {
    $this->actingAs($this->draft['admin']);

    Livewire::withQueryParams(['start' => 'result:1', 'visitor' => 'anonymous'])->test(PreviewGame::class, ['game' => $this->draft['game']->id])
        ->assertSee('data-testid="game-signup"', false)
        ->assertSee('else { $event.preventDefault(); $wire.signUp(&#039;register&#039;) }', false)
        ->call('signUpStarted', 'email')
        ->call('signUp', 'register')
        ->assertDispatched('toast');

    expect(GameEvent::query()->exists())->toBeFalse();
});

it('puts a logged-in player\'s @name on the card, and shares their own result', function () {
    liveGame($this->draft);
    $this->actingAs(User::factory()->create(['username' => 'ivan']));

    $page = Livewire::test(PlayGame::class, ['slug' => $this->draft['game']->slug])
        ->call('init')->call('continue')->call('choose', 0)->call('next');
    $attempt = GameAttempt::query()->sole();

    $page->assertSeeHtml('data-testid="game-byline">@ivan<')
        ->assertSeeHtml(e(route('games.player-result', ['slug' => $this->draft['game']->slug, 'attempt' => $attempt->public_id])))
        ->assertSeeHtml(e(route('games.player-share', ['slug' => $this->draft['game']->slug, 'attempt' => $attempt->public_id, 'provider' => 'x'])))
        ->assertDontSeeHtml('data-testid="game-named"');
});

it('tells a guest who signed up from the result that their name is on the card now', function () {
    liveGame($this->draft);
    $slug = $this->draft['game']->slug;

    playToResult($slug)->assertSeeHtml('data-testid="game-byline">'.DisplayHost::of(url('/')).'<');

    // What registering does: the account signed in, the session's guest attempts claimed.
    $user = User::factory()->create(['username' => 'newbie']);
    $this->actingAs($user);
    app(ClaimGuestGameAttemptsAction::class)->handle($user, GameSessionAttempts::all(session()->driver()), GameAccountOutcome::Registered);

    Livewire::test(PlayGame::class, ['slug' => $slug])
        ->assertSeeHtml('data-testid="game-byline">@newbie<')
        ->assertSee('Your name is on the card now: @newbie')
        ->assertDontSeeHtml('data-testid="game-signup"');
});
