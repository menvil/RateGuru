<?php

use App\Models\Category;
use App\Models\Post;
use App\Models\ProjectSettings;
use App\Models\Tag;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Categories and Tags in a real browser: the Admin v2 layout at each width,
 * the row menu from the mouse and the keyboard, the browser's search, the
 * drawer that creates and edits, and Undo on the toast — measured from the
 * page the browser built, not from markup.
 */

/**
 * Opens a screen at this size once Alpine, which also wires Livewire's
 * buttons, has started on it and lifted the cloak the rows open under.
 */
function visitTaxonomy(string $url, int $width = 1440, int $height = 900): mixed
{
    $page = visit($url)->resize($width, $height);

    waitForScript($page, "(() => { const body = document.querySelector('.rg-admin-screen__body'); return !! body?._x_dataStack && body.querySelector('[x-cloak]') === null })()");

    return $page;
}

/** The names of the rows a person sees: the browser's search hides the others. */
function taxonomyRows(mixed $page): array
{
    return $page->script(<<<'JS'
        [...document.querySelectorAll('[role="table"] [role="row"][data-search]')]
            .filter((row) => getComputedStyle(row).display !== 'none')
            .map((row) => row.querySelector('.rg-admin-taxonomy__name').textContent.trim())
    JS);
}

/** Waits for the drawer to be open as a person meets it: drawn and holding keyboard focus. */
function taxonomyDrawerOpen(mixed $page): void
{
    waitForScript($page, "(() => { const layer = document.querySelector('.rg-admin-drawer-layer'); return !! layer && getComputedStyle(layer).display !== 'none' && !! document.activeElement?.closest('.rg-admin-drawer') })()");
}

beforeEach(function () {
    ProjectSettings::factory()->create();
    [$first, $second] = twoTranslatedLocales();
    offerLocales([$first, $second]);
    actingAs(User::factory()->admin()->create());

    $this->dogs = Category::factory()->create(['name' => 'Dogs', 'slug' => 'dogs', 'sort_order' => 1, 'name_translations' => [$first => 'Dogs', $second => 'Dogs']]);
    $this->cats = Category::factory()->create(['name' => 'Cats', 'slug' => 'cats', 'sort_order' => 2, 'name_translations' => [$first => 'Cats']]);
    $this->exotic = Category::factory()->create(['name' => 'Exotic', 'slug' => 'exotic', 'sort_order' => 3, 'is_active' => false]);
    Post::factory()->count(2)->published()->create(['category_id' => $this->dogs->id]);
});

it('draws Categories in Admin v2 at every width, the table scrolling inside its card and never the page', function (int $width) {
    $page = visitTaxonomy('/admin/categories', $width);

    expect($page->script(<<<'JS'
        (() => {
            const scroll = document.querySelector('.rg-admin-table__scroll')

            return {
                headings: [...document.querySelectorAll('h1')].map((h) => h.textContent.trim()),
                filament: document.querySelectorAll('.fi-ta, .fi-header-heading, .fi-section').length,
                create: document.querySelector('.rg-admin-topbar__actions')?.innerText.trim(),
                columns: [...document.querySelectorAll('[role="columnheader"]')].map((cell) => cell.textContent.trim()),
                cardScrolls: scroll.scrollWidth > scroll.clientWidth,
                overflow: document.documentElement.scrollWidth > window.innerWidth,
            }
        })()
    JS))->toMatchArray([
        'headings' => ['Categories'],
        'filament' => 0,
        'create' => 'Create category',
        'columns' => ['Name', 'Slug', 'Active', 'Sort order', 'Posts', 'Actions'],
        // Every column stays: below the grid's own width the card scrolls instead.
        'cardScrolls' => $width < 1024,
        'overflow' => false,
    ]);

    expect(taxonomyRows($page))->toBe(['Dogs', 'Cats', 'Exotic']);
})->with([1440, 1280, 1024, 768, 390]);

