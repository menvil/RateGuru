<?php

use App\Actions\Games\ChangeGameFlowAction;
use App\Enums\GameFlowStepType;
use App\Models\GameAttempt;
use App\Models\GameEmailCapture;
use App\Models\GameFlowStep;
use App\Models\ProjectSettings;
use App\Support\Games\Flow\GameFlowTemplate;

/**
 * A live game in a real browser: a guest plays it through at every width,
 * the page never wider than the screen; a refresh in the middle carries on
 * from the next pair; the email step keeps the address; and the sign-up
 * button opens the site's own sign-up dialog.
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

it('opens the site’s sign-up dialog from the result', function () {
    buttonPacedGame($this->draft);
    liveGame($this->draft);
    $page = visitGame($this->draft['game']->slug, 1280);

    $page->click('[data-testid="game-start"]');
    waitForScript($page, "!! document.querySelector('[data-testid=\"game-option-0\"]')", true, 10.0);
    pickInGame($page, 0);
    nextInGame($page, "document.querySelector('[data-testid=\"game-step-game\"]')?.dataset.round === '1'");
    pickInGame($page, 1);
    nextInGame($page, "!! document.querySelector('[data-testid=\"game-signup-register\"]')");

    $page->click('[data-testid="game-signup-register"]');
    waitForScript($page, 'getComputedStyle(document.querySelector(\'[data-testid="auth-modal"]\')).display !== "none"', true, 10.0);

    $page->assertVisible('[data-testid="auth-modal-register-panel"]');
    $page->assertNoJavaScriptErrors();
});
