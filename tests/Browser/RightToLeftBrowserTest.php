<?php

use App\Models\GameAnswer;
use App\Models\GameAttempt;
use App\Models\Post;
use App\Models\ProjectSettings;

/*
 * The public site in a language read right to left, in a real browser, at a
 * phone's width and a desktop's: each page is laid out right to left — the
 * sidebar, the menu button and the drawer on the right — nothing pushes it
 * wider than the screen, and no script fails (checked after every test). A
 * game plays through to its result with the pair drawn the other way round,
 * and each pick is recorded as the option picked, not the side it was on.
 *
 * The language is the one the browser asks for (Accept-Language), as on a
 * first visit.
 */

beforeEach(function () {
    ProjectSettings::factory()->create();
    offerLocales(rightToLeftLocales());
    [$this->locale] = rightToLeftLocales();
});

/** A page of the site asked for in this language, at this width, laid out and with its fonts in. */
function visitInLanguage(string $url, string $locale, int $width): mixed
{
    $page = visit($url)->withLocale($locale)->resize($width, 900);

    waitForViewportSize($page, $width, 900);

    return $page;
}

/**
 * The direction the page is laid out in, as its root says it and as the body
 * is drawn, and how far it runs past the right or left edge of the screen.
 *
 * @return array{dir: ?string, direction: string, overflow: int}
 */
function pageDirection(mixed $page): array
{
    return $page->script(<<<'JS'
        (() => ({
            dir: document.documentElement.getAttribute('dir'),
            direction: getComputedStyle(document.body).direction,
            overflow: document.documentElement.scrollWidth - window.innerWidth,
        }))()
    JS);
}

/** Where an element is across the screen: its left and right edges, and the screen's width. */
function horizontalBox(mixed $page, string $selector): array
{
    return $page->script(<<<JS
        (() => {
            const box = document.querySelector('{$selector}').getBoundingClientRect();

            return { left: Math.round(box.left), right: Math.round(box.right), top: Math.round(box.top), viewport: window.innerWidth };
        })()
    JS);
}

it('lays the feed out right to left, the sidebar on the right', function () {
    Post::factory()->published()->count(3)->create();

    $page = visitInLanguage(route('feed'), $this->locale, 1440);

    expect(pageDirection($page))->toBe(['dir' => 'rtl', 'direction' => 'rtl', 'overflow' => 0])
        ->and(horizontalBox($page, '[data-testid="app-sidebar"]')['left'])
        ->toBeGreaterThan(horizontalBox($page, '[data-testid="app-main"]')['left']);
});

it('lays the feed out right to left on a phone, the menu button and its drawer on the right', function () {
    Post::factory()->published()->count(3)->create();

    $page = visitInLanguage(route('feed'), $this->locale, 390);

    expect(pageDirection($page))->toBe(['dir' => 'rtl', 'direction' => 'rtl', 'overflow' => 0]);

    $trigger = horizontalBox($page, '[data-testid="mobile-nav-trigger"]');
    expect($trigger['right'])->toBeGreaterThan(390 - 40);

    $page->click('[data-testid="mobile-nav-trigger"]');
    waitForScript($page, <<<'JS'
        (() => {
            const panel = document.querySelector('[data-testid="mobile-nav-panel"]');

            return panel.checkVisibility() && panel.getAnimations().every((animation) => animation.playState === 'finished');
        })()
    JS);

    $drawer = horizontalBox($page, '[data-testid="mobile-nav-panel"]');
    expect($drawer['right'])->toBe(390)
        ->and($drawer['left'])->toBeGreaterThan(0)
        ->and(pageDirection($page)['overflow'])->toBe(0);
});

it('lays a post out right to left at every width, never wider than the screen', function (int $width) {
    $post = Post::factory()->published()->create(['title' => 'A post read right to left']);

    $page = visitInLanguage(route('posts.show', $post), $this->locale, $width);
    $page->assertSee('A post read right to left');

    expect(pageDirection($page))->toBe(['dir' => 'rtl', 'direction' => 'rtl', 'overflow' => 0]);
})->with([390, 1440]);

it('lays the sign-in page out right to left at every width, never wider than the screen', function (int $width) {
    $page = visitInLanguage(route('login'), $this->locale, $width);

    $field = $page->script("getComputedStyle(document.querySelector('input[type=\"email\"]')).direction");

    expect(pageDirection($page))->toBe(['dir' => 'rtl', 'direction' => 'rtl', 'overflow' => 0])
        ->and($field)->toBe('rtl');
})->with([390, 1440]);

it('plays a game through right to left, recording each pick as the option picked', function (int $width, bool $sideBySide) {
    $draft = publishableGameDraft(2);
    buttonPacedGame($draft);
    liveGame($draft);

    $page = visitGame($draft['game']->slug, $width, $this->locale);
    $page->click('[data-testid="game-start"]');
    waitForScript($page, "document.querySelector('[data-testid=\"game-step-game\"]')?.dataset.round", '0', 10.0);

    expect(pageDirection($page))->toBe(['dir' => 'rtl', 'direction' => 'rtl', 'overflow' => 0]);

    // Side by side, the pair the other way round: the first option on the
    // right. A wide pair on a phone stands one above the other, the first on
    // top, whichever way the line runs.
    $first = horizontalBox($page, '[data-testid="game-option-0"]');
    $second = horizontalBox($page, '[data-testid="game-option-1"]');

    if ($sideBySide) {
        expect($first['top'])->toBe($second['top'])
            ->and($first['left'])->toBeGreaterThanOrEqual($second['right']);
    } else {
        expect($first['top'])->toBeLessThan($second['top']);
    }

    // The first option, wherever it is drawn, is recorded as the first.
    pickInGame($page, 0);
    expect(GameAnswer::query()->sole()->option_position)->toBe(0);

    nextInGame($page, "document.querySelector('[data-testid=\"game-step-game\"]')?.dataset.round === '1'");

    // And the second as the second.
    pickInGame($page, 1);
    expect(GameAnswer::query()->where('round_index', 1)->sole()->option_position)->toBe(1);

    nextInGame($page, "!! document.querySelector('[data-testid=\"game-profile\"]')");

    expect(pageDirection($page))->toBe(['dir' => 'rtl', 'direction' => 'rtl', 'overflow' => 0])
        ->and(GameAttempt::query()->sole()->isCompleted())->toBeTrue()
        ->and(GameAttempt::query()->sole()->rounds_answered)->toBe(2);
})->with([
    'a phone, the wide pair stacked' => [390, false],
    'a desktop, the pair side by side' => [1440, true],
]);

it('draws the pair in reading order left to right, the first option on the left', function () {
    $draft = publishableGameDraft(2);
    buttonPacedGame($draft);
    liveGame($draft);

    $page = visitGame($draft['game']->slug, 1440, config('locales.default'));
    $page->click('[data-testid="game-start"]');
    waitForScript($page, "document.querySelector('[data-testid=\"game-step-game\"]')?.dataset.round", '0', 10.0);

    expect(pageDirection($page))->toBe(['dir' => 'ltr', 'direction' => 'ltr', 'overflow' => 0])
        ->and(horizontalBox($page, '[data-testid="game-option-0"]')['right'])
        ->toBeLessThanOrEqual(horizontalBox($page, '[data-testid="game-option-1"]')['left']);
});
