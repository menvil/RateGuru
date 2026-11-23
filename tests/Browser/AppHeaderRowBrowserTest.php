<?php

use App\Models\ProjectSettings;
use App\Models\User;

use function Pest\Laravel\actingAs;

/*
 * The header's row in every installed language, from the narrowest phone to a
 * desktop, for a guest and for a signed-in reader. The brand is drawn at its
 * full size and its optional controls are shown in order of importance as far
 * as the row has room: nothing runs past its end, a guest's Log in is always
 * whole, the brand's whole name is drawn (but in a signed-in reader's row
 * at 320px, which has no room for it), and a desktop's search keeps room
 * to type in. The brand's full size comes first: it is smaller only once
 * every optional control is put away, and then as large as still fits. No
 * control is shown while a more important one is put away, and none is put
 * away that would have fitted beside the brand at its full size. From the
 * desktop width, where the drawer is gone, the language switcher (and a
 * guest's theme switcher) are always there. The drawer draws the brand at the
 * header's full size, whole even on the narrowest phone.
 */

beforeEach(function () {
    ProjectSettings::factory()->create();
    offerEveryInstalledLocale();
});

/**
 * The header's row once it has been fitted to this width: the language the
 * page is in; which optional controls are shown, most important first (those
 * always shown at this width left out); whether the most important one put
 * away would have fitted beside the brand at its full size; whether Log in is
 * whole on screen with its label unclipped (null when there is none); the
 * brand's size, its full and least sizes, whether its whole name is drawn,
 * how much of it is drawn as a percentage, and whether it would still fit at
 * a pixel larger (null at its full size); how wide the search is drawn and the
 * least it should be; whether the language and theme switchers are shown; how
 * far the row and the page run past their boxes; and the items of the row and
 * of its action group that are laid out with no width, each of which still
 * takes a gap from the brand.
 *
 * @return array{lang: string, shown: list<bool>, nextFits: ?bool, login: ?bool, brandSize: int|float, brandFull: int, brandLeast: int, brandWhole: bool, brand: int, largerFits: ?bool, search: int, searchMin: int, language: bool, theme: ?bool, row: int, page: int, emptyItems: list<string>}
 */
function headerRowAt(mixed $page, int $width): array
{
    $page->resize($width, 800);
    waitForViewportSize($page, $width, 800);
    waitForScript($page, 'Number(document.querySelector("[data-header-fit-row]").dataset.headerFitted) === document.querySelector("[data-header-fit-row]").clientWidth');

    return $page->script(<<<'JS'
        (() => {
            const row = document.querySelector('[data-header-fit-row]');
            const brand = row.querySelector('[data-testid="site-brand"]');
            const name = row.querySelector('[data-header-fit-brand]');
            const search = row.querySelector('[data-header-fit-search]');
            const login = row.querySelector('[data-testid="header-login-link"]');
            const theme = row.querySelector('[data-testid="desktop-header-theme"]');
            const searchMin = Number(search.dataset.headerFitSearch);
            const brandFull = Number(brand.dataset.headerFitBrandSize);
            const brandSize = parseFloat(getComputedStyle(brand).fontSize);
            const nameRange = document.createRange();
            nameRange.selectNodeContents(name);
            // As the fit measures it: the text's width against its box's, to a hundredth of a pixel.
            const whole = () => nameRange.getBoundingClientRect().width <= name.getBoundingClientRect().width + 0.01;
            const brandFits = () => row.scrollWidth <= row.clientWidth && whole();
            const fits = () => brandFits()
                && (search.offsetWidth === 0 || search.getBoundingClientRect().width >= searchMin);
            const optional = [...row.querySelectorAll('[data-header-fit]')]
                .filter((control) => ! control.dataset.headerFitAlways || ! matchMedia(control.dataset.headerFitAlways).matches)
                .sort((a, b) => Number(b.dataset.headerFit) - Number(a.dataset.headerFit));
            const next = optional.find((control) => ! control.checkVisibility());
            const brandStyle = brand.style.fontSize;
            let nextFits = null;
            let largerFits = null;

            if (next) {
                const short = next.dataset.headerFitLong
                    ? next.parentElement.querySelector('[data-header-fit-short="' + next.dataset.headerFitLong + '"]')
                    : null;
                const shortDisplay = short?.style.display;

                brand.style.fontSize = brandFull + 'px';
                next.style.display = next.dataset.headerFitDisplay;
                if (short) short.style.display = 'none';
                nextFits = fits();
                next.style.display = 'none';
                if (short) short.style.display = shortDisplay;
                brand.style.fontSize = brandStyle;
            }

            if (brandSize < brandFull) {
                brand.style.fontSize = (brandSize + 1) + 'px';
                largerFits = brandFits();
                brand.style.fontSize = brandStyle;
            }

            const loginBox = login?.getBoundingClientRect();
            // A box's items as its layout takes them: a display: contents box
            // gives its own children instead, and one that is not drawn or is
            // positioned out of the flow is no item at all.
            const items = (box) => [...box.children].flatMap((child) => {
                const style = getComputedStyle(child);

                if (style.display === 'contents') return items(child);
                if (style.display === 'none' || style.position === 'absolute' || style.position === 'fixed') return [];

                return [child];
            });
            const actions = row.querySelector('[data-testid="header-auth-actions"], [data-testid="header-guest-actions"]');

            return {
                lang: document.documentElement.lang,
                shown: optional.map((control) => control.checkVisibility()),
                nextFits,
                login: login === null ? null : loginBox.left >= 0 && loginBox.right <= window.innerWidth
                    && login.scrollWidth <= login.clientWidth && login.scrollHeight <= login.clientHeight,
                brandSize,
                brandFull,
                brandLeast: Number(brand.dataset.headerFitBrandMinSize),
                brandWhole: whole(),
                brand: Math.min(100, Math.floor(100 * name.getBoundingClientRect().width / nameRange.getBoundingClientRect().width)),
                largerFits,
                search: Math.round(search.getBoundingClientRect().width),
                searchMin,
                language: row.querySelector('[data-testid="language-switcher"]').checkVisibility(),
                theme: theme === null ? null : theme.checkVisibility(),
                row: row.scrollWidth - row.clientWidth,
                page: document.documentElement.scrollWidth - window.innerWidth,
                emptyItems: [...items(row), ...items(actions)]
                    .filter((item) => item.getBoundingClientRect().width === 0)
                    .map((item) => item.dataset.testid ?? item.outerHTML.replace(/\s+/g, ' ').slice(0, 80)),
            };
        })()
    JS);
}

