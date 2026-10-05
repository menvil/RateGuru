<?php

use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * The Admin v2 shell in a real browser: which form the sidebar takes at each
 * width, that the page beside it is never covered, and that the rail, the
 * drawer and the account menu behave — measured from computed layout, not
 * from markup.
 */

/** Where the shell sits on the page, as the browser laid it out. */
function adminShellLayout(mixed $page): array
{
    return $page->script(<<<'JS'
        (() => {
            const sidebar = document.querySelector('#rg-admin-sidebar')
            const label = sidebar.querySelector('.rg-admin-nav-item__label')
            const menu = document.querySelector('.rg-admin-topbar__menu')

            return {
                sidebar: getComputedStyle(sidebar).visibility === 'visible' ? Math.round(sidebar.getBoundingClientRect().width) : 0,
                open: sidebar.classList.contains('rg-admin-sidebar--open'),
                labels: label.getBoundingClientRect().width > 1,
                content: Math.round(document.querySelector('.fi-main-ctn').getBoundingClientRect().left),
                topbar: Math.round(document.querySelector('.rg-admin-topbar').getBoundingClientRect().left),
                topbarHeight: Math.round(document.querySelector('.rg-admin-topbar').getBoundingClientRect().height),
                menuButton: getComputedStyle(menu).display !== 'none',
                scrim: getComputedStyle(document.querySelector('.rg-admin-shell-scrim')).display !== 'none',
                overflow: document.documentElement.scrollWidth > window.innerWidth,
            }
        })()
    JS);
}

/** The label of the sidebar link marked as the current page. */
function adminShellActive(mixed $page): ?string
{
    return $page->script(<<<'JS'
        document.querySelector('#rg-admin-sidebar a[aria-current="page"] .rg-admin-nav-item__label')?.textContent.trim() ?? null
    JS);
}

beforeEach(function () {
    ProjectSettings::factory()->create();
});

it('takes the full sidebar, the rail or a drawer by width, never covering the page', function (int $width, array $expected) {
    actingAs(User::factory()->admin()->create());

    $layout = adminShellLayout(visit('/admin')->resize($width, 900)->wait(0.4));

    expect($layout)->toMatchArray($expected)
        ->and($layout['open'])->toBeFalse()
        ->and($layout['scrim'])->toBeFalse()
        ->and($layout['topbarHeight'])->toBe(62)
        ->and($layout['overflow'])->toBeFalse();
})->with([
    '1440' => [1440, ['sidebar' => 300, 'labels' => true, 'content' => 300, 'topbar' => 300, 'menuButton' => false]],
    '1280' => [1280, ['sidebar' => 300, 'labels' => true, 'content' => 300, 'topbar' => 300, 'menuButton' => false]],
    '1279' => [1279, ['sidebar' => 68, 'labels' => false, 'content' => 68, 'topbar' => 68, 'menuButton' => false]],
    '1024' => [1024, ['sidebar' => 68, 'labels' => false, 'content' => 68, 'topbar' => 68, 'menuButton' => false]],
    '900' => [900, ['sidebar' => 0, 'content' => 0, 'topbar' => 0, 'menuButton' => true]],
]);

it('shows each desktop page inside the shell with its own destination marked', function (string $path, string $active, string $content) {
    actingAs(User::factory()->admin()->create());
    Post::factory()->count(2)->published()->withImage()->create();

    $page = visit($path)->resize(1440, 900)->wait(0.4);

    $page->assertVisible('#rg-admin-sidebar')
        ->assertVisible('.rg-admin-topbar')
        ->assertSee($content);

    expect(adminShellActive($page))->toBe($active);
})->with([
    'dashboard' => ['/admin', 'Dashboard', 'Pending posts'],
    'posts' => ['/admin/posts', 'Posts', 'Author'],
    'languages' => ['/admin/languages', 'Languages', 'How languages work'],
    'project settings' => ['/admin/project-settings', 'Project settings', 'Project Settings'],
]);

