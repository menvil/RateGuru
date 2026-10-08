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

/**
 * An admin page at the given size, once the shell is laid out for it: the
 * window has the size, the fonts are in and the sidebar's Alpine component
 * has started. The shell has no transitions, so that layout is final.
 */
function adminShellAt(string $path, int $width, int $height): mixed
{
    $page = resizeAndSettle(visit($path), $width, $height);

    waitForScript($page, 'document.fonts.status === "loaded" && Alpine.$data(document.querySelector(".rg-admin-shell-sidebar")).open === false');

    return $page;
}

/**
 * Waits until the sidebar has opened over the page: show() moves focus to
 * its close button last, a tick after it opens.
 */
function waitForAdminShellSidebarOpen(mixed $page): void
{
    waitForScript($page, 'document.getElementById("rg-admin-sidebar").classList.contains("rg-admin-sidebar--open") && document.activeElement === document.querySelector(".rg-admin-sidebar__close")');
}

/**
 * Waits until the sidebar has closed and given focus back to the button
 * named $opener: hide() returns focus last, a tick after it closes.
 */
function waitForAdminShellSidebarClosed(mixed $page, string $opener): void
{
    waitForScript($page, "! document.getElementById('rg-admin-sidebar').classList.contains('rg-admin-sidebar--open') && document.activeElement?.getAttribute('aria-label') === '{$opener}'");
}

/** Waits until the browser has left the page and finished loading $path. */
function waitForAdminShellPage(mixed $page, string $path): void
{
    waitForScript($page, "location.pathname === '{$path}' && document.readyState === 'complete'");
}

/** Waits until the sidebar search lists its results for what was typed. */
function waitForAdminShellSearchResults(mixed $page, string $title): void
{
    waitForScript($page, <<<JS
        (() => {
            const results = document.getElementById('rg-admin-search-results')

            return !! results && getComputedStyle(results).display !== 'none' && results.textContent.includes('{$title}')
        })()
    JS);
}

beforeEach(function () {
    ProjectSettings::factory()->create();
});

it('takes the full sidebar, the rail or a drawer by width, never covering the page', function (int $width, array $expected) {
    actingAs(User::factory()->admin()->create());

    $layout = adminShellLayout(adminShellAt('/admin', $width, 900));

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

    $page = adminShellAt($path, 1440, 900);

    $page->assertVisible('#rg-admin-sidebar')
        ->assertVisible('.rg-admin-topbar')
        ->assertSee($content);

    expect(adminShellActive($page))->toBe($active);
})->with([
    'dashboard' => ['/admin', 'Dashboard', 'Pending posts'],
    'posts' => ['/admin/posts', 'Posts', 'Author'],
    'project settings' => ['/admin/project-settings', 'Project settings', 'Project Settings'],
]);

it('navigates from the sidebar', function () {
    actingAs(User::factory()->admin()->create());

    $page = adminShellAt('/admin', 1440, 900);
    $page->click('#rg-admin-sidebar a[data-tooltip="Languages"]');
    waitForAdminShellPage($page, '/admin/languages');

    // Which item is marked after the click is not asserted here: the test
    // server handles every request in one process, where Filament keeps the
    // first request as the "original" one. Each page's marked item is checked
    // on its own visit above.
    $page->assertPathIs('/admin/languages')->assertSee('Manage which installed languages are available to visitors');
});

it('names the rail\'s icons, and expands it into the full sidebar that Escape closes', function () {
    actingAs(User::factory()->admin()->create());

    $page = adminShellAt('/admin/posts', 1024, 800);

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

    waitForScript($page, 'getComputedStyle(document.querySelector(".rg-admin-shell-tooltip")).display !== "none"');

    expect($page->script(<<<'JS'
        (() => {
            const tip = document.querySelector('.rg-admin-shell-tooltip')

            return { shown: getComputedStyle(tip).display !== 'none', text: tip.textContent.trim() }
        })()
    JS))->toBe(['shown' => true, 'text' => 'Comments']);

    $page->click('.rg-admin-sidebar__expand button');
    waitForAdminShellSidebarOpen($page);

    expect(adminShellLayout($page))->toMatchArray(['sidebar' => 300, 'open' => true, 'labels' => true, 'scrim' => true, 'content' => 68]);

    $page->keys('.rg-admin-sidebar__close', 'Escape');
    waitForAdminShellSidebarClosed($page, 'Expand navigation');

    expect(adminShellLayout($page))->toMatchArray(['sidebar' => 68, 'open' => false, 'scrim' => false])
        ->and($page->script('document.activeElement?.getAttribute("aria-label") ?? null'))->toBe('Expand navigation');
});

it('opens the navigation as a drawer on narrow screens, closed by the scrim or a destination', function () {
    actingAs(User::factory()->admin()->create());

    $page = adminShellAt('/admin', 800, 900);

    $page->click('.rg-admin-topbar__menu');
    waitForAdminShellSidebarOpen($page);

    expect(adminShellLayout($page))->toMatchArray(['sidebar' => 300, 'open' => true, 'scrim' => true, 'overflow' => false])
        ->and($page->script('document.querySelector(".rg-admin-topbar__menu").getAttribute("aria-expanded")'))->toBe('true');

    $page->click('.rg-admin-shell-scrim');
    waitForAdminShellSidebarClosed($page, 'Open navigation');

    expect(adminShellLayout($page))->toMatchArray(['sidebar' => 0, 'open' => false, 'scrim' => false])
        ->and($page->script('document.activeElement?.getAttribute("aria-label") ?? null'))->toBe('Open navigation');

    $page->click('.rg-admin-topbar__menu');
    waitForAdminShellSidebarOpen($page);
    $page->click('#rg-admin-sidebar a[data-tooltip="Posts"]');
    waitForAdminShellPage($page, '/admin/posts');
    waitForScript($page, 'Alpine.$data(document.querySelector(".rg-admin-shell-sidebar")).open === false');

    $page->assertPathIs('/admin/posts');

    expect(adminShellLayout($page))->toMatchArray(['open' => false, 'overflow' => false]);
});

