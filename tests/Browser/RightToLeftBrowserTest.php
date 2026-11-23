<?php

use App\Models\Comment;
use App\Models\GameAnswer;
use App\Models\GameAttempt;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\User;

use function Pest\Laravel\actingAs;

/*
 * The public site in each language read right to left, in a real browser, at a
 * phone's width and a desktop's: each page is laid out right to left — the
 * sidebar, the menu button and the drawer on the right, a dialog's close button
 * and a form's button at the end of their line on the left — nothing pushes it
 * wider than the screen, the signs that point along the line turn round and no
 * other does, the type is the script's own, and no script fails (checked after
 * every test). A game plays through to its result with the pair drawn the other
 * way round, and each pick is recorded as the option picked, not the side it
 * was on. The same pages in the default language stay as they were, left to
 * right.
 *
 * The language is the one the browser asks for (Accept-Language), as on a
 * first visit, or the one on a signed-in reader's account.
 */

beforeEach(function () {
    ProjectSettings::factory()->create();
    offerLocales(rightToLeftLocales());
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
 * is drawn, and how far it runs past the edge of the screen: the right edge on
 * a page read left to right, the left one on a page read right to left, which
 * is where a page scrolls to when something pushes it wider.
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
            const element = [...document.querySelectorAll('{$selector}')].find((candidate) => candidate.checkVisibility());
            const box = element.getBoundingClientRect();

            return { left: Math.round(box.left), right: Math.round(box.right), top: Math.round(box.top), viewport: window.innerWidth };
        })()
    JS);
}

/**
 * The drawings on the page as they are drawn: how many of the visible ones
 * that point along the line (rg-mirror-rtl) are turned round and how many are
 * not, and how many of the others are turned at all.
 *
 * @return array{directional: int, directionalTurned: int, otherTurned: int}
 */
function drawnIcons(mixed $page): array
{
    return $page->script(<<<'JS'
        (() => {
            const visible = [...document.querySelectorAll('svg')].filter((svg) => svg.checkVisibility());
            const turned = (svg) => getComputedStyle(svg).scale === '-1 1';
            const directional = visible.filter((svg) => svg.classList.contains('rg-mirror-rtl'));

            return {
                directional: directional.length,
                directionalTurned: directional.filter(turned).length,
                otherTurned: visible.filter((svg) => ! svg.classList.contains('rg-mirror-rtl') && getComputedStyle(svg).scale !== 'none').length,
            };
        })()
    JS);
}

it('lays the feed out right to left, the sidebar and the brand on the right', function (string $locale) {
    Post::factory()->published()->count(3)->create();

    $page = visitInLanguage(route('feed'), $locale, 1440);

    expect(pageDirection($page))->toBe(['dir' => 'rtl', 'direction' => 'rtl', 'overflow' => 0])
        ->and(horizontalBox($page, '[data-testid="app-sidebar"]')['left'])
        ->toBeGreaterThan(horizontalBox($page, '[data-testid="app-main"]')['left'])
        // The brand is a Latin name reading its own way, at the start of the
        // header all the same.
        ->and(horizontalBox($page, '[data-testid="site-brand"] > span')['right'])->toBeGreaterThan(1440 - 60);
})->with(rightToLeftLocales());

it('lays the feed out left to right in the default language, the sidebar and the brand on the left', function () {
    Post::factory()->published()->count(3)->create();

    $page = visitInLanguage(route('feed'), config('locales.default'), 1440);

    expect(pageDirection($page))->toBe(['dir' => 'ltr', 'direction' => 'ltr', 'overflow' => 0])
        ->and(horizontalBox($page, '[data-testid="app-sidebar"]')['right'])
        ->toBeLessThanOrEqual(horizontalBox($page, '[data-testid="app-main"]')['left'])
        ->and(horizontalBox($page, '[data-testid="site-brand"] > span')['left'])->toBeLessThan(60);
});

it('lays the feed out right to left on a phone, the menu button and its drawer on the right', function (string $locale) {
    Post::factory()->published()->count(3)->create();

    $page = visitInLanguage(route('feed'), $locale, 390);

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
})->with(rightToLeftLocales());

