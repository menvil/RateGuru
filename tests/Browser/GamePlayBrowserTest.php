<?php

use App\Actions\Games\ChangeGameFlowAction;
use App\Enums\GameAccountOutcome;
use App\Enums\GameEventType;
use App\Enums\GameFlowStepType;
use App\Models\GameAttempt;
use App\Models\GameEmailCapture;
use App\Models\GameEvent;
use App\Models\GameFlowStep;
use App\Models\ProjectSettings;
use App\Models\User;
use App\Support\Games\Flow\GameFlowTemplate;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;

/**
 * A live game in a real browser: a guest plays it through at every width,
 * the page never wider than the screen; a refresh in the middle carries on
 * from the next pair; the email step keeps the address; a guest signs up on
 * the result in two steps and comes back to it signed in, the result theirs;
 * and logging in instead opens the site's own dialog.
 *
 * Each round waits for its Next button, so no timer races a click.
 */
function buttonPacedGame(array $draft): void
{
    GameFlowStep::query()->where('flow_id', $draft['flow']->id)->where('type', GameFlowStepType::Game->value)->get()
        ->each(fn (GameFlowStep $step) => $step->forceFill(['settings' => [...$step->settings, 'advance' => 'button']])->save());
}

function visitGame(string $slug, int $width = 1440): mixed
{
    $page = visit('/games/'.$slug)->resize($width, 900);

    waitForScript($page, "!! window.Livewire && window.Livewire.all().length > 0 && !! document.querySelector('[data-testid=\"game-page\"]')", true, 10.0);

    return $page;
}

/** Picks an option and waits for the reveal's Next button. */
function pickInGame(mixed $page, int $position): void
{
    $page->click('[data-testid="game-option-'.$position.'"]');
    waitForScript($page, "!! document.querySelector('[data-testid=\"game-next\"]')", true, 10.0);
}

function nextInGame(mixed $page, string $until): void
{
    $page->click('[data-testid="game-next"]');
    waitForScript($page, $until, true, 10.0);
}

beforeEach(function () {
    ProjectSettings::factory()->create();
    $this->draft = publishableGameDraft(2);
});

it('lets a guest play to the result at every width, never wider than the screen', function (int $width) {
    buttonPacedGame($this->draft);
    liveGame($this->draft);
    $page = visitGame($this->draft['game']->slug, $width);

    $page->assertVisible('[data-testid="game-step-landing"]');
    $page->click('[data-testid="game-start"]');
    waitForScript($page, "document.querySelector('[data-testid=\"game-step-game\"]')?.dataset.round", '0', 10.0);

    expect($page->script('document.documentElement.scrollWidth > window.innerWidth'))->toBeFalse();

    pickInGame($page, 0);
    nextInGame($page, "document.querySelector('[data-testid=\"game-step-game\"]')?.dataset.round === '1'");
    pickInGame($page, 0);
    nextInGame($page, "!! document.querySelector('[data-testid=\"game-profile\"]')");

    expect($page->script("document.querySelector('[data-testid=\"game-score\"]').textContent.trim()"))->toContain('2 of 2')
        ->and($page->script('document.documentElement.scrollWidth > window.innerWidth'))->toBeFalse()
        ->and(GameAttempt::query()->sole()->isCompleted())->toBeTrue();

    $page->assertNoJavaScriptErrors();
})->with([1440, 1280, 1024, 390]);

it('carries on from the next pair after a refresh in the middle of a game', function () {
    buttonPacedGame($this->draft);
    liveGame($this->draft);
    $page = visitGame($this->draft['game']->slug, 390);

    $page->click('[data-testid="game-start"]');
    waitForScript($page, "!! document.querySelector('[data-testid=\"game-option-0\"]')", true, 10.0);
    pickInGame($page, 1);

    $page = visitGame($this->draft['game']->slug, 390);
    waitForScript($page, "document.querySelector('[data-testid=\"game-step-game\"]')?.dataset.round", '1', 10.0);

    expect(GameAttempt::query()->count())->toBe(1)
        ->and(GameAttempt::query()->sole()->rounds_answered)->toBe(1);

    $page->assertNoJavaScriptErrors();
});