it('opens a row menu under its button, moves through it from the keyboard and closes back to the button', function () {
    $page = visitTaxonomy('/admin/categories');
    $trigger = "#rg-admin-category-{$this->cats->id}-actions";

    $page->keys($trigger, 'ArrowDown');
    waitForScript($page, "document.activeElement?.closest('{$trigger}-menu') !== null");

    expect($page->script(<<<JS
        (() => {
            const menu = document.querySelector('{$trigger}-menu')
            const button = document.querySelector('{$trigger}').getBoundingClientRect()
            const box = menu.getBoundingClientRect()

            return {
                expanded: document.querySelector('{$trigger}').getAttribute('aria-expanded'),
                focused: document.activeElement.querySelector('.rg-admin-menu__label').textContent.trim(),
                items: [...menu.querySelectorAll('[role=menuitem]')].map((item) => item.querySelector('.rg-admin-menu__label').textContent.trim() + (item.getAttribute('aria-disabled') === 'true' ? ' (unavailable)' : '')),
                below: box.top >= button.bottom,
                alignedRight: Math.abs(box.right - button.right) <= 1,
            }
        })()
    JS))->toBe([
        'expanded' => 'true',
        'focused' => 'Deactivate',
        'items' => ['Deactivate', 'Translate', 'Delete'],
        'below' => true,
        'alignedRight' => true,
    ]);

    $page->keys(':focus', 'End');
    waitForScript($page, "document.activeElement?.querySelector('.rg-admin-menu__label')?.textContent.trim()", 'Delete');
    $page->keys(':focus', 'ArrowDown');
    waitForScript($page, "document.activeElement?.querySelector('.rg-admin-menu__label')?.textContent.trim()", 'Deactivate');
    $page->keys(':focus', 'Escape');
    waitForScript($page, "document.activeElement?.id === 'rg-admin-category-{$this->cats->id}-actions' && document.activeElement.getAttribute('aria-expanded') === 'false'");

    // A click elsewhere closes it too.
    $page->click($trigger);
    waitForScript($page, "document.querySelector('{$trigger}').getAttribute('aria-expanded') === 'true'");
    $page->click('.rg-admin-page-header__title');
    waitForScript($page, "getComputedStyle(document.querySelector('{$trigger}-menu')).display === 'none'");
});

it('opens a row menu above its button when the window has no room below', function () {
    $page = visitTaxonomy('/admin/categories', 1440, 470);
    $trigger = "#rg-admin-category-{$this->exotic->id}-actions";

    $page->click($trigger);
    waitForScript($page, "document.activeElement?.closest('{$trigger}-menu') !== null");

    expect($page->script("document.querySelector('{$trigger}-menu').getBoundingClientRect().bottom <= document.querySelector('{$trigger}').getBoundingClientRect().top"))->toBeTrue();

    // The scroll that brought the button into view left it open; a scroll after that closes it.
    $page->script('document.scrollingElement.scrollBy(0, -40)');
    waitForScript($page, "document.querySelector('{$trigger}-menu').hidden && document.querySelector('{$trigger}').getAttribute('aria-expanded') === 'false'");
});

it('deactivates from the row menu, and the toast\'s Undo activates it again', function () {
    $page = visitTaxonomy('/admin/categories');
    $row = "#rg-admin-category-{$this->cats->id}";

    $page->click("{$row}-actions");
    waitForScript($page, "document.activeElement?.closest('{$row}-actions-menu') !== null");
    $page->click("{$row}-actions-menu [role=menuitem]:first-child");

    waitForScript($page, "document.querySelector('{$row} .rg-admin-badge')?.textContent.trim()", 'Inactive');
    waitForScript($page, "document.querySelector('.rg-admin-toast .rg-admin-toast__text')?.textContent.trim()", 'Cats deactivated');

    $page->click('.rg-admin-toast .rg-admin-toast__action');

    waitForScript($page, "document.querySelector('{$row} .rg-admin-badge')?.textContent.trim()", 'Active');
    waitForScript($page, "[...document.querySelectorAll('.rg-admin-toast .rg-admin-toast__text')].map((toast) => toast.textContent.trim()).join('|')", 'Cats is active again');

    expect($this->cats->fresh()->is_active)->toBeTrue();
});