it('navigates from the sidebar', function () {
    actingAs(User::factory()->admin()->create());

    $page = visit('/admin')->resize(1440, 900)->wait(0.4);
    $page->click('#rg-admin-sidebar a[data-tooltip="Languages"]')->wait(0.6);

    // Which item is marked after the click is not asserted here: the test
    // server handles every request in one process, where Filament keeps the
    // first request as the "original" one. Each page's marked item is checked
    // on its own visit above.
    $page->assertPathIs('/admin/languages')->assertSee('How languages work');
});

it('names the rail\'s icons, and expands it into the full sidebar that Escape closes', function () {
    actingAs(User::factory()->admin()->create());

    $page = visit('/admin/posts')->resize(1024, 800)->wait(0.4);

    // Labels are hidden from sight, not from assistive technology, and a tooltip shows on focus.
    expect($page->script(<<<'JS'
        (() => {
            const link = document.querySelector('#rg-admin-sidebar a[data-tooltip="Comments"]')
            link.focus()

            return {
                name: link.querySelector('.rg-admin-nav-item__label').textContent.trim(),
                visibleWidth: Math.round(link.querySelector('.rg-admin-nav-item__label').getBoundingClientRect().width),
            }
        })()
    JS))->toBe(['name' => 'Comments', 'visibleWidth' => 1]);

    $page->wait(0.2);

    expect($page->script(<<<'JS'
        (() => {
            const tip = document.querySelector('.rg-admin-shell-tooltip')

            return { shown: getComputedStyle(tip).display !== 'none', text: tip.textContent.trim() }
        })()
    JS))->toBe(['shown' => true, 'text' => 'Comments']);

    $page->click('.rg-admin-sidebar__expand button')->wait(0.3);

    expect(adminShellLayout($page))->toMatchArray(['sidebar' => 300, 'open' => true, 'labels' => true, 'scrim' => true, 'content' => 68]);

    $page->keys('.rg-admin-sidebar__close', 'Escape')->wait(0.3);

    expect(adminShellLayout($page))->toMatchArray(['sidebar' => 68, 'open' => false, 'scrim' => false])
        ->and($page->script('document.activeElement?.getAttribute("aria-label") ?? null'))->toBe('Expand navigation');
});

it('opens the navigation as a drawer on narrow screens, closed by the scrim or a destination', function () {
    actingAs(User::factory()->admin()->create());

    $page = visit('/admin')->resize(800, 900)->wait(0.4);

    $page->click('.rg-admin-topbar__menu')->wait(0.3);

    expect(adminShellLayout($page))->toMatchArray(['sidebar' => 300, 'open' => true, 'scrim' => true, 'overflow' => false])
        ->and($page->script('document.querySelector(".rg-admin-topbar__menu").getAttribute("aria-expanded")'))->toBe('true');

    $page->click('.rg-admin-shell-scrim')->wait(0.3);

    expect(adminShellLayout($page))->toMatchArray(['sidebar' => 0, 'open' => false, 'scrim' => false])
        ->and($page->script('document.activeElement?.getAttribute("aria-label") ?? null'))->toBe('Open navigation');

    $page->click('.rg-admin-topbar__menu')->wait(0.3);
    $page->click('#rg-admin-sidebar a[data-tooltip="Posts"]')->wait(0.6);

    $page->assertPathIs('/admin/posts');

    expect(adminShellLayout($page))->toMatchArray(['open' => false, 'overflow' => false]);
});

it('signs out from the account menu through Filament\'s logout', function () {
    actingAs(User::factory()->admin()->create(['name' => 'Daria Kovaleva']));

    $page = visit('/admin')->resize(1440, 900)->wait(0.4);

    $page->click('.rg-admin-account')->wait(0.2);
    $page->assertVisible('#rg-admin-account-menu')->assertSee('Sign out');

    $page->click('#rg-admin-account-menu button[type="submit"]')->wait(0.8);

    $page->assertPathIs('/admin/login');
});