it('lays the feed out left to right on a phone in the default language, the menu button and its drawer on the left', function () {
    Post::factory()->published()->count(3)->create();

    $page = visitInLanguage(route('feed'), config('locales.default'), 390);

    expect(horizontalBox($page, '[data-testid="mobile-nav-trigger"]')['left'])->toBeLessThan(40);

    $page->click('[data-testid="mobile-nav-trigger"]');
    waitForScript($page, <<<'JS'
        (() => {
            const panel = document.querySelector('[data-testid="mobile-nav-panel"]');

            return panel.checkVisibility() && panel.getAnimations().every((animation) => animation.playState === 'finished');
        })()
    JS);

    $drawer = horizontalBox($page, '[data-testid="mobile-nav-panel"]');
    expect($drawer['left'])->toBe(0)
        ->and($drawer['right'])->toBeLessThan(390)
        ->and(pageDirection($page))->toBe(['dir' => 'ltr', 'direction' => 'ltr', 'overflow' => 0]);
});

it('draws each language read right to left in its own script\'s faces, the default language in the site\'s own', function (string $locale) {
    $fonts = "(() => { const root = getComputedStyle(document.documentElement); return [getComputedStyle(document.body).fontFamily, root.getPropertyValue('--font-mono').trim()]; })()";
    // The faces a monospaced stack lists after its Latin ones and before Courier New.
    $facesBeforeCourier = function (string $stack): array {
        $faces = array_map(fn (string $face): string => trim($face, ' "\''), explode(',', $stack));
        $from = array_search('Liberation Mono', $faces, true);
        $to = array_search('Courier New', $faces, true);

        return array_slice($faces, $from + 1, $to - $from - 1);
    };

    [$defaultSans, $defaultMono] = visitInLanguage(route('feed'), config('locales.default'), 1440)->script($fonts);
    [$sans, $mono] = visitInLanguage(route('feed'), $locale, 1440)->script($fonts);

    // Inter first for a Latin name or a number, then the script's faces —
    // before the generic ones, and in the monospaced face before Courier New,
    // which would draw joined letters each in a cell of its own.
    expect($defaultSans)->toStartWith('"Inter Variable", ui-sans-serif')
        ->and($sans)->toStartWith('"Inter Variable"')
        ->and($sans)->not->toBe($defaultSans)
        ->and($facesBeforeCourier($mono))->not->toBe([])
        ->and($facesBeforeCourier($defaultMono))->toBe([]);
})->with(rightToLeftLocales());

it('spaces Urdu\'s Nastaliq lines to hold it, at every text size', function () {
    $leading = <<<'JS'
        (() => {
            const probe = document.createElement('p');
            probe.className = 'text-sm';
            probe.textContent = 'x';
            document.body.append(probe);
            const style = getComputedStyle(probe);
            const ratio = parseFloat(style.lineHeight) / parseFloat(style.fontSize);
            probe.remove();

            return ratio;
        })()
    JS;

    expect(visitInLanguage(route('feed'), 'ur', 1440)->script($leading))->toBe(2)
        ->and(visitInLanguage(route('feed'), config('locales.default'), 1440)->script($leading))->toBeLessThan(2);
});

it('turns the signs that point along the line round on a right-to-left page, and no other', function (string $locale) {
    $post = Post::factory()->published()->create();
    Comment::factory()->for($post)->for(User::factory())->create();
    actingAs(User::factory()->create(['locale' => $locale]));

    $page = visitInLanguage(route('posts.show', $post), $locale, 1440);
    $icons = drawnIcons($page);

    // The trend in the sidebar and a comment's reply arrow among them.
    expect($icons['directional'])->toBeGreaterThanOrEqual(2)
        ->and($icons['directionalTurned'])->toBe($icons['directional'])
        ->and($icons['otherTurned'])->toBe(0);
})->with(rightToLeftLocales());

it('turns no sign round on a page in the default language', function () {
    $post = Post::factory()->published()->create();
    Comment::factory()->for($post)->for(User::factory())->create();
    actingAs(User::factory()->create(['locale' => config('locales.default')]));

    $icons = drawnIcons(visitInLanguage(route('posts.show', $post), config('locales.default'), 1440));

    expect($icons['directional'])->toBeGreaterThanOrEqual(2)
        ->and($icons['directionalTurned'])->toBe(0)
        ->and($icons['otherTurned'])->toBe(0);
});