it('creates a category from the top bar, its slug following the name, and lists it', function () {
    $page = visitTaxonomy('/admin/categories');

    $page->click('.rg-admin-topbar__actions button');
    taxonomyDrawerOpen($page);
    $page->type('#rg-admin-category-drawer-name', 'Guinea pigs');
    waitForScript($page, "document.getElementById('rg-admin-category-drawer-slug').value", 'guinea-pigs');

    $page->click('.rg-admin-drawer__footer .rg-admin-button--primary');

    waitForScript($page, "document.querySelector('.rg-admin-drawer-layer') === null");
    waitForScript($page, "document.querySelector('.rg-admin-toast .rg-admin-toast__text')?.textContent.trim()", 'Guinea pigs created. Translations are missing for 2 languages.');

    expect(taxonomyRows($page))->toBe(['Dogs', 'Cats', 'Exotic', 'Guinea pigs']);
});

it('searches in the browser, keeps the search in the address, and creates what it did not find', function () {
    $page = visitTaxonomy('/admin/categories');

    $page->type('#rg-admin-categories-search', 'guinea');
    waitForScript($page, 'new URLSearchParams(location.search).get("q") ?? ""', 'guinea');
    waitForScript($page, "document.querySelector('.rg-admin-toolbar__count').textContent.trim()", '0 of 3');
    waitForScript($page, "[...document.querySelectorAll('[role=\"table\"] [data-search]')].every((row) => getComputedStyle(row).display === 'none')");

    expect(taxonomyRows($page))->toBe([]);

    $page->click('.rg-admin-empty-state__action button');
    taxonomyDrawerOpen($page);

    expect($page->script("[document.getElementById('rg-admin-category-drawer-name').value, document.getElementById('rg-admin-category-drawer-slug').value]"))->toBe(['guinea', 'guinea']);
});

it('opens the drawer the address names, and closes it with Escape, taking it out of the address', function () {
    $page = visitTaxonomy("/admin/categories?edit={$this->dogs->id}");
    taxonomyDrawerOpen($page);

    expect($page->script("document.querySelector('.rg-admin-drawer__title').textContent.trim()"))->toBe('Edit category')
        ->and($page->script("document.getElementById('rg-admin-category-drawer-slug').readOnly"))->toBeTrue();

    $page->keys(':focus', 'Escape');

    waitForScript($page, "document.querySelector('.rg-admin-drawer-layer') === null && new URLSearchParams(location.search).get('edit') === null");
});

it('replaces the drawer with the confirmation when Delete is pressed in it', function () {
    $page = visitTaxonomy("/admin/categories?edit={$this->dogs->id}");
    taxonomyDrawerOpen($page);

    $page->click('.rg-admin-drawer__footer button:first-child');

    waitForScript($page, "document.querySelector('.rg-admin-drawer-layer') === null && document.querySelector('.rg-admin-dialog__title')?.textContent.trim() === 'Dogs can’t be deleted'");
});

it('shows Merge into… unavailable on Tags and keeps its place in the keyboard order', function () {
    $tag = Tag::factory()->create(['name' => 'zoomies', 'slug' => 'zoomies']);
    $page = visitTaxonomy('/admin/tags', 768);
    $trigger = "#rg-admin-tag-{$tag->id}-actions";

    $page->keys($trigger, 'Enter');
    waitForScript($page, "document.activeElement?.closest('{$trigger}-menu') !== null");

    expect($page->script("[document.activeElement.querySelector('.rg-admin-menu__label').textContent.trim(), document.activeElement.getAttribute('aria-disabled')]"))->toBe(['Merge into…', 'true']);

    // Choosing it does nothing: the menu stays open on it.
    $page->keys(':focus', 'Enter');

    expect($page->script("document.querySelector('{$trigger}').getAttribute('aria-expanded')"))->toBe('true')
        ->and($page->script('document.documentElement.scrollWidth > window.innerWidth'))->toBeFalse();
});
