<?php

use App\Models\ProjectSettings;
use App\Models\RatingGroup;
use App\Models\RatingOption;
use App\Models\User;

use function Pest\Laravel\actingAs;

/**
 * Rating groups in a real browser: the list at each width, and the group
 * editor — what the top bar says about unsaved settings, Save waiting while
 * the range cannot hold the active options, Discard, and an option archived
 * from its row menu — measured from the page the browser built.
 *
 * One kind of page per test: the test server reads the top bar's actions
 * from the first page it drew.
 */

/** Opens a screen at this size once Livewire, and with it Alpine, has started on it. */
function visitRatingGroups(string $url, int $width = 1440, int $height = 900): mixed
{
    $page = visit($url)->resize($width, $height);

    waitForScript($page, '!! window.Livewire && window.Livewire.all().length > 0');

    return $page;
}

/** What the top bar of the group editor says now. */
function ratingGroupTopBar(mixed $page): array
{
    return $page->script(<<<'JS'
        (() => {
            const bar = document.querySelector('.rg-admin-topbar__editor')
            const [discard, save] = bar.querySelectorAll('button')

            return {
                unsaved: getComputedStyle(bar.querySelector('.rg-admin-topbar__unsaved')).display !== 'none',
                discard: ! discard.disabled,
                save: ! save.disabled,
            }
        })()
    JS);
}

beforeEach(function () {
    ProjectSettings::factory()->create();
    actingAs(User::factory()->admin()->create());

    $this->group = RatingGroup::factory()->create(['label' => 'Vibe', 'key' => 'vibe', 'min_options' => 2, 'max_options' => 4, 'is_active' => true, 'sort_order' => 1]);
    foreach (['Goofball', 'Majestic', 'Sleepy'] as $index => $label) {
        RatingOption::factory()->for($this->group, 'group')->create(['label' => $label, 'key' => strtolower($label), 'sort_order' => $index + 1, 'is_active' => true]);
    }
    RatingGroup::factory()->create(['label' => 'Tricks', 'min_options' => 2, 'max_options' => 4, 'is_active' => false, 'sort_order' => 2]);
});

it('draws the list in Admin v2 at every width, the table scrolling inside its card and never the page', function (int $width) {
    $page = visitRatingGroups('/admin/rating-groups', $width);

    expect($page->script(<<<'JS'
        (() => {
            const scroll = document.querySelector('.rg-admin-table__scroll')
            const grid = scroll.querySelector('[role="table"]')

            return {
                headings: [...document.querySelectorAll('h1')].map((h) => h.textContent.trim()),
                columns: [...document.querySelectorAll('[role="columnheader"]')].map((cell) => cell.textContent.trim()),
                // Every column stays: where the card is narrower than the grid, the card scrolls.
                scrollsWhenNarrower: (scroll.scrollWidth > scroll.clientWidth) === (grid.offsetWidth > scroll.clientWidth),
                overflow: document.documentElement.scrollWidth > window.innerWidth,
                rows: [...document.querySelectorAll('[role="table"] [role="row"][id^="rg-admin-rating-group-"] .rg-admin-taxonomy__name')].map((link) => link.textContent.trim()),
            }
        })()
    JS))->toBe([
        'headings' => ['Rating groups'],
        'columns' => ['Label', 'Key', 'Options', 'Votes', 'Active range', 'Active', 'Sort', 'Actions'],
        'scrollsWhenNarrower' => true,
        'overflow' => false,
        'rows' => ['Vibe', 'Tricks'],
    ]);
})->with([1440, 1280, 1024, 768, 390]);

it('says in the top bar when the settings are unsaved, and puts them back on Discard', function () {
    $page = visitRatingGroups("/admin/rating-groups/{$this->group->id}/edit");

    expect(ratingGroupTopBar($page))->toBe(['unsaved' => false, 'discard' => false, 'save' => false]);

    $page->type('#rg-admin-rating-group-label', 'Vibe check');
    // x-show draws the note on the next frame.
    waitForScript($page, "getComputedStyle(document.querySelector('.rg-admin-topbar__unsaved')).display !== 'none'");

    expect(ratingGroupTopBar($page))->toBe(['unsaved' => true, 'discard' => true, 'save' => true]);

    $page->click('.rg-admin-topbar__editor button:first-of-type');
    waitForScript($page, "document.getElementById('rg-admin-rating-group-label').value", 'Vibe');
    waitForScript($page, "document.querySelector('.rg-admin-topbar__editor button').disabled && getComputedStyle(document.querySelector('.rg-admin-topbar__unsaved')).display === 'none'");

    expect(ratingGroupTopBar($page))->toBe(['unsaved' => false, 'discard' => false, 'save' => false]);
});

