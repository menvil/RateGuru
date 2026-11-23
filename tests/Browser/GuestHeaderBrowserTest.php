<?php

use App\Models\ProjectSettings;

/*
 * The header a guest sees, in every installed language, at the widths where it
 * changes shape: on a narrow phone it offers Log in only, whole and on screen,
 * with room left for the brand; from the small-screen width Sign up joins it;
 * from the desktop width the search sits between them, and the brand keeps its
 * whole name while the search gives way. With both buttons on a phone, a
 * language with long labels squeezed the brand out and cut Log in off at the
 * edge of the screen; at the desktop width the search took its full width
 * first and left the brand no room at all.
 */

beforeEach(function () {
    ProjectSettings::factory()->create();
    offerEveryInstalledLocale();
});

/**
 * The guest header as drawn: the language the page is in; whether each
 * sign-in control is hidden, whole on screen with its label unclipped, or cut;
 * how much of the brand's name is drawn, as a percentage of its full width; how
 * wide the search is drawn; and how far the header's row and the page run past
 * their boxes.
 *
 * @return array{lang: string, register: string, login: string, brand: int, search: int, row: int, page: int}
 */
function guestHeaderLayout(mixed $page): array
{
    return $page->script(<<<'JS'
        (() => {
            const state = (selector) => {
                const element = document.querySelector(selector);
                if (element === null || ! element.checkVisibility()) {
                    return 'hidden';
                }
                const box = element.getBoundingClientRect();
                const whole = box.left >= 0 && box.right <= window.innerWidth
                    && element.scrollWidth <= element.clientWidth && element.scrollHeight <= element.clientHeight;

                return whole ? 'whole' : 'cut';
            };
            const name = document.querySelector('[data-testid="site-brand"]').firstElementChild;
            const search = document.querySelector('[data-testid="app-header-search"]');
            const row = document.querySelector('[data-testid="app-header"] .rg-app-header-layout');

            return {
                lang: document.documentElement.lang,
                register: state('[data-testid="header-register-link"]'),
                login: state('[data-testid="header-login-link"]'),
                brand: Math.floor(100 * name.clientWidth / name.scrollWidth),
                search: Math.round(search.getBoundingClientRect().width),
                row: row.scrollWidth - row.clientWidth,
                page: document.documentElement.scrollWidth - window.innerWidth,
            };
        })()
    JS);
}

/** The guest header at this width, once the page has been laid out at it. */
function guestHeaderAt(mixed $page, int $width): array
{
    $page->resize($width, 800);
    waitForViewportSize($page, $width, 800);

    return guestHeaderLayout($page);
}

it('fits the guest header in every language: Log in alone on a phone, Sign up beside it from the small-screen width, the whole brand beside the search on a desktop', function (string $locale) {
    $page = visit(route('feed'))->withLocale($locale);

    $phone = guestHeaderAt($page, 360);
    $small = guestHeaderAt($page, 640);
    $desktop = guestHeaderAt($page, 1024);
    $fits = ['lang' => $locale, 'login' => 'whole', 'row' => 0, 'page' => 0];

    // At least half the name is drawn on the phone, the whole of it once there
    // is room; on the desktop the search gives way, but keeps room to type in.
    expect($phone)->toMatchArray([...$fits, 'register' => 'hidden'])
        ->and($phone['brand'])->toBeGreaterThanOrEqual(50)
        ->and($small)->toMatchArray([...$fits, 'register' => 'whole', 'brand' => 100])
        ->and($desktop)->toMatchArray([...$fits, 'register' => 'whole', 'brand' => 100])
        ->and($desktop['search'])->toBeGreaterThanOrEqual(200);
})->with(supportedLocales());
