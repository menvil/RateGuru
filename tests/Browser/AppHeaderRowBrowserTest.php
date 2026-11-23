<?php

use App\Models\ProjectSettings;
use App\Models\User;

use function Pest\Laravel\actingAs;

/*
 * The header's row in every installed language, from a narrow phone to a
 * desktop, for a guest and for a signed-in reader. Its optional controls are
 * shown in order of importance as far as the row has room: nothing runs past
 * its end, a guest's Log in is always whole, the brand's name is drawn whole
 * unless every optional control is already put away, and a desktop's search
 * keeps room to type in. No control is shown while a more important one is
 * put away, and none is put away that would have fitted. From the desktop
 * width, where the drawer is gone, the language switcher (and a guest's theme
 * switcher) are always there.
 */

beforeEach(function () {
    ProjectSettings::factory()->create();
    offerEveryInstalledLocale();
});

/**
 * The header's row once it has been fitted to this width: the language the
 * page is in; which optional controls are shown, most important first (those
 * always shown at this width left out); whether the most important one put
 * away would have fitted; whether Log in is whole on screen with its label
 * unclipped (null when there is none); whether the brand's name is drawn whole
 * and how much of it is drawn, as a percentage; how wide the search is drawn
 * and the least it should be; whether the language and theme switchers are
 * shown; and how far the row and the page run past their boxes.
 *
 * @return array{lang: string, shown: list<bool>, nextFits: ?bool, login: ?bool, brandWhole: bool, brand: int, search: int, searchMin: int, language: bool, theme: ?bool, row: int, page: int}
 */
function headerRowAt(mixed $page, int $width): array
{
    $page->resize($width, 800);
    waitForViewportSize($page, $width, 800);
    waitForScript($page, 'Number(document.querySelector("[data-header-fit-row]").dataset.headerFitted) === document.querySelector("[data-header-fit-row]").clientWidth');

    return $page->script(<<<'JS'
        (() => {
            const row = document.querySelector('[data-header-fit-row]');
            const name = row.querySelector('[data-header-fit-brand]');
            const search = row.querySelector('[data-header-fit-search]');
            const login = row.querySelector('[data-testid="header-login-link"]');
            const theme = row.querySelector('[data-testid="desktop-header-theme"]');
            const searchMin = Number(search.dataset.headerFitSearch);
            const nameRange = document.createRange();
            nameRange.selectNodeContents(name);
            const drawn = () => name.getBoundingClientRect().width / nameRange.getBoundingClientRect().width;
            // As the fit measures it: the text's width against its box's, to a hundredth of a pixel.
            const whole = () => nameRange.getBoundingClientRect().width <= name.getBoundingClientRect().width + 0.01;
            const fits = () => row.scrollWidth <= row.clientWidth && whole()
                && (search.offsetWidth === 0 || search.getBoundingClientRect().width >= searchMin);
            const optional = [...row.querySelectorAll('[data-header-fit]')]
                .filter((control) => ! control.dataset.headerFitAlways || ! matchMedia(control.dataset.headerFitAlways).matches)
                .sort((a, b) => Number(b.dataset.headerFit) - Number(a.dataset.headerFit));
            const next = optional.find((control) => ! control.checkVisibility());
            let nextFits = null;

            if (next) {
                const short = next.dataset.headerFitLong
                    ? next.parentElement.querySelector('[data-header-fit-short="' + next.dataset.headerFitLong + '"]')
                    : null;
                const shortDisplay = short?.style.display;

                next.style.display = next.dataset.headerFitDisplay;
                if (short) short.style.display = 'none';
                nextFits = fits();
                next.style.display = 'none';
                if (short) short.style.display = shortDisplay;
            }

            const loginBox = login?.getBoundingClientRect();

            return {
                lang: document.documentElement.lang,
                shown: optional.map((control) => control.checkVisibility()),
                nextFits,
                login: login === null ? null : loginBox.left >= 0 && loginBox.right <= window.innerWidth
                    && login.scrollWidth <= login.clientWidth && login.scrollHeight <= login.clientHeight,
                brandWhole: whole(),
                brand: Math.min(100, Math.floor(100 * drawn())),
                search: Math.round(search.getBoundingClientRect().width),
                searchMin,
                language: row.querySelector('[data-testid="language-switcher"]').checkVisibility(),
                theme: theme === null ? null : theme.checkVisibility(),
                row: row.scrollWidth - row.clientWidth,
                page: document.documentElement.scrollWidth - window.innerWidth,
            };
        })()
    JS);
}

/** What holds of the row at every width: see the description at the top. */
function expectHeaderRowFitted(array $row, string $locale, bool $guest, int $width): void
{
    $allPutAway = ! in_array(true, $row['shown'], true);
    $shownFirst = array_slice($row['shown'], 0, count(array_filter($row['shown'])));

    expect($row)->toMatchArray(['lang' => $locale, 'row' => 0, 'page' => 0, 'login' => $guest ? true : null])
        ->and($shownFirst)->each->toBeTrue()
        ->and($row['nextFits'])->not->toBeTrue()
        ->and($row['searchMin'])->toBe(240);

    if (! $allPutAway) {
        expect($row['brandWhole'])->toBeTrue();
    } else {
        expect($row['brand'])->toBeGreaterThanOrEqual(50);
    }

    if ($row['search'] > 0 && ! $allPutAway) {
        expect($row['search'])->toBeGreaterThanOrEqual($row['searchMin']);
    }

    if ($width >= 1024) {
        expect($row['language'])->toBeTrue()
            ->and($row['theme'])->toBe($guest ? true : null);
    }
}

it('fits a guest\'s header to every width in every language, Log in always and the rest by importance', function (string $locale) {
    $page = visit(route('feed'))->withLocale($locale);

    foreach ([360, 414, 415, 639, 640, 768, 1023, 1024, 1280] as $width) {
        expectHeaderRowFitted(headerRowAt($page, $width), $locale, guest: true, width: $width);
    }
})->with(supportedLocales());

it('fits a signed-in reader\'s header to every width in every language', function (string $locale) {
    actingAs(User::factory()->create(['locale' => $locale]));

    $page = visit(route('feed'));

    foreach ([360, 640, 768, 1024] as $width) {
        expectHeaderRowFitted(headerRowAt($page, $width), $locale, guest: false, width: $width);
    }
})->with(supportedLocales());