it('saves the settings from the top bar, which then has nothing unsaved', function () {
    $page = visitRatingGroups("/admin/rating-groups/{$this->group->id}/edit");

    $page->type('#rg-admin-rating-group-label', 'Vibe check');
    waitForScript($page, "! document.querySelector('.rg-admin-topbar__editor .rg-admin-button--primary').disabled");
    $page->click('.rg-admin-topbar__editor .rg-admin-button--primary');

    waitForScript($page, "document.querySelector('.rg-admin-toast .rg-admin-toast__text')?.textContent.trim()", 'Vibe check saved');
    waitForScript($page, "document.getElementById('rg-admin-rating-group-title').textContent.trim()", 'Vibe check');
    waitForScript($page, "getComputedStyle(document.querySelector('.rg-admin-topbar__unsaved')).display === 'none'");

    expect(ratingGroupTopBar($page))->toBe(['unsaved' => false, 'discard' => false, 'save' => false])
        ->and($this->group->fresh()->label)->toBe('Vibe check');
});

it('keeps Save waiting while the range cannot hold the active options, and says why', function () {
    $page = visitRatingGroups("/admin/rating-groups/{$this->group->id}/edit");

    // Two steps down: a maximum of 2 under three active options.
    $page->click('button[aria-label="Decrease maximum active options"]');
    waitForScript($page, "document.getElementById('rg-admin-rating-group-max').value", '3');
    $page->click('button[aria-label="Decrease maximum active options"]');
    waitForScript($page, "document.getElementById('rg-admin-rating-group-max-error')?.textContent.trim() ?? ''", '3 options are active. Deactivate or archive 1 first.');
    waitForScript($page, "document.querySelector('.rg-admin-topbar__editor .rg-admin-button--primary').disabled && getComputedStyle(document.querySelector('.rg-admin-topbar__unsaved')).display !== 'none'");

    expect(ratingGroupTopBar($page))->toBe(['unsaved' => true, 'discard' => true, 'save' => false]);
});

it('archives an option from its row menu after asking', function () {
    $sleepy = $this->group->options()->where('key', 'sleepy')->firstOrFail();
    $page = visitRatingGroups("/admin/rating-groups/{$this->group->id}/edit");
    $trigger = "#rg-admin-rating-option-{$sleepy->id}-actions";

    $page->keys($trigger, 'ArrowDown');
    waitForScript($page, "document.activeElement?.closest('{$trigger}-menu') !== null");
    $page->keys(':focus', 'ArrowDown');
    waitForScript($page, "document.activeElement?.querySelector('.rg-admin-menu__label')?.textContent.trim()", 'Archive');
    $page->keys(':focus', 'Enter');

    waitForScript($page, "document.querySelector('.rg-admin-dialog__title')?.textContent.trim()", 'Archive Sleepy?');
    $page->click('.rg-admin-dialog .rg-admin-button--primary');

    waitForScript($page, "document.querySelector('#rg-admin-rating-option-{$sleepy->id} .rg-admin-badge')?.textContent.trim()", 'Archived');

    expect($sleepy->fresh()->archived_at)->not->toBeNull();
});

it('creates a group with a key made from its label, then opens its editor', function () {
    $page = visitRatingGroups('/admin/rating-groups/create');

    $page->type('#rg-admin-rating-group-label', 'Real or fake');
    waitForScript($page, "document.getElementById('rg-admin-rating-group-key').value", 'real_or_fake');
    waitForScript($page, "! document.querySelector('.rg-admin-topbar__editor .rg-admin-button--primary').disabled");
    $page->click('.rg-admin-topbar__editor .rg-admin-button--primary');

    $group = null;
    eventually(function () use (&$group): void {
        $group = RatingGroup::query()->where('key', 'real_or_fake')->first();
        expect($group)->not->toBeNull();
    });

    waitForScript($page, 'location.pathname', "/admin/rating-groups/{$group->id}/edit");
    waitForScript($page, "document.querySelector('.rg-admin-toast .rg-admin-toast__text')?.textContent.trim()", 'Real or fake created. Add its options, then activate it.');

    expect($group->is_active)->toBeFalse();
});