/** What holds of the row at every width: see the description at the top. */
function expectHeaderRowFitted(array $row, string $locale, bool $guest, int $width): void
{
    $allPutAway = ! in_array(true, $row['shown'], true);
    $shownFirst = array_slice($row['shown'], 0, count(array_filter($row['shown'])));

    expect($row)->toMatchArray([
        'lang' => $locale, 'row' => 0, 'page' => 0, 'login' => $guest ? true : null,
        'brandFull' => 22, 'brandLeast' => 15, 'emptyItems' => [],
    ])
        ->and($shownFirst)->each->toBeTrue()
        ->and($row['nextFits'])->not->toBeTrue()
        ->and($row['searchMin'])->toBe(240)
        ->and($row['brandSize'])->toBeGreaterThanOrEqual($row['brandLeast'])->toBeLessThanOrEqual($row['brandFull']);

    // The one row too narrow for the whole name: a signed-in reader's at
    // 320px, where the upload button, the bell and the account menu are always
    // shown and leave the name 60px — less than it takes even at its least
    // size (67px, 70px in Arabic and Urdu). There it is cut at its least size
    // with every optional control put away, more than four fifths of it drawn.
    if (! $guest && $width <= 320 && ! $row['brandWhole']) {
        expect($row['brandSize'])->toBe($row['brandLeast'])
            ->and($allPutAway)->toBeTrue()
            ->and($row['brand'])->toBeGreaterThanOrEqual(80);
    } else {
        expect($row['brandWhole'])->toBeTrue("At {$width}px the brand's name is cut at {$row['brandSize']}px");
    }

    if ($row['brandSize'] < $row['brandFull']) {
        expect($allPutAway)->toBeTrue("At {$width}px the brand is {$row['brandSize']}px while an optional control is shown")
            ->and($row['largerFits'])->toBeFalse("At {$width}px the brand is {$row['brandSize']}px, yet a pixel larger would fit");
    }

    if ($row['search'] > 0 && ! $allPutAway) {
        expect($row['search'])->toBeGreaterThanOrEqual($row['searchMin']);
    }

    if ($width >= 1024) {
        expect($row['language'])->toBeTrue()
            ->and($row['theme'])->toBe($guest ? true : null);
    }
}

/**
 * Opens the drawer at this phone width: the size its brand is drawn at, the
 * header brand's full size, and whether the drawer's whole brand name is drawn.
 *
 * @return array{size: int|float, headerFull: int, whole: bool}
 */
function drawerBrandAt(mixed $page, int $width): array
{
    $page->resize($width, 800);
    waitForViewportSize($page, $width, 800);
    openMobileNav($page);

    return $page->script(<<<'JS'
        (() => {
            const brand = document.querySelector('[data-testid="mobile-nav-brand"]');
            const name = document.createRange();
            name.selectNodeContents(brand);

            return {
                size: parseFloat(getComputedStyle(brand).fontSize),
                headerFull: Number(document.querySelector('[data-testid="site-brand"]').dataset.headerFitBrandSize),
                whole: name.getBoundingClientRect().width <= brand.getBoundingClientRect().width + 0.01,
            };
        })()
    JS);
}

it('fits a guest\'s header to every width in every language, Log in always, then the brand\'s full size, then the rest by importance', function (string $locale) {
    $page = visit(route('feed'))->withLocale($locale);

    foreach ([320, 360, 375, 414, 415, 639, 640, 768, 1023, 1024, 1280] as $width) {
        expectHeaderRowFitted(headerRowAt($page, $width), $locale, guest: true, width: $width);
    }

    expect(drawerBrandAt($page, 320))->toBe(['size' => 22, 'headerFull' => 22, 'whole' => true]);
})->with(supportedLocales());

it('fits a signed-in reader\'s header to every width in every language', function (string $locale) {
    actingAs(User::factory()->create(['locale' => $locale]));

    $page = visit(route('feed'));

    foreach ([320, 360, 375, 640, 768, 1024] as $width) {
        expectHeaderRowFitted(headerRowAt($page, $width), $locale, guest: false, width: $width);
    }
})->with(supportedLocales());