it('keeps the email a guest leaves before the result, sending nothing', function () {
    $flow = app(ChangeGameFlowAction::class)->create($this->draft['admin'], $this->draft['game'], 'Email first', GameFlowTemplate::EmailBeforeResult);
    $this->draft['flow'] = $flow;
    buttonPacedGame($this->draft);
    liveGame($this->draft);
    $page = visitGame($this->draft['game']->slug, 390);

    $page->click('[data-testid="game-start"]');
    waitForScript($page, "!! document.querySelector('[data-testid=\"game-option-0\"]')", true, 10.0);
    pickInGame($page, 0);
    nextInGame($page, "document.querySelector('[data-testid=\"game-step-game\"]')?.dataset.round === '1'");
    pickInGame($page, 0);
    // The result preview, then the email step.
    nextInGame($page, "!! document.querySelector('[data-testid=\"game-step-result_preview\"]')");
    $page->click('[data-testid="game-step-result_preview"] [data-testid="game-continue"]');
    waitForScript($page, "!! document.querySelector('[data-testid=\"game-email\"]')", true, 10.0);

    $page->type('[data-testid="game-email"]', 'guest@example.test');
    $page->click('[data-testid="game-email-submit"]');
    waitForScript($page, "!! document.querySelector('[data-testid=\"game-profile\"]')", true, 10.0);

    expect(GameEmailCapture::query()->sole()->email)->toBe('guest@example.test')
        ->and(GameEmailCapture::query()->sole()->marketing_consent)->toBeFalse();

    $page->assertNoJavaScriptErrors();
});

it('signs a guest up on the result in two steps, back on it signed in, the result theirs and their name on the card', function () {
    // The browser test server runs inside this process, so the guard hands the
    // very instance RegisterUserAction created to the next request — without
    // the column defaults (status) a real request reads back from the database.
    Event::listen(Registered::class, fn (Registered $event) => $event->user->refresh());
    buttonPacedGame($this->draft);
    liveGame($this->draft);
    $page = visitGame($this->draft['game']->slug, 390);

    $page->click('[data-testid="game-start"]');
    waitForScript($page, "!! document.querySelector('[data-testid=\"game-option-0\"]')", true, 10.0);
    pickInGame($page, 0);
    nextInGame($page, "document.querySelector('[data-testid=\"game-step-game\"]')?.dataset.round === '1'");
    pickInGame($page, 1);
    nextInGame($page, "!! document.querySelector('[data-testid=\"game-signup-email\"]')");

    $page->type('[data-testid="game-signup-email"]', 'new.player@example.test');
    $page->click('[data-testid="game-signup-continue"]');
    waitForScript($page, "document.querySelector('[data-testid=\"game-signup\"]').dataset.signupStep === 'details'", true, 10.0);
    $page->assertSee('new.player@example.test');

    $page->type('[data-testid="game-signup-name"]', 'New Player');
    $page->type('[data-testid="game-signup-password"]', 'a-long-enough-password');
    $page->click('[data-testid="game-signup-submit"]');
    waitForScript($page, "!! document.querySelector('[data-testid=\"game-step-full_result\"]') && ! document.querySelector('[data-testid=\"game-signup\"]')", true, 15.0);

    $user = User::query()->where('email', 'new.player@example.test')->sole();
    $attempt = GameAttempt::query()->sole();

    expect($user->name)->toBe('New Player')
        ->and($attempt->user_id)->toBe($user->id)
        ->and($attempt->account_outcome)->toBe(GameAccountOutcome::Registered)
        ->and(GameEvent::query()->where('type', GameEventType::RegistrationCtaClicked)->pluck('provider')->all())->toBe(['email']);

    // Their name on the card now, where the site's address was.
    $page->assertSee('Your name is on the card now: @'.$user->username)
        ->assertSeeIn('[data-testid="game-byline"]', '@'.$user->username);
    $page->assertNoJavaScriptErrors();
});

it('opens the site’s log-in dialog from the result instead', function () {
    buttonPacedGame($this->draft);
    liveGame($this->draft);
    $page = visitGame($this->draft['game']->slug, 1280);

    $page->click('[data-testid="game-start"]');
    waitForScript($page, "!! document.querySelector('[data-testid=\"game-option-0\"]')", true, 10.0);
    pickInGame($page, 0);
    nextInGame($page, "document.querySelector('[data-testid=\"game-step-game\"]')?.dataset.round === '1'");
    pickInGame($page, 1);
    nextInGame($page, "!! document.querySelector('[data-testid=\"game-signup-login\"]')");

    $page->click('[data-testid="game-signup-login"]');
    waitForScript($page, 'getComputedStyle(document.querySelector(\'[data-testid="auth-modal"]\')).display !== "none"', true, 10.0);

    $page->assertVisible('[data-testid="auth-modal-login-panel"]');
    $page->assertNoJavaScriptErrors();
});
