<?php

use App\Enums\GameFlowStepType;
use App\Models\Comment;
use App\Models\GameAnswer;
use App\Models\GameAttempt;
use App\Models\GameFlowStep;
use App\Models\GameRound;
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
 * right. Text people wrote reads its own way: an English headline or title on
 * such a page runs left to right with its "?" at its end, and an Arabic title
 * on a page in the default language runs right to left.
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
 * How a line of text is drawn: the direction its element is laid out in, and
 * where across the screen its first and its last character are — the last to
 * the right of the first on a line read left to right, to its left on one read
 * right to left. A "?" ending an English line that is laid out the wrong way
 * is drawn at the far left, before the first word.
 *
 * @return array{direction: string, first: float, last: float}
 */
function drawnLine(mixed $page, string $selector): array
{
    return $page->script(<<<JS
        (() => {
            const element = [...document.querySelectorAll('{$selector}')].find((candidate) => candidate.checkVisibility());
            const walker = document.createTreeWalker(element, NodeFilter.SHOW_TEXT);
            const nodes = [];

            for (let node = walker.nextNode(); node; node = walker.nextNode()) {
                if (node.textContent.trim() !== '') nodes.push(node);
            }

            const first = nodes[0];
            const last = nodes[nodes.length - 1];
            const characterLeft = (node, offset) => {
                const range = document.createRange();
                range.setStart(node, offset);
                range.setEnd(node, offset + 1);

                return range.getBoundingClientRect().left;
            };

            return {
                direction: getComputedStyle(element).direction,
                first: characterLeft(first, first.textContent.search(/\S/)),
                last: characterLeft(last, last.textContent.trimEnd().length - 1),
            };
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
            // Turned round: drawn as its mirror image, by its scale or its
            // transform. A drawing made larger or smaller, or rotated, is not.
            const turned = (svg) => {
                const style = getComputedStyle(svg);
                const [scaleX, scaleY = scaleX] = style.scale === 'none' ? [1] : style.scale.split(' ').map(parseFloat);
                const matrix = new DOMMatrix(style.transform === 'none' ? undefined : style.transform);

                return scaleX * scaleY * (matrix.a * matrix.d - matrix.b * matrix.c) < 0;
            };
            const directional = visible.filter((svg) => svg.classList.contains('rg-mirror-rtl'));

            return {
                directional: directional.length,
                directionalTurned: directional.filter(turned).length,
                otherTurned: visible.filter((svg) => ! svg.classList.contains('rg-mirror-rtl') && turned(svg)).length,
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

    openMobileNav($page);

    $drawer = horizontalBox($page, '[data-testid="mobile-nav-panel"]');
    expect($drawer['right'])->toBe(390)
        ->and($drawer['left'])->toBeGreaterThan(0)
        ->and(pageDirection($page)['overflow'])->toBe(0);
})->with(rightToLeftLocales());

it('lays the feed out left to right on a phone in the default language, the menu button and its drawer on the left', function () {
    Post::factory()->published()->count(3)->create();

    $page = visitInLanguage(route('feed'), config('locales.default'), 390);

    expect(horizontalBox($page, '[data-testid="mobile-nav-trigger"]')['left'])->toBeLessThan(40);

    openMobileNav($page);

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
    // Every text size the views use, as they write it — the largest but one
    // only from the small breakpoint on, which a wide page is past — and the
    // leading Nastaliq needs at it.
    $urdu = [
        'text-xs' => 2, 'text-sm' => 2, 'text-base' => 2, 'text-lg' => 1.9, 'text-xl' => 1.9,
        'text-2xl' => 1.8, 'text-3xl' => 1.7, 'sm:text-4xl' => 1.6, 'text-6xl' => 1.5,
    ];
    $sizes = json_encode(array_keys($urdu));
    $leading = <<<JS
        (() => Object.fromEntries({$sizes}.map((size) => {
            const probe = document.createElement('p');
            probe.className = size;
            probe.textContent = 'x';
            document.body.append(probe);
            const style = getComputedStyle(probe);
            const ratio = Math.round(parseFloat(style.lineHeight) / parseFloat(style.fontSize) * 100) / 100;
            probe.remove();

            return [size, ratio];
        })))()
    JS;

    $default = visitInLanguage(route('feed'), config('locales.default'), 1440)->script($leading);

    expect(visitInLanguage(route('feed'), 'ur', 1440)->script($leading))->toEqual($urdu);

    // The site's own, tighter leading everywhere else.
    foreach ($urdu as $size => $ratio) {
        expect($default[$size])->toBeLessThan($ratio, $size);
    }
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
    // On a phone, sign-up is reached through the sign-in dialog's switch: a narrow phone's header may have room for Log in alone.
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
        (() => {
            const select = getComputedStyle(document.querySelector('#locale'));

            return {
                // Where the chevron is drawn from, if one is drawn at all.
                chevron: select.backgroundImage === 'none' ? null : select.backgroundPositionX,
                address: getComputedStyle(document.querySelector('#edit-website')).direction,
            };
        })()
    JS;

    actingAs(User::factory()->create(['locale' => $locale]));
    $page = visitInLanguage(route('profile.edit'), $locale, 1440);

    // On the left, where the chosen option's line ends: on the right it covered its first letters.
    expect($page->script($controls))->toBe(['chevron' => '8px', 'address' => 'ltr'])
        ->and(pageDirection($page))->toBe(['dir' => 'rtl', 'direction' => 'rtl', 'overflow' => 0]);

    actingAs(User::factory()->create(['locale' => config('locales.default')]));
    $page = visitInLanguage(route('profile.edit'), config('locales.default'), 1440);

    expect($page->script($controls))->toBe(['chevron' => 'calc(100% - 8px)', 'address' => 'ltr']);
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

it('lays an English game headline out left to right on a page read right to left, its "?" at the end of the line', function (string $locale, int $width) {
    // The landing's headline as the admin wrote it, in English only.
    $draft = publishableGameDraft(1);
    liveGame($draft);

    $page = visitGame($draft['game']->slug, $width, $locale);
    $headline = drawnLine($page, '[data-testid="game-step-landing"] .rg-game__title');

    expect($page->script("document.querySelector('[data-testid=\"game-step-landing\"] .rg-game__title').textContent.trim()"))->toBe('Which one would you pick?')
        ->and($headline['direction'])->toBe('ltr')
        ->and($headline['last'])->toBeGreaterThan($headline['first'])
        // The page itself, and the game's own words, still read right to left.
        ->and(pageDirection($page))->toBe(['dir' => 'rtl', 'direction' => 'rtl', 'overflow' => 0])
        ->and($page->script("getComputedStyle(document.querySelector('[data-testid=\"game-skip-to-feed\"]')).direction"))->toBe('rtl');
})->with(rightToLeftLocales())->with([390, 1440]);

it('lays an English post title out left to right on a page read right to left, at the start of its own line', function (string $locale) {
    $post = Post::factory()->published()->create(['title' => 'Which one is better?']);

    $page = visitInLanguage(route('posts.show', $post), $locale, 1440);
    $title = drawnLine($page, '[data-testid="post-show-page"] h1');

    // Its own start is the left: the title sits against the card's left edge.
    expect($title['direction'])->toBe('ltr')
        ->and($title['last'])->toBeGreaterThan($title['first'])
        ->and($title['first'] - horizontalBox($page, '[data-testid="post-show-page"] h1')['left'])->toBeLessThan(2)
        ->and(pageDirection($page))->toBe(['dir' => 'rtl', 'direction' => 'rtl', 'overflow' => 0]);
})->with(rightToLeftLocales());

it('lays an Arabic post title out right to left on a page in the default language, its "!" at the end of the line', function () {
    $post = Post::factory()->published()->create(['title' => 'اختر الصورة الأجمل!']);

    $page = visitInLanguage(route('posts.show', $post), config('locales.default'), 1440);
    $title = drawnLine($page, '[data-testid="post-show-page"] h1');

    expect($title['direction'])->toBe('rtl')
        ->and($title['last'])->toBeLessThan($title['first'])
        ->and(pageDirection($page))->toBe(['dir' => 'ltr', 'direction' => 'ltr', 'overflow' => 0]);
});

it('keeps an English title and headline on a page in the default language as they were, left to right', function () {
    $post = Post::factory()->published()->create(['title' => 'Which one is better?']);

    $page = visitInLanguage(route('posts.show', $post), config('locales.default'), 1440);
    $title = drawnLine($page, '[data-testid="post-show-page"] h1');

    expect($title['direction'])->toBe('ltr')
        ->and($title['last'])->toBeGreaterThan($title['first'])
        ->and($title['first'] - horizontalBox($page, '[data-testid="post-show-page"] h1')['left'])->toBeLessThan(2);

    $draft = publishableGameDraft(1);
    liveGame($draft);
    $headline = drawnLine(visitGame($draft['game']->slug, 390, config('locales.default')), '[data-testid="game-step-landing"] .rg-game__title');

    expect($headline['direction'])->toBe('ltr')
        ->and($headline['last'])->toBeGreaterThan($headline['first']);
});

it('keeps an empty comment box in the page\'s direction, its placeholder at the start of a line read right to left', function (string $locale) {
    $post = Post::factory()->published()->create();
    actingAs(User::factory()->create(['locale' => $locale]));

    $page = visitInLanguage(route('posts.show', $post), $locale, 1440);

    // The box reads what is typed its own way (dir="auto"); with nothing typed yet it has no letter to go by.
    expect($page->script("document.querySelector('[data-testid=\"comment-body\"]').getAttribute('dir')"))->toBe('auto')
        ->and($page->script("getComputedStyle(document.querySelector('[data-testid=\"comment-body\"]')).direction"))->toBe('rtl');
})->with(rightToLeftLocales());

it('keeps a title with no letter in it in the page\'s direction, at the start of a line read right to left', function (string $locale) {
    // Digits and punctuation only: a browser lays such text out left to right under dir="auto".
    $post = Post::factory()->published()->create(['title' => '100% 2024!']);

    $page = visitInLanguage(route('posts.show', $post), $locale, 1440);
    $title = $page->script(<<<'JS'
        (() => {
            const element = document.querySelector('[data-testid="post-show-page"] h1');
            const range = document.createRange();
            range.selectNodeContents(element);

            return { direction: getComputedStyle(element).direction, endGap: Math.round(element.getBoundingClientRect().right - range.getBoundingClientRect().right) };
        })()
    JS);

    expect($title['direction'])->toBe('rtl')
        ->and(abs($title['endGap']))->toBeLessThanOrEqual(1);
})->with(rightToLeftLocales());

it('takes a game text\'s direction from the administrator\'s own words, a Latin title put in front of them reading its own way', function (string $locale) {
    $draft = publishableGameDraft(1);
    $draft['game']->forceFill(['title' => 'Pairs game!'])->save();
    // The page's own words after the game's title, as Urdu's "{game} میں میرا نتیجہ" has them.
    GameFlowStep::query()->where('flow_id', $draft['flow']->id)->where('type', 'landing')
        ->update(['body_translations' => [$locale => '{game} '.__('games.play.share_title', [], $locale)]]);
    $draft['game'] = $draft['game']->fresh();
    liveGame($draft);

    $page = visitGame($draft['game']->slug, 390, $locale);
    $title = drawnLine($page, '[data-testid="game-step-landing"] .rg-game__lead bdi');
    $order = $page->script(<<<'JS'
        (() => {
            const lead = document.querySelector('[data-testid="game-step-landing"] .rg-game__lead');
            const title = lead.querySelector('bdi');
            const range = document.createRange();
            range.setStartAfter(title);
            range.setEnd(lead, lead.childNodes.length);

            return { direction: getComputedStyle(lead).direction, titleLeft: title.getBoundingClientRect().left, wordsRight: range.getBoundingClientRect().right };
        })()
    JS);

    // The sentence right to left, the title first in it — at its right — and left to right in itself, its "!" at its end.
    expect($order['direction'])->toBe('rtl')
        ->and($order['titleLeft'])->toBeGreaterThanOrEqual($order['wordsRight'] - 1)
        ->and($title['direction'])->toBe('ltr')
        ->and($title['last'])->toBeGreaterThan($title['first']);
})->with(rightToLeftLocales());

it('draws the site\'s own words on a game\'s button in the page\'s direction, the site\'s name where the line puts it', function (string $locale) {
    $draft = publishableGameDraft(1);
    // The administrator left the button's label empty: the site's own line, with the site's name in it.
    replaceFlowSteps($draft['flow'], [GameFlowStepType::Landing, GameFlowStepType::Game, GameFlowStepType::FullResult], settings: [
        'game' => ['advance' => 'button'],
        'full_result' => ['onward' => 'feed'],
    ]);
    liveGame($draft);

    $page = visitGame($draft['game']->slug, 1440, $locale);
    $page->click('[data-testid="game-start"]');
    waitForScript($page, "document.querySelector('[data-testid=\"game-step-game\"]')?.dataset.round", '0', 10.0);
    pickInGame($page, 0);
    nextInGame($page, "!! document.querySelector('[data-testid=\"game-onward-go\"]')");

    $button = $page->script(<<<'JS'
        (() => {
            const words = document.querySelector('[data-testid="game-onward-go"] > span');
            const site = words.querySelector('bdi');
            const box = words.getBoundingClientRect();
            const name = site.getBoundingClientRect();

            return { direction: getComputedStyle(words).direction, site: site.textContent, startGap: Math.round(box.right - name.right), endGap: Math.round(name.left - box.left) };
        })()
    JS);

    // Urdu's line starts with the site's name: at the right, where a line read right to left starts.
    $startsWithSite = str_starts_with(__('games.play.onward_feed_button', [], $locale), ':site');

    expect($button['direction'])->toBe('rtl')
        ->and($button['site'])->toBe('RateGuru')
        ->and($startsWithSite ? $button['startGap'] : $button['endGap'])->toBeLessThanOrEqual(1);
})->with(rightToLeftLocales());

it('starts an English title in the post drawer where its description starts, on a page read right to left', function (string $locale) {
    Post::factory()->published()->create(['title' => 'Which one is better?', 'description' => 'Look at both photos, then vote.']);

    $page = visitInLanguage(route('feed'), $locale, 1440);
    $page->click('[data-testid="post-card"]');
    waitForScript($page, "!! document.querySelector('[data-testid=\"post-detail-column\"] [data-testid=\"post-drawer-title\"]')", true, 10.0);

    $title = drawnLine($page, '[data-testid="post-detail-column"] #post-drawer-title');
    $description = drawnLine($page, '[data-testid="post-detail-column"] #post-drawer-title + p');

    expect($title['direction'])->toBe('ltr')
        ->and($title['last'])->toBeGreaterThan($title['first'])
        ->and(abs($title['first'] - $description['first']))->toBeLessThan(2);
})->with(rightToLeftLocales());

it('keeps an English message under a game\'s verdict with the verdict, at the start of a line read right to left', function (string $locale) {
    $draft = publishableGameDraft(1);
    buttonPacedGame($draft);
    GameRound::query()->where('content_set_id', $draft['set']->id)->update(['reveal_message' => 'Nice pick, most people agree.']);
    liveGame($draft);

    $page = visitGame($draft['game']->slug, 1440, $locale);
    $page->click('[data-testid="game-start"]');
    waitForScript($page, "document.querySelector('[data-testid=\"game-step-game\"]')?.dataset.round", '0', 10.0);
    pickInGame($page, 0);

    $message = horizontalBox($page, '.rg-game__note--message');
    $verdict = horizontalBox($page, '.rg-game__verdict-title');
    $line = drawnLine($page, '.rg-game__note--message');

    // Beside the verdict, not across the screen by the way on; read left to right, its "." at its end.
    expect(abs($message['right'] - $verdict['right']))->toBeLessThanOrEqual(1)
        ->and($message['left'])->toBeGreaterThan($verdict['left'] + 100)
        ->and($line['direction'])->toBe('ltr')
        ->and($line['last'])->toBeGreaterThan($line['first']);
})->with(rightToLeftLocales());