it('lays a post out right to left at every width, never wider than the screen', function (string $locale, int $width) {
    $post = Post::factory()->published()->create(['title' => 'A post read right to left']);

    $page = visitInLanguage(route('posts.show', $post), $locale, $width);
    $page->assertSee('A post read right to left');

    expect(pageDirection($page))->toBe(['dir' => 'rtl', 'direction' => 'rtl', 'overflow' => 0]);
})->with(rightToLeftLocales())->with([390, 1440]);

it('takes a comment in a right-to-left language, its button at the end of the line', function (string $locale, int $width) {
    $post = Post::factory()->published()->create();
    actingAs(User::factory()->create(['locale' => $locale]));

    $page = visitInLanguage(route('posts.show', $post), $locale, $width);

    $field = horizontalBox($page, '[data-testid="comment-body"]');
    $submit = horizontalBox($page, '[data-testid="comment-submit"]');

    expect($submit['right'])->toBeLessThanOrEqual($field['left'])
        ->and($page->script("getComputedStyle(document.querySelector('[data-testid=\"comment-body\"]')).direction"))->toBe('rtl');

    $page->type('[data-testid="comment-body"]', 'تعليق من اليمين إلى اليسار');
    $page->click('[data-testid="comment-submit"]');
    $page->waitForText('تعليق من اليمين إلى اليسار');

    expect(Comment::query()->sole()->body)->toBe('تعليق من اليمين إلى اليسار')
        ->and(pageDirection($page))->toBe(['dir' => 'rtl', 'direction' => 'rtl', 'overflow' => 0]);
})->with(rightToLeftLocales())->with([390, 1440]);

it('keeps the comment form left to right in the default language, its button on the right', function () {
    $post = Post::factory()->published()->create();
    actingAs(User::factory()->create(['locale' => config('locales.default')]));

    $page = visitInLanguage(route('posts.show', $post), config('locales.default'), 1440);

    expect(horizontalBox($page, '[data-testid="comment-submit"]')['left'])
        ->toBeGreaterThanOrEqual(horizontalBox($page, '[data-testid="comment-body"]')['right']);
});

it('opens the sign-up dialog right to left, its close button at the end of the title', function (string $locale, int $width, array $opens) {
    $page = visitInLanguage(route('feed'), $locale, $width);

    foreach ($opens as $control) {
        $page->click($control);
    }

    waitForScript($page, 'document.querySelector(\'[data-testid="auth-modal-title-register"]\').checkVisibility()');

    $title = horizontalBox($page, '[data-testid="auth-modal-title-register"]');
    $close = horizontalBox($page, '[data-testid="auth-modal"] [data-testid="modal-close"]');
    $dialog = horizontalBox($page, '[data-testid="auth-modal"] [data-modal-panel]');

    expect($close['right'])->toBeLessThanOrEqual($title['left'])
        ->and($dialog['left'])->toBeGreaterThanOrEqual(0)
        ->and($dialog['right'])->toBeLessThanOrEqual($width)
        ->and($page->script("getComputedStyle(document.querySelector('[data-testid=\"auth-modal-register-panel\"] input[name=\"name\"]')).direction"))->toBe('rtl')
        ->and(pageDirection($page))->toBe(['dir' => 'rtl', 'direction' => 'rtl', 'overflow' => 0]);
})->with(rightToLeftLocales())->with([
    // A phone's header offers Log in only: sign-up is the sign-in dialog's switch.
    'phone' => [390, ['[data-testid="header-login-link"]', '[data-testid="auth-modal"] [data-testid="auth-switch-to-register"]']],
    'desktop' => [1440, ['[data-testid="header-register-link"]']],
]);

it('opens the sign-up dialog left to right in the default language, its close button on the right', function () {
    $page = visitInLanguage(route('feed'), config('locales.default'), 1440);

    $page->click('[data-testid="header-register-link"]');
    waitForScript($page, 'getComputedStyle(document.querySelector(\'[data-testid="auth-modal"]\')).display !== "none"');

    expect(horizontalBox($page, '[data-testid="auth-modal"] [data-testid="modal-close"]')['left'])
        ->toBeGreaterThanOrEqual(horizontalBox($page, '[data-testid="auth-modal-title-register"]')['right']);
});