it('signs out from the account menu through Filament\'s logout', function () {
    actingAs(User::factory()->admin()->create(['name' => 'Daria Kovaleva']));

    $page = adminShellAt('/admin', 1440, 900);

    $page->click('.rg-admin-account');
    waitForScript($page, 'getComputedStyle(document.getElementById("rg-admin-account-menu")).display !== "none"');
    $page->assertVisible('#rg-admin-account-menu')->assertSee('Sign out');

    $page->click('#rg-admin-account-menu button[type="submit"]');
    waitForAdminShellPage($page, '/admin/login');

    $page->assertPathIs('/admin/login');
});

it('lines the sidebar header up with the top bar at every width', function (int $width) {
    actingAs(User::factory()->admin()->create());

    $page = adminShellAt('/admin', $width, 900);

    expect($page->script(<<<'JS'
        (() => ({
            header: Math.round(document.querySelector('.rg-admin-sidebar__header').getBoundingClientRect().height),
            topbar: Math.round(document.querySelector('.rg-admin-topbar').getBoundingClientRect().height),
        }))()
    JS))->toBe(['header' => 62, 'topbar' => 62]);
})->with([1440, 1100]);

it('focuses the sidebar search with Ctrl+K and opens the first result with Enter', function () {
    actingAs(User::factory()->admin()->create());
    Post::factory()->published()->create(['title' => 'Searchable sunset photo']);

    $page = adminShellAt('/admin', 1440, 900);

    // The field sits under the workspace, above the navigation.
    expect($page->script(<<<'JS'
        (() => {
            const top = (selector) => document.querySelector(selector).getBoundingClientRect().top

            return top('.rg-admin-sidebar__header') < top('#rg-admin-search') && top('#rg-admin-search') < top('.rg-admin-sidebar__nav')
        })()
    JS))->toBeTrue();

    $page->keys('.fi-main', 'Control+k');
    waitForScript($page, 'document.activeElement?.id', 'rg-admin-search');

    expect($page->script('document.activeElement?.id ?? null'))->toBe('rg-admin-search');

    $page->type('#rg-admin-search', 'Searchable sunset');
    waitForAdminShellSearchResults($page, 'Searchable sunset photo');
    $page->assertVisible('#rg-admin-search-results')->assertSee('Searchable sunset photo');

    $page->keys('#rg-admin-search', 'Enter');
    waitForScript($page, 'location.pathname.startsWith("/admin/posts") && document.readyState === "complete"');

    $page->assertPathBeginsWith('/admin/posts');
});

it('opens the collapsed rail before focusing the search', function () {
    actingAs(User::factory()->admin()->create());

    $page = adminShellAt('/admin', 1024, 800);

    $page->keys('.fi-main', 'Control+k');
    // Focus reaches the search last, a tick after the sidebar has opened.
    waitForScript($page, 'document.activeElement?.id', 'rg-admin-search');

    expect(adminShellLayout($page))->toMatchArray(['sidebar' => 300, 'open' => true])
        ->and($page->script('document.activeElement?.id ?? null'))->toBe('rg-admin-search');
});

it('leaves Ctrl+K to a rich text editor', function () {
    actingAs(User::factory()->admin()->create());

    $page = adminShellAt('/admin', 1440, 900);

    $page->script(<<<'JS'
        (() => {
            const editor = document.createElement('div')
            editor.id = 'editor-under-test'
            editor.contentEditable = 'true'
            editor.textContent = 'Rich text'
            document.querySelector('.fi-main').prepend(editor)
        })()
    JS);

    // Registered after the shell's own listener, so it sees the event once the shell is done with it.
    $page->script(<<<'JS'
        window.addEventListener('keydown', (event) => {
            if (event.ctrlKey && event.key.toLowerCase() === 'k') {
                window.ctrlKDefaultPrevented = event.defaultPrevented
            }
        })
    JS);

    // Taking the shortcut would move focus to the search a tick after the key
    // press; nothing marks it not happening, so the test gives it that time.
    proveNothingHappensFor($page->keys('#editor-under-test', 'Control+k'), 0.1, 'the shortcut taking focus from the editor');

    expect($page->script('document.activeElement?.id ?? null'))->toBe('editor-under-test')
        ->and($page->script('window.ctrlKDefaultPrevented ?? null'))->toBeFalse();
});

it('opens a result with Enter only while the list is open and no IME composition is under way', function () {
    actingAs(User::factory()->admin()->create());
    Post::factory()->published()->create(['title' => 'Searchable sunset photo']);

    $page = adminShellAt('/admin', 1440, 900);

    $page->type('#rg-admin-search', 'Searchable sunset');
    waitForAdminShellSearchResults($page, 'Searchable sunset photo');
    $page->assertVisible('#rg-admin-search-results');

    // Enter that confirms an IME composition is left to the input method.
    expect($page->script(<<<'JS'
        document.getElementById('rg-admin-search').dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Enter', isComposing: true, bubbles: true, cancelable: true }),
        )
    JS))->toBeTrue();

    // With the list closed, Enter picks nothing.
    $page->keys('#rg-admin-search', 'Escape');
    waitForScript($page, 'getComputedStyle(document.getElementById("rg-admin-search-results")).display', 'none');

    // A pick would leave for the result once the server answered; nothing
    // marks it not happening, so the test gives it that long.
    proveNothingHappensFor($page->keys('#rg-admin-search', 'Enter'), 0.6, 'a pick leaving for a result');

    $page->assertPathIs('/admin');
});
