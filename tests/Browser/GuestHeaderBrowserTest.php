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
 * put away, and none is put away that would have fitted. With fixed
 * breakpoints, a narrow phone in a language with long labels lost the brand
 * and cut Log in off at the edge, and a wide tablet held back controls it had
 * room for.
 */

beforeEach(function () {
    ProjectSettings::factory()->create();
    offerEveryInstalledLocale();
});

/**
 * The header's row once it has been fitted to this width: the language the
 * page is in; which optional controls are shown, most important first; whether
 * the most important one put away would have fitted; whether Log in is whole
 * on screen with its label unclipped (null when there is none); how much of the
 * brand's name is drawn, as a percentage; how wide the search is drawn; and how
 * far the row and the page run past their boxes.
 *
 * @return array{lang: string, shown: list<bool>, nextFits: ?bool, login: ?bool, brand: int, search: int, row: int, page: int}
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
            const fits = () => row.scrollWidth <= row.clientWidth && name.scrollWidth <= name.clientWidth
                && (search.offsetWidth === 0 || search.offsetWidth >= 240);
            const optional = [...row.querySelectorAll('[data-header-fit]')]
                .sort((a, b) => Number(b.dataset.headerFit) - Number(a.dataset.headerFit));
            const next = optional.find((control) => ! control.checkVisibility());
            let nextFits = null;

            if (next) {
                next.style.display = next.dataset.headerFitDisplay;
                nextFits = fits();
                next.style.display = 'none';
            }

            const loginBox = login?.getBoundingClientRect();

            return {
                lang: document.documentElement.lang,
                shown: optional.map((control) => control.checkVisibility()),
                nextFits,
                login: login === null ? null : loginBox.left >= 0 && loginBox.right <= window.innerWidth
                    && login.scrollWidth <= login.clientWidth && login.scrollHeight <= login.clientHeight,
                brand: Math.floor(100 * name.clientWidth / name.scrollWidth),
                search: Math.round(search.getBoundingClientRect().width),
                row: row.scrollWidth - row.clientWidth,
                page: document.documentElement.scrollWidth - window.innerWidth,
            };
        })()
    JS);
}

/** What holds of the row at every width: see the description at the top. */
function expectHeaderRowFitted(array $row, string $locale, bool $guest): void
{
    $allPutAway = ! in_array(true, $row['shown'], true);
    $shownFirst = array_slice($row['shown'], 0, count(array_filter($row['shown'])));

    expect($row)->toMatchArray(['lang' => $locale, 'row' => 0, 'page' => 0, 'login' => $guest ? true : null])
        ->and($shownFirst)->each->toBeTrue()
        ->and($row['nextFits'])->not->toBeTrue()
        ->and($row['brand'])->toBeGreaterThanOrEqual($allPutAway ? 50 : 100);

    if ($row['search'] > 0 && ! $allPutAway) {
        expect($row['search'])->toBeGreaterThanOrEqual(240);
    }
}

it('fits a guest\'s header to every width in every language, Log in always and the rest by importance', function (string $locale) {
    $page = visit(route('feed'))->withLocale($locale);

    foreach ([360, 414, 640, 768, 1024, 1280] as $width) {
        expectHeaderRowFitted(headerRowAt($page, $width), $locale, guest: true);
    }
})->with(supportedLocales());

it('fits a signed-in reader\'s header to every width in every language', function (string $locale) {
    actingAs(User::factory()->create(['locale' => $locale]));

    $page = visit(route('feed'));

    foreach ([360, 768, 1024] as $width) {
        expectHeaderRowFitted(headerRowAt($page, $width), $locale, guest: false);
    }
})->with(supportedLocales());