it('turns the page round as the language switcher changes to a language read the other way, and back', function (string $locale) {
    $page = visitInLanguage(route('feed'), $locale, 1440);
    $switcher = "[...document.querySelectorAll('[data-testid=\"language-switcher\"]')].find((switcher) => switcher.checkVisibility())";

    $page->script("{$switcher}.querySelector('[data-testid=\"locale-switcher-trigger\"]').click()");
    waitForScript($page, "{$switcher}.querySelector('[data-testid=\"locale-switcher-menu\"]').checkVisibility()");

    // The list opens under its button, from the end of the line, inside the screen.
    $trigger = horizontalBox($page, '[data-testid="locale-switcher-trigger"]');
    $menu = horizontalBox($page, '[data-testid="locale-switcher-menu"]');

    expect(abs($menu['left'] - $trigger['left']))->toBeLessThanOrEqual(1)
        ->and($menu['right'])->toBeLessThanOrEqual(1440);

    $default = config('locales.default');
    $page->script("{$switcher}.querySelector('[data-testid=\"locale-option-{$default}\"]').click()");
    waitForScript($page, "document.documentElement.getAttribute('dir') === 'ltr' && document.readyState === 'complete'");

    expect(pageDirection($page))->toBe(['dir' => 'ltr', 'direction' => 'ltr', 'overflow' => 0]);

    $page->script("{$switcher}.querySelector('[data-testid=\"locale-switcher-trigger\"]').click()");
    waitForScript($page, "{$switcher}.querySelector('[data-testid=\"locale-switcher-menu\"]').checkVisibility()");
    $page->script("{$switcher}.querySelector('[data-testid=\"locale-option-{$locale}\"]').click()");
    waitForScript($page, "document.documentElement.getAttribute('dir') === 'rtl' && document.readyState === 'complete'");

    expect(pageDirection($page))->toBe(['dir' => 'rtl', 'direction' => 'rtl', 'overflow' => 0])
        ->and($page->script('document.documentElement.lang'))->toBe($locale);
})->with(rightToLeftLocales());

it('draws a select\'s chevron at the end of the line and keeps an address field left to right', function (string $locale) {
    $controls = <<<'JS'
        (() => ({
            chevronFromTheRight: getComputedStyle(document.querySelector('#locale')).backgroundPositionX.startsWith('calc(100%'),
            address: getComputedStyle(document.querySelector('#edit-website')).direction,
        }))()
    JS;

    actingAs(User::factory()->create(['locale' => $locale]));
    $page = visitInLanguage(route('profile.edit'), $locale, 1440);

    // On the left, where the chosen option's line ends: on the right it covered its first letters.
    expect($page->script($controls))->toBe(['chevronFromTheRight' => false, 'address' => 'ltr'])
        ->and(pageDirection($page))->toBe(['dir' => 'rtl', 'direction' => 'rtl', 'overflow' => 0]);

    actingAs(User::factory()->create(['locale' => config('locales.default')]));
    $page = visitInLanguage(route('profile.edit'), config('locales.default'), 1440);

    expect($page->script($controls))->toBe(['chevronFromTheRight' => true, 'address' => 'ltr']);
})->with(rightToLeftLocales());

it('lays the sign-in page out right to left at every width, never wider than the screen', function (string $locale, int $width) {
    $page = visitInLanguage(route('login'), $locale, $width);

    $field = $page->script("getComputedStyle(document.querySelector('input[type=\"email\"]')).direction");

    expect(pageDirection($page))->toBe(['dir' => 'rtl', 'direction' => 'rtl', 'overflow' => 0])
        ->and($field)->toBe('rtl');
})->with(rightToLeftLocales())->with([390, 1440]);

it('plays a game through right to left, recording each pick as the option picked', function (string $locale, int $width, bool $sideBySide) {
    $draft = publishableGameDraft(2);
    buttonPacedGame($draft);
    liveGame($draft);

    $page = visitGame($draft['game']->slug, $width, $locale);
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
})->with(rightToLeftLocales())->with([
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
